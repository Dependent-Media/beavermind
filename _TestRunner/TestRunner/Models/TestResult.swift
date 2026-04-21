import Foundation

enum TestStatus: String {
    case pending
    case running
    case passed
    case failed
    case skipped
}

struct TestResult: Identifiable {
    let id = UUID()
    let testName: String
    var status: TestStatus
    var duration: String?
    var errorMessage: String?
}
