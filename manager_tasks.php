<?php
// manager_tasks.php
session_start();
require_once __DIR__ . "/db_connect.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'manager') {
    header("Location: login.php");
    exit;
}

$manager_no   = $_SESSION["emp_no"];
$manager_name = $_SESSION["emp_name"] ?? "Manager";

/**
 * 1) Get manager's own department
 */
$mgr_sql = "
    SELECT e.emp_no, e.first_name, e.last_name,
           d.dept_no, d.dept_name
    FROM employees e
    LEFT JOIN departments d ON e.dept_no = d.dept_no
    WHERE e.emp_no = ?
    LIMIT 1
";
$stmt = $mysqli->prepare($mgr_sql);
$stmt->bind_param("s", $manager_no);
$stmt->execute();
$mgr_result = $stmt->get_result();
$manager    = $mgr_result->fetch_assoc();
$stmt->close();

if (!$manager || empty($manager["dept_no"])) {
    die("Manager department not found. Make sure this manager has a dept_no set.");
}

$manager_dept = $manager["dept_no"];
$dept_name    = $manager["dept_name"] ?? "";

/**
 * 2) Load employees in this manager's department
 */
$employees = [];
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
$employees_res = $emp_stmt->get_result();
$emp_stmt->close();

while ($row = $employees_res->fetch_assoc()) {
    $employees[] = $row;
}

/**
 * 3) Handle creating a task for ONE or MULTIPLE employees
 *    - We use a multi-select <select name="assignees[]"> so manager can pick 1 or many.
 */
$task_msg = "";
$task_ok  = false;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create_task"])) {
    $title = trim($_POST["task_title"] ?? "");
    $desc  = trim($_POST["task_description"] ?? "");
    $due   = $_POST["due_date"] ?? null;

    // Always treat as multi-select (even if they pick one)
    $assignees = $_POST["assignees"] ?? [];
    if (!is_array($assignees)) {
        $assignees = [$assignees];
    }

    if ($title === "") {
        $task_msg = "Task title is required.";
    } elseif (empty($assignees)) {
        $task_msg = "Please select at least one employee.";
    } else {
        // Make sure all assignees really belong to this manager's department
        $valid_emp_nos = array_column($employees, "emp_no");
        $insert_list   = [];

        foreach ($assignees as $emp_no_target) {
            $emp_no_target = trim($emp_no_target);
            if ($emp_no_target === "") continue;

            if (!in_array($emp_no_target, $valid_emp_nos, true)) {
                // If any is invalid, abort and show error
                $task_msg = "You can only assign tasks to employees in your department.";
                $insert_list = [];
                break;
            } else {
                $insert_list[] = $emp_no_target;
            }
        }

        if (!empty($insert_list)) {
            $stmt = $mysqli->prepare("
                INSERT INTO tasks (emp_no, task_title, task_description, due_date, status)
                VALUES (?, ?, ?, ?, 'pending')
            ");
            if ($stmt) {
                foreach ($insert_list as $emp_no_target) {
                    $stmt->bind_param("ssss", $emp_no_target, $title, $desc, $due);
                    $stmt->execute();
                }
                $stmt->close();
                $task_ok  = true;
                $task_msg = "Task created for " . count($insert_list) . " employee(s) in your department.";
            } else {
                $task_msg = "Database error when creating tasks.";
            }
        }
    }
}

/**
 * 4) Load manager's own tasks
 */
$my_tasks_stmt = $mysqli->prepare("
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
$my_tasks_stmt->bind_param("s", $manager_no);
$my_tasks_stmt->execute();
$my_tasks = $my_tasks_stmt->get_result();
$my_tasks_stmt->close();

/**
 * 5) Load tasks for employees in this department
 */
$team_tasks_stmt = $mysqli->prepare("
    SELECT t.id, t.emp_no, t.task_title, t.task_description, t.due_date, t.status,
           e.first_name, e.last_name
    FROM tasks t
    JOIN employees e ON t.emp_no = e.emp_no
    WHERE e.dept_no = ?
      AND e.role = 'employee'
    ORDER BY
      CASE t.status
          WHEN 'pending' THEN 1
          WHEN 'in_progress' THEN 2
          WHEN 'done' THEN 3
      END,
      COALESCE(t.due_date, '9999-12-31')
");
$team_tasks_stmt->bind_param("s", $manager_dept);
$team_tasks_stmt->execute();
$team_tasks = $team_tasks_stmt->get_result();
$team_tasks_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Manager Tasks</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="manager">
<div class="container">
    <header class="top-bar">
        <h1>Team Tasks – <?php echo htmlspecialchars($manager_name); ?></h1>
        <div>
            <a href="manager_dashboard.php" class="btn btn-secondary btn-small">Back to Dashboard</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <div class="card">
        <h2>Your Department</h2>
        <p><strong><?php echo htmlspecialchars($dept_name); ?></strong> (<?php echo htmlspecialchars($manager_dept); ?>)</p>
        <p class="hint-text">
            You can only assign tasks to employees in this department.
            Pick one or multiple people from the list below.
        </p>
    </div>

    <div class="card">
        <h2>Assign Task to One or Many Employees</h2>

        <?php if ($task_msg): ?>
            <div class="alert <?php echo $task_ok ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars($task_msg); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($employees)): ?>
            <p>You currently have no active employees in your department.</p>
        <?php else: ?>
            <form method="post" style="max-width:520px;">
                <input type="hidden" name="create_task" value="1">

                <label for="assignees">Employees (hold Ctrl / Cmd to select multiple)</label>
                <select id="assignees" name="assignees[]" multiple size="6" required>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?php echo htmlspecialchars($e["emp_no"]); ?>">
                            <?php echo htmlspecialchars($e["emp_no"] . " – " . $e["first_name"] . " " . $e["last_name"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="task_title" style="margin-top:8px;">Task Title</label>
                <input type="text" id="task_title" name="task_title" required>

                <label for="task_description" style="margin-top:8px;">Description</label>
                <textarea id="task_description" name="task_description"
                          placeholder="Give them a quest… or a bug fix, your choice."></textarea>

                <label for="due_date" style="margin-top:8px;">Due Date</label>
                <input type="date" id="due_date" name="due_date">

                <button type="submit" class="btn btn-primary" style="margin-top:10px;">
                    Assign Task to Selected Employees
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="grid-2">
        <div class="card">
            <h2>My Tasks (as Manager)</h2>
            <?php if ($my_tasks->num_rows === 0): ?>
                <p>You have no assigned tasks.</p>
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
                    <?php while ($t = $my_tasks->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($t["task_title"]); ?></strong><br>
                                <small><?php echo nl2br(htmlspecialchars($t["task_description"])); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($t["due_date"]); ?></td>
                            <td>
                                <span class="task-status <?php echo htmlspecialchars($t["status"]); ?>">
                                    <?php echo str_replace('_', ' ', ucfirst($t["status"])); ?>
                                </span>
                            </td>
                            <td>
                                <form method="post" action="update_task_status.php">
                                    <input type="hidden" name="task_id" value="<?php echo (int)$t["id"]; ?>">
                                    <select name="status">
                                        <option value="pending"     <?php if ($t["status"] === 'pending') echo 'selected'; ?>>Pending</option>
                                        <option value="in_progress" <?php if ($t["status"] === 'in_progress') echo 'selected'; ?>>In progress</option>
                                        <option value="done"        <?php if ($t["status"] === 'done') echo 'selected'; ?>>Done</option>
                                    </select>
                                    <button type="submit" class="btn btn-small btn-primary" style="margin-top:6px;">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Team Tasks (Employees in Your Department)</h2>
            <?php if ($team_tasks->num_rows === 0): ?>
                <p>No tasks assigned to your team yet.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Task</th>
                        <th>Due</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php while ($t = $team_tasks->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($t["emp_no"] . " – " . $t["first_name"] . " " . $t["last_name"]); ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($t["task_title"]); ?></strong><br>
                                <small><?php echo nl2br(htmlspecialchars($t["task_description"])); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($t["due_date"]); ?></td>
                            <td>
                                <span class="task-status <?php echo htmlspecialchars($t["status"]); ?>">
                                    <?php echo str_replace('_', ' ', ucfirst($t["status"])); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>