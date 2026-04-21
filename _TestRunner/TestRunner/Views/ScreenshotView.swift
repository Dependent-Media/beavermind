import SwiftUI
import AppKit

/// In-app preview for a Playwright failure screenshot. Shows the image at
/// aspect-fit scale with a toolbar that offers "Back to console", "Open
/// externally" (Preview.app), and "Reveal in Finder".
struct ScreenshotView: View {
    let path: String
    let testName: String
    let onDismiss: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            // Toolbar
            HStack(spacing: 12) {
                Button("Back to Console", systemImage: "arrow.left") { onDismiss() }
                    .keyboardShortcut(.escape, modifiers: [])

                Divider().frame(height: 18)

                VStack(alignment: .leading, spacing: 2) {
                    Text(testName)
                        .font(.system(.body, weight: .medium))
                        .lineLimit(1)
                    Text((path as NSString).lastPathComponent)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }

                Spacer()

                Button("Open", systemImage: "arrow.up.right.square") {
                    NSWorkspace.shared.open(URL(fileURLWithPath: path))
                }
                .help("Open in default image viewer")

                Button("Reveal", systemImage: "folder") {
                    NSWorkspace.shared.selectFile(path, inFileViewerRootedAtPath: "")
                }
                .help("Reveal in Finder")
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 10)
            .background(.bar)

            Divider()

            // Image
            if let image = NSImage(contentsOfFile: path) {
                ScrollView([.horizontal, .vertical]) {
                    Image(nsImage: image)
                        .resizable()
                        .aspectRatio(contentMode: .fit)
                        .frame(minWidth: 400, minHeight: 300)
                        .padding(16)
                }
                .background(Color(nsColor: .underPageBackgroundColor))
            } else {
                VStack(spacing: 12) {
                    Spacer()
                    Image(systemName: "photo.badge.exclamationmark")
                        .font(.system(size: 40))
                        .foregroundStyle(.secondary)
                    Text("Couldn't load image")
                        .font(.headline)
                        .foregroundStyle(.secondary)
                    Text(path)
                        .font(.caption.monospaced())
                        .foregroundStyle(.tertiary)
                        .textSelection(.enabled)
                    Spacer()
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
    }
}
