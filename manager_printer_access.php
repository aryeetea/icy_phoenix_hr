<?php
// manager_printer_access.php (clean version)
session_start();
require_once __DIR__ . "/db_connect.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'manager') {
    header("Location: login.php");
    exit;
}

$emp_no   = $_SESSION["emp_no"];
$emp_name = $_SESSION["emp_name"];

function log_printer_demo(mysqli $mysqli, string $emp_no, string $code_used, string $status): void {
    $stmt = $mysqli->prepare("
        INSERT INTO audit_logs (table_name, record_pk, action, changed_by, new_values)
        VALUES ('printer_demo_manager', ?, 'printer_demo', ?, ?)
    ");
    if ($stmt) {
        $data = json_encode([
            "role"       => "manager",
            "code_used"  => $code_used,
            "status"     => $status,
            "timestamp"  => date('c'),
        ]);
        $stmt->bind_param("sss", $emp_no, $emp_no, $data);
        $stmt->execute();
        $stmt->close();
    }
}

// Load manager record
$stmt = $mysqli->prepare("
    SELECT emp_no, first_name, last_name, employment_status, access_code
    FROM employees
    WHERE emp_no = ?
");
$stmt->bind_param("s", $emp_no);
$stmt->execute();
$res      = $stmt->get_result();
$manager  = $res->fetch_assoc();
$stmt->close();

if (!$manager) {
    $error       = "Your manager record could not be found.";
    $access_code = "";
} else {
    $access_code = $manager["access_code"] ?? "";
    if ($manager["employment_status"] !== "active") {
        $error = "Your account is not active. Printer access is disabled.";
    } elseif ($access_code === "") {
        $error = "You do not have a printer access code assigned yet.";
    } else {
        $error = "";
    }
}

// QR code
$qr_url = "";
if ($access_code !== "") {
    $qr_data = urlencode("IcyPhoenix-PRINTER:" . $access_code);
    $qr_url  = "https://api.qrserver.com/v1/create-qr-code/?size=180x180&data={$qr_data}";
}

// Handle test print
$result_msg   = "";
$result_ok    = false;
$code_entered = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && $access_code !== "" && $error === "") {
    $code_entered = trim($_POST["code"] ?? "");
    if ($code_entered === "") {
        $result_msg = "Please enter your printer access code.";
    } elseif (strcasecmp($code_entered, $access_code) !== 0) {
        $result_msg = "Print denied. The code you entered is incorrect.";
        log_printer_demo($mysqli, $emp_no, $code_entered, "denied");
    } else {
        $result_msg = "Print allowed. Your printer code is valid.";
        $result_ok  = true;
        log_printer_demo($mysqli, $emp_no, $code_entered, "granted");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Manager Printer Access</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="manager">
<div class="container">
    <header class="top-bar">
        <h1>Printer Access – <?php echo htmlspecialchars($emp_name); ?></h1>
        <div class="top-actions">
            <a href="manager_dashboard.php" class="btn btn-secondary btn-small">Back to Dashboard</a>
            <a href="scan_access.php" class="btn btn-secondary btn-small">Access Scanner</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <div class="card">
        <h2>Your Printer Access Code</h2>

        <p><strong>Code:</strong>
            <?php echo $access_code !== "" ? htmlspecialchars($access_code) : "Not assigned"; ?>
        </p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($access_code !== "" && $error === ""): ?>
            <div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:16px;">
                <div>
                    <label style="font-size:13px;">QR Code</label><br>
                    <img src="<?php echo htmlspecialchars($qr_url); ?>" alt="QR code"
                         style="background:#fff; padding:4px; border-radius:12px; border:1px solid #eee;">
                </div>

                <div style="flex:1; min-width:220px;">
                    <label style="font-size:13px;">Barcode (visual only)</label>
                    <div class="barcode">
                        <?php
                        $len = max(12, strlen($access_code));
                        for ($i = 0; $i < $len; $i++):
                            $h = 24 + ($i % 5) * 4;
                            $w = ($i % 3 === 0) ? 3 : 2;
                        ?>
                            <span style="height:<?php echo $h; ?>px; width:<?php echo $w; ?>px;"></span>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Simulate Printer Use</h2>
        <p>Enter your printer access code to test it.</p>

        <?php if ($access_code === "" || $error !== ""): ?>
            <p class="hint-text">You need an access code assigned before printing.</p>
        <?php else: ?>

            <form method="post" style="max-width:420px;">
                <input type="text" name="code" id="code"
                       value="<?php echo htmlspecialchars($code_entered !== "" ? $code_entered : $access_code); ?>"
                       placeholder="Enter your printer code">
                <button class="btn btn-primary" style="margin-top:10px;">Test Print</button>
            </form>

            <?php if ($result_msg !== ""): ?>
                <div class="alert <?php echo $result_ok ? 'alert-success' : 'alert-error'; ?>" style="margin-top:12px;">
                    <?php echo htmlspecialchars($result_msg); ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
</body>
</html>