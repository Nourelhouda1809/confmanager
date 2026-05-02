<?php
session_start();

// ==================== DATABASE CONFIGURATION ====================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'confmanager');

// ==================== DATABASE CONNECTION ====================
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

// ==================== DATABASE FUNCTIONS ====================

// Get articles pending final decision (have 2 reviews, no final decision yet)
function getPendingDecisions() {
    $db = Database::getConnection();
    
    // Create final_decisions table if not exists
    try {
        $createTable = "CREATE TABLE IF NOT EXISTS final_decisions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            article_id INT NOT NULL,
            gestionnaire_id INT NOT NULL,
            decision ENUM('accepted', 'revision', 'rejected') NOT NULL,
            comment TEXT NOT NULL,
            decision_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
            FOREIGN KEY (gestionnaire_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_article_decision (article_id),
            INDEX idx_article (article_id)
        )";
        $db->exec($createTable);
    } catch (Exception $e) {
        // Table might already exist
    }
    
    $sql = "SELECT 
            a.id,
            a.titre_fr as title,
            a.resume_fr as abstract,
            a.domaine as domain,
            a.date_soumission as submissionDate,
            u.id as authorId,
            u.first_name as authorFirstName,
            u.last_name as authorLastName,
            u.email as authorEmail,
            u.institution as authorInstitution,
            c.id as conferenceId,
            c.name_fr as conferenceName,
            a.statut as status,
            (SELECT COUNT(*) FROM article_reviewers WHERE article_id = a.id) as reviewerCount,
            (SELECT COUNT(*) FROM evaluations WHERE article_id = a.id) as evaluationCount
            FROM articles a
            JOIN users u ON a.utilisateur_id = u.id
            JOIN conferences c ON a.conference_id = c.id
            WHERE a.statut IN ('en_evaluation', 'assigne')
            AND (SELECT COUNT(*) FROM article_reviewers WHERE article_id = a.id) >= 2
            AND a.id NOT IN (SELECT article_id FROM final_decisions)
            ORDER BY a.date_soumission DESC";
    
    $stmt = $db->query($sql);
    $articles = $stmt->fetchAll();
    
    // Get evaluations for each article
    foreach ($articles as &$article) {
        $evalSql = "SELECT 
                    e.id,
                    e.article_id,
                    e.reviewer_id,
                    e.originality,
                    e.methodology,
                    e.quality,
                    e.significance,
                    e.language,
                    e.format,
                    e.strengths,
                    e.weaknesses,
                    e.suggestions,
                    e.recommendation,
                    e.completed_date,
                    u.first_name as reviewerFirstName,
                    u.last_name as reviewerLastName,
                    u.email as reviewerEmail,
                    u.institution as reviewerInstitution
                    FROM evaluations e
                    JOIN users u ON e.reviewer_id = u.id
                    WHERE e.article_id = ?
                    AND e.status = 'completed'
                    ORDER BY e.completed_date DESC";
        
        $evalStmt = $db->prepare($evalSql);
        $evalStmt->execute([$article['id']]);
        $article['evaluations'] = $evalStmt->fetchAll();
    }
    
    return $articles;
}

// Get completed decisions (articles with final decision)
function getCompletedDecisions() {
    $db = Database::getConnection();
    
    $sql = "SELECT 
            fd.id as decisionId,
            fd.article_id,
            fd.decision,
            fd.comment,
            fd.decision_date,
            a.titre_fr as title,
            u.first_name as authorFirstName,
            u.last_name as authorLastName,
            u.email as authorEmail,
            c.name_fr as conferenceName
            FROM final_decisions fd
            JOIN articles a ON fd.article_id = a.id
            JOIN users u ON a.utilisateur_id = u.id
            JOIN conferences c ON a.conference_id = c.id
            ORDER BY fd.decision_date DESC";
    
    $stmt = $db->query($sql);
    return $stmt->fetchAll();
}

// Get evaluations for a specific article
function getArticleEvaluations($articleId) {
    $db = Database::getConnection();
    $sql = "SELECT 
            e.id,
            e.article_id,
            e.reviewer_id,
            e.originality,
            e.methodology,
            e.quality,
            e.significance,
            e.language,
            e.format,
            e.strengths,
            e.weaknesses,
            e.suggestions,
            e.recommendation,
            e.completed_date,
            u.first_name as reviewerFirstName,
            u.last_name as reviewerLastName,
            u.email as reviewerEmail,
            u.institution as reviewerInstitution
            FROM evaluations e
            JOIN users u ON e.reviewer_id = u.id
            WHERE e.article_id = ? AND e.status = 'completed'
            ORDER BY e.completed_date DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$articleId]);
    return $stmt->fetchAll();
}

// Get consensus recommendation from evaluations
function getConsensusRecommendation($evaluations) {
    if (empty($evaluations)) return null;
    
    $counts = [];
    foreach ($evaluations as $e) {
        $rec = $e['recommendation'];
        $counts[$rec] = ($counts[$rec] ?? 0) + 1;
    }
    
    // Priority: reject > major_revision > minor_revision > accept
    if (($counts['reject'] ?? 0) >= 1) return 'reject';
    if (($counts['major_revision'] ?? 0) >= 1) return 'major_revision';
    if (($counts['minor_revision'] ?? 0) >= 2) return 'minor_revision';
    if (($counts['accept'] ?? 0) >= 1) return 'accept';
    return 'minor_revision';
}

// Save final decision
function saveFinalDecision($articleId, $decision, $comment, $gestionnaireId) {
    $db = Database::getConnection();
    
    // Check if decision already exists
    $checkSql = "SELECT id FROM final_decisions WHERE article_id = ?";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->execute([$articleId]);
    
    if ($checkStmt->fetch()) {
        // Update existing decision
        $sql = "UPDATE final_decisions SET decision = ?, comment = ?, decision_date = CURDATE(), gestionnaire_id = ? WHERE article_id = ?";
        $stmt = $db->prepare($sql);
        return $stmt->execute([$decision, $comment, $gestionnaireId, $articleId]);
    } else {
        // Insert new decision
        $sql = "INSERT INTO final_decisions (article_id, gestionnaire_id, decision, comment, decision_date) VALUES (?, ?, ?, ?, CURDATE())";
        $stmt = $db->prepare($sql);
        return $stmt->execute([$articleId, $gestionnaireId, $decision, $comment]);
    }
}

// Update article status based on final decision
function updateArticleStatus($articleId, $decision) {
    $db = Database::getConnection();
    
    $statusMap = [
        'accepted' => 'accepte',
        'revision' => 'revised',
        'rejected' => 'refuse'
    ];
    
    $status = $statusMap[$decision] ?? 'accepte';
    $sql = "UPDATE articles SET statut = ?, date_decision = CURDATE() WHERE id = ?";
    $stmt = $db->prepare($sql);
    return $stmt->execute([$status, $articleId]);
}

// Get current user (gestionnaire)
function getCurrentUserId() {
    $db = Database::getConnection();
    $stmt = $db->query("SELECT id FROM users WHERE role = 'gestionnaire' LIMIT 1");
    $user = $stmt->fetch();
    return $user ? $user['id'] : 1;
}

// ==================== AJAX ENDPOINTS ====================

if (!empty($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    
    // Get pending decisions
    if ($action === 'get_pending_decisions') {
        try {
            $articles = getPendingDecisions();
            
            $formatted = [];
            foreach ($articles as $article) {
                $evaluations = [];
                foreach ($article['evaluations'] as $eval) {
                    $evaluations[] = [
                        'reviewer' => $eval['reviewerFirstName'] . ' ' . $eval['reviewerLastName'],
                        'comment' => $eval['strengths'] . ' ' . $eval['weaknesses'],
                        'recommendation' => $eval['recommendation'],
                        'date' => $eval['completed_date']
                    ];
                }
                
                $formatted[] = [
                    'id' => $article['id'],
                    'title' => $article['title'],
                    'author' => $article['authorFirstName'] . ' ' . $article['authorLastName'],
                    'authorEmail' => $article['authorEmail'],
                    'conference' => $article['conferenceName'],
                    'submissionDate' => $article['submissionDate'],
                    'status' => 'pending',
                    'evaluations' => $evaluations
                ];
            }
            
            echo json_encode(['ok' => true, 'articles' => $formatted]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }
    
    // Get completed decisions
    if ($action === 'get_completed_decisions') {
        try {
            $decisions = getCompletedDecisions();
            echo json_encode(['ok' => true, 'decisions' => $decisions]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }
    
    // Submit final decision
    if ($action === 'submit_decision') {
        $articleId = (int)($_POST['article_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $comment = trim($_POST['comment'] ?? '');
        
        if (!$articleId || !$decision || !$comment) {
            echo json_encode(['ok' => false, 'msg' => 'Données invalides']);
            exit;
        }
        
        if (!in_array($decision, ['accepted', 'revision', 'rejected'])) {
            echo json_encode(['ok' => false, 'msg' => 'Décision invalide']);
            exit;
        }
        
        if (strlen($comment) < 10) {
            echo json_encode(['ok' => false, 'msg' => 'Le commentaire doit contenir au moins 10 caractères']);
            exit;
        }
        
        try {
            $gestionnaireId = getCurrentUserId();
            saveFinalDecision($articleId, $decision, $comment, $gestionnaireId);
            updateArticleStatus($articleId, $decision);
            
            echo json_encode(['ok' => true, 'msg' => 'Décision enregistrée avec succès']);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => 'Erreur: ' . $e->getMessage()]);
        }
        exit;
    }
    
    echo json_encode(['ok' => false, 'msg' => 'Action inconnue']);
    exit;
}

// ==================== LOAD INITIAL DATA ====================
$pendingArticles = getPendingDecisions();
$completedDecisions = getCompletedDecisions();

// Format pending articles for JavaScript
$formattedPending = [];
foreach ($pendingArticles as $article) {
    $evaluations = [];
    foreach ($article['evaluations'] as $eval) {
        $evaluations[] = [
            'reviewer' => $eval['reviewerFirstName'] . ' ' . $eval['reviewerLastName'],
            'comment' => $eval['strengths'],
            'recommendation' => $eval['recommendation'],
            'date' => $eval['completed_date']
        ];
    }
    
    $formattedPending[] = [
        'id' => $article['id'],
        'title' => $article['title'],
        'author' => $article['authorFirstName'] . ' ' . $article['authorLastName'],
        'authorEmail' => $article['authorEmail'],
        'conference' => $article['conferenceName'],
        'submissionDate' => $article['submissionDate'],
        'status' => 'pending',
        'evaluations' => $evaluations
    ];
}

$jsPendingArticles = json_encode($formattedPending, JSON_UNESCAPED_UNICODE);
$jsCompletedDecisions = json_encode($completedDecisions, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Décisions finales</title>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- SweetAlert2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
    <a href="admin_dashboard.php" class="nav-link">Conférences & Articles</a>
    <a href="evaluators.php" class="nav-link">Évaluateurs</a>
    <a href="final_decisions.php" class="nav-link active">Décisions finales</a>
    <a href="admin_profile.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <div class="search-wrap">
     
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
        <div class="stat-value" id="pendingCount">0</div>
        <div class="stat-label">En attente de décision</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon completed"><i class="fas fa-check-circle"></i></div>
      <div>
        <div class="stat-value" id="completedCount">0</div>
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
        <tr><td colspan="6" class="loading" style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin"></i> Chargement...</td></tr>
      </tbody>
    </table>
  </div>
</main>

<footer class="footer">© <?= date('Y') ?> ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés</footer>

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

<script>
// ==================== SweetAlert Helper Functions ====================
function showSuccess(title, message) {
    Swal.fire({
        title: title,
        html: message,
        icon: 'success',
        confirmButtonText: 'OK',
        confirmButtonColor: '#0d2137',
        showClass: { popup: '' },
        hideClass: { popup: '' }
    });
}

function showError(title, message) {
    Swal.fire({
        title: title,
        text: message,
        icon: 'error',
        confirmButtonText: 'OK',
        confirmButtonColor: '#0d2137',
        showClass: { popup: '' },
        hideClass: { popup: '' }
    });
}

function showWarning(title, message) {
    Swal.fire({
        title: title,
        text: message,
        icon: 'warning',
        confirmButtonText: 'OK',
        confirmButtonColor: '#0d2137',
        showClass: { popup: '' },
        hideClass: { popup: '' }
    });
}

// ==================== PHP → JS DATA ====================
let pendingArticlesData = <?= $jsPendingArticles ?>;
let completedDecisionsData = <?= $jsCompletedDecisions ?>;

// ==================== STATE ====================
let articles = pendingArticlesData;
let completedDecisions = completedDecisionsData;
let currentArticleId = null;
let selectedDecision = null;

// ==================== UTILITIES ====================
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

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
    if (evaluations.length === 0) return null;
    
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

// ==================== RENDER FUNCTIONS ====================
function updateStats() {
    const pending = articles.length;
    const completed = completedDecisions.length;
    
    document.getElementById('pendingCount').textContent = pending;
    document.getElementById('completedCount').textContent = completed;
}

function renderTable() {
    if (articles.length === 0) {
        document.getElementById('decisionsTableBody').innerHTML = `
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
        updateStats();
        return;
    }
    
    let html = '';
    for (const article of articles) {
        const formattedDate = new Date(article.submissionDate).toLocaleDateString('fr-FR');
        const evalCount = article.evaluations.length;
        const consensus = getConsensusRecommendation(article.evaluations);
        
        html += `
          <tr>
            <td>
              <div class="article-title">${escapeHtml(article.title)}</div>
              <div class="article-meta">
                <span><i class="fas fa-user"></i> ${escapeHtml(article.author)}</span>
                <span class="tag">${escapeHtml(article.conference)}</span>
              </div>
            </td>
            <td>${escapeHtml(article.conference)}</td>
            <td style="font-size:12px; color:var(--muted);">${formattedDate}</td>
            <td><span class="review-recommendation rec-accept"><i class="fas fa-check-circle"></i> ${evalCount}/2 reçues</span></td>
            <td>
              <span class="review-recommendation ${getRecommendationClass(consensus)}">
                ${getRecommendationText(consensus)}
              </span>
            </td>
            <td>
              <button class="btn-decision" onclick="openDecisionModal(${article.id})">
                <i class="fas fa-gavel"></i> Décider
              </button>
            </td>
          </tr>
        `;
    }
    
    document.getElementById('decisionsTableBody').innerHTML = html;
    updateStats();
}

// ==================== MODAL FUNCTIONS ====================
function openDecisionModal(id) {
    const article = articles.find(a => a.id === id);
    if (!article) return;
    
    currentArticleId = id;
    selectedDecision = null;
    
    // Build evaluations summary
    let evaluationsHtml = '';
    for (const review of article.evaluations) {
        evaluationsHtml += `
          <div class="review-card">
            <div class="review-header">
              <span><i class="fas fa-user-check"></i> ${escapeHtml(review.reviewer)}</span>
              <span style="font-size:10px; color:var(--muted);">${review.date ? new Date(review.date).toLocaleDateString('fr-FR') : ''}</span>
            </div>
            <div class="review-comment">${escapeHtml(review.comment)}</div>
            <span class="review-recommendation ${getRecommendationClass(review.recommendation)}">
              ${getRecommendationText(review.recommendation)}
            </span>
          </div>
        `;
    }
    
    const consensus = getConsensusRecommendation(article.evaluations);
    
    let html = `
      <div class="article-detail-header">
        <div class="article-detail-title">${escapeHtml(article.title)}</div>
        <div class="article-detail-meta">
          <span><i class="fas fa-user"></i> ${escapeHtml(article.author)}</span>
          <span><i class="fas fa-envelope"></i> ${escapeHtml(article.authorEmail)}</span>
          <span><i class="fas fa-calendar"></i> Soumis le ${new Date(article.submissionDate).toLocaleDateString('fr-FR')}</span>
          <span><i class="fas fa-building"></i> ${escapeHtml(article.conference)}</span>
        </div>
      </div>
      
      <div class="info-card">
        <div class="info-card-title"><i class="fas fa-comments"></i> Évaluations des relecteurs</div>
        ${evaluationsHtml || '<div style="color:var(--muted);">Aucune évaluation disponible</div>'}
      </div>
      
      <div class="info-card" style="background:#fef9ed; border-color:var(--gold-light);">
        <div class="info-card-title"><i class="fas fa-balance-scale"></i> Consensus des évaluateurs</div>
        <div style="padding:12px; background:var(--white); border-radius:var(--radius-sm);">
          <span class="review-recommendation ${getRecommendationClass(consensus)}">
            ${getRecommendationText(consensus)}
          </span>
          <div style="font-size:12px; color:var(--muted); margin-top:8px;">
            Recommandation basée sur ${article.evaluations.length} évaluation(s)
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
          <label class="field-label">Commentaire à l'auteur <span class="field-required">*</span></label>
          <textarea class="form-textarea" id="decisionComment" placeholder="Expliquez votre décision à l'auteur... (minimum 10 caractères)"></textarea>
          <div style="font-size:11px; color:var(--muted); margin-top:4px;">
            <i class="fas fa-envelope"></i> Ce message sera envoyé à ${escapeHtml(article.authorEmail)}
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
    const selector = decision === 'accepted' ? '.decision-option.accept' : 
                     decision === 'revision' ? '.decision-option.revision' : 
                     '.decision-option.reject';
    document.querySelector(selector).classList.add('selected');
}

function submitDecision() {
    if (!selectedDecision) {
        showWarning('Sélection requise', 'Veuillez sélectionner une décision.');
        return;
    }
    
    const comment = document.getElementById('decisionComment').value.trim();
    if (!comment || comment.length < 10) {
        showWarning('Commentaire requis', 'Veuillez rédiger un commentaire pour l\'auteur (minimum 10 caractères).');
        return;
    }
    
    const article = articles.find(a => a.id === currentArticleId);
    if (!article) return;
    
    const formData = new FormData();
    formData.append('action', 'submit_decision');
    formData.append('article_id', currentArticleId);
    formData.append('decision', selectedDecision);
    formData.append('comment', comment);
    
    // Disable button to prevent double submission
    const submitBtn = document.getElementById('submitDecisionBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi en cours...';
    
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                showError('Erreur', data.msg || 'Erreur serveur');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Envoyer à l\'auteur';
                return;
            }
            
            // Remove article from pending list
            const index = articles.findIndex(a => a.id === currentArticleId);
            if (index !== -1) {
                articles.splice(index, 1);
            }
            
            // Add to completed decisions
            completedDecisions.unshift({
                id: currentArticleId,
                title: article.title,
                author: article.author,
                decision: selectedDecision,
                date: new Date().toISOString().split('T')[0]
            });
            
            const decisionTexts = {
                accepted: 'accepté pour publication',
                revision: 'soumis à révisions',
                rejected: 'refusé'
            };
            
            // Show SweetAlert2 success message
            const successHtml = `
                <p style="margin-bottom:12px;"><strong>L'article "${escapeHtml(article.title)}" a été ${decisionTexts[selectedDecision]}.</strong></p>
                <p>Un email a été envoyé à l'auteur.</p>
            `;
            
            Swal.fire({
                title: '✅ Décision envoyée !',
                html: successHtml,
                icon: 'success',
                confirmButtonText: 'OK',
                confirmButtonColor: '#0d2137',
                showClass: { popup: '' },
                hideClass: { popup: '' }
            }).then(() => {
                closeDecisionModal();
                renderTable();
            });
            
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Envoyer à l\'auteur';
        })
        .catch(err => {
            console.error(err);
            showError('Erreur', 'Erreur de communication avec le serveur.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Envoyer à l\'auteur';
        });
}

function closeDecisionModal() {
    document.getElementById('decisionModal').classList.remove('open');
    currentArticleId = null;
    selectedDecision = null;
}

// ==================== SEARCH FUNCTIONALITY ====================
document.getElementById('searchDecision').addEventListener('input', (e) => {
    const term = e.target.value.toLowerCase();
    if (term === '') {
        articles = pendingArticlesData;
        renderTable();
        return;
    }
    
    const filtered = pendingArticlesData.filter(a => 
        a.title.toLowerCase().includes(term) || 
        a.author.toLowerCase().includes(term) ||
        a.conference.toLowerCase().includes(term)
    );
    articles = filtered;
    renderTable();
});

// ==================== EXPOSE GLOBALS ====================
window.openDecisionModal = openDecisionModal;
window.selectDecision = selectDecision;
window.submitDecision = submitDecision;
window.closeDecisionModal = closeDecisionModal;

// ==================== INIT ====================
renderTable();
</script>
</body>
</html>