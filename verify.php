<?php
// verify.php

session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/auth_helpers.php";        
require_once __DIR__ . "/notify_helpers.php";      
require_once __DIR__ . "/two_factor_helpers.php";  

$pending_emp_no = $_SESSION["pending_emp_no"] ?? null;

if (!$pending_emp_no) {
    header("Location: login.php");
    exit;
}

/* ----------------------------------------------
   1) Load employee
------------------------------------------------*/
$stmt = $mysqli->prepare("
    SELECT emp_no, first_name, last_name, role, email, password_hash
    FROM employees
    WHERE emp_no = ?
    LIMIT 1
");
$stmt->bind_param("s", $pending_emp_no);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    unset($_SESSION["pending_emp_no"]);
    header("Location: login.php");
    exit;
}

$error = "";

/* ----------------------------------------------
   2) Handle continue
------------------------------------------------*/
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["continue"])) {

    $final_emp_no   = $user["emp_no"];
    $final_emp_name = trim($user["first_name"] . " " . $user["last_name"]);
    $final_role     = $user["role"] ?: "employee";

    // Save identity for OTP stage
    $_SESSION["pending_final_emp_no"]   = $final_emp_no;
    $_SESSION["pending_final_emp_name"] = $final_emp_name;
    $_SESSION["pending_final_role"]     = $final_role;

    // Ensure email exists
    $email      = $user["email"];
    $first_name = $user["first_name"];
    $last_name  = $user["last_name"];

    if (!$email) {
        $email = ipx_make_login_email($final_emp_no, $first_name, $last_name);
        $upd = $mysqli->prepare("UPDATE employees SET email = ? WHERE emp_no = ?");
        $upd->bind_param("ss", $email, $final_emp_no);
        $upd->execute();
        $upd->close();
    }

    // Ensure password hash exists
    if (empty($user["password_hash"])) {
        $plain_pw = ipx_make_login_password($final_emp_no, $first_name);
        $hash = password_hash($plain_pw, PASSWORD_DEFAULT);

        $upd_pw = $mysqli->prepare("UPDATE employees SET password_hash = ? WHERE emp_no = ?");
        $upd_pw->bind_param("ss", $hash, $final_emp_no);
        $upd_pw->execute();
        $upd_pw->close();
    }

    // Generate OTP
    $otp = ipx_generate_otp();
    ipx_create_otp($mysqli, $final_emp_no, $otp);

    // Email OTP
    if (!empty($email)) {
        $subject = "Your Icy Phoenix login code";
        $body = "Hi {$first_name} {$last_name},\n\n";
        $body .= "Here is your login code:\n\n";
        $body .= "    {$otp}\n\n";
        $body .= "This code expires in 30 minutes.\n\n";
        $body .= "- Icy Phoenix HR Bot ❄️\n";

        ipx_send_email($email, $subject, $body);
    }

    header("Location: two_factor.php");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Verify Identity</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">

<div class="login-wrapper">
    <div class="login-card">
        <h1>Verify Identity</h1>

        <p>Are you <strong><?php echo htmlspecialchars($user["first_name"] . " " . $user["last_name"]); ?></strong>?</p>

        <form method="post">
            <button class="btn btn-primary full-width" name="continue" value="1">
                Yes, send me the login code
            </button>
            <a href="login.php" class="btn btn-secondary full-width" style="margin-top:8px;">
                No, go back
            </a>
        </form>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <p class="hint-text">
            After confirming, a one-time login code will be emailed to you.
        </p>
    </div>
</div>

</body>
</html>