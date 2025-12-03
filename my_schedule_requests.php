<?php
// my_schedule_requests.php
session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/notification_helper.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'employee') {
    header("Location: login.php");
    exit;
}

$emp_no   = $_SESSION["emp_no"];
$emp_name = $_SESSION["emp_name"] ?? "";

$feedback_msg = "";
$feedback_ok  = false;

/**
 * Helper: find this employee's manager (for notifications).
 */
function ipx_find_manager_for_employee(mysqli $mysqli, string $emp_no): ?string {
    $sql = "
        SELECT dm.manager_emp_no
        FROM employees e
        JOIN department_managers dm ON e.dept_no = dm.dept_no
        WHERE e.emp_no = ?
        LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param("s", $emp_no);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && !empty($row["manager_emp_no"])) {
        return $row["manager_emp_no"];
    }
    return null;
}

/* -------------------------------------------------
   Handle new request submission
---------------------------------------------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["new_request"])) {
    $work_date   = trim($_POST["work_date"] ?? "");
    $req_type    = $_POST["req_type"] ?? "shift"; // "shift" or "off"
    $start_time  = trim($_POST["start_time"] ?? "");
    $end_time    = trim($_POST["end_time"] ?? "");
    $reason      = trim($_POST["reason"] ?? "");

    if ($work_date === "") {
        $feedback_msg = "Please choose the date for your schedule change.";
    } else {
        $requested_is_off = 0;
        $req_start        = null;
        $req_end          = null;

        if ($req_type === "off") {
            $requested_is_off = 1;
        } else {
            // shift change / overtime
            if ($start_time === "" || $end_time === "") {
                $feedback_msg = "Please enter both start and end time for the shift.";
            } else {
                $requested_is_off = 0;
                $req_start        = $start_time . ":00";
                $req_end          = $end_time . ":00";
            }
        }

        if ($feedback_msg === "") {
            // Insert the request
            $stmt = $mysqli->prepare("
                INSERT INTO schedule_change_requests
                    (emp_no, work_date, requested_start_time, requested_end_time, requested_is_off, reason)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            if ($stmt) {
                $stmt->bind_param(
                    "ssssis",
                    $emp_no,
                    $work_date,
                    $req_start,
                    $req_end,
                    $requested_is_off,
                    $reason
                );
                if ($stmt->execute()) {
                    $feedback_ok  = true;
                    $feedback_msg = "Your schedule change request has been submitted.";

                    // Notification to manager (and maybe CEO fallback)
                    $manager_emp = ipx_find_manager_for_employee($mysqli, $emp_no);
                    if ($manager_emp && function_exists('ipx_add_notification')) {
                        $title = "New schedule change request";
                        $body  = "Employee #{$emp_no} has requested a schedule change for {$work_date}.";
                        ipx_add_notification($mysqli, $manager_emp, $title, $body);
                    }
                } else {
                    $feedback_msg = "Could not save your request.";
                }
                $stmt->close();
            } else {
                $feedback_msg = "Database error while creating request.";
            }
        }
    }
}

/* -------------------------------------------------
   Load this employee's requests
---------------------------------------------------- */
$requests = $mysqli->prepare("
    SELECT id, work_date, requested_start_time, requested_end_time, requested_is_off,
           reason, status, manager_comment, requested_at, decided_at
    FROM schedule_change_requests
    WHERE emp_no = ?
    ORDER BY requested_at DESC
");
$requests_list = null;
if ($requests) {
    $requests->bind_param("s", $emp_no);
    $requests->execute();
    $requests_list = $requests->get_result();
    $requests->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – My Schedule Requests</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="employee">
<div class="container">
    <header class="top-bar">
        <h1>Schedule Change – <?php echo htmlspecialchars($emp_name); ?></h1>
        <div>
            <a href="employee_dashboard.php" class="btn btn-secondary btn-small">Back to Dashboard</a>
            <a href="employee_calendar.php" class="btn btn-secondary btn-small">My Calendar</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <?php if ($feedback_msg): ?>
        <div class="alert <?php echo $feedback_ok ? 'alert-success' : 'alert-error'; ?>">
            <?php echo htmlspecialchars($feedback_msg); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Request a Schedule Change</h2>
        <form method="post" style="max-width:420px;">
            <input type="hidden" name="new_request" value="1">

            <label for="work_date">Date you want to change</label>
            <input type="date" id="work_date" name="work_date" required>

            <label>What do you want?</label>
            <select name="req_type" id="req_type" onchange="toggleShiftFields()">
                <option value="shift">Change my shift / work overtime</option>
                <option value="off">Request full day off</option>
            </select>

            <div id="shift_fields">
                <label for="start_time">New start time (HH:MM)</label>
                <input type="time" id="start_time" name="start_time">

                <label for="end_time">New end time (HH:MM)</label>
                <input type="time" id="end_time" name="end_time">
            </div>

            <label for="reason">Reason (optional but helpful)</label>
            <textarea id="reason" name="reason" placeholder="Explain why you need this change..."></textarea>

            <button type="submit" class="btn btn-primary" style="margin-top:10px;">
                Submit Request
            </button>

            <p class="hint-text" style="margin-top:6px;">
                Rules: You must still have at least two days off each week.  
                Working on a normal day off will count as overtime.
            </p>
        </form>
    </div>

    <div class="card">
        <h2>My Schedule Requests</h2>
        <?php if (!$requests_list || $requests_list->num_rows === 0): ?>
            <p>You have not sent any schedule change requests yet.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Requested At</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Requested Shift</th>
                        <th>Status</th>
                        <th>Manager Comment</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($r = $requests_list->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r["requested_at"]); ?></td>
                        <td><?php echo htmlspecialchars($r["work_date"]); ?></td>
                        <td>
                            <?php echo $r["requested_is_off"] ? "Day off" : "Shift / overtime"; ?>
                        </td>
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
                        <td>
                            <span class="task-status <?php
                                echo $r["status"] === 'pending'  ? 'pending' :
                                     ($r["status"] === 'approved' ? 'done' : 'in_progress');
                            ?>">
                                <?php echo ucfirst($r["status"]); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo nl2br(htmlspecialchars($r["manager_comment"] ?? "")); ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>.

<script>
function toggleShiftFields() {
    const select = document.getElementById('req_type');
    const block  = document.getElementById('shift_fields');
    if (select.value === 'off') {
        block.style.display = 'none';
    } else {
        block.style.display = 'block';
    }
}
// default on load
toggleShiftFields();
</script>
</body>
</html>