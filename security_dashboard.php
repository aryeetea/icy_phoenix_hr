<?php
// security_dashboard.php
session_start();
require_once __DIR__ . "/db_connect.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'ceo') {
    header("Location: login.php");
    exit;
}

$ceo_name = $_SESSION["emp_name"];

// Simple filter (last 100 events, or by table_name)
$filter_table = $_GET["table"] ?? "";
$limit        = 100;

$sql = "
    SELECT a.id, a.table_name, a.record_pk, a.action, a.changed_by, a.changed_at, a.new_values,
           e.first_name, e.last_name
    FROM audit_logs a
    LEFT JOIN employees e ON a.changed_by = e.emp_no
";

$params = [];
$types  = "";

if ($filter_table !== "") {
    $sql .= " WHERE a.table_name = ? ";
    $types  = "s";
    $params[] = $filter_table;
}

$sql .= " ORDER BY a.changed_at DESC LIMIT ? ";
$types .= "i";
$params[] = $limit;

$stmt = $mysqli->prepare($sql);
if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$logs = $stmt->get_result();
$stmt->close();

// Quick access stats (today only)
$today = date("Y-m-d");

$access_stats = $mysqli->query("
    SELECT
        SUM(JSON_EXTRACT(new_values, '$.status') = 'granted') AS granted_count,
        SUM(JSON_EXTRACT(new_values, '$.status') = 'denied')  AS denied_count
    FROM audit_logs
    WHERE table_name = 'access_scanner'
      AND DATE(changed_at) = '{$today}'
")->fetch_assoc();

$granted_today = (int)($access_stats["granted_count"] ?? 0);
$denied_today  = (int)($access_stats["denied_count"] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Security Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="ceo">
<div class="container">
    <header class="top-bar">
        <h1>Security Dashboard – <?php echo htmlspecialchars($ceo_name); ?></h1>
        <div class="top-actions">
            <a href="ceo_dashboard.php" class="btn btn-secondary btn-small">Back to CEO Dashboard</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <div class="grid-3">
        <div class="card">
            <h2>Today's Access Scans</h2>
            <p><strong><?php echo $granted_today; ?></strong> granted</p>
            <p><strong><?php echo $denied_today; ?></strong> denied</p>
        </div>
        <div class="card">
            <h2>Filters</h2>
            <p>Viewing last 100 events.</p>
            <form method="get">
                <label for="table">Event Source</label>
                <select name="table" id="table" onchange="this.form.submit()">
                    <option value="">All sources</option>
                    <option value="access_scanner" <?php if ($filter_table === "access_scanner") echo "selected"; ?>>Access Scanner</option>
                    <option value="printer" <?php if ($filter_table === "printer") echo "selected"; ?>>Printers</option>
                    <option value="login" <?php if ($filter_table === "login") echo "selected"; ?>>Logins</option>
                    <option value="logout" <?php if ($filter_table === "logout") echo "selected"; ?>>Logouts</option>
                </select>
            </form>
        </div>
        <div class="card">
            <h2>Tip</h2>
            <p>Use this dashboard to watch for repeated denied scans on gates or devices. That might mean a lost badge or suspicious activity.</p>
        </div>
    </div>

    <div class="card">
        <h2>Recent Security Events</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Time</th>
                <th>User</th>
                <th>Source</th>
                <th>Target</th>
                <th>Action</th>
                <th>Details</th>
            </tr>
            </thead>
            <tbody>
            <?php while ($row = $logs->fetch_assoc()): ?>
                <?php
                $who = $row["first_name"]
                    ? $row["first_name"] . " " . $row["last_name"] . " (" . $row["changed_by"] . ")"
                    : $row["changed_by"];

                $details = "";
                if (!empty($row["new_values"])) {
                    $decoded = @json_decode($row["new_values"], true);
                    if (is_array($decoded)) {
                        if (isset($decoded["status"])) {
                            $details .= "Status: " . $decoded["status"] . "; ";
                        }
                        if (isset($decoded["device_type"])) {
                            $details .= "Device: " . $decoded["device_type"] . "; ";
                        }
                        if (isset($decoded["code_used"])) {
                            $details .= "Code: " . $decoded["code_used"] . "; ";
                        }
                        $details = trim($details);
                    }
                }
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($row["changed_at"]); ?></td>
                    <td><?php echo htmlspecialchars($who); ?></td>
                    <td><?php echo htmlspecialchars($row["table_name"]); ?></td>
                    <td><?php echo htmlspecialchars($row["record_pk"]); ?></td>
                    <td><?php echo htmlspecialchars($row["action"]); ?></td>
                    <td><?php echo htmlspecialchars($details); ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>