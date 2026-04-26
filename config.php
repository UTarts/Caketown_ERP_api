<?php
// config.php - Database Connection
// NOTE FOR HOSTINGER DEPLOYMENT:
// Change DB_USER, DB_PASS, DB_NAME to your Hostinger MySQL credentials.
// Remove the 'debug' line from the catch block before going live.

date_default_timezone_set('Asia/Kolkata');

define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // Hostinger: your DB username
define('DB_PASS', '');           // Hostinger: your DB password
define('DB_NAME', 'caketown_vault'); // Hostinger: your DB name

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE,          PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,  false);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database connection failed.',
        'debug'   => $e->getMessage() // REMOVE THIS LINE before Hostinger deployment
    ]);
    exit;
}
?>
