<?php

// config.php - Database connection & session start
define('DB_HOST', 'localhost');
define('DB_NAME', 'confmanager');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Database connection failed!");
}


// Helper function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Helper function to redirect
function redirect($url) {
    header("Location: $url");
    exit;
}

// Helper function to get redirect based on role
function getRoleRedirect($role) {
    switch ($role) {
        case 'chercheur':
            return 'submit_article.php';
        case 'gestionnaire':
            return 'conferences.php';
        case 'reviewer':
            return 'reviewer_dashboard.php';
        default:
            return 'login.php';
    }
}

// Helper function to require login
function requireLogin() {
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}

// Helper function to require specific role
function requireRole($requiredRole) {
    requireLogin();
    if ($_SESSION['user_role'] !== $requiredRole) {
        redirect(getRoleRedirect($_SESSION['user_role']));
    }
}
?>