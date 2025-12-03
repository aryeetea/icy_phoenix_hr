<?php
/**
 * Icy Phoenix Holiday + Theme Day System
 *
 * Generates:
 * 1. Standard real holidays
 * 2. Company holidays
 * 3. Fun theme days (fixed)
 * 4. Random monthly surprise theme days (auto-generated)
 */

function ipx_get_holidays_for_year(int $year): array
{
    $holidays = [];

    $add = function (string $date, string $name) use (&$holidays) {
        $holidays[$date] = $name;
    };

    /* ================================
       1. REAL STANDARD HOLIDAYS
    ================================== */
    $add("$year-01-01", "New Year's Day");
    $add("$year-07-04", "Independence Day");
    $add("$year-11-28", "Thanksgiving Day");  // customize by year if needed
    $add("$year-12-25", "Christmas Day");

    /* ================================
       2. ICY PHOENIX COMPANY HOLIDAYS
    ================================== */
    $add("$year-03-15", "Icy Phoenix Day");
    $add("$year-09-10", "Global Creativity Day");
    $add("$year-06-01", "Studio Wellness Day");

    /* ================================
       3. FIXED FUN THEME DAYS
    ================================== */
    $fun_days = [
        "02-14" => "Pink & Cute Day 💗",
        "04-15" => "Pajama Productivity Day 🛌",
        "05-05" => "Anime Opening Theme Day 🎵",
        "06-21" => "Summer Neon Outfit Day 🌈",
        "10-13" => "Spooky Cozy Coding Day 🎃",
        "11-03" => "Bring Your Plushie Day 🧸",
        "12-10" => "Bunny Hoodie Friday 🐰",
    ];

    foreach ($fun_days as $md => $name) {
        $add("$year-$md", $name);
    }

    /* ================================
       4. RANDOM MONTHLY "SURPRISE HOLIDAYS"
       Each month gets 0–2 surprise theme days.
    ================================== */

    // Fun surprise themes
    $randomThemes = [
        "Mystery Snack Day",
        "Ice Element Energy Day ❄️",
        "Phoenix Fire Spirit Day 🔥",
        "K-Pop Dance Break Day 💃",
        "Retro Game Hour Day 🎮",
        "Match With Your Manager Day 👯",
        "Cute Stationery Appreciation Day ✨",
        "Anime Episode Lunch Break 🍱",
        "Fantasy Cloak / Cape Day 🧙‍♂️",
        "Music & Headphones Day 🎧",
    ];

    // For each month, generate up to 2 random holidays
    for ($month = 1; $month <= 12; $month++) {
        $howMany = rand(0, 2); // up to 2 random holidays per month
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

        for ($i = 0; $i < $howMany; $i++) {
            $day = rand(1, $daysInMonth);

            $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
            $label = $randomThemes[array_rand($randomThemes)];

            // Avoid overwriting existing holidays
            if (!isset($holidays[$date])) {
                $holidays[$date] = $label;
            }
        }
    }

    return $holidays;
}

/**
 * Returns only holidays that fall inside the selected month.
 */
function ipx_get_holidays_for_month(int $year, int $month): array
{
    $all = ipx_get_holidays_for_year($year);
    $filtered = [];

    foreach ($all as $date => $name) {
        [$y, $m, $d] = explode('-', $date);
        if ((int)$y === $year && (int)$m === $month) {
            $filtered[$date] = $name;
        }
    }

    return $filtered;
}