<?php
// manager_dashboard.php
session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/rank_helpers.php";
require_once __DIR__ . "/salary_predict.php";
require_once __DIR__ . "/notification_helper.php";
require_once __DIR__ . "/leave_helpers.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'manager') {
    header("Location: login.php");
    exit;
}

$manager_no   = $_SESSION["emp_no"];
$manager_name = $_SESSION["emp_name"] ?? "Manager";

/**
 * Same rank logic as CEO board (fallback if rank_helpers doesn't define it).
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

// -------- Unread notifications count for this manager --------
$unread_notifications = 0;
if (function_exists('ipx_get_unread_count')) {
    $unread_notifications = ipx_get_unread_count($mysqli, $manager_no);
}

// -------- Manager info (including salary for rank) --------
$mgr_sql = "
SELECT e.emp_no, e.first_name, e.last_name, e.birth_date, e.hire_date,
       d.dept_no, d.dept_name, t.title, s.salary
FROM employees e
LEFT JOIN departments d ON e.dept_no = d.dept_no
LEFT JOIN titles t      ON e.title_id = t.id
LEFT JOIN salaries s    ON e.emp_no = s.emp_no AND s.is_current = 1
WHERE e.emp_no = ?
";
$stmt = $mysqli->prepare($mgr_sql);
$stmt->bind_param("s", $manager_no);
$stmt->execute();
$mgr_result = $stmt->get_result();
$manager    = $mgr_result->fetch_assoc();
$stmt->close();

$manager_dept = $manager["dept_no"] ?? null;
$manager_rank = ip_get_rank($manager["salary"] !== null ? (float)$manager["salary"] : null);

// -------- Employees in manager's department --------
$employees = [];
if ($manager_dept !== null) {
    $emp_stmt = $mysqli->prepare("
        SELECT emp_no, first_name, last_name
        FROM employees
        WHERE role = 'employee'
          AND dept_no = ?
          AND employment_status = 'active'
        ORDER BY first_name, last_name
    ");
    $emp_stmt->bind_param("s", $manager_dept);
    $emp_stmt->execute();
    $employees_result = $emp_stmt->get_result();
    $emp_stmt->close();

    while ($row = $employees_result->fetch_assoc()) {
        $employees[] = $row;
    }
}

$selected_emp_no   = $_GET["emp_no"] ?? ($employees[0]["emp_no"] ?? null);
$selected_employee = null;
$employee_reviews  = null;

if ($selected_emp_no) {
    $sql = "
    SELECT e.emp_no,
           e.first_name,
           e.last_name,
           e.birth_date,
           e.hire_date,
           e.employment_status,
           e.access_code,
           e.login_email,
           e.login_password,
           d.dept_no,
           d.dept_name,
           t.id AS title_id,
           t.title,
           s.salary,
           dm.manager_name
    FROM employees e
    LEFT JOIN departments d        ON e.dept_no = d.dept_no
    LEFT JOIN titles t             ON e.title_id = t.id
    LEFT JOIN salaries s           ON e.emp_no = s.emp_no AND s.is_current = 1
    LEFT JOIN department_managers dm ON e.dept_no = dm.dept_no
    WHERE e.emp_no = ?
    ";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("s", $selected_emp_no);
    $stmt->execute();
    $res = $stmt->get_result();
    $selected_employee = $res->fetch_assoc();
    $stmt->close();

    // Load recent performance reviews for this employee
    $rev_stmt = $mysqli->prepare("
        SELECT r.id,
               r.review_date,
               r.score,
               r.summary,
               r.comments,
               r.reviewer_emp_no,
               CONCAT(er.first_name, ' ', er.last_name) AS reviewer_name
        FROM performance_reviews r
        LEFT JOIN employees er ON r.reviewer_emp_no = er.emp_no
        WHERE r.emp_no = ?
        ORDER BY r.review_date DESC, r.id DESC
        LIMIT 5
    ");
    if ($rev_stmt) {
        $rev_stmt->bind_param("s", $selected_emp_no);
        $rev_stmt->execute();
        $employee_reviews = $rev_stmt->get_result();
        $rev_stmt->close();
    }
}

// For dropdowns
$departments = $mysqli->query("SELECT dept_no, dept_name FROM departments ORDER BY dept_name");
$titles      = $mysqli->query("SELECT id, title FROM titles ORDER BY title");

// Flash from other actions (optional)
$add_error   = $_SESSION["add_error"]   ?? "";
$add_success = $_SESSION["add_success"] ?? "";
unset($_SESSION["add_error"], $_SESSION["add_success"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Manager Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="manager">
<div class="container">

    <header class="top-bar">
    <h1>Manager View – <?php echo htmlspecialchars($manager_name); ?></h1>
    <div>
        <a href="scan_access.php" class="btn btn-secondary btn-small">Access Scanner</a>
        <a href="manager_tasks.php" class="btn btn-secondary btn-small">My Team Tasks</a>
        <a href="projects.php" class="btn btn-secondary btn-small">Project Board</a>
        <a href="my_leave.php" class="btn btn-secondary btn-small">My Leave</a>
        <a href="manager_schedule.php" class="btn btn-secondary btn-small">Team Schedule</a>
        <a href="export_employees.php" class="btn btn-secondary btn-small">Export CSV</a>
        <a href="my_notifications.php" class="btn btn-secondary btn-small">
            Notifications<?php if ($unread_notifications > 0) echo " (" . (int)$unread_notifications . ")"; ?>
        </a>
        <a href="team_schedule.php" class="btn btn-primary">View Team Schedule</a>
        <a href="manager_calendar.php" class="btn btn-secondary btn-small">My Calendar</a>
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

    <div class="card">
        <h2>Your Info</h2>
        <p><strong>Employee No:</strong> <?php echo htmlspecialchars($manager["emp_no"]); ?></p>
        <p><strong>Name:</strong> <?php echo htmlspecialchars($manager["first_name"] . " " . $manager["last_name"]); ?></p>
        <p><strong>Department:</strong> <?php echo htmlspecialchars($manager["dept_name"]); ?></p>
        <p><strong>Title:</strong> <?php echo htmlspecialchars($manager["title"]); ?></p>
        <p><strong>Salary:</strong>
            <?php echo $manager["salary"] !== null ? "$" . number_format($manager["salary"], 2) : "—"; ?>
        </p>
        <p><strong>Rank:</strong> <?php echo htmlspecialchars($manager_rank); ?></p>
        <span class="hint-text">
            Ranks: Bronze → Silver → Gold → Platinum → Mythic (based on your current salary).
        </span>
    </div>

    <div class="card">
        <h2>Select Employee</h2>

        <?php if (empty($employees)): ?>
            <p>No employees in your department yet.</p>
        <?php else: ?>
            <form method="get" style="max-width:360px;">
                <label for="emp_no">Employee</label>
                <select name="emp_no" id="emp_no" onchange="this.form.submit()">
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo $emp["emp_no"]; ?>"
                            <?php if ($emp["emp_no"] == $selected_emp_no) echo "selected"; ?>>
                            <?php echo htmlspecialchars($emp["emp_no"] . " – " . $emp["first_name"] . " " . $emp["last_name"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($selected_employee): ?>
        <div class="card">
            <h2>Employee Information</h2>
            <p><strong>Employee No:</strong> <?php echo htmlspecialchars($selected_employee["emp_no"]); ?></p>
            <p><strong>Name:</strong> <?php echo htmlspecialchars($selected_employee["first_name"] . " " . $selected_employee["last_name"]); ?></p>
            <p><strong>Birthdate:</strong> <?php echo htmlspecialchars($selected_employee["birth_date"]); ?></p>
            <p><strong>Hire Date:</strong> <?php echo htmlspecialchars($selected_employee["hire_date"]); ?></p>
            <p><strong>Status:</strong> <?php echo htmlspecialchars($selected_employee["employment_status"]); ?></p>
            <p><strong>Department:</strong> <?php echo htmlspecialchars($selected_employee["dept_name"]); ?></p>
            <p><strong>Title:</strong> <?php echo htmlspecialchars($selected_employee["title"]); ?></p>
            <p><strong>Salary:</strong>
                <?php echo $selected_employee["salary"] !== null
                    ? "$" . number_format($selected_employee["salary"], 2)
                    : "—"; ?>
            </p>
            <p><strong>Manager of record:</strong> <?php echo htmlspecialchars($selected_employee["manager_name"]); ?></p>
            <p><strong>Access Code:</strong> <?php echo htmlspecialchars($selected_employee["access_code"]); ?></p>
            <p><strong>Studio Login Email:</strong>
                <?php echo htmlspecialchars($selected_employee["login_email"] ?? ""); ?>
            </p>
            <p><strong>Studio Login Password:</strong>
                <?php echo htmlspecialchars($selected_employee["login_password"] ?? ""); ?>
            </p>
        </div>

        <div class="grid-2">
            <form class="card" method="post" action="update_department.php">
                <h2>Change Department</h2>
                <input type="hidden" name="emp_no" value="<?php echo htmlspecialchars($selected_employee["emp_no"]); ?>">
                <label for="dept_no">Department</label>
                <select name="dept_no" id="dept_no" required>
                    <?php while ($d = $departments->fetch_assoc()): ?>
                        <option value="<?php echo $d["dept_no"]; ?>"
                            <?php if ($d["dept_no"] === $selected_employee["dept_no"]) echo "selected"; ?>>
                            <?php echo htmlspecialchars($d["dept_name"]); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <button type="submit" class="btn btn-primary" style="margin-top:10px;">
                    Update Department
                </button>
            </form>

            <form class="card" method="post" action="update_title.php">
                <h2>Change Title</h2>
                <input type="hidden" name="emp_no" value="<?php echo htmlspecialchars($selected_employee["emp_no"]); ?>">
                <label for="title_id">Title</label>
                <select name="title_id" id="title_id" required>
                    <?php while ($t = $titles->fetch_assoc()): ?>
                        <option value="<?php echo $t["id"]; ?>"
                            <?php if ((int)$t["id"] === (int)$selected_employee["title_id"]) echo "selected"; ?>>
                            <?php echo htmlspecialchars($t["title"]); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <button type="submit" class="btn btn-primary" style="margin-top:10px;">
                    Update Title
                </button>
            </form>
        </div>

        <div class="grid-2">
            <form class="card" method="post" action="update_salary.php">
                <h2>Change Salary</h2>
                <input type="hidden" name="emp_no" value="<?php echo htmlspecialchars($selected_employee["emp_no"]); ?>">

                <?php
                // Optional AI-style salary suggestion, if helper exists
                $salary_suggestion = null;
                if (
                    function_exists('ipx_predict_salary') &&
                    $selected_employee["salary"] !== null &&
                    !empty($selected_employee["title"]) &&
                    !empty($selected_employee["hire_date"])
                ) {
                    $salary_suggestion = ipx_predict_salary(
                        $mysqli,
                        (float)$selected_employee["salary"],
                        $selected_employee["title"],
                        $selected_employee["hire_date"]
                    );
                }
                ?>

                <?php if ($salary_suggestion): ?>
                    <p class="hint-text">
                        Suggested salary based on title peers and years of service:
                        <strong>$<?php echo number_format($salary_suggestion["suggested"], 2); ?></strong>
                        (team avg: $<?php echo number_format($salary_suggestion["base_title_avg"], 2); ?>,
                        years here: <?php echo round($salary_suggestion["years"], 1); ?>)
                    </p>
                <?php endif; ?>

                <label for="salary">New Salary</label>
                <input type="number" id="salary" name="salary" step="0.01" min="0"
                       value="<?php echo htmlspecialchars($selected_employee["salary"]); ?>">
                <button type="submit" class="btn btn-primary" style="margin-top:10px;">
                    Update Salary
                </button>
            </form>

            <form class="card" method="post" action="fire_employee.php"
                  onsubmit="return confirm('Fire this employee from Icy Phoenix?');">
                <h2>Fire Employee</h2>
                <input type="hidden" name="emp_no" value="<?php echo htmlspecialchars($selected_employee["emp_no"]); ?>">
                <p>This will mark the employee as terminated and disable access.</p>
                <button type="submit" class="btn btn-danger">
                    Fire Employee
                </button>
            </form>
        </div>

        <div class="card">
            <h2>Performance Reviews for this Employee</h2>
            <?php if (!$employee_reviews || $employee_reviews->num_rows === 0): ?>
                <p>No reviews recorded yet for this employee.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Score</th>
                            <th>Summary</th>
                            <th>Reviewer</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($rev = $employee_reviews->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($rev["review_date"]); ?></td>
                            <td><?php echo htmlspecialchars($rev["score"]); ?>/5</td>
                            <td><?php echo nl2br(htmlspecialchars($rev["summary"])); ?></td>
                            <td><?php echo htmlspecialchars($rev["reviewer_name"] ?: $rev["reviewer_emp_no"]); ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
                <p class="hint-text">
                    Use your reviews page to write full performance reviews that tie into promotions and rank changes.
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>
</body>
</html>