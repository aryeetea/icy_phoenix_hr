<?php
// employee_schedule.php
session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/schedule_helpers.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"])) {
    header("Location: login.php");
    exit;
}

$emp_no   = $_SESSION["emp_no"];
$emp_name = $_SESSION["emp_name"] ?? "";

// Month navigation
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

// clamp
if ($month < 1)  { $month = 1; }
if ($month > 12) { $month = 12; }

$grid = ipx_build_month_grid($year, $month);

$firstGridDay = $grid[0][0]['date']->format('Y-m-d');
$lastGridDay  = end($grid)[6]['date']->format('Y-m-d');

$holidays = ipx_get_holidays_in_range($mysqli, $firstGridDay, $lastGridDay);

$monthLabel = (new DateTimeImmutable("$year-$month-01"))->format('F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – My Schedule</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="employee">
<div class="container">

    <header class="top-bar">
        <h1>My Schedule</h1>
        <div class="top-actions">
            <a href="employee_dashboard.php" class="btn btn-secondary btn-small">Back to Dashboard</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <section class="card schedule-card">
        <div class="schedule-header-row">
            <button class="cal-nav-btn"
                    onclick="window.location.href='employee_schedule.php?year=<?php
                        echo ($month == 1 ? $year - 1 : $year);
                    ?>&month=<?php
                        echo ($month == 1 ? 12 : $month - 1);
                    ?>'">
                ‹
            </button>
            <div class="schedule-month-label">
                <?php echo htmlspecialchars($monthLabel); ?>
            </div>
            <button class="cal-nav-btn"
                    onclick="window.location.href='employee_schedule.php?year=<?php
                        echo ($month == 12 ? $year + 1 : $year);
                    ?>&month=<?php
                        echo ($month == 12 ? 1 : $month + 1);
                    ?>'">
                ›
            </button>
        </div>

        <p class="schedule-subtitle">
            Default shift: <strong>Mon–Fri, 9:00 AM – 5:00 PM</strong>.
            Weekends are shown as <strong>Day off</strong>.
        </p>

        <div class="cal-grid">
            <div class="cal-grid-header">Mon</div>
            <div class="cal-grid-header">Tue</div>
            <div class="cal-grid-header">Wed</div>
            <div class="cal-grid-header">Thu</div>
            <div class="cal-grid-header">Fri</div>
            <div class="cal-grid-header">Sat</div>
            <div class="cal-grid-header">Sun</div>

            <?php foreach ($grid as $week): ?>
                <?php foreach ($week as $day): ?>
                    <?php
                        /** @var DateTimeImmutable $d */
                        $d = $day['date'];
                        $iso = $d->format('Y-m-d');

                        $shift   = ipx_default_shift_for_date($d);
                        $isHol   = isset($holidays[$iso]);
                        $isCurr  = $day['is_current'];
                        $isToday = $day['is_today'];

                        $classes = ['cal-cell'];
                        if (!$isCurr)   $classes[] = 'cal-cell--muted';
                        if ($isToday)   $classes[] = 'cal-cell--today';
                        if ($shift['is_day_off']) $classes[] = 'cal-cell--off';
                        if ($isHol)     $classes[] = 'cal-cell--holiday';
                    ?>
                    <div class="<?php echo implode(' ', $classes); ?>">
                        <div class="cal-cell-date">
                            <?php echo $d->format('j'); ?>
                        </div>

                        <?php if ($isHol): ?>
                            <div class="cal-pill cal-pill-holiday">
                                <?php echo htmlspecialchars($holidays[$iso]); ?>
                            </div>
                        <?php endif; ?>

                        <div class="cal-pill <?php echo $shift['is_day_off'] ? 'cal-pill-off' : 'cal-pill-shift'; ?>">
                            <?php echo htmlspecialchars($shift['label']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <div class="cal-legend">
            <span class="legend-item">
                <span class="legend-dot legend-dot-shift"></span> 9–5 shift
            </span>
            <span class="legend-item">
                <span class="legend-dot legend-dot-off"></span> Day off
            </span>
            <span class="legend-item">
                <span class="legend-dot legend-dot-holiday"></span> Company holiday
            </span>
            <span class="legend-item">
                <span class="legend-dot legend-dot-today"></span> Today
            </span>
        </div>
    </section>
</div>
</body>
</html>