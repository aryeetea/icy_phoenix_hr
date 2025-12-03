<?php
// my_notifications.php
session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/notification_helper.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"])) {
    header("Location: login.php");
    exit;
}

$emp_no   = $_SESSION["emp_no"];
$emp_name = $_SESSION["emp_name"] ?? "";
$role     = $_SESSION["role"];

$feedback_msg = "";
$feedback_ok  = false;

/**
 * Mark all notifications as read for this user.
 */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["mark_all_read"])) {
    $stmt = $mysqli->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE emp_no = ?
    ");
    if ($stmt) {
        $stmt->bind_param("s", $emp_no);
        if ($stmt->execute()) {
            $feedback_ok  = true;
            $feedback_msg = "All notifications marked as read.";
        } else {
            $feedback_msg = "Could not update notifications.";
        }
        $stmt->close();
    }
}

/**
 * Load all notifications for this employee.
 */
$stmt = $mysqli->prepare("
    SELECT id, created_at, title, body, is_read
    FROM notifications
    WHERE emp_no = ?
    ORDER BY created_at DESC, id DESC
");
$stmt->bind_param("s", $emp_no);
$stmt->execute();
$notifications = $stmt->get_result();
$stmt->close();

/**
 * Decide back link based on role
 */
$back_link = "employee_dashboard.php";
if ($role === "manager") {
    $back_link = "manager_dashboard.php";
} elseif ($role === "ceo") {
    $back_link = "ceo_dashboard.php";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – My Notifications</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="<?php echo htmlspecialchars($role); ?>">
<div class="container">
    <header class="top-bar">
        <h1>Notifications for <?php echo htmlspecialchars($emp_name); ?></h1>
        <div class="top-actions">
            <a href="<?php echo htmlspecialchars($back_link); ?>" class="btn btn-secondary btn-small">
                Back to Dashboard
            </a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <?php if ($feedback_msg): ?>
        <div class="alert <?php echo $feedback_ok ? 'alert-success' : 'alert-error'; ?>">
            <?php echo htmlspecialchars($feedback_msg); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>My Notifications</h2>

        <?php if ($notifications->num_rows === 0): ?>
            <p>You have no notifications yet.</p>
        <?php else: ?>
            <form method="post" style="margin-bottom:10px;">
                <button type="submit" name="mark_all_read" class="btn btn-secondary btn-small">
                    Mark all as read
                </button>
            </form>

            <table class="table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Title</th>
                        <th>Message</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($n = $notifications->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($n["created_at"]); ?></td>
                        <td><strong><?php echo htmlspecialchars($n["title"]); ?></strong></td>
                        <td><?php echo nl2br(htmlspecialchars($n["body"])); ?></td>
                        <td>
                            <?php if ($n["is_read"]): ?>
                                <span class="task-status done">Read</span>
                            <?php else: ?>
                                <span class="task-status pending">New</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>