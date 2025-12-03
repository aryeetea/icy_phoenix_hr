<?php
// schedule_requests_approval.php
session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/notification_helper.php";

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

/**
 * If manager, find their department.
 */
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

/**
 * Helper: upsert into work_schedules for approved change.
 */
function ipx_apply_schedule_change(
    mysqli $mysqli,
    string $emp_no,
    string $work_date,
    ?string $start_time,
    ?string $end_time,
    int $is_off
): bool {
    // Does a row exist?
    $check = $mysqli->prepare("
        SELECT COUNT(*) AS c
        FROM work_schedules
        WHERE emp_no = ? AND work_date = ?
    ");
    if (!$check) return false;
    $check->bind_param("ss", $emp_no, $work_date);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    $exists = $row && (int)$row["c"] > 0;

    if ($exists) {
        $stmt = $mysqli->prepare("
            UPDATE work_schedules
            SET start_time = ?, end_time = ?, is_off = ?
            WHERE emp_no = ? AND work_date = ?
        ");
        if (!$stmt) return false;
        $stmt->bind_param("ssiss", $start_time, $end_time, $is_off, $emp_no, $work_date);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    } else {
        $stmt = $mysqli->prepare("
            INSERT INTO work_schedules (emp_no, work_date, start_time, end_time, is_off)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$stmt) return false;
        $stmt->bind_param("ssssi", $emp_no, $work_date, $start_time, $end_time, $is_off);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

/* -------------------------------------------------
   Handle approve / reject
---------------------------------------------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["decision"])) {
    $req_id  = (int)($_POST["req_id"] ?? 0);
    $decision = $_POST["decision"]; // 'approved' or 'rejected'
    $comment  = trim($_POST["manager_comment"] ?? "");

    if ($req_id <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
        $feedback_msg = "Something went wrong with your decision.";
    } else {
        // Check permission + load needed fields
        if ($is_ceo) {
            $check_sql = "
                SELECT r.*, e.dept_no, e.email
                FROM schedule_change_requests r
                JOIN employees e ON r.emp_no = e.emp_no
                WHERE r.id = ? AND r.status = 'pending'
                LIMIT 1
            ";
            $check_stmt = $mysqli->prepare($check_sql);
            if ($check_stmt) {
                $check_stmt->bind_param("i", $req_id);
            }
        } else {
            $check_sql = "
                SELECT r.*, e.dept_no, e.email
                FROM schedule_change_requests r
                JOIN employees e ON r.emp_no = e.emp_no
                WHERE r.id = ?
                  AND r.status = 'pending'
                  AND e.dept_no = ?
                LIMIT 1
            ";
            $check_stmt = $mysqli->prepare($check_sql);
            if ($check_stmt) {
                $check_stmt->bind_param("is", $req_id, $manager_dept);
            }
        }

        if ($check_stmt) {
            $check_stmt->execute();
            $request = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if (!$request) {
                $feedback_msg = "You are not allowed to decide on this request, or it is no longer pending.";
            } else {
                // If approving: push into work_schedules
                if ($decision === 'approved') {
                    $ok = ipx_apply_schedule_change(
                        $mysqli,
                        $request["emp_no"],
                        $request["work_date"],
                        $request["requested_is_off"] ? null : $request["requested_start_time"],
                        $request["requested_is_off"] ? null : $request["requested_end_time"],
                        (int)$request["requested_is_off"]
                    );
                    if (!$ok) {
                        $feedback_msg = "Could not apply the new schedule to the calendar.";
                    }
                }

                if ($feedback_msg === "") {
                    // Update request status
                    $update = $mysqli->prepare("
                        UPDATE schedule_change_requests
                        SET status = ?, manager_comment = ?, decided_by = ?, decided_at = NOW()
                        WHERE id = ?
                    ");
                    if ($update) {
                        $update->bind_param("sssi", $decision, $comment, $current_emp_no, $req_id);
                        if ($update->execute()) {
                            $feedback_ok = true;
                            $feedback_msg = "Schedule change has been " .
                                ($decision === 'approved' ? 'approved and applied.' : 'rejected.');

                            // Notification to employee
                            if (function_exists('ipx_add_notification')) {
                                $title = "Schedule change " . $decision;
                                $body  = "Your schedule change for " . $request["work_date"] .
                                         " has been " . $decision . ".";
                                ipx_add_notification($mysqli, $request["emp_no"], $title, $body);
                            }
                        } else {
                            $feedback_msg = "Could not save your decision.";
                        }
                        $update->close();
                    } else {
                        $feedback_msg = "Database error when saving decision.";
                    }
                }
            }
        } else {
            $feedback_msg = "Database error when checking permission.";
        }
    }
}

/* -------------------------------------------------
   Load requests to display
---------------------------------------------------- */
if ($is_ceo) {
    $sql = "
        SELECT r.*, e.first_name, e.last_name, e.dept_no, e.role, d.dept_name
        FROM schedule_change_requests r
        JOIN employees e ON r.emp_no = e.emp_no
        LEFT JOIN departments d ON e.dept_no = d.dept_no
        ORDER BY
          FIELD(r.status, 'pending','approved','rejected'),
          r.work_date DESC
    ";
    $stmt = $mysqli->prepare($sql);
} else {
    $sql = "
        SELECT r.*, e.first_name, e.last_name, e.dept_no, e.role, d.dept_name
        FROM schedule_change_requests r
        JOIN employees e ON r.emp_no = e.emp_no
        LEFT JOIN departments d ON e.dept_no = d.dept_no
        WHERE e.dept_no = ?
        ORDER BY
          FIELD(r.status, 'pending','approved','rejected'),
          r.work_date DESC
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

$back_link = $is_ceo ? "ceo_dashboard.php" : "manager_dashboard.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Schedule Change Approvals</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="<?php echo htmlspecialchars($current_role); ?>">
<div class="container">
    <header class="top-bar">
        <h1>Schedule Change Requests</h1>
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
        <h2>
            <?php echo $is_ceo
                ? "All schedule change requests"
                : "Schedule change requests for your department"; ?>
        </h2>

        <?php if (!$requests || $requests->num_rows === 0): ?>
            <p>No schedule change requests yet.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Dept</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Requested Shift</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Your Decision</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($r = $requests->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($r["emp_no"] . " – " . $r["first_name"] . " " . $r["last_name"]); ?>
                            <br>
                            <small><?php echo htmlspecialchars(ucfirst($r["role"])); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($r["dept_name"] ?? ""); ?></td>
                        <td><?php echo htmlspecialchars($r["work_date"]); ?></td>
                        <td><?php echo $r["requested_is_off"] ? "Day off" : "Shift / overtime"; ?></td>
                        <td>
                            <?php if ($r["requested_is_off"]): ?>
                                Off (no shift)
                            <?php else: ?>
                                <?php
                                    if ($r["requested_start_time"] && $r["requested_end_time"]) {
                                        echo htmlspecialchars(substr($r["requested_start_time"], 0, 5)) .
                                             " – " .
                                             htmlspecialchars(substr($r["requested_end_time"], 0, 5));
                                    } else {
                                        echo "—";
                                    }
                                ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo nl2br(htmlspecialchars($r["reason"])); ?></td>
                        <td>
                            <span class="task-status <?php
                                echo $r["status"] === 'pending'  ? 'pending' :
                                     ($r["status"] === 'approved' ? 'done' : 'in_progress');
                            ?>">
                                <?php echo ucfirst($r["status"]); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($r["status"] === 'pending'): ?>
                                <form method="post" style="max-width:240px;">
                                    <input type="hidden" name="req_id" value="<?php echo (int)$r["id"]; ?>">
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
                                                echo "<br><em>Decided at: " .
                                                     htmlspecialchars($r["decided_at"]) .
                                                     "</em>";
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