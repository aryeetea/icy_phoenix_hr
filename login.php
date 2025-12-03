<?php
// login.php
session_start();
require_once __DIR__ . "/db_connect.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $emp_no = trim($_POST["emp_no"] ?? "");

    if ($emp_no === "") {
        $error = "Please enter your employee number.";
    } else {
        $stmt = $mysqli->prepare("
            SELECT emp_no
            FROM employees
            WHERE emp_no = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("s", $emp_no);
            $stmt->execute();
            $res = $stmt->get_result();
            $user = $res->fetch_assoc();
            $stmt->close();

            if ($user) {
                $_SESSION["pending_emp_no"] = $emp_no;
                header("Location: verify.php");
                exit;
            } else {
                $error = "Employee number not found.";
            }
        } else {
            $error = "Database error: " . $mysqli->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
<div class="login-wrapper">
    <div class="login-card">
        <h1>Log in to Icy Phoenix</h1>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post">
            <label for="emp_no">Employee number</label>
            <input type="text" id="emp_no" name="emp_no" required>
            <button class="btn btn-primary full-width" style="margin-top:12px;">
                Continue
            </button>
        </form>
        <p class="hint-text" style="margin-top:12px;">
            You’ll receive a one-time login code after this step.
        </p>
    </div>
</div>
</body>
</html>