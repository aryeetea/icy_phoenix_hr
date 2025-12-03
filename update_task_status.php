<?php
session_start();
require_once __DIR__ . "/db_connect.php";

if (!isset($_SESSION["emp_no"])) {
    header("Location: login.php");
    exit;
}

$task_id = $_POST["task_id"] ?? null;
$status  = $_POST["status"] ?? null;

$allowed = ['pending', 'in_progress', 'done'];

if (!$task_id || !in_array($status, $allowed, true)) {
    header("Location: employee_dashboard.php");
    exit;
}

$stmt = $mysqli->prepare("UPDATE tasks SET status = ? WHERE id = ?");
$stmt->bind_param("si", $status, $task_id);
$stmt->execute();
$stmt->close();

header("Location: employee_dashboard.php");
exit;