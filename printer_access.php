<?php
session_start();
require_once __DIR__ . "/db_connect.php";

// CEO only
if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'ceo') {
    header("Location: login.php");
    exit;
}

$printers = [
    "hq_laser_1"      => "HQ – LaserJet Black & White",
    "design_lab_color"=> "Design Lab – Color Printer",
    "game_studio_dev" => "Game Studio – Dev Printer"
];

$selected_printer = $_POST["printer_id"] ?? "hq_laser_1";
$code             = trim($_POST["code"] ?? "");
$result_msg       = "";
$employee         = null;
$jobs             = [];

/**
 * Log printer access to audit_logs
 * $status: 'granted' or 'denied'
 */
function log_printer_access(mysqli $mysqli, string $printer_id, ?string $emp_no, string $code_used, string $status): void {
    $table_name = "printer";
    $record_pk  = $printer_id;
    $action     = "access";
    $changed_by = $emp_no; // can be null
    $old_values = null;
    $new_values = json_encode([
        "code_used" => $code_used,
        "status"    => $status
    ]);

    $stmt = $mysqli->prepare("
        INSERT INTO audit_logs (table_name, record_pk, action, changed_by, old_values, new_values)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($stmt) {
        $stmt->bind_param("ssssss", $table_name, $record_pk, $action, $changed_by, $old_values, $new_values);
        $stmt->execute();
        $stmt->close();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($code === "") {
        $result_msg = "Please enter an access code.";
        // log denied with no employee
        log_printer_access($mysqli, $selected_printer, null, "", "denied_empty");
    } else {
        $stmt = $mysqli->prepare("
            SELECT emp_no, first_name, last_name, employment_status
            FROM employees
            WHERE access_code = ?
        ");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $res = $stmt->get_result();
        $employee = $res->fetch_assoc();
        $stmt->close();

        if (!$employee) {
            $result_msg = "Access denied: code not recognized.";
            log_printer_access($mysqli, $selected_printer, null, $code, "denied_unknown");
        } elseif ($employee["employment_status"] !== "active") {
            $result_msg = "Access denied: employee is not active.";
            log_printer_access($mysqli, $selected_printer, $employee["emp_no"], $code, "denied_inactive");
        } else {
            $printer_name = $printers[$selected_printer] ?? "Unknown Printer";
            $result_msg   = "Access granted for "
                . $employee["first_name"] . " " . $employee["last_name"]
                . " (Emp #" . $employee["emp_no"] . ") on "
                . $printer_name . ".";

            log_printer_access($mysqli, $selected_printer, $employee["emp_no"], $code, "granted");

            // Fake jobs
            $jobs = [
                [
                    "doc"   => "Project Wireframes – Icy Phoenix",
                    "pages" => 8,
                    "owner" => $employee["first_name"] . " " . $employee["last_name"],
                ],
                [
                    "doc"   => "Game Design Notes – Jianxin Isle",
                    "pages" => 4,
                    "owner" => $employee["first_name"] . " " . $employee["last_name"],
                ],
                [
                    "doc"   => "Sprint Tasks – Web/UI Team",
                    "pages" => 2,
                    "owner" => $employee["first_name"] . " " . $employee["last_name"],
                ],
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Printer Access Demo</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="ceo">

<div class="container">
    <header class="top-bar">
        <h1>Printer Access Demo (CEO)</h1>
        <div>
            <a href="ceo_dashboard.php" class="btn btn-secondary btn-small">Back to CEO Dashboard</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <div class="card">
        <p>This page simulates how any access code in the company would unlock printers.</p>

        <form method="post" style="max-width:480px; margin-top:10px;">
            <label for="printer_id">Select Printer</label>
            <select name="printer_id" id="printer_id">
                <?php foreach ($printers as $pid => $pname): ?>
                    <option value="<?php echo htmlspecialchars($pid); ?>"
                        <?php if ($pid === $selected_printer) echo "selected"; ?>>
                        <?php echo htmlspecialchars($pname); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="code" style="margin-top:10px;">Employee Access Code</label>
            <input type="text" id="code" name="code"
                   value="<?php echo htmlspecialchars($code); ?>"
                   placeholder="IPX-20001-ABC123">

            <button type="submit" class="btn btn-primary" style="margin-top:10px;">
                Scan & Release Jobs
            </button>
        </form>

        <?php if ($result_msg): ?>
            <div class="alert <?php echo $employee ? 'alert-success' : 'alert-error'; ?>" style="margin-top:16px;">
                <?php echo htmlspecialchars($result_msg); ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($employee && $jobs): ?>
        <div class="card">
            <h2>Print Jobs Released</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Document</th>
                    <th>Pages</th>
                    <th>Owner</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($job["doc"]); ?></td>
                        <td><?php echo (int)$job["pages"]; ?></td>
                        <td><?php echo htmlspecialchars($job["owner"]); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:10px;font-size:13px;opacity:0.8;">
                In a real setup, this would send the jobs to the physical printer after a successful scan.
            </p>
        </div>
    <?php endif; ?>

</div>

</body>
</html>