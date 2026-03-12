<?php
if (!defined('SMTP_ENABLED'))    define('SMTP_ENABLED',    true);
if (!defined('SMTP_HOST'))       define('SMTP_HOST',       getenv('SMTP_HOST')       ?: 'smtp.gmail.com');
if (!defined('SMTP_PORT'))       define('SMTP_PORT',       (int)(getenv('SMTP_PORT') ?: 587));
if (!defined('SMTP_ENCRYPTION')) define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls');
if (!defined('SMTP_USERNAME'))   define('SMTP_USERNAME',   getenv('SMTP_USERNAME')   ?: 'christianpisalbon24@gmail.com');
if (!defined('SMTP_PASSWORD'))   define('SMTP_PASSWORD',   getenv('SMTP_PASSWORD')   ?: 'vhvuytjnnzpqcdas');
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'christianpisalbon24@gmail.com');
if (!defined('SMTP_FROM_NAME'))  define('SMTP_FROM_NAME',  getenv('SMTP_FROM_NAME')  ?: 'StayWise');
if (!defined('SMTP_DEBUG'))      define('SMTP_DEBUG', 0);
