<?php
// manager_add_task.php
session_start();
require_once __DIR__ . "/db_connect.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'manager') {
    header("Location: login.php");
    exit;
}

$manager_no = $_SESSION["emp_no"];

$emp_no = $_POST["emp_no"] ?? "";
$title  = trim($_POST["task_title"] ?? "");
$desc   = trim($_POST["task_description"] ?? "");
$due    = $_POST["due_date"] ?? null;

if ($emp_no === "" || $title === "") {
    // Missing required fields – just go back to dashboard
    header("Location: manager_dashboard.php?emp_no=" . urlencode($emp_no));
    exit;
}

$stmt = $mysqli->prepare("
    INSERT INTO tasks (emp_no, task_title, task_description, due_date, status)
    VALUES (?, ?, ?, ?, 'pending')
");
if ($stmt) {
    $stmt->bind_param("ssss", $emp_no, $title, $desc, $due);
    $stmt->execute();
    $stmt->close();
}

header("Location: manager_dashboard.php?emp_no=" . urlencode($emp_no));
exit;