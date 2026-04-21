import Foundation
import UserNotifications

/// Thin wrapper around UNUserNotificationCenter so the VM doesn't have to
/// know about permissions or request lifecycles. All calls are best-effort —
/// if the user denies notifications, everything silently no-ops.
@MainActor
final class NotificationService {

    static let shared = NotificationService()

    private let center = UNUserNotificationCenter.current()
    private var permissionRequested = false

    private init() {}

    /// Ask for permission the first time this is called. Safe to call
    /// repeatedly — subsequent calls hit the cached authorization.
    func requestPermissionIfNeeded() async {
        guard !permissionRequested else { return }
        permissionRequested = true
        _ = try? await center.requestAuthorization(options: [.alert, .sound])
    }

    /// Post a notification about a completed test run.
    /// Intentionally swallows errors — this is UX sugar, not load-bearing.
    func postRunFinished(passed: Int, failed: Int, elapsedSeconds: TimeInterval) async {
        await requestPermissionIfNeeded()

        let settings = await center.notificationSettings()
        guard settings.authorizationStatus == .authorized
            || settings.authorizationStatus == .provisional else { return }

        let content = UNMutableNotificationContent()
        let total = passed + failed
        if failed == 0 {
            content.title = "✅ BeaverMind tests passed"
            content.body  = "\(passed) of \(total) tests in \(formatElapsed(elapsedSeconds))"
        } else {
            content.title = "❌ BeaverMind tests failed"
            content.body  = "\(failed) failing · \(passed) passing · \(formatElapsed(elapsedSeconds))"
        }
        content.sound = .default

        let request = UNNotificationRequest(
            identifier: "beavermind.testrun.\(UUID().uuidString)",
            content: content,
            trigger: nil
        )
        try? await center.add(request)
    }

    private func formatElapsed(_ seconds: TimeInterval) -> String {
        let mins = Int(seconds) / 60
        let secs = Int(seconds) % 60
        return mins > 0 ? "\(mins)m \(secs)s" : "\(secs)s"
    }
}
