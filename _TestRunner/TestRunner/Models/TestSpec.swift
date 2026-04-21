import Foundation

struct TestItem: Identifiable, Hashable {
    let id = UUID()
    let name: String
    let fullTitle: String // e.g. "Authentication & Dashboard > dashboard loads..."
}

struct TestSpec: Identifiable {
    let id = UUID()
    let fileName: String
    var tests: [TestItem]

    var displayName: String {
        fileName
            .replacingOccurrences(of: ".spec.js", with: "")
            .replacingOccurrences(of: "-", with: " ")
            .capitalized
    }
}
