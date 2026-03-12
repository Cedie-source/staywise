<?php
require_once __DIR__ . '/config/smtp.php';
require_once __DIR__ . '/vendor/phpmailer/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';

echo "<pre>";

// Test port 465 SSL
echo "Testing port 465 (SSL)...\n";
$mail = new PHPMailer\PHPMailer\PHPMailer(true);
try {
    $mail->SMTPDebug = 0;
    $mail->isSMTP();
    $mail->Host       = 'smtp-relay.brevo.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;
    $mail->Timeout    = 10;
    $mail->setFrom(SMTP_FROM_EMAIL, 'StayWise');
    $mail->addAddress('christianpisalbon24@gmail.com', 'Test');
    $mail->Subject = 'StayWise Test';
    $mail->Body    = 'Working!';
    $mail->send();
    echo "PORT 465: SUCCESS!\n";
} catch (Exception $e) {
    echo "PORT 465 FAILED: " . htmlspecialchars($mail->ErrorInfo) . "\n";
}

// Test port 2525
echo "\nTesting port 2525...\n";
$mail2 = new PHPMailer\PHPMailer\PHPMailer(true);
try {
    $mail2->SMTPDebug = 0;
    $mail2->isSMTP();
    $mail2->Host       = 'smtp-relay.brevo.com';
    $mail2->SMTPAuth   = true;
    $mail2->Username   = SMTP_USERNAME;
    $mail2->Password   = SMTP_PASSWORD;
    $mail2->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail2->Port       = 2525;
    $mail2->Timeout    = 10;
    $mail2->setFrom(SMTP_FROM_EMAIL, 'StayWise');
    $mail2->addAddress('christianpisalbon24@gmail.com', 'Test');
    $mail2->Subject = 'StayWise Test';
    $mail2->Body    = 'Working!';
    $mail2->send();
    echo "PORT 2525: SUCCESS!\n";
} catch (Exception $e) {
    echo "PORT 2525 FAILED: " . htmlspecialchars($mail2->ErrorInfo) . "\n";
}

echo "</pre>";
