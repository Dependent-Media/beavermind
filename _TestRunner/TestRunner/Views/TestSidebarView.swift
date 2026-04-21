import SwiftUI

struct TestSidebarView: View {
    @ObservedObject var vm: TestRunnerVM

    var body: some View {
        VStack(spacing: 0) {
            // Toolbar
            VStack(spacing: 6) {
                HStack(spacing: 8) {
                    Button("Run All", systemImage: "play.fill") {
                        vm.runAll()
                    }
                    .disabled(vm.isRunning || vm.specs.isEmpty)
                    .buttonStyle(.borderedProminent)
                    .controlSize(.small)

                    Button("Refresh", systemImage: "arrow.clockwise") {
                        vm.refreshSpecs()
                    }
                    .disabled(vm.isRunning)
                    .controlSize(.small)

                    Spacer()

                    Text("\(vm.totalCount) tests")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                // Run mode picker
                Picker("Mode", selection: $vm.runMode) {
                    ForEach(TestRunnerVM.RunMode.allCases) { mode in
                        Label(mode.rawValue, systemImage: mode.icon)
                            .tag(mode)
                    }
                }
                .pickerStyle(.segmented)
                .controlSize(.small)

                // BeaverMind-specific: mint a fresh wp-cli auth cookie before
                // running the Playwright suite. The clone spec depends on a
                // valid `.auth/state.json`; this button wraps the helper script.
                HStack(spacing: 6) {
                    Button("Refresh Auth Cookie", systemImage: "key.fill") {
                        vm.refreshAuthCookie()
                    }
                    .disabled(vm.isRunning)
                    .controlSize(.small)

                    Spacer()

                    if let status = vm.authCookieStatus {
                        Label(status.label, systemImage: status.icon)
                            .font(.caption2)
                            .foregroundStyle(status.color)
                            .help(status.tooltip)
                    }
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)

            // Filter
            TextField("Filter tests...", text: $vm.filterText)
                .textFieldStyle(.roundedBorder)
                .controlSize(.small)
                .padding(.horizontal, 12)
                .padding(.bottom, 8)

            Divider()

            // Test tree
            if vm.specs.isEmpty {
                VStack(spacing: 12) {
                    Spacer()
                    Image(systemName: "testtube.2")
                        .font(.system(size: 40))
                        .foregroundStyle(.secondary)
                    Text("No tests loaded")
                        .font(.headline)
                        .foregroundStyle(.secondary)
                    Text("Click Refresh to load tests")
                        .font(.caption)
                        .foregroundStyle(.tertiary)
                    Spacer()
                }
                .frame(maxWidth: .infinity)
            } else {
                List {
                    ForEach(vm.filteredSpecs) { spec in
                        DisclosureGroup {
                            ForEach(spec.tests) { test in
                                TestRowView(
                                    test: test,
                                    result: vm.results[test.fullTitle],
                                    onRun: { vm.runTest(test.fullTitle) },
                                    onShowScreenshot: { vm.openScreenshotForTest(test.name) }
                                )
                            }
                        } label: {
                            specLabel(spec)
                        }
                    }
                }
                .listStyle(.sidebar)
            }
        }
    }

    private func specLabel(_ spec: TestSpec) -> some View {
        HStack(spacing: 8) {
            specStatusIcon(spec)
                .frame(width: 16, height: 16)

            VStack(alignment: .leading, spacing: 2) {
                Text(spec.displayName)
                    .font(.system(.body, weight: .medium))
                Text("\(spec.tests.count) tests")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }

            Spacer()

            Button {
                vm.runSpec(spec.fileName)
            } label: {
                Image(systemName: "play.fill")
                    .font(.caption)
            }
            .buttonStyle(.borderless)
            .help("Run \(spec.fileName)")
            .disabled(vm.isRunning)
        }
    }

    @ViewBuilder
    private func specStatusIcon(_ spec: TestSpec) -> some View {
        let statuses = spec.tests.compactMap { vm.results[$0.fullTitle]?.status }
        let hasFailed = statuses.contains(.failed)
        let hasRunning = statuses.contains(.running)
        let allPassed = !statuses.isEmpty && statuses.allSatisfy { $0 == .passed }

        if hasRunning {
            ProgressView()
                .controlSize(.small)
        } else if hasFailed {
            Image(systemName: "xmark.circle.fill")
                .foregroundStyle(.red)
        } else if allPassed {
            Image(systemName: "checkmark.circle.fill")
                .foregroundStyle(.green)
        } else {
            Image(systemName: "doc.text")
                .foregroundStyle(.secondary)
        }
    }
}
