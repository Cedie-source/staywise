<?php
require_once __DIR__ . '/config/smtp.php';
require_once __DIR__ . '/vendor/phpmailer/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';

echo "<pre>";
echo "SMTP_HOST: " . SMTP_HOST . "\n";
echo "SMTP_PORT: " . SMTP_PORT . "\n";
echo "SMTP_USERNAME: " . SMTP_USERNAME . "\n";
echo "SMTP_PASSWORD length: " . strlen(SMTP_PASSWORD) . "\n\n";

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
    $mail->Port       = SMTP_PORT;
    $mail->Timeout    = 15;
    $mail->setFrom(SMTP_FROM_EMAIL, 'StayWise');
    $mail->addAddress('christianpisalbon24@gmail.com', 'Test');
    $mail->Subject = 'StayWise SMTP Test';
    $mail->Body    = 'SMTP is working!';
    $mail->send();
    echo "\nSUCCESS!";
} catch (Exception $e) {
    echo "\nFAILED: " . htmlspecialchars($mail->ErrorInfo);
}
echo "</pre>";
