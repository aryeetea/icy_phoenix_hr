<?php
// employee_dashboard.php
session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/rank_helpers.php"; // if you already have this for ranks, keep it
require_once __DIR__ . "/notification_helper.php";
require_once __DIR__ . "/leave_helpers.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'employee') {
    header("Location: login.php");
    exit;
}

$emp_no   = $_SESSION["emp_no"];
$emp_name = $_SESSION["emp_name"] ?? "";

/**
 * Check if this user is on the clock today.
 * On the clock = attendance row today with status='present',
 * check_in_time NOT NULL, check_out_time NULL.
 */
function ipx_is_on_clock(mysqli $mysqli, string $emp_no): bool {
    $today = date('Y-m-d');
    $stmt = $mysqli->prepare("
        SELECT status, check_in_time, check_out_time
        FROM attendance
        WHERE emp_no = ? AND attendance_date = ?
        LIMIT 1
    ");
    if (!$stmt) return false;

    $stmt->bind_param("ss", $emp_no, $today);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if (!$row) return false;
    if ($row["status"] !== "present") return false;
    if ($row["check_in_time"] === null) return false;
    if ($row["check_out_time"] !== null) return false;

    return true;
}

/**
 * Rank helper (if not already in rank_helpers.php)
 * You can delete this if rank_helpers.php already defines ip_get_rank.
 */
if (!function_exists('ip_get_rank')) {
    function ip_get_rank(?float $salary): string {
        if ($salary === null) return "Unranked";
        if ($salary < 60000)  return "Bronze";
        if ($salary < 80000)  return "Silver";
        if ($salary < 100000) return "Gold";
        if ($salary < 130000) return "Platinum";
        return "Mythic";
    }
}

$is_on_clock = ipx_is_on_clock($mysqli, $emp_no);

// unread notifications (safe if helper missing)
$unread_notifications = 0;
if (function_exists('ipx_get_unread_count')) {
    $unread_notifications = ipx_get_unread_count($mysqli, $emp_no);
}

/* -------------------------------------------------
   IF NOT CLOCKED IN → show ONLY the gate message
------------------------------------------------- */
if (!$is_on_clock): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Employee Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="employee">
<div class="container">
    <header class="top-bar">
        <h1>Welcome, <?php echo htmlspecialchars($emp_name); ?></h1>
        <div>
            <a href="scan_access.php" class="btn btn-primary btn-small">Go to Access Scanner</a>
            <a href="employee_calendar.php" class="btn btn-secondary btn-small">My Calendar</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <div class="card">
        <h2>Clock In Required</h2>
        <p>
            You are currently <strong>not on the clock</strong>.  
            To see your information, tasks, and projects, you must first
            <strong>scan at the Gate</strong> using your access code.
        </p>
        <p class="hint-text">
            Go to <strong>Access Scanner</strong>, choose
            <strong>Gate (Clock In / Out)</strong>, and enter your access code.
            Once you are clocked in, come back here to see your dashboard.
        </p>
        <a href="scan_access.php" class="btn btn-primary">Open Access Scanner</a>
    </div>
</div>
</body>
</html>
<?php
exit;
endif;

/* -------------------------------------------------
   FROM HERE ON: only runs if they ARE on the clock
------------------------------------------------- */

// Load employee information
$sql = "
SELECT e.emp_no,
       e.first_name,
       e.last_name,
       e.birth_date,
       e.hire_date,
       e.access_code,
       e.login_email,
       e.login_password,
       d.dept_name,
       t.title,
       s.salary,
       dm.manager_name
FROM employees e
LEFT JOIN departments d       ON e.dept_no = d.dept_no
LEFT JOIN titles t            ON e.title_id = t.id
LEFT JOIN salaries s          ON e.emp_no = s.emp_no AND s.is_current = 1
LEFT JOIN department_managers dm ON e.dept_no = dm.dept_no
WHERE e.emp_no = ?
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $emp_no);
$stmt->execute();
$result   = $stmt->get_result();
$employee = $result->fetch_assoc();
$stmt->close();

$salary   = $employee["salary"] !== null ? (float)$employee["salary"] : null;
$rank     = ip_get_rank($salary);

// Tasks for this employee
$tasks_stmt = $mysqli->prepare("
    SELECT id, task_title, task_description, due_date, status
    FROM tasks
    WHERE emp_no = ?
    ORDER BY
      CASE status
          WHEN 'pending' THEN 1
          WHEN 'in_progress' THEN 2
          WHEN 'done' THEN 3
      END,
      COALESCE(due_date, '9999-12-31')
");
$tasks_stmt->bind_param("s", $emp_no);
$tasks_stmt->execute();
$tasks = $tasks_stmt->get_result();
$tasks_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Employee Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="employee">

<div class="container">
    <header class="top-bar">
        <h1>Welcome, <?php echo htmlspecialchars($emp_name); ?></h1>
        <div>
            <a href="scan_access.php" class="btn btn-secondary btn-small">My Access Scanner</a>
            <a href="printer_access_employee.php" class="btn btn-secondary btn-small">My Printer Access</a>
            <a href="projects.php" class="btn btn-secondary btn-small">Project Board</a>
            <a href="my_leave.php" class="btn btn-secondary btn-small">My Leave</a>
            <a href="my_notifications.php" class="btn btn-secondary btn-small">
                Notifications<?php if ($unread_notifications > 0) echo " (" . (int)$unread_notifications . ")"; ?>
            </a>
            <a href="employee_calendar.php" class="btn btn-secondary btn-small">My Calendar</a>
            <a href="team_schedule.php" class="btn btn-secondary btn-small">Company Schedule</a>
            <a href="my_schedule_requests.php" class="btn btn-secondary btn-small">Schedule Change</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <div class="card">
        <h2>Your Information</h2>
        <p><strong>Employee No:</strong> <?php echo htmlspecialchars($employee["emp_no"]); ?></p>
        <p><strong>Name:</strong> <?php echo htmlspecialchars($employee["first_name"] . " " . $employee["last_name"]); ?></p>
        <p><strong>Birthdate:</strong> <?php echo htmlspecialchars($employee["birth_date"]); ?></p>
        <p><strong>Hire Date:</strong> <?php echo htmlspecialchars($employee["hire_date"]); ?></p>
        <p><strong>Department:</strong> <?php echo htmlspecialchars($employee["dept_name"]); ?></p>
        <p><strong>Title:</strong> <?php echo htmlspecialchars($employee["title"]); ?></p>
        <p><strong>Salary:</strong>
            <?php echo $salary !== null ? "$" . number_format($salary, 2) : "—"; ?>
        </p>
        <p><strong>Rank:</strong> <?php echo htmlspecialchars($rank); ?></p>
        <p><strong>Manager:</strong> <?php echo htmlspecialchars($employee["manager_name"]); ?></p>
        <p><strong>Access Code (gates / printers / devices):</strong>
            <?php echo htmlspecialchars($employee["access_code"]); ?>
        </p>
        <p><strong>Studio Login Email:</strong>
            <?php echo htmlspecialchars($employee["login_email"] ?? ""); ?>
        </p>
        <p><strong>Studio Login Password:</strong>
            <?php echo htmlspecialchars($employee["login_password"] ?? ""); ?>
        </p>
    </div>

    <div class="card">
        <h2>Your Tasks</h2>
        <?php if ($tasks->num_rows === 0): ?>
            <p>You have no tasks assigned yet.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Due</th>
                        <th>Status</th>
                        <th>Update</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($task = $tasks->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($task["task_title"]); ?></strong><br>
                            <small><?php echo nl2br(htmlspecialchars($task["task_description"])); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($task["due_date"]); ?></td>
                        <td>
                            <span class="task-status <?php echo htmlspecialchars($task["status"]); ?>">
                                <?php echo str_replace('_', ' ', ucfirst($task["status"])); ?>
                            </span>
                        </td>
                        <td>
                            <form method="post" action="update_task_status.php">
                                <input type="hidden" name="task_id"
                                       value="<?php echo (int)$task["id"]; ?>">
                                <select name="status">
                                    <option value="pending"     <?php if ($task["status"] === 'pending') echo 'selected'; ?>>Pending</option>
                                    <option value="in_progress" <?php if ($task["status"] === 'in_progress') echo 'selected'; ?>>In progress</option>
                                    <option value="done"        <?php if ($task["status"] === 'done') echo 'selected'; ?>>Done</option>
                                </select>
                                <button type="submit" class="btn btn-small btn-primary" style="margin-top:6px;">
                                    Save
                                </button>
                            </form>
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