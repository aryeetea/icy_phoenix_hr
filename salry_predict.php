<?php
/**
 * AI-style salary prediction helper
 * ---------------------------------
 * This function generates a salary suggestion based on:
 *  - The employee’s years in the company
 *  - The average salary for their job title
 */

function ipx_predict_salary(mysqli $mysqli, int $salary, string $title, string $hire_date): array {

    // 1. Years at company
    $years = 0;
    if ($hire_date) {
        $years = (time() - strtotime($hire_date)) / (60 * 60 * 24 * 365);
    }

    // 2. Get average salary for this title
    $stmt = $mysqli->prepare("
        SELECT AVG(s.salary) AS avg_salary
        FROM salaries s
        JOIN employees e ON e.emp_no = s.emp_no
        JOIN titles t ON e.title_id = t.id
        WHERE s.is_current = 1
          AND t.title = ?
    ");
    $stmt->bind_param("s", $title);
    $stmt->execute();
    $avg = $stmt->get_result()->fetch_assoc()["avg_salary"] ?? null;
    $stmt->close();

    if ($avg === null) {
        $avg = $salary; // fallback
    }

    // 3. Adjust prediction based on experience
    $experience_bonus = min($years * 1500, 15000);  
    // max +15k for long-term employees

    $suggested = $avg + $experience_bonus;

    return [
        "suggested"      => round($suggested, 2),
        "base_title_avg" => round($avg, 2),
        "years"          => round($years, 2)
    ];
}