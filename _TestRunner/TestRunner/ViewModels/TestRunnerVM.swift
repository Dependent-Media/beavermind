import Foundation
import Combine
import AppKit
import SwiftUI  // for Color (used by AuthCookieStatus)

@MainActor
class TestRunnerVM: ObservableObject {

    // MARK: - Published State

    @Published var specs: [TestSpec] = []
    @Published var results: [String: TestResult] = [:]
    @Published var output: String = ""
    @Published var isRunning = false
    @Published var filterText = ""
    @Published var startTime: Date?
    @Published var runMode: RunMode = .headless
    @Published var authCookieStatus: AuthCookieStatus?
    /// When set, the detail pane flips from Console to ScreenshotView. Cleared
    /// by ScreenshotView's "Back to Console" button.
    @Published var previewedScreenshot: (path: String, testName: String)?
    /// Threshold above which a completed run posts a macOS notification.
    /// Short runs (the normal 15-20s happy path) stay quiet.
    var notifyIfLongerThan: TimeInterval = 30

    enum AuthCookieStatus {
        case refreshing
        case ok(mintedAt: Date)
        case error(String)

        var label: String {
            switch self {
            case .refreshing:         return "Refreshing…"
            case .ok:                 return "Cookie OK"
            case .error:              return "Cookie error"
            }
        }
        var icon: String {
            switch self {
            case .refreshing:         return "arrow.triangle.2.circlepath"
            case .ok:                 return "checkmark.seal.fill"
            case .error:              return "exclamationmark.triangle.fill"
            }
        }
        var color: Color {
            switch self {
            case .refreshing:         return .secondary
            case .ok:                 return .green
            case .error:              return .red
            }
        }
        var tooltip: String {
            switch self {
            case .refreshing:           return "Running scripts/generate-auth-state.sh over SSH"
            case .ok(let mintedAt):     return "Minted \(Self.relativeFormatter.localizedString(for: mintedAt, relativeTo: Date()))"
            case .error(let message):   return message
            }
        }

        private static let relativeFormatter: RelativeDateTimeFormatter = {
            let f = RelativeDateTimeFormatter()
            f.unitsStyle = .short
            return f
        }()
    }

    enum RunMode: String, CaseIterable, Identifiable {
        case headless = "Headless"
        case headed = "Headed"
        case ui = "UI Mode"

        var id: String { rawValue }

        var icon: String {
            switch self {
            case .headless: return "terminal"
            case .headed: return "macwindow"
            case .ui: return "rectangle.on.rectangle"
            }
        }
    }

    // MARK: - Configuration

    let projectPath: String

    // MARK: - Private

    private var currentProcess: Process?
    private var outputPipe: Pipe?
    private var errorPipe: Pipe?

    var passedCount: Int { results.values.filter { $0.status == .passed }.count }
    var failedCount: Int { results.values.filter { $0.status == .failed }.count }
    var totalCount: Int { specs.reduce(0) { $0 + $1.tests.count } }

    var filteredSpecs: [TestSpec] {
        guard !filterText.isEmpty else { return specs }
        let query = filterText.lowercased()
        return specs.compactMap { spec in
            let matchingTests = spec.tests.filter {
                $0.name.lowercased().contains(query) || spec.fileName.lowercased().contains(query)
            }
            guard !matchingTests.isEmpty else { return nil }
            var filtered = spec
            filtered.tests = matchingTests
            return filtered
        }
    }

    // MARK: - Init

    init(projectPath: String? = nil) {
        self.projectPath = projectPath ?? Self.detectProjectPath()
    }

    private static func detectProjectPath() -> String {
        // BeaverMind's Playwright project lives at tests/playwright/ inside
        // the plugin repo. It's TypeScript (playwright.config.ts), unlike
        // the Client Sync TestRunner this was forked from which used .js.
        let candidates = [
            NSString("~/Projects/Code/dm-software/bb-dm-ai-builder/tests/playwright").expandingTildeInPath,
            "/Users/joshuajordan/Projects/Code/dm-software/bb-dm-ai-builder/tests/playwright",
        ]
        for path in candidates {
            if FileManager.default.fileExists(atPath: path + "/playwright.config.ts")
                || FileManager.default.fileExists(atPath: path + "/playwright.config.js") {
                return path
            }
        }
        return candidates.last!
    }

    // MARK: - Refresh Spec List

    func refreshSpecs() {
        isRunning = true
        output = "Loading test list...\n"

        Task {
            let (stdout, _) = await runShellCommand("npx playwright test --list 2>&1")
            parseTestList(stdout)
            isRunning = false
        }
    }

    private func parseTestList(_ text: String) {
        // Playwright list-reporter format:
        //     [project] › file.spec.ts:line:col › Suite Name › test name
        // BeaverMind's playwright.config.ts declares a single project
        // (`[chromium]`) but accept any bracketed first token so future
        // multi-project setups just work. Skip the summary line
        // ("Total: N tests in M files") which doesn't match this shape.
        var specMap: [String: [TestItem]] = [:]
        var seen: Set<String> = []

        for line in text.components(separatedBy: "\n") {
            let trimmed = line.trimmingCharacters(in: .whitespaces)
            guard trimmed.hasPrefix("[") else { continue }

            // Split on " › " (the Playwright list separator — U+203A).
            let parts = trimmed.components(separatedBy: " \u{203A} ")
            guard parts.count >= 3 else { continue }

            // parts[0] = "[project]"
            // parts[1] = "file.spec.js:6:3"
            // parts[2..] = "Suite Name" › "test name"
            //
            // Guard against non-test lines that happen to start with "[".
            let fileRef = parts[1].trimmingCharacters(in: .whitespaces)
            guard fileRef.contains(".spec.js") || fileRef.contains(".spec.ts") else { continue }
            let fileName = fileRef.components(separatedBy: ":").first ?? fileRef

            let testParts = Array(parts.dropFirst(2))
            let testName = testParts.last ?? "unknown"
            let fullTitle = testParts.joined(separator: " \u{203A} ")

            // De-dupe — the same test can appear under multiple projects
            // (e.g. a spec that runs under both `frontend` and `frontend-auth`
            // shows up twice in --list output). Key by file+title so we
            // only register one sidebar row per logical test.
            let dedupeKey = "\(fileName)|\(fullTitle)"
            guard seen.insert(dedupeKey).inserted else { continue }

            let item = TestItem(name: testName.trimmingCharacters(in: .whitespaces),
                                fullTitle: fullTitle.trimmingCharacters(in: .whitespaces))

            specMap[fileName, default: []].append(item)
        }

        specs = specMap.keys.sorted().map { fileName in
            TestSpec(fileName: fileName, tests: specMap[fileName] ?? [])
        }

        // Initialize results as pending.
        results = [:]
        for spec in specs {
            for test in spec.tests {
                results[test.fullTitle] = TestResult(testName: test.name, status: .pending)
            }
        }
    }

    // MARK: - Auth Cookie (BeaverMind-specific)

    /// Run the generate-auth-state.sh helper that SSHes to testbeavermind and
    /// mints a fresh WP auth cookie for Playwright. Required before the clone
    /// spec runs. Output is streamed into the console; `authCookieStatus`
    /// drives the sidebar badge.
    func refreshAuthCookie() {
        guard !isRunning else { return }

        isRunning = true
        authCookieStatus = .refreshing
        output += "--- Refreshing auth cookie ---\n"

        Task {
            let (stdout, exit) = await runShellCommandStreaming("bash scripts/generate-auth-state.sh")
            isRunning = false
            if exit == 0 {
                authCookieStatus = .ok(mintedAt: Date())
                output += "--- Auth cookie refreshed ---\n"
            } else {
                // Grab the last non-empty stderr-ish line as the tooltip hint.
                let lastLine = stdout.components(separatedBy: "\n")
                    .map { $0.trimmingCharacters(in: .whitespaces) }
                    .filter { !$0.isEmpty }
                    .last ?? "exit \(exit)"
                authCookieStatus = .error(lastLine)
                output += "--- Auth cookie FAILED (exit \(exit)) ---\n"
            }
        }
    }

    // MARK: - Run Tests

    /// True when the last completed run left at least one failing test.
    /// Drives the enabled state of the "Run Failing" button.
    var hasFailedTests: Bool { failedCount > 0 }

    func runAll() {
        runPlaywright(args: "")
    }

    func runSpec(_ fileName: String) {
        runPlaywright(args: fileName)
    }

    /// Re-run only the tests that failed in the last run.
    /// Uses Playwright's built-in `--last-failed` flag, which reads the
    /// result of the most recent invocation from test-results/.last-run.json.
    func runLastFailing() {
        runPlaywright(args: "--last-failed")
    }

    func runTest(_ fullTitle: String) {
        // Escape special regex characters in the test name.
        let escaped = fullTitle
            .replacingOccurrences(of: "(", with: "\\(")
            .replacingOccurrences(of: ")", with: "\\)")
            .replacingOccurrences(of: "[", with: "\\[")
            .replacingOccurrences(of: "]", with: "\\]")
            .replacingOccurrences(of: "$", with: "\\$")
        runPlaywright(args: "--grep \"\(escaped)\"")
    }

    func stop() {
        currentProcess?.terminate()
        isRunning = false
        output += "\n--- Stopped by user ---\n"
    }

    /// Launch Playwright UI mode in a detached process (non-blocking).
    func openUIMode() {
        launchDetached("npx playwright test --ui")
    }

    private func runPlaywright(args: String) {
        guard !isRunning else { return }

        // UI mode launches a separate app — don't block.
        if runMode == .ui {
            let extraArgs = args.isEmpty ? "" : " \(args)"
            launchDetached("npx playwright test --ui\(extraArgs)")
            return
        }

        isRunning = true
        startTime = Date()
        output = ""

        // Reset results that will be affected.
        for key in results.keys {
            results[key]?.status = .pending
            results[key]?.errorMessage = nil
            results[key]?.duration = nil
        }

        let modeFlag = runMode == .headed ? " --headed" : ""

        Task {
            let cmd = "npx playwright test\(modeFlag) \(args) 2>&1"
            let (stdout, _) = await runShellCommandStreaming(cmd)
            parseResults(stdout)
            isRunning = false

            // Post a macOS notification if the run was long enough that the
            // user probably tabbed away. Short runs (~15-20s) stay quiet.
            if let start = startTime {
                let elapsed = Date().timeIntervalSince(start)
                if elapsed >= notifyIfLongerThan {
                    await NotificationService.shared.postRunFinished(
                        passed: passedCount,
                        failed: failedCount,
                        elapsedSeconds: elapsed
                    )
                }
            }
        }
    }

    /// Launch a command in a fire-and-forget detached process.
    private func launchDetached(_ command: String) {
        output = "Launching: \(command)\n"
        DispatchQueue.global(qos: .userInitiated).async { [self] in
            let process = Process()
            process.executableURL = URL(fileURLWithPath: "/bin/zsh")
            process.arguments = ["-l", "-c", "cd \(projectPath) && \(command)"]
            process.environment = ProcessInfo.processInfo.environment
            try? process.run()
        }
    }

    private func parseResults(_ text: String) {
        // Parse Playwright list reporter output.
        // Patterns (bracketed token is the *project* name, not a browser):
        //   ✓  1 [frontend] › file.spec.js:6:3 › Suite › test name (3.2s)
        //   ✘  2 [journey]  › file.spec.js:6:3 › Suite › test name (5.1s)
        //   -  3 [admin]    › file.spec.js:6:3 › Suite › test name
        //
        // Any `[...]` token counts as a project marker (was hard-coded to
        // `[chromium]` in the DAS AutoFiler version).

        for line in text.components(separatedBy: "\n") {
            let trimmed = line.trimmingCharacters(in: .whitespaces)

            var status: TestStatus?
            if trimmed.contains("\u{2713}") || trimmed.contains("✓") || trimmed.hasPrefix("✓") {
                status = .passed
            } else if trimmed.contains("\u{2718}") || trimmed.contains("✘") || trimmed.contains("×") || trimmed.contains("✗") {
                status = .failed
            } else if trimmed.hasPrefix("-") && trimmed.range(of: #"\[[^\]]+\]"#, options: .regularExpression) != nil {
                status = .skipped
            }

            guard let testStatus = status else { continue }

            // Extract test title by splitting on " › ".
            let parts = trimmed.components(separatedBy: " \u{203A} ")
            guard parts.count >= 3 else { continue }

            let testParts = Array(parts.dropFirst(2))
            var fullTitle = testParts.joined(separator: " \u{203A} ")

            // Strip trailing duration like "(3.2s)".
            if let range = fullTitle.range(of: #"\s*\(\d+\.?\d*m?s\)\s*$"#, options: .regularExpression) {
                let durationStr = String(fullTitle[range]).trimmingCharacters(in: .whitespaces)
                fullTitle = String(fullTitle[..<range.lowerBound]).trimmingCharacters(in: .whitespaces)
                results[fullTitle]?.duration = durationStr.trimmingCharacters(in: CharacterSet(charactersIn: "()"))
            }

            results[fullTitle]?.status = testStatus
        }
    }

    // MARK: - Screenshots & Reports

    /// Directory where Playwright stores test results (screenshots, traces).
    var testResultsPath: String { projectPath + "/test-results" }

    /// Whether any failure screenshots exist.
    var hasFailureScreenshots: Bool {
        let fm = FileManager.default
        guard let contents = try? fm.contentsOfDirectory(atPath: testResultsPath) else { return false }
        return contents.contains { $0.contains("chromium") }
    }

    /// Open the test-results folder in Finder.
    func openTestResults() {
        NSWorkspace.shared.open(URL(fileURLWithPath: testResultsPath))
    }

    /// Open the Playwright HTML report.
    func openHTMLReport() {
        launchDetached("npx playwright show-report")
    }

    /// Locate the failure screenshot for a test and show it in the detail
    /// pane (flipping the console away). Falls back to opening the folder
    /// externally if no match is found.
    func previewScreenshotForTest(_ testName: String) {
        guard let png = findScreenshotPath(forTest: testName) else {
            openTestResults()
            return
        }
        previewedScreenshot = (path: png, testName: testName)
    }

    /// Dismiss the in-app screenshot preview and return to the console.
    func dismissScreenshotPreview() {
        previewedScreenshot = nil
    }

    /// Best-effort match from a test title to a `test-results/<slug>/` PNG.
    /// Used by previewScreenshotForTest.
    private func findScreenshotPath(forTest testName: String) -> String? {
        let fm = FileManager.default
        guard let dirs = try? fm.contentsOfDirectory(atPath: testResultsPath) else { return nil }

        let slug = testName.lowercased()
            .replacingOccurrences(of: " ", with: "-")
            .replacingOccurrences(of: ".", with: "-")

        let keywords = slug.components(separatedBy: "-").filter { $0.count > 3 }
        var bestMatch: String?
        var bestScore = 0
        for dir in dirs {
            let dirLower = dir.lowercased()
            let score = keywords.filter { dirLower.contains($0) }.count
            if score > bestScore {
                bestScore = score
                bestMatch = dir
            }
        }
        guard let matchDir = bestMatch else { return nil }

        let dirPath = testResultsPath + "/" + matchDir
        guard let files = try? fm.contentsOfDirectory(atPath: dirPath) else { return nil }
        // Prefer the "test-failed" image Playwright always writes, then any PNG.
        if let failed = files.first(where: { $0.contains("test-failed") && $0.hasSuffix(".png") }) {
            return dirPath + "/" + failed
        }
        if let png = files.first(where: { $0.hasSuffix(".png") }) {
            return dirPath + "/" + png
        }
        return nil
    }

    // MARK: - Shell Execution

    private func runShellCommand(_ command: String) async -> (String, Int32) {
        await withCheckedContinuation { continuation in
            DispatchQueue.global(qos: .userInitiated).async { [self] in
                let process = Process()
                let pipe = Pipe()

                process.executableURL = URL(fileURLWithPath: "/bin/zsh")
                process.arguments = ["-l", "-c", "cd \(projectPath) && \(command)"]
                process.standardOutput = pipe
                process.standardError = pipe
                process.environment = ProcessInfo.processInfo.environment

                do {
                    try process.run()
                    process.waitUntilExit()
                    let data = pipe.fileHandleForReading.readDataToEndOfFile()
                    let output = String(data: data, encoding: .utf8) ?? ""

                    DispatchQueue.main.async {
                        self.output += output
                    }

                    continuation.resume(returning: (output, process.terminationStatus))
                } catch {
                    continuation.resume(returning: ("Error: \(error.localizedDescription)", -1))
                }
            }
        }
    }

    private func runShellCommandStreaming(_ command: String) async -> (String, Int32) {
        await withCheckedContinuation { continuation in
            DispatchQueue.global(qos: .userInitiated).async { [self] in
                let process = Process()
                let pipe = Pipe()

                process.executableURL = URL(fileURLWithPath: "/bin/zsh")
                process.arguments = ["-l", "-c", "cd \(self.projectPath) && \(command)"]
                process.standardOutput = pipe
                process.standardError = pipe
                process.environment = ProcessInfo.processInfo.environment

                var accumulated = ""

                pipe.fileHandleForReading.readabilityHandler = { handle in
                    let data = handle.availableData
                    guard !data.isEmpty else { return }
                    if let str = String(data: data, encoding: .utf8) {
                        accumulated += str
                        let stripped = Self.stripAnsi(str)
                        DispatchQueue.main.async {
                            self.output += stripped
                        }
                    }
                }

                DispatchQueue.main.async {
                    self.currentProcess = process
                }

                do {
                    try process.run()
                    process.waitUntilExit()
                    pipe.fileHandleForReading.readabilityHandler = nil

                    // Read any remaining data.
                    let remaining = pipe.fileHandleForReading.readDataToEndOfFile()
                    if let str = String(data: remaining, encoding: .utf8), !str.isEmpty {
                        accumulated += str
                        let stripped = Self.stripAnsi(str)
                        DispatchQueue.main.async {
                            self.output += stripped
                        }
                    }

                    continuation.resume(returning: (accumulated, process.terminationStatus))
                } catch {
                    continuation.resume(returning: ("Error: \(error.localizedDescription)", -1))
                }
            }
        }
    }

    static func stripAnsi(_ text: String) -> String {
        // Remove ANSI escape codes.
        text.replacingOccurrences(
            of: #"\x1B\[[0-9;]*[a-zA-Z]"#,
            with: "",
            options: .regularExpression
        )
    }
}
