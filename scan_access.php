<?php
// scan_access.php
session_start();
require_once __DIR__ . "/db_connect.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"])) {
    header("Location: login.php");
    exit;
}

$emp_no   = $_SESSION["emp_no"];
$emp_name = $_SESSION["emp_name"];
$role     = $_SESSION["role"];

function log_access_event(mysqli $mysqli, string $emp_no, string $device_type, string $code_used, string $status): void {
    $stmt = $mysqli->prepare("
        INSERT INTO audit_logs (table_name, record_pk, action, changed_by, new_values)
        VALUES ('access_scanner', ?, 'access', ?, ?)
    ");
    if ($stmt) {
        $data = json_encode([
            "device_type" => $device_type,
            "code_used"   => $code_used,
            "status"      => $status,
        ]);
        $stmt->bind_param("sss", $device_type, $emp_no, $data);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Check if this user is currently "on the clock".
 * On the clock = today, status='present', has check_in_time and no check_out_time yet.
 */
function ipx_is_on_clock(mysqli $mysqli, string $emp_no): bool {
    $today = date('Y-m-d');
    $stmt = $mysqli->prepare("
        SELECT status, check_in_time, check_out_time
        FROM attendance
        WHERE emp_no = ? AND attendance_date = ?
        LIMIT 1
    ");
    if (!$stmt) return false;

    $stmt->bind_param("ss", $emp_no, $today);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if (!$row) return false;
    if ($row["status"] !== "present") return false;
    if ($row["check_in_time"] === null) return false;
    if ($row["check_out_time"] !== null) return false;

    return true;
}

/**
 * Clock in or clock out via the gate.
 * Returns ["status" => "in"|"out", "message" => "..."] or null on error.
 */
function ipx_toggle_clock(mysqli $mysqli, string $emp_no): ?array {
    $today = date('Y-m-d');

    $stmt = $mysqli->prepare("
        SELECT id, status, check_in_time, check_out_time
        FROM attendance
        WHERE emp_no = ? AND attendance_date = ?
        LIMIT 1
    ");
    if (!$stmt) return null;

    $stmt->bind_param("ss", $emp_no, $today);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    // No record yet → clock in
    if (!$row) {
        $insert = $mysqli->prepare("
            INSERT INTO attendance (emp_no, attendance_date, status, check_in_time)
            VALUES (?, ?, 'present', NOW())
        ");
        if ($insert) {
            $insert->bind_param("ss", $emp_no, $today);
            $insert->execute();
            $insert->close();
            return ["status" => "in", "message" => "Clocked in. Welcome to Icy Phoenix for today. ✅"];
        }
        return null;
    }

    // There is a record
    if ($row["check_out_time"] === null) {
        // Currently in → clock out
        $upd = $mysqli->prepare("
            UPDATE attendance
            SET check_out_time = NOW()
            WHERE id = ?
        ");
        if ($upd) {
            $upd->bind_param("i", $row["id"]);
            $upd->execute();
            $upd->close();
            return ["status" => "out", "message" => "You are now clocked out. See you next time. 💤"];
        }
        return null;
    } else {
        // Already clocked out → start a new "in" for today
        $upd = $mysqli->prepare("
            UPDATE attendance
            SET status = 'present', check_in_time = NOW(), check_out_time = NULL
            WHERE id = ?
        ");
        if ($upd) {
            $upd->bind_param("i", $row["id"]);
            $upd->execute();
            $upd->close();
            return ["status" => "in", "message" => "Clocked back in. You're on the clock again. ✅"];
        }
        return null;
    }
}

// Load this employee's record (for access_code + status)
$stmt = $mysqli->prepare("
    SELECT emp_no, first_name, last_name, employment_status, access_code
    FROM employees
    WHERE emp_no = ?
");
$stmt->bind_param("s", $emp_no);
$stmt->execute();
$res      = $stmt->get_result();
$employee = $res->fetch_assoc();
$stmt->close();

if (!$employee) {
    $error   = "Your employee record could not be found.";
    $access_code = "";
} else {
    $access_code = $employee["access_code"] ?? "";
    if ($employee["employment_status"] !== "active") {
        $error = "Your account is not active. Access is disabled.";
    } elseif ($access_code === "") {
        $error = "You do not have an access code assigned yet.";
    } else {
        $error = "";
    }
}

$device_types = [
    "gate"        => "Gate (Clock In / Out)",
    "workstation" => "Workstation / Laptop",
    "printer"     => "Printer",
];

$result_msg      = "";
$result_ok       = false;
$selected_device = $_POST["device_type"] ?? "gate";
$code_entered    = "";
$is_on_clock     = ipx_is_on_clock($mysqli, $emp_no);

// Handle scan simulation
if ($_SERVER["REQUEST_METHOD"] === "POST" && $employee && $error === "") {
    $selected_device = $_POST["device_type"] ?? "gate";
    $code_entered    = trim($_POST["code"] ?? "");

    if ($code_entered === "") {
        $result_msg = "Please enter your access code.";
    } elseif (strcasecmp($code_entered, $access_code) !== 0) {
        $result_msg = "Access denied. The code does not match your assigned access code.";
        log_access_event($mysqli, $emp_no, $selected_device, $code_entered, "denied");
    } else {
        // Correct access code, now branch by device type
        if ($selected_device === "gate") {
            $toggle = ipx_toggle_clock($mysqli, $emp_no);
            if ($toggle === null) {
                $result_msg = "Could not update your clock status. Please try again.";
                $result_ok  = false;
                log_access_event($mysqli, $emp_no, "gate", $code_entered, "error");
            } else {
                $result_msg = $toggle["message"];
                $result_ok  = true;
                $is_on_clock = ipx_is_on_clock($mysqli, $emp_no); // refresh
                log_access_event($mysqli, $emp_no, "gate", $code_entered, $toggle["status"]);
            }
        } else {
            // Workstation or printer: must already be on the clock
            if (!ipx_is_on_clock($mysqli, $emp_no)) {
                $result_msg = "You are not clocked in. Use the Gate option first to clock in.";
                $result_ok  = false;
                log_access_event($mysqli, $emp_no, $selected_device, $code_entered, "blocked_not_on_clock");
            } else {
                $result_msg = "Access granted for " . ($device_types[$selected_device] ?? "device") . ".";
                $result_ok  = true;
                log_access_event($mysqli, $emp_no, $selected_device, $code_entered, "granted");
            }
        }
    }
}

// QR code URL (just like printers)
$qr_url = "";
if ($access_code !== "") {
    $qr_data = urlencode("IcyPhoenix-ACCESS:" . $access_code);
    $qr_url  = "https://api.qrserver.com/v1/create-qr-code/?size=180x180&data={$qr_data}";
}

// Decide where “Back to Dashboard” goes
$back_link = "employee_dashboard.php";
if ($role === "manager") {
    $back_link = "manager_dashboard.php";
} elseif ($role === "ceo") {
    $back_link = "ceo_dashboard.php";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Access Scanner</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="<?php echo htmlspecialchars($role); ?>">
<div class="container">
    <header class="top-bar">
        <h1>Access Scanner – <?php echo htmlspecialchars($emp_name); ?></h1>
        <div class="top-actions">
            <a href="<?php echo htmlspecialchars($back_link); ?>" class="btn btn-secondary btn-small">Back to Dashboard</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <div class="card">
        <h2>Your Access Badge</h2>
        <p><strong>Your Access Code:</strong>
            <?php echo $access_code !== "" ? htmlspecialchars($access_code) : "Not assigned"; ?>
        </p>

        <p><strong>Clock Status Today:</strong>
            <?php echo $is_on_clock ? "On the clock ✅" : "Not on the clock ⏱"; ?>
        </p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" style="margin-top:8px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($access_code !== "" && $error === ""): ?>
            <div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:14px;">
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

        <p class="hint-text">
            Use <strong>Gate (Clock In / Out)</strong> to start or end your workday.
            Once you're on the clock, you can use printers and workstations.
        </p>
    </div>

    <div class="card">
        <h2>Simulate a Scan</h2>
        <p>Select what you're trying to access, then enter (or paste) your access code.</p>

        <form method="post" style="max-width:480px;">
            <label for="device_type">Device / Location</label>
            <select name="device_type" id="device_type">
                <?php foreach ($device_types as $key => $label): ?>
                    <option value="<?php echo htmlspecialchars($key); ?>"
                        <?php if ($key === $selected_device) echo "selected"; ?>>
                        <?php echo htmlspecialchars($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="code" style="margin-top:10px;">Access Code</label>
            <input type="text" id="code" name="code"
                   value="<?php echo htmlspecialchars($code_entered !== "" ? $code_entered : $access_code); ?>"
                   placeholder="Paste or type your access code">

            <button type="submit" class="btn btn-primary" style="margin-top:12px;">Scan</button>
        </form>

        <?php if ($result_msg !== ""): ?>
            <div class="alert <?php echo $result_ok ? 'alert-success' : 'alert-error'; ?>" style="margin-top:14px;">
                <?php echo htmlspecialchars($result_msg); ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
