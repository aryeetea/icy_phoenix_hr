<?php
// notification_helper.php
// Full notification system: in-app + email (Mailtrap)

require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/vendor/autoload.php"; // PHPMailer

/* ---------------------------------------------------------
   1. BASIC NOTIFICATION (your old system, kept!)
--------------------------------------------------------- */
function ipx_add_notification(mysqli $mysqli, string $emp_no, string $title, string $body): void {
    $stmt = $mysqli->prepare("
        INSERT INTO notifications (emp_no, title, body, is_read, created_at)
        VALUES (?, ?, ?, 0, NOW())
    ");
    if ($stmt) {
        $stmt->bind_param("sss", $emp_no, $title, $body);
        $stmt->execute();
        $stmt->close();
    }
}

/* ---------------------------------------------------------
   2. Unread count
--------------------------------------------------------- */
function ipx_get_unread_count(mysqli $mysqli, string $emp_no): int {
    $stmt = $mysqli->prepare("
        SELECT COUNT(*) AS c
        FROM notifications
        WHERE emp_no = ? AND is_read = 0
    ");
    if (!$stmt) return 0;

    $stmt->bind_param("s", $emp_no);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row["c"] ?? 0);
}

/* ---------------------------------------------------------
   3. INTERNAL NOTIFICATION (used by leave, schedule, etc.)
--------------------------------------------------------- */
function ipx_create_notification(mysqli $mysqli, string $emp_no, string $title, string $message): bool {
    $stmt = $mysqli->prepare("
        INSERT INTO notifications (emp_no, title, body, is_read, created_at)
        VALUES (?, ?, ?, 0, NOW())
    ");
    if (!$stmt) return false;

    $stmt->bind_param("sss", $emp_no, $title, $message);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/* ---------------------------------------------------------
   4. Get employee email + name
--------------------------------------------------------- */
function ipx_get_employee_email(mysqli $mysqli, string $emp_no): ?array {
    $stmt = $mysqli->prepare("
        SELECT email, first_name, last_name
        FROM employees
        WHERE emp_no = ?
        LIMIT 1
    ");
    if (!$stmt) return null;

    $stmt->bind_param("s", $emp_no);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $data ?: null;
}

/* ---------------------------------------------------------
   5. Send EMAIL via Mailtrap (PHPMailer)
--------------------------------------------------------- */
function ipx_send_notification(string $to, string $subject, string $message): bool {
    $mail = new PHPMailer\PHPMailer\PHPMailer();

    try {
        // Mailtrap credentials
        $mail->isSMTP();
        $mail->Host       = 'sandbox.smtp.mailtrap.io';
        $mail->SMTPAuth   = true;
        $mail->Port       = 2525;
        $mail->Username   = 'd2b95c6beb5c4b';  // your mailtrap username
        $mail->Password   = 'd6d0a5701908cc'; // your mailtrap password

        // Sender format (HR)
        $mail->setFrom("hr@icyphoenix.com", "Icy Phoenix HR");

        // Receiver
        $mail->addAddress($to);

        // Content
        $mail->Subject = $subject;
        $mail->Body    = $message;

        return $mail->send();

    } catch (Exception $e) {
        return false;
    }
}
?>