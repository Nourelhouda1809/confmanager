<?php
session_start();

// ==================== VERIFY REVIEWER ACCESS ====================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'reviewer') {
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
                die(json_encode(['ok' => false, 'msg' => 'Database connection failed: ' . $e->getMessage()]));
            }
        }
        return self::$connection;
    }
}

$pdo = Database::getConnection();
$reviewerId = $_SESSION['user_id'];

// ==================== GET REVIEWER INFO ====================
$stmtUser = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = :id");
$stmtUser->execute([':id' => $reviewerId]);
$reviewer = $stmtUser->fetch();
$reviewerName = $reviewer ? $reviewer['first_name'] . ' ' . $reviewer['last_name'] : 'Évaluateur';

// ==================== GET REVIEWED ARTICLES ====================
// Get articles that this reviewer has evaluated (completed)
$stmt = $pdo->prepare("
    SELECT 
        a.id,
        a.reference as ref,
        a.titre_fr as title,
        a.resume_fr as abstract,
        a.domaine as specialty,
        a.date_soumission as submission_date,
        c.name_fr as conf,
        c.id as conference_id,
        u.first_name as author_first_name,
        u.last_name as author_last_name,
        u.email as author_email,
        u.institution as author_institution,
        e.id as evaluation_id,
        e.recommendation,
        e.strengths,
        e.weaknesses,
        e.suggestions,
        e.originality,
        e.methodology,
        e.quality,
        e.significance,
        e.language,
        e.format,
        e.completed_date,
        a.fichier_principal as file,
        CASE
            WHEN e.recommendation = 'accept' THEN 'accepte'
            WHEN e.recommendation = 'reject' THEN 'rejete'
            ELSE 'revision'
        END AS decision_status
    FROM evaluations e
    JOIN articles a ON a.id = e.article_id
    JOIN conferences c ON c.id = a.conference_id
    JOIN users u ON u.id = a.utilisateur_id
    WHERE e.reviewer_id = :reviewer_id
    ORDER BY e.completed_date DESC
");

$stmt->execute([':reviewer_id' => $reviewerId]);
$reviewedArticles = $stmt->fetchAll();

// ==================== GET ALL ARTICLES FOR SEARCH (BASE) ====================
// Get all published/accepted articles from database for originality check
$stmtAll = $pdo->prepare("
    SELECT 
        a.id,
        a.reference as ref,
        a.titre_fr as title,
        a.resume_fr as abstract,
        a.domaine as specialty,
        c.name_fr as conf,
        c.id as conference_id,
        u.first_name as author_first_name,
        u.last_name as author_last_name,
        a.date_soumission as submission_date,
        a.fichier_principal as file
    FROM articles a
    JOIN conferences c ON c.id = a.conference_id
    JOIN users u ON u.id = a.utilisateur_id
    WHERE a.statut IN ('accepte', 'accepte', 'published')
    ORDER BY a.date_soumission DESC
    LIMIT 100
");

$stmtAll->execute();
$allArticles = $stmtAll->fetchAll();

// ==================== AJAX HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    
    // Search articles
    if ($action === 'search_articles') {
        $query = trim($_POST['query'] ?? '');
        
        if (empty($query)) {
            echo json_encode(['ok' => true, 'articles' => $allArticles]);
            exit;
        }
        
        $searchTerm = '%' . $query . '%';
        $searchStmt = $pdo->prepare("
            SELECT 
                a.id,
                a.reference as ref,
                a.titre_fr as title,
                a.resume_fr as abstract,
                a.domaine as specialty,
                c.name_fr as conf,
                u.first_name as author_first_name,
                u.last_name as author_last_name,
                a.date_soumission as submission_date,
                a.fichier_principal as file
            FROM articles a
            JOIN conferences c ON c.id = a.conference_id
            JOIN users u ON u.id = a.utilisateur_id
            WHERE (a.titre_fr LIKE :search 
                OR a.resume_fr LIKE :search 
                OR u.first_name LIKE :search 
                OR u.last_name LIKE :search
                OR a.reference LIKE :search
                OR c.name_fr LIKE :search)
                AND a.statut IN ('accepte', 'published')
            ORDER BY a.date_soumission DESC
            LIMIT 100
        ");
        $searchStmt->execute([':search' => $searchTerm]);
        $results = $searchStmt->fetchAll();
        
        echo json_encode(['ok' => true, 'articles' => $results]);
        exit;
    }
    
    // Get article details
    if ($action === 'get_article') {
        $articleId = (int)($_POST['article_id'] ?? 0);
        
        $articleStmt = $pdo->prepare("
            SELECT 
                a.id,
                a.reference as ref,
                a.titre_fr as title,
                a.resume_fr as abstract,
                a.domaine as specialty,
                a.mots_cles as keywords,
                a.date_soumission as submission_date,
                c.name_fr as conf,
                u.first_name as author_first_name,
                u.last_name as author_last_name,
                u.email as author_email,
                u.institution as author_institution,
                a.fichier_principal as file
            FROM articles a
            JOIN conferences c ON c.id = a.conference_id
            JOIN users u ON u.id = a.utilisateur_id
            WHERE a.id = :id
        ");
        $articleStmt->execute([':id' => $articleId]);
        $article = $articleStmt->fetch();
        
        echo json_encode(['ok' => true, 'article' => $article]);
        exit;
    }
    
    echo json_encode(['ok' => false, 'msg' => 'Action inconnue']);
    exit;
}

// ==================== CSRF TOKEN ====================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Articles évalués</title>
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
    --success: #2a9d8f;
    --warning: #d4830a;
    --danger: #d94040;
    --shadow-sm: 0 1px 4px rgba(13,33,55,0.07);
    --shadow: 0 4px 20px rgba(13,33,55,0.10);
    --radius: 8px;
    --radius-sm: 4px;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

  /* ─── TOPBAR ─── */
  .topbar {
    background: var(--white); border-bottom: 1px solid var(--border);
    padding: 0 40px; height: 62px; display: flex; align-items: center;
    position: sticky; top: 0; z-index: 100; box-shadow: var(--shadow-sm);
  }
  .brand { display: flex; align-items: center; gap: 10px; margin-right: 48px; text-decoration: none; }
  .brand-icon {
    width: 34px; height: 34px; background: var(--navy);
    border-radius: 6px; display: flex; align-items: center;
    justify-content: center; color: white; font-size: 16px;
  }
  .brand-name { font-size: 18px; font-weight: 600; color: var(--navy); letter-spacing: -0.3px; }
  .nav-links { display: flex; align-items: center; gap: 4px; flex: 1; }
  .nav-link {
    padding: 8px 16px; font-size: 14px; font-weight: 400;
    color: var(--text-light); background: none; border: none;
    border-radius: var(--radius-sm); cursor: pointer; font-family: 'DM Sans', sans-serif;
    transition: all 0.15s; position: relative; text-decoration: none; display: inline-block;
  }
  .nav-link:hover { color: var(--navy); background: var(--bg); }
  .nav-link.active { color: var(--gold); font-weight: 500; }
  .nav-link.active::after {
    content: ''; position: absolute; bottom: -1px; left: 16px; right: 16px;
    height: 2px; background: var(--gold); border-radius: 2px 2px 0 0;
  }
  .topbar-right { display: flex; align-items: center; gap: 12px; }

  .logout-btn {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 18px; background: var(--bg);
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    font-size: 13.5px; font-weight: 500; color: var(--text-light);
    cursor: pointer; font-family: 'DM Sans', sans-serif;
    transition: all 0.15s; text-decoration: none;
  }
  .logout-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--white); }

  /* ─── TABS ─── */
  .tabs {
    display: flex;
    gap: 4px;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 30px;
    padding: 4px;
    margin-bottom: 24px;
    width: fit-content;
  }
  .tab {
    padding: 8px 24px;
    font-size: 14px;
    font-weight: 500;
    color: var(--muted);
    background: none;
    border: none;
    border-radius: 30px;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    transition: all 0.15s;
  }
  .tab:hover { color: var(--navy); background: var(--bg); }
  .tab.active { background: var(--navy); color: var(--gold-light); }

  /* ─── PAGE ─── */
  .page { max-width: 1200px; margin: 0 auto; padding: 40px 32px 60px; }
  .page-header { margin-bottom: 28px; }
  .page-header-row { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 6px; }
  .page-header h1 { font-family: 'Libre Baskerville', serif; font-size: 32px; font-weight: 700; color: var(--navy); letter-spacing: -0.5px; }
  .page-header h1 em { font-style: italic; color: var(--gold); }
  .page-header p { font-size: 14px; color: var(--muted); margin-top: 4px; }

  /* ─── SEARCH BAR ─── */
  .search-bar { 
    display: flex; gap: 10px; margin-bottom: 20px;
    background: var(--white); padding: 20px; border-radius: var(--radius);
    box-shadow: var(--shadow-sm); border: 1px solid var(--border);
  }
  .search-input {
    flex: 1; border: 1px solid var(--border); border-radius: var(--radius-sm);
    padding: 12px 16px; font-size: 14px; font-family: 'DM Sans', sans-serif;
    color: var(--text); outline: none; background: var(--white); transition: all .15s;
  }
  .search-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(44,111,173,.08); }
  .btn-primary {
    background: var(--navy); color: var(--gold-light);
    border: none; border-radius: var(--radius-sm);
    padding: 12px 24px; font-size: 14px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer;
    transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none; white-space: nowrap;
  }
  .btn-primary:hover { background: var(--navy-mid); }
  .btn-secondary {
    background: none; color: var(--text-light);
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    padding: 9px 20px; font-size: 13.5px;
    font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s;
  }
  .btn-secondary:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }

  /* ─── ARTICLES TABLE ─── */
  .articles-table-wrap { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
  .table-head { display: grid; grid-template-columns: 2.5fr 1fr 1fr 100px; background: var(--bg); border-bottom: 1px solid var(--border); }
  .th { padding: 12px 18px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); }
  .th:first-child { padding-left: 24px; }
  .th:last-child  { padding-right: 24px; text-align: right; }

  .article-row { display: grid; grid-template-columns: 2.5fr 1fr 1fr 100px; border-bottom: 1px solid var(--border); transition: background 0.12s; align-items: center; }
  .article-row:last-child { border-bottom: none; }
  .article-row:hover { background: #fafbfd; }

  .td { padding: 16px 18px; }
  .td:first-child { padding-left: 24px; }
  .td:last-child  { padding-right: 24px; }

  .article-title-main { font-size: 14px; font-weight: 600; color: var(--navy); margin-bottom: 4px; line-height: 1.35; }
  .article-meta-row   { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .article-ref { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--muted); background: var(--bg); padding: 2px 7px; border-radius: 3px; border: 1px solid var(--border); }
  .td-domain { font-size: 13px; color: var(--text-light); }

  /* Badges */
  .badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
  }
  .badge-dot { width: 6px; height: 6px; border-radius: 50%; }
  .badge.accepte { background: #e8f6f3; color: #1a5f57; }
  .badge.accepte .badge-dot { background: var(--success); }
  .badge.rejete { background: #fdf2f2; color: #8b2020; }
  .badge.rejete .badge-dot { background: var(--danger); }
  .badge.revision { background: #fef8ec; color: #9a5f00; }
  .badge.revision .badge-dot { background: var(--warning); }

  /* ROW ACTIONS */
  .action-btn {
    height: 30px; padding: 0 12px; border-radius: var(--radius-sm);
    border: 1px solid var(--border); background: none;
    color: var(--muted); cursor: pointer; font-size: 12px;
    display: flex; align-items: center; gap: 5px;
    transition: all 0.15s; font-family: 'DM Sans', sans-serif; white-space: nowrap;
  }
  .action-btn:hover { background: var(--bg); color: var(--navy); border-color: var(--navy); }

  /* ─── MODALS ─── */
  .modal-backdrop {
    position: fixed; inset: 0; background: rgba(13,33,55,0.5);
    backdrop-filter: blur(4px); z-index: 200;
    display: none; align-items: center; justify-content: center; padding: 20px;
  }
  .modal-backdrop.open { display: flex; }
  .modal {
    background: var(--white); border-radius: var(--radius);
    width: 100%; max-width: 800px; box-shadow: var(--shadow);
    animation: fadeUp 0.2s ease; max-height: 90vh; overflow-y: auto;
  }
  .modal-header {
    padding: 20px 24px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; background: var(--white); z-index: 1;
  }
  .modal-title { font-family: 'Libre Baskerville', serif; font-size: 18px; color: var(--navy); }
  .modal-close {
    width: 30px; height: 30px; border-radius: var(--radius-sm);
    border: 1px solid var(--border); background: none;
    color: var(--muted); cursor: pointer; font-size: 14px;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.15s;
  }
  .modal-close:hover { background: var(--bg); color: var(--navy); }
  .modal-body { padding: 24px; }
  .modal-footer {
    padding: 16px 24px; border-top: 1px solid var(--border);
    display: flex; justify-content: flex-end; gap: 10px;
  }

  .modal-detail-row { display: flex; gap: 10px; margin-bottom: 14px; align-items: flex-start; }
  .modal-detail-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); width: 110px; flex-shrink: 0; padding-top: 2px; }
  .modal-detail-value { font-size: 13.5px; color: var(--text); flex: 1; line-height: 1.5; }

  /* Evaluation details */
  .eval-section {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 16px;
    margin-bottom: 16px;
  }
  .eval-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--navy);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .rating-stars {
    display: inline-flex;
    gap: 3px;
    margin-left: 8px;
  }
  .rating-stars i {
    font-size: 12px;
    color: var(--gold);
  }
  .eval-text {
    font-size: 13px;
    color: var(--text-light);
    line-height: 1.5;
    margin-bottom: 8px;
  }

  /* FOOTER */
  .footer { background: var(--navy); color: rgba(255,255,255,0.45); text-align: center; padding: 22px; font-size: 13px; margin-top: 48px; }

  @keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:none} }

  @media(max-width:900px) {
    .table-head,.article-row { grid-template-columns: 2fr 1fr 100px; }
    .th:nth-child(3), .td:nth-child(3) { display:none; }
    .tabs { width: 100%; justify-content: center; }
  }
  @media(max-width:650px) {
    .topbar { padding: 0 16px; }
    .page { padding: 24px 16px; }
    .search-bar { flex-direction: column; }
    .btn-primary { width: 100%; justify-content: center; }
    .table-head,.article-row { grid-template-columns: 1fr 100px; }
    .th:nth-child(2), .td:nth-child(2) { display: none; }
  }
</style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
  <a class="brand" href="reviewer_dashboard.php">
    <div class="brand-icon">📋</div>
    <span class="brand-name">ConfManager</span>
  </a>
  <nav class="nav-links">
    <a href="reviewer_dashboard.php" class="nav-link">Tableau de bord</a>
    <a href="review_articles.php" class="nav-link">Articles assignés</a>
    <a href="reviewed_articles.php" class="nav-link active">Articles évalués</a>
    <a href="profile.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</header>

<!-- ═══════════════════════════════════════════════
     ARTICLES ÉVALUÉS
═══════════════════════════════════════════════ -->
<main class="page">
  <div class="page-header">
    <div class="page-header-row">
      <h1>Articles <em>évalués</em></h1>
    </div>
    <p>Historique des articles que vous avez déjà évalués</p>
  </div>

  <div class="tabs">
    <button class="tab active" onclick="switchTab('reviewed')">Mes évaluations</button>
    <button class="tab" onclick="switchTab('search')">Recherche d'articles</button>
  </div>

  <!-- Tab 1: Mes évaluations -->
  <div id="reviewedTab">
    <div class="articles-table-wrap">
      <div class="table-head">
        <div class="th">Article</div>
        <div class="th">Auteur(s)</div>
        <div class="th">Conférence</div>
        <div class="th" style="text-align:right">Action</div>
      </div>
      <div id="reviewedList">
        <?php if (empty($reviewedArticles)): ?>
          <div style="text-align:center;padding:40px;color:var(--muted);font-size:14px">
            <i class="fas fa-inbox" style="font-size:32px;opacity:.3;display:block;margin-bottom:12px"></i>
            Aucun article évalué pour le moment
          </div>
        <?php else: ?>
          <?php foreach ($reviewedArticles as $a): ?>
            <div class="article-row">
              <div class="td">
                <div class="article-title-main"><?= htmlspecialchars($a['title']) ?></div>
                <div class="article-meta-row">
                  <span class="article-ref"><?= htmlspecialchars($a['ref']) ?></span>
                  <span class="badge <?= $a['decision_status'] ?>">
                    <span class="badge-dot"></span>
                    <?= $a['decision_status'] === 'accepte' ? 'Accepté' : ($a['decision_status'] === 'rejete' ? 'Rejeté' : 'Révision demandée') ?>
                  </span>
                </div>
              </div>
              <div class="td td-domain"><?= htmlspecialchars($a['author_first_name'] . ' ' . $a['author_last_name']) ?></div>
              <div class="td td-domain"><?= htmlspecialchars($a['conf']) ?></div>
              <div class="td" style="text-align:right">
                <button class="action-btn" onclick="viewReviewedArticle(<?= $a['id'] ?>)">
                  <i class="fas fa-eye"></i> Détail
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Tab 2: Recherche d'articles -->
  <div id="searchTab" style="display:none">
    <div class="search-bar">
      <input type="text" class="search-input" id="searchInput" placeholder="Rechercher par titre, auteur, mot-clé, conférence..." onkeyup="if(event.key==='Enter') searchArticles()">
      <button class="btn-primary" onclick="searchArticles()">
        <i class="fas fa-search"></i> Rechercher
      </button>
    </div>

    <div class="articles-table-wrap">
      <div class="table-head">
        <div class="th">Article</div>
        <div class="th">Auteur(s)</div>
        <div class="th">Conférence</div>
        <div class="th" style="text-align:right">Action</div>
      </div>
      <div id="searchResults">
        <div style="text-align:center;padding:40px;color:var(--muted);font-size:14px">
          <i class="fas fa-search" style="font-size:32px;opacity:.3;display:block;margin-bottom:12px"></i>
          Utilisez la barre de recherche pour trouver des articles
        </div>
      </div>
    </div>
  </div>
</main>

<!-- MODAL: ARTICLE DETAIL -->
<div class="modal-backdrop" id="articleModal" onclick="closeOnBackdrop(event)">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Détail de l'article</div>
      <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="articleModalBody"></div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal()">Fermer</button>
    </div>
  </div>
</div>

<footer class="footer">© <?= date('Y') ?> ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés</footer>

<script>
// ==================== PHP DATA INJECTION ====================
const reviewedArticles = <?= json_encode($reviewedArticles) ?>;
const allArticles = <?= json_encode($allArticles) ?>;

// ==================== TAB SWITCHING ====================
function switchTab(tab) {
    const reviewedTab = document.getElementById('reviewedTab');
    const searchTab = document.getElementById('searchTab');
    const tabs = document.querySelectorAll('.tab');
    
    tabs.forEach(t => t.classList.remove('active'));
    
    if (tab === 'reviewed') {
        reviewedTab.style.display = 'block';
        searchTab.style.display = 'none';
        tabs[0].classList.add('active');
    } else {
        reviewedTab.style.display = 'none';
        searchTab.style.display = 'block';
        tabs[1].classList.add('active');
    }
}

// ==================== SEARCH ARTICLES ====================
function searchArticles() {
    const query = document.getElementById('searchInput').value.trim();
    
    if (!query) {
        renderSearchResults(allArticles);
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'search_articles');
    formData.append('query', query);
    
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                renderSearchResults(data.articles);
            } else {
                showToast(data.msg || 'Erreur de recherche', 'error');
            }
        })
        .catch(() => showToast('Erreur de communication', 'error'));
}

function renderSearchResults(articles) {
    const container = document.getElementById('searchResults');
    
    if (!articles || articles.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:40px;color:var(--muted);font-size:14px">
                <i class="fas fa-search" style="font-size:32px;opacity:.3;display:block;margin-bottom:12px"></i>
                Aucun article trouvé
            </div>`;
        return;
    }
    
    let html = '';
    for (const a of articles) {
        html += `
            <div class="article-row">
                <div class="td">
                    <div class="article-title-main">${escapeHtml(a.title)}</div>
                    <div class="article-meta-row">
                        <span class="article-ref">${escapeHtml(a.ref)}</span>
                    </div>
                </div>
                <div class="td td-domain">${escapeHtml(a.author_first_name + ' ' + a.author_last_name)}</div>
                <div class="td td-domain">${escapeHtml(a.conf)}</div>
                <div class="td" style="text-align:right">
                    <button class="action-btn" onclick="viewArticle(${a.id})">
                        <i class="fas fa-eye"></i> Voir
                    </button>
                </div>
            </div>`;
    }
    container.innerHTML = html;
}

// ==================== VIEW REVIEWED ARTICLE ====================
function viewReviewedArticle(id) {
    const article = reviewedArticles.find(a => a.id === id);
    if (!article) return;
    
    const decisionText = article.decision_status === 'accepte' ? 'Accepté' :
                        article.decision_status === 'rejete' ? 'Rejeté' : 'Révision demandée';
    const decisionClass = article.decision_status;
    
    // Calculate average rating
    const avgRating = (article.originality + article.methodology + article.quality + 
                       article.significance + article.language + article.format) / 6;
    
    document.getElementById('articleModalBody').innerHTML = `
        <div class="modal-detail-row">
            <span class="modal-detail-label">Référence</span>
            <span class="modal-detail-value"><span class="article-ref">${escapeHtml(article.ref)}</span></span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Titre</span>
            <span class="modal-detail-value" style="font-weight:600;color:var(--navy)">${escapeHtml(article.title)}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Résumé</span>
            <span class="modal-detail-value" style="color:var(--text-light);line-height:1.6">${escapeHtml(article.abstract || 'Aucun résumé disponible')}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Auteur(s)</span>
            <span class="modal-detail-value">${escapeHtml(article.author_first_name + ' ' + article.author_last_name)}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Email</span>
            <span class="modal-detail-value">${escapeHtml(article.author_email)}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Institution</span>
            <span class="modal-detail-value">${escapeHtml(article.author_institution || 'Non spécifiée')}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Conférence</span>
            <span class="modal-detail-value">${escapeHtml(article.conf)}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Date d'évaluation</span>
            <span class="modal-detail-value">${formatDate(article.completed_date)}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Décision</span>
            <span class="modal-detail-value">
                <span class="badge ${decisionClass}">
                    <span class="badge-dot"></span> ${decisionText}
                </span>
            </span>
        </div>
        
        <div class="eval-section">
            <div class="eval-title">
                <i class="fas fa-star" style="color:var(--gold)"></i> 
                Évaluation scientifique
                <span class="rating-stars">
                    ${renderStars(Math.round(avgRating))}
                </span>
                <span style="margin-left:auto;font-size:11px">${avgRating.toFixed(1)}/5</span>
            </div>
            <div class="eval-text"><strong>Originalité :</strong> ${article.originality}/5</div>
            <div class="eval-text"><strong>Méthodologie :</strong> ${article.methodology}/5</div>
            <div class="eval-text"><strong>Qualité des résultats :</strong> ${article.quality}/5</div>
            <div class="eval-text"><strong>Significance :</strong> ${article.significance}/5</div>
            <div class="eval-text"><strong>Langue :</strong> ${article.language}/5</div>
            <div class="eval-text"><strong>Format :</strong> ${article.format}/5</div>
        </div>
        
        <div class="eval-section">
            <div class="eval-title"><i class="fas fa-comment-dots"></i> Points forts</div>
            <div class="eval-text">${escapeHtml(article.strengths || 'Non spécifié')}</div>
        </div>
        
        <div class="eval-section">
            <div class="eval-title"><i class="fas fa-exclamation-triangle"></i> Points faibles</div>
            <div class="eval-text">${escapeHtml(article.weaknesses || 'Non spécifié')}</div>
        </div>
        
        ${article.suggestions ? `
        <div class="eval-section">
            <div class="eval-title"><i class="fas fa-lightbulb"></i> Suggestions</div>
            <div class="eval-text">${escapeHtml(article.suggestions)}</div>
        </div>` : ''}
        
        <div class="download-zone" style="background:var(--bg);padding:12px;border-radius:8px;margin-top:16px">
            <i class="fas fa-file-pdf" style="color:var(--danger);margin-right:10px"></i>
            <span style="font-size:13px">Fichier : ${escapeHtml(article.file)}</span>
        </div>
    `;
    openModal();
}

// ==================== VIEW SEARCHED ARTICLE ====================
function viewArticle(id) {
    const formData = new FormData();
    formData.append('action', 'get_article');
    formData.append('article_id', id);
    
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.ok && data.article) {
                const a = data.article;
                document.getElementById('articleModalBody').innerHTML = `
                    <div class="modal-detail-row">
                        <span class="modal-detail-label">Référence</span>
                        <span class="modal-detail-value"><span class="article-ref">${escapeHtml(a.ref)}</span></span>
                    </div>
                    <div class="modal-detail-row">
                        <span class="modal-detail-label">Titre</span>
                        <span class="modal-detail-value" style="font-weight:600;color:var(--navy)">${escapeHtml(a.title)}</span>
                    </div>
                    <div class="modal-detail-row">
                        <span class="modal-detail-label">Résumé</span>
                        <span class="modal-detail-value" style="color:var(--text-light);line-height:1.6">${escapeHtml(a.abstract || 'Aucun résumé disponible')}</span>
                    </div>
                    <div class="modal-detail-row">
                        <span class="modal-detail-label">Mots-clés</span>
                        <span class="modal-detail-value">${escapeHtml(a.keywords || 'Non spécifiés')}</span>
                    </div>
                    <div class="modal-detail-row">
                        <span class="modal-detail-label">Auteur(s)</span>
                        <span class="modal-detail-value">${escapeHtml(a.author_first_name + ' ' + a.author_last_name)}</span>
                    </div>
                    <div class="modal-detail-row">
                        <span class="modal-detail-label">Email</span>
                        <span class="modal-detail-value">${escapeHtml(a.author_email)}</span>
                    </div>
                    <div class="modal-detail-row">
                        <span class="modal-detail-label">Institution</span>
                        <span class="modal-detail-value">${escapeHtml(a.author_institution || 'Non spécifiée')}</span>
                    </div>
                    <div class="modal-detail-row">
                        <span class="modal-detail-label">Conférence</span>
                        <span class="modal-detail-value">${escapeHtml(a.conf)}</span>
                    </div>
                    <div class="modal-detail-row">
                        <span class="modal-detail-label">Date de soumission</span>
                        <span class="modal-detail-value">${formatDate(a.submission_date)}</span>
                    </div>
                    <div class="download-zone" style="background:var(--bg);padding:12px;border-radius:8px;margin-top:16px">
                        <i class="fas fa-file-pdf" style="color:var(--danger);margin-right:10px"></i>
                        <span style="font-size:13px">Fichier : ${escapeHtml(a.file)}</span>
                    </div>
                `;
                openModal();
            } else {
                showToast('Article non trouvé', 'error');
            }
        })
        .catch(() => showToast('Erreur de chargement', 'error'));
}

// ==================== UTILITIES ====================
function renderStars(rating) {
    let stars = '';
    for (let i = 1; i <= 5; i++) {
        stars += `<i class="fas fa-star" style="${i <= rating ? 'color:var(--gold)' : 'color:var(--border)'}"></i>`;
    }
    return stars;
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    const date = new Date(dateStr);
    return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' });
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function showToast(msg, type = 'info') {
    const wrap = document.getElementById('toastWrap') || (() => {
        const w = document.createElement('div');
        w.className = 'toast-wrap';
        w.id = 'toastWrap';
        document.body.appendChild(w);
        return w;
    })();
    const t = document.createElement('div');
    const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', info: 'fa-info-circle' };
    t.className = `toast ${type}`;
    t.innerHTML = `<i class="fas ${icons[type] || 'fa-info-circle'}"></i> ${msg}`;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

function openModal() { 
    document.getElementById('articleModal').classList.add('open'); 
    document.body.style.overflow = 'hidden'; 
}

function closeModal() { 
    document.getElementById('articleModal').classList.remove('open'); 
    document.body.style.overflow = ''; 
}

function closeOnBackdrop(e) { 
    if (e.target === document.getElementById('articleModal')) closeModal(); 
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
});

// Toast styles
const style = document.createElement('style');
style.textContent = `
    .toast-wrap { position: fixed; bottom: 28px; right: 28px; display: flex; flex-direction: column; gap: 10px; z-index: 500; pointer-events: none; }
    .toast {
        background: var(--navy); color: white; padding: 12px 18px; border-radius: var(--radius);
        font-size: 13.5px; box-shadow: var(--shadow-md);
        display: flex; align-items: center; gap: 10px;
        animation: toastIn 0.25s ease, toastOut 0.25s ease 3.5s forwards;
        pointer-events: auto; max-width: 380px;
    }
    .toast.success { background: var(--success); }
    .toast.error   { background: var(--danger); }
    @keyframes toastIn { from{opacity:0;transform:translateX(30px)} to{opacity:1;transform:none} }
    @keyframes toastOut { from{opacity:1;transform:none} to{opacity:0;transform:translateX(30px)} }
`;
document.head.appendChild(style);

// Initialize search with all articles
setTimeout(() => {
    renderSearchResults(allArticles);
}, 100);
</script>
</body>
</html>