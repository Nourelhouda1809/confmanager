<?php
// ============================================
// logout.php - Déconnexion utilisateur
// ConfManager - Université Hassiba Benbouali de Chlef
// ============================================

// Start session
session_start();

// ============================================
// 1. CAPTURE USER INFO BEFORE DESTROYING EVERYTHING
// ============================================
$userId = $_SESSION['user_id'] ?? null;

// ============================================
// 2. INVALIDATE REMEMBER ME TOKEN IN DATABASE
// ============================================
if ($userId) {
    try {
        require_once 'config/database.php';
        $db = Database::getConnection();
        
        // Remove remember token from database
        $stmt = $db->prepare("UPDATE users SET remember_token = NULL WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        
    } catch (Exception $e) {
        error_log("Logout database error: " . $e->getMessage());
    }
}

// ============================================
// 3. CLEAR ALL SESSION VARIABLES
// ============================================
$_SESSION = array();

// ============================================
// 4. DESTROY SESSION COOKIE COMPLETELY
// ============================================
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Multiple fallbacks to ensure cookie is deleted
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
    setcookie(session_name(), '', time() - 3600, '/', '', false, true);
}

// ============================================
// 5. DESTROY REMEMBER ME COOKIE COMPLETELY
// ============================================
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

// ============================================
// 6. DESTROY THE SESSION
// ============================================
session_destroy();

// ============================================
// 7. REGENERATE SESSION ID TO PREVENT REUSE
// ============================================
// Note: session_destroy() doesn't remove session file immediately
// Force removal of session file
if (session_id()) {
    $session_file = session_save_path() . '/sess_' . session_id();
    if (file_exists($session_file)) {
        @unlink($session_file);
    }
}

// ============================================
// 8. PREVENT BROWSER CACHING
// ============================================
header("Cache-Control: no-cache, no-store, must-revalidate, private");
header("Pragma: no-cache");
header("Expires: 0");

// ============================================
// 9. CLEAR ANY POSSIBLE SESSION VARIABLES FROM SUPER GLOBALS
// ============================================
unset($_SESSION);
unset($userId);

// ============================================
// 10. REDIRECT TO LOGIN PAGE
// ============================================
header('Location: login.php?logout=success');
exit();
?>