<?php
session_start();

// ==================== VERIFY ADMIN ACCESS ====================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'gestionnaire') {
    header('Location: login.php');
    exit;
}

// ==================== DATABASE CONFIGURATION ====================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'confmanager');

class Database {
    private static $connection = null;
    
    public static function getConnection() {
        if (self::$connection === null) {
            try {
                self::$connection = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                    DB_USER,
                    DB_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
            } catch (PDOException $e) {
                die("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$connection;
    }
}

$db = Database::getConnection();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// ==================== GET USER DATA ====================
$query = "SELECT * FROM users WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: logout.php');
    exit;
}

// Generate initials for avatar
$initials = strtoupper(substr($user['first_name'] ?? '', 0, 1) . substr($user['last_name'] ?? '', 0, 1));

// ==================== GET STATISTICS FOR ADMIN ====================
// Total conferences
$totalConferences = $db->query("SELECT COUNT(*) FROM conferences")->fetchColumn() ?: 0;

// Active conferences (submission deadline not passed and submission start date passed)
$activeConferences = $db->query("SELECT COUNT(*) FROM conferences WHERE submission_deadline > CURDATE() AND submission_start_date <= CURDATE()")->fetchColumn() ?: 0;

// Total articles
$totalArticles = $db->query("SELECT COUNT(*) FROM articles")->fetchColumn() ?: 0;

// Pending articles (en_attente status)
$pendingArticles = $db->query("SELECT COUNT(*) FROM articles WHERE statut = 'en_attente'")->fetchColumn() ?: 0;

// Total reviewers
$totalReviewers = $db->query("SELECT COUNT(*) FROM users WHERE role = 'reviewer'")->fetchColumn() ?: 0;

// Total researchers
$totalResearchers = $db->query("SELECT COUNT(*) FROM users WHERE role = 'chercheur'")->fetchColumn() ?: 0;

// ==================== FUNCTIONS ====================
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function uploadImage($file, $type, $userId) {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return false;
    
    $uploadDir = __DIR__ . '/uploads/' . $type . 's/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    
    $fileName = $type . '_' . $userId . '_' . time() . '.' . $ext;
    $targetPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'uploads/' . $type . 's/' . $fileName;
    }
    return false;
}

// ==================== PROCESS FORM SUBMISSIONS ====================
$message = '';
$error = '';

// Update profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $service = trim($_POST['service'] ?? '');
    $twitter = trim($_POST['twitter'] ?? '');
    $linkedin = trim($_POST['linkedin'] ?? '');
    $facebook = trim($_POST['facebook'] ?? '');
    
    $errors = [];
    
    if (empty($first_name)) $errors[] = 'Le prénom est obligatoire.';
    if (empty($last_name)) $errors[] = 'Le nom est obligatoire.';
    if (empty($email)) $errors[] = "L'email est obligatoire.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalide.';
    if (empty($username)) $errors[] = "Le nom d'utilisateur est obligatoire.";
    
    // Check email uniqueness
    $checkEmail = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
    $checkEmail->execute([':email' => $email, ':id' => $userId]);
    if ($checkEmail->fetch()) $errors[] = "Cet email est déjà utilisé.";
    
    // Check username uniqueness
    $checkUsername = $db->prepare("SELECT id FROM users WHERE username = :username AND id != :id");
    $checkUsername->execute([':username' => $username, ':id' => $userId]);
    if ($checkUsername->fetch()) $errors[] = "Ce nom d'utilisateur est déjà pris.";
    
    // Upload avatar
    $avatarPath = $user['avatar'] ?? null;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $uploaded = uploadImage($_FILES['avatar'], 'avatar', $userId);
        if ($uploaded) $avatarPath = $uploaded;
    }
    
    // Upload cover
    $coverPath = $user['cover'] ?? null;
    if (isset($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
        $uploaded = uploadImage($_FILES['cover'], 'cover', $userId);
        if ($uploaded) $coverPath = $uploaded;
    }
    
    if (empty($errors)) {
        try {
            $updateQuery = "UPDATE users SET 
                first_name = :first_name,
                last_name = :last_name,
                username = :username,
                email = :email,
                phone = :phone,
                location = :location,
                bio = :bio,
                department = :department,
                service = :service,
                twitter = :twitter,
                linkedin = :linkedin,
                facebook = :facebook,
                avatar = :avatar,
                cover = :cover
            WHERE id = :id";
            
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([
                ':first_name' => $first_name,
                ':last_name' => $last_name,
                ':username' => $username,
                ':email' => $email,
                ':phone' => $phone,
                ':location' => $location,
                ':bio' => $bio,
                ':department' => $department,
                ':service' => $service,
                ':twitter' => $twitter,
                ':linkedin' => $linkedin,
                ':facebook' => $facebook,
                ':avatar' => $avatarPath,
                ':cover' => $coverPath,
                ':id' => $userId
            ]);
            
            $_SESSION['user_nom'] = $last_name;
            $_SESSION['user_prenom'] = $first_name;
            $_SESSION['user_email'] = $email;
            $_SESSION['username'] = $username;
            
            $message = "Profil mis à jour avec succès !";
            
            // Reload user data
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();
            $initials = strtoupper(substr($user['first_name'] ?? '', 0, 1) . substr($user['last_name'] ?? '', 0, 1));
        } catch (PDOException $e) {
            $error = "Erreur base de données: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// Change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $errors[] = "Veuillez remplir tous les champs.";
    }
    if (!password_verify($currentPassword, $user['password'])) {
        $errors[] = "Mot de passe actuel incorrect.";
    }
    if (strlen($newPassword) < 6) {
        $errors[] = "Le nouveau mot de passe doit contenir au moins 6 caractères.";
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = "Les mots de passe ne correspondent pas.";
    }
    
    if (empty($errors)) {
        try {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateQuery = "UPDATE users SET password = :password WHERE id = :id";
            $updateStmt = $db->prepare($updateQuery);
            
            if ($updateStmt->execute([':password' => $hashedPassword, ':id' => $userId])) {
                $message = "Mot de passe modifié avec succès !";
            } else {
                $error = "Erreur lors de la modification du mot de passe.";
            }
        } catch (PDOException $e) {
            $error = "Erreur base de données: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// ==================== ADMIN NAVIGATION ====================
$nav_items = [
    ['url' => 'admin_dashboard.php', 'label' => 'Tableau de bord', 'icon' => 'fas fa-chart-line'],
    ['url' => 'admin_dashboard.php', 'label' => 'Conférences', 'icon' => 'fas fa-calendar-alt'],
    ['url' => 'evaluators.php', 'label' => 'Évaluateurs', 'icon' => 'fas fa-users'],
    ['url' => 'final_decisions.php', 'label' => 'Décisions', 'icon' => 'fas fa-gavel'],
    ['url' => 'admin_profile.php', 'label' => 'Profil', 'icon' => 'fas fa-user', 'active' => true],
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Profil Administrateur</title>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root {
    --navy: #0d2137;
    --navy-mid: #1a3a5c;
    --gold: #c9a84c;
    --gold-light: #e2c97e;
    --bg: #f0f2f5;
    --white: #ffffff;
    --border: #e2e8f0;
    --muted: #7a8fa6;
    --text: #1a2e44;
    --text-light: #4a607a;
    --accent: #2c6fad;
    --danger: #d94040;
    --success: #2a9d8f;
    --warning: #d4830a;
    --purple: #5b6ef5;
    --shadow-sm: 0 1px 4px rgba(13,33,55,0.07);
    --shadow: 0 4px 20px rgba(13,33,55,0.10);
    --radius: 12px;
    --radius-sm: 8px;
  }

  * { margin: 0; padding: 0; box-sizing: border-box; }

  body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
  }
  <style>
  :root {
    --navy: #0d2137;
    ...
  }

  .brand {
    display: flex;
    align-items: center;
    gap: 10px;
  }


  .logo-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .logo-icon {
    width: 36px;
    height: 36px;
    background: var(--navy);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
  }

  .logo-text {
    font-family: 'Libre Baskerville', serif;
    font-size: 18px;
    font-weight: 700;
    color: var(--navy);
  }



  /* Topbar */
  .topbar {
    background: var(--white);
    border-bottom: 1px solid var(--border);
    padding: 0 40px;
    height: 62px;
    display: flex;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: var(--shadow-sm);
  }
  .brand {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-right: 48px;
    text-decoration: none;
  }
  .brand-icon {
    width: 34px;
    height: 34px;
    background: var(--navy);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
  }
  .brand-name {
    font-size: 18px;
    font-weight: 600;
    color: var(--navy);
  }
  .nav-links {
    display: flex;
    align-items: center;
    gap: 4px;
    flex: 1;
  }
  .nav-link {
    padding: 8px 16px;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-light);
    text-decoration: none;
    border-radius: 8px;
    transition: all 0.15s;
  }
  .nav-link:hover {
    background: var(--bg);
    color: var(--navy);
  }
  .nav-link.active {
    background: var(--navy);
    color: var(--gold-light);
  }
  .logout-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 18px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text-light);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.15s;
  }
  .logout-btn:hover {
    border-color: var(--navy);
    color: var(--navy);
    background: var(--white);
  }

  /* Main */
  .main {
    max-width: 1200px;
    margin: 0 auto;
    padding: 40px 24px;
  }

  /* Messages */
  .alert {
    padding: 14px 18px;
    border-radius: var(--radius-sm);
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    animation: slideDown 0.3s ease;
  }
  .alert-success {
    background: #e8f6f3;
    border-left: 3px solid var(--success);
    color: var(--success);
  }
  .alert-error {
    background: #fef2f2;
    border-left: 3px solid var(--danger);
    color: var(--danger);
  }
  @keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* Stats Cards */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 32px;
  }
  .stat-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: var(--shadow-sm);
  }
  .stat-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
  }
  .stat-icon.conferences { background: rgba(44,111,173,0.1); color: var(--accent); }
  .stat-icon.articles { background: rgba(42,157,143,0.1); color: var(--success); }
  .stat-icon.reviewers { background: rgba(91,110,245,0.1); color: var(--purple); }
  .stat-icon.users { background: rgba(212,131,10,0.1); color: var(--warning); }
  .stat-info h3 { font-size: 24px; font-weight: 700; color: var(--navy); }
  .stat-info p { font-size: 12px; color: var(--muted); }

  /* Page Header */
  .page-header {
    margin-bottom: 32px;
  }
  .page-header h1 {
    font-family: 'Libre Baskerville', serif;
    font-size: 32px;
    font-weight: 700;
    color: var(--navy);
    margin-bottom: 6px;
  }
  .page-header h1 em {
    font-style: italic;
    color: var(--gold);
  }
  .page-header p {
    font-size: 14px;
    color: var(--muted);
  }

  /* Profile Grid */
  .profile-grid {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 24px;
  }

  /* Sidebar */
  .sidebar-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    position: sticky;
    top: 82px;
  }
  .cover-container {
    height: 100px;
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%);
    position: relative;
    cursor: pointer;
    overflow: hidden;
  }
  .cover-container img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .cover-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s;
  }
  .cover-container:hover .cover-overlay {
    opacity: 1;
  }
  .cover-btn {
    background: white;
    color: var(--navy);
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .avatar-container {
    text-align: center;
    margin-top: -40px;
    margin-bottom: 16px;
    position: relative;
  }
  .avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    border: 4px solid var(--white);
    background: var(--navy);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    font-weight: 600;
    color: white;
    margin: 0 auto;
    overflow: hidden;
    cursor: pointer;
    box-shadow: var(--shadow-sm);
  }
  .avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .avatar-edit {
    position: absolute;
    bottom: 0;
    right: 100px;
    background: var(--gold);
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--navy);
    font-size: 12px;
    border: 2px solid var(--white);
    cursor: pointer;
  }
  .profile-name {
    text-align: center;
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 4px;
  }
  .profile-role {
    text-align: center;
    font-size: 12px;
    color: var(--gold);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 16px;
  }
  .profile-email {
    text-align: center;
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
  }
  .sidebar-nav {
    border-top: 1px solid var(--border);
    padding: 8px 0;
  }
  .sidebar-nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    width: 100%;
    border: none;
    background: none;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-light);
    cursor: pointer;
    transition: all 0.15s;
    text-align: left;
  }
  .sidebar-nav-item:hover {
    background: var(--bg);
    color: var(--navy);
  }
  .sidebar-nav-item.active {
    background: #fef9ed;
    color: var(--navy);
    border-left: 3px solid var(--gold);
  }
  .sidebar-nav-item i {
    width: 20px;
  }
  .sidebar-nav-item.danger {
    color: var(--danger);
  }
  .sidebar-nav-item.danger:hover {
    background: #fdf2f2;
  }

  /* Main Card */
  .form-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
  }
  .section-panel {
    display: none;
    padding: 28px;
  }
  .section-panel.active {
    display: block;
    animation: fadeIn 0.3s ease;
  }
  @keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 24px;
  }
  .form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .field-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
  }
  .form-input, .form-textarea {
    padding: 11px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    transition: all 0.15s;
    outline: none;
  }
  .form-input:focus, .form-textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.12);
  }
  .form-textarea {
    resize: vertical;
    min-height: 100px;
  }
  .char-count {
    font-size: 11px;
    color: var(--muted);
    text-align: right;
    margin-top: 4px;
  }

  .input-with-prefix {
    display: flex;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
  }
  .input-with-prefix:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.12);
  }
  .input-prefix {
    padding: 11px 12px;
    background: var(--bg);
    color: var(--muted);
    font-size: 14px;
    border-right: 1px solid var(--border);
  }
  .input-with-prefix .form-input {
    border: none;
    flex: 1;
  }

  .social-input {
    display: flex;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
  }
  .social-input:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.12);
  }
  .social-icon {
    width: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg);
    color: var(--muted);
    border-right: 1px solid var(--border);
  }
  .social-input .form-input {
    border: none;
    flex: 1;
  }

  .security-card {
    background: var(--bg);
    border-radius: var(--radius-sm);
    padding: 20px;
  }
  .security-card-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 20px;
  }
  .security-icon {
    width: 48px;
    height: 48px;
    background: var(--white);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--navy);
    font-size: 20px;
  }
  .security-title h4 {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 4px;
  }
  .security-title p {
    font-size: 12px;
    color: var(--muted);
  }

  .form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 28px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
  }
  .btn-primary {
    background: var(--navy);
    color: var(--gold-light);
    border: none;
    border-radius: var(--radius-sm);
    padding: 10px 24px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.15s;
  }
  .btn-primary:hover {
    background: var(--navy-mid);
    transform: translateY(-1px);
    box-shadow: var(--shadow);
  }
  .btn-secondary {
    background: none;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 10px 22px;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-light);
    cursor: pointer;
    transition: all 0.15s;
  }
  .btn-secondary:hover {
    border-color: var(--navy);
    color: var(--navy);
    background: var(--bg);
  }

  .footer {
    text-align: center;
    padding: 24px;
    color: var(--muted);
    font-size: 12px;
    border-top: 1px solid var(--border);
    margin-top: 48px;
  }

  @media (max-width: 900px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .profile-grid { grid-template-columns: 1fr; }
    .sidebar-card { position: static; margin-bottom: 24px; }
    .form-grid { grid-template-columns: 1fr; }
    .topbar { padding: 0 16px; }
    .main { padding: 24px 16px; }
  }
</style>
</head>
<body>

<header class="topbar">
<a class="brand" href="#">
  <div class="logo-wrapper">
    <div class="logo-icon"><i class="fas fa-book-open"></i></div>
    <span class="logo-text">ConfManager</span>
  </div>
</a>
  <nav class="nav-links">
    <?php foreach ($nav_items as $item): ?>
      <a href="<?= e($item['url']); ?>" class="nav-link <?= isset($item['active']) && $item['active'] ? 'active' : ''; ?>">
        <i class="<?= e($item['icon']); ?>"></i> <?= e($item['label']); ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="topbar-right">
    <a href="logout.php" class="logout-btn">
      <i class="fas fa-sign-out-alt"></i> Déconnexion
    </a>
  </div>
</header>

<main class="main">

  <?php if ($message): ?>
    <div class="alert alert-success">
      <i class="fas fa-check-circle"></i>
      <span><?= e($message); ?></span>
    </div>
  <?php endif; ?>
  
  <?php if ($error): ?>
    <div class="alert alert-error">
      <i class="fas fa-exclamation-triangle"></i>
      <span><?= $error; ?></span>
    </div>
  <?php endif; ?>



  <div class="page-header">
    <h1>Profil <em>Administrateur</em></h1>
    <p>Gérez votre profil et vos informations personnelles</p>
  </div>

  <div class="profile-grid">

    <!-- Sidebar -->
    <aside class="sidebar-card">
      <div class="cover-container" onclick="document.getElementById('coverInput').click()">
        <?php if (!empty($user['cover']) && file_exists($user['cover'])): ?>
          <img src="<?= e($user['cover']); ?>" alt="Cover">
        <?php endif; ?>
        <div class="cover-overlay">
          <span class="cover-btn"><i class="fas fa-camera"></i> Changer</span>
        </div>
      </div>
      <input type="file" id="coverInput" name="cover" accept="image/*" style="display:none" form="profileForm">

      <div class="avatar-container">
        <div class="avatar" onclick="document.getElementById('avatarInput').click()">
          <?php if (!empty($user['avatar']) && file_exists($user['avatar'])): ?>
            <img src="<?= e($user['avatar']); ?>" alt="Avatar">
          <?php else: ?>
            <span><?= e($initials); ?></span>
          <?php endif; ?>
        </div>
        <div class="avatar-edit" onclick="document.getElementById('avatarInput').click()">
          <i class="fas fa-camera"></i>
        </div>
      </div>
      <input type="file" id="avatarInput" name="avatar" accept="image/*" style="display:none" form="profileForm">

      <div class="profile-name"><?= e($user['first_name'] ?? '') ?> <?= e($user['last_name'] ?? '') ?></div>
      <div class="profile-role">
        <i class="fas fa-user-shield"></i> Administrateur
      </div>
      <div class="profile-email"><i class="fas fa-envelope"></i> <?= e($user['email'] ?? '') ?></div>

      <div class="sidebar-nav">
        <button class="sidebar-nav-item active" data-section="personal">
          <i class="fas fa-user"></i> Informations personnelles
        </button>
        <button class="sidebar-nav-item" data-section="professional">
          <i class="fas fa-briefcase"></i> Informations professionnelles
        </button>
        <button class="sidebar-nav-item" data-section="social">
          <i class="fas fa-share-alt"></i> Réseaux sociaux
        </button>
        <button class="sidebar-nav-item" data-section="security">
          <i class="fas fa-lock"></i> Sécurité
        </button>
        <a href="logout.php" class="sidebar-nav-item danger">
          <i class="fas fa-sign-out-alt"></i> Déconnexion
        </a>
      </div>
    </aside>

    <!-- Main Form -->
    <div class="form-card">
      <form method="POST" enctype="multipart/form-data" id="profileForm">

        <!-- Personal Section -->
        <div class="section-panel active" id="personal-section">
          <div class="form-grid">
            <div class="form-group">
              <label class="field-label">Prénom</label>
              <input class="form-input" type="text" name="first_name" 
                value="<?= e($user['first_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
              <label class="field-label">Nom</label>
              <input class="form-input" type="text" name="last_name" 
                value="<?= e($user['last_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
              <label class="field-label">Nom d'utilisateur</label>
              <div class="input-with-prefix">
                <span class="input-prefix">@</span>
                <input class="form-input" type="text" name="username" 
                  value="<?= e($user['username'] ?? '') ?>" required>
              </div>
            </div>
            <div class="form-group">
              <label class="field-label">Email</label>
              <input class="form-input" type="email" name="email" 
                value="<?= e($user['email'] ?? '') ?>" required>
            </div>
            <div class="form-group">
              <label class="field-label">Téléphone</label>
              <input class="form-input" type="tel" name="phone" 
                value="<?= e($user['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="field-label">Localisation</label>
              <input class="form-input" type="text" name="location" 
                value="<?= e($user['location'] ?? '') ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="field-label">Bio / Présentation</label>
            <textarea class="form-textarea" name="bio" rows="4" 
              oninput="updateCharCount()"><?= e($user['bio'] ?? '') ?></textarea>
            <div class="char-count">
              <span id="charCount"><?= strlen($user['bio'] ?? '') ?></span> / 500 caractères
            </div>
          </div>
          <div class="form-actions">
            <button type="submit" name="update_profile" class="btn-primary">
              <i class="fas fa-save"></i> Enregistrer
            </button>
          </div>
        </div>

        <!-- Professional Section (Admin specific) -->
        <div class="section-panel" id="professional-section">
          <div class="form-grid">
            <div class="form-group">
              <label class="field-label">Département / Service</label>
              <input class="form-input" type="text" name="department" 
                value="<?= e($user['department'] ?? '') ?>" placeholder="ex: Département Informatique">
            </div>
            <div class="form-group">
              <label class="field-label">Fonction / Service</label>
              <input class="form-input" type="text" name="service" 
                value="<?= e($user['service'] ?? '') ?>" placeholder="ex: Direction de la Recherche">
            </div>
          </div>
          <div class="notice-box" style="background: var(--bg); padding: 12px 16px; border-radius: var(--radius-sm); margin-top: 16px;">
            <i class="fas fa-info-circle" style="color: var(--accent);"></i>
            <span style="font-size: 12px; color: var(--muted);">Ces informations aident les utilisateurs à vous identifier en tant qu'administrateur.</span>
          </div>
          <div class="form-actions">
            <button type="submit" name="update_profile" class="btn-primary">
              <i class="fas fa-save"></i> Enregistrer
            </button>
          </div>
        </div>

        <!-- Social Section -->
        <div class="section-panel" id="social-section">
          <div class="form-group">
            <label class="field-label">Twitter</label>
            <div class="social-input">
              <span class="social-icon"><i class="fab fa-twitter"></i></span>
              <input class="form-input" type="text" name="twitter" 
                value="<?= e($user['twitter'] ?? '') ?>" placeholder="@username">
            </div>
          </div>
          <div class="form-group">
            <label class="field-label">LinkedIn</label>
            <div class="social-input">
              <span class="social-icon"><i class="fab fa-linkedin-in"></i></span>
              <input class="form-input" type="text" name="linkedin" 
                value="<?= e($user['linkedin'] ?? '') ?>" placeholder="linkedin.com/in/username">
            </div>
          </div>
          <div class="form-group">
            <label class="field-label">Facebook</label>
            <div class="social-input">
              <span class="social-icon"><i class="fab fa-facebook-f"></i></span>
              <input class="form-input" type="text" name="facebook" 
                value="<?= e($user['facebook'] ?? '') ?>" placeholder="facebook.com/username">
            </div>
          </div>
          <div class="form-actions">
            <button type="submit" name="update_profile" class="btn-primary">
              <i class="fas fa-save"></i> Enregistrer
            </button>
          </div>
        </div>

        <!-- Security Section -->
        <div class="section-panel" id="security-section">
          <div class="security-card">
            <div class="security-card-header">
              <div class="security-icon"><i class="fas fa-key"></i></div>
              <div class="security-title">
                <h4>Changer le mot de passe</h4>
                <p>Mettez à jour votre mot de passe régulièrement</p>
              </div>
            </div>
            <div class="form-group">
              <label class="field-label">Mot de passe actuel</label>
              <input class="form-input" type="password" name="current_password" placeholder="••••••••">
            </div>
            <div class="form-grid">
              <div class="form-group">
                <label class="field-label">Nouveau mot de passe</label>
                <input class="form-input" type="password" name="new_password" placeholder="••••••••">
              </div>
              <div class="form-group">
                <label class="field-label">Confirmer</label>
                <input class="form-input" type="password" name="confirm_password" placeholder="••••••••">
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" name="change_password" class="btn-primary">
                <i class="fas fa-lock"></i> Changer le mot de passe
              </button>
            </div>
          </div>
        </div>

      </form>
    </div>
  </div>
</main>

<footer class="footer">
  © <?= date('Y') ?> ConfManager · Université Hassiba Benbouali de Chlef
</footer>

<script>
  // Section navigation
  document.querySelectorAll('.sidebar-nav-item[data-section]').forEach(item => {
    item.addEventListener('click', function() {
      document.querySelectorAll('.sidebar-nav-item').forEach(n => n.classList.remove('active'));
      this.classList.add('active');
      const section = this.dataset.section;
      document.querySelectorAll('.section-panel').forEach(p => p.classList.remove('active'));
      document.getElementById(section + '-section').classList.add('active');
    });
  });

  // Avatar preview
  document.getElementById('avatarInput').addEventListener('change', function(e) {
    if (this.files && this.files[0]) {
      const reader = new FileReader();
      reader.onload = function(ev) {
        const avatar = document.querySelector('.avatar');
        avatar.innerHTML = `<img src="${ev.target.result}" style="width:100%;height:100%;object-fit:cover">`;
      };
      reader.readAsDataURL(this.files[0]);
    }
  });

  // Cover preview
  document.getElementById('coverInput').addEventListener('change', function(e) {
    if (this.files && this.files[0]) {
      const reader = new FileReader();
      reader.onload = function(ev) {
        const cover = document.querySelector('.cover-container');
        const img = cover.querySelector('img');
        if (img) img.style.display = 'none';
        cover.style.backgroundImage = `url('${ev.target.result}')`;
        cover.style.backgroundSize = 'cover';
        cover.style.backgroundPosition = 'center';
      };
      reader.readAsDataURL(this.files[0]);
    }
  });

  // Char counter
  function updateCharCount() {
    const bio = document.querySelector('textarea[name="bio"]');
    if (bio) {
      const count = bio.value.length;
      document.getElementById('charCount').textContent = count;
    }
  }

  // Password validation
  document.getElementById('profileForm').addEventListener('submit', function(e) {
    const newPwd = document.querySelector('input[name="new_password"]');
    const confirmPwd = document.querySelector('input[name="confirm_password"]');
    
    if (newPwd && confirmPwd && (newPwd.value || confirmPwd.value)) {
      if (newPwd.value !== confirmPwd.value) {
        e.preventDefault();
        alert('Les mots de passe ne correspondent pas !');
      } else if (newPwd.value.length > 0 && newPwd.value.length < 6) {
        e.preventDefault();
        alert('Le mot de passe doit contenir au moins 6 caractères.');
      }
    }
  });

  // Auto-hide messages
  setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
      alert.style.transition = 'opacity 0.5s';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 500);
    });
  }, 5000);
</script>
</body>
</html>