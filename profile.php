<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// ─── DATABASE CONNECTION ───
$conn = null;
if (file_exists("config.php")) {
    include "config.php";
}
if (!isset($conn) || !$conn) {
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "confmanager";

    $conn = mysqli_connect($servername, $username, $password, $dbname);
    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }
    mysqli_set_charset($conn, "utf8mb4");
}

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';
$edit_mode = isset($_GET['edit']) && $_GET['edit'] === '1';

// ─── FETCH USER ───
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

if(!$user){
    session_destroy();
    header("Location: login.php");
    exit();
}

// ─── ENSURE ALL COLUMNS EXIST ───
$required_cols = [
    'username' => "VARCHAR(100) AFTER last_name",
    'phone' => "VARCHAR(50) AFTER email",
    'location' => "VARCHAR(255) AFTER phone",
    'bio' => "TEXT AFTER location",
    'avatar' => "VARCHAR(255) AFTER bio",
    'cover' => "VARCHAR(255) AFTER avatar",
    'twitter' => "VARCHAR(100) AFTER cover",
    'linkedin' => "VARCHAR(100) AFTER twitter",
    'facebook' => "VARCHAR(100) AFTER linkedin",
    'researchgate' => "VARCHAR(100) AFTER facebook",
    'specialties' => "TEXT AFTER researchgate"
];

foreach ($required_cols as $col => $def) {
    $check = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '$col'");
    if(mysqli_num_rows($check) == 0){
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN $col $def");
    }
}

// Refresh user data after schema updates
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

// ─── PARSE SPECIALTIES ───
$specialties = [];
if (!empty($user['specialties'])) {
    $specialties = array_filter(array_map('trim', explode(',', $user['specialties'])));
}

// ─── UPDATE PROFILE ───
if(isset($_POST['update_profile'])){
    $first_name  = mysqli_real_escape_string($conn, $_POST['first_name'] ?? '');
    $last_name   = mysqli_real_escape_string($conn, $_POST['last_name'] ?? '');
    $username    = mysqli_real_escape_string($conn, $_POST['username'] ?? '');
    $email       = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
    $phone       = mysqli_real_escape_string($conn, $_POST['phone'] ?? '');
    $location    = mysqli_real_escape_string($conn, $_POST['location'] ?? '');
    $bio         = mysqli_real_escape_string($conn, $_POST['bio'] ?? '');
    $twitter     = mysqli_real_escape_string($conn, $_POST['twitter'] ?? '');
    $linkedin    = mysqli_real_escape_string($conn, $_POST['linkedin'] ?? '');
    $facebook    = mysqli_real_escape_string($conn, $_POST['facebook'] ?? '');
    $researchgate= mysqli_real_escape_string($conn, $_POST['researchgate'] ?? '');

    // Handle specialties from hidden input
    $specialties_raw = $_POST['specialties_hidden'] ?? '';
    $specialties_arr = array_filter(array_map('trim', explode(',', $specialties_raw)));
    $specialties_str = implode(',', $specialties_arr);

    $avatar_path = $user['avatar'] ?? '';
    if(isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0){
        $allowed = ['jpg','jpeg','png','gif'];
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        if(in_array($ext, $allowed)){
            $upload_dir = 'uploads/avatars/';
            if(!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $new_filename = uniqid() . '.' . $ext;
            if(move_uploaded_file($_FILES['avatar']['tmp_name'], $upload_dir . $new_filename))
                $avatar_path = $upload_dir . $new_filename;
            else
                $error_message = "Erreur lors du téléchargement de l'avatar.";
        }
    }

    $cover_path = $user['cover'] ?? '';
    if(isset($_FILES['cover']) && $_FILES['cover']['error'] == 0){
        $allowed = ['jpg','jpeg','png','gif'];
        $ext = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION));
        if(in_array($ext, $allowed)){
            $upload_dir = 'uploads/covers/';
            if(!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $new_filename = uniqid() . '.' . $ext;
            if(move_uploaded_file($_FILES['cover']['tmp_name'], $upload_dir . $new_filename))
                $cover_path = $upload_dir . $new_filename;
            else
                $error_message = "Erreur lors du téléchargement de la couverture.";
        }
    }

    $update_sql = "UPDATE users SET first_name=?,last_name=?,username=?,email=?,phone=?,location=?,bio=?,avatar=?,cover=?,twitter=?,linkedin=?,facebook=?,researchgate=?,specialties=? WHERE id=?";
    $update_stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param($update_stmt, "ssssssssssssssi",
        $first_name,$last_name,$username,$email,$phone,$location,$bio,$avatar_path,$cover_path,
        $twitter,$linkedin,$facebook,$researchgate,$specialties_str,$user_id);
    if(mysqli_stmt_execute($update_stmt)){
        $success_message = "Profil mis à jour avec succès !";
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        // Re-parse specialties after update
        $specialties = [];
        if (!empty($user['specialties'])) {
            $specialties = array_filter(array_map('trim', explode(',', $user['specialties'])));
        }
        $edit_mode = false;
    } else {
        $error_message = "Erreur SQL: " . mysqli_stmt_error($update_stmt);
    }
}

// ─── CHANGE PASSWORD ───
if(isset($_POST['change_password'])){
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if(password_verify($current, $user['password'])){
        if($new === $confirm){
            if(strlen($new) >= 6){
                $hashed = password_hash($new, PASSWORD_DEFAULT);
                $s = mysqli_prepare($conn, "UPDATE users SET password=? WHERE id=?");
                mysqli_stmt_bind_param($s, "si", $hashed, $user_id);
                if(mysqli_stmt_execute($s)){
                    $success_message = "Mot de passe changé avec succès !";
                } else {
                    $error_message = "Erreur SQL: " . mysqli_stmt_error($s);
                }
            } else { 
                $error_message = "Le mot de passe doit contenir au moins 6 caractères."; 
            }
        } else { 
            $error_message = "Les nouveaux mots de passe ne correspondent pas."; 
        }
    } else { 
        $error_message = "Mot de passe actuel incorrect."; 
    }
}

$initials = strtoupper(substr($user['first_name']??'',0,1).substr($user['last_name']??'',0,1));
$full_name = htmlspecialchars(($user['first_name']??'') . ' ' . ($user['last_name']??''));
$display_username = htmlspecialchars($user['username'] ?? $user['email'] ?? '');
$user_role = $user['role'] ?? 'chercheur';

// Role label
$role_label = match($user_role) {
    'gestionnaire' => 'Gestionnaire',
    'reviewer' => 'Évaluateur',
    default => 'Chercheur'
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Profil</title>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root {
    --navy: #0d2137;
    --navy-mid: #1a3a5c;
    --navy-light: #2a4a6e;
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
    --radius: 12px;
    --radius-sm: 8px;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    line-height: 1.6;
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

  /* ─── PAGE ─── */
  .page {
    max-width: 900px;
    margin: 0 auto;
    padding: 40px 32px 60px;
  }

  /* ─── MESSAGES ─── */
  .message {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 18px; border-radius: var(--radius-sm);
    margin-bottom: 24px; font-size: 14px;
    animation: fadeUp 0.25s ease;
  }
  .message.success { background: #e8f6f3; border: 1px solid #9dd8d0; color: #1a5f57; }
  .message.error   { background: #fdf2f2; border: 1px solid #f5b8b8; color: #8b2020; }
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: none; }
  }

  /* ─── PROFILE CARD ─── */
  .profile-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: var(--shadow);
  }

  /* Cover */
  .cover-container {
    height: 200px;
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 50%, var(--navy-light) 100%);
    position: relative;
    overflow: hidden;
  }
  .cover-container img {
    width: 100%; height: 100%; object-fit: cover;
  }
  .cover-pattern {
    position: absolute;
    inset: 0;
    opacity: 0.1;
    background-image: radial-gradient(circle at 2px 2px, white 1px, transparent 0);
    background-size: 24px 24px;
  }

  /* Profile Header */
  .profile-header {
    padding: 0 40px 32px;
    position: relative;
  }
  .avatar-section {
    display: flex;
    align-items: flex-end;
    gap: 24px;
    margin-top: -50px;
    margin-bottom: 24px;
  }
  .avatar-wrap {
    position: relative;
    flex-shrink: 0;
  }
  .avatar {
    width: 100px; height: 100px;
    border-radius: 50%;
    border: 4px solid var(--white);
    background: linear-gradient(135deg, var(--navy-mid), var(--navy-light));
    display: flex; align-items: center; justify-content: center;
    font-size: 32px; font-weight: 700;
    color: var(--white);
    overflow: hidden;
    box-shadow: var(--shadow-md);
  }
  .avatar img {
    width: 100%; height: 100%; object-fit: cover;
  }
  .avatar-initials {
    background: linear-gradient(135deg, var(--gold), var(--gold-light));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
  }

  .profile-titles {
    flex: 1;
    padding-bottom: 8px;
  }
  .profile-name {
    font-family: 'Libre Baskerville', serif;
    font-size: 28px;
    font-weight: 700;
    color: var(--navy);
    letter-spacing: -0.5px;
    margin-bottom: 4px;
  }
  .profile-handle {
    font-size: 15px;
    color: var(--muted);
    font-weight: 400;
  }
  .profile-role {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    padding: 4px 12px;
    background: #fef9ed;
    border: 1px solid #f0e4c3;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    color: var(--gold);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  /* Action Buttons */
  .profile-actions {
    display: flex;
    gap: 12px;
    margin-top: 16px;
  }
  .btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-weight: 500;
    font-family: 'DM Sans', sans-serif;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    border: none;
  }
  .btn-primary {
    background: var(--navy);
    color: var(--gold-light);
  }
  .btn-primary:hover {
    background: var(--navy-mid);
    transform: translateY(-1px);
    box-shadow: var(--shadow);
  }
  .btn-secondary {
    background: var(--white);
    color: var(--text);
    border: 1px solid var(--border);
  }
  .btn-secondary:hover {
    border-color: var(--navy);
    color: var(--navy);
    background: var(--bg);
  }
  .btn-danger {
    background: none;
    color: var(--danger);
    border: 1px solid var(--danger);
  }
  .btn-danger:hover {
    background: var(--danger);
    color: white;
  }

  /* ─── INFO SECTIONS ─── */
  .info-sections {
    padding: 0 40px 40px;
  }
  .info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
  }
  .info-block {
    padding: 24px 0;
    border-bottom: 1px solid var(--border);
  }
  .info-block:nth-child(odd) {
    padding-right: 32px;
    border-right: 1px solid var(--border);
  }
  .info-block:nth-child(even) {
    padding-left: 32px;
  }
  .info-block.full-width {
    grid-column: 1 / -1;
    border-right: none !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
  }

  .info-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: var(--muted);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .info-label i {
    font-size: 13px;
    color: var(--gold);
    width: 16px;
  }
  .info-value {
    font-size: 15px;
    color: var(--text);
    font-weight: 500;
    line-height: 1.5;
  }
  .info-value.empty {
    color: var(--muted);
    font-style: italic;
    font-weight: 400;
  }
  .info-value.bio-text {
    line-height: 1.7;
    color: var(--text-light);
  }

  /* Social Links */
  .social-links {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
  }
  .social-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 13px;
    color: var(--text);
    text-decoration: none;
    transition: all 0.15s;
  }
  .social-link:hover {
    border-color: var(--accent);
    color: var(--accent);
    background: white;
  }
  .social-link i {
    font-size: 14px;
    color: var(--muted);
  }
  .social-link:hover i {
    color: var(--accent);
  }

  /* ─── SPECIALTIES ─── */
  .specialties-list {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
  }
  .specialty-tag {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #e8f0f8, #f0f4f8);
    border: 1px solid #c5d5e8;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    color: var(--accent);
    transition: all 0.15s;
  }
  .specialty-tag i {
    font-size: 11px;
    color: var(--accent);
  }
  .specialty-tag:hover {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
  }
  .specialty-tag:hover i {
    color: white;
  }

  /* Specialties Edit */
  .specialties-edit {
    margin-bottom: 16px;
  }
  .specialty-tags-container {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
    min-height: 40px;
  }
  .specialty-tag-edit {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: linear-gradient(135deg, #e8f0f8, #f0f4f8);
    border: 1px solid #c5d5e8;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    color: var(--accent);
    animation: tagPop 0.2s ease;
  }
  @keyframes tagPop {
    from { opacity: 0; transform: scale(0.8); }
    to { opacity: 1; transform: scale(1); }
  }
  .specialty-tag-edit .remove-tag {
    cursor: pointer;
    width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: rgba(44,111,173,0.15);
    color: var(--accent);
    font-size: 10px;
    transition: all 0.15s;
    border: none;
    padding: 0;
  }
  .specialty-tag-edit .remove-tag:hover {
    background: var(--danger);
    color: white;
  }
  .specialty-input-row {
    display: flex;
    gap: 8px;
  }
  .specialty-input {
    flex: 1;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    color: var(--text);
    outline: none;
    transition: all 0.15s;
  }
  .specialty-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.12);
  }
  .btn-add-specialty {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 18px;
    background: var(--accent);
    color: white;
    border: none;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 600;
    font-family: 'DM Sans', sans-serif;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
  }
  .btn-add-specialty:hover {
    background: var(--navy);
    transform: translateY(-1px);
  }

  /* ─── EDIT FORM ─── */
  .edit-form {
    padding: 0 40px 40px;
  }
  .form-section-title {
    font-family: 'Libre Baskerville', serif;
    font-size: 20px;
    font-weight: 700;
    color: var(--navy);
    margin-bottom: 24px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--gold);
    display: inline-block;
  }
  .form-grid {
    display: grid;
    gap: 20px;
    margin-bottom: 20px;
  }
  .form-grid.cols-2 { grid-template-columns: 1fr 1fr; }
  .form-group { display: flex; flex-direction: column; gap: 6px; }
  .field-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
  }
  .form-input, .form-textarea {
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    color: var(--text);
    background: var(--white);
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
    line-height: 1.6;
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
    transition: all 0.15s;
  }
  .input-with-prefix:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.12);
  }
  .input-prefix {
    padding: 12px 14px;
    background: var(--bg);
    color: var(--muted);
    font-size: 14px;
    font-weight: 500;
    border-right: 1px solid var(--border);
    display: flex;
    align-items: center;
  }
  .input-with-prefix .form-input {
    border: none;
    border-radius: 0;
    flex: 1;
    box-shadow: none !important;
  }
  .social-input {
    display: flex;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
    transition: all 0.15s;
  }
  .social-input:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.12);
  }
  .social-icon-box {
    width: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg);
    color: var(--muted);
    font-size: 14px;
    border-right: 1px solid var(--border);
    flex-shrink: 0;
  }
  .social-input .form-input {
    border: none;
    border-radius: 0;
    flex: 1;
    box-shadow: none !important;
  }

  .form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 32px;
    padding-top: 24px;
    border-top: 1px solid var(--border);
  }

  /* File Upload */
  .upload-area {
    border: 2px dashed var(--border);
    border-radius: var(--radius-sm);
    padding: 24px;
    text-align: center;
    cursor: pointer;
    transition: all 0.15s;
    margin-bottom: 8px;
  }
  .upload-area:hover {
    border-color: var(--accent);
    background: rgba(44,111,173,0.03);
  }
  .upload-area i {
    font-size: 24px;
    color: var(--muted);
    margin-bottom: 8px;
  }
  .upload-area p {
    font-size: 13px;
    color: var(--muted);
  }
  .upload-preview {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    object-fit: cover;
    margin: 0 auto 8px;
    border: 3px solid var(--white);
    box-shadow: var(--shadow);
  }

  /* Security Block in Edit */
  .security-block {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 20px;
    margin-bottom: 16px;
  }
  .security-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 16px;
  }
  .security-icon {
    width: 40px;
    height: 40px;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--navy);
    font-size: 15px;
    flex-shrink: 0;
  }
  .security-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--navy);
  }
  .security-desc {
    font-size: 13px;
    color: var(--muted);
  }

  /* Footer */
  .footer {
    background: var(--navy);
    color: rgba(255,255,255,0.5);
    text-align: center;
    padding: 24px;
    margin-top: 48px;
    font-size: 13px;
  }

  /* Responsive */
  @media (max-width: 768px) {
    .page { padding: 24px 16px; }
    .profile-header { padding: 0 24px 24px; }
    .info-sections { padding: 0 24px 24px; }
    .edit-form { padding: 0 24px 24px; }
    .info-grid { grid-template-columns: 1fr; }
    .info-block:nth-child(odd) {
      border-right: none;
      padding-right: 0;
    }
    .info-block:nth-child(even) {
      padding-left: 0;
    }
    .avatar-section {
      flex-direction: column;
      align-items: center;
      text-align: center;
    }
    .form-grid.cols-2 { grid-template-columns: 1fr; }
    .topbar { padding: 0 16px; }
    .nav-links { display: none; }
    .search-wrap { display: none; }
    .specialty-input-row { flex-direction: column; }
  }
</style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
  <a class="brand" href="dashboard.php">
    <div class="brand-icon"><i class="fas fa-clipboard-list"></i></div>
    <span class="brand-name">ConfManager</span>
  </a>
  <nav class="nav-links">
    <?php if($user_role === 'gestionnaire'): ?>
      <a href="conferences.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'conferences.php' ? ' active' : ''; ?>">Conferences & Articles</a>
      <a href="evaluators.html" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'evaluators.html' ? ' active' : ''; ?>">Evaluators</a>
      <a href="final_decisions.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'final_decisions.php' ? ' active' : ''; ?>">Final Decision</a>
      <a href="profile.php" class="nav-link active">Profile</a>
    <?php elseif($user_role === 'reviewer'): ?>
      <a href="reviewer_dashboard.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'reviewer_dashboard.php' ? ' active' : ''; ?>">Tableau de bord</a>
      <a href="articles.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'articles.php' ? ' active' : ''; ?>">Articles assignés</a>
      <a href="base-articles.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'base-articles.php' ? ' active' : ''; ?>">Base d'articles</a>
      <a href="profile.php" class="nav-link active">Profil</a>
    <?php else: ?>
      <a href="soumettre_article.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'soumettre_article.php' ? ' active' : ''; ?>">Soumettre un article</a>
      <a href="mes_articles.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'mes_articles.php' ? ' active' : ''; ?>">Mes articles</a>
      <a href="profile.php" class="nav-link active">Profil</a>
    <?php endif; ?>
  </nav>
  <div class="topbar-right">
    <div class="search-wrap">
      <span class="search-icon"><i class="fas fa-search"></i></span>
      <input type="text" class="search-input" placeholder="Rechercher...">
    </div>
    <a href="logout.php" class="logout-btn">
      <i class="fas fa-sign-out-alt"></i> Déconnexion
    </a>
  </div>
</header>

<!-- PAGE -->
<main class="page">

  <!-- MESSAGES -->
  <?php if($success_message): ?>
    <div class="message success">
      <i class="fas fa-check-circle"></i>
      <?php echo htmlspecialchars($success_message); ?>
    </div>
  <?php endif; ?>
  <?php if($error_message): ?>
    <div class="message error">
      <i class="fas fa-exclamation-circle"></i>
      <?php echo htmlspecialchars($error_message); ?>
    </div>
  <?php endif; ?>

  <!-- PROFILE CARD -->
  <div class="profile-card">

    <!-- Cover -->
    <div class="cover-container">
      <?php if(!empty($user['cover']) && file_exists($user['cover'])): ?>
        <img src="<?php echo htmlspecialchars($user['cover']); ?>" alt="Cover">
      <?php endif; ?>
      <div class="cover-pattern"></div>
    </div>

    <!-- Header -->
    <div class="profile-header">
      <div class="avatar-section">
        <div class="avatar-wrap">
          <div class="avatar">
            <?php if(!empty($user['avatar']) && file_exists($user['avatar'])): ?>
              <img src="<?php echo htmlspecialchars($user['avatar']); ?>" alt="Avatar">
            <?php else: ?>
              <span class="avatar-initials"><?php echo htmlspecialchars($initials); ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="profile-titles">
          <div class="profile-name"><?php echo $full_name; ?></div>
          <div class="profile-handle">@<?php echo $display_username; ?></div>
          <div class="profile-role">
            <i class="fas fa-shield-alt"></i>
            <?php echo $role_label; ?>
          </div>
        </div>
      </div>

      <div class="profile-actions">
        <?php if(!$edit_mode): ?>
          <a href="?edit=1" class="btn btn-primary">
            <i class="fas fa-pen"></i> Modifier le profil
          </a>
          <a href="logout.php" class="btn btn-secondary">
            <i class="fas fa-sign-out-alt"></i> Déconnexion
          </a>
        <?php else: ?>
          <a href="profile.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Retour au profil
          </a>
        <?php endif; ?>
      </div>
    </div>

    <?php if(!$edit_mode): ?>
    <!-- ═══ VIEW MODE ═══ -->
    <div class="info-sections">
      <div class="info-grid">

        <!-- Email -->
        <div class="info-block">
          <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
          <div class="info-value"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
        </div>

        <!-- Phone -->
        <div class="info-block">
          <div class="info-label"><i class="fas fa-phone"></i> Téléphone</div>
          <div class="info-value <?php echo empty($user['phone']) ? 'empty' : ''; ?>">
            <?php echo !empty($user['phone']) ? htmlspecialchars($user['phone']) : 'Non renseigné'; ?>
          </div>
        </div>

        <!-- Location -->
        <div class="info-block">
          <div class="info-label"><i class="fas fa-map-marker-alt"></i> Localisation</div>
          <div class="info-value <?php echo empty($user['location']) ? 'empty' : ''; ?>">
            <?php echo !empty($user['location']) ? htmlspecialchars($user['location']) : 'Non renseignée'; ?>
          </div>
        </div>

        <!-- Institution -->
        <div class="info-block">
          <div class="info-label"><i class="fas fa-university"></i> Institution</div>
          <div class="info-value <?php echo empty($user['institution']) ? 'empty' : ''; ?>">
            <?php echo !empty($user['institution']) ? htmlspecialchars($user['institution']) : 'Non renseignée'; ?>
          </div>
        </div>

        <!-- Bio -->
        <div class="info-block full-width">
          <div class="info-label"><i class="fas fa-quote-left"></i> Bio</div>
          <div class="info-value bio-text <?php echo empty($user['bio']) ? 'empty' : ''; ?>">
            <?php echo !empty($user['bio']) ? nl2br(htmlspecialchars($user['bio'])) : 'Aucune bio renseignée.'; ?>
          </div>
        </div>

        <?php if($user_role === 'reviewer'): ?>
        <!-- Specialties (Reviewer Only) -->
        <div class="info-block full-width">
          <div class="info-label"><i class="fas fa-flask"></i> Spécialités Scientifiques</div>
          <div class="specialties-list">
            <?php if(!empty($specialties)): ?>
              <?php foreach($specialties as $spec): ?>
                <span class="specialty-tag"><i class="fas fa-flask"></i> <?php echo htmlspecialchars($spec); ?></span>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="info-value empty">Aucune spécialité renseignée.</div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Social Links -->
        <div class="info-block full-width">
          <div class="info-label"><i class="fas fa-share-alt"></i> Réseaux sociaux</div>
          <div class="social-links">
            <?php if(!empty($user['twitter'])): ?>
              <a href="https://twitter.com/<?php echo htmlspecialchars($user['twitter']); ?>" target="_blank" class="social-link">
                <i class="fab fa-twitter"></i> <?php echo htmlspecialchars($user['twitter']); ?>
              </a>
            <?php endif; ?>
            <?php if(!empty($user['linkedin'])): ?>
              <a href="https://linkedin.com/in/<?php echo htmlspecialchars($user['linkedin']); ?>" target="_blank" class="social-link">
                <i class="fab fa-linkedin-in"></i> <?php echo htmlspecialchars($user['linkedin']); ?>
              </a>
            <?php endif; ?>
            <?php if(!empty($user['facebook'])): ?>
              <a href="https://facebook.com/<?php echo htmlspecialchars($user['facebook']); ?>" target="_blank" class="social-link">
                <i class="fab fa-facebook-f"></i> <?php echo htmlspecialchars($user['facebook']); ?>
              </a>
            <?php endif; ?>
            <?php if(!empty($user['researchgate'])): ?>
              <a href="https://researchgate.net/profile/<?php echo htmlspecialchars($user['researchgate']); ?>" target="_blank" class="social-link">
                <i class="fab fa-researchgate"></i> <?php echo htmlspecialchars($user['researchgate']); ?>
              </a>
            <?php endif; ?>
            <?php if(empty($user['twitter']) && empty($user['linkedin']) && empty($user['facebook']) && empty($user['researchgate'])): ?>
              <div class="info-value empty">Aucun réseau social renseigné.</div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>

    <?php else: ?>
    <!-- ═══ EDIT MODE ═══ -->
    <form method="POST" enctype="multipart/form-data" class="edit-form" id="profileForm">

      <div class="form-section-title">Informations personnelles</div>

      <div class="form-grid cols-2">
        <div class="form-group">
          <label class="field-label">Prénom</label>
          <input class="form-input" type="text" name="first_name"
            value="<?php echo htmlspecialchars($user['first_name']??''); ?>" required>
        </div>
        <div class="form-group">
          <label class="field-label">Nom</label>
          <input class="form-input" type="text" name="last_name"
            value="<?php echo htmlspecialchars($user['last_name']??''); ?>" required>
        </div>
        <div class="form-group">
          <label class="field-label">Nom d'utilisateur</label>
          <div class="input-with-prefix">
            <span class="input-prefix">@</span>
            <input class="form-input" type="text" name="username"
              value="<?php echo htmlspecialchars($user['username']??''); ?>" required>
          </div>
        </div>
        <div class="form-group">
          <label class="field-label">Email</label>
          <input class="form-input" type="email" name="email"
            value="<?php echo htmlspecialchars($user['email']??''); ?>" required>
        </div>
        <div class="form-group">
          <label class="field-label">Téléphone</label>
          <input class="form-input" type="tel" name="phone"
            value="<?php echo htmlspecialchars($user['phone']??''); ?>">
        </div>
        <div class="form-group">
          <label class="field-label">Localisation</label>
          <input class="form-input" type="text" name="location"
            value="<?php echo htmlspecialchars($user['location']??''); ?>">
        </div>
      </div>

      <div class="form-group" style="margin-bottom:24px">
        <label class="field-label">Bio</label>
        <textarea class="form-textarea" name="bio" maxlength="160"
          oninput="updateCharCount()"><?php echo htmlspecialchars($user['bio']??''); ?></textarea>
        <div class="char-count">
          <span id="charCount"><?php echo strlen($user['bio']??''); ?></span> / 160
        </div>
      </div>

      <!-- Avatar Upload -->
      <div class="form-group" style="margin-bottom:24px">
        <label class="field-label">Photo de profil</label>
        <div class="upload-area" onclick="document.getElementById('avatarInput').click()">
          <?php if(!empty($user['avatar']) && file_exists($user['avatar'])): ?>
            <img src="<?php echo htmlspecialchars($user['avatar']); ?>" class="upload-preview" alt="Current avatar">
            <p>Cliquez pour changer la photo</p>
          <?php else: ?>
            <i class="fas fa-camera"></i>
            <p>Cliquez pour ajouter une photo de profil</p>
          <?php endif; ?>
        </div>
        <input type="file" id="avatarInput" name="avatar" accept="image/*" style="display:none" onchange="previewAvatar(this)">
      </div>

      <!-- Cover Upload -->
      <div class="form-group" style="margin-bottom:24px">
        <label class="field-label">Image de couverture</label>
        <div class="upload-area" onclick="document.getElementById('coverInput').click()">
          <?php if(!empty($user['cover']) && file_exists($user['cover'])): ?>
            <img src="<?php echo htmlspecialchars($user['cover']); ?>" style="max-height:120px; border-radius:8px; margin-bottom:8px;" alt="Current cover">
            <p>Cliquez pour changer la couverture</p>
          <?php else: ?>
            <i class="fas fa-image"></i>
            <p>Cliquez pour ajouter une image de couverture</p>
          <?php endif; ?>
        </div>
        <input type="file" id="coverInput" name="cover" accept="image/*" style="display:none" onchange="previewCover(this)">
      </div>

      <?php if($user_role === 'reviewer'): ?>
      <!-- Specialties (Reviewer Only) -->
      <div class="form-section-title"><i class="fas fa-flask" style="margin-right:8px"></i>Spécialités Scientifiques</div>
      <div class="specialties-edit">
        <div class="specialty-tags-container" id="specialtyTags">
          <?php foreach($specialties as $spec): ?>
            <span class="specialty-tag-edit" data-value="<?php echo htmlspecialchars($spec); ?>">
              <i class="fas fa-flask" style="font-size:11px"></i>
              <?php echo htmlspecialchars($spec); ?>
              <button type="button" class="remove-tag" onclick="removeSpecialty(this)"><i class="fas fa-times"></i></button>
            </span>
          <?php endforeach; ?>
        </div>
        <div class="specialty-input-row">
          <input type="text" class="specialty-input" id="specialtyInput" placeholder="Ajouter une spécialité (ex: NLP, Cybersécurité...)" onkeypress="if(event.key==='Enter'){event.preventDefault();addSpecialty();}">
          <button type="button" class="btn-add-specialty" onclick="addSpecialty()">
            <i class="fas fa-plus"></i> Ajouter
          </button>
        </div>
        <input type="hidden" name="specialties_hidden" id="specialtiesHidden" value="<?php echo htmlspecialchars(implode(',', $specialties)); ?>">
      </div>
      <?php endif; ?>

      <div class="form-section-title">Réseaux sociaux</div>

      <div class="form-group" style="margin-bottom:16px">
        <label class="field-label">Twitter</label>
        <div class="social-input">
          <span class="social-icon-box"><i class="fab fa-twitter"></i></span>
          <input class="form-input" type="text" name="twitter" placeholder="username"
            value="<?php echo htmlspecialchars($user['twitter']??''); ?>">
        </div>
      </div>
      <div class="form-group" style="margin-bottom:16px">
        <label class="field-label">LinkedIn</label>
        <div class="social-input">
          <span class="social-icon-box"><i class="fab fa-linkedin-in"></i></span>
          <input class="form-input" type="text" name="linkedin" placeholder="username"
            value="<?php echo htmlspecialchars($user['linkedin']??''); ?>">
        </div>
      </div>
      <div class="form-group" style="margin-bottom:16px">
        <label class="field-label">Facebook</label>
        <div class="social-input">
          <span class="social-icon-box"><i class="fab fa-facebook-f"></i></span>
          <input class="form-input" type="text" name="facebook" placeholder="username"
            value="<?php echo htmlspecialchars($user['facebook']??''); ?>">
        </div>
      </div>
      <div class="form-group" style="margin-bottom:16px">
        <label class="field-label">ResearchGate</label>
        <div class="social-input">
          <span class="social-icon-box"><i class="fab fa-researchgate"></i></span>
          <input class="form-input" type="text" name="researchgate" placeholder="username"
            value="<?php echo htmlspecialchars($user['researchgate']??''); ?>">
        </div>
      </div>

      <div class="form-section-title" id="password">Sécurité</div>

      <div class="security-block">
        <div class="security-header">
          <div class="security-icon"><i class="fas fa-key"></i></div>
          <div>
            <div class="security-title">Changer le mot de passe</div>
            <div class="security-desc">Laissez vide si vous ne souhaitez pas le modifier</div>
          </div>
        </div>
        <div style="margin-top:16px; display:grid; gap:16px;">
          <div class="form-group">
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
        </div>
      </div>

      <div class="form-actions">
        <a href="profile.php" class="btn btn-secondary">
          <i class="fas fa-times"></i> Annuler
        </a>
        <button type="submit" name="update_profile" class="btn btn-primary">
          <i class="fas fa-save"></i> Enregistrer les modifications
        </button>
      </div>

    </form>
    <?php endif; ?>

  </div><!-- /profile-card -->

</main>

<footer class="footer">
  © 2026 ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés
</footer>

<script>
  // ─── SPECIALTIES MANAGEMENT ───
  function addSpecialty() {
    const input = document.getElementById('specialtyInput');
    const container = document.getElementById('specialtyTags');
    const hidden = document.getElementById('specialtiesHidden');
    const value = input.value.trim();

    if (!value) return;

    // Check if already exists
    const existing = container.querySelectorAll('.specialty-tag-edit');
    for (let tag of existing) {
      if (tag.dataset.value.toLowerCase() === value.toLowerCase()) {
        input.value = '';
        return;
      }
    }

    // Create tag element
    const tag = document.createElement('span');
    tag.className = 'specialty-tag-edit';
    tag.dataset.value = value;
    tag.innerHTML = `<i class="fas fa-flask" style="font-size:11px"></i> ${value} <button type="button" class="remove-tag" onclick="removeSpecialty(this)"><i class="fas fa-times"></i></button>`;
    container.appendChild(tag);

    // Update hidden input
    updateSpecialtiesHidden();
    input.value = '';
    input.focus();
  }

  function removeSpecialty(btn) {
    const tag = btn.closest('.specialty-tag-edit');
    tag.remove();
    updateSpecialtiesHidden();
  }

  function updateSpecialtiesHidden() {
    const container = document.getElementById('specialtyTags');
    const hidden = document.getElementById('specialtiesHidden');
    const tags = container.querySelectorAll('.specialty-tag-edit');
    const values = Array.from(tags).map(t => t.dataset.value);
    hidden.value = values.join(',');
  }

  // Char counter
  function updateCharCount() {
    const bio = document.querySelector('textarea[name="bio"]');
    if(bio) {
      document.getElementById('charCount').textContent = bio.value.length;
    }
  }

  // Avatar preview
  function previewAvatar(input) {
    if(input.files && input.files[0]){
      const r = new FileReader();
      r.onload = e => {
        const area = input.previousElementSibling;
        area.innerHTML = `<img src="${e.target.result}" class="upload-preview" alt="Preview"><p>Cliquez pour changer la photo</p>`;
      };
      r.readAsDataURL(input.files[0]);
    }
  }

  // Cover preview
  function previewCover(input) {
    if(input.files && input.files[0]){
      const r = new FileReader();
      r.onload = e => {
        const area = input.previousElementSibling;
        area.innerHTML = `<img src="${e.target.result}" style="max-height:120px; border-radius:8px; margin-bottom:8px;" alt="Preview"><p>Cliquez pour changer la couverture</p>`;
      };
      r.readAsDataURL(input.files[0]);
    }
  }

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