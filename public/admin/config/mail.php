<?php
/**
 * SMTP settings for password reset emails.
 *
 * Best practice: set these as environment variables on the server.
 * If you can't, replace the fallback values below — and NEVER commit
 * this file with a real password to Git or share it.
 */
return [
'host'       => getenv('KIFALA_SMTP_HOST')   ?: 'mail.kifalatrust.org',
'port'       => (int)(getenv('KIFALA_SMTP_PORT') ?: 465),
'encryption' => getenv('KIFALA_SMTP_SECURE') ?: 'ssl',
'username'   => getenv('KIFALA_SMTP_USER')   ?: 'info@kifalatrust.org',
'password'   => getenv('KIFALA_SMTP_PASS')   ?: 'Madina123++',
'from_email' => getenv('KIFALA_SMTP_FROM')   ?: 'info@kifalatrust.org',
'from_name'  => 'Kifala Trust',
];
