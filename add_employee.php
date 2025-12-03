<?php
// add_employee.php
session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/auth_helpers.php";     // ipx_make_login_email, ipx_make_login_password
require_once __DIR__ . "/notify_helpers.php";   // ipx_send_email()

if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'ceo') {
    header("Location: login.php");
    exit;
}

$ceo_name = $_SESSION["emp_name"] ?? "CEO";

$error   = "";
$success = "";

// Load departments + titles for the form
$departments = $mysqli->query("SELECT dept_no, dept_name FROM departments ORDER BY dept_name");
$titles      = $mysqli->query("SELECT id, title FROM titles ORDER BY title");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $emp_no     = trim($_POST["emp_no"] ?? "");
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name  = trim($_POST["last_name"] ?? "");
    $birth_date = trim($_POST["birth_date"] ?? "");
    $hire_date  = trim($_POST["hire_date"] ?? "");
    $dept_no    = trim($_POST["dept_no"] ?? "");
    $title_id   = trim($_POST["title_id"] ?? "");
    $role       = trim($_POST["role"] ?? "employee"); // 'employee' or 'manager'

    if (
        $emp_no     === "" ||
        $first_name === "" ||
        $last_name  === "" ||
        $birth_date === "" ||
        $hire_date  === "" ||
        $dept_no    === "" ||
        $title_id   === ""
    ) {
        $error = "Please fill out all required fields.";
    } else {
        // Access code: first letter of first name + emp_no
        $access_code = strtoupper(substr($first_name, 0, 1)) . $emp_no;

        // Login email + password (consistent with your rules)
        $email          = ipx_make_login_email($emp_no, $first_name, $last_name);
        $plain_password = ipx_make_login_password($emp_no, $first_name);
        $password_hash  = password_hash($plain_password, PASSWORD_DEFAULT);

        $stmt = $mysqli->prepare("
            INSERT INTO employees (
                emp_no,
                first_name,
                last_name,
                birth_date,
                hire_date,
                dept_no,
                title_id,
                role,
                employment_status,
                access_code,
                email,
                password_hash
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?
            )
        ");

        if (!$stmt) {
            $error = "DB error: " . $mysqli->error;
        } else {
            $stmt->bind_param(
                "ssssssissss",
                $emp_no,
                $first_name,
                $last_name,
                $birth_date,
                $hire_date,
                $dept_no,
                $title_id,
                $role,
                $access_code,
                $email,
                $password_hash
            );

            if ($stmt->execute()) {
                // Base success message (shown to CEO)
                $success =
                    "Employee created!<br>" .
                    "Login email: <strong>{$email}</strong><br>" .
                    "Initial password: <strong>{$plain_password}</strong> (please share securely)";

                // Try to send welcome email
                if (function_exists('ipx_send_email') && !empty($email)) {
                    $subject = "Welcome to Icy Phoenix – Your Login Details";
                    $body    = "Hi {$first_name} {$last_name},\n\n"
                             . "Welcome to Icy Phoenix! ❄️\n\n"
                             . "Here are your login details for the employee system:\n"
                             . "Login email: {$email}\n"
                             . "Password:   {$plain_password}\n\n"
                             . "You can change your password later once you're logged in.\n\n"
                             . "- Icy Phoenix HR\n";

                    $sent = ipx_send_email($email, $subject, $body);

                    if ($sent) {
                        $success .= "<br><em>Welcome email sent to {$email}.</em>";
                    } else {
                        $success .= "<br><em>(Email could not be sent from this server, "
                                  . "but credentials are shown above.)</em>";
                    }
                }
            } else {
                $error = "Could not create employee: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Employee – Icy Phoenix</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="ceo">
<div class="container">
    <header class="top-bar">
        <h1>Add Employee</h1>
        <div class="top-actions">
            <a href="ceo_dashboard.php" class="btn btn-secondary btn-small">Back to CEO Dashboard</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>New Employee</h2>
        <form method="post" class="form-grid">
            <label>
                Employee No
                <input type="text" name="emp_no" required>
            </label>

            <label>
                First Name
                <input type="text" name="first_name" required>
            </label>

            <label>
                Last Name
                <input type="text" name="last_name" required>
            </label>

            <label>
                Birth Date
                <input type="date" name="birth_date" required>
            </label>

            <label>
                Hire Date
                <input type="date" name="hire_date" required>
            </label>

            <label>
                Department
                <select name="dept_no" required>
                    <option value="">Select…</option>
                    <?php while ($d = $departments->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($d["dept_no"]); ?>">
                            <?php echo htmlspecialchars($d["dept_name"]); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </label>

            <label>
                Title
                <select name="title_id" required>
                    <option value="">Select…</option>
                    <?php while ($t = $titles->fetch_assoc()): ?>
                        <option value="<?php echo (int)$t["id"]; ?>">
                            <?php echo htmlspecialchars($t["title"]); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </label>

            <label>
                Role
                <select name="role">
                    <option value="employee">Employee</option>
                    <option value="manager">Manager</option>
                </select>
            </label>

            <div style="grid-column: 1 / -1; margin-top: 10px;">
                <button type="submit" class="btn btn-primary">Create Employee</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>