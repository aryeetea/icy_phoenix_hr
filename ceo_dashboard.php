<?php
// ceo_dashboard.php
session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/salary_predict.php";   // salary suggestion helper
require_once __DIR__ . "/notification_helper.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'ceo') {
    header("Location: login.php");
    exit;
}

$ceo_emp_no = $_SESSION["emp_no"];
$ceo_name   = $_SESSION["emp_name"] ?? "CEO";

/* -------------------------------------------------
   Unread notifications
---------------------------------------------------- */
$unread_notifications = 0;
if (function_exists('ipx_get_unread_count')) {
    $unread_notifications = ipx_get_unread_count($mysqli, $ceo_emp_no);
}

/* -------------------------------------------------
   Quick Stats
---------------------------------------------------- */

// total employees
$row        = $mysqli->query("SELECT COUNT(*) AS c FROM employees")->fetch_assoc();
$total_emp  = (int)$row["c"];

// active employees
$row        = $mysqli->query("SELECT COUNT(*) AS c FROM employees WHERE employment_status = 'active'")->fetch_assoc();
$active_emp = (int)$row["c"];

// terminated employees
$row             = $mysqli->query("SELECT COUNT(*) AS c FROM employees WHERE employment_status = 'terminated'")->fetch_assoc();
$terminated_emp  = (int)$row["c"];

// managers
$row           = $mysqli->query("SELECT COUNT(*) AS c FROM employees WHERE role = 'manager'")->fetch_assoc();
$manager_count = (int)$row["c"];

/* -------------------------------------------------
   Department stats panel
---------------------------------------------------- */
$dept_stats = $mysqli->query("
    SELECT d.dept_no,
           d.dept_name,
           COUNT(e.emp_no) AS cnt
    FROM departments d
    LEFT JOIN employees e
      ON e.dept_no = d.dept_no
     AND e.employment_status = 'active'
    GROUP BY d.dept_no, d.dept_name
    ORDER BY d.dept_name
");

/* -------------------------------------------------
   Employee directory – EVERYONE (CEO + managers + employees)
---------------------------------------------------- */
$employees = $mysqli->query("
    SELECT e.emp_no,
           e.first_name,
           e.last_name,
           e.role,
           e.employment_status,
           e.hire_date,
           e.login_email,
           e.login_password,
           d.dept_name,
           t.title,
           s.salary,
           e.access_code
    FROM employees e
    LEFT JOIN departments d ON e.dept_no = d.dept_no
    LEFT JOIN titles      t ON e.title_id = t.id
    LEFT JOIN salaries    s ON e.emp_no  = s.emp_no AND s.is_current = 1
    ORDER BY
      FIELD(e.role, 'ceo','manager','employee'),
      CAST(e.emp_no AS UNSIGNED)
");

/* -------------------------------------------------
   Flash messages
---------------------------------------------------- */
$add_error   = $_SESSION["add_error"]   ?? "";
$add_success = $_SESSION["add_success"] ?? "";
unset($_SESSION["add_error"], $_SESSION["add_success"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – CEO Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="ceo">
<div class="container">
    <header class="top-bar">
        <h1>CEO Dashboard – <?php echo htmlspecialchars($ceo_name); ?></h1>
        <div class="top-actions">
            <a href="scan_access.php" class="btn btn-secondary btn-small">Access Scanner</a>
            <a href="projects.php" class="btn btn-secondary btn-small">Project Board</a>
            <a href="manager_tasks.php" class="btn btn-secondary btn-small">Team Tasks</a>
            <a href="manager_leaves.php" class="btn btn-secondary btn-small">Leave Approvals</a>
            <a href="printer_logs.php" class="btn btn-secondary btn-small">Printer Logs</a>
            <a href="security_dashboard.php" class="btn btn-secondary btn-small">Security</a>
            <a href="manager_performance.php" class="btn btn-secondary btn-small">Performance Reviews</a>
            <a href="analytics_dashboard.php" class="btn btn-secondary btn-small">Analytics</a>
            <a href="export_employees.php" class="btn btn-secondary btn-small">Export CSV</a>
            <a href="import_employees.php" class="btn btn-secondary btn-small">Import CSV</a>
            <a href="my_notifications.php" class="btn btn-secondary btn-small">
                Notifications<?php if ($unread_notifications > 0) echo " (" . (int)$unread_notifications . ")"; ?>
            </a>
            <a href="ceo_calendar.php" class="btn btn-secondary btn-small">Company Calendar</a>
            <a href="schedule_requests_approval.php" class="btn btn-secondary btn-small">Schedule Requests</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <?php if ($add_error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($add_error); ?></div>
    <?php endif; ?>
    <?php if ($add_success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($add_success); ?></div>
    <?php endif; ?>

    <div class="grid-3">
        <div class="card">
            <h2>Team Size</h2>
            <p><strong><?php echo $total_emp; ?></strong> total employees</p>
            <p><?php echo $active_emp; ?> active · <?php echo $terminated_emp; ?> terminated</p>
        </div>
        <div class="card">
            <h2>Leadership</h2>
            <p><strong><?php echo $manager_count; ?></strong> managers</p>
            <p>Plus CEO: you 🧊</p>
        </div>
        <div class="card">
            <h2>Access &amp; Devices</h2>
            <p>Each active employee has an access code.</p>
            <p>Used for gates, printers, and workstations.</p>
        </div>
    </div>

    <div class="card">
        <h2>Departments Overview</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Active Employees</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($d = $dept_stats->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($d["dept_name"]); ?></td>
                    <td><?php echo (int)$d["cnt"]; ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Employee Directory (all roles)</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Department</th>
                    <th>Title</th>
                    <th>Salary</th>
                    <th>Salary Insight</th>
                    <th>Access Code</th>
                    <th>Studio Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($e = $employees->fetch_assoc()): ?>
                <?php
                // AI-style salary suggestion (safe: only if helper exists and we have data)
                $salary_suggestion = null;
                if (
                    function_exists('ipx_predict_salary') &&
                    $e["salary"] !== null &&
                    !empty($e["title"]) &&
                    !empty($e["hire_date"])
                ) {
                    $salary_suggestion = ipx_predict_salary(
                        $mysqli,
                        (float)$e["salary"],
                        $e["title"],
                        $e["hire_date"]
                    );
                }
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($e["emp_no"]); ?></td>
                    <td><?php echo htmlspecialchars($e["first_name"] . " " . $e["last_name"]); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($e["role"])); ?></td>
                    <td><?php echo htmlspecialchars($e["employment_status"]); ?></td>
                    <td><?php echo htmlspecialchars($e["dept_name"]); ?></td>
                    <td><?php echo htmlspecialchars($e["title"]); ?></td>
                    <td>
                        <?php if ($e["salary"] !== null): ?>
                            $<?php echo number_format($e["salary"], 2); ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>

                        <?php if ($e["role"] !== 'ceo'): ?>
                            <!-- Inline salary update form for CEO -->
                            <form method="post" action="update_salary.php" style="margin-top:6px;">
                                <input type="hidden" name="emp_no"
                                       value="<?php echo htmlspecialchars($e["emp_no"]); ?>">
                                <input type="number" name="salary" step="0.01" min="0"
                                       value="<?php echo htmlspecialchars($e["salary"]); ?>"
                                       style="width:100px;">
                                <button type="submit" class="btn btn-small btn-primary" style="margin-top:4px;">
                                    Update
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($salary_suggestion): ?>
                            <strong>$<?php echo number_format($salary_suggestion["suggested"], 2); ?></strong><br>
                            <small>
                                Avg title: $<?php echo number_format($salary_suggestion["base_title_avg"], 2); ?><br>
                                Years: <?php echo number_format($salary_suggestion["years"], 1); ?>
                            </small>
                        <?php else: ?>
                            <span class="hint-text">No data yet</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($e["access_code"] ?? ""); ?></td>
                    <td>
                        <small>
                            <?php echo htmlspecialchars($e["login_email"] ?? ""); ?><br>
                            <?php echo htmlspecialchars($e["login_password"] ?? ""); ?>
                        </small>
                    </td>
                    <td>
                        <a class="btn btn-secondary btn-small"
                           href="employee_details.php?emp_no=<?php echo urlencode($e["emp_no"]); ?>">
                            View
                        </a>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>

</div>
</body>
</html>