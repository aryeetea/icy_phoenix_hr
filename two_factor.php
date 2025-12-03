<?php
// two_factor.php

session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/two_factor_helpers.php";

$emp_no   = $_SESSION["pending_final_emp_no"]   ?? null;
$emp_name = $_SESSION["pending_final_emp_name"] ?? null;
$role     = $_SESSION["pending_final_role"]     ?? null;

if (!$emp_no || !$role) {
    header("Location: login.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $code = trim($_POST["otp"] ?? "");

    if ($code === "") {
        $error = "Please enter the code.";
    } else {
        if (ipx_verify_otp($mysqli, $emp_no, $code)) {

            $_SESSION["emp_no"]   = $emp_no;
            $_SESSION["emp_name"] = $emp_name;
            $_SESSION["role"]     = $role;

            unset(
                $_SESSION["pending_emp_no"],
                $_SESSION["pending_final_emp_no"],
                $_SESSION["pending_final_emp_name"],
                $_SESSION["pending_final_role"]
            );

            if ($role === 'manager') {
                header("Location: manager_dashboard.php");
            } elseif ($role === 'ceo') {
                header("Location: ceo_dashboard.php");
            } else {
                header("Location: employee_dashboard.php");
            }
            exit;
        } else {
            $error = "Invalid or expired code.";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Enter Login Code</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">

<div class="login-wrapper">
    <div class="login-card">
        <h1>Enter Login Code</h1>

        <p>We sent a one-time code to the email on file for <strong><?php echo htmlspecialchars($emp_name); ?></strong>.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post">
            <label for="otp">6-digit code</label>
            <input type="text" id="otp" name="otp" maxlength="6" autocomplete="one-time-code">
            <button class="btn btn-primary full-width" style="margin-top:12px;">
                Complete Login
            </button>
        </form>

        <p class="hint-text">
            Code expires in 30 minutes.
        </p>
    </div>
</div>

</body>
</html>