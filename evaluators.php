<?php
ob_start();
require_once 'config.php';
requireRole('gestionnaire');

$user_id = getCurrentUserId();

// Handle AJAX / Form POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Ensure clean JSON output
    ob_clean();
    $action = $_POST['action'];

    switch ($action) {
        case 'add_evaluator':
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $institution = trim($_POST['institution'] ?? '');
            $specialties = trim($_POST['specialties'] ?? '');
            $tempPassword = bin2hex(random_bytes(6)); // random 12-char temp password
            $password = password_hash($tempPassword, PASSWORD_BCRYPT);
            $reviewer_code = 'REV' . strtoupper(substr(md5(uniqid()), 0, 6));

            if (empty($first_name) || empty($last_name) || empty($email)) {
                jsonResponse(['success' => false, 'message' => 'Champs obligatoires manquants']);
            }

            // Check if email exists
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                jsonResponse(['success' => false, 'message' => 'Un utilisateur avec cet email existe déjà']);
            }

            $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, institution, specialties, password, role, status, reviewer_code, created_at) 
                                   VALUES (?, ?, ?, ?, ?, ?, 'reviewer', 'active', ?, NOW())");
            $stmt->execute([$first_name, $last_name, $email, $institution, $specialties, $password, $reviewer_code]);
            $newId = $pdo->lastInsertId();

            // Send notification to new evaluator
            $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, created_at) VALUES (?, 'info', ?, NOW())");
            $notifStmt->execute([$newId, "Bienvenue sur ConfManager ! Vous avez été ajouté comme évaluateur. Votre code reviewer est : $reviewer_code. Mot de passe temporaire : $tempPassword"]);

            jsonResponse(['success' => true, 'id' => $newId, 'message' => 'Évaluateur ajouté avec succès', 'temp_password' => $tempPassword, 'reviewer_code' => $reviewer_code]);
            // jsonResponse() calls exit, so no break needed here
            break;

        case 'assign_articles':
            $evaluator_id = intval($_POST['evaluator_id'] ?? 0);
            $article_ids = json_decode($_POST['article_ids'] ?? '[]', true);

            if (!$evaluator_id || empty($article_ids)) {
                jsonResponse(['success' => false, 'message' => 'Données invalides']);
            }

            $assigned = 0;
            $skipped = [];

            foreach ($article_ids as $article_id) {
                $article_id = intval($article_id);

                // Check current reviewer count
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM article_reviewers WHERE article_id = ?");
                $countStmt->execute([$article_id]);
                $reviewerCount = $countStmt->fetchColumn();

                // Check if already assigned
                $existStmt = $pdo->prepare("SELECT id FROM article_reviewers WHERE article_id = ? AND evaluator_id = ?");
                $existStmt->execute([$article_id, $evaluator_id]);

                if ($reviewerCount >= 2) {
                    $artStmt = $pdo->prepare("SELECT title FROM articles WHERE id = ?");
                    $artStmt->execute([$article_id]);
                    $skipped[] = $artStmt->fetchColumn();
                    continue;
                }

                // Skip if this evaluator is already assigned to this article
                if ($existStmt->fetch()) {
                    continue;
                }

                if (true) {
                    $stmt = $pdo->prepare("INSERT INTO article_reviewers (article_id, evaluator_id, assigned_at) VALUES (?, ?, NOW())");
                    $stmt->execute([$article_id, $evaluator_id]);

                    // Update article status if needed
                    $updStmt = $pdo->prepare("UPDATE articles SET status = 'assigned' WHERE id = ? AND status = 'new'");
                    $updStmt->execute([$article_id]);

                    // Notify evaluator
                    $artStmt = $pdo->prepare("SELECT title FROM articles WHERE id = ?");
                    $artStmt->execute([$article_id]);
                    $articleTitle = $artStmt->fetchColumn();

                    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, article_id, created_at) VALUES (?, 'info', ?, ?, NOW())");
                    $notifStmt->execute([$evaluator_id, "Un nouvel article vous a été assigné : $articleTitle", $article_id]);

                    $assigned++;
                }
            }

            jsonResponse(['success' => true, 'assigned' => $assigned, 'skipped' => $skipped]);

        default:
            jsonResponse(['success' => false, 'message' => 'Action inconnue']);
    }
}

// Fetch all evaluators (reviewers)
$evalStmt = $pdo->query("
    SELECT u.*, 
           (SELECT COUNT(*) FROM article_reviewers ar WHERE ar.evaluator_id = u.id) as assigned_count,
           (SELECT COUNT(*) FROM article_reviewers ar WHERE ar.evaluator_id = u.id AND ar.completed_at IS NOT NULL) as completed_count
    FROM users u 
    WHERE u.role = 'reviewer' 
    ORDER BY u.last_name, u.first_name
");
$evaluators = $evalStmt->fetchAll();

// Fetch available articles with reviewer counts and topic info
$artStmt = $pdo->query("
    SELECT a.id, a.title, a.author, a.status, a.submission_date, a.conference_id,
           c.name_fr as conference_name, c.name_en,
           t.name as topic_name,
           (SELECT COUNT(*) FROM article_reviewers ar WHERE ar.article_id = a.id) as reviewer_count
    FROM articles a
    LEFT JOIN conferences c ON a.conference_id = c.id
    LEFT JOIN topics t ON a.topic_id = t.id
    WHERE a.status IN ('new', 'assigned', 'review')
    ORDER BY a.submission_date DESC
");
$articles = $artStmt->fetchAll();

// Fetch conferences for filter
$confStmt = $pdo->query("SELECT id, name_fr, name_en FROM conferences ORDER BY name_fr");
$conferences = $confStmt->fetchAll();

// Fetch all topics
$topicStmt = $pdo->query("SELECT id, name FROM topics ORDER BY name");
$topics = $topicStmt->fetchAll();

// Prepare data for JavaScript
$evaluatorsJson = json_encode($evaluators);
$articlesJson = json_encode($articles);
$conferencesJson = json_encode($conferences);
$topicsJson = json_encode($topics);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Gestion des évaluateurs</title>
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
    --warning: #d4830a;
    --purple: #5b6ef5;
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
    display: flex; align-items: center; gap: 10px;
    margin-right: 48px; text-decoration: none;
  }
  .brand-icon {
    width: 34px; height: 34px; background: var(--navy);
    border-radius: 6px; display: flex; align-items: center;
    justify-content: center; color: white; font-size: 16px;
  }
  .brand-name {
    font-size: 18px; font-weight: 600; color: var(--navy); letter-spacing: -0.3px;
  }
  .nav-links { display: flex; align-items: center; gap: 4px; flex: 1; }
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
  .search-wrap { position: relative; margin-right: 8px; }
  .search-input {
    width: 240px; padding: 8px 14px 8px 36px;
    border: 1px solid var(--border); border-radius: 20px;
    font-size: 13.5px; font-family: 'DM Sans', sans-serif;
    background: var(--bg); color: var(--text); outline: none; transition: all 0.15s;
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
    padding: 9px 18px; background: var(--bg);
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    font-size: 13.5px; font-weight: 500; color: var(--text-light);
    cursor: pointer; font-family: 'DM Sans', sans-serif;
    transition: all 0.15s; text-decoration: none;
  }
  .logout-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--white); }

  /* ─── PAGE ─── */
  .page { max-width: 1400px; margin: 0 auto; padding: 40px 32px 60px; }
  .page-header { margin-bottom: 28px; }
  .page-header-row {
    display: flex; align-items: flex-end;
    justify-content: space-between; gap: 16px;
    margin-bottom: 6px;
  }
  .page-header h1 {
    font-family: 'Libre Baskerville', serif;
    font-size: 32px; font-weight: 700;
    color: var(--navy); letter-spacing: -0.5px;
  }
  .page-header h1 em { font-style: italic; color: var(--gold); }
  .page-header p { font-size: 14px; color: var(--muted); margin-top: 4px; }

  /* ─── TABLE VIEW ONLY ─── */
  .table-wrapper {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow-x: auto;
    margin-bottom: 32px;
  }
  .data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 900px;
  }
  .data-table th {
    text-align: left;
    padding: 14px 18px;
    background: var(--bg);
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    border-bottom: 1px solid var(--border);
  }
  .data-table td {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
    vertical-align: middle;
  }
  .data-table tr:hover { background: #fafbfd; }
  .data-table tr:last-child td { border-bottom: none; }

  /* Badges */
  .badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
  }
  .badge-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
  .badge.active { background: #e8f6f3; color: #1a5f57; border: 1px solid #9dd8d0; }
  .badge.active .badge-dot { background: var(--success); }
  .badge.pending { background: #fef8ec; color: #9a5f00; border: 1px solid #f5d98a; }
  .badge.pending .badge-dot { background: var(--warning); }
  .badge.invited { background: #f0f4ff; color: #3347bb; border: 1px solid #bcc7fa; }
  .badge.invited .badge-dot { background: var(--purple); }

  /* Progress bar */
  .progress-bar {
    width: 100px;
    height: 6px;
    background: var(--border);
    border-radius: 3px;
    overflow: hidden;
  }
  .progress-fill {
    height: 100%;
    background: var(--success);
    border-radius: 3px;
  }

  /* Buttons */
  .btn-primary {
    background: var(--navy); color: var(--gold-light);
    border: none; border-radius: var(--radius-sm);
    padding: 10px 22px; font-size: 13.5px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer;
    transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px;
  }
  .btn-primary:hover { background: var(--navy-mid); transform: translateY(-1px); box-shadow: var(--shadow); }
  .btn-secondary {
    background: none; color: var(--text-light);
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    padding: 9px 18px; font-size: 13px;
    font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s;
  }
  .btn-secondary:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }
  .action-btn {
    width: 32px; height: 32px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: var(--white);
    color: var(--muted);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    transition: all 0.15s;
    margin: 0 2px;
  }
  .action-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }

  /* Modal */
  .modal-backdrop {
    position: fixed; inset: 0;
    background: rgba(13,33,55,0.5); backdrop-filter: blur(4px);
    z-index: 200; display: none; align-items: center; justify-content: center;
  }
  .modal-backdrop.open { display: flex; }
  .modal {
    background: var(--white); border-radius: var(--radius);
    width: 100%; max-width: 600px; box-shadow: var(--shadow-md);
    animation: fadeUp 0.2s ease; max-height: 90vh; overflow-y: auto;
  }
  .modal.large {
    max-width: 1000px;
  }
  .modal-header {
    padding: 20px 24px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
  }
  .modal-title { font-family: 'Libre Baskerville', serif; font-size: 18px; color: var(--navy); }
  .modal-close {
    width: 30px; height: 30px; border-radius: var(--radius-sm);
    border: 1px solid var(--border); background: none;
    color: var(--muted); cursor: pointer; font-size: 14px;
    display: flex; align-items: center; justify-content: center;
  }
  .modal-close:hover { background: var(--bg); color: var(--navy); }
  .modal-body { padding: 24px; }
  .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; }

  /* Form */
  .form-group { margin-bottom: 20px; }
  .field-label {
    font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted);
    display: block; margin-bottom: 6px;
  }
  .field-required { color: var(--danger); margin-left: 3px; }
  .form-input, .form-select, .form-textarea {
    width: 100%;
    padding: 11px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    color: var(--text);
    background: var(--white);
    transition: all 0.15s;
    outline: none;
  }
  .form-input:focus, .form-select:focus, .form-textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.12);
  }
  .form-textarea { resize: vertical; min-height: 80px; }
  .notice-box {
    background: var(--bg); border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 12px 16px; font-size: 12.5px;
    color: var(--muted); line-height: 1.6;
    display: flex; gap: 10px; align-items: flex-start;
  }

  /* Assignment modal */
  .assign-list {
    max-height: 400px;
    overflow-y: auto;
  }
  .assign-item {
    display: flex;
    align-items: center;
    padding: 12px;
    border-bottom: 1px solid var(--border);
    gap: 12px;
  }
  .assign-item:hover { background: var(--bg); }
  .assign-checkbox { width: 20px; height: 20px; cursor: pointer; }
  .assign-info { flex: 1; }
  .assign-title { font-weight: 600; font-size: 13px; color: var(--navy); }
  .assign-meta { font-size: 11px; color: var(--muted); margin-top: 2px; }
  .assign-topic {
    background: var(--bg);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 10px;
    color: var(--text-light);
  }

  /* Reviewer count indicator */
  .reviewer-count {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
  }
  .reviewer-count.complete {
    background: #e8f6f3;
    color: var(--success);
  }
  .reviewer-count.incomplete {
    background: #fef8ec;
    color: var(--warning);
  }
  .reviewer-count.full {
    background: #fdf2f2;
    color: var(--danger);
  }

  .pagination {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 20px;
  }
  .page-btn {
    padding: 8px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--white);
    color: var(--text-light);
    cursor: pointer;
    font-size: 13px;
  }
  .page-btn:hover { border-color: var(--navy); color: var(--navy); }
  .page-btn.active { background: var(--navy); color: var(--gold-light); }
  .page-btn:disabled { opacity: 0.5; cursor: not-allowed; }

  .footer {
    background: var(--navy); color: rgba(255,255,255,0.45);
    text-align: center; padding: 22px; margin-top: 48px; font-size: 13px;
  }
  @keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }
  @media (max-width: 900px) {
    .topbar { padding: 0 16px; }
    .nav-links { display: none; }
    .page { padding: 24px 16px; }
  }

  /* Toast notification */
  .toast-container {
    position: fixed;
    top: 80px;
    right: 24px;
    z-index: 300;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .toast {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 14px 20px;
    box-shadow: var(--shadow-md);
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 300px;
    animation: slideIn 0.3s ease;
    font-size: 13px;
  }
  .toast.success { border-left: 4px solid var(--success); }
  .toast.error { border-left: 4px solid var(--danger); }
  .toast.info { border-left: 4px solid var(--accent); }
  @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

  /* Specialty tags */
  .specialty-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    background: var(--bg);
    color: var(--text-light);
    margin: 2px;
    border: 1px solid var(--border);
  }
</style>
</head>
<body>

<!-- TOAST CONTAINER -->
<div class="toast-container" id="toastContainer"></div>

<!-- TOPBAR -->
<header class="topbar">
  <a class="brand" href="dashboard.php">
    <div class="brand-icon"><i class="fas fa-clipboard-list"></i></div>
    <span class="brand-name">ConfManager</span>
  </a>
  <nav class="nav-links">
    <a href="conferences.php" class="nav-link">Conférences & Articles</a>
    <a href="evaluators.php" class="nav-link active">Évaluateurs</a>
    <a href="final_decisions.php" class="nav-link">Décision Finale</a>
    <a href="profile.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <div class="search-wrap">
      <span class="search-icon"><i class="fas fa-search"></i></span>
      <input type="text" class="search-input" id="searchEvaluator" placeholder="Rechercher un évaluateur...">
    </div>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</header>

<main class="page">
  <div class="page-header">
    <div class="page-header-row">
      <h1>Gestion <em>des évaluateurs</em></h1>
      <button class="btn-primary" id="openAddEvaluatorModal"><i class="fas fa-user-plus"></i> Ajouter un évaluateur</button>
    </div>
    <p>Gérez les évaluateurs et assignez-les aux articles (max 2 évaluateurs par article)</p>
  </div>

  <!-- TABLE VIEW ONLY -->
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr>
          <th>Évaluateur</th>
          <th>Contact</th>
          <th>Spécialités</th>
          <th>Statut</th>
          <th>Articles assignés</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="evaluatorTableBody"></tbody>
    </table>
  </div>

  <!-- PAGINATION -->
  <div class="pagination" id="pagination"></div>
</main>

<footer class="footer">© 2026 ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés</footer>

<!-- MODAL AJOUT ÉVALUATEUR -->
<div id="evaluatorModal" class="modal-backdrop">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="modalTitle">Ajouter un évaluateur</div>
      <button class="modal-close" onclick="closeEvaluatorModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form id="evaluatorForm">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          <div class="form-group">
            <label class="field-label">Prénom <span class="field-required">*</span></label>
            <input type="text" class="form-input" id="evalFirstName" placeholder="Prénom">
          </div>
          <div class="form-group">
            <label class="field-label">Nom <span class="field-required">*</span></label>
            <input type="text" class="form-input" id="evalLastName" placeholder="Nom">
          </div>
        </div>
        <div class="form-group">
          <label class="field-label">Adresse e-mail <span class="field-required">*</span></label>
          <input type="email" class="form-input" id="evalEmail" placeholder="prenom.nom@universite.dz">
        </div>
        <div class="form-group">
          <label class="field-label">Institution</label>
          <input type="text" class="form-input" id="evalInstitution" placeholder="Université / Centre de recherche">
        </div>
        <div class="form-group">
          <label class="field-label">Spécialités (séparées par des virgules)</label>
          <input type="text" class="form-input" id="evalSpecialties" placeholder="ex: IA, NLP, Machine Learning">
        </div>
        <div class="notice-box">
          <i class="fas fa-info-circle"></i>
          <span>Un email d'invitation sera envoyé à l'évaluateur pour l'informer de son rôle et lui donner accès à la plateforme. Mot de passe par défaut : <strong>evaluator123</strong></span>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeEvaluatorModal()">Annuler</button>
      <button class="btn-primary" id="saveEvaluatorBtn"><i class="fas fa-save"></i> Enregistrer et inviter</button>
    </div>
  </div>
</div>

<!-- MODAL ASSIGNER DES ARTICLES -->
<div id="assignModal" class="modal-backdrop">
  <div class="modal large">
    <div class="modal-header">
      <div class="modal-title">Assigner des articles à <span id="assignEvalName"></span></div>
      <button class="modal-close" onclick="closeAssignModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div style="margin-bottom: 16px; display: flex; gap: 12px; align-items: center;">
        <select class="sort-select" id="filterConference" style="flex: 1;">
          <option value="all">Toutes les conférences</option>
        </select>
        <select class="sort-select" id="filterTopic" style="flex: 1;">
          <option value="all">Tous les topics</option>
        </select>
      </div>

      <!-- Info box about 2 reviewer limit -->
      <div class="notice-box" style="background: #e0e7ff; border-color: #c7d2fe; margin-bottom: 16px;">
        <i class="fas fa-info-circle" style="color: var(--purple);"></i>
        <span><strong>Règle:</strong> Chaque article doit avoir exactement <strong>2 évaluateurs</strong>. Les articles ayant déjà 2 évaluateurs seront masqués.</span>
      </div>

      <div class="assign-list" id="assignList"></div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeAssignModal()">Annuler</button>
      <button class="btn-primary" id="confirmAssignBtn"><i class="fas fa-check"></i> Assigner les articles sélectionnés</button>
    </div>
  </div>
</div>

<script>
  // ==================== DATA FROM PHP ====================
  const evaluators = <?php echo $evaluatorsJson; ?>;
  const availableArticles = <?php echo $articlesJson; ?>;
  const conferences = <?php echo $conferencesJson; ?>;
  const topics = <?php echo $topicsJson; ?>;

  // ==================== STATE ====================
  let currentPage = 1;
  let itemsPerPage = 10;
  let currentEvaluatorId = null;
  let assignFilterConference = 'all';
  let assignFilterTopic = 'all';
  let searchTerm = '';

  // ==================== UTILITY FUNCTIONS ====================

  function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', info: 'fa-info-circle' };
    const colors = { success: 'var(--success)', error: 'var(--danger)', info: 'var(--accent)' };

    toast.innerHTML = `
      <i class="fas ${icons[type]}" style="color: ${colors[type]}; font-size: 18px;"></i>
      <span>${message}</span>
    `;

    container.appendChild(toast);
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateX(100%)';
      setTimeout(() => toast.remove(), 300);
    }, 4000);
  }

  function getStatusBadge(status) {
    const badges = {
      active: '<span class="badge active"><span class="badge-dot"></span>Actif</span>',
      pending: '<span class="badge pending"><span class="badge-dot"></span>En attente</span>',
      invited: '<span class="badge invited"><span class="badge-dot"></span>Invité</span>'
    };
    return badges[status] || badges.invited;
  }

  function getReviewerCountBadge(article) {
    const count = article.reviewer_count || 0;
    if (count >= 2) {
      return `<span class="reviewer-count full"><i class="fas fa-check-circle"></i> Complet (${count}/2)</span>`;
    } else if (count === 1) {
      return `<span class="reviewer-count incomplete"><i class="fas fa-user-clock"></i> 1/2 évaluateurs</span>`;
    } else {
      return `<span class="reviewer-count incomplete"><i class="fas fa-user-plus"></i> 0/2 évaluateurs</span>`;
    }
  }

  function formatDate(dateStr) {
    if (!dateStr) return 'N/A';
    return new Date(dateStr).toLocaleDateString('fr-FR');
  }

  // ==================== RENDER FUNCTIONS ====================

  function getFilteredEvaluators() {
    let filtered = [...evaluators];

    if (searchTerm) {
      const term = searchTerm.toLowerCase();
      filtered = filtered.filter(e => 
        (e.first_name + ' ' + e.last_name).toLowerCase().includes(term) || 
        e.email.toLowerCase().includes(term) ||
        (e.specialties && e.specialties.toLowerCase().includes(term))
      );
    }

    // Sort by name
    filtered.sort((a, b) => {
      const nameA = (a.last_name + ' ' + a.first_name).toLowerCase();
      const nameB = (b.last_name + ' ' + b.first_name).toLowerCase();
      return nameA.localeCompare(nameB);
    });

    return filtered;
  }

  function renderTable() {
    let filtered = getFilteredEvaluators();
    const start = (currentPage - 1) * itemsPerPage;
    const paginated = filtered.slice(start, start + itemsPerPage);

    let html = '';
    paginated.forEach(eval_ => {
      const fullName = (eval_.first_name && eval_.last_name) ? 
        `${eval_.first_name} ${eval_.last_name}` : (eval_.name || 'N/A');

      // Get assigned articles info
      let articlesHtml = '';
      if (eval_.assigned_count > 0) {
        const completed = eval_.completed_count || 0;
        const total = eval_.assigned_count || 0;
        const progress = total > 0 ? (completed / total) * 100 : 0;

        articlesHtml = `
          <div style="display: flex; flex-direction: column; gap: 6px;">
            <div style="font-size: 12px;">
              <strong>${total}</strong> article(s) assigné(s)
            </div>
            <div class="progress-bar">
              <div class="progress-fill" style="width: ${progress}%"></div>
            </div>
            <div style="font-size: 11px; color: var(--muted);">${completed}/${total} évaluations terminées</div>
          </div>
        `;
      } else {
        articlesHtml = '<span style="color: var(--muted); font-size: 12px;">Aucun article assigné</span>';
      }

      // Format specialties
      let specialtiesHtml = '';
      if (eval_.specialties) {
        const specs = eval_.specialties.split(',').map(s => s.trim()).filter(s => s);
        specialtiesHtml = specs.map(s => `<span class="specialty-tag">${s}</span>`).join('');
      } else {
        specialtiesHtml = '<span style="color: var(--muted); font-size: 11px;">Non spécifié</span>';
      }

      html += `
        <tr>
          <td>
            <strong>${fullName}</strong><br>
            <span style="font-size:11px; color:var(--muted);">${eval_.institution || 'Institution non spécifiée'}</span>
          </td>
          <td style="font-size:12px;">
            <i class="fas fa-envelope" style="color: var(--muted); margin-right: 4px;"></i>${eval_.email}<br>
            ${eval_.phone ? `<i class="fas fa-phone" style="color: var(--muted); margin-right: 4px;"></i>${eval_.phone}` : ''}
          </td>
          <td>${specialtiesHtml}</td>
          <td>${getStatusBadge(eval_.status)}</td>
          <td>${articlesHtml}</td>
          <td>
            ${eval_.status === 'active' ? 
              `<button class="action-btn" onclick="assignArticles(${eval_.id}, '${fullName.replace(/'/g, "\'")}')" title="Assigner des articles"><i class="fas fa-tasks"></i></button>` :
              `<span style="font-size: 11px; color: var(--muted);">Non disponible</span>`
            }
          </td>
        </tr>
      `;
    });

    document.getElementById('evaluatorTableBody').innerHTML = html || '<tr><td colspan="6" style="text-align:center; padding:40px;"><i class="fas fa-users" style="font-size: 32px; color: var(--border); margin-bottom: 12px; display: block;"></i>Aucun évaluateur trouvé</td></tr>';

    const totalPages = Math.ceil(filtered.length / itemsPerPage);
    renderPagination(totalPages);
  }

  function renderPagination(totalPages) {
    const paginationDiv = document.getElementById('pagination');
    if (totalPages <= 1) {
      paginationDiv.innerHTML = '';
      return;
    }

    let html = '';
    html += `<button class="page-btn" onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}><i class="fas fa-chevron-left"></i></button>`;

    for (let i = 1; i <= Math.min(totalPages, 5); i++) {
      html += `<button class="page-btn ${currentPage === i ? 'active' : ''}" onclick="changePage(${i})">${i}</button>`;
    }

    if (totalPages > 5) {
      html += `<span style="padding:8px;">...</span>`;
      html += `<button class="page-btn" onclick="changePage(${totalPages})">${totalPages}</button>`;
    }

    html += `<button class="page-btn" onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}><i class="fas fa-chevron-right"></i></button>`;

    paginationDiv.innerHTML = html;
  }

  function render() {
    renderTable();
  }

  function changePage(page) {
    currentPage = page;
    render();
  }

  // ==================== MODAL FUNCTIONS ====================

  function openEvaluatorModal() {
    document.getElementById('evaluatorForm').reset();
    document.getElementById('evaluatorModal').classList.add('open');
  }

  function closeEvaluatorModal() {
    document.getElementById('evaluatorModal').classList.remove('open');
  }

  async function saveEvaluator() {
    const firstName = document.getElementById('evalFirstName').value.trim();
    const lastName = document.getElementById('evalLastName').value.trim();
    const email = document.getElementById('evalEmail').value.trim();
    const institution = document.getElementById('evalInstitution').value.trim();
    const specialties = document.getElementById('evalSpecialties').value.trim();

    if (!firstName || !lastName || !email) {
      showToast('Veuillez remplir tous les champs obligatoires.', 'error');
      return;
    }

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
      showToast('Veuillez entrer une adresse email valide.', 'error');
      return;
    }

    const formData = new FormData();
    formData.append('action', 'add_evaluator');
    formData.append('first_name', firstName);
    formData.append('last_name', lastName);
    formData.append('email', email);
    formData.append('institution', institution);
    formData.append('specialties', specialties);

    try {
      const response = await fetch('evaluators.php', {
        method: 'POST',
        body: formData
      });
      const result = await response.json();

      if (result.success) {
        let msg = result.message;
        if (result.temp_password) {
          msg += ` Mot de passe temporaire : <strong>${result.temp_password}</strong> (code: ${result.reviewer_code})`;
        }
        showToast(msg, 'success');
        // Add to local array and refresh
        evaluators.push({
          id: result.id,
          first_name: firstName,
          last_name: lastName,
          email: email,
          institution: institution,
          specialties: specialties,
          status: 'active',
          assigned_count: 0,
          completed_count: 0
        });
        render();
        closeEvaluatorModal();
      } else {
        showToast(result.message, 'error');
      }
    } catch (error) {
      showToast('Erreur lors de l\'ajout de l\'évaluateur.', 'error');
      console.error(error);
    }
  }

  // ==================== ASSIGNMENT FUNCTIONS ====================

  function populateAssignmentFilters() {
    const conferenceSelect = document.getElementById('filterConference');
    conferenceSelect.innerHTML = '<option value="all">Toutes les conférences</option>';
    conferences.forEach(conf => {
      conferenceSelect.innerHTML += `<option value="${conf.id}">${conf.name_fr}</option>`;
    });

    const topicSelect = document.getElementById('filterTopic');
    topicSelect.innerHTML = '<option value="all">Tous les topics</option>';
    topics.forEach(topic => {
      topicSelect.innerHTML += `<option value="${topic.name}">${topic.name}</option>`;
    });
  }

  function assignArticles(id, name) {
    const evaluator = evaluators.find(e => e.id === id);
    if (!evaluator) return;

    if (evaluator.status !== 'active') {
      showToast(`L'évaluateur ${name} doit être actif pour recevoir des assignations.`, 'error');
      return;
    }

    currentEvaluatorId = id;
    document.getElementById('assignEvalName').textContent = name;

    populateAssignmentFilters();
    renderAssignmentList();

    document.getElementById('assignModal').classList.add('open');
  }

  function renderAssignmentList() {
    const evaluator = evaluators.find(e => e.id === currentEvaluatorId);
    if (!evaluator) return;

    // Filter articles - HIDE those with 2 reviewers already
    let filteredArticles = availableArticles.filter(a => {
      const reviewerCount = a.reviewer_count || 0;
      if (reviewerCount >= 2) return false;

      if (a.status === 'accepted' || a.status === 'rejected') return false;

      if (assignFilterConference !== 'all' && a.conference_id !== parseInt(assignFilterConference)) return false;

      if (assignFilterTopic !== 'all' && a.topic_name !== assignFilterTopic) return false;

      return true;
    });

    // Sort by relevance to evaluator specialties
    const evaluatorSpecs = evaluator.specialties ? evaluator.specialties.split(',').map(s => s.trim().toLowerCase()) : [];
    filteredArticles.sort((a, b) => {
      const aMatch = evaluatorSpecs.some(s => 
        (a.topic_name && a.topic_name.toLowerCase().includes(s)) || 
        (a.title && a.title.toLowerCase().includes(s))
      );
      const bMatch = evaluatorSpecs.some(s => 
        (b.topic_name && b.topic_name.toLowerCase().includes(s)) || 
        (b.title && b.title.toLowerCase().includes(s))
      );
      return bMatch - aMatch;
    });

    let html = '';
    if (filteredArticles.length === 0) {
      html = `<div style="text-align: center; padding: 40px; color: var(--muted);">
        <i class="fas fa-check-circle" style="font-size: 48px; color: var(--success); margin-bottom: 16px; display: block;"></i>
        Aucun article disponible pour assignation.<br>
        <small>Tous les articles ont déjà leurs 2 évaluateurs ou ne correspondent pas aux filtres.</small>
      </div>`;
    } else {
      filteredArticles.forEach(article => {
        const reviewerCount = article.reviewer_count || 0;

        // Check if matches evaluator specialties
        const matchesSpecialty = evaluatorSpecs.some(s => 
          (article.topic_name && article.topic_name.toLowerCase().includes(s)) || 
          (article.title && article.title.toLowerCase().includes(s))
        );

        let statusBadge = '';
        if (reviewerCount === 1) {
          statusBadge = '<span style="color: var(--warning); font-size: 11px;"><i class="fas fa-exclamation-circle"></i> Il manque 1 évaluateur</span>';
        } else {
          statusBadge = '<span style="color: var(--purple); font-size: 11px;"><i class="fas fa-user-plus"></i> Aucun évaluateur</span>';
        }

        html += `
          <div class="assign-item" style="${matchesSpecialty ? 'background: rgba(44,111,173,0.05);' : ''}">
            <input type="checkbox" class="assign-checkbox" data-article-id="${article.id}">
            <div class="assign-info">
              <div class="assign-title">${article.title}</div>
              <div class="assign-meta">
                <i class="fas fa-user"></i> ${article.author || 'Auteur inconnu'} · 
                <i class="fas fa-calendar"></i> ${formatDate(article.submission_date)} · 
                <span class="assign-topic">${article.topic_name || 'Topic non défini'}</span>
              </div>
              <div style="margin-top: 4px; font-size: 11px; color: var(--muted);">
                <i class="fas fa-folder"></i> ${article.conference_name || 'Conférence inconnue'} · 
                <span style="color: ${reviewerCount === 0 ? 'var(--purple)' : 'var(--warning)'}; font-weight: 600;">
                  ${reviewerCount}/2 évaluateurs
                </span>
              </div>
            </div>
            <div style="text-align: right; min-width: 140px;">
              ${statusBadge}
              ${matchesSpecialty ? '<div style="margin-top: 4px; font-size: 10px; color: var(--accent);"><i class="fas fa-star"></i> Correspond à vos spécialités</div>' : ''}
            </div>
          </div>
        `;
      });
    }

    document.getElementById('assignList').innerHTML = html;
  }

  function closeAssignModal() {
    document.getElementById('assignModal').classList.remove('open');
    currentEvaluatorId = null;
    assignFilterConference = 'all';
    assignFilterTopic = 'all';
  }

  async function confirmAssign() {
    if (!currentEvaluatorId) return;

    const evaluator = evaluators.find(e => e.id === currentEvaluatorId);
    if (!evaluator) return;

    const selectedIds = [];
    document.querySelectorAll('.assign-checkbox:checked:not(:disabled)').forEach(cb => {
      selectedIds.push(parseInt(cb.dataset.articleId));
    });

    if (selectedIds.length === 0) {
      showToast('Veuillez sélectionner au moins un article.', 'error');
      return;
    }

    const formData = new FormData();
    formData.append('action', 'assign_articles');
    formData.append('evaluator_id', currentEvaluatorId);
    formData.append('article_ids', JSON.stringify(selectedIds));

    try {
      const response = await fetch('evaluators.php', {
        method: 'POST',
        body: formData
      });
      const result = await response.json();

      if (result.success) {
        let message = `${result.assigned} article(s) assigné(s) avec succès.`;
        if (result.skipped && result.skipped.length > 0) {
          message += ` ${result.skipped.length} article(s) ignoré(s) (déjà 2 évaluateurs).`;
        }
        showToast(message, 'success');

        // Refresh data
        setTimeout(() => window.location.reload(), 1500);
      } else {
        showToast(result.message, 'error');
      }
    } catch (error) {
      showToast('Erreur lors de l\'assignation.', 'error');
      console.error(error);
    }

    closeAssignModal();
  }

  // ==================== EVENT LISTENERS ====================

  // Event listeners - using {once: true} for save buttons to prevent double submission
  document.getElementById('openAddEvaluatorModal').addEventListener('click', openEvaluatorModal);

  // Use a flag to prevent double submission
  let isSaving = false;
  document.getElementById('saveEvaluatorBtn').addEventListener('click', async function(e) {
    e.preventDefault();
    e.stopPropagation();
    if (isSaving) return;
    isSaving = true;
    await saveEvaluator();
    isSaving = false;
  });

  let isAssigning = false;
  document.getElementById('confirmAssignBtn').addEventListener('click', async function(e) {
    e.preventDefault();
    e.stopPropagation();
    if (isAssigning) return;
    isAssigning = true;
    await confirmAssign();
    isAssigning = false;
  });

  document.getElementById('filterConference').addEventListener('change', (e) => {
    assignFilterConference = e.target.value;
    renderAssignmentList();
  });

  document.getElementById('filterTopic').addEventListener('change', (e) => {
    assignFilterTopic = e.target.value;
    renderAssignmentList();
  });

  document.getElementById('searchEvaluator').addEventListener('input', (e) => {
    searchTerm = e.target.value.toLowerCase();
    currentPage = 1;
    render();
  });

  document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', (e) => {
      if (e.target === backdrop) {
        backdrop.classList.remove('open');
      }
    });
  });

  // Keyboard shortcuts
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open'));
    }
  });

  // ==================== INIT ====================

  window.assignArticles = assignArticles;
  window.changePage = changePage;
  window.closeEvaluatorModal = closeEvaluatorModal;
  window.closeAssignModal = closeAssignModal;
  window.confirmAssign = confirmAssign;

  render();
</script>
</body>
</html>