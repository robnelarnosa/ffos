<?php
// config.php

if(!isset($_SESSION))
	session_start();

$dsn = 'mysql:host=127.0.0.1;dbname=ffos;charset=utf8mb4';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die('DB connection failed');
}

// ---- Admin credentials (simple) ----

	define('ADMIN_USER', 'admin');

	define('ADMIN_PASS', 'admin123'); // change this

// ---- CSRF Protection ----
// Generate CSRF token for session if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Function to get current CSRF token
function get_csrf_token(): string {
    return $_SESSION['csrf_token'];
}

// Function to validate CSRF token
function validate_csrf_token(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ---- WebSocket push bridge ----
// Make sure this function is defined ONLY ONCE.
if (!function_exists('send_ws_message')) {
    function send_ws_message(array $payload): void
    {
        $fp = @fsockopen('127.0.0.1', 9001, $errno, $errstr, 0.5);
        if (!$fp) {
            return;
        }
        fwrite($fp, json_encode($payload) . "\n");
        fclose($fp);
    }
}
