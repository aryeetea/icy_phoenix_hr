<?php
// schedule_helpers.php
//
// Helpers for building calendar grids, default 9–5 schedules,
// company holidays, and department theme days.

/**
 * Build a month grid for a calendar view.
 * Returns an array of weeks, each week is an array of 7 days:
 * [
 *   'date'           => DateTimeImmutable,
 *   'is_current'     => bool,
 *   'is_today'       => bool,
 *   'weekday_index'  => int (1 = Mon ... 7 = Sun),
 * ]
 */
function ipx_build_month_grid(int $year, int $month): array
{
    $firstDay    = new DateTimeImmutable("$year-$month-01");
    $startWeekday = (int)$firstDay->format('N'); // 1 = Mon ... 7 = Sun

    // Calendar starts on Monday
    $gridStart = $firstDay->modify('-' . ($startWeekday - 1) . ' days');

    // End date: last day of month, then forward to Sunday
    $lastDay    = $firstDay->modify('last day of this month');
    $endWeekday = (int)$lastDay->format('N');
    $gridEnd    = $lastDay->modify('+' . (7 - $endWeekday) . ' days');

    $today = new DateTimeImmutable('today');

    $weeks  = [];
    $cursor = $gridStart;

    while ($cursor <= $gridEnd) {
        $week = [];
        for ($i = 0; $i < 7; $i++) {
            $isCurrent = ((int)$cursor->format('n') === $month);
            $isToday   = ($cursor->format('Y-m-d') === $today->format('Y-m-d'));

            $week[] = [
                'date'          => $cursor,
                'is_current'    => $isCurrent,
                'is_today'      => $isToday,
                'weekday_index' => (int)$cursor->format('N'), // 1..7
            ];

            $cursor = $cursor->modify('+1 day');
        }
        $weeks[] = $week;
    }

    return $weeks;
}

/**
 * Default shift rule:
 *   - Mon–Fri: 9:00 AM – 5:00 PM
 *   - Sat/Sun: Day off
 */
function ipx_default_shift_for_date(DateTimeImmutable $d): array
{
    $weekday = (int)$d->format('N'); // 6,7 = weekend

    if ($weekday >= 6) {
        return [
            'is_day_off' => true,
            'label'      => 'Day off',
        ];
    }

    return [
        'is_day_off' => false,
        'label'      => '9:00 AM – 5:00 PM',
    ];
}

/**
 * Optional: fetch holidays inside a date range.
 * Table: company_holidays(holiday_date DATE, name VARCHAR…)
 */
function ipx_get_holidays_in_range(mysqli $mysqli, string $startDate, string $endDate): array
{
    $holidays = [];

    $stmt = $mysqli->prepare("
        SELECT holiday_date, name
        FROM company_holidays
        WHERE holiday_date BETWEEN ? AND ?
    ");
    if (!$stmt) {
        return $holidays;
    }

    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $holidays[$row['holiday_date']] = $row['name'];
    }
    $stmt->close();

    return $holidays;
}

/**
 * Fetch themed days per department in a date range.
 *
 * Table: department_themes(
 *   dept_no    VARCHAR,
 *   theme_date DATE,
 *   theme_name VARCHAR,
 *   theme_color VARCHAR (hex or css colour)
 * )
 *
 * Returns:
 * [
 *   '2025-12-05' => [
 *       ['dept_no' => 'd001', 'theme_name' => 'Retro Console Day', 'theme_color' => '#ff66aa'],
 *       ['dept_no' => 'd003', 'theme_name' => 'UI Neon Night',    'theme_color' => '#00ffcc'],
 *   ],
 *   ...
 * ]
 */
function ipx_get_department_themes(mysqli $mysqli, string $dept_no, string $startDate, string $endDate): array
{
    $themes = [];

    $stmt = $mysqli->prepare("
        SELECT theme_date, theme_name, theme_color
        FROM department_themes
        WHERE dept_no = ?
          AND theme_date BETWEEN ? AND ?
    ");
    if (!$stmt) {
        return $themes;
    }

    $stmt->bind_param("sss", $dept_no, $startDate, $endDate);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $date = $row['theme_date'];
        if (!isset($themes[$date])) {
            $themes[$date] = [];
        }
        $themes[$date][] = $row;
    }

    $stmt->close();

    return $themes;
}