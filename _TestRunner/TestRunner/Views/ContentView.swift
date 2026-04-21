import SwiftUI

struct ContentView: View {
    @StateObject private var vm = TestRunnerVM()

    var body: some View {
        NavigationSplitView {
            TestSidebarView(vm: vm)
                .navigationSplitViewColumnWidth(min: 250, ideal: 300, max: 400)
        } detail: {
            // Flip detail pane between Console and ScreenshotView depending
            // on whether the user has clicked a failure screenshot.
            if let preview = vm.previewedScreenshot {
                ScreenshotView(
                    path: preview.path,
                    testName: preview.testName,
                    onDismiss: { vm.dismissScreenshotPreview() }
                )
            } else {
                ConsoleView(
                    output: vm.output,
                    isRunning: vm.isRunning,
                    passedCount: vm.passedCount,
                    failedCount: vm.failedCount,
                    totalCount: vm.totalCount,
                    startTime: vm.startTime,
                    hasFailures: vm.failedCount > 0,
                    onStop: { vm.stop() },
                    onClear: { vm.output = "" },
                    onShowScreenshots: { vm.openTestResults() },
                    onShowReport: { vm.openHTMLReport() }
                )
            }
        }
        .navigationTitle("BeaverMind Test Runner")
        .onAppear {
            vm.refreshSpecs()
            // Kick off a permission request so the first long run can notify
            // without first having to wait for a prompt.
            Task { await NotificationService.shared.requestPermissionIfNeeded() }
        }
    }
}
