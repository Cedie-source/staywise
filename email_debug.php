<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/smtp.php';
require_once __DIR__ . '/vendor/phpmailer/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';

echo "<pre>";

$mail = new PHPMailer\PHPMailer\PHPMailer(true);
try {
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = function($str, $level) { echo htmlspecialchars($str) . "\n"; };
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->Timeout    = 15;
    $mail->setFrom(SMTP_FROM_EMAIL, 'StayWise');
    $mail->addAddress(SMTP_FROM_EMAIL, 'Test');
    $mail->Subject = 'Test from Railway';
    $mail->Body    = 'If you get this, SMTP works!';
    $mail->send();
    echo "\n\nSUCCESS - Email sent!";
} catch (Exception $e) {
    echo "\n\nFAILED: " . htmlspecialchars($mail->ErrorInfo);
}
echo "</pre>";
