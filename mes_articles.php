<?php
// ============================================
// mes_articles.php - Mes articles
// ConfManager
// ============================================

session_start();
require_once 'config/database.php';

// ============================================
// 1. VÉRIFICATION DE L'AUTHENTIFICATION
// ============================================

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'chercheur';
$userName = $_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom'];

// ============================================
// 2. FONCTIONS UTILITAIRES
// ============================================

/**
 * Échappe une chaîne pour le HTML
 */
function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Formate une date pour affichage
 */
function formatDate(string $date): string {
    if (empty($date)) return '—';
    $timestamp = strtotime($date);
    return date('d/m/Y', $timestamp);
}

/**
 * Retourne la classe CSS pour le badge de statut
 */
function getStatusBadgeClass(string $status): string {
    $badges = [
        'en_attente' => 'pending',
        'en_evaluation' => 'reviewing',
        'accepte' => 'accepted',
        'refuse' => 'rejected',
        'assigne' => 'assigned',
        'revised' => 'revised',
        'done' => 'done',
        'decided' => 'decided'
    ];
    return $badges[$status] ?? 'pending';
}

/**
 * Retourne le libellé du statut
 */
function getStatusLabel(string $status): string {
    $labels = [
        'en_attente' => 'En attente',
        'en_evaluation' => 'En évaluation',
        'accepte' => 'Accepté',
        'refuse' => 'Refusé',
        'assigne' => 'Assigné',
        'revised' => 'Révisé',
        'done' => 'Évalué',
        'decided' => 'Décision prise'
    ];
    return $labels[$status] ?? $status;
}

// ============================================
// 3. RÉCUPÉRATION DES ARTICLES DE L'UTILISATEUR
// ============================================

// Paramètres de pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Filtres
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = trim($_GET['search'] ?? '');

// Construction de la requête
$whereConditions = ["utilisateur_id = :user_id"];
$params = [':user_id' => $userId];

if ($statusFilter !== 'all') {
    $whereConditions[] = "statut = :status";
    $params[':status'] = $statusFilter;
}

if (!empty($searchQuery)) {
    $whereConditions[] = "(titre_fr LIKE :search OR titre_en LIKE :search OR reference LIKE :search)";
    $params[':search'] = "%$searchQuery%";
}

$whereClause = implode(' AND ', $whereConditions);

// Comptage total
$countQuery = "SELECT COUNT(*) as total FROM articles WHERE $whereClause";
$countStmt = $db->prepare($countQuery);
$countStmt->execute($params);
$totalArticles = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalArticles / $limit);

// Récupération des articles
$query = "
    SELECT a.*, 
           c.name_fr as conference_name,
           (SELECT COUNT(*) FROM evaluations WHERE article_id = a.id) as evaluations_count
    FROM articles a
    LEFT JOIN conferences c ON a.conference_id = c.id
    WHERE $whereClause
    ORDER BY a.date_soumission DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($query);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    if ($key !== ':limit' && $key !== ':offset') {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// 4. STATISTIQUES POUR LE TABLEAU DE BORD
// ============================================

$statsQuery = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN statut = 'en_attente' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN statut = 'en_evaluation' THEN 1 ELSE 0 END) as reviewing,
        SUM(CASE WHEN statut = 'accepte' THEN 1 ELSE 0 END) as accepted,
        SUM(CASE WHEN statut = 'refuse' THEN 1 ELSE 0 END) as rejected
    FROM articles 
    WHERE utilisateur_id = :user_id
";
$statsStmt = $db->prepare($statsQuery);
$statsStmt->execute([':user_id' => $userId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ============================================
// 5. NAVIGATION PAR RÔLE
// ============================================

$navItems = [
    'gestionnaire' => [
        ['url' => 'dashboard.php', 'label' => 'Tableau de bord', 'icon' => 'fa-chart-line'],
        ['url' => 'submit_article.php', 'label' => 'Soumettre des articles', 'icon' => 'fa-file-upload'],
        ['url' => 'all_articles.php', 'label' => 'Tous les articles', 'icon' => 'fa-folder-open'],
        ['url' => 'users.php', 'label' => 'Utilisateurs', 'icon' => 'fa-users'],
        ['url' => 'conferences.php', 'label' => 'Conférences', 'icon' => 'fa-calendar-alt'],
        ['url' => 'profile.php', 'label' => 'Profil', 'icon' => 'fa-user'],
    ],
    'chercheur' => [
        ['url' => 'submit_article.php', 'label' => 'Soumettre des articles', 'icon' => 'fa-file-upload'],
        ['url' => 'mes_articles.php', 'label' => 'Mes articles', 'icon' => 'fa-folder', 'active' => true],
      
        ['url' => 'profile.php', 'label' => 'Profil', 'icon' => 'fa-user'],
    ],
    'reviewer' => [
        ['url' => 'review_articles.php', 'label' => 'Articles à réviser', 'icon' => 'fa-glasses'],
        ['url' => 'reviewed_articles.php', 'label' => 'Articles révisés', 'icon' => 'fa-check-circle'],
        ['url' => 'profile.php', 'label' => 'Profil', 'icon' => 'fa-user'],
    ]
];

$currentNav = $navItems[$userRole] ?? $navItems['chercheur'];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ConfManager — Mes articles</title>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================ */
        /* VARIABLES & RESET */
        /* ============================================ */
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
            --radius: 8px;
            --radius-sm: 4px;
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



        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        /* ============================================ */
        /* TOPBAR */
        /* ============================================ */
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
        .brand { display: flex; align-items: center; gap: 10px; margin-right: 48px; text-decoration: none; }
        .brand-icon {
            width: 34px; height: 34px; background: var(--navy);
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 16px;
        }
        .brand-name { font-size: 18px; font-weight: 600; color: var(--navy); letter-spacing: -0.3px; }
        .nav-links { display: flex; align-items: center; gap: 4px; flex: 1; }
        .nav-link {
            padding: 8px 16px; font-size: 14px; font-weight: 400;
            color: var(--text-light); background: none; border: none;
            border-radius: var(--radius-sm);
            font-family: 'DM Sans', sans-serif;
            transition: all 0.15s;
            position: relative; text-decoration: none; display: inline-block;
        }
        .nav-link:hover { color: var(--navy); background: var(--bg); }
        .nav-link.active { color: var(--gold); font-weight: 500; }
        .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -1px; left: 16px; right: 16px;
            height: 2px; background: var(--gold);
            border-radius: 2px 2px 0 0;
        }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .search-wrap { position: relative; margin-right: 8px; }
        .search-input {
            width: 240px; padding: 8px 14px 8px 36px;
            border: 1px solid var(--border); border-radius: 20px;
            font-size: 13.5px;
            background: var(--bg); color: var(--text); outline: none;
            transition: all 0.15s;
        }
        .search-input:focus {
            border-color: var(--accent); background: var(--white);
            box-shadow: 0 0 0 3px rgba(44,111,173,0.10);
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
            cursor: pointer; text-decoration: none;
            transition: all 0.15s;
        }
        .logout-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--white); }

        /* ============================================ */
        /* PAGE LAYOUT */
        /* ============================================ */
        .page { max-width: 1400px; margin: 0 auto; padding: 40px 32px 60px; }
        .page-header { margin-bottom: 28px; }
        .page-header h1 {
            font-family: 'Libre Baskerville', serif;
            font-size: 32px; font-weight: 700;
            color: var(--navy); letter-spacing: -0.5px;
        }
        .page-header h1 em { font-style: italic; color: var(--gold); }
        .page-header p { font-size: 14px; color: var(--muted); margin-top: 4px; }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%);
            border-radius: var(--radius);
            padding: 24px 32px;
            margin-bottom: 32px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .welcome-title { font-size: 20px; font-weight: 600; margin-bottom: 4px; }
        .welcome-subtitle { font-size: 13px; opacity: 0.8; }
        .btn-new-article {
            background: var(--gold);
            color: var(--navy);
            padding: 12px 24px;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.15s;
        }
        .btn-new-article:hover { background: var(--gold-light); transform: translateY(-2px); }

        /* Stats Cards */
        .stats-row {
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
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
        .stat-icon {
            width: 44px; height: 44px; border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
        }
        .stat-icon.total { background: rgba(44,111,173,0.1); color: var(--accent); }
        .stat-icon.pending { background: rgba(212,131,10,0.1); color: var(--warning); }
        .stat-icon.reviewing { background: rgba(91,110,245,0.1); color: var(--purple); }
        .stat-icon.accepted { background: rgba(42,157,143,0.1); color: var(--success); }
        .stat-value { font-size: 24px; font-weight: 700; color: var(--navy); line-height: 1; margin-bottom: 4px; }
        .stat-label { font-size: 12px; color: var(--muted); }

        /* Filters Bar */
        .filters-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .filter-tabs {
            display: flex;
            gap: 4px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 30px;
            padding: 4px;
        }
        .filter-tab {
            padding: 8px 20px;
            font-size: 13px;
            font-weight: 500;
            color: var(--muted);
            background: none;
            border: none;
            border-radius: 30px;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            transition: all 0.15s;
        }
        .filter-tab:hover { color: var(--navy); background: var(--bg); }
        .filter-tab.active { background: var(--navy); color: var(--gold-light); }
        .search-box {
            display: flex;
            gap: 8px;
        }
        .search-input-box {
            padding: 8px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13px;
            width: 250px;
            font-family: 'DM Sans', sans-serif;
            outline: none;
            transition: all 0.15s;
        }
        .search-input-box:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(44,111,173,0.1); }
        .btn-search {
            padding: 8px 18px;
            background: var(--navy);
            color: var(--gold-light);
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            transition: all 0.15s;
        }
        .btn-search:hover { background: var(--navy-mid); }

        /* Articles Table */
        .articles-table-wrap {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 800px;
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
        .article-ref {
            font-family: 'DM Mono', monospace;
            font-size: 11px;
            color: var(--muted);
            background: var(--bg);
            padding: 2px 7px;
            border-radius: 3px;
            border: 1px solid var(--border);
            display: inline-block;
        }
        .article-conference {
            font-size: 11px;
            color: var(--muted);
            margin-top: 4px;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
        .badge.pending { background: #fef8ec; color: #9a5f00; border: 1px solid #f5d98a; }
        .badge.pending .badge-dot { background: var(--warning); }
        .badge.reviewing { background: #eef3fb; color: #1a4a7a; border: 1px solid #b3cdf0; }
        .badge.reviewing .badge-dot { background: var(--accent); }
        .badge.accepted { background: #e8f6f3; color: #1a5f57; border: 1px solid #9dd8d0; }
        .badge.accepted .badge-dot { background: var(--success); }
        .badge.rejected { background: #fdf2f2; color: #8b2020; border: 1px solid #f5b8b8; }
        .badge.rejected .badge-dot { background: var(--danger); }
        .badge.assigned { background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; }
        .badge.assigned .badge-dot { background: var(--purple); }
        .badge.revised { background: #f0f4ff; color: #3347bb; border: 1px solid #bcc7fa; }
        .badge.revised .badge-dot { background: #5b6ef5; }
        .badge.done { background: #e8f6f3; color: #1a5f57; border: 1px solid #9dd8d0; }
        .badge.done .badge-dot { background: var(--success); }

        /* Action Buttons */
        .actions { display: flex; gap: 8px; }
        .action-btn {
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--white);
            color: var(--muted);
            cursor: pointer;
            font-size: 12px;
            font-family: 'DM Sans', sans-serif;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }
        .action-btn:hover { background: var(--bg); color: var(--navy); border-color: var(--navy); }
        .action-btn.primary { background: var(--navy); color: var(--gold-light); border-color: var(--navy); }
        .action-btn.primary:hover { background: var(--navy-mid); }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }
        .empty-state i { font-size: 48px; margin-bottom: 16px; color: var(--border); }
        .empty-state h3 { font-size: 18px; color: var(--navy); margin-bottom: 8px; }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
        }
        .page-btn {
            padding: 8px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--white);
            color: var(--text-light);
            cursor: pointer;
            font-size: 13px;
            transition: all 0.15s;
            text-decoration: none;
        }
        .page-btn:hover { border-color: var(--navy); color: var(--navy); }
        .page-btn.active { background: var(--navy); color: var(--gold-light); border-color: var(--navy); }
        .page-btn.disabled { opacity: 0.5; cursor: not-allowed; }

        /* Footer */
        .footer {
            background: var(--navy);
            color: rgba(255,255,255,0.45);
            text-align: center;
            padding: 22px;
            margin-top: 48px;
            font-size: 13px;
        }

        @media (max-width: 900px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .topbar { padding: 0 16px; }
            .page { padding: 24px 16px; }
            .welcome-banner { flex-direction: column; text-align: center; }
            .filter-tabs { flex-wrap: wrap; }
        }
        @media (max-width: 600px) {
            .nav-links { display: none; }
            .stats-row { grid-template-columns: 1fr; }
            .search-box { width: 100%; }
            .search-input-box { flex: 1; }
        }
    </style>
</head>
<body>

<!-- ============================================ -->
<!-- TOPBAR -->
<!-- ============================================ -->
<header class="topbar">
   <a class="brand" href="#">
  <div class="logo-wrapper">
    <div class="logo-icon"><i class="fas fa-book-open"></i></div>
    <span class="logo-text">ConfManager</span>
  </div>
</a>
    <nav class="nav-links">
        <?php foreach ($currentNav as $item): ?>
            <a href="<?= e($item['url']); ?>" 
               class="nav-link <?= isset($item['active']) && $item['active'] ? 'active' : ''; ?>">
                <?= e($item['label']); ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="topbar-right">
        <div class="search-wrap">
       
            <input type="text" class="search-input" placeholder="Rechercher...">
        </div>
        <a href="logout.php" class="logout-btn"><span>↪</span> Déconnexion</a>
    </div>
</header>

<!-- ============================================ -->
<!-- CONTENU PRINCIPAL -->
<!-- ============================================ -->
<main class="page">

    <div class="page-header">
        <h1>Mes <em>articles</em></h1>
        <p>Consultez et gérez tous vos articles soumis</p>
    </div>

    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <div>
            <div class="welcome-title">Bonjour, <?= e($userName); ?> </div>
            <div class="welcome-subtitle">Voici l'état de vos soumissions</div>
        </div>
        <a href="submit_article.php" class="btn-new-article">
            <i class="fas fa-plus"></i> Nouvel article
        </a>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon total"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="stat-value"><?= $stats['total'] ?? 0; ?></div>
                <div class="stat-label">Total articles</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon pending"><i class="fas fa-clock"></i></div>
            <div>
                <div class="stat-value"><?= $stats['pending'] ?? 0; ?></div>
                <div class="stat-label">En attente</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon reviewing"><i class="fas fa-search"></i></div>
            <div>
                <div class="stat-value"><?= $stats['reviewing'] ?? 0; ?></div>
                <div class="stat-label">En évaluation</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon accepted"><i class="fas fa-check-circle"></i></div>
            <div>
                <div class="stat-value"><?= $stats['accepted'] ?? 0; ?></div>
                <div class="stat-label">Acceptés</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
        <div class="filter-tabs">
            <a href="?status=all&search=<?= urlencode($searchQuery); ?>" 
               class="filter-tab <?= $statusFilter === 'all' ? 'active' : ''; ?>">Tous</a>
            <a href="?status=en_attente&search=<?= urlencode($searchQuery); ?>" 
               class="filter-tab <?= $statusFilter === 'en_attente' ? 'active' : ''; ?>">En attente</a>
            <a href="?status=en_evaluation&search=<?= urlencode($searchQuery); ?>" 
               class="filter-tab <?= $statusFilter === 'en_evaluation' ? 'active' : ''; ?>">En évaluation</a>
            <a href="?status=accepte&search=<?= urlencode($searchQuery); ?>" 
               class="filter-tab <?= $statusFilter === 'accepte' ? 'active' : ''; ?>">Acceptés</a>
            <a href="?status=refuse&search=<?= urlencode($searchQuery); ?>" 
               class="filter-tab <?= $statusFilter === 'refuse' ? 'active' : ''; ?>">Refusés</a>
        </div>
        
        <form method="GET" class="search-box">
            <?php if ($statusFilter !== 'all'): ?>
                <input type="hidden" name="status" value="<?= e($statusFilter); ?>">
            <?php endif; ?>
            <input type="text" name="search" class="search-input-box" 
                   placeholder="Rechercher par titre, référence..." 
                   value="<?= e($searchQuery); ?>">
            <button type="submit" class="btn-search"><i class="fas fa-search"></i></button>
        </form>
    </div>

    <!-- Articles Table -->
    <div class="articles-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Référence / Titre</th>
                    <th>Conférence</th>
                    <th>Date de soumission</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($articles)): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <i class="fas fa-file-alt"></i>
                                <h3>Aucun article trouvé</h3>
                                <p>Vous n'avez pas encore soumis d'articles ou aucun résultat ne correspond à votre recherche.</p>
                                <a href="submit_article.php" class="action-btn primary" style="margin-top: 16px; display: inline-flex;">
                                    <i class="fas fa-plus"></i> Soumettre un article
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($articles as $article): ?>
                        <tr>
                            <td>
                                <div class="article-title"><?= e($article['titre_fr']); ?></div>
                                <div>
                                    <span class="article-ref"><?= e($article['reference']); ?></span>
                                </div>
                            </td>
                            <td>
                                <?= e($article['conference_name'] ?? '—'); ?>
                            </td>
                            <td><?= formatDate($article['date_soumission']); ?></td>
                            <td>
                                <span class="badge <?= getStatusBadgeClass($article['statut']); ?>">
                                    <span class="badge-dot"></span>
                                    <?= getStatusLabel($article['statut']); ?>
                                </span>
                            </td>
                            <td class="actions">
                                <a href="article_details.php?id=<?= $article['id']; ?>" class="action-btn" title="Voir les détails">
                                    <i class="fas fa-eye"></i> Voir
                                </a>
                                <?php if ($article['statut'] === 'refuse' && !empty($article['reject_reason'])): ?>
                                    <button class="action-btn" onclick="showRejectReason('<?= e(addslashes($article['reject_reason'])); ?>')" title="Motif du refus">
                                        <i class="fas fa-info-circle"></i> Motif
                                    </button>
                                <?php endif; ?>
                                <?php if ($article['statut'] === 'revised'): ?>
                                    <a href="submit_revision.php?id=<?= $article['id']; ?>" class="action-btn primary" title="Soumettre une révision">
                                        <i class="fas fa-sync-alt"></i> Réviser
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <a href="?page=1&status=<?= e($statusFilter); ?>&search=<?= urlencode($searchQuery); ?>" 
           class="page-btn <?= $page <= 1 ? 'disabled' : ''; ?>">
            <i class="fas fa-chevron-left"></i><i class="fas fa-chevron-left"></i>
        </a>
        <a href="?page=<?= $page - 1; ?>&status=<?= e($statusFilter); ?>&search=<?= urlencode($searchQuery); ?>" 
           class="page-btn <?= $page <= 1 ? 'disabled' : ''; ?>">
            <i class="fas fa-chevron-left"></i>
        </a>
        
        <?php
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        for ($i = $startPage; $i <= $endPage; $i++):
        ?>
            <a href="?page=<?= $i; ?>&status=<?= e($statusFilter); ?>&search=<?= urlencode($searchQuery); ?>" 
               class="page-btn <?= $i === $page ? 'active' : ''; ?>"><?= $i; ?></a>
        <?php endfor; ?>
        
        <a href="?page=<?= $page + 1; ?>&status=<?= e($statusFilter); ?>&search=<?= urlencode($searchQuery); ?>" 
           class="page-btn <?= $page >= $totalPages ? 'disabled' : ''; ?>">
            <i class="fas fa-chevron-right"></i>
        </a>
        <a href="?page=<?= $totalPages; ?>&status=<?= e($statusFilter); ?>&search=<?= urlencode($searchQuery); ?>" 
           class="page-btn <?= $page >= $totalPages ? 'disabled' : ''; ?>">
            <i class="fas fa-chevron-right"></i><i class="fas fa-chevron-right"></i>
        </a>
    </div>
    <?php endif; ?>

</main>

<!-- ============================================ -->
<!-- FOOTER -->
<!-- ============================================ -->
<footer class="footer">
    © 2026 ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés
</footer>

<!-- Modal pour afficher le motif de refus -->
<div id="rejectModal" class="modal-backdrop" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000;">
    <div style="background: white; border-radius: 12px; max-width: 500px; width: 90%; padding: 24px;">
        <h3 style="color: var(--danger); margin-bottom: 16px;">
            <i class="fas fa-times-circle"></i> Motif du refus
        </h3>
        <p id="rejectReasonText" style="color: var(--text); line-height: 1.6; margin-bottom: 20px;"></p>
        <button onclick="closeRejectModal()" class="btn-secondary" style="width: 100%;">Fermer</button>
    </div>
</div>

<style>
    .modal-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    .modal-backdrop.show {
        display: flex;
    }
</style>

<script>
    function showRejectReason(reason) {
        document.getElementById('rejectReasonText').innerHTML = reason;
        document.getElementById('rejectModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    
    function closeRejectModal() {
        document.getElementById('rejectModal').style.display = 'none';
        document.body.style.overflow = '';
    }
    
    // Fermer en cliquant à l'extérieur
    document.getElementById('rejectModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeRejectModal();
        }
    });
    
    // Fermer avec la touche Echap
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeRejectModal();
        }
    });
</script>

</body>
</html>