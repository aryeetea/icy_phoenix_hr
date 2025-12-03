<?php
session_start();
require_once __DIR__ . "/db_connect.php";

// CEO only
if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'ceo') {
    header("Location: login.php");
    exit;
}

// Filters from GET
$filter_emp   = trim($_GET["emp_no"] ?? "");
$filter_prn   = trim($_GET["printer_id"] ?? "");
$filter_status = trim($_GET["status"] ?? "");
$filter_date_from = trim($_GET["date_from"] ?? "");
$filter_date_to   = trim($_GET["date_to"] ?? "");

// Base query: get latest logs, join with employees
$sql = "
    SELECT a.id, a.record_pk, a.changed_at, a.changed_by, a.new_values,
           e.first_name, e.last_name
    FROM audit_logs a
    LEFT JOIN employees e ON e.emp_no = a.changed_by
    WHERE a.table_name = 'printer'
      AND a.action = 'access'
    ORDER BY a.changed_at DESC
    LIMIT 200
";
$result = $mysqli->query($sql);

$logs = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // decode JSON new_values
        $status = "";
        $code_used = "";
        if (!empty($row["new_values"])) {
            $decoded = json_decode($row["new_values"], true);
            if (is_array($decoded)) {
                $status    = $decoded["status"]    ?? "";
                $code_used = $decoded["code_used"] ?? "";
            }
        }
        $row["status"]    = $status;
        $row["code_used"] = $code_used;
        $logs[] = $row;
    }
}

// Apply filters in PHP (simple & safe)
$filtered_logs = array_filter($logs, function ($log) use (
    $filter_emp, $filter_prn, $filter_status, $filter_date_from, $filter_date_to
) {
    // emp filter
    if ($filter_emp !== "" && $log["changed_by"] !== $filter_emp) {
        return false;
    }
    // printer filter
    if ($filter_prn !== "" && $log["record_pk"] !== $filter_prn) {
        return false;
    }
    // status filter
    if ($filter_status !== "" && $log["status"] !== $filter_status) {
        return false;
    }
    // date filters
    if ($filter_date_from !== "") {
        if (substr($log["changed_at"], 0, 10) < $filter_date_from) {
            return false;
        }
    }
    if ($filter_date_to !== "") {
        if (substr($log["changed_at"], 0, 10) > $filter_date_to) {
            return false;
        }
    }
    return true;
});

// Known printers (same keys as printer_access pages)
$printers = [
    "hq_laser_1"      => "HQ – LaserJet Black & White",
    "design_lab_color"=> "Design Lab – Color Printer",
    "game_studio_dev" => "Game Studio – Dev Printer"
];

function printerName($id, $printers) {
    return $printers[$id] ?? $id;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Printer Logs</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="ceo">

<div class="container">
    <header class="top-bar">
        <h1>Printer Access Logs</h1>
        <div>
            <a href="ceo_dashboard.php" class="btn btn-secondary btn-small">Back to CEO Dashboard</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <div class="card">
        <h2>Filters</h2>
        <form method="get" class="grid-3" style="row-gap:12px;">
            <div>
                <label for="emp_no">Employee No</label>
                <input type="text" id="emp_no" name="emp_no"
                       value="<?php echo htmlspecialchars($filter_emp); ?>"
                       placeholder="e.g. 20001">
            </div>
            <div>
                <label for="printer_id">Printer</label>
                <select id="printer_id" name="printer_id">
                    <option value="">All printers</option>
                    <?php foreach ($printers as $pid => $pname): ?>
                        <option value="<?php echo htmlspecialchars($pid); ?>"
                            <?php if ($pid === $filter_prn) echo "selected"; ?>>
                            <?php echo htmlspecialchars($pname); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    <option value="granted"          <?php if ($filter_status === "granted") echo "selected"; ?>>Granted</option>
                    <option value="denied_unknown"   <?php if ($filter_status === "denied_unknown") echo "selected"; ?>>Denied – Unknown Code</option>
                    <option value="denied_inactive"  <?php if ($filter_status === "denied_inactive") echo "selected"; ?>>Denied – Inactive</option>
                    <option value="denied_empty"     <?php if ($filter_status === "denied_empty") echo "selected"; ?>>Denied – Empty Code</option>
                </select>
            </div>
            <div>
                <label for="date_from">From Date</label>
                <input type="date" id="date_from" name="date_from"
                       value="<?php echo htmlspecialchars($filter_date_from); ?>">
            </div>
            <div>
                <label for="date_to">To Date</label>
                <input type="date" id="date_to" name="date_to"
                       value="<?php echo htmlspecialchars($filter_date_to); ?>">
            </div>
            <div style="display:flex;align-items:flex-end;gap:8px;">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="printer_logs.php" class="btn btn-secondary btn-small">Clear</a>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Recent Printer Access (latest 200)</h2>
        <?php if (empty($filtered_logs)): ?>
            <p>No logs match your filters yet.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Time</th>
                    <th>Employee</th>
                    <th>Printer</th>
                    <th>Status</th>
                    <th>Code Used</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($filtered_logs as $log): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($log["changed_at"]); ?></td>
                        <td>
                            <?php if ($log["changed_by"]): ?>
                                #<?php echo htmlspecialchars($log["changed_by"]); ?>
                                <?php if ($log["first_name"] || $log["last_name"]): ?>
                                    – <?php echo htmlspecialchars(trim(($log["first_name"] ?? "") . " " . ($log["last_name"] ?? ""))); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                System / Unknown
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars(printerName($log["record_pk"], $printers)); ?></td>
                        <td>
                            <?php
                            $status = $log["status"];
                            if ($status === "granted") {
                                echo "Granted";
                            } elseif ($status === "denied_unknown") {
                                echo "Denied – Unknown Code";
                            } elseif ($status === "denied_inactive") {
                                echo "Denied – Inactive";
                            } elseif ($status === "denied_empty") {
                                echo "Denied – Empty Code";
                            } else {
                                echo htmlspecialchars($status ?: "Unknown");
                            }
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($log["code_used"]); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <p style="margin-top:10px;font-size:13px;opacity:0.8;">
            Logs are capped to the latest 200 entries for performance. All printer events are recorded in the audit_logs table.
        </p>
    </div>
</div>

</body>
</html>