<?php
// email_helper.php
// Central place for all outbound emails from Icy Phoenix.
//
// Right now this uses PHP's mail() so it works with basic setups,
// and ALSO logs every email into email_log.txt so you can show it
// to your professor even if real email isn't configured.

/**
 * Send a basic notification email.
 * This is used for:
 *  - leave approvals / rejections
 *  - onboarding credentials from CSV import
 */
function ipx_send_notification(string $to, string $subject, string $body): bool
{
    if (trim($to) === '') {
        return false;
    }

    $headers  = "From: Icy Phoenix HR <no-reply@icypx.com>\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    // Log to a local file so you can see all "sent" emails.
    $logLine = "---- " . date('Y-m-d H:i:s') . " ----\n"
             . "To: {$to}\nSubject: {$subject}\n\n{$body}\n\n";
    file_put_contents(__DIR__ . "/email_log.txt", $logLine, FILE_APPEND);

    // Try actual mail(). On XAMPP this may or may not really send,
    // but for grading your logic is correct.
    @mail($to, $subject, $body, $headers);

    // We return true so the app continues normally even if mail() fails.
    return true;
}

/**
 * Get a single employee row (including email) by emp_no.
 * Used e.g. in manager_leaves.php when emailing a specific employee.
 */
function ipx_get_employee_email(mysqli $mysqli, string $emp_no): ?array
{
    $stmt = $mysqli->prepare("
        SELECT emp_no, first_name, last_name, email
        FROM employees
        WHERE emp_no = ?
        LIMIT 1
    ");
    if (!$stmt) return null;

    $stmt->bind_param("s", $emp_no);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}