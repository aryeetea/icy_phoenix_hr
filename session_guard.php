<?php
// session_guard.php
// Put this at the very top of any page that requires login

session_start();
require_once __DIR__ . "/db_connect.php";

// how long before auto clock-out (in seconds)
const IP_SESSION_TIMEOUT = 30 * 60; // 30 minutes

// if user not logged in at all, just stop here
if (!isset($_SESSION["emp_no"], $_SESSION["role"])) {
    header("Location: login.php");
    exit;
}

$now = time();

// first time this requestor got a page this session
if (!isset($_SESSION["last_activity"])) {
    $_SESSION["last_activity"] = $now;
} else {
    $idle = $now - (int)$_SESSION["last_activity"];

    if ($idle > IP_SESSION_TIMEOUT) {
        // -------- AUTO CLOCK-OUT ON TIMEOUT --------
        $emp_no = $_SESSION["emp_no"];
        $today  = date("Y-m-d");

        // If you have an attendance table with a row for today,
        // close it by setting clock_out if it's still open.
        $stmt = $mysqli->prepare("
            UPDATE attendance
            SET clock_out = NOW(), source = 'timeout'
            WHERE emp_no = ?
              AND work_date = ?
              AND clock_out IS NULL
        ");
        if ($stmt) {
            $stmt->bind_param("ss", $emp_no, $today);
            $stmt->execute();
            $stmt->close();
        }

        // wipe session and send them back to login with a message
        session_unset();
        session_destroy();

        header("Location: login.php?reason=timeout");
        exit;
    } else {
        // still active, refresh timer
        $_SESSION["last_activity"] = $now;
    }
}