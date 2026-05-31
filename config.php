<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// config.php - Database connection & session start
define('DB_HOST', 'localhost');
define('DB_NAME', 'confmanager');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');


// Configuration application
define('APP_NAME', 'ConfManager');
define('APP_URL', 'http://localhost/confmanager');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('ARTICLES_DIR', __DIR__ . '/articles/');


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

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// ─── Helper: Get current user info (handles missing session vars) ───
function getCurrentUser($pdo) {
    $userId = getCurrentUserId();
    if (!$userId) return null;
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

// ─── Helper: Format date in French ───
function formatDateFr($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 
               'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    return $date->format('j') . ' ' . $months[(int)$date->format('n') - 1] . ' ' . $date->format('Y');
}

// ─── Helper: Generate article reference ───
function generateRef($articleId) {
    return 'ART-' . date('Y') . '-' . str_pad($articleId, 3, '0', STR_PAD_LEFT);
}

// ─── Helper: Get status label in French ───
function getStatusLabel($status) {
    $labels = [
        'new'      => 'Soumis',
        'assigned' => 'Assigné',
        'review'   => 'En évaluation',
        'accepted' => 'Accepté',
        'rejected' => 'Rejeté',
        'revision' => 'Révision demandée'
    ];
    return $labels[$status] ?? $status;
}

// ─── Helper: Redirect if not logged in ───
function requireAuth() {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

// ─── Helper: JSON response ───
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// ─── Helper: Upload file ───
function uploadFile($file, $allowedTypes = ['application/pdf'], $maxSize = 15 * 1024 * 1024, $uploadDir = 'uploads/') {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed'];
    }
    
    if (!in_array($file['type'], $allowedTypes)) {
        return ['success' => false, 'error' => 'Invalid file type. Only PDF allowed.'];
    }
    
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'File too large. Max 15MB.'];
    }
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('article_') . '_' . time() . '.' . $ext;
    $filepath = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'path' => $filepath, 'filename' => $filename];
    }
    
    return ['success' => false, 'error' => 'Failed to save file'];
}

function hasRole($role) {
    if (!isLoggedIn()) return false;
    global $pdo;
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user && $user['role'] === $role;
}

/**
 * Messages flash (succès/erreur)
 */
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Génère un token CSRF
 */
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifie un token CSRF
 */
function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Nettoie une entrée utilisateur
 */
function cleanInput($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Formate une date
 */
function formatDate($date, $format = 'd/m/Y') {
    if (!$date) return 'N/A';
    $d = new DateTime($date);
    return $d->format($format);
}

/**
 * Formate une date avec heure
 */
function formatDateTime($date) {
    return formatDate($date, 'd/m/Y à H:i');
}

/**
 * Tronque un texte
 */
function truncate($text, $length = 100) {
    if (strlen($text) <= $length) return $text;
    return substr($text, 0, $length) . '...';
}

/**
 * Upload un fichier
 */
function handleFileUpload($file) {
    $result = uploadFile($file);
    if (!$result['success']) {
        setFlash('error', $result['error']);
        return null;
    }
    return $result['path'];
}
/**
 * Envoie une notification
 */
function sendNotification($userId, $type, $message, $articleId = null) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, article_id, created_at) VALUES (?, ?, ?, ?, NOW())");
    return $stmt->execute([$userId, $type, $message, $articleId]);
}

/**
 * Récupère les notifications non lues
 */
function getUnreadNotifications($userId, $limit = 10) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

/**
 * Marque une notification comme lue
 */
function markNotificationRead($notificationId, $userId) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    return $stmt->execute([$notificationId, $userId]);
}

/**
 * Compte les notifications non lues
 */
function countUnreadNotifications($userId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

/**
 * Récupère un utilisateur par ID
 */
function getUserById($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Récupère un article par ID
 */
function getArticleById($id) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT a.*, c.name_fr as conference_name, c.name_en, t.name as topic_name,
               u.first_name as author_first_name, u.last_name as author_last_name
        FROM articles a
        LEFT JOIN conferences c ON a.conference_id = c.id
        LEFT JOIN topics t ON a.topic_id = t.id
        LEFT JOIN users u ON a.author = CONCAT(u.first_name, '.', u.last_name)
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Récupère les évaluateurs assignés à un article
 */
function getArticleReviewers($articleId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, u.institution, u.specialties,
               ar.assigned_at, ar.completed_at
        FROM article_reviewers ar
        JOIN users u ON ar.evaluator_id = u.id
        WHERE ar.article_id = ?
    ");
    $stmt->execute([$articleId]);
    return $stmt->fetchAll();
}

/**
 * Récupère les articles assignés à un évaluateur
 */
function getEvaluatorArticles($evaluatorId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT a.*, c.name_fr as conference_name, t.name as topic_name,
               ar.assigned_at, ar.completed_at
        FROM article_reviewers ar
        JOIN articles a ON ar.article_id = a.id
        LEFT JOIN conferences c ON a.conference_id = c.id
        LEFT JOIN topics t ON a.topic_id = t.id
        WHERE ar.evaluator_id = ?
        ORDER BY ar.assigned_at DESC
    ");
    $stmt->execute([$evaluatorId]);
    return $stmt->fetchAll();
}

/**
 * Vérifie si un évaluateur peut être assigné à un article
 */
function canAssignReviewer($articleId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM article_reviewers WHERE article_id = ?");
    $stmt->execute([$articleId]);
    $count = $stmt->fetchColumn();
    return $count < 2;
}

/**
 * Récupère les conférences actives
 */
function getActiveConferences() {
    global $pdo;
    $stmt = $pdo->query("
        SELECT * FROM conferences 
        WHERE end_date >= CURDATE() 
        ORDER BY start_date DESC
    ");
    return $stmt->fetchAll();
}

/**
 * Récupère tous les topics
 */
function getAllTopics() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM topics ORDER BY name");
    return $stmt->fetchAll();
}

/**
 * Récupère les topics d'une conférence
 */
function getConferenceTopics($conferenceId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT t.* FROM topics t
        JOIN conference_topics ct ON t.id = ct.topic_id
        WHERE ct.conference_id = ?
        ORDER BY t.name
    ");
    $stmt->execute([$conferenceId]);
    return $stmt->fetchAll();
}

/**
 * Génère un code reviewer unique
 */
function generateReviewerCode() {
    return 'REV' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
}

/**
 * Journal des actions (audit log)
 */
function logAction($userId, $action, $details = '') {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
}

// Auto-include CSRF token dans les formulaires
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCsrfToken() . '">';
}
function getUserIdByEmail($email) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    return $user ? (int)$user['id'] : 0;
}

// ─── Additional Helper: Get reviewer statistics ───
function getReviewerStats($reviewerId) {
    global $pdo;
    $stats = ['total' => 0, 'pending' => 0, 'completed' => 0, 'accepted' => 0, 'rejected' => 0];

    $stmt = $pdo->prepare("
        SELECT a.status, r.recommendation
        FROM article_reviewers ar
        JOIN articles a ON ar.article_id = a.id
        LEFT JOIN reviews r ON r.article_id = a.id AND r.evaluator_id = ?
        WHERE ar.evaluator_id = ?
    ");
    $stmt->execute([$reviewerId, $reviewerId]);

    while ($row = $stmt->fetch()) {
        $stats['total']++;
        if ($row['recommendation']) {
            $stats['completed']++;
            if ($row['recommendation'] === 'accept') $stats['accepted']++;
            elseif ($row['recommendation'] === 'reject') $stats['rejected']++;
        } else {
            $stats['pending']++;
        }
    }

    return $stats;
}

// ─── Helper: Check if user has reviewer role ───
function isReviewer() {
    return hasRole('reviewer');
}

// ─── Helper: Safe JSON encode for HTML ───
function safeJsonEncode($data) {
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}
?>