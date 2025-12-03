<?php
// rank_helpers.php
// Simple rank system for Icy Phoenix

function ipx_rank_from_points(int $points): string {
    if ($points >= 500) {
        return 'Mythic';
    } elseif ($points >= 300) {
        return 'Platinum';
    } elseif ($points >= 150) {
        return 'Gold';
    } elseif ($points >= 50) {
        return 'Silver';
    }
    return 'Bronze';
}

/**
 * Give rank points to an employee and update their rank_tier.
 *
 * Returns ['points' => int, 'tier' => string] or null if not found.
 */
function ipx_award_rank_points(mysqli $mysqli, string $emp_no, int $points): ?array
{
    // Add points
    $stmt = $mysqli->prepare("
        UPDATE employees 
        SET rank_points = rank_points + ? 
        WHERE emp_no = ?
    ");
    if (!$stmt) return null;

    $stmt->bind_param("is", $points, $emp_no);
    $stmt->execute();
    $stmt->close();

    // Fetch new total
    $stmt = $mysqli->prepare("
        SELECT rank_points, rank_tier 
        FROM employees 
        WHERE emp_no = ?
    ");
    if (!$stmt) return null;

    $stmt->bind_param("s", $emp_no);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if (!$row) return null;

    $current_points = (int)$row['rank_points'];
    $old_tier       = $row['rank_tier'];
    $new_tier       = ipx_rank_from_points($current_points);

    if ($new_tier !== $old_tier) {
        $stmt = $mysqli->prepare("
            UPDATE employees 
            SET rank_tier = ? 
            WHERE emp_no = ?
        ");
        if ($stmt) {
            $stmt->bind_param("ss", $new_tier, $emp_no);
            $stmt->execute();
            $stmt->close();
        }
    }

    return [
        'points' => $current_points,
        'tier'   => $new_tier,
    ];
}