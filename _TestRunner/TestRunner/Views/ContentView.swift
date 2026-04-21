import SwiftUI

struct ContentView: View {
    @StateObject private var vm = TestRunnerVM()

    var body: some View {
        NavigationSplitView {
            TestSidebarView(vm: vm)
                .navigationSplitViewColumnWidth(min: 250, ideal: 300, max: 400)
        } detail: {
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
        .navigationTitle("BeaverMind Test Runner")
        .onAppear {
            vm.refreshSpecs()
        }
    }
}
