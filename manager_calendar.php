<?php
// manager_calendar.php
session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/holidays.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'manager') {
    header("Location: login.php");
    exit;
}

$manager_no   = $_SESSION["emp_no"];
$manager_name = $_SESSION["emp_name"] ?? "Manager";

// Month / year
$year  = isset($_GET["year"])  ? (int)$_GET["year"]  : (int)date("Y");
$month = isset($_GET["month"]) ? (int)$_GET["month"] : (int)date("m");

if ($month < 1) $month = 1;
if ($month > 12) $month = 12;

$holidays = ipx_get_holidays_for_month($year, $month);

$firstDayTs   = strtotime(sprintf("%04d-%02d-01", $year, $month));
$daysInMonth  = (int)date("t", $firstDayTs);
$firstWeekday = (int)date("N", $firstDayTs); // 1..7 (Mon..Sun)

// prev / next
$prevYear  = $year;
$prevMonth = $month - 1;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextYear  = $year;
$nextMonth = $month + 1;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

$monthName = date("F", $firstDayTs);
$todayStr  = date("Y-m-d");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Manager Calendar</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .calendar-nav {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:10px;
        }
        .calendar-table {
            width:100%;
            border-collapse:collapse;
            font-size:13px;
        }
        .calendar-table th,
        .calendar-table td {
            border:1px solid #ebddcf;
            padding:6px;
            vertical-align:top;
            height:70px;
        }
        .calendar-table th {
            background:#f7eadf;
            text-align:center;
            font-weight:600;
            font-size:11px;
            letter-spacing:0.08em;
            text-transform:uppercase;
        }
        .calendar-day-number {
            font-weight:600;
            margin-bottom:4px;
        }
        .holiday-label {
            display:block;
            margin-top:3px;
            font-size:11px;
            color:#b23838;
        }
        .today-cell {
            box-shadow:0 0 0 2px rgba(214,143,74,0.7) inset;
        }
    </style>
</head>
<body class="manager">
<div class="container">
    <header class="top-bar">
        <h1>Manager Calendar – <?php echo htmlspecialchars($manager_name); ?></h1>
        <div>
            <a href="manager_dashboard.php" class="btn btn-secondary btn-small">Back to Dashboard</a>
            <a href="scan_access.php" class="btn btn-secondary btn-small">Access Scanner</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <div class="card">
        <div class="calendar-nav">
            <a class="btn btn-secondary btn-small"
               href="?year=<?php echo $prevYear; ?>&month=<?php echo $prevMonth; ?>">&larr; Previous</a>
            <h2 style="margin:0;"><?php echo htmlspecialchars($monthName . " " . $year); ?></h2>
            <a class="btn btn-secondary btn-small"
               href="?year=<?php echo $nextYear; ?>&month=<?php echo $nextMonth; ?>">Next &rarr;</a>
        </div>

        <p class="hint-text">
            This calendar shows <strong>company holidays</strong> and <strong>fun theme days</strong> 
            so you can plan your team’s schedule.
        </p>

        <table class="calendar-table">
            <thead>
                <tr>
                    <th>Mon</th>
                    <th>Tue</th>
                    <th>Wed</th>
                    <th>Thu</th>
                    <th>Fri</th>
                    <th>Sat</th>
                    <th>Sun</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $day = 1;
            for ($week = 0; $week < 6 && $day <= $daysInMonth; $week++) {
                echo "<tr>";
                for ($dow = 1; $dow <= 7; $dow++) {
                    if ($week === 0 && $dow < $firstWeekday) {
                        echo "<td></td>";
                    } elseif ($day > $daysInMonth) {
                        echo "<td></td>";
                    } else {
                        $dateStr = sprintf("%04d-%02d-%02d", $year, $month, $day);
                        $classes = [];
                        if ($dateStr === $todayStr) $classes[] = "today-cell";
                        $classAttr = $classes ? ' class="'.implode(" ", $classes).'"' : "";

                        echo "<td{$classAttr}>";
                        echo '<div class="calendar-day-number">' . $day . '</div>';

                        if (isset($holidays[$dateStr])) {
                            echo '<span class="holiday-label">'
                               . htmlspecialchars($holidays[$dateStr])
                               . '</span>';
                        }

                        echo "</td>";
                        $day++;
                    }
                }
                echo "</tr>";
            }
            ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>