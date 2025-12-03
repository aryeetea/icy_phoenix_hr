<?php
// schedule_approvals.php
session_start();
require_once __DIR__ . "/db_connect.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"])) {
    header("Location: login.php");
    exit;
}

$current_emp_no = $_SESSION["emp_no"];
$current_role   = $_SESSION["role"];
$current_name   = $_SESSION["emp_name"] ?? "";

if (!in_array($current_role, ['manager','ceo'], true)) {
    header("Location: login.php");
    exit;
}

$is_ceo = ($current_role === 'ceo');

$weekday_names = [
    1 => "Monday",
    2 => "Tuesday",
    3 => "Wednesday",
    4 => "Thursday",
    5 => "Friday",
    6 => "Saturday",
    7 => "Sunday",
];

$feedback_msg = "";
$feedback_ok  = false;

/* Manager dept */
$manager_dept = null;
if (!$is_ceo) {
    $stmt = $mysqli->prepare("SELECT dept_no FROM employees WHERE emp_no = ? LIMIT 1");
    $stmt->bind_param("s", $current_emp_no);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && !empty($row["dept_no"])) {
        $manager_dept = $row["dept_no"];
    }
}

/* Handle decisions */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["decision"])) {
    $req_id   = (int)($_POST["req_id"] ?? 0);
    $decision = $_POST["decision"]; // approved / rejected
    $comment  = trim($_POST["manager_comment"] ?? "");

    if ($req_id <= 0 || !in_array($decision, ['approved','rejected'], true)) {
        $feedback_msg = "Something went wrong with this decision.";
    } else {
        if ($is_ceo) {
            $check_sql = "
                SELECT scr.id
                FROM schedule_change_requests scr
                JOIN employees e ON scr.emp_no = e.emp_no
                WHERE scr.id = ? AND scr.status = 'pending'
            ";
            $check = $mysqli->prepare($check_sql);
            $check->bind_param("i", $req_id);
        } else {
            $check_sql = "
                SELECT scr.id
                FROM schedule_change_requests scr
                JOIN employees e ON scr.emp_no = e.emp_no
                WHERE scr.id = ?
                  AND scr.status = 'pending'
                  AND e.role = 'employee'
                  AND e.dept_no = ?
            ";
            $check = $mysqli->prepare($check_sql);
            $check->bind_param("is", $req_id, $manager_dept);
        }

        if ($check) {
            $check->execute();
            $found = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$found) {
                $feedback_msg = "You cannot decide on this request or it is no longer pending.";
            } else {
                $upd = $mysqli->prepare("
                    UPDATE schedule_change_requests
                    SET status = ?, manager_comment = ?, decided_by = ?, decided_at = NOW()
                    WHERE id = ?
                ");
                if ($upd) {
                    $upd->bind_param("sssi", $decision, $comment, $current_emp_no, $req_id);
                    if ($upd->execute()) {
                        $feedback_ok  = true;
                        $feedback_msg = "Schedule request has been " . ($decision === 'approved' ? 'approved' : 'rejected') . ".";
                    } else {
                        $feedback_msg = "Could not save your decision.";
                    }
                    $upd->close();
                } else {
                    $feedback_msg = "Database error when saving decision.";
                }
            }
        } else {
            $feedback_msg = "Database error when checking request.";
        }
    }
}

/* Load requests */
if ($is_ceo) {
    $sql = "
        SELECT scr.*, e.first_name, e.last_name, e.role, d.dept_name
        FROM schedule_change_requests scr
        JOIN employees e ON scr.emp_no = e.emp_no
        LEFT JOIN departments d ON e.dept_no = d.dept_no
        ORDER BY FIELD(scr.status,'pending','approved','rejected'),
                 scr.request_date DESC
    ";
    $stmt = $mysqli->prepare($sql);
} else {
    $sql = "
        SELECT scr.*, e.first_name, e.last_name, e.role, d.dept_name
        FROM schedule_change_requests scr
        JOIN employees e ON scr.emp_no = e.emp_no
        LEFT JOIN departments d ON e.dept_no = d.dept_no
        WHERE e.role = 'employee'
          AND e.dept_no = ?
        ORDER BY FIELD(scr.status,'pending','approved','rejected'),
                 scr.request_date DESC
    ";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("s", $manager_dept);
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
    <title>Icy Phoenix – Schedule Approvals</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="<?php echo htmlspecialchars($current_role); ?>">
<div class="container">
    <header class="top-bar">
        <h1>Schedule & Overtime Approvals</h1>
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
        <h2><?php echo $is_ceo ? "All schedule requests" : "Your team’s schedule requests"; ?></h2>

        <?php if (!$requests || $requests->num_rows === 0): ?>
            <p>No schedule requests yet.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Employee</th>
                    <th>Role</th>
                    <th>Dept</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Current</th>
                    <th>Requested</th>
                    <th>Status</th>
                    <th>Decision</th>
                </tr>
                </thead>
                <tbody>
                <?php while ($r = $requests->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r["emp_no"] . " – " . $r["first_name"] . " " . $r["last_name"]); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($r["role"])); ?></td>
                        <td><?php echo htmlspecialchars($r["dept_name"] ?? ""); ?></td>
                        <td>
                            <?php echo htmlspecialchars($r["request_date"]); ?><br>
                            <small><?php echo $weekday_names[(int)$r["weekday"]] ?? ""; ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($r["request_type"]); ?></td>
                        <td>
                            <?php
                            $cs = $r["current_start"] ? substr($r["current_start"], 0, 5) : "";
                            $ce = $r["current_end"]   ? substr($r["current_end"], 0, 5)   : "";
                            echo ($cs || $ce) ? "{$cs}–{$ce}" : "—";
                            ?>
                        </td>
                        <td>
                            <?php
                            $rs = $r["requested_start"] ? substr($r["requested_start"], 0, 5) : "";
                            $re = $r["requested_end"]   ? substr($r["requested_end"], 0, 5)   : "";
                            echo ($rs || $re) ? "{$rs}–{$re}" : "—";
                            ?>
                        </td>
                        <td>
                            <span class="task-status <?php
                                echo $r['status'] === 'approved' ? 'done'
                                     : ($r['status'] === 'pending' ? 'pending' : 'in_progress');
                            ?>">
                                <?php echo ucfirst($r["status"]); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($r["status"] === 'pending'): ?>
                                <form method="post" style="max-width:230px;">
                                    <input type="hidden" name="req_id" value="<?php echo (int)$r["id"]; ?>">
                                    <textarea name="manager_comment"
                                              placeholder="Comment (overtime rules, etc.)"
                                              style="margin-bottom:6px;"></textarea>
                                    <div style="display:flex; gap:6px;">
                                        <button type="submit" name="decision" value="approved"
                                                class="btn btn-primary btn-small">
                                            Approve
                                        </button>
                                        <button type="submit" name="decision" value="rejected"
                                                class="btn btn-danger btn-small">
                                            Reject
                                        </button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div>
                                    <strong><?php echo ucfirst($r["status"]); ?></strong><br>
                                    <small><?php echo nl2br(htmlspecialchars($r["manager_comment"] ?? "")); ?></small>
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