<?php
// manager_leaves.php  (Leave Approvals)
session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/email_helper.php";          // email sending (Mailtrap helper)
require_once __DIR__ . "/notification_helper.php";   // in-app notifications

if (!isset($_SESSION["emp_no"], $_SESSION["role"])) {
    header("Location: login.php");
    exit;
}

$current_emp_no = $_SESSION["emp_no"];
$current_role   = $_SESSION["role"];
$current_name   = $_SESSION["emp_name"] ?? "";

if (!in_array($current_role, ['manager', 'ceo'], true)) {
    header("Location: login.php");
    exit;
}

$is_ceo = ($current_role === 'ceo');

$feedback_msg = "";
$feedback_ok  = false;

/* -------------------------------------------------
   1) If manager, find their department
---------------------------------------------------- */
$manager_dept = null;

if (!$is_ceo) {
    $stmt = $mysqli->prepare("
        SELECT dept_no
        FROM employees
        WHERE emp_no = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $current_emp_no);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($res && !empty($res["dept_no"])) {
            $manager_dept = $res["dept_no"];
        }
    }
}

/* -------------------------------------------------
   2) Handle approve / reject  (+ email + notification)
---------------------------------------------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["decision"])) {
    $leave_id = (int)($_POST["leave_id"] ?? 0);
    $decision = $_POST["decision"]; // 'approved' or 'rejected'
    $comment  = trim($_POST["manager_comment"] ?? "");

    if ($leave_id <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
        $feedback_msg = "Something went wrong with your decision.";
    } else {
        // Extra safety: make sure this approver is allowed to handle this request
        if ($is_ceo) {
            // CEO can see everything: employees + managers
            $check_sql = "
                SELECT lr.id,
                       lr.emp_no,
                       lr.start_date,
                       lr.end_date,
                       lr.reason
                FROM leave_requests lr
                JOIN employees e ON lr.emp_no = e.emp_no
                WHERE lr.id = ?
                  AND lr.status = 'pending'
            ";
            $check_stmt = $mysqli->prepare($check_sql);
            if ($check_stmt) {
                $check_stmt->bind_param("i", $leave_id);
            }
        } else {
            // Manager: can only approve employees in their own department
            $check_sql = "
                SELECT lr.id,
                       lr.emp_no,
                       lr.start_date,
                       lr.end_date,
                       lr.reason
                FROM leave_requests lr
                JOIN employees e ON lr.emp_no = e.emp_no
                WHERE lr.id = ?
                  AND lr.status = 'pending'
                  AND e.role = 'employee'
                  AND e.dept_no = ?
            ";
            $check_stmt = $mysqli->prepare($check_sql);
            if ($check_stmt) {
                $check_stmt->bind_param("is", $leave_id, $manager_dept);
            }
        }

        $request_row = null;

        if ($check_stmt) {
            $check_stmt->execute();
            $request_row = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if (!$request_row) {
                $feedback_msg = "You are not allowed to decide on this leave request, or it is no longer pending.";
            } else {
                // IMPORTANT: leave_requests table should have:
                //   status (ENUM 'pending','approved','rejected')
                //   manager_comment TEXT
                //   decided_by VARCHAR
                //   decided_at DATETIME
                $update = $mysqli->prepare("
                    UPDATE leave_requests
                    SET status = ?, manager_comment = ?, decided_by = ?, decided_at = NOW()
                    WHERE id = ?
                ");
                if ($update) {
                    $update->bind_param("sssi", $decision, $comment, $current_emp_no, $leave_id);
                    if ($update->execute()) {
                        $feedback_ok  = true;
                        $feedback_msg = "Leave request has been " . ($decision === 'approved' ? 'approved' : 'rejected') . ".";

                        // ------------ EMAIL + IN-APP NOTIFICATIONS ------------
                        $emp_no_for_leave = $request_row["emp_no"];

                        // 1) In-app notification (shows up in My Notifications)
                        if (function_exists('ipx_create_notification')) {
                            $title   = "Leave " . ucfirst($decision);
                            $message = "Your leave request from "
                                     . $request_row["start_date"] . " to " . $request_row["end_date"]
                                     . " has been " . strtoupper($decision) . ".";
                            if ($comment !== "") {
                                $message .= " Manager comment: " . $comment;
                            }

                            ipx_create_notification(
                                $mysqli,
                                $emp_no_for_leave,
                                "leave_decision",
                                $title,
                                $message
                            );
                        }

                        // 2) Email notification (using internal icyphoenix-style email)
                        if (function_exists('ipx_get_employee_email') && function_exists('ipx_send_notification')) {
                            $info = ipx_get_employee_email($mysqli, $emp_no_for_leave);

                            if ($info) {
                                $to      = $info["email"];
                                $subject = "Leave request " . ucfirst($decision) . " – Icy Phoenix";

                                $body =
                                    "Hi " . $info["first_name"] . ",\n\n" .
                                    "Your leave request has been " . strtoupper($decision) . ".\n\n" .
                                    "Dates: " . $request_row["start_date"] . " → " . $request_row["end_date"] . "\n" .
                                    "Reason: " . $request_row["reason"] . "\n\n";

                                if ($comment !== "") {
                                    $body .= "Manager comment:\n" . $comment . "\n\n";
                                }

                                $body .=
                                    "Decided by: {$current_name} ({$current_role})\n\n" .
                                    "- Icy Phoenix HR";

                                ipx_send_notification($to, $subject, $body);
                            }
                        }
                        // -------------------------------------------------------
                    } else {
                        $feedback_msg = "Could not save your decision: " . $mysqli->error;
                    }
                    $update->close();
                } else {
                    $feedback_msg = "Database error when saving decision: " . $mysqli->error;
                }
            }
        } else {
            $feedback_msg = "Database error when checking permission: " . $mysqli->error;
        }
    }
}

/* -------------------------------------------------
   3) Load leave requests for display
---------------------------------------------------- */
/*
   Managers: see only employees in their department, and never managers.
   CEO: sees everyone (employees + managers).
*/
if ($is_ceo) {
    $sql = "
        SELECT lr.*, e.first_name, e.last_name, e.role, d.dept_name
        FROM leave_requests lr
        JOIN employees e ON lr.emp_no = e.emp_no
        LEFT JOIN departments d ON e.dept_no = d.dept_no
        ORDER BY 
          FIELD(lr.status, 'pending','approved','rejected'),
          lr.start_date
    ";
    $stmt = $mysqli->prepare($sql);
} else {
    $sql = "
        SELECT lr.*, e.first_name, e.last_name, e.role, d.dept_name
        FROM leave_requests lr
        JOIN employees e ON lr.emp_no = e.emp_no
        LEFT JOIN departments d ON e.dept_no = d.dept_no
        WHERE e.role = 'employee'
          AND e.dept_no = ?
        ORDER BY 
          FIELD(lr.status, 'pending','approved','rejected'),
          lr.start_date
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $manager_dept);
    }
}

$requests = null;
if ($stmt) {
    $stmt->execute();
    $requests = $stmt->get_result();
    $stmt->close();
}

/* -------------------------------------------------
   Back link
---------------------------------------------------- */
$back_link = $is_ceo ? "ceo_dashboard.php" : "manager_dashboard.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Leave Approvals</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="<?php echo htmlspecialchars($current_role); ?>">
<div class="container">
    <header class="top-bar">
        <h1>Leave Approvals</h1>
        <div class="top-actions">
            <a href="<?php echo htmlspecialchars($back_link); ?>" class="btn btn-secondary btn-small">Back to Dashboard</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <?php if ($feedback_msg): ?>
        <div class="alert <?php echo $feedback_ok ? 'alert-success' : 'alert-error'; ?>">
            <?php echo htmlspecialchars($feedback_msg); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2><?php echo $is_ceo ? "All leave requests (employees + managers)" : "Your team’s leave requests"; ?></h2>

        <?php if (!$requests || $requests->num_rows === 0): ?>
            <p>No leave requests yet.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Role</th>
                        <th>Department</th>
                        <th>Dates</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Your decision</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($r = $requests->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($r["emp_no"] . " – " . $r["first_name"] . " " . $r["last_name"]); ?>
                        </td>
                        <td><?php echo htmlspecialchars(ucfirst($r["role"])); ?></td>
                        <td><?php echo htmlspecialchars($r["dept_name"] ?? ""); ?></td>
                        <td>
                            <?php echo htmlspecialchars($r["start_date"]); ?>
                            &rarr;
                            <?php echo htmlspecialchars($r["end_date"]); ?>
                        </td>
                        <td><?php echo nl2br(htmlspecialchars($r["reason"])); ?></td>
                        <td>
                            <?php
                                $cls = 'pending';
                                if ($r["status"] === 'approved') $cls = 'done';
                                if ($r["status"] === 'rejected') $cls = 'in_progress'; // orange-ish badge
                            ?>
                            <span class="task-status <?php echo $cls; ?>">
                                <?php echo ucfirst($r["status"]); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($r["status"] === 'pending'): ?>
                                <form method="post" style="max-width:220px;">
                                    <input type="hidden" name="leave_id" value="<?php echo (int)$r["id"]; ?>">
                                    <textarea name="manager_comment" placeholder="Comment (optional)"
                                              style="margin-bottom:6px;"></textarea>
                                    <div style="display:flex; gap:6px;">
                                        <button type="submit" name="decision" value="approved"
                                                class="btn btn-primary btn-small">Approve</button>
                                        <button type="submit" name="decision" value="rejected"
                                                class="btn btn-danger btn-small">Reject</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div>
                                    <strong><?php echo ucfirst($r["status"]); ?></strong><br>
                                    <small>
                                        <?php
                                            echo nl2br(htmlspecialchars($r["manager_comment"] ?? ""));
                                            if (!empty($r["decided_at"])) {
                                                echo "<br><em>Decided at: " . htmlspecialchars($r["decided_at"]) . "</em>";
                                            }
                                        ?>
                                    </small>
                                </div>
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