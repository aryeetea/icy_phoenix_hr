<?php
// fire_employee.php
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

$emp_no = $_POST["emp_no"] ?? "";
$redirect = ($current_role === 'ceo') ? "ceo_dashboard.php" : "manager_dashboard.php";

if ($emp_no === "") {
    $_SESSION["add_error"] = "No employee selected.";
    header("Location: {$redirect}");
    exit;
}

// mark as terminated + clear access
$stmt = $mysqli->prepare("
    UPDATE employees
    SET employment_status = 'terminated',
        access_code       = NULL
    WHERE emp_no = ?
");
if (!$stmt) {
    $_SESSION["add_error"] = "Error preparing termination: " . $mysqli->error;
    header("Location: {$redirect}?emp_no=" . urlencode($emp_no));
    exit;
}

$stmt->bind_param("s", $emp_no);
if (!$stmt->execute()) {
    $_SESSION["add_error"] = "Could not terminate employee.";
    $stmt->close();
    header("Location: {$redirect}?emp_no=" . urlencode($emp_no));
    exit;
}
$stmt->close();

// Email notification (polite generic)
$info = ipx_get_employee_email($mysqli, $emp_no);
if ($info) {
    $to      = $info["email"];
    $subject = "Update to your employment status at Icy Phoenix";
    $body    = "Hi {$info["first_name"]},\n\n" .
               "This is a notice that your employment status at Icy Phoenix " .
               "has been changed to 'terminated' in our HR system.\n\n" .
               "Please refer to your manager or HR for any questions.\n\n" .
               "- Icy Phoenix HR";
    ipx_send_notification($to, $subject, $body);
}

$_SESSION["add_success"] = "Employee {$emp_no} has been marked as terminated and their access has been disabled.";
header("Location: {$redirect}");
exit;