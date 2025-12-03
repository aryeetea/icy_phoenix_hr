<?php
// analytics_dashboard.php
session_start();
require_once __DIR__ . "/db_connect.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'ceo') {
    header("Location: login.php");
    exit;
}

// Employees per department
$dept_data = [];
$res = $mysqli->query("
    SELECT d.dept_name, COUNT(e.emp_no) AS c
    FROM departments d
    LEFT JOIN employees e ON e.dept_no = d.dept_no AND e.employment_status = 'active'
    GROUP BY d.dept_name
    ORDER BY d.dept_name
");
while ($row = $res->fetch_assoc()) {
    $dept_data[] = $row;
}

// Salary bands
$salary_data = [
    "Under 60k" => 0,
    "60k–80k"   => 0,
    "80k–100k"  => 0,
    "100k–130k" => 0,
    "130k+"     => 0,
];
$res2 = $mysqli->query("
    SELECT salary FROM salaries WHERE is_current = 1
");
while ($row = $res2->fetch_assoc()) {
    $s = (float)$row["salary"];
    if ($s < 60000)       $salary_data["Under 60k"]++;
    elseif ($s < 80000)   $salary_data["60k–80k"]++;
    elseif ($s < 100000)  $salary_data["80k–100k"]++;
    elseif ($s < 130000)  $salary_data["100k–130k"]++;
    else                  $salary_data["130k+"]++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Analytics</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="ceo">
<div class="container">
    <header class="top-bar">
        <h1>Analytics Dashboard</h1>
        <div class="top-actions">
            <a href="ceo_dashboard.php" class="btn btn-secondary btn-small">Back to CEO Dashboard</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <div class="card">
        <h2>Headcount by Department</h2>
        <canvas id="deptChart" height="120"></canvas>
    </div>

    <div class="card">
        <h2>Salary Distribution</h2>
        <canvas id="salaryChart" height="120"></canvas>
    </div>
</div>

<script>
const deptLabels = <?php echo json_encode(array_column($dept_data, "dept_name")); ?>;
const deptCounts = <?php echo json_encode(array_map("intval", array_column($dept_data, "c"))); ?>;

const salaryLabels = <?php echo json_encode(array_keys($salary_data)); ?>;
const salaryCounts = <?php echo json_encode(array_values($salary_data)); ?>;

new Chart(document.getElementById('deptChart'), {
    type: 'bar',
    data: {
        labels: deptLabels,
        datasets: [{
            label: 'Active employees',
            data: deptCounts
        }]
    }
});

new Chart(document.getElementById('salaryChart'), {
    type: 'pie',
    data: {
        labels: salaryLabels,
        datasets: [{
            data: salaryCounts
        }]
    }
});
</script>
</body>
</html>