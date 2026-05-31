<?php
require_once 'config.php';
requireRole('reviewer');

$reviewerId = getCurrentUserId();

// Fetch previous articles (accepted/rejected articles from past conferences)
$stmt = $pdo->prepare("
    SELECT a.*, c.name_fr as conference_name, c.name_en, t.name as topic_name, u.first_name, u.last_name
    FROM articles a
    LEFT JOIN conferences c ON a.conference_id = c.id
    LEFT JOIN topics t ON a.topic_id = t.id
    LEFT JOIN users u ON a.author = CONCAT(u.first_name, '.', u.last_name)
    WHERE a.status IN ('accepted', 'rejected', 'review')
    AND a.id NOT IN (SELECT article_id FROM article_reviewers WHERE evaluator_id = ?)
    ORDER BY a.submission_date DESC
    LIMIT 50
");
$stmt->execute([$reviewerId]);
$previousArticles = $stmt->fetchAll();

$formattedArticles = [];
foreach ($previousArticles as $a) {
    $formattedArticles[] = [
        'ref' => generateRef($a['id']),
        'title' => $a['title'],
        'authors' => $a['author'] ?? ($a['first_name'] . ' ' . $a['last_name']),
        'conf' => $a['conference_name'] ?? $a['name_en'] ?? 'Conférence',
        'year' => date('Y', strtotime($a['submission_date'])),
        'abstract' => $a['abstract'] ?? 'Résumé non disponible'
    ];
}

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Base d'articles</title>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root {
    --navy: #0d2137; --navy-mid: #1a3a5c; --gold: #c9a84c; --gold-light: #e2c97e;
    --bg: #f0f2f5; --white: #ffffff; --border: #e2e8f0; --muted: #7a8fa6;
    --text: #1a2e44; --text-light: #4a607a; --accent: #2c6fad;
    --shadow-sm: 0 1px 4px rgba(13,33,55,0.07); --shadow: 0 4px 20px rgba(13,33,55,0.10);
    --radius: 8px; --radius-sm: 4px;
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
  .logout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 18px; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 500; color: var(--text-light); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all 0.15s; text-decoration: none; }
  .logout-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--white); }
  .page { max-width: 1100px; margin: 0 auto; padding: 40px 32px 60px; }
  .page-header { margin-bottom: 28px; }
  .page-header-row { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 6px; }
  .page-header h1 { font-family: 'Libre Baskerville', serif; font-size: 32px; font-weight: 700; color: var(--navy); letter-spacing: -0.5px; }
  .page-header h1 em { font-style: italic; color: var(--gold); }
  .page-header p { font-size: 14px; color: var(--muted); margin-top: 4px; }
  .search-prev-bar { display: flex; gap: 10px; margin-bottom: 20px; background: var(--white); padding: 20px; border-radius: var(--radius); box-shadow: var(--shadow-sm); border: 1px solid var(--border); }
  .search-prev-input { flex: 1; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px 16px; font-size: 14px; font-family: 'DM Sans', sans-serif; color: var(--text); outline: none; background: var(--white); transition: all .15s; }
  .search-prev-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(44,111,173,.08); }
  .btn-primary { background: var(--navy); color: var(--gold-light); border: none; border-radius: var(--radius-sm); padding: 12px 24px; font-size: 14px; font-weight: 500; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; white-space: nowrap; }
  .btn-primary:hover { background: var(--navy-mid); }
  .articles-table-wrap { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
  .table-head { display: grid; grid-template-columns: 2.5fr 1fr 1fr 120px; background: var(--bg); border-bottom: 1px solid var(--border); }
  .th { padding: 12px 18px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); }
  .th:first-child { padding-left: 24px; }
  .th:last-child { padding-right: 24px; text-align: right; }
  .article-row { display: grid; grid-template-columns: 2.5fr 1fr 1fr 120px; border-bottom: 1px solid var(--border); transition: background 0.12s; align-items: center; }
  .article-row:last-child { border-bottom: none; }
  .article-row:hover { background: #fafbfd; }
  .td { padding: 16px 18px; }
  .td:first-child { padding-left: 24px; }
  .td:last-child { padding-right: 24px; }
  .article-title-main { font-size: 14px; font-weight: 600; color: var(--navy); margin-bottom: 4px; line-height: 1.35; }
  .article-meta-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .article-ref { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--muted); background: var(--bg); padding: 2px 7px; border-radius: 3px; border: 1px solid var(--border); }
  .td-domain { font-size: 13px; color: var(--text-light); }
  .action-btn { height: 30px; padding: 0 12px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: none; color: var(--muted); cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 5px; transition: all 0.15s; font-family: 'DM Sans', sans-serif; white-space: nowrap; }
  .action-btn:hover { background: var(--bg); color: var(--navy); border-color: var(--navy); }
  .modal-backdrop { position: fixed; inset: 0; background: rgba(13,33,55,0.5); backdrop-filter: blur(4px); z-index: 200; display: none; align-items: center; justify-content: center; padding: 20px; }
  .modal-backdrop.open { display: flex; }
  .modal { background: var(--white); border-radius: var(--radius); width: 100%; max-width: 700px; box-shadow: var(--shadow); animation: fadeUp 0.2s ease; max-height: 90vh; overflow-y: auto; }
  .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; background: var(--white); z-index: 1; }
  .modal-title { font-family: 'Libre Baskerville', serif; font-size: 18px; color: var(--navy); }
  .modal-close { width: 30px; height: 30px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: none; color: var(--muted); cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; transition: all 0.15s; }
  .modal-close:hover { background: var(--bg); color: var(--navy); }
  .modal-body { padding: 24px; }
  .modal-detail-row { display: flex; gap: 10px; margin-bottom: 14px; align-items: flex-start; }
  .modal-detail-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); width: 110px; flex-shrink: 0; padding-top: 2px; }
  .modal-detail-value { font-size: 13.5px; color: var(--text); flex: 1; line-height: 1.5; }
  .btn-secondary { background: none; color: var(--text-light); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 9px 20px; font-size: 13.5px; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s; }
  .btn-secondary:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }
  .footer { background: var(--navy); color: rgba(255,255,255,0.45); text-align: center; padding: 22px; font-size: 13px; margin-top: 48px; }
  @keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:none} }
  @media(max-width:900px) { .table-head,.article-row { grid-template-columns: 2fr 1fr 100px; } .th:nth-child(3), .td:nth-child(3) { display:none; } }
  @media(max-width:650px) { .topbar { padding: 0 16px; } .page { padding: 24px 16px; } .search-prev-bar { flex-direction: column; } .btn-primary { width: 100%; justify-content: center; } }
</style>
</head>
<body>

<header class="topbar">
  <a class="brand" href="reviewer_dashboard.php">
    <div class="brand-icon">📋</div>
    <span class="brand-name">ConfManager</span>
  </a>
  <nav class="nav-links">
    <a href="reviewer_dashboard.php" class="nav-link">Tableau de bord</a>
    <a href="articles.php" class="nav-link">Articles assignés</a>
    <a href="base-articles.php" class="nav-link active">Base d'articles</a>
    <a href="profile.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</header>

<main class="page">
  <div class="page-header">
    <div class="page-header-row">
      <h1>Base <em>d'articles</em></h1>
    </div>
    <p>Recherchez les articles précédents pour évaluer l'originalité</p>
  </div>

  <div class="search-prev-bar">
    <input type="text" class="search-prev-input" id="prevSearchInput" placeholder="Rechercher par titre, auteur, mot-clé, conférence..." oninput="searchPrevious(this.value)">
    <button class="btn-primary" onclick="searchPrevious(document.getElementById('prevSearchInput').value)">
      <i class="fas fa-search"></i> Rechercher
    </button>
  </div>

  <div class="articles-table-wrap">
    <div class="table-head">
      <div class="th">Titre</div>
      <div class="th">Auteur(s)</div>
      <div class="th">Conférence</div>
      <div class="th" style="text-align:right">Action</div>
    </div>
    <div id="prevArticleList">
      <?php foreach ($formattedArticles as $a): ?>
      <div class="article-row">
        <div class="td">
          <div class="article-title-main"><?php echo htmlspecialchars($a['title']); ?></div>
          <div class="article-meta-row">
            <span class="article-ref"><?php echo $a['ref']; ?></span>
            <span style="font-size:11px;color:var(--muted)"><?php echo $a['year']; ?></span>
          </div>
        </div>
        <div class="td td-domain"><?php echo htmlspecialchars($a['authors']); ?></div>
        <div class="td td-domain"><?php echo htmlspecialchars($a['conf']); ?></div>
        <div class="td" style="text-align:right">
          <button class="action-btn" onclick="viewArticle('<?php echo $a['ref']; ?>')">
            <i class="fas fa-eye"></i> Voir
          </button>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($formattedArticles)): ?>
      <div style="text-align:center;padding:40px;color:var(--muted);font-size:14px">
        <i class="fas fa-search" style="font-size:32px;opacity:.3;display:block;margin-bottom:12px"></i>
        Aucun article trouvé dans la base
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<div class="modal-backdrop" id="articleModal" onclick="closeOnBackdrop(event)">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Détail de l'article</div>
      <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="articleModalBody"></div>
  </div>
</div>

<footer class="footer">© 2026 ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés</footer>

<script>
const previousArticles = <?php echo json_encode($formattedArticles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function renderPrevious(q) {
  const filtered = q ? previousArticles.filter(a =>
    a.title.toLowerCase().includes(q.toLowerCase()) ||
    a.authors.toLowerCase().includes(q.toLowerCase()) ||
    a.conf.toLowerCase().includes(q.toLowerCase()) ||
    a.abstract.toLowerCase().includes(q.toLowerCase())
  ) : previousArticles;

  document.getElementById('prevArticleList').innerHTML = filtered.length
    ? filtered.map(a => `
      <div class="article-row">
        <div class="td">
          <div class="article-title-main">${a.title}</div>
          <div class="article-meta-row">
            <span class="article-ref">${a.ref}</span>
            <span style="font-size:11px;color:var(--muted)">${a.year}</span>
          </div>
        </div>
        <div class="td td-domain">${a.authors}</div>
        <div class="td td-domain">${a.conf}</div>
        <div class="td" style="text-align:right">
          <button class="action-btn" onclick="viewArticle('${a.ref}')">
            <i class="fas fa-eye"></i> Voir
          </button>
        </div>
      </div>`).join('')
    : `<div style="text-align:center;padding:40px;color:var(--muted);font-size:14px">
        <i class="fas fa-search" style="font-size:32px;opacity:.3;display:block;margin-bottom:12px"></i>
        Aucun article trouvé
       </div>`;
}

function searchPrevious(q) { renderPrevious(q); }

function viewArticle(ref) {
  const a = previousArticles.find(x => x.ref === ref);
  if (!a) return;
  document.getElementById('articleModalBody').innerHTML = `
    <div class="modal-detail-row"><span class="modal-detail-label">Référence</span><span class="modal-detail-value"><span class="article-ref">${a.ref}</span></span></div>
    <div class="modal-detail-row"><span class="modal-detail-label">Titre</span><span class="modal-detail-value" style="font-weight:600;color:var(--navy)">${a.title}</span></div>
    <div class="modal-detail-row"><span class="modal-detail-label">Résumé</span><span class="modal-detail-value" style="color:var(--text-light);line-height:1.6">${a.abstract}</span></div>
    <div class="modal-detail-row"><span class="modal-detail-label">Auteur(s)</span><span class="modal-detail-value">${a.authors}</span></div>
    <div class="modal-detail-row"><span class="modal-detail-label">Conférence</span><span class="modal-detail-value">${a.conf} · ${a.year}</span></div>
    <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)">
      <button class="btn-secondary" onclick="closeModal()" style="width:100%"><i class="fas fa-times"></i> Fermer</button>
    </div>
  `;
  openModal();
}

function openModal() { document.getElementById('articleModal').classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeModal() { document.getElementById('articleModal').classList.remove('open'); document.body.style.overflow = ''; }
function closeOnBackdrop(e) { if(e.target === document.getElementById('articleModal')) closeModal(); }
document.addEventListener('keydown', e => { if(e.key === 'Escape') closeModal(); });
</script>
</body>
</html>