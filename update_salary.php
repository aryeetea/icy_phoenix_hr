<?php
// update_salary.php
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
$salary = $_POST["salary"] ?? "";

$redirect = ($current_role === 'ceo') ? "ceo_dashboard.php" : "manager_dashboard.php";

if ($emp_no === "" || $salary === "") {
    $_SESSION["add_error"] = "Missing employee or salary.";
    header("Location: {$redirect}?emp_no=" . urlencode($emp_no));
    exit;
}
if (!is_numeric($salary)) {
    $_SESSION["add_error"] = "Salary must be numeric.";
    header("Location: {$redirect}?emp_no=" . urlencode($emp_no));
    exit;
}

$new_salary = (float)$salary;

$mysqli->begin_transaction();

try {
    // Mark old as not current
    $stmt1 = $mysqli->prepare("UPDATE salaries SET is_current = 0 WHERE emp_no = ? AND is_current = 1");
    if ($stmt1) {
        $stmt1->bind_param("s", $emp_no);
        $stmt1->execute();
        $stmt1->close();
    }

    // Insert new salary row
    $stmt2 = $mysqli->prepare("
        INSERT INTO salaries (emp_no, salary, from_date, is_current)
        VALUES (?, ?, CURDATE(), 1)
    ");
    if (!$stmt2) {
        throw new Exception("Could not prepare salary insert: " . $mysqli->error);
    }
    $stmt2->bind_param("sd", $emp_no, $new_salary);
    $stmt2->execute();
    $stmt2->close();

    $mysqli->commit();

    // Email notification
    $info = ipx_get_employee_email($mysqli, $emp_no);
    if ($info) {
        $to      = $info["email"];
        $subject = "Your salary has been updated at Icy Phoenix";
        $body    = "Hi {$info["first_name"]},\n\n" .
                   "Your salary has been updated. Your new base salary is:\n" .
                   "$" . number_format($new_salary, 2) . "\n\n" .
                   "You can see details in your dashboard.\n\n" .
                   "- Icy Phoenix HR";
        ipx_send_notification($to, $subject, $body);
    }

    $_SESSION["add_success"] = "Salary updated for employee {$emp_no}.";
} catch (Exception $e) {
    $mysqli->rollback();
    $_SESSION["add_error"] = "Could not update salary: " . $e->getMessage();
}

header("Location: {$redirect}?emp_no=" . urlencode($emp_no));
exit;