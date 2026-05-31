<?php
require_once 'config.php';
requireRole('gestionnaire');

$user_id = getCurrentUserId();

// Handle AJAX request to submit final decision
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_decision') {
    header('Content-Type: application/json');
    
    $article_id = intval($_POST['article_id']);
    $decision = $_POST['decision'];
    $comment = trim($_POST['comment']);
    
    // Validate decision
    $valid_decisions = ['accepted', 'revision', 'rejected'];
    if (!in_array($decision, $valid_decisions)) {
        echo json_encode(['success' => false, 'message' => 'Décision invalide']);
        exit;
    }
    
    if (strlen($comment) < 10) {
        echo json_encode(['success' => false, 'message' => 'Le commentaire doit contenir au moins 10 caractères']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update article with final decision
        $stmt = $pdo->prepare("
            UPDATE articles 
            SET status = CASE 
                WHEN ? = 'accepted' THEN 'accepted'
                WHEN ? = 'revision' THEN 'review'
                ELSE 'rejected'
            END,
            final_decision = ?,
            final_comment = ?,
            decision_date = NOW(),
            decided_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$decision, $decision, $decision, $comment, $user_id, $article_id]);
        
        // Get article and author info for email
        $stmt = $pdo->prepare("
            SELECT a.*, u.email as author_email, u.first_name, u.last_name, c.name_fr as conference_name
            FROM articles a
            JOIN users u ON a.author_email = u.email
            JOIN conferences c ON a.conference_id = c.id
            WHERE a.id = ?
        ");
        $stmt->execute([$article_id]);
        $article = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($article) {
            // Send email notification to researcher
            $decision_texts = [
                'accepted' => 'accepté pour publication',
                'revision' => 'soumis à révisions',
                'rejected' => 'refusé'
            ];
            
            $to = $article['author_email'];
            $subject = "Décision finale - " . $article['title'];
            $message = "
                <html>
                <head><title>Décision finale</title></head>
                <body>
                    <h2>Cher(e) " . htmlspecialchars($article['first_name'] . ' ' . $article['last_name']) . ",</h2>
                    <p>Votre article soumis à la conférence <strong>" . htmlspecialchars($article['conference_name']) . "</strong> a été <strong>" . $decision_texts[$decision] . "</strong>.</p>
                    <p><strong>Titre :</strong> " . htmlspecialchars($article['title']) . "</p>
                    <p><strong>Commentaire du gestionnaire :</strong></p>
                    <blockquote>" . nl2br(htmlspecialchars($comment)) . "</blockquote>
                    <p>Cordialement,<br>L'équipe ConfManager</p>
                </body>
                </html>
            ";
            
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= 'From: confmanager@univ-chlef.dz' . "\r\n";
            
            @mail($to, $subject, $message, $headers);
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Décision enregistrée et notification envoyée']);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
    exit;
}

// Fetch articles pending final decision (have 2 completed reviews but no final decision)
$stmt = $pdo->prepare("
    SELECT 
        a.*,
        c.name_fr as conference_name,
        t.name as topic_name,
        COUNT(r.id) as review_count
    FROM articles a
    JOIN conferences c ON a.conference_id = c.id
    LEFT JOIN topics t ON a.topic_id = t.id
    LEFT JOIN reviews r ON a.id = r.article_id
    WHERE a.status = 'assigned' 
    OR (a.status = 'review' AND a.final_decision IS NULL)
    GROUP BY a.id
    HAVING review_count >= 2
    ORDER BY a.submission_date DESC
");
$stmt->execute();
$pendingArticles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch completed decisions
$stmt = $pdo->prepare("
    SELECT 
        a.*,
        c.name_fr as conference_name,
        u.first_name as decided_by_first,
        u.last_name as decided_by_last
    FROM articles a
    JOIN conferences c ON a.conference_id = c.id
    LEFT JOIN users u ON a.decided_by = u.id
    WHERE a.final_decision IS NOT NULL
    ORDER BY a.decision_date DESC
");
$stmt->execute();
$completedDecisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch reviews for each pending article
$articleReviews = [];
foreach ($pendingArticles as $article) {
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            u.first_name,
            u.last_name,
            u.grade
        FROM reviews r
        JOIN users u ON r.evaluator_id = u.id
        WHERE r.article_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$article['id']]);
    $articleReviews[$article['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Stats
$pendingCount = count($pendingArticles);
$completedCount = count($completedDecisions);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Décisions finales</title>
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

  /* ─── STATS ROW ─── */
  .stats-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 32px;
    max-width: 600px;
  }
  .stat-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px 20px;
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 14px;
  }
  .stat-icon {
    width: 44px; height: 44px; border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
  }
  .stat-icon.pending { background: rgba(212,131,10,0.1); color: var(--warning); }
  .stat-icon.completed { background: rgba(42,157,143,0.1); color: var(--success); }
  .stat-value { font-size: 22px; font-weight: 700; color: var(--navy); line-height: 1; margin-bottom: 2px; }
  .stat-label { font-size: 12px; color: var(--muted); }

  /* ─── TABLE VIEW ─── */
  .table-wrapper {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow-x: auto;
    margin-bottom: 24px;
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

  .article-title {
    font-weight: 600;
    color: var(--navy);
    margin-bottom: 4px;
  }
  .article-meta {
    font-size: 11px;
    color: var(--muted);
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .tag {
    background: var(--bg);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 10px;
    color: var(--text-light);
  }

  /* Actions */
  .actions { display: flex; gap: 6px; }
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
  }
  .action-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }
  .btn-decision {
    padding: 8px 16px;
    background: var(--navy);
    color: var(--gold-light);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s;
  }
  .btn-decision:hover { background: var(--navy-mid); transform: translateY(-1px); box-shadow: var(--shadow); }

  /* Modal */
  .modal-backdrop {
    position: fixed; inset: 0;
    background: rgba(13,33,55,0.5); backdrop-filter: blur(4px);
    z-index: 200; display: none; align-items: center; justify-content: center;
  }
  .modal-backdrop.open { display: flex; }
  .modal {
    background: var(--white); border-radius: var(--radius);
    width: 100%; max-width: 800px; box-shadow: var(--shadow-md);
    animation: fadeUp 0.2s ease; max-height: 90vh; overflow-y: auto;
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

  /* Article detail inside modal */
  .article-detail-header {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%);
    color: white;
    padding: 20px;
    border-radius: var(--radius);
    margin-bottom: 24px;
  }
  .article-detail-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 8px;
    font-family: 'Libre Baskerville', serif;
  }
  .article-detail-meta {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    font-size: 12px;
    opacity: 0.9;
  }
  .info-card {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px;
    margin-bottom: 20px;
  }
  .info-card-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--navy);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .review-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 16px;
    margin-bottom: 12px;
  }
  .review-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    font-weight: 600;
    color: var(--navy);
  }
  .review-comment {
    font-size: 13px;
    color: var(--text-light);
    line-height: 1.5;
    margin-bottom: 8px;
  }
  .review-recommendation {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    margin-top: 8px;
  }
  .rec-accept { background: #e8f6f3; color: #1a5f57; }
  .rec-minor { background: #f0f4ff; color: #3347bb; }
  .rec-major { background: #fef8ec; color: #9a5f00; }
  .rec-reject { background: #fdf2f2; color: #8b2020; }
  
  .decision-options {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 20px;
  }
  .decision-option {
    padding: 16px;
    border: 2px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
    text-align: center;
    transition: all 0.15s;
  }
  .decision-option:hover {
    border-color: var(--gold);
    background: #fef9ed;
  }
  .decision-option.selected {
    border-color: var(--navy);
    background: #f0f4ff;
  }
  .decision-option i {
    font-size: 24px;
    margin-bottom: 8px;
    display: block;
  }
  .decision-option.accept { color: var(--success); }
  .decision-option.revision { color: var(--purple); }
  .decision-option.reject { color: var(--danger); }
  .decision-option span {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
  }
  
  .form-group { margin-bottom: 20px; }
  .field-label {
    font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted);
    display: block; margin-bottom: 8px;
  }
  .form-textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    color: var(--text);
    background: var(--white);
    resize: vertical;
    min-height: 120px;
    outline: none;
  }
  .form-textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(44,111,173,0.12); }
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

  /* Empty state */
  .empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--muted);
  }
  .empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    color: var(--border);
  }
  .empty-state h3 {
    font-size: 18px;
    color: var(--navy);
    margin-bottom: 8px;
  }

  .footer {
    background: var(--navy); color: rgba(255,255,255,0.45);
    text-align: center; padding: 22px; margin-top: 48px; font-size: 13px;
  }
  @keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }
  @media (max-width: 900px) {
    .stats-row { grid-template-columns: 1fr; }
    .decision-options { grid-template-columns: 1fr; }
    .topbar { padding: 0 16px; }
    .nav-links { display: none; }
    .page { padding: 24px 16px; }
  }
  
  /* Toast notification */
  .toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: var(--navy);
    color: white;
    padding: 14px 20px;
    border-radius: var(--radius);
    box-shadow: var(--shadow-md);
    z-index: 300;
    display: none;
    align-items: center;
    gap: 10px;
    animation: slideIn 0.3s ease;
  }
  .toast.show { display: flex; }
  .toast.success { background: var(--success); }
  .toast.error { background: var(--danger); }
  @keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
  }
</style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
  <a class="brand" href="#">
    <div class="brand-icon">📋</div>
    <span class="brand-name">ConfManager</span>
  </a>
  <nav class="nav-links">
    <a href="conferences.php" class="nav-link">Conférences & Articles</a>
    <a href="evaluators.php" class="nav-link">Évaluateurs</a>
    <a href="final_decisions.php" class="nav-link active">Décisions finales</a>
    <a href="profile.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <div class="search-wrap">
      <span class="search-icon">🔍</span>
      <input type="text" class="search-input" id="searchDecision" placeholder="Rechercher un article...">
    </div>
    <a href="logout.php" class="logout-btn"><span>↪</span> Déconnexion</a>
  </div>
</header>

<main class="page">
  <div class="page-header">
    <div class="page-header-row">
      <h1>Décisions <em>finales</em></h1>
    </div>
    <p>Articles en attente de décision finale après évaluation des relecteurs</p>
  </div>

  <!-- STATISTIQUES -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-icon pending"><i class="fas fa-hourglass-half"></i></div>
      <div>
        <div class="stat-value" id="pendingCount"><?php echo $pendingCount; ?></div>
        <div class="stat-label">En attente de décision</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon completed"><i class="fas fa-check-circle"></i></div>
      <div>
        <div class="stat-value" id="completedCount"><?php echo $completedCount; ?></div>
        <div class="stat-label">Décisions prises</div>
      </div>
    </div>
  </div>

  <!-- TABLEAU DES ARTICLES -->
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr>
          <th>Article / Auteur</th>
          <th>Conférence</th>
          <th>Soumission</th>
          <th>Évaluations</th>
          <th>Recommandation</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="decisionsTableBody">
        <?php if (empty($pendingArticles)): ?>
          <tr>
            <td colspan="6">
              <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h3>Toutes les décisions ont été prises</h3>
                <p>Aucun article en attente de décision finale.</p>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($pendingArticles as $article): 
            $reviews = $articleReviews[$article['id']] ?? [];
            $consensus = getConsensusRecommendation($reviews);
          ?>
            <tr data-article-id="<?php echo $article['id']; ?>">
              <td>
                <div class="article-title"><?php echo htmlspecialchars($article['title']); ?></div>
                <div class="article-meta">
                  <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($article['author']); ?></span>
                  <span class="tag"><?php echo htmlspecialchars($article['conference_name']); ?></span>
                  <?php if ($article['topic_name']): ?>
                    <span class="tag"><?php echo htmlspecialchars($article['topic_name']); ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td><?php echo htmlspecialchars($article['conference_name']); ?></td>
              <td style="font-size:12px; color:var(--muted);">
                <?php echo date('d/m/Y', strtotime($article['submission_date'])); ?>
              </td>
              <td>
                <span class="review-recommendation rec-accept">
                  <i class="fas fa-check-circle"></i> <?php echo count($reviews); ?>/2 reçues
                </span>
              </td>
              <td>
                <span class="review-recommendation <?php echo getRecommendationClass($consensus); ?>">
                  <?php echo getRecommendationText($consensus); ?>
                </span>
              </td>
              <td>
                <button class="btn-decision" onclick="openDecisionModal(<?php echo $article['id']; ?>)">
                  <i class="fas fa-gavel"></i> Décider
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  
  <?php if (!empty($completedDecisions)): ?>
  <h2 style="font-family: 'Libre Baskerville', serif; font-size: 24px; color: var(--navy); margin-bottom: 20px; margin-top: 40px;">
    Décisions <em style="color: var(--gold);">passées</em>
  </h2>
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr>
          <th>Article</th>
          <th>Conférence</th>
          <th>Décision</th>
          <th>Date</th>
          <th>Par</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($completedDecisions as $decision): ?>
          <tr>
            <td>
              <div class="article-title"><?php echo htmlspecialchars($decision['title']); ?></div>
              <div class="article-meta">
                <span><?php echo htmlspecialchars($decision['author']); ?></span>
              </div>
            </td>
            <td><?php echo htmlspecialchars($decision['conference_name']); ?></td>
            <td>
              <span class="review-recommendation <?php echo getDecisionClass($decision['final_decision']); ?>">
                <?php echo getDecisionText($decision['final_decision']); ?>
              </span>
            </td>
            <td style="font-size:12px; color:var(--muted);">
              <?php echo $decision['decision_date'] ? date('d/m/Y', strtotime($decision['decision_date'])) : '-'; ?>
            </td>
            <td>
              <?php echo htmlspecialchars(($decision['decided_by_first'] ?? '') . ' ' . ($decision['decided_by_last'] ?? '')); ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</main>

<footer class="footer">© 2026 ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés</footer>

<!-- MODAL DÉCISION FINALE -->
<div id="decisionModal" class="modal-backdrop">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Décision finale</div>
      <button class="modal-close" onclick="closeDecisionModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="decisionModalBody"></div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeDecisionModal()">Annuler</button>
      <button class="btn-primary" id="submitDecisionBtn" onclick="submitDecision()">
        <i class="fas fa-paper-plane"></i> Envoyer à l'auteur
      </button>
    </div>
  </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="toast">
  <i class="fas fa-check-circle"></i>
  <span id="toastMessage"></span>
</div>

<script>
  // Article data from PHP
  const articlesData = <?php echo json_encode($pendingArticles); ?>;
  const reviewsData = <?php echo json_encode($articleReviews); ?>;
  
  let currentArticleId = null;
  let selectedDecision = null;

  function getRecommendationText(rec) {
    const texts = {
      accept: 'Accepter',
      minor_revision: 'Révisions mineures',
      major_revision: 'Révisions majeures',
      reject: 'Refuser'
    };
    return texts[rec] || rec;
  }

  function getRecommendationClass(rec) {
    const classes = {
      accept: 'rec-accept',
      minor_revision: 'rec-minor',
      major_revision: 'rec-major',
      reject: 'rec-reject'
    };
    return classes[rec] || 'rec-minor';
  }

  function getConsensusRecommendation(evaluations) {
    if (!evaluations || evaluations.length === 0) return null;
    
    const counts = {};
    evaluations.forEach(e => {
      counts[e.recommendation] = (counts[e.recommendation] || 0) + 1;
    });
    
    if (counts.reject >= 1) return 'reject';
    if (counts.major_revision >= 1) return 'major_revision';
    if (counts.minor_revision >= 2) return 'minor_revision';
    if (counts.accept >= 1) return 'accept';
    return 'minor_revision';
  }

  function openDecisionModal(id) {
    const article = articlesData.find(a => a.id == id);
    if (!article) return;
    
    const reviews = reviewsData[id] || [];
    currentArticleId = id;
    selectedDecision = null;
    
    // Build evaluations summary
    let evaluationsHtml = '';
    reviews.forEach(review => {
      evaluationsHtml += `
        <div class="review-card">
          <div class="review-header">
            <span><i class="fas fa-user-check"></i> ${review.first_name} ${review.last_name}${review.grade ? ' (' + review.grade + ')' : ''}</span>
            <span style="font-size:10px; color:var(--muted);">${new Date(review.created_at).toLocaleDateString('fr-FR')}</span>
          </div>
          <div class="review-comment">${review.comment}</div>
          <span class="review-recommendation ${getRecommendationClass(review.recommendation)}">
            ${getRecommendationText(review.recommendation)}
          </span>
        </div>
      `;
    });
    
    const consensus = getConsensusRecommendation(reviews);
    
    let html = `
      <div class="article-detail-header">
        <div class="article-detail-title">${article.title}</div>
        <div class="article-detail-meta">
          <span><i class="fas fa-user"></i> ${article.author}</span>
          <span><i class="fas fa-envelope"></i> ${article.author_email}</span>
          <span><i class="fas fa-calendar"></i> Soumis le ${new Date(article.submission_date).toLocaleDateString('fr-FR')}</span>
          <span><i class="fas fa-building"></i> ${article.conference_name}</span>
        </div>
      </div>
      
      <div class="info-card">
        <div class="info-card-title"><i class="fas fa-comments"></i> Évaluations des relecteurs</div>
        ${evaluationsHtml || '<p style="color:var(--muted); font-size:13px;">Aucune évaluation disponible</p>'}
      </div>
      
      <div class="info-card" style="background:#fef9ed; border-color:var(--gold-light);">
        <div class="info-card-title"><i class="fas fa-balance-scale"></i> Consensus des évaluateurs</div>
        <div style="padding:12px; background:var(--white); border-radius:var(--radius-sm);">
          <span class="review-recommendation ${getRecommendationClass(consensus)}">
            ${getRecommendationText(consensus)}
          </span>
          <div style="font-size:12px; color:var(--muted); margin-top:8px;">
            Recommandation basée sur ${reviews.length} évaluation(s)
          </div>
        </div>
      </div>
      
      <div class="info-card">
        <div class="info-card-title"><i class="fas fa-gavel"></i> Votre décision finale</div>
        <div style="font-size:12px; color:var(--muted); margin-bottom:12px;">
          Sélectionnez une décision et rédigez un commentaire pour l'auteur.
        </div>
        
        <div class="decision-options">
          <div class="decision-option accept" onclick="selectDecision('accepted')">
            <i class="fas fa-check-circle"></i>
            <span>Accepter</span>
          </div>
          <div class="decision-option revision" onclick="selectDecision('revision')">
            <i class="fas fa-pencil-alt"></i>
            <span>Révisions</span>
          </div>
          <div class="decision-option reject" onclick="selectDecision('rejected')">
            <i class="fas fa-times-circle"></i>
            <span>Refuser</span>
          </div>
        </div>
        
        <div class="form-group">
          <label class="field-label">Commentaire à l'auteur</label>
          <textarea class="form-textarea" id="decisionComment" placeholder="Expliquez votre décision à l'auteur..."></textarea>
          <div style="font-size:11px; color:var(--muted); margin-top:4px;">
            <i class="fas fa-envelope"></i> Ce message sera envoyé à ${article.author_email}
          </div>
        </div>
      </div>
    `;
    
    document.getElementById('decisionModalBody').innerHTML = html;
    document.getElementById('decisionModal').classList.add('open');
  }

  function selectDecision(decision) {
    selectedDecision = decision;
    document.querySelectorAll('.decision-option').forEach(opt => opt.classList.remove('selected'));
    document.querySelector(`.decision-option.${decision === 'accepted' ? 'accept' : decision === 'revision' ? 'revision' : 'reject'}`).classList.add('selected');
  }

  function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    const toastMessage = document.getElementById('toastMessage');
    toast.className = `toast ${type} show`;
    toastMessage.textContent = message;
    
    setTimeout(() => {
      toast.classList.remove('show');
    }, 4000);
  }

  function submitDecision() {
    if (!selectedDecision) {
      showToast('Veuillez sélectionner une décision.', 'error');
      return;
    }
    
    const comment = document.getElementById('decisionComment').value;
    if (!comment || comment.trim().length < 10) {
      showToast('Veuillez rédiger un commentaire pour l\'auteur (minimum 10 caractères).', 'error');
      return;
    }
    
    const submitBtn = document.getElementById('submitDecisionBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi en cours...';
    
    // Send AJAX request
    const formData = new FormData();
    formData.append('action', 'submit_decision');
    formData.append('article_id', currentArticleId);
    formData.append('decision', selectedDecision);
    formData.append('comment', comment);
    
    fetch('final_decisions.php', {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showToast(data.message, 'success');
        
        // Remove article from table
        const row = document.querySelector(`tr[data-article-id="${currentArticleId}"]`);
        if (row) {
          row.style.transition = 'all 0.3s';
          row.style.opacity = '0';
          row.style.transform = 'translateX(20px)';
          setTimeout(() => row.remove(), 300);
        }
        
        // Update stats
        const pendingCount = document.getElementById('pendingCount');
        pendingCount.textContent = parseInt(pendingCount.textContent) - 1;
        
        const completedCount = document.getElementById('completedCount');
        completedCount.textContent = parseInt(completedCount.textContent) + 1;
        
        // Check if table is empty
        const tbody = document.getElementById('decisionsTableBody');
        if (tbody.children.length === 0 || tbody.querySelectorAll('tr').length === 0) {
          setTimeout(() => {
            tbody.innerHTML = `
              <tr>
                <td colspan="6">
                  <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <h3>Toutes les décisions ont été prises</h3>
                    <p>Aucun article en attente de décision finale.</p>
                  </div>
                </td>
              </tr>
            `;
          }, 300);
        }
        
        closeDecisionModal();
      } else {
        showToast(data.message, 'error');
      }
    })
    .catch(error => {
      showToast('Erreur de connexion. Veuillez réessayer.', 'error');
      console.error('Error:', error);
    })
    .finally(() => {
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Envoyer à l\'auteur';
    });
  }

  function closeDecisionModal() {
    document.getElementById('decisionModal').classList.remove('open');
    currentArticleId = null;
    selectedDecision = null;
  }

  // Search functionality
  document.getElementById('searchDecision').addEventListener('input', (e) => {
    const term = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#decisionsTableBody tr[data-article-id]');
    
    rows.forEach(row => {
      const text = row.textContent.toLowerCase();
      if (text.includes(term)) {
        row.style.display = '';
      } else {
        row.style.display = 'none';
      }
    });
  });

  // Close modal on backdrop click
  document.getElementById('decisionModal').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) {
      closeDecisionModal();
    }
  });

  // Expose global functions
  window.openDecisionModal = openDecisionModal;
  window.selectDecision = selectDecision;
  window.submitDecision = submitDecision;
  window.closeDecisionModal = closeDecisionModal;
</script>

</body>
</html>
<?php
// PHP Helper Functions
function getRecommendationText($rec) {
    $texts = [
        'accept' => 'Accepter',
        'minor_revision' => 'Révisions mineures',
        'major_revision' => 'Révisions majeures',
        'reject' => 'Refuser'
    ];
    return $texts[$rec] ?? $rec;
}

function getRecommendationClass($rec) {
    $classes = [
        'accept' => 'rec-accept',
        'minor_revision' => 'rec-minor',
        'major_revision' => 'rec-major',
        'reject' => 'rec-reject'
    ];
    return $classes[$rec] ?? 'rec-minor';
}

function getDecisionText($decision) {
    $texts = [
        'accepted' => 'Accepté',
        'revision' => 'Révisions demandées',
        'rejected' => 'Refusé'
    ];
    return $texts[$decision] ?? $decision;
}

function getDecisionClass($decision) {
    $classes = [
        'accepted' => 'rec-accept',
        'revision' => 'rec-minor',
        'rejected' => 'rec-reject'
    ];
    return $classes[$decision] ?? 'rec-minor';
}

function getConsensusRecommendation($evaluations) {
    if (empty($evaluations)) return null;
    
    $counts = [];
    foreach ($evaluations as $e) {
        $counts[$e['recommendation']] = ($counts[$e['recommendation']] ?? 0) + 1;
    }
    
    if (($counts['reject'] ?? 0) >= 1) return 'reject';
    if (($counts['major_revision'] ?? 0) >= 1) return 'major_revision';
    if (($counts['minor_revision'] ?? 0) >= 2) return 'minor_revision';
    if (($counts['accept'] ?? 0) >= 1) return 'accept';
    return 'minor_revision';
}
?>