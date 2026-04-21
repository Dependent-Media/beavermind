import SwiftUI

struct TestRowView: View {
    let test: TestItem
    let result: TestResult?
    let onRun: () -> Void
    var onShowScreenshot: (() -> Void)?

    var body: some View {
        HStack(spacing: 8) {
            statusIcon
                .frame(width: 16, height: 16)

            Text(test.name)
                .font(.system(.body, design: .default))
                .lineLimit(2)
                .foregroundStyle(result?.status == .failed ? .red : .primary)

            Spacer()

            if let duration = result?.duration {
                Text(duration)
                    .font(.system(.caption, design: .monospaced))
                    .foregroundStyle(.secondary)
            }

            if result?.status == .failed, let onScreenshot = onShowScreenshot {
                Button {
                    onScreenshot()
                } label: {
                    Image(systemName: "photo")
                        .font(.caption)
                        .foregroundStyle(.orange)
                }
                .buttonStyle(.borderless)
                .help("View failure screenshot")
            }

            Button {
                onRun()
            } label: {
                Image(systemName: "play.fill")
                    .font(.caption)
            }
            .buttonStyle(.borderless)
            .help("Run this test")
        }
        .padding(.vertical, 2)
    }

    @ViewBuilder
    private var statusIcon: some View {
        switch result?.status ?? .pending {
        case .pending:
            Circle()
                .fill(.gray.opacity(0.3))
                .frame(width: 10, height: 10)
        case .running:
            ProgressView()
                .controlSize(.small)
        case .passed:
            Image(systemName: "checkmark.circle.fill")
                .foregroundStyle(.green)
        case .failed:
            Image(systemName: "xmark.circle.fill")
                .foregroundStyle(.red)
        case .skipped:
            Image(systemName: "minus.circle.fill")
                .foregroundStyle(.orange)
        }
    }
}
