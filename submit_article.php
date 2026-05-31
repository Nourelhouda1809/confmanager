<?php
// ─────────────────────────────────────────
// submit_article.php  — ConfManager
// ─────────────────────────────────────────
require_once 'config.php';

// ── Configuration upload ───────────────────
if (!defined('UPLOAD_DIR')) {
    define('UPLOAD_DIR', dirname(__FILE__) . '/uploads');
}



// ── Check if user is logged in ──────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}



$user_id       = (int) $_SESSION['user_id'];
// Support both French and English session variable names
$user_nom      = htmlspecialchars($_SESSION['user_nom']      ?? $_SESSION['last_name']   ?? $_SESSION['nom']    ?? '');
$user_prenom   = htmlspecialchars($_SESSION['user_prenom']    ?? $_SESSION['first_name'] ?? $_SESSION['prenom']     ?? '');
$user_initials = strtoupper(substr($user_prenom, 0, 1) . substr($user_nom, 0, 1));
$user_email    = htmlspecialchars($_SESSION['user_email']     ?? $_SESSION['email']      ?? '');

// ── Fetch conferences with open submission window ────────────────────
$conferences = [];
try {
    $stmt = $pdo->prepare(
        "SELECT id, name_fr, name_en, submission_start_date, submission_deadline
         FROM conferences
         WHERE submission_start_date <= CURDATE()
           AND submission_deadline   >= CURDATE()
         ORDER BY name_fr"
    );
    $stmt->execute();
    $conferences = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // silent fail
}

// ── Traitement POST ───────────────────────
$errors     = [];
$success    = false;
$old        = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    }

    // --- Nettoyage & récupération ---
    $conference_id = (int) ($_POST['conference_id'] ?? 0);
    $topic_id      = (int) ($_POST['topic_id']      ?? 0);
    $title         = trim($_POST['title']            ?? '');
    $abstract      = trim($_POST['abstract']         ?? '');
    $keywords      = trim($_POST['keywords']         ?? '');
    $author        = trim($_POST['author']           ?? '');
    $author_institution = trim($_POST['author_institution'] ?? '');
    $author_email  = trim($_POST['author_email']     ?? '');
    $chk_original  = isset($_POST['chk_original']);
    $chk_terms     = isset($_POST['chk_terms']);
    $chk_authors   = isset($_POST['chk_authors']);

    $old = compact('conference_id', 'topic_id', 'title', 'abstract', 'keywords', 
                   'author', 'author_institution', 'author_email');

    // --- Validation ---
    if (empty($errors)) {
        if ($conference_id <= 0)         $errors[] = 'Veuillez sélectionner une conférence.';
        if ($topic_id <= 0)              $errors[] = 'Veuillez sélectionner un topic.';
        if (mb_strlen($title) < 5)       $errors[] = 'Le titre est obligatoire (min. 5 caractères).';
        if (mb_strlen($abstract) < 50)   $errors[] = "L'abstract est obligatoire (min. 50 caractères).";
        if ($keywords === '')            $errors[] = 'Les mots-clés sont obligatoires.';
        if (mb_strlen($author) < 2)      $errors[] = "Le nom de l'auteur est obligatoire.";
        if (!$chk_original)              $errors[] = "Vous devez confirmer l'originalité de l'article.";
        if (!$chk_terms)                 $errors[] = 'Vous devez accepter les conditions de publication.';

        // Verify conference exists and is open
        if ($conference_id > 0) {
            try {
                $chk = $pdo->prepare(
                    "SELECT id FROM conferences
                     WHERE id = :id
                       AND submission_start_date <= CURDATE()
                       AND submission_deadline   >= CURDATE()"
                );
                $chk->execute([':id' => $conference_id]);
                if (!$chk->fetch()) {
                    $errors[] = 'La conférence sélectionnée ne prend plus de soumissions.';
                }
            } catch (PDOException $e) { /* ignore */ }
        }

        // Verify topic exists and is linked to this conference
        if ($topic_id > 0 && $conference_id > 0) {
            try {
                $chkTopic = $pdo->prepare(
                    "SELECT ct.topic_id 
                     FROM conference_topics ct
                     JOIN topics t ON ct.topic_id = t.id
                     WHERE ct.conference_id = :cid AND ct.topic_id = :tid"
                );
                $chkTopic->execute([':cid' => $conference_id, ':tid' => $topic_id]);
                if (!$chkTopic->fetch()) {
                    $errors[] = 'Le topic sélectionné n\'est pas associé à cette conférence.';
                }
            } catch (PDOException $e) { /* ignore */ }
        }
    }

    // --- File upload ---
    $file_path = '';
    if (!isset($_FILES['file_path']) || $_FILES['file_path']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Le fichier de l\'article (PDF) est obligatoire.';
    } elseif ($_FILES['file_path']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Erreur lors du téléchargement du fichier (code : ' . $_FILES['file_path']['error'] . ').';
    } else {
        $allowed = ['pdf'];
        $ext = strtolower(pathinfo($_FILES['file_path']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $errors[] = 'Format invalide. Seul le PDF est accepté.';
        } elseif ($_FILES['file_path']['size'] > 20 * 1024 * 1024) {
            $errors[] = 'Le fichier dépasse 20 Mo.';
        } else {
            $upload_dir = rtrim(UPLOAD_DIR, '/') . '/articles/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $safe_name = uniqid('art_', true) . '.' . $ext;
            $file_path = 'articles/' . $safe_name;
            if (!move_uploaded_file($_FILES['file_path']['tmp_name'], $upload_dir . $safe_name)) {
                $errors[] = 'Impossible de déplacer le fichier. Vérifiez les droits du dossier.';
                $file_path = '';
            }
        }
    }

    // --- Insertion BD si pas d'erreur ---
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $sql = "INSERT INTO articles
                        (conference_id, topic_id, title, author, author_institution, 
                         author_email, submission_date, status, abstract, keywords, file_path)
                    VALUES
                        (:cid, :tid, :title, :author, :author_inst, 
                         :author_email, CURDATE(), 'new', :abstract, :keywords, :file_path)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':cid'          => $conference_id,
                ':tid'          => $topic_id,
                ':title'        => $title,
                ':author'       => $author,
                ':author_inst'  => $author_institution,
                ':author_email' => $author_email,
                ':abstract'     => $abstract,
                ':keywords'     => $keywords,
                ':file_path'    => $file_path,
            ]);
            $article_id = (int) $pdo->lastInsertId();

            $pdo->commit();
            $success = true;
            $old     = [];

        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Erreur base de données : ' . htmlspecialchars($e->getMessage());
        }
    }
}

// ── Helpers ───────────────────────────────
function esc(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function old(string $key, array $old, string $default = ''): string {
    return esc($old[$key] ?? $default);
}
function sel(string $key, $value, array $old): string {
    return ($old[$key] ?? '') == $value ? 'selected' : '';
}

// Régénérer le token CSRF
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Soumettre un article</title>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #0d2137; --navy-mid: #1a3a5c; --gold: #c9a84c; --gold-light: #e2c97e;
    --bg: #f0f2f5; --white: #ffffff; --border: #e2e8f0; --muted: #7a8fa6;
    --text: #1a2e44; --text-light: #4a607a; --accent: #2c6fad;
    --danger: #d94040; --success: #2a9d8f; --warning: #d4830a;
    --shadow-sm: 0 1px 4px rgba(13,33,55,0.07); --shadow: 0 4px 20px rgba(13,33,55,0.10);
    --shadow-md: 0 8px 32px rgba(13,33,55,0.15); --radius: 8px; --radius-sm: 4px;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

  .topbar { background: var(--white); border-bottom: 1px solid var(--border); padding: 0 40px; height: 62px; display: flex; align-items: center; position: sticky; top: 0; z-index: 100; box-shadow: var(--shadow-sm); }
  .brand { display: flex; align-items: center; gap: 10px; margin-right: 48px; text-decoration: none; }
  .brand-icon { width: 34px; height: 34px; background: var(--navy); border-radius: 6px; display: flex; align-items: center; justify-content: center; color: white; font-size: 16px; }
  .brand-name { font-size: 18px; font-weight: 600; color: var(--navy); letter-spacing: -0.3px; }
  .nav-links { display: flex; align-items: center; gap: 4px; flex: 1; }
  .nav-link { padding: 8px 16px; font-size: 14px; font-weight: 400; color: var(--text-light); background: none; border: none; border-radius: var(--radius-sm); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all 0.15s; position: relative; text-decoration: none; display: inline-block; }
  .nav-link:hover { color: var(--navy); background: var(--bg); }
  .nav-link.active { color: var(--gold); font-weight: 500; }
  .nav-link.active::after { content: ''; position: absolute; bottom: -1px; left: 16px; right: 16px; height: 2px; background: var(--gold); border-radius: 2px 2px 0 0; }
  .topbar-right { display: flex; align-items: center; gap: 12px; }
  .search-wrap { position: relative; margin-right: 8px; }
  .search-input { width: 240px; padding: 8px 14px 8px 36px; border: 1px solid var(--border); border-radius: 20px; font-size: 13.5px; font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); outline: none; transition: all 0.15s; }
  .search-input::placeholder { color: var(--muted); }
  .search-input:focus { border-color: var(--accent); background: var(--white); box-shadow: 0 0 0 3px rgba(44,111,173,0.10); width: 280px; }
  .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 13px; pointer-events: none; }
  .logout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 18px; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 500; color: var(--text-light); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all 0.15s; text-decoration: none; }
  .logout-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--white); }

  .page { max-width: 1100px; margin: 0 auto; padding: 40px 32px 60px; }
  .page-header { margin-bottom: 32px; }
  .page-header h1 { font-family: 'Libre Baskerville', serif; font-size: 32px; font-weight: 700; color: var(--navy); letter-spacing: -0.5px; margin-bottom: 6px; }
  .page-header h1 em { font-style: italic; color: var(--gold); }
  .page-header p { font-size: 14px; color: var(--muted); }
  .layout-single { max-width: 860px; margin: 0 auto; }

  .success-banner { background: #e8f6f3; border: 1px solid #9dd8d0; border-radius: var(--radius); padding: 18px 22px; display: none; align-items: flex-start; gap: 14px; margin-bottom: 24px; }
  .success-banner.show { display: flex; }
  .success-icon { font-size: 18px; background: var(--success); color: white; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .success-title { font-weight: 600; color: #1a5f57; font-size: 14px; margin-bottom: 4px; }
  .success-msg { font-size: 13px; color: #1a5f57; margin-top: 4px; }

  .error-box { background: #fdf2f2; border: 1px solid #f5b8b8; border-radius: var(--radius); padding: 16px 20px; margin-bottom: 24px; }
  .error-box ul { padding-left: 18px; }
  .error-box li { font-size: 13.5px; color: var(--danger); margin-bottom: 4px; line-height: 1.5; }
  .error-box li:last-child { margin-bottom: 0; }

  .form-card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow-sm); }
  .profile-banner { height: 120px; background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%); }
  .profile-meta { padding: 0 32px 24px; position: relative; }
  .profile-avatar-wrap { display: inline-block; margin-top: -30px; margin-bottom: 10px; }
  .profile-avatar { width: 64px; height: 64px; border-radius: 50%; background: var(--navy-mid); border: 3px solid var(--white); display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; color: var(--white); }
  .profile-name { font-size: 18px; font-weight: 600; color: var(--navy); }
  .profile-email { font-size: 13px; color: var(--muted); margin-top: 2px; }

  .section-header { display: flex; align-items: center; gap: 10px; padding: 18px 28px; border-bottom: 1px solid var(--border); border-top: 1px solid var(--border); }
  .section-header-icon { width: 30px; height: 30px; background: var(--gold); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--navy); font-size: 14px; }
  .section-header-title { font-size: 15px; font-weight: 600; color: var(--navy); }

  .steps-bar { display: flex; align-items: center; background: var(--bg); border: 1px solid var(--border); border-radius: 40px; padding: 5px; margin-bottom: 32px; }
  .step-pill { flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 12px; border-radius: 30px; transition: all 0.2s; cursor: default; }
  .step-pill.active { background: var(--white); box-shadow: var(--shadow-sm); }
  .step-num { width: 26px; height: 26px; border-radius: 50%; background: var(--border); color: var(--muted); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; flex-shrink: 0; }
  .step-pill.active .step-num { background: var(--navy); color: var(--gold-light); }
  .step-pill.done .step-num { background: var(--gold); color: white; }
  .step-txt { font-size: 12px; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
  .step-pill.active .step-txt { color: var(--navy); }
  .step-pill.done .step-txt { color: var(--gold); }

  .form-body { padding: 28px; }
  .mini-section-title { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.2px; color: var(--muted); margin-bottom: 14px; margin-top: 24px; display: flex; align-items: center; gap: 8px; }
  .mini-section-title::after { content: ''; flex: 1; height: 1px; background: var(--border); }
  .mini-section-title:first-child { margin-top: 0; }

  .form-grid { display: grid; gap: 20px; margin-bottom: 20px; }
  .form-grid.cols-2 { grid-template-columns: 1fr 1fr; }
  .form-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
  .form-group { display: flex; flex-direction: column; gap: 6px; }

  .field-label-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
  .field-label { font-size: 11px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }
  .field-required { color: var(--danger); }

  .form-input, .form-select, .form-textarea { padding: 11px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-family: 'DM Sans', sans-serif; font-size: 14px; color: var(--text); background: var(--white); transition: all 0.15s; outline: none; }
  .form-input::placeholder, .form-textarea::placeholder { color: #b0bec9; }
  .form-input:focus, .form-select:focus, .form-textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(44,111,173,0.12); }
  .form-textarea { resize: vertical; min-height: 120px; line-height: 1.6; }
  .char-count { font-size: 11px; color: var(--muted); text-align: right; }

  .conf-dates-info { display: none; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 16px; margin-top: 12px; font-size: 13px; }
  .conf-dates-info.show { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; }
  .conf-date-item .cdi-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); font-weight: 600; margin-bottom: 3px; }
  .conf-date-item .cdi-value { font-size: 13px; font-weight: 600; color: var(--navy); }

  .upload-zone { border: 2px dashed var(--border); border-radius: var(--radius); padding: 28px; text-align: center; cursor: pointer; transition: all 0.2s; background: var(--bg); position: relative; margin-bottom: 12px; }
  .upload-zone:hover, .upload-zone.dragover { border-color: var(--gold); background: #fef9ed; }
  .upload-zone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
  .upload-icon { font-size: 28px; margin-bottom: 8px; color: var(--gold); }
  .upload-label { font-size: 14px; font-weight: 500; color: var(--text-light); margin-bottom: 4px; }
  .upload-hint { font-size: 11px; color: var(--muted); }

  .file-list { display: flex; flex-direction: column; gap: 8px; }
  .file-item { display: flex; align-items: center; gap: 10px; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px 14px; font-size: 12.5px; }
  .file-icon { color: var(--accent); font-size: 14px; }
  .file-name { flex: 1; font-family: 'DM Mono', monospace; font-size: 12px; color: var(--text); word-break: break-all; }
  .file-size { color: var(--muted); font-size: 11px; margin-right: 8px; white-space: nowrap; }
  .file-remove { cursor: pointer; color: var(--danger); font-size: 14px; border: none; background: none; padding: 0 4px; }

  .checkbox-group { display: flex; flex-direction: column; gap: 14px; margin: 20px 0; }
  .checkbox-item { display: flex; align-items: flex-start; gap: 12px; cursor: pointer; }
  .checkbox-item input[type="checkbox"] { width: 17px; height: 17px; margin-top: 2px; accent-color: var(--navy); cursor: pointer; flex-shrink: 0; }
  .checkbox-item span { font-size: 13.5px; color: var(--text-light); line-height: 1.5; }

  .btn-primary { background: var(--navy); color: var(--gold-light); border: none; border-radius: var(--radius-sm); padding: 11px 26px; font-size: 14px; font-weight: 500; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px; }
  .btn-primary:hover { background: var(--navy-mid); transform: translateY(-1px); box-shadow: var(--shadow); }
  .btn-secondary { background: none; color: var(--text-light); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 11px 22px; font-size: 14px; font-weight: 400; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s; }
  .btn-secondary:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }

  .form-actions { display: flex; justify-content: flex-end; gap: 12px; align-items: center; margin-top: 28px; padding-top: 22px; border-top: 1px solid var(--border); }
  .notice-box { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 16px; font-size: 12.5px; color: var(--muted); line-height: 1.7; margin-top: 16px; }

  .step-content { display: none; animation: fadeUp 0.22s ease; }
  .step-content.active { display: block; }
  @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }

  @media (max-width: 900px) {
    .form-grid.cols-2, .form-grid.cols-3 { grid-template-columns: 1fr; }
    .conf-dates-info.show { grid-template-columns: 1fr; }
  }
  @media (max-width: 600px) {
    .topbar { padding: 0 16px; }
    .nav-links { display: none; }
    .search-wrap { display: none; }
    .page { padding: 24px 16px; }
    .form-body { padding: 20px 16px; }
    .profile-meta { padding: 0 16px 20px; }
  }
</style>
</head>
<body>

<header class="topbar">
  <a class="brand" href="#">
    <div class="brand-icon">📋</div>
    <span class="brand-name">ConfManager</span>
  </a>
  <nav class="nav-links">
    <a href="submit_article.php" class="nav-link active">Soumettre un article</a>
    <a href="mes_articles.php" class="nav-link">Mes articles</a>
    <a href="profile.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <div class="search-wrap">
      <span class="search-icon">🔍</span>
      <input type="text" class="search-input" placeholder="Rechercher...">
    </div>
    <a href="logout.php" class="logout-btn"><span>↪</span> Déconnexion</a>
  </div>
</header>

<main class="page">

  <?php if ($success): ?>
  <div class="success-banner show">
    <div class="success-icon">✓</div>
    <div>
      <div class="success-title">Article soumis avec succès !</div>
      <div class="success-msg">Votre article a été enregistré avec le statut <strong>Nouveau</strong>.</div>
      <div class="success-msg">Vous recevrez une notification par email lorsque l'évaluation commencera.</div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
  <div class="error-box" id="errorBox">
    <ul>
      <?php foreach ($errors as $err): ?>
        <li><?= esc($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="page-header">
    <h1>Soumettre <em>un article</em></h1>
    <p>Remplissez tous les champs obligatoires pour soumettre votre article à une conférence.</p>
  </div>

  <div class="layout-single">
    <div class="form-card">
      <div class="profile-banner"></div>
      <div class="profile-meta">
        <div class="profile-avatar-wrap">
          <div class="profile-avatar"><?= esc($user_initials) ?></div>
        </div>
        <div class="profile-name"><?= esc($user_prenom) ?> <?= esc($user_nom) ?></div>
        <div class="profile-email"><?= esc($user_email) ?></div>
      </div>

      <div class="section-header">
        <div class="section-header-icon">✦</div>
        <div class="section-header-title">Nouvelle soumission</div>
      </div>

      <form method="POST" enctype="multipart/form-data" id="mainForm" novalidate class="form-body">
        <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">

        <div class="steps-bar">
          <div class="step-pill active" id="pill1"><div class="step-num">1</div><div class="step-txt">Conférence</div></div>
          <div class="step-pill" id="pill2"><div class="step-num">2</div><div class="step-txt">Informations</div></div>
          <div class="step-pill" id="pill3"><div class="step-num">3</div><div class="step-txt">Fichiers</div></div>
          <div class="step-pill" id="pill4"><div class="step-num">4</div><div class="step-txt">Confirmation</div></div>
        </div>

        <!-- ÉTAPE 1 — Conférence & Topic -->
        <div class="step-content active" id="sc1">
          <div class="mini-section-title">Sélection de la conférence</div>

          <div class="form-group" style="margin-bottom:20px">
            <div class="field-label-row">
              <span class="field-label">Conférence <span class="field-required">*</span></span>
            </div>
            <select class="form-select" name="conference_id" id="selectConference" required onchange="onConferenceChange(this)">
              <option value="">— Choisissez une conférence —</option>
              <?php if (!empty($conferences)): ?>
                <?php foreach ($conferences as $conf): ?>
                  <option value="<?= (int)$conf['id'] ?>"
                          data-sub-start="<?= esc($conf['submission_start_date']) ?>"
                          data-sub-end="<?= esc($conf['submission_deadline']) ?>"
                          <?= ($old['conference_id'] ?? 0) == $conf['id'] ? 'selected' : '' ?>>
                    <?= esc($conf['name_fr']) ?>
                  </option>
                <?php endforeach; ?>
              <?php else: ?>
                <option value="11" data-sub-start="2026-05-20" data-sub-end="2026-07-10" <?= sel('conference_id', 11, $old) ?>>
                  Conférence Internationale sur l'Intelligence Artificielle
                </option>
                <option value="12" data-sub-start="2026-07-01" data-sub-end="2026-09-01" <?= sel('conference_id', 12, $old) ?>>
                  Séminaire National sur la Cybersécurité
                </option>
              <?php endif; ?>
            </select>

            <div class="conf-dates-info" id="confDatesInfo">
              <div class="conf-date-item">
                <div class="cdi-label">Ouverture soumissions</div>
                <div class="cdi-value" id="cdiSubStart">—</div>
              </div>
              <div class="conf-date-item">
                <div class="cdi-label">Clôture soumissions</div>
                <div class="cdi-value" id="cdiSubEnd">—</div>
              </div>
              <div class="conf-date-item">
                <div class="cdi-label">Soumissions</div>
                <div class="cdi-value" id="cdiStatus">—</div>
              </div>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:20px">
            <div class="field-label-row">
              <span class="field-label">Topic <span class="field-required">*</span></span>
            </div>
            <select class="form-select" name="topic_id" id="selectTopic" required disabled>
              <option value="">— Sélectionnez d'abord une conférence —</option>
            </select>
            <div class="char-count" style="text-align:left; margin-top:4px;">Les topics disponibles dépendent de la conférence choisie</div>
          </div>

          <div class="form-actions">
            <button type="button" class="btn-primary" onclick="goToStep(2)">Continuer →</button>
          </div>
        </div><!-- /sc1 -->

        <!-- ÉTAPE 2 — Informations -->
        <div class="step-content" id="sc2">
          <div class="mini-section-title">Informations de l'article</div>

          <div class="form-group" style="margin-bottom:20px">
            <div class="field-label-row"><span class="field-label">Titre <span class="field-required">*</span></span></div>
            <input class="form-input" type="text" name="title"
                   value="<?= old('title', $old) ?>"
                   placeholder="Titre de l'article"
                   oninput="updateCharCount(this,'countTitle',200)">
            <div class="char-count"><span id="countTitle"><?= mb_strlen(old('title', $old)) ?></span> / 200</div>
          </div>

          <div class="form-grid cols-2">
            <div class="form-group">
              <div class="field-label-row"><span class="field-label">Abstract <span class="field-required">*</span></span></div>
              <textarea class="form-textarea" name="abstract"
                        placeholder="Abstract de l'article (min. 50 caractères)..."
                        oninput="updateCharCount(this,'countAbs',2000)"><?= old('abstract', $old) ?></textarea>
              <div class="char-count"><span id="countAbs"><?= mb_strlen(old('abstract', $old)) ?></span> / 2000</div>
            </div>
            <div class="form-group">
              <div class="field-label-row"><span class="field-label">Mots-clés <span class="field-required">*</span></span></div>
              <input class="form-input" type="text" name="keywords"
                     value="<?= old('keywords', $old) ?>"
                     placeholder="ex: intelligence artificielle, apprentissage automatique, NLP">
              <div class="char-count" style="text-align:left;">Séparez par des virgules</div>
            </div>
          </div>

          <div class="mini-section-title">Informations de l'auteur</div>
          <div class="form-grid cols-3">
            <div class="form-group">
              <div class="field-label-row"><span class="field-label">Auteur principal <span class="field-required">*</span></span></div>
              <input class="form-input" type="text" name="author"
                     value="<?= old('author', $old) ?>"
                     placeholder="Nom complet">
            </div>
            <div class="form-group">
              <div class="field-label-row"><span class="field-label">Email</span></div>
              <input class="form-input" type="email" name="author_email"
                     value="<?= old('author_email', $old) ?>"
                     placeholder="email@institution.dz">
            </div>
            <div class="form-group">
              <div class="field-label-row"><span class="field-label">Institution</span></div>
              <input class="form-input" type="text" name="author_institution"
                     value="<?= old('author_institution', $old) ?>"
                     placeholder="Université / Institut">
            </div>
          </div>

          <div class="form-actions">
            <button type="button" class="btn-secondary" onclick="goToStep(1)">← Retour</button>
            <button type="button" class="btn-primary" onclick="goToStep(3)">Continuer →</button>
          </div>
        </div><!-- /sc2 -->

        <!-- ÉTAPE 3 — Fichiers -->
        <div class="step-content" id="sc3">
          <div class="mini-section-title">Fichier principal</div>
          <div class="form-group" style="margin-bottom:24px">
            <div class="field-label-row">
              <span class="field-label">Article (PDF) <span class="field-required">*</span></span>
            </div>
            <div class="upload-zone" id="mainZone">
              <input type="file" name="file_path" id="mainFile"
                     accept=".pdf" onchange="handleFile(this,'mainFiles',false)">
              <div class="upload-icon">📄</div>
              <div class="upload-label">Cliquez ou glissez-déposez votre article</div>
              <div class="upload-hint">PDF uniquement — Max 20 Mo</div>
            </div>
            <div class="file-list" id="mainFiles"></div>
          </div>

          <div class="form-actions">
            <button type="button" class="btn-secondary" onclick="goToStep(2)">← Retour</button>
            <button type="button" class="btn-primary" onclick="goToStep(4)">Continuer →</button>
          </div>
        </div><!-- /sc3 -->

        <!-- ÉTAPE 4 — Confirmation -->
        <div class="step-content" id="sc4">
          <div class="mini-section-title">Déclarations obligatoires</div>

          <div class="checkbox-group">
            <label class="checkbox-item">
              <input type="checkbox" name="chk_original" id="chkOriginal"
                     <?= (!empty($errors) && isset($_POST['chk_original'])) ? 'checked' : '' ?>>
              <span>Je certifie que cet article est original et n'a pas été publié ni soumis simultanément dans une autre revue ou conférence.</span>
            </label>
            <label class="checkbox-item">
              <input type="checkbox" name="chk_terms" id="chkTerms"
                     <?= (!empty($errors) && isset($_POST['chk_terms'])) ? 'checked' : '' ?>>
              <span>J'accepte les conditions de publication et le processus d'évaluation par les pairs de cette conférence.</span>
            </label>
            <label class="checkbox-item">
              <input type="checkbox" name="chk_authors" id="chkAuthors"
                     <?= (!empty($errors) && isset($_POST['chk_authors'])) ? 'checked' : '' ?>>
              <span>Tous les co-auteurs ont été informés et ont consenti à cette soumission.</span>
            </label>
          </div>

          <div class="notice-box">
            En soumettant cet article, vous reconnaissez que toutes les informations fournies sont exactes et complètes.
            Toute falsification peut entraîner le rejet immédiat et la notification de votre institution.
          </div>

          <div class="form-actions">
            <button type="button" class="btn-secondary" onclick="goToStep(3)">← Retour</button>
            <button type="submit" class="btn-primary">✓ Soumettre l'article</button>
          </div>
        </div><!-- /sc4 -->

      </form>
    </div>
  </div>
</main>

<script>
  let currentStep = 1;
  const TOTAL_STEPS = 4;

  <?php if (!empty($errors)): ?>
  document.addEventListener('DOMContentLoaded', () => goToStep(4));
  <?php endif; ?>

  function goToStep(next) {
    document.getElementById('sc' + currentStep).classList.remove('active');
    const prevPill = document.getElementById('pill' + currentStep);
    prevPill.classList.remove('active');
    if (next > currentStep) prevPill.classList.add('done');
    else prevPill.classList.remove('done');

    currentStep = next;
    document.getElementById('sc' + currentStep).classList.add('active');
    const nextPill = document.getElementById('pill' + currentStep);
    nextPill.classList.add('active');
    nextPill.classList.remove('done');
    document.getElementById('mainForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function updateCharCount(input, countId, max) {
    const len = input.value.length;
    const el = document.getElementById(countId);
    if (el) {
      el.textContent = len;
      el.style.color = len > max ? 'var(--danger)' : 'var(--muted)';
    }
  }

  function showConfDates(select) {
    const opt = select.options[select.selectedIndex];
    const box = document.getElementById('confDatesInfo');
    if (!opt.value) { box.classList.remove('show'); return; }

    const subStart = opt.dataset.subStart || '';
    const subEnd = opt.dataset.subEnd || '';

    document.getElementById('cdiSubStart').textContent = subStart ? fmtDate(subStart) : '—';
    document.getElementById('cdiSubEnd').textContent = subEnd ? fmtDate(subEnd) : '—';

    const today = new Date(); today.setHours(0,0,0,0);
    const start = subStart ? new Date(subStart) : null;
    const end = subEnd ? new Date(subEnd) : null;
    let statusEl = document.getElementById('cdiStatus');
    if (end && today > end) {
      statusEl.textContent = 'Clôturées';
      statusEl.style.color = 'var(--danger)';
    } else if (start && today < start) {
      statusEl.textContent = 'Pas encore ouvertes';
      statusEl.style.color = 'var(--warning)';
    } else {
      statusEl.textContent = 'Ouvertes ✓';
      statusEl.style.color = 'var(--success)';
    }
    box.classList.add('show');
  }

  function fmtDate(iso) {
    const [y,m,d] = iso.split('-');
    const months = ['jan.','fév.','mar.','avr.','mai','juin','juil.','août','sep.','oct.','nov.','déc.'];
    return `${parseInt(d)} ${months[parseInt(m)-1]} ${y}`;
  }

  // ── Dynamic Topic Loading ─────────────────────────────
  function onConferenceChange(select) {
    showConfDates(select);
    const topicSelect = document.getElementById('selectTopic');
    const confId = select.value;
    
    if (!confId) {
      topicSelect.innerHTML = '<option value="">— Sélectionnez d\'abord une conférence —</option>';
      topicSelect.disabled = true;
      return;
    }

    // Fetch topics for this conference via AJAX
    fetch('get_topics.php?conference_id=' + encodeURIComponent(confId))
      .then(r => r.json())
      .then(topics => {
        let html = '<option value="">— Choisissez un topic —</option>';
        topics.forEach(t => {
          const selected = (<?= (int)($old['topic_id'] ?? 0) ?> == t.id) ? 'selected' : '';
          html += `<option value="${t.id}" ${selected}>${escHtml(t.name)}</option>`;
        });
        topicSelect.innerHTML = html;
        topicSelect.disabled = false;
      })
      .catch(() => {
        topicSelect.innerHTML = '<option value="">Erreur de chargement</option>';
        topicSelect.disabled = true;
      });
  }

  function handleFile(input, listId, multiple) {
    const list = document.getElementById(listId);
    if (!multiple) list.innerHTML = '';
    Array.from(input.files).forEach(file => {
      const div = document.createElement('div');
      div.className = 'file-item';
      const size = (file.size / 1024 / 1024).toFixed(2);
      div.innerHTML = `
        <span class="file-icon">📄</span>
        <span class="file-name">${escHtml(file.name)}</span>
        <span class="file-size">${size} Mo</span>
        <button type="button" class="file-remove" onclick="this.parentElement.remove()">✕</button>`;
      list.appendChild(div);
    });
  }

  document.querySelectorAll('.upload-zone').forEach(zone => {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
    zone.addEventListener('drop', e => {
      e.preventDefault();
      zone.classList.remove('dragover');
      const input = zone.querySelector('input[type="file"]');
      if (input && e.dataTransfer.files.length) {
        Object.defineProperty(input, 'files', { value: e.dataTransfer.files, writable: false });
        input.dispatchEvent(new Event('change'));
      }
    });
  });

  function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('selectConference');
    if (sel && sel.value) onConferenceChange(sel);
  });
</script>
</body>
</html>