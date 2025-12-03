<?php
// salary_predict.php
//
// Not true ML, but a simple algorithm that can be explained in docs.

/**
 * Return a suggestion array:
 * [
 *   'base_title_avg' => float|null,
 *   'years'          => float,
 *   'suggested'      => float|null
 * ]
 */
function ipx_suggest_salary(
    mysqli $mysqli,
    string $emp_no,
    ?string $title_id
): array {
    $years = 0.0;
    $base  = null;

    // 1) years of service
    $stmt = $mysqli->prepare("
        SELECT hire_date FROM employees WHERE emp_no = ? LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $emp_no);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($res && $res["hire_date"]) {
            $hire_ts = strtotime($res["hire_date"]);
            $now_ts  = time();
            $years   = max(0, ($now_ts - $hire_ts) / (365 * 24 * 3600));
        }
    }

    // 2) average salary for people with same title
    if ($title_id !== null) {
        $stmt2 = $mysqli->prepare("
            SELECT AVG(s.salary) AS avg_sal
            FROM employees e
            JOIN salaries s ON e.emp_no = s.emp_no AND s.is_current = 1
            WHERE e.title_id = ?
        ");
        if ($stmt2) {
            $stmt2->bind_param("i", $title_id);
            $stmt2->execute();
            $row2 = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
            if ($row2 && $row2["avg_sal"] !== null) {
                $base = (float)$row2["avg_sal"];
            }
        }
    }

    if ($base === null) {
        // Fallback guess if no colleagues
        $base = 60000.0;
    }

    // 3) Add 2% per year of service, up to +20%
    $multiplier = 1.0 + min($years, 10) * 0.02;
    $suggested  = $base * $multiplier;

    return [
        'base_title_avg' => $base,
        'years'          => $years,
        'suggested'      => $suggested,
    ];
}