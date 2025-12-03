<?php
session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/schedule_helpers.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION["role"]; // employee, manager, ceo

// ====== Month Picker ======
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$month = max(1, min(12, $month));

$grid = ipx_build_month_grid($year, $month);
$firstGridDay = $grid[0][0]['date']->format('Y-m-d');
$lastGridDay  = end($grid)[6]['date']->format('Y-m-d');

// holidays
$holidays = ipx_get_holidays_in_range($mysqli, $firstGridDay, $lastGridDay);

// ALL department themes
$themes = [];
$res2 = $mysqli->query("SELECT dept_no FROM departments");
if ($res2) {
    while ($r = $res2->fetch_assoc()) {
        $deptThemes = ipx_get_department_themes($mysqli, $r['dept_no'], $firstGridDay, $lastGridDay);
        foreach ($deptThemes as $date => $th) {
            $themes[$date][] = $th;
        }
    }
}

$monthLabel = (new DateTimeImmutable("$year-$month-01"))->format('F Y');

// ====== Load employees only if manager or CEO ======
$employees = [];
if ($role === 'manager' || $role === 'ceo') {
    $res = $mysqli->query("
        SELECT e.emp_no,
               e.first_name,
               e.last_name,
               e.dept_no,
               d.dept_name
        FROM employees AS e
        LEFT JOIN departments AS d ON e.dept_no = d.dept_no
        WHERE e.employment_status = 'active'
        ORDER BY d.dept_name, e.last_name, e.first_name
    ");
    while ($row = $res->fetch_assoc()) {
        $employees[] = $row;
    }
}

// ====== Department Badge Class ======
function ipx_department_badge_class(?string $dept_no): string {
    return "dept-" . strtolower($dept_no ?: "none");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Team Schedule</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="<?php echo htmlspecialchars($role); ?>">
<div class="container">

    <header class="top-bar">
        <h1>Team Schedule</h1>
        <div class="top-actions">
            <?php if ($role === "employee"): ?>
                <a href="employee_dashboard.php" class="btn btn-secondary btn-small">Back</a>
            <?php elseif ($role === "manager"): ?>
                <a href="manager_dashboard.php" class="btn btn-secondary btn-small">Back</a>
            <?php else: ?>
                <a href="ceo_dashboard.php" class="btn btn-secondary btn-small">Back</a>
            <?php endif; ?>

            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <section class="card">

        <!-- Month Navigation -->
        <div class="schedule-header-row">
            <button class="cal-nav-btn"
                onclick="location.href='team_schedule.php?year=<?php echo ($month==1?$year-1:$year); ?>&month=<?php echo ($month==1?12:$month-1); ?>'">
                ‹
            </button>

            <div class="schedule-month-label"><?php echo htmlspecialchars($monthLabel); ?></div>

            <button class="cal-nav-btn"
                onclick="location.href='team_schedule.php?year=<?php echo ($month==12?$year+1:$year); ?>&month=<?php echo ($month==12?1:$month+1); ?>'">
                ›
            </button>
        </div>

        <p class="schedule-subtitle">
            Default shift: <strong>Mon–Fri, 9:00 AM – 5:00 PM</strong>.
            Weekends are days off. Holidays and department theme days appear automatically.
        </p>

        <!-- Calendar Grid -->
        <div class="cal-grid cal-grid-team">
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
                        $d = $day['date'];
                        $iso = $d->format('Y-m-d');

                        $classes = ['cal-cell'];
                        if (!$day['is_current']) $classes[] = 'cal-cell--muted';
                        if ($day['is_today']) $classes[] = 'cal-cell--today';

                        $shift = ipx_default_shift_for_date($d);
                        if ($shift['is_day_off']) $classes[] = 'cal-cell--off';
                        if (isset($holidays[$iso])) $classes[] = 'cal-cell--holiday';
                    ?>
                    <div class="<?php echo implode(' ', $classes); ?>">
                        <div class="cal-cell-date"><?php echo $d->format('j'); ?></div>

                        <!-- Holiday -->
                        <?php if (isset($holidays[$iso])): ?>
                            <div class="cal-pill cal-pill-holiday">
                                <?php echo htmlspecialchars($holidays[$iso]); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Theme Days -->
                        <?php if (isset($themes[$iso])): ?>
                            <?php foreach ($themes[$iso] as $th): ?>
                                <div class="cal-pill cal-pill-theme" style="background: <?php echo $th['theme_color']; ?>;">
                                    <?php echo htmlspecialchars($th['theme_name']); ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Shift -->
                        <?php if ($shift['is_day_off']): ?>
                            <div class="cal-pill cal-pill-off">Day Off</div>
                        <?php else: ?>
                            <div class="cal-pill cal-pill-shift">9:00–5:00</div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <?php if ($role !== 'employee'): ?>
            <h2 class="schedule-subheading">Team Members</h2>

            <div class="table-wrapper">
                <table class="ipx-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Employee No</th>
                            <th>Department</th>
                            <th>Default Shift</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $e): ?>
                            <tr>
                                <td><?php echo $e['first_name']." ".$e['last_name']; ?></td>
                                <td><?php echo $e['emp_no']; ?></td>
                                <td>
                                    <span class="dept-badge <?php echo ipx_department_badge_class($e['dept_no']); ?>">
                                        <?php echo $e['dept_name']; ?>
                                    </span>
                                </td>
                                <td>Mon–Fri, 9–5</td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$employees): ?>
                            <tr><td colspan="4">No active employees.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </section>

</div>
</body>
</html>