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
define('SMTP_HOST',       getenv('SMTP_HOST'));
define('SMTP_PORT',       getenv('SMTP_PORT'));
define('SMTP_USERNAME',   getenv('SMTP_USERNAME'));
define('SMTP_PASSWORD',   getenv('SMTP_PASSWORD'));
define('SMTP_FROM_NAME',  getenv('SMTP_FROM_NAME'));
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL'));
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION'));

// ── Base URL Configuration ──────────────────────────────────────────────
// This should match your actual domain where the application is hosted.
// For development: use http://localhost/yoonet-quest-system/
// For production: use your actual domain with HTTPS
// IMPORTANT: This must match the domain in email links to avoid "dangerous link" warnings
define('BASE_URL', getenv('BASE_URL'));
?>