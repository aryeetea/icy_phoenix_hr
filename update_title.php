<?php
// update_title.php
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

$emp_no   = $_POST["emp_no"] ?? "";
$title_id = $_POST["title_id"] ?? "";

$redirect = ($current_role === 'ceo') ? "ceo_dashboard.php" : "manager_dashboard.php";

if ($emp_no === "" || $title_id === "") {
    $_SESSION["add_error"] = "Missing employee or title.";
    header("Location: {$redirect}?emp_no=" . urlencode($emp_no));
    exit;
}

// find title name
$title_stmt = $mysqli->prepare("SELECT title FROM titles WHERE id = ?");
$title_name = "";
if ($title_stmt) {
    $tid_int = (int)$title_id;
    $title_stmt->bind_param("i", $tid_int);
    $title_stmt->execute();
    $tr = $title_stmt->get_result()->fetch_assoc();
    $title_stmt->close();
    if ($tr) {
        $title_name = $tr["title"];
    }
}

// update
$stmt = $mysqli->prepare("
    UPDATE employees
    SET title_id = ?
    WHERE emp_no = ?
");
if (!$stmt) {
    $_SESSION["add_error"] = "Error preparing title update: " . $mysqli->error;
    header("Location: {$redirect}?emp_no=" . urlencode($emp_no));
    exit;
}

$title_id_int = (int)$title_id;
$stmt->bind_param("is", $title_id_int, $emp_no);
if (!$stmt->execute()) {
    $_SESSION["add_error"] = "Could not update title.";
    $stmt->close();
    header("Location: {$redirect}?emp_no=" . urlencode($emp_no));
    exit;
}
$stmt->close();

// Email notification
$info = ipx_get_employee_email($mysqli, $emp_no);
if ($info) {
    $to      = $info["email"];
    $subject = "Your title has changed at Icy Phoenix";
    $body    = "Hi {$info["first_name"]},\n\n" .
               "Your job title has been updated to: {$title_name}.\n\n" .
               "Congrats on the new role (or responsibilities)!\n\n" .
               "- Icy Phoenix HR";
    ipx_send_notification($to, $subject, $body);
}

$_SESSION["add_success"] = "Title updated for employee {$emp_no}.";
header("Location: {$redirect}?emp_no=" . urlencode($emp_no));
exit;