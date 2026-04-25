<?php
// ================================================================
// CAKETOWN ERP — DATABASE CONFIGURATION
// ================================================================
// LOCAL (XAMPP): DB_HOST=localhost, DB_USER=root, DB_PASS=''
// HOSTINGER:     Replace values below with your Hostinger MySQL credentials.
//                Find them in: Hostinger cPanel → Databases → MySQL Databases
// ================================================================

date_default_timezone_set('Asia/Kolkata');

// ----- EDIT THESE FOR HOSTINGER -----
define('DB_HOST', 'localhost');           // Always 'localhost' on Hostinger shared hosting
define('DB_USER', 'your_db_username');    // e.g. u123456789_caketown
define('DB_PASS', 'your_db_password');    // Your MySQL user password from Hostinger
define('DB_NAME', 'your_db_name');        // e.g. u123456789_caketown_vault
// ------------------------------------

// Set to true ONLY during local development. Set to false before Hostinger deployment.
define('DEBUG_MODE', false);

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,   false);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database connection failed.',
        'debug'   => DEBUG_MODE ? $e->getMessage() : 'Contact administrator.'
    ]);
    exit;
}
?>
