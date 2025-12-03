<?php
// two_factor_helpers.php

// Generate a random 6-digit code as a string (keeps leading zeros)
function ipx_generate_otp(): string
{
    return str_pad(random_int(0, 999999), 6, "0", STR_PAD_LEFT);
}

/**
 * Store OTP in the database with a 30-minute expiration.
 */
function ipx_create_otp(mysqli $mysqli, string $emp_no, string $otp): bool
{
    // Delete old codes for this user
    if ($del = $mysqli->prepare("DELETE FROM otp_codes WHERE emp_no = ?")) {
        $del->bind_param("s", $emp_no);
        $del->execute();
        $del->close();
    }

    // Insert new OTP, expires in 30 minutes
    $stmt = $mysqli->prepare("
        INSERT INTO otp_codes (emp_no, otp, expires_at)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
    ");

    if (!$stmt) {
        error_log("OTP insert failed: " . $mysqli->error);
        return false;
    }

    $stmt->bind_param("ss", $emp_no, $otp);
    $stmt->execute();
    $stmt->close();

    return true;
}

/**
 * Verify OTP for a user.
 * Returns true only if:
 *   - emp_no matches
 *   - otp matches
 *   - expires_at is still in the future
 */
function ipx_verify_otp(mysqli $mysqli, string $emp_no, string $otp): bool
{
    $stmt = $mysqli->prepare("
        SELECT 1
        FROM otp_codes
        WHERE emp_no = ?
          AND otp    = ?
          AND expires_at > NOW()
        LIMIT 1
    ");

    if (!$stmt) {
        error_log("OTP verify failed: " . $mysqli->error);
        return false;
    }

    $stmt->bind_param("ss", $emp_no, $otp);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok  = ($res && $res->num_rows === 1);
    $stmt->close();

    // Optional: delete the OTP after successful use (one-time code)
    if ($ok) {
        if ($del = $mysqli->prepare("DELETE FROM otp_codes WHERE emp_no = ?")) {
            $del->bind_param("s", $emp_no);
            $del->execute();
            $del->close();
        }
    }

    return $ok;
}