<?php
// notify_helpers.php
//
// Central email sender for Icy Phoenix using PHPMailer + Mailtrap

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer from Composer
require_once __DIR__ . "/vendor/autoload.php";

/**
 * Send an email using Mailtrap sandbox.
 * Returns true on success, false on failure.
 */
function ipx_send_email(string $to, string $subject, string $body): bool
{
    $mail = new PHPMailer(true);

    try {
        // -------------------------------------------------------------
        // SMTP CONFIG (MAILTRAP)
        // -------------------------------------------------------------
        $mail->isSMTP();
        $mail->Host       = 'sandbox.smtp.mailtrap.io';
        $mail->SMTPAuth   = true;
        $mail->Port       = 2525;
        $mail->Username   = 'd2b95c6beb5c4b';   // Your Mailtrap username
        $mail->Password   = 'd6d0a5701908cc';   // Your Mailtrap password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // REQUIRED

        // -------------------------------------------------------------
        // SENDER (IMPORTANT — MUST EXIST OR MAILTRAP WILL REJECT)
        // -------------------------------------------------------------
        $mail->setFrom('no-reply@icyphoenix.test', 'Icy Phoenix HR Bot');

        // -------------------------------------------------------------
        // RECIPIENT
        // -------------------------------------------------------------
        $mail->addAddress($to);

        // -------------------------------------------------------------
        // MESSAGE CONTENT
        // -------------------------------------------------------------
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        // Send email
        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("📧 Email failed: " . $mail->ErrorInfo);
        return false;
    }
}