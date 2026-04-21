import SwiftUI

struct ConsoleView: View {
    let output: String
    let isRunning: Bool
    let passedCount: Int
    let failedCount: Int
    let totalCount: Int
    let startTime: Date?
    let hasFailures: Bool
    let onStop: () -> Void
    let onClear: () -> Void
    let onShowScreenshots: () -> Void
    let onShowReport: () -> Void

    @State private var autoScroll = true

    var body: some View {
        VStack(spacing: 0) {
            // Toolbar
            HStack(spacing: 16) {
                // Status summary
                HStack(spacing: 12) {
                    if isRunning {
                        ProgressView()
                            .controlSize(.small)
                        Text("Running...")
                            .font(.headline)
                    } else if passedCount + failedCount > 0 {
                        Text(failedCount == 0 ? "All Passed" : "Done")
                            .font(.headline)
                            .foregroundStyle(failedCount == 0 ? .green : .red)
                    } else {
                        Text("Console")
                            .font(.headline)
                    }
                }

                Spacer()

                // Counters
                if passedCount + failedCount > 0 {
                    HStack(spacing: 8) {
                        Label("\(passedCount)", systemImage: "checkmark.circle.fill")
                            .foregroundStyle(.green)
                        Label("\(failedCount)", systemImage: "xmark.circle.fill")
                            .foregroundStyle(failedCount > 0 ? .red : .secondary)

                        Text("/ \(totalCount)")
                            .foregroundStyle(.secondary)
                    }
                    .font(.system(.body, design: .monospaced))
                }

                // Elapsed time
                if let start = startTime {
                    TimelineView(.periodic(from: start, by: 1)) { _ in
                        let elapsed = Date().timeIntervalSince(start)
                        Text(formatElapsed(elapsed))
                            .font(.system(.body, design: .monospaced))
                            .foregroundStyle(.secondary)
                    }
                }

                // Actions
                if isRunning {
                    Button("Stop", systemImage: "stop.fill") {
                        onStop()
                    }
                    .tint(.red)
                }

                if hasFailures && !isRunning {
                    Button("Screenshots", systemImage: "photo") {
                        onShowScreenshots()
                    }
                    .tint(.orange)

                    Button("Report", systemImage: "doc.text.magnifyingglass") {
                        onShowReport()
                    }
                }

                Button("Clear", systemImage: "trash") {
                    onClear()
                }
                .disabled(isRunning)
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 10)
            .background(.bar)

            Divider()

            // Console output
            ScrollViewReader { proxy in
                ScrollView {
                    Text(output.isEmpty ? "Ready. Click 'Run All' or select a test to begin." : output)
                        .font(.system(.body, design: .monospaced))
                        .foregroundStyle(output.isEmpty ? .secondary : .primary)
                        .textSelection(.enabled)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(12)
                        .id("bottom")
                }
                .background(Color(nsColor: .textBackgroundColor))
                .onChange(of: output) { _, _ in
                    if autoScroll {
                        proxy.scrollTo("bottom", anchor: .bottom)
                    }
                }
            }
        }
    }

    private func formatElapsed(_ seconds: TimeInterval) -> String {
        let mins = Int(seconds) / 60
        let secs = Int(seconds) % 60
        return String(format: "%d:%02d", mins, secs)
    }
}
