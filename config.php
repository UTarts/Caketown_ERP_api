<?php
date_default_timezone_set('Asia/Kolkata');

define('DB_HOST', 'localhost');
define('DB_USER', 'root'); // Default XAMPP user
define('DB_PASS', '');     // Default XAMPP password is empty
define('DB_NAME', 'caketown_vault');

try {
    // Create a strict, secure PDO connection
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    
    // Set PDO to throw exceptions on errors so we catch them instantly
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Return data as associative arrays by default
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Disable emulated prepares for maximum security against SQL injection
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    
} catch (PDOException $e) {
    // In production, we log this. For now, we output a clean JSON error.
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed. Check XAMPP.',
        'debug' => $e->getMessage() // Remove this line before final Hostinger deployment
    ]);
    exit;
}
?>