<?php
session_start();
require_once __DIR__ . "/db_connect.php";

// Only CEO can use this page
if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'ceo') {
    header("Location: login.php");
    exit;
}

// Helper: get CEO key from system_settings
function get_ceo_access_key(mysqli $mysqli): string {
    $stmt = $mysqli->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'ceo_access_key' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        if ($row && isset($row["setting_value"])) {
            return $row["setting_value"];
        }
    }
    return "ICY-PHOENIX-CEO-ONLY";
}

$CEO_ACCESS_KEY = get_ceo_access_key($mysqli);

$target_emp = trim($_GET["emp_no"] ?? "");

if ($target_emp === "") {
    header("Location: ceo_dashboard.php");
    exit;
}

$error = "";
$employee = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $ceo_key = trim($_POST["ceo_key"] ?? "");

    if ($ceo_key === "") {
        $error = "Please enter the CEO access key to view employee details.";
    } elseif ($ceo_key !== $CEO_ACCESS_KEY) {
        $error = "CEO access key is incorrect.";
    } else {
        $stmt = $mysqli->prepare("
            SELECT e.emp_no, e.first_name, e.last_name, e.birth_date, e.hire_date,
                   e.employment_status, e.role, e.access_code,
                   d.dept_name, t.title, s.salary
            FROM employees e
            LEFT JOIN departments d ON e.dept_no = d.dept_no
            LEFT JOIN titles t ON e.title_id = t.id
            LEFT JOIN salaries s ON e.emp_no = s.emp_no AND s.is_current = 1
            WHERE e.emp_no = ?
        ");
        $stmt->bind_param("s", $target_emp);
        $stmt->execute();
        $res = $stmt->get_result();
        $employee = $res->fetch_assoc();
        $stmt->close();

        if (!$employee) {
            $error = "Employee not found.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Details – Icy Phoenix</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="ceo">

<div class="container">
    <header class="top-bar">
        <h1>Employee Details</h1>
        <a href="ceo_dashboard.php" class="btn btn-secondary btn-small">Back to CEO Dashboard</a>
    </header>

    <?php if (!$employee): ?>
        <div class="card">
            <h2>Unlock Details for Employee #<?php echo htmlspecialchars($target_emp); ?></h2>
            <p>To protect your team's privacy, full details are locked behind the CEO access key.</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="post" style="max-width:380px;">
                <label for="ceo_key">CEO Access Key</label>
                <input type="password" id="ceo_key" name="ceo_key"
                       placeholder="Enter CEO access key">
                <button type="submit" class="btn btn-primary" style="margin-top:10px;">
                    Unlock Employee Details
                </button>
            </form>
        </div>
    <?php else: ?>
        <div class="card">
            <h2><?php echo htmlspecialchars($employee["first_name"] . " " . $employee["last_name"]); ?></h2>
            <p><strong>Employee No:</strong> <?php echo htmlspecialchars($employee["emp_no"]); ?></p>
            <p><strong>Role:</strong> <?php echo htmlspecialchars(ucfirst($employee["role"])); ?></p>
            <p><strong>Status:</strong> <?php echo htmlspecialchars($employee["employment_status"]); ?></p>
            <p><strong>Birthdate:</strong> <?php echo htmlspecialchars($employee["birth_date"]); ?></p>
            <p><strong>Hire Date:</strong> <?php echo htmlspecialchars($employee["hire_date"]); ?></p>
            <p><strong>Department:</strong> <?php echo htmlspecialchars($employee["dept_name"]); ?></p>
            <p><strong>Title:</strong> <?php echo htmlspecialchars($employee["title"]); ?></p>
            <p><strong>Salary:</strong>
                <?php echo $employee["salary"] !== null
                    ? "$" . number_format($employee["salary"], 2)
                    : "—"; ?>
            </p>
            <p><strong>Access Code:</strong> <?php echo htmlspecialchars($employee["access_code"]); ?></p>

            <p style="margin-top:16px;font-size:13px;opacity:0.8;">
                This information is visible only to the CEO of Icy Phoenix after entering the CEO access key.
            </p>
        </div>
    <?php endif; ?>

</div>

</body>
</html>