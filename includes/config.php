<?php
session_start();

$host = 'localhost';
$db   = 'yoonet_quest';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ── SMTP Email Configuration ────────────────────────────────────────────
// Used by the server-side email sender (includes/smtp_mailer.php).
// For Gmail SMTP you MUST use a Gmail "App Password" (16-char code), NOT
// your regular Gmail password.  Steps:
//   1. Enable 2-Step Verification on your Google Account
//   2. Go to https://myaccount.google.com/apppasswords
//   3. Generate a new App Password for "Mail"
//   4. Paste the 16-character password below (spaces optional)
//
// You can also use other SMTP providers (Outlook, Yahoo, etc.) by changing
// the host/port/credentials below.
// ─────────────────────────────────────────────────────────────────────────
define('SMTP_HOST',       'smtp.gmail.com');          // SMTP server hostname
define('SMTP_PORT',       465);                       // 465 = SSL (recommended for XAMPP), 587 = STARTTLS
define('SMTP_USERNAME',   'christianbacani581@gmail.com'); // Your system email address
define('SMTP_PASSWORD',   'kwsf tzuh zxfd xrot');     // Gmail App Password (16-char code)
define('SMTP_FROM_NAME',  'YooNet Quest System');      // Display name in the From header
define('SMTP_FROM_EMAIL', 'christianbacani581@gmail.com'); // Must match SMTP_USERNAME for Gmail
define('SMTP_ENCRYPTION', 'ssl');                      // 'ssl' (port 465, best for XAMPP) or 'tls' (port 587)
?>