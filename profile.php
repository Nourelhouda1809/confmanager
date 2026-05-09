<?php
session_start();

// ============================================
// 1. VÉRIFICATION DE L'AUTHENTIFICATION
// ============================================

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ============================================
// 2. CONNEXION À LA BASE DE DONNÉES
// ============================================

$host = 'localhost';
$dbname = 'confmanager';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// ============================================
// 3. RÉCUPÉRATION DES DONNÉES UTILISATEUR
// ============================================

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'chercheur';

$query = "SELECT * FROM users WHERE id = :id";
$stmt = $pdo->prepare($query);
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: logout.php');
    exit;
}

// Génération des initiales pour l'avatar
$initials = strtoupper(substr($user['first_name'] ?? '', 0, 1) . substr($user['last_name'] ?? '', 0, 1));

// ============================================
// 4. FONCTIONS UTILITAIRES
// ============================================

function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function uploadImage($file, $type, $userId, $pdo) {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return false;
    
    // Create uploads directory if not exists
    $uploadDir = __DIR__ . '/uploads/' . $type . 's/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $fileName = $type . '_' . $userId . '_' . time() . '.' . $ext;
    $targetPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'uploads/' . $type . 's/' . $fileName;
    }
    return false;
}

// ============================================
// 5. TRAITEMENT DU FORMULAIRE
// ============================================

$message = '';
$error = '';

// Mise à jour du profil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    
    // Réseaux sociaux
    $twitter = trim($_POST['twitter'] ?? '');
    $linkedin = trim($_POST['linkedin'] ?? '');
    $facebook = trim($_POST['facebook'] ?? '');
    $researchgate = trim($_POST['researchgate'] ?? '');
    
    $errors = [];
    
    // Validations
    if (empty($first_name)) $errors[] = 'Le prénom est obligatoire.';
    if (empty($last_name)) $errors[] = 'Le nom est obligatoire.';
    if (empty($email)) $errors[] = "L'email est obligatoire.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalide.';
    if (empty($username)) $errors[] = "Le nom d'utilisateur est obligatoire.";
    
    // Vérification unicité de l'email
    $checkEmailQuery = "SELECT id FROM users WHERE email = :email AND id != :id";
    $checkEmailStmt = $pdo->prepare($checkEmailQuery);
    $checkEmailStmt->execute([':email' => $email, ':id' => $userId]);
    if ($checkEmailStmt->fetch()) {
        $errors[] = "Cet email est déjà utilisé.";
    }
    
    // Vérification unicité du username
    $checkUsernameQuery = "SELECT id FROM users WHERE username = :username AND id != :id";
    $checkUsernameStmt = $pdo->prepare($checkUsernameQuery);
    $checkUsernameStmt->execute([':username' => $username, ':id' => $userId]);
    if ($checkUsernameStmt->fetch()) {
        $errors[] = "Ce nom d'utilisateur est déjà pris.";
    }
    
    // Upload de l'avatar
    $avatarPath = $user['avatar'] ?? null;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $uploaded = uploadImage($_FILES['avatar'], 'avatar', $userId, $pdo);
        if ($uploaded) $avatarPath = $uploaded;
    }
    
    // Upload de la couverture
    $coverPath = $user['cover'] ?? null;
    if (isset($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
        $uploaded = uploadImage($_FILES['cover'], 'cover', $userId, $pdo);
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
                twitter = :twitter,
                linkedin = :linkedin,
                facebook = :facebook,
                researchgate = :researchgate,
                avatar = :avatar,
                cover = :cover
            WHERE id = :id";
            
            $updateStmt = $pdo->prepare($updateQuery);
            $result = $updateStmt->execute([
                ':first_name' => $first_name,
                ':last_name' => $last_name,
                ':username' => $username,
                ':email' => $email,
                ':phone' => $phone,
                ':location' => $location,
                ':bio' => $bio,
                ':twitter' => $twitter,
                ':linkedin' => $linkedin,
                ':facebook' => $facebook,
                ':researchgate' => $researchgate,
                ':avatar' => $avatarPath,
                ':cover' => $coverPath,
                ':id' => $userId
            ]);
            
            if ($result) {
                // Mise à jour de la session
                $_SESSION['user_nom'] = $last_name;
                $_SESSION['user_prenom'] = $first_name;
                $_SESSION['user_email'] = $email;
                $_SESSION['username'] = $username;
                
                $message = "Profil mis à jour avec succès !";
                
                // Rechargement des données
                $stmt->execute([':id' => $userId]);
                $user = $stmt->fetch();
                $initials = strtoupper(substr($user['first_name'] ?? '', 0, 1) . substr($user['last_name'] ?? '', 0, 1));
            } else {
                $error = "Erreur lors de la mise à jour du profil.";
            }
        } catch (PDOException $e) {
            $error = "Erreur base de données: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// Changement de mot de passe
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
            $updateStmt = $pdo->prepare($updateQuery);
            
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

// ============================================
// 6. NAVIGATION PAR RÔLE
// ============================================

$nav_items = [
    'gestionnaire' => [
        ['url' => 'admin_dashboard.php', 'label' => 'Tableau de bord', 'icon' => 'fa-chart-line'],
     
        
        ['url' => 'evaluators.php', 'label' => 'Évaluateurs', 'icon' => 'fa-users'],
        ['url' => 'final_decisions.php', 'label' => 'Décisions', 'icon' => 'fa-gavel'],
        ['url' => 'profile.php', 'label' => 'Profil', 'icon' => 'fa-user', 'active' => true],
    ],
    'chercheur' => [
        ['url' => 'submit_article.php', 'label' => 'Soumettre des articles', 'icon' => 'fa-file-upload'],
        ['url' => 'mes_articles.php', 'label' => 'Mes articles', 'icon' => 'fa-folder'],
        
        ['url' => 'profile.php', 'label' => 'Profil', 'icon' => 'fa-user', 'active' => true],
    ],
    'reviewer' => [
        ['url' => 'review_articles.php', 'label' => 'Articles assignés', 'icon' => 'fa-glasses'],
        ['url' => 'reviewed_articles.php', 'label' => 'Articles évalués', 'icon' => 'fa-check-circle'],
        ['url' => 'profile.php', 'label' => 'Profil', 'icon' => 'fa-user', 'active' => true],
    ]
];

$current_nav = $nav_items[$userRole] ?? $nav_items['chercheur'];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Éditer le profil</title>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
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
    --shadow-sm: 0 1px 4px rgba(13,33,55,0.07);
    --shadow: 0 4px 20px rgba(13,33,55,0.10);
    --shadow-md: 0 8px 32px rgba(13,33,55,0.15);
    --radius: 8px;
    --radius-sm: 4px;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

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


  /* ─── TOPBAR ─── */
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
    width: 34px; height: 34px;
    background: var(--navy);
    border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 16px;
  }
  .brand-name {
    font-size: 18px; font-weight: 600;
    color: var(--navy); letter-spacing: -0.3px;
  }
  .nav-links {
    display: flex; align-items: center; gap: 4px; flex: 1;
  }
  .nav-link {
    padding: 8px 16px; font-size: 14px; font-weight: 400;
    color: var(--text-light); background: none; border: none;
    border-radius: var(--radius-sm); cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    transition: all 0.15s; position: relative;
    text-decoration: none; display: inline-block;
  }
  .nav-link:hover { color: var(--navy); background: var(--bg); }
  .nav-link.active { color: var(--gold); font-weight: 500; }
  .nav-link.active::after {
    content: ''; position: absolute;
    bottom: -1px; left: 16px; right: 16px;
    height: 2px; background: var(--gold);
    border-radius: 2px 2px 0 0;
  }
  .topbar-right { display: flex; align-items: center; gap: 12px; }
  .search-wrap { position: relative; }
  .search-input {
    width: 240px; padding: 8px 14px 8px 36px;
    border: 1px solid var(--border); border-radius: 20px;
    font-size: 13.5px; font-family: 'DM Sans', sans-serif;
    background: var(--bg); color: var(--text); outline: none;
    transition: all 0.15s;
  }
  .search-input::placeholder { color: var(--muted); }
  .search-input:focus {
    border-color: var(--accent); background: var(--white);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.10); width: 280px;
  }
  .search-icon {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    color: var(--muted); font-size: 13px; pointer-events: none;
  }
  .logout-btn {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 18px;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 500;
    color: var(--text-light); cursor: pointer;
    font-family: 'DM Sans', sans-serif; transition: all 0.15s;
    text-decoration: none;
  }
  .logout-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--white); }

  /* ─── MESSAGES ─── */
  .message {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 18px; border-radius: var(--radius);
    margin-bottom: 24px; font-size: 14px;
    animation: fadeUp 0.25s ease;
  }
  .message.success { background: #e8f6f3; border: 1px solid #9dd8d0; color: #1a5f57; }
  .message.error   { background: #fdf2f2; border: 1px solid #f5b8b8; color: #8b2020; }
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: none; }
  }

  /* ─── PAGE ─── */
  .page {
    max-width: 1100px;
    margin: 0 auto;
    padding: 40px 32px 60px;
  }
  .page-header { margin-bottom: 32px; }
  .page-header h1 {
    font-family: 'Libre Baskerville', serif;
    font-size: 32px; font-weight: 700;
    color: var(--navy); letter-spacing: -0.5px; margin-bottom: 6px;
  }
  .page-header h1 em { font-style: italic; color: var(--gold); }
  .page-header p { font-size: 14px; color: var(--muted); }

  /* ─── LAYOUT ─── */
  .profile-grid {
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 24px;
    align-items: start;
  }

  /* ─── SIDEBAR ─── */
  .sidebar-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    position: sticky;
    top: 82px;
  }
  .sidebar-nav-item {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 20px; font-size: 14px;
    color: var(--text-light); cursor: pointer;
    transition: all 0.15s; border: none; background: none;
    width: 100%; text-align: left;
    font-family: 'DM Sans', sans-serif;
    border-bottom: 1px solid var(--border);
    text-decoration: none;
  }
  .sidebar-nav-item:last-child { border-bottom: none; }
  .sidebar-nav-item:hover { background: var(--bg); color: var(--navy); }
  .sidebar-nav-item.active {
    background: #fef9ed; color: var(--text);
    font-weight: 500;
    border-left: 3px solid var(--gold);
    padding-left: 17px;
  }
  .sidebar-nav-item.active .snav-icon { color: var(--gold); }
  .snav-icon { font-size: 14px; color: var(--muted); width: 18px; text-align: center; }
  .sidebar-nav-item.danger { color: var(--danger); }
  .sidebar-nav-item.danger .snav-icon { color: var(--danger); }
  .sidebar-nav-item.danger:hover { background: #fdf2f2; }

  /* ─── MAIN CARD ─── */
  .form-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
  }

  /* Cover */
  .cover-container {
    height: 130px;
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%);
    position: relative; cursor: pointer; overflow: hidden;
  }
  .cover-container img { width: 100%; height: 100%; object-fit: cover; }
  .cover-overlay {
    position: absolute; inset: 0;
    background: rgba(0,0,0,0.35);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity 0.2s;
  }
  .cover-container:hover .cover-overlay { opacity: 1; }
  .cover-btn {
    background: var(--white); color: var(--navy);
    padding: 7px 18px; border-radius: 20px;
    font-size: 13px; font-weight: 600;
    display: flex; align-items: center; gap: 6px;
  }

  /* Avatar + meta */
  .profile-meta {
    padding: 0 28px 24px;
    border-bottom: 1px solid var(--border);
  }
  .avatar-row {
    display: flex; align-items: flex-end;
    gap: 16px; margin-top: -32px; margin-bottom: 12px;
  }
  .avatar-wrap { position: relative; cursor: pointer; }
  .avatar {
    width: 72px; height: 72px; border-radius: 50%;
    border: 3px solid var(--white);
    background: var(--navy-mid);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 700; color: var(--white);
    overflow: hidden; box-shadow: var(--shadow-sm);
  }
  .avatar img { width: 100%; height: 100%; object-fit: cover; }
  .avatar-edit-badge {
    position: absolute; bottom: 3px; right: 3px;
    width: 22px; height: 22px;
    background: var(--gold); border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: var(--navy); font-size: 9px;
    border: 2px solid var(--white);
  }
  .profile-name {
    font-size: 18px; font-weight: 600; color: var(--navy); margin-bottom: 2px;
  }
  .profile-handle { font-size: 13px; color: var(--muted); }

  /* Section tabs inside card */
  .section-tabs {
    display: flex; gap: 0;
    border-bottom: 1px solid var(--border);
    padding: 0 28px;
  }
  .section-tab {
    padding: 14px 18px;
    font-size: 13.5px; font-weight: 500;
    color: var(--muted); cursor: pointer;
    border: none; background: none;
    font-family: 'DM Sans', sans-serif;
    position: relative; transition: color 0.15s;
  }
  .section-tab:hover { color: var(--navy); }
  .section-tab.active { color: var(--navy); }
  .section-tab.active::after {
    content: ''; position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 2px; background: var(--gold);
  }

  /* Form body */
  .form-body { padding: 28px; }

  /* Field styles */
  .mini-section-title {
    font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 1.2px;
    color: var(--muted); margin-bottom: 16px; margin-top: 24px;
    display: flex; align-items: center; gap: 8px;
  }
  .mini-section-title:first-child { margin-top: 0; }
  .mini-section-title::after {
    content: ''; flex: 1; height: 1px; background: var(--border);
  }

  .form-grid {
    display: grid; gap: 20px; margin-bottom: 20px;
  }
  .form-grid.cols-2 { grid-template-columns: 1fr 1fr; }
  .form-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }

  .form-group { display: flex; flex-direction: column; gap: 6px; }

  .field-label {
    font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 1px;
    color: var(--muted);
  }
  .form-input, .form-select, .form-textarea {
    padding: 11px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px; color: var(--text);
    background: var(--white); transition: all 0.15s; outline: none;
  }
  .form-input::placeholder, .form-textarea::placeholder { color: #b0bec9; }
  .form-input:focus, .form-select:focus, .form-textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.12);
  }
  .form-textarea { resize: vertical; min-height: 100px; line-height: 1.6; }
  .char-count { font-size: 11px; color: var(--muted); text-align: right; margin-top: 2px; }

  /* Username prefix input */
  .input-with-prefix {
    display: flex; border: 1px solid var(--border);
    border-radius: var(--radius-sm); overflow: hidden;
    transition: all 0.15s;
  }
  .input-with-prefix:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.12);
  }
  .input-prefix {
    padding: 11px 12px;
    background: var(--bg); color: var(--muted);
    font-size: 14px; font-weight: 500;
    border-right: 1px solid var(--border);
    display: flex; align-items: center;
  }
  .input-with-prefix .form-input {
    border: none; border-radius: 0; flex: 1; box-shadow: none !important;
  }

  /* Social inputs */
  .social-input {
    display: flex; border: 1px solid var(--border);
    border-radius: var(--radius-sm); overflow: hidden;
    transition: all 0.15s;
  }
  .social-input:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.12);
  }
  .social-icon {
    width: 44px; display: flex; align-items: center; justify-content: center;
    background: var(--bg); color: var(--muted); font-size: 14px;
    border-right: 1px solid var(--border);
    flex-shrink: 0;
  }
  .social-input .form-input { border: none; border-radius: 0; flex: 1; box-shadow: none !important; }

  /* Security cards */
  .security-card {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px 20px;
    margin-bottom: 16px;
  }
  .security-card-header {
    display: flex; align-items: center; gap: 14px;
  }
  .security-card-icon {
    width: 40px; height: 40px;
    background: var(--white); border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    color: var(--navy); font-size: 15px;
    flex-shrink: 0;
  }
  .security-card-info { flex: 1; }
  .security-card-info h4 { font-size: 14px; font-weight: 600; color: var(--navy); margin-bottom: 2px; }
  .security-card-info p  { font-size: 12.5px; color: var(--muted); }
  .security-card-body { margin-top: 18px; }

  /* Buttons */
  .btn-primary {
    background: var(--navy); color: var(--gold-light);
    border: none; border-radius: var(--radius-sm);
    padding: 11px 26px; font-size: 14px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer;
    transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px;
  }
  .btn-primary:hover { background: var(--navy-mid); transform: translateY(-1px); box-shadow: var(--shadow); }
  .btn-secondary {
    background: none; color: var(--text-light);
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    padding: 11px 22px; font-size: 14px;
    font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s;
  }
  .btn-secondary:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }
  .btn-danger {
    background: none; color: var(--danger);
    border: 1px solid var(--danger); border-radius: var(--radius-sm);
    padding: 9px 18px; font-size: 13px;
    font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s;
  }
  .btn-danger:hover { background: var(--danger); color: white; }

  .form-actions {
    display: flex; justify-content: flex-end; gap: 12px;
    align-items: center; margin-top: 28px; padding-top: 22px;
    border-top: 1px solid var(--border);
  }

  /* Section content panels */
  .section-panel { display: none; }
  .section-panel.active { display: block; animation: fadeUp 0.2s ease; }

  /* Footer */
  .footer {
    background: var(--navy); color: rgba(255,255,255,0.5);
    text-align: center; padding: 22px;
    margin-top: 48px; font-size: 13px;
  }

  /* Responsive */
  @media (max-width: 900px) {
    .profile-grid { grid-template-columns: 1fr; }
    .sidebar-card { position: static; display: flex; flex-wrap: wrap; }
    .sidebar-nav-item { flex: 1; min-width: 130px; border-bottom: none; border-right: 1px solid var(--border); }
    .sidebar-nav-item.active { border-left: none; border-bottom: 2px solid var(--gold); padding-left: 20px; }
    .form-grid.cols-2, .form-grid.cols-3 { grid-template-columns: 1fr; }
    .section-tabs { overflow-x: auto; }
  }
  @media (max-width: 600px) {
    .topbar { padding: 0 16px; }
    .nav-links { display: none; }
    .search-wrap { display: none; }
    .page { padding: 24px 16px; }
  }
</style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
  <a class="brand" href="#">
  <div class="logo-wrapper">
    <div class="logo-icon"><i class="fas fa-book-open"></i></div>
    <span class="logo-text">ConfManager</span>
  </div>
</a>
  <nav class="nav-links">
    <?php foreach($current_nav as $item): ?>
      <a href="<?php echo e($item['url']); ?>" 
         class="nav-link <?php echo isset($item['active']) && $item['active'] ? 'active' : ''; ?>">
        <?php echo e($item['label']); ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="topbar-right">
    <div class="search-wrap">
    
      <input type="text" class="search-input" placeholder="Rechercher...">
    </div>
    <a href="logout.php" class="logout-btn">
      <span>↪</span> Déconnexion
    </a>
  </div>
</header>

<!-- PAGE -->
<main class="page">

  <!-- AFFICHAGE DES MESSAGES -->
  <?php if ($message): ?>
    <div class="message success">
      <i class="fas fa-check-circle"></i>
      <span><?php echo e($message); ?></span>
    </div>
  <?php endif; ?>
  
  <?php if ($error): ?>
    <div class="message error">
      <i class="fas fa-exclamation-triangle"></i>
      <span><?php echo $error; ?></span>
    </div>
  <?php endif; ?>

  <!-- PAGE HEADER -->
  <div class="page-header">
    <h1>Éditer <em>le profil</em></h1>
    <p>Gérez votre apparence publique et vos informations personnelles</p>
  </div>

  <!-- GRID -->
  <div class="profile-grid">

    <!-- LEFT SIDEBAR -->
    <aside class="sidebar-card">
      <button class="sidebar-nav-item active" data-section="personal">
        <span class="snav-icon"><i class="fas fa-user"></i></span>
        Informations personnelles
      </button>
      <button class="sidebar-nav-item" data-section="social">
        <span class="snav-icon"><i class="fas fa-share-alt"></i></span>
        Réseaux sociaux
      </button>
      <button class="sidebar-nav-item" data-section="security">
        <span class="snav-icon"><i class="fas fa-lock"></i></span>
        Sécurité
      </button>
      <a href="logout.php" class="sidebar-nav-item danger">
        <span class="snav-icon"><i class="fas fa-sign-out-alt"></i></span>
        Déconnexion
      </a>
    </aside>

    <!-- MAIN CARD -->
    <div class="form-card">

      <form method="POST" enctype="multipart/form-data" id="profileForm">

        <!-- Cover -->
        <div class="cover-container" onclick="document.getElementById('coverInput').click()">
          <?php if(!empty($user['cover']) && file_exists($user['cover'])): ?>
            <img src="<?php echo e($user['cover']); ?>" id="coverImg">
          <?php endif; ?>
          <div class="cover-overlay">
            <span class="cover-btn"><i class="fas fa-camera"></i> Changer la couverture</span>
          </div>
        </div>
        <input type="file" id="coverInput" name="cover" accept="image/*" style="display:none">

        <!-- Avatar + name -->
        <div class="profile-meta">
          <div class="avatar-row">
            <div class="avatar-wrap" onclick="document.getElementById('avatarInput').click()">
              <div class="avatar" id="avatarEl">
                <?php if(!empty($user['avatar']) && file_exists($user['avatar'])): ?>
                  <img src="<?php echo e($user['avatar']); ?>" alt="Avatar">
                <?php else: ?>
                  <span><?php echo e($initials); ?></span>
                <?php endif; ?>
              </div>
              <div class="avatar-edit-badge"><i class="fas fa-pencil-alt"></i></div>
            </div>
            <input type="file" id="avatarInput" name="avatar" accept="image/*" style="display:none">
            <div>
              <div class="profile-name" id="displayName">
                <?php echo e(($user['first_name']??'').' '.($user['last_name']??'')); ?>
              </div>
              <div class="profile-handle" id="displayHandle">
                @<?php echo e($user['username']??''); ?>
              </div>
            </div>
          </div>
        </div>

        <!-- ─── PERSONAL SECTION ─── -->
        <div class="section-panel active" id="personal-section">
          <div class="form-body">
            <div class="mini-section-title">Informations personnelles</div>

            <div class="form-grid cols-2">
              <div class="form-group">
                <label class="field-label">Prénom</label>
                <input class="form-input" type="text" name="first_name"
                  value="<?php echo e($user['first_name']??''); ?>" required>
              </div>
              <div class="form-group">
                <label class="field-label">Nom</label>
                <input class="form-input" type="text" name="last_name"
                  value="<?php echo e($user['last_name']??''); ?>" required>
              </div>
              <div class="form-group">
                <label class="field-label">Nom d'utilisateur</label>
                <div class="input-with-prefix">
                  <span class="input-prefix">@</span>
                  <input class="form-input" type="text" name="username"
                    value="<?php echo e($user['username']??''); ?>" required>
                </div>
              </div>
              <div class="form-group">
                <label class="field-label">Email</label>
                <input class="form-input" type="email" name="email"
                  value="<?php echo e($user['email']??''); ?>" required>
              </div>
              <div class="form-group">
                <label class="field-label">Téléphone</label>
                <input class="form-input" type="tel" name="phone"
                  value="<?php echo e($user['phone']??''); ?>">
              </div>
              <div class="form-group">
                <label class="field-label">Localisation</label>
                <input class="form-input" type="text" name="location"
                  value="<?php echo e($user['location']??''); ?>">
              </div>
            </div>

            <div class="form-group">
              <label class="field-label">Bio</label>
              <textarea class="form-textarea" name="bio" maxlength="160"
                oninput="updateCharCount()"><?php echo e($user['bio']??''); ?></textarea>
              <div class="char-count">
                <span id="charCount"><?php echo strlen($user['bio']??''); ?></span> / 160
              </div>
            </div>

            <div class="form-actions">
              <button type="button" class="btn-secondary" onclick="if(confirm('Annuler les modifications ?')) window.location.reload()">
                Annuler
              </button>
              <button type="submit" name="update_profile" class="btn-primary">
                <i class="fas fa-save"></i> Enregistrer
              </button>
            </div>
          </div>
        </div>

        <!-- ─── SOCIAL SECTION ─── -->
        <div class="section-panel" id="social-section">
          <div class="form-body">
            <div class="mini-section-title">Réseaux sociaux</div>

            <div class="form-group" style="margin-bottom:16px">
              <label class="field-label">Twitter</label>
              <div class="social-input">
                <span class="social-icon"><i class="fab fa-twitter"></i></span>
                <input class="form-input" type="text" name="twitter" placeholder="username"
                  value="<?php echo e($user['twitter']??''); ?>">
              </div>
            </div>
            <div class="form-group" style="margin-bottom:16px">
              <label class="field-label">LinkedIn</label>
              <div class="social-input">
                <span class="social-icon"><i class="fab fa-linkedin-in"></i></span>
                <input class="form-input" type="text" name="linkedin" placeholder="username"
                  value="<?php echo e($user['linkedin']??''); ?>">
              </div>
            </div>
            <div class="form-group" style="margin-bottom:16px">
              <label class="field-label">Facebook</label>
              <div class="social-input">
                <span class="social-icon"><i class="fab fa-facebook-f"></i></span>
                <input class="form-input" type="text" name="facebook" placeholder="username"
                  value="<?php echo e($user['facebook']??''); ?>">
              </div>
            </div>
            <div class="form-group" style="margin-bottom:16px">
              <label class="field-label">ResearchGate</label>
              <div class="social-input">
                <span class="social-icon"><i class="fab fa-researchgate"></i></span>
                <input class="form-input" type="text" name="researchgate" placeholder="username"
                  value="<?php echo e($user['researchgate']??''); ?>">
              </div>
            </div>

            <div class="form-actions">
              <button type="button" class="btn-secondary" onclick="if(confirm('Annuler les modifications ?')) window.location.reload()">
                Annuler
              </button>
              <button type="submit" name="update_profile" class="btn-primary">
                <i class="fas fa-save"></i> Enregistrer
              </button>
            </div>
          </div>
        </div>

        <!-- ─── SECURITY SECTION ─── -->
        <div class="section-panel" id="security-section">
          <div class="form-body">
            <div class="mini-section-title">Sécurité</div>

            <!-- Password change -->
            <div class="security-card">
              <div class="security-card-header">
                <div class="security-card-icon"><i class="fas fa-key"></i></div>
                <div class="security-card-info">
                  <h4>Changer le mot de passe</h4>
                  <p>Mettez à jour votre mot de passe régulièrement</p>
                </div>
              </div>
              <div class="security-card-body">
                <div class="form-group" style="margin-bottom:16px">
                  <label class="field-label">Mot de passe actuel</label>
                  <input class="form-input" type="password" name="current_password" placeholder="••••••••">
                </div>
                <div class="form-grid cols-2">
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

          </div>
        </div>

      </form>
    </div><!-- /form-card -->
  </div><!-- /profile-grid -->
</main>

<footer class="footer">
  © <?php echo date('Y'); ?> ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés
</footer>

<script>
  // Section navigation
  document.querySelectorAll('.sidebar-nav-item[data-section]').forEach(item => {
    item.addEventListener('click', function() {
      document.querySelectorAll('.sidebar-nav-item').forEach(n => n.classList.remove('active'));
      this.classList.add('active');
      const id = this.dataset.section;
      document.querySelectorAll('.section-panel').forEach(p => p.classList.remove('active'));
      document.getElementById(id + '-section').classList.add('active');
    });
  });

  // Avatar preview
  document.getElementById('avatarInput').addEventListener('change', function() {
    if(this.files && this.files[0]){
      const r = new FileReader();
      r.onload = e => {
        document.getElementById('avatarEl').innerHTML = `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover">`;
      };
      r.readAsDataURL(this.files[0]);
    }
  });

  // Cover preview
  document.getElementById('coverInput').addEventListener('change', function() {
    if(this.files && this.files[0]){
      const r = new FileReader();
      r.onload = e => {
        const c = document.querySelector('.cover-container');
        c.style.backgroundImage = `url('${e.target.result}')`;
        c.style.backgroundSize = 'cover';
        c.style.backgroundPosition = 'center';
        const existingImg = c.querySelector('img');
        if(existingImg) existingImg.style.display = 'none';
      };
      r.readAsDataURL(this.files[0]);
    }
  });

  // Live name update
  ['first_name','last_name','username'].forEach(n => {
    const el = document.querySelector(`input[name="${n}"]`);
    if(el) el.addEventListener('input', () => {
      const fn = document.querySelector('input[name="first_name"]').value;
      const ln = document.querySelector('input[name="last_name"]').value;
      const un = document.querySelector('input[name="username"]').value;
      document.getElementById('displayName').textContent = fn + ' ' + ln;
      document.getElementById('displayHandle').textContent = '@' + un;
    });
  });

  // Char counter
  function updateCharCount() {
    const bio = document.querySelector('textarea[name="bio"]');
    if(bio) {
      const count = bio.value.length;
      document.getElementById('charCount').textContent = count;
    }
  }

  // Password confirm validation
  document.getElementById('profileForm').addEventListener('submit', function(e) {
    const np = document.querySelector('input[name="new_password"]');
    const cp = document.querySelector('input[name="confirm_password"]');
    if(np && cp && (np.value || cp.value)){
      if(np.value !== cp.value){
        e.preventDefault();
        alert('Les mots de passe ne correspondent pas !');
      } else if(np.value.length > 0 && np.value.length < 6){
        e.preventDefault();
        alert('Le mot de passe doit contenir au moins 6 caractères.');
      }
    }
  });

  // Auto-hide messages
  setTimeout(() => {
    document.querySelectorAll('.message').forEach(m => {
      m.style.transition = 'opacity 0.5s';
      m.style.opacity = '0';
      setTimeout(() => m.remove(), 500);
    });
  }, 5000);
</script>
</body>
</html>