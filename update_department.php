<?php
// update_department.php
session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/email_helper.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"])) {
    header("Location: login.php");
    exit;
}

$current_role = $_SESSION["role"];
if (!in_array($current_role, ['manager', 'ceo'], true)) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: manager_dashboard.php");
    exit;
}

$emp_no  = $_POST["emp_no"] ?? "";
$dept_no = $_POST["dept_no"] ?? "";

$redirect = ($current_role === 'ceo') ? "ceo_dashboard.php" : "manager_dashboard.php";

if ($emp_no === "" || $dept_no === "") {
    $_SESSION["add_error"] = "Missing employee or department.";
    header("Location: {$redirect}?emp_no=" . urlencode($emp_no));
    exit;
}

// Get department name for message
$dept_stmt = $mysqli->prepare("SELECT dept_name FROM departments WHERE dept_no = ?");
$dept_name = "";
if ($dept_stmt) {
    $dept_stmt->bind_param("s", $dept_no);
    $dept_stmt->execute();
    $r = $dept_stmt->get_result()->fetch_assoc();
    $dept_stmt->close();
    if ($r) {
        $dept_name = $r["dept_name"];
    }
}

$stmt = $mysqli->prepare("
    UPDATE employees
    SET dept_no = ?
    WHERE emp_no = ?
");
if (!$stmt) {
    $_SESSION["add_error"] = "Error preparing department update: " . $mysqli->error;
    header("Location: {$redirect}?emp_no=" . urlencode($emp_no));
    exit;
}

$stmt->bind_param("ss", $dept_no, $emp_no);
if (!$stmt->execute()) {
    $_SESSION["add_error"] = "Could not update department.";
    $stmt->close();
    header("Location: {$redirect}?emp_no=" . urlencode($emp_no));
    exit;
}
$stmt->close();

// Email notification
$info = ipx_get_employee_email($mysqli, $emp_no);
if ($info) {
    $to      = $info["email"];
    $subject = "Your department has changed at Icy Phoenix";
    $body    = "Hi {$info["first_name"]},\n\n" .
               "Your department has been updated to: {$dept_name}.\n\n" .
               "If you have any questions, please talk to your manager.\n\n" .
               "- Icy Phoenix HR";
    ipx_send_notification($to, $subject, $body);
}

$_SESSION["add_success"] = "Department updated for employee {$emp_no}.";
header("Location: {$redirect}?emp_no=" . urlencode($emp_no));
exit;