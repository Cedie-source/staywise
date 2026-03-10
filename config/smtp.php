<?php
/**
 * SMTP Email Configuration for StayWise
 * 
 * This uses PHPMailer with Gmail SMTP (free).
 * 
 * SETUP INSTRUCTIONS:
 * 1. Go to https://myaccount.google.com/security
 * 2. Enable 2-Step Verification on your Google account
 * 3. Go to https://myaccount.google.com/apppasswords
 * 4. Generate an App Password (select "Mail" and "Windows Computer")
 * 5. Copy the 16-character password and paste it below
 * 6. Set your Gmail address below
 * 
 * That's it! Emails will be sent from your Gmail for free.
 */

// Set to true once you've configured the settings below
define('SMTP_ENABLED', true);

// Gmail SMTP settings (free)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_ENCRYPTION', 'tls');    // 'tls' for port 587, 'ssl' for port 465

// Your Gmail credentials
define('SMTP_USERNAME', 'christianpisalbon24@gmail.com');
define('SMTP_PASSWORD', 'vhvu ytjn nzpq cdas');

// Sender info (shown in the email "From" field)
define('SMTP_FROM_EMAIL', 'christianpisalbon24@gmail.com');
define('SMTP_FROM_NAME', 'StayWise');

// Debug level: 0 = off, 1 = client messages, 2 = client+server messages
define('SMTP_DEBUG', 0);
