<?php
// leave_helpers.php

/**
 * Get leave history for one employee (with approver name if available).
 */
function ipx_get_leave_history(mysqli $mysqli, string $emp_no): array
{
    $rows = [];

    $stmt = $mysqli->prepare("
        SELECT
            lr.id,
            lr.leave_type,
            lr.start_date,
            lr.end_date,
            lr.status,
            lr.approved_by,
            CONCAT(a.first_name, ' ', a.last_name) AS approver_name
        FROM leave_requests AS lr
        LEFT JOIN employees AS a
            ON lr.approved_by = a.emp_no
        WHERE lr.emp_no = ?
        ORDER BY lr.start_date DESC
    ");

    if ($stmt) {
        $stmt->bind_param("s", $emp_no);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    }

    return $rows;
}