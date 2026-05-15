<?php
/**
 * Shared PHPMailer SMTP — single place for mail credentials (same pattern as send_loan_email.php).
 * Do not read SMTP from .env; change values here if the server changes.
 */
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * @return PHPMailer Configured for SMTP; caller sets recipients, subject, body.
 */
function xander_create_phpmailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'xanderglobalscholars.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'admissions@xanderglobalscholars.com';
    $mail->Password = 'Xander2026$';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 30;
    $mail->SMTPDebug = SMTP::DEBUG_OFF;

    $mail->setFrom('admissions@xanderglobalscholars.com', 'Xander Global Scholars');

    return $mail;
}

/**
 * SMTP identity used for outbound mail to applicants (matches send-job-Email / legacy scripts).
 */
function xander_create_phpmailer_applicant_sender(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'xanderglobalscholars.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'admission@xanderglobalscholars.com';
    $mail->Password = 'Xander@2026';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 30;
    $mail->SMTPDebug = SMTP::DEBUG_OFF;
    $mail->setFrom('admission@xanderglobalscholars.com', 'Xander Global Scholars');

    return $mail;
}
