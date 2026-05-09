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

// ==================== CREATE TABLES IF NOT EXISTS ====================
function createTables($pdo) {
    // Evaluations table
    $sql1 = "CREATE TABLE IF NOT EXISTS evaluations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        article_id INT NOT NULL,
        reviewer_id INT NOT NULL,
        originality INT DEFAULT 0,
        methodology INT DEFAULT 0,
        quality INT DEFAULT 0,
        significance INT DEFAULT 0,
        language INT DEFAULT 0,
        format INT DEFAULT 0,
        strengths TEXT NOT NULL,
        weaknesses TEXT NOT NULL,
        suggestions TEXT,
        recommendation ENUM('accept', 'minor_revision', 'major_revision', 'reject') NOT NULL,
        status ENUM('pending', 'completed') DEFAULT 'completed',
        assigned_date DATE,
        completed_date DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_review (article_id, reviewer_id)
    )";
    
    // Article reviewers table
    $sql2 = "CREATE TABLE IF NOT EXISTS article_reviewers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        article_id INT NOT NULL,
        reviewer_id INT NOT NULL,
        assigned_date DATE NOT NULL,
        status ENUM('pending', 'accepted', 'completed', 'declined') DEFAULT 'pending',
        completed_date DATE,
        email_sent BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_assignment (article_id, reviewer_id)
    )";
    
    try {
        $pdo->exec($sql1);
        $pdo->exec($sql2);
    } catch (PDOException $e) {
        // Tables might already exist
    }
}

createTables($pdo);

// ==================== GET REVIEWER INFO ====================
$stmtUser = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = :id");
$stmtUser->execute([':id' => $reviewerId]);
$reviewer = $stmtUser->fetch();
$reviewerName = $reviewer ? $reviewer['first_name'] . ' ' . $reviewer['last_name'] : 'Évaluateur';

// ==================== GET ASSIGNED ARTICLES ====================
$stmt = $pdo->prepare("
    SELECT 
        a.id,
        a.reference as ref,
        a.titre_fr as title,
        a.resume_fr as abstract,
        a.domaine as specialty,
        c.name_fr as conf,
        c.id as conference_id,
        a.date_soumission as submission_date,
        ar.assigned_date,
        DATE_ADD(ar.assigned_date, INTERVAL 14 DAY) as deadline,
        ar.status as review_status,
        a.status as article_status,
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
        a.fichier_principal as file,
        CASE
            WHEN e.id IS NOT NULL THEN 'done'
            WHEN a.status = 'revised' THEN 'revised'
            ELSE 'pending'
        END AS display_status
    FROM article_reviewers ar
    JOIN articles a ON a.id = ar.article_id
    JOIN conferences c ON c.id = a.conference_id
    LEFT JOIN evaluations e ON e.article_id = a.id AND e.reviewer_id = ar.reviewer_id
    WHERE ar.reviewer_id = :reviewer_id
    ORDER BY deadline ASC
");

$stmt->execute([':reviewer_id' => $reviewerId]);
$assignments = $stmt->fetchAll();

// Calculate deadline info for each assignment
foreach ($assignments as &$a) {
    $today = new DateTime('today');
    $deadlineDate = new DateTime($a['deadline']);
    $diff = (int)$today->diff($deadlineDate)->format('%r%a');
    
    if ($diff < 0) {
        $a['deadline_label'] = 'Expiré';
        $a['deadline_urgency'] = 'urgent';
    } elseif ($diff <= 2) {
        $a['deadline_label'] = "Dans {$diff} jour" . ($diff > 1 ? 's' : '');
        $a['deadline_urgency'] = 'urgent';
    } elseif ($diff <= 7) {
        $a['deadline_label'] = "Dans {$diff} jours";
        $a['deadline_urgency'] = 'soon';
    } else {
        $a['deadline_label'] = "Dans {$diff} jours";
        $a['deadline_urgency'] = 'ok';
    }
    
    $a['status_label'] = match($a['display_status']) {
        'done' => 'Évalué',
        'revised' => 'À re-évaluer',
        default => 'À évaluer'
    };
    
    $a['assigned_date_formatted'] = date('d M Y', strtotime($a['assigned_date']));
}
unset($a);

// Count stats
$stats = [
    'all' => count($assignments),
    'pending' => count(array_filter($assignments, fn($a) => $a['display_status'] === 'pending')),
    'revised' => count(array_filter($assignments, fn($a) => $a['display_status'] === 'revised')),
    'done' => count(array_filter($assignments, fn($a) => $a['display_status'] === 'done'))
];

// ==================== AJAX HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    
    // Submit evaluation
    if ($action === 'submit_evaluation') {
        $articleId = (int)($_POST['article_id'] ?? 0);
        $originality = (int)($_POST['originality'] ?? 0);
        $methodology = (int)($_POST['methodology'] ?? 0);
        $quality = (int)($_POST['quality'] ?? 0);
        $significance = (int)($_POST['significance'] ?? 0);
        $language = (int)($_POST['language'] ?? 0);
        $format = (int)($_POST['format'] ?? 0);
        $strengths = trim($_POST['strengths'] ?? '');
        $weaknesses = trim($_POST['weaknesses'] ?? '');
        $suggestions = trim($_POST['suggestions'] ?? '');
        $recommendation = $_POST['recommendation'] ?? '';
        
        $errors = [];
        
        if (empty($strengths)) $errors[] = "Les points forts sont obligatoires.";
        if (empty($weaknesses)) $errors[] = "Les points faibles sont obligatoires.";
        if (!in_array($recommendation, ['accept', 'minor_revision', 'major_revision', 'reject'])) {
            $errors[] = "Décision invalide.";
        }
        
        if (empty($errors)) {
            try {
                // Check if evaluation already exists
                $checkStmt = $pdo->prepare("SELECT id FROM evaluations WHERE article_id = ? AND reviewer_id = ?");
                $checkStmt->execute([$articleId, $reviewerId]);
                
                if ($checkStmt->fetch()) {
                    // Update existing evaluation
                    $updateStmt = $pdo->prepare("
                        UPDATE evaluations SET 
                            originality = ?, methodology = ?, quality = ?, significance = ?, 
                            language = ?, format = ?, strengths = ?, weaknesses = ?, 
                            suggestions = ?, recommendation = ?, completed_date = CURDATE()
                        WHERE article_id = ? AND reviewer_id = ?
                    ");
                    $updateStmt->execute([
                        $originality, $methodology, $quality, $significance,
                        $language, $format, $strengths, $weaknesses,
                        $suggestions, $recommendation, $articleId, $reviewerId
                    ]);
                } else {
                    // Insert new evaluation
                    $insertStmt = $pdo->prepare("
                        INSERT INTO evaluations (
                            article_id, reviewer_id, originality, methodology, quality,
                            significance, language, format, strengths, weaknesses,
                            suggestions, recommendation, assigned_date, completed_date
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), CURDATE())
                    ");
                    $insertStmt->execute([
                        $articleId, $reviewerId, $originality, $methodology, $quality,
                        $significance, $language, $format, $strengths, $weaknesses,
                        $suggestions, $recommendation
                    ]);
                }
                
                // Update article_reviewers status
                $updateArStmt = $pdo->prepare("
                    UPDATE article_reviewers 
                    SET status = 'completed', completed_date = CURDATE()
                    WHERE article_id = ? AND reviewer_id = ?
                ");
                $updateArStmt->execute([$articleId, $reviewerId]);
                
                // Update article status based on all reviews
                $reviewCountStmt = $pdo->prepare("
                    SELECT COUNT(*) as total, 
                           SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                    FROM article_reviewers 
                    WHERE article_id = ?
                ");
                $reviewCountStmt->execute([$articleId]);
                $reviewStats = $reviewCountStmt->fetch();
                
                if ($reviewStats['completed'] == $reviewStats['total']) {
                    $newStatus = match($recommendation) {
                        'accept' => 'accepte',
                        'reject' => 'refuse',
                        'minor_revision', 'major_revision' => 'revised',
                        default => 'en_evaluation'
                    };
                    $updateArticleStmt = $pdo->prepare("
                        UPDATE articles SET status = ?, date_decision = CURDATE()
                        WHERE id = ?
                    ");
                    $updateArticleStmt->execute([$newStatus, $articleId]);
                }
                
                echo json_encode(['ok' => true, 'msg' => 'Évaluation soumise avec succès']);
            } catch (PDOException $e) {
                echo json_encode(['ok' => false, 'msg' => 'Erreur: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'msg' => implode('<br>', $errors)]);
        }
        exit;
    }
    
    // Submit re-review decision
    if ($action === 'submit_rereview') {
        $articleId = (int)($_POST['article_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $comment = trim($_POST['comment'] ?? '');
        
        if (!in_array($decision, ['accept', 'reject'])) {
            echo json_encode(['ok' => false, 'msg' => 'Décision invalide']);
            exit;
        }
        
        try {
            // Update evaluation with final decision
            $updateStmt = $pdo->prepare("
                UPDATE evaluations 
                SET recommendation = ?, 
                    strengths = CONCAT(strengths, '\n\n[RÉ-ÉVALUATION] ', ?),
                    completed_date = CURDATE()
                WHERE article_id = ? AND reviewer_id = ?
            ");
            $updateStmt->execute([$decision, $comment, $articleId, $reviewerId]);
            
            // Update article_reviewers status
            $updateArStmt = $pdo->prepare("
                UPDATE article_reviewers 
                SET status = 'completed', completed_date = CURDATE()
                WHERE article_id = ? AND reviewer_id = ?
            ");
            $updateArStmt->execute([$articleId, $reviewerId]);
            
            // Update article final status
            $newStatus = $decision === 'accept' ? 'accepte' : 'refuse';
            $updateArticleStmt = $pdo->prepare("
                UPDATE articles SET status = ?, date_decision = CURDATE()
                WHERE id = ?
            ");
            $updateArticleStmt->execute([$newStatus, $articleId]);
            
            echo json_encode(['ok' => true, 'msg' => 'Décision finale enregistrée']);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'msg' => 'Erreur: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Get article details
    if ($action === 'get_article') {
        $articleId = (int)($_POST['article_id'] ?? 0);
        
        $stmt = $pdo->prepare("
            SELECT 
                a.id, a.reference as ref, a.titre_fr as title, a.resume_fr as abstract,
                a.domaine as specialty, c.name_fr as conf, a.fichier_principal as file,
                e.originality, e.methodology, e.quality, e.significance, e.language, e.format,
                e.strengths, e.weaknesses, e.suggestions, e.recommendation
            FROM articles a
            JOIN conferences c ON a.conference_id = c.id
            LEFT JOIN evaluations e ON e.article_id = a.id AND e.reviewer_id = ?
            WHERE a.id = ?
        ");
        $stmt->execute([$reviewerId, $articleId]);
        $article = $stmt->fetch();
        
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
<title>ConfManager — Articles Assignés</title>
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
    --shadow-sm: 0 1px 4px rgba(13,33,55,0.07);
    --shadow: 0 4px 20px rgba(13,33,55,0.10);
    --shadow-md: 0 8px 32px rgba(13,33,55,0.15);
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

  /* ─── PAGE ─── */
  .page { max-width: 1100px; margin: 0 auto; padding: 40px 32px 60px; }
  .page-header { margin-bottom: 28px; }
  .page-header-row { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 6px; }
  .page-header h1 { font-family: 'Libre Baskerville', serif; font-size: 32px; font-weight: 700; color: var(--navy); letter-spacing: -0.5px; }
  .page-header h1 em { font-style: italic; color: var(--gold); }
  .page-header p { font-size: 14px; color: var(--muted); margin-top: 4px; }

  /* ─── FILTERS BAR ─── */
  .filters-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
  .filter-tabs {
    display: flex; gap: 4px; background: var(--white);
    border: 1px solid var(--border); border-radius: 20px; padding: 4px;
    box-shadow: var(--shadow-sm);
  }
  .filter-tab {
    padding: 7px 16px; font-size: 13px; font-weight: 500;
    color: var(--muted); background: none; border: none;
    border-radius: 16px; cursor: pointer; font-family: 'DM Sans', sans-serif;
    transition: all 0.15s; display: flex; align-items: center; gap: 6px;
  }
  .filter-tab:hover { color: var(--navy); }
  .filter-tab.active { background: var(--navy); color: var(--gold-light); }
  .filter-count { font-size: 10px; font-weight: 700; background: rgba(255,255,255,0.2); padding: 1px 5px; border-radius: 8px; }
  .filter-tab:not(.active) .filter-count { background: var(--bg); color: var(--muted); }
  .filters-right { margin-left: auto; display: flex; gap: 10px; }
  .sort-select {
    padding: 8px 12px; border: 1px solid var(--border);
    border-radius: var(--radius-sm); font-size: 13px;
    font-family: 'DM Sans', sans-serif; color: var(--text-light);
    background: var(--white); outline: none; cursor: pointer;
  }

  /* ─── ARTICLES TABLE ─── */
  .articles-table-wrap { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
  .table-head { display: grid; grid-template-columns: 2.2fr 1fr 1fr 120px 140px; background: var(--bg); border-bottom: 1px solid var(--border); }
  .th { padding: 12px 18px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); }
  .th:first-child { padding-left: 24px; }
  .th:last-child  { padding-right: 24px; text-align: right; }

  .article-row { display: grid; grid-template-columns: 2.2fr 1fr 1fr 120px 140px; border-bottom: 1px solid var(--border); transition: background 0.12s; align-items: center; }
  .article-row:last-child { border-bottom: none; }
  .article-row:hover { background: #fafbfd; }

  .td { padding: 16px 18px; }
  .td:first-child { padding-left: 24px; }
  .td:last-child  { padding-right: 24px; }

  .article-title-main { font-size: 14px; font-weight: 600; color: var(--navy); margin-bottom: 4px; line-height: 1.35; }
  .article-meta-row   { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .article-ref { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--muted); background: var(--bg); padding: 2px 7px; border-radius: 3px; border: 1px solid var(--border); }
  .article-conf    { font-size: 12px; color: var(--text-light); }
  .td-domain       { font-size: 13px; color: var(--text-light); }
  .deadline-cell   { font-size: 12.5px; font-weight: 500; }
  .deadline-cell.urgent { color: var(--danger); }
  .deadline-cell.soon   { color: var(--warning); }
  .deadline-cell.ok     { color: var(--success); }

  /* BADGES */
  .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
  .badge-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
  .badge.pending   { background: #fef8ec; color: #9a5f00;  border: 1px solid #f5d98a; }
  .badge.pending   .badge-dot { background: var(--warning); }
  .badge.reviewing { background: #eef3fb; color: #1a4a7a;  border: 1px solid #b3cdf0; }
  .badge.reviewing .badge-dot { background: var(--accent); }
  .badge.done      { background: #e8f6f3; color: #1a5f57;  border: 1px solid #9dd8d0; }
  .badge.done      .badge-dot { background: var(--success); }
  .badge.revised   { background: #f0f4ff; color: #3347bb;  border: 1px solid #bcc7fa; }
  .badge.revised   .badge-dot { background: #5b6ef5; }

  /* ROW ACTIONS */
  .row-actions { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
  .action-btn {
    height: 30px; padding: 0 10px; border-radius: var(--radius-sm);
    border: 1px solid var(--border); background: none;
    color: var(--muted); cursor: pointer; font-size: 12px;
    display: flex; align-items: center; gap: 5px;
    transition: all 0.15s; font-family: 'DM Sans', sans-serif; white-space: nowrap;
  }
  .action-btn:hover { background: var(--bg); color: var(--navy); border-color: var(--navy); }
  .action-btn.primary { background: var(--navy); color: var(--gold-light); border-color: var(--navy); }
  .action-btn.primary:hover { background: var(--navy-mid); }
  .action-btn.revised-btn { border-color: #bcc7fa; color: #5b6ef5; }
  .action-btn.revised-btn:hover { background: #f0f4ff; color: #3347bb; }

  /* ─── PAGINATION ─── */
  .pagination { display: flex; align-items: center; justify-content: space-between; margin-top: 24px; padding: 0 4px; }
  .page-info { font-size: 13px; color: var(--muted); }
  .page-btns { display: flex; gap: 4px; }
  .page-btn {
    width: 34px; height: 34px; border: 1px solid var(--border);
    border-radius: var(--radius-sm); background: var(--white);
    color: var(--text-light); font-size: 13px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-family: 'DM Sans', sans-serif; transition: all 0.15s;
  }
  .page-btn:hover { border-color: var(--navy); color: var(--navy); }
  .page-btn.active { background: var(--navy); color: var(--gold-light); border-color: var(--navy); }

  /* ─── MODALS ─── */
  .modal-backdrop {
    position: fixed; inset: 0; background: rgba(13,33,55,0.5);
    backdrop-filter: blur(4px); z-index: 200;
    display: none; align-items: center; justify-content: center; padding: 20px;
  }
  .modal-backdrop.open { display: flex; }
  .modal {
    background: var(--white); border-radius: var(--radius);
    width: 100%; max-width: 580px; box-shadow: var(--shadow-md);
    animation: fadeUp 0.2s ease; max-height: 90vh; overflow-y: auto;
  }
  .modal.modal-wide  { max-width: 700px; }
  .modal.modal-xl    { max-width: 900px; }
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
    transition: all 0.15s; flex-shrink: 0;
  }
  .modal-close:hover { background: var(--bg); color: var(--navy); }
  .modal-body { padding: 24px; }
  .modal-footer {
    padding: 16px 24px; border-top: 1px solid var(--border);
    display: flex; justify-content: flex-end; gap: 10px;
    position: sticky; bottom: 0; background: var(--white);
  }
  .modal-detail-row { display: flex; gap: 10px; margin-bottom: 14px; align-items: flex-start; }
  .modal-detail-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); width: 110px; flex-shrink: 0; padding-top: 2px; }
  .modal-detail-value { font-size: 13.5px; color: var(--text); flex: 1; line-height: 1.5; }

  .btn-primary {
    background: var(--navy); color: var(--gold-light);
    border: none; border-radius: var(--radius-sm);
    padding: 11px 22px; font-size: 13.5px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer;
    transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none; white-space: nowrap;
  }
  .btn-primary:hover { background: var(--navy-mid); }
  .btn-primary:disabled { opacity: .5; cursor: not-allowed; }
  .btn-secondary {
    background: none; color: var(--text-light);
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    padding: 9px 20px; font-size: 13.5px;
    font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s;
  }
  .btn-secondary:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }

  /* ─── REVIEW FORM ─── */
  .form-section { margin-bottom: 28px; }
  .form-section-title {
    font-size: 13px; font-weight: 600; color: var(--navy);
    text-transform: uppercase; letter-spacing: .8px;
    margin-bottom: 16px; padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 8px;
  }
  .form-section-title i { color: var(--gold); }

  /* STAR RATING */
  .criteria-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .criteria-item { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 16px; }
  .criteria-label { font-size: 12.5px; font-weight: 500; color: var(--text-light); margin-bottom: 10px; }
  .star-row { display: flex; gap: 6px; align-items: center; }
  .star-btn {
    font-size: 20px; cursor: pointer; color: var(--border);
    background: none; border: none; padding: 0; transition: all 0.1s;
    line-height: 1;
  }
  .star-btn.active, .star-btn:hover { color: var(--gold); }
  .star-value { font-size: 12px; color: var(--muted); margin-left: 6px; font-family: 'DM Mono', monospace; }

  /* TEXTAREA */
  .form-group { margin-bottom: 16px; }
  .form-label { font-size: 12.5px; font-weight: 500; color: var(--text-light); margin-bottom: 6px; display: block; }
  .form-label span { color: var(--muted); font-weight: 400; }
  .form-textarea {
    width: 100%; border: 1px solid var(--border); border-radius: var(--radius-sm);
    padding: 10px 14px; font-size: 13px; font-family: 'DM Sans', sans-serif;
    color: var(--text); resize: vertical; min-height: 80px; outline: none;
    background: var(--white); transition: border-color 0.15s;
  }
  .form-textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(44,111,173,0.08); }

  /* DECISION CARDS */
  .decision-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .decision-card {
    padding: 14px 16px; border: 2px solid var(--border);
    border-radius: var(--radius); cursor: pointer; transition: all 0.2s;
    display: flex; align-items: flex-start; gap: 12px;
  }
  .decision-card:hover { border-color: var(--accent); background: #f5f8ff; }
  .decision-card.selected { border-color: var(--navy); background: #f0f4fb; }
  .decision-card.selected.accept    { border-color: var(--success); background: #e8f6f3; }
  .decision-card.selected.minor     { border-color: var(--warning); background: #fef8ec; }
  .decision-card.selected.major     { border-color: #5b6ef5; background: #f0f4ff; }
  .decision-card.selected.reject    { border-color: var(--danger); background: #fdf2f2; }
  .decision-icon { font-size: 22px; flex-shrink: 0; margin-top: 2px; }
  .decision-title { font-size: 13px; font-weight: 600; color: var(--navy); margin-bottom: 3px; }
  .decision-sub   { font-size: 11.5px; color: var(--muted); line-height: 1.4; }

  /* DOWNLOAD ZONE */
  .download-zone {
    background: var(--bg); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: 16px 20px;
    display: flex; align-items: center; gap: 14px;
    margin-bottom: 20px;
  }
  .download-icon { font-size: 28px; color: var(--danger); flex-shrink: 0; }
  .download-info { flex: 1; }
  .download-name { font-size: 13.5px; font-weight: 600; color: var(--navy); }
  .download-meta { font-size: 12px; color: var(--muted); margin-top: 2px; }
  .download-btn {
    padding: 8px 18px; background: var(--navy); color: var(--gold-light);
    border: none; border-radius: var(--radius-sm); font-size: 13px;
    font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s;
    display: flex; align-items: center; gap: 7px; white-space: nowrap;
  }
  .download-btn:hover { background: var(--navy-mid); }

  /* VERSION COMPARISON */
  .version-compare {
    background: var(--bg); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 20px; margin-bottom: 20px;
  }
  .version-tabs {
    display: flex; gap: 8px; margin-bottom: 16px;
    border-bottom: 1px solid var(--border); padding-bottom: 12px;
  }
  .version-tab {
    padding: 8px 16px; border: none; background: none;
    font-family: 'DM Sans', sans-serif; font-size: 13px;
    color: var(--muted); cursor: pointer; border-radius: var(--radius-sm);
    transition: all 0.15s;
  }
  .version-tab:hover { color: var(--navy); background: var(--white); }
  .version-tab.active { background: var(--navy); color: var(--gold-light); }
  .version-content { display: none; }
  .version-content.active { display: block; }

  /* REVISED BANNER */
  .revised-banner {
    background: #f0f4ff; border: 1px solid #bcc7fa;
    border-left: 4px solid #5b6ef5;
    border-radius: var(--radius-sm); padding: 14px 18px;
    margin-bottom: 18px;
  }
  .revised-banner-title { font-size: 13px; font-weight: 600; color: #3347bb; margin-bottom: 4px; }
  .revised-banner-text  { font-size: 12.5px; color: var(--text-light); line-height: 1.55; }

  /* TOAST */
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
  .toast i { font-size: 15px; }

  /* FOOTER */
  .footer { background: var(--navy); color: rgba(255,255,255,0.45); text-align: center; padding: 22px; font-size: 13px; margin-top: 48px; }

  @keyframes fadeUp   { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:none} }
  @keyframes toastIn  { from{opacity:0;transform:translateX(30px)} to{opacity:1;transform:none} }
  @keyframes toastOut { from{opacity:1;transform:none} to{opacity:0;transform:translateX(30px)} }

  @media(max-width:900px) {
    .table-head,.article-row { grid-template-columns: 2fr 120px 140px; }
    .td-domain,.th:nth-child(2) { display:none; }
    .criteria-grid { grid-template-columns: 1fr; }
  }
  @media(max-width:650px) {
    .topbar { padding: 0 16px; }
    .page { padding: 24px 16px; }
    .decision-grid { grid-template-columns: 1fr; }
    .filters-right { display: none; }
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
    <a href="reviewer_dashboard.php" class="nav-link">Tableau de bord</a>
    <a href="review_articles.php" class="nav-link active">Articles assignés</a>
    <a href="profile.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</header>

<!-- ═══════════════════════════════════════════════
     ARTICLES ASSIGNÉS
═══════════════════════════════════════════════ -->
<main class="page">
  <div class="page-header">
    <div class="page-header-row">
      <h1>Articles <em>assignés</em></h1>
    </div>
    <p>Articles qui vous ont été confiés pour évaluation</p>
  </div>

  <div class="filters-bar">
    <div class="filter-tabs">
      <button class="filter-tab active" data-filter="all" onclick="setFilter(this,'all')">Tous <span class="filter-count" id="cnt-all"><?= $stats['all'] ?></span></button>
      <button class="filter-tab" data-filter="pending" onclick="setFilter(this,'pending')">À évaluer <span class="filter-count" id="cnt-pending"><?= $stats['pending'] ?></span></button>
      <button class="filter-tab" data-filter="revised" onclick="setFilter(this,'revised')">À re-évaluer <span class="filter-count" id="cnt-revised"><?= $stats['revised'] ?></span></button>
      <button class="filter-tab" data-filter="done" onclick="setFilter(this,'done')">Évalués <span class="filter-count" id="cnt-done"><?= $stats['done'] ?></span></button>
    </div>
    <div class="filters-right">
      <select class="sort-select" onchange="sortRows(this.value)">
        <option value="deadline-asc">Échéance proche</option>
        <option value="date-desc">Plus récent</option>
        <option value="title">Titre A→Z</option>
      </select>
    </div>
  </div>

  <div class="articles-table-wrap">
    <div class="table-head">
      <div class="th">Article</div>
      <div class="th">Spécialité</div>
      <div class="th">Échéance</div>
      <div class="th">Statut</div>
      <div class="th" style="text-align:right">Action</div>
    </div>
    <div id="articleListBody">
      <?php foreach ($assignments as $a): ?>
        <div class="article-row" data-status="<?= $a['display_status'] ?>" data-title="<?= htmlspecialchars(strtolower($a['title'])) ?>" data-deadline="<?= $a['deadline'] ?>">
          <div class="td">
            <div class="article-title-main"><?= htmlspecialchars($a['title']) ?></div>
            <div class="article-meta-row">
              <span class="article-ref"><?= htmlspecialchars($a['ref']) ?></span>
              <span class="article-conf"><?= htmlspecialchars($a['conf']) ?></span>
              <?php if ($a['display_status'] === 'revised'): ?>
                <span class="badge revised" style="font-size:10px;padding:2px 7px"><span class="badge-dot"></span>Révisé</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="td td-domain"><?= htmlspecialchars($a['specialty']) ?></div>
          <div class="td"><span class="deadline-cell <?= $a['deadline_urgency'] ?>"><i class="fas fa-calendar-alt" style="margin-right:5px;opacity:.7"></i><?= $a['deadline_label'] ?></span></div>
          <div class="td"><span class="badge <?= $a['display_status'] ?>"><span class="badge-dot"></span><?= $a['status_label'] ?></span></div>
          <div class="td">
            <div class="row-actions">
              <?php if ($a['display_status'] === 'done'): ?>
                <button class="action-btn" onclick="openDetailModal(<?= $a['id'] ?>)"><i class="fas fa-eye"></i> Voir</button>
              <?php elseif ($a['display_status'] === 'revised'): ?>
                <button class="action-btn revised-btn" onclick="openReReviewModal(<?= $a['id'] ?>)"><i class="fas fa-sync-alt"></i> Re-évaluer</button>
              <?php else: ?>
                <button class="action-btn primary" onclick="openReviewForm(<?= $a['id'] ?>)"><i class="fas fa-play"></i> Commencer</button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="pagination">
    <span class="page-info" id="pageInfo">Affichage de 1–<?= $stats['all'] ?> sur <?= $stats['all'] ?> articles</span>
    <div class="page-btns">
      <button class="page-btn"><i class="fas fa-chevron-left"></i></button>
      <button class="page-btn active">1</button>
      <button class="page-btn"><i class="fas fa-chevron-right"></i></button>
    </div>
  </div>
</main>

<!-- MODAL: ARTICLE DETAIL -->
<div class="modal-backdrop" id="detailModal" onclick="closeOnBackdrop(event, 'detailModal')">
  <div class="modal modal-wide">
    <div class="modal-header">
      <div class="modal-title">Détail de l'article</div>
      <button class="modal-close" onclick="closeModal('detailModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="detailBody"></div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('detailModal')">Fermer</button>
    </div>
  </div>
</div>

<!-- MODAL: REVIEW FORM (FIRST REVIEW) -->
<div class="modal-backdrop" id="reviewModal" onclick="closeOnBackdrop(event, 'reviewModal')">
  <div class="modal modal-xl">
    <div class="modal-header">
      <div class="modal-title" id="reviewModalTitle"><i class="fas fa-pen-alt" style="color:var(--gold);margin-right:8px"></i>Formulaire d'évaluation</div>
      <button class="modal-close" onclick="closeModal('reviewModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="reviewModalBody"></div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('reviewModal')">Annuler</button>
      <button class="btn-primary" id="submitReviewBtn" onclick="submitReview()">
        <i class="fas fa-paper-plane"></i> Soumettre l'évaluation
      </button>
    </div>
  </div>
</div>

<!-- MODAL: RE-REVIEW (REVISED VERSION) -->
<div class="modal-backdrop" id="reReviewModal" onclick="closeOnBackdrop(event, 'reReviewModal')">
  <div class="modal modal-xl">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-sync-alt" style="color:var(--gold);margin-right:8px"></i>Re-évaluation — Version révisée</div>
      <button class="modal-close" onclick="closeModal('reReviewModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="reReviewBody"></div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('reReviewModal')">Annuler</button>
      <button class="btn-primary" onclick="submitReReview()">
        <i class="fas fa-gavel"></i> Soumettre la décision finale
      </button>
    </div>
  </div>
</div>

<!-- TOAST -->
<div class="toast-wrap" id="toastWrap"></div>

<footer class="footer">© <?= date('Y') ?> ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés</footer>

<script>
// ==================== PHP DATA INJECTION ====================
const assignments = <?= json_encode($assignments) ?>;
const csrfToken = '<?= $csrfToken ?>';

let currentFilter = 'all';
let activeArticleId = null;
let ratings = {};
let selectedDecision = null;
let selectedReReviewDecision = null;

// ==================== UTILITIES ====================
function showToast(msg, type = 'info') {
    const wrap = document.getElementById('toastWrap');
    const t = document.createElement('div');
    const icons = { success:'fa-check-circle', error:'fa-exclamation-circle', info:'fa-info-circle' };
    t.className = `toast ${type}`;
    t.innerHTML = `<i class="fas ${icons[type]||'fa-info-circle'}"></i> ${msg}`;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

function openModal(id) { 
    document.getElementById(id).classList.add('open'); 
    document.body.style.overflow = 'hidden'; 
}

function closeModal(id) { 
    document.getElementById(id).classList.remove('open'); 
    document.body.style.overflow = ''; 
}

function closeOnBackdrop(e, id) { 
    if(e.target === document.getElementById(id)) closeModal(id); 
}

document.addEventListener('keydown', e => {
    if(e.key === 'Escape') ['detailModal','reviewModal','reReviewModal'].forEach(id => closeModal(id));
});

// ==================== FILTERS ====================
function setFilter(btn, filter) {
    document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    currentFilter = filter;
    applyFilter();
}

function applyFilter() {
    const rows = document.querySelectorAll('#articleListBody .article-row');
    rows.forEach(row => {
        const show = currentFilter === 'all' || row.dataset.status === currentFilter;
        row.style.display = show ? '' : 'none';
    });
}

function sortRows(val) {
    const container = document.getElementById('articleListBody');
    const rows = [...container.querySelectorAll('.article-row')];
    rows.sort((a, b) => {
        if(val === 'title') return a.dataset.title.localeCompare(b.dataset.title);
        if(val === 'date-desc') return b.dataset.deadline.localeCompare(a.dataset.deadline);
        return a.dataset.deadline.localeCompare(b.dataset.deadline);
    });
    rows.forEach(r => container.appendChild(r));
}

// ==================== DETAIL MODAL ====================
function openDetailModal(id) {
    const a = assignments.find(x => x.id === id);
    if (!a) return;
    
    document.getElementById('detailBody').innerHTML = `
        <div class="download-zone">
            <i class="fas fa-file-pdf download-icon"></i>
            <div class="download-info">
                <div class="download-name">${escapeHtml(a.file)}</div>
                <div class="download-meta">PDF · Assigné le ${a.assigned_date_formatted}</div>
            </div>
            <button class="download-btn" onclick="showToast('Fonctionnalité de téléchargement disponible dans la version complète','info')">
                <i class="fas fa-download"></i> Télécharger
            </button>
        </div>
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
            <span class="modal-detail-value" style="color:var(--text-light)">${escapeHtml(a.abstract || 'Aucun résumé disponible')}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Conférence</span>
            <span class="modal-detail-value">${escapeHtml(a.conf)}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Spécialité</span>
            <span class="modal-detail-value">${escapeHtml(a.specialty)}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Échéance</span>
            <span class="modal-detail-value"><span class="deadline-cell ${a.deadline_urgency}"><i class="fas fa-calendar-alt" style="margin-right:5px"></i>${a.deadline_label}</span></span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Statut</span>
            <span class="modal-detail-value"><span class="badge ${a.display_status}"><span class="badge-dot"></span>${a.status_label}</span></span>
        </div>
        ${a.recommendation ? `
        <div class="modal-detail-row">
            <span class="modal-detail-label">Décision</span>
            <span class="modal-detail-value"><span class="badge ${a.recommendation === 'accept' ? 'done' : 'pending'}">
                <span class="badge-dot"></span>${a.recommendation === 'accept' ? 'Accepté' : (a.recommendation === 'reject' ? 'Rejeté' : 'Révisions demandées')}
            </span></span>
        </div>` : ''}
    `;
    openModal('detailModal');
}

// ==================== REVIEW FORM ====================
function openReviewForm(id) {
    const a = assignments.find(x => x.id === id);
    if (!a) return;
    activeArticleId = id;
    
    if (!ratings[id]) {
        ratings[id] = { originality:0, methodology:0, quality:0, significance:0, language:0, format:0 };
    }
    selectedDecision = null;
    
    const criteriaList = [
        { key: 'originality', label: 'Originalité et innovation' },
        { key: 'methodology', label: 'Méthodologie scientifique' },
        { key: 'quality', label: 'Qualité des résultats' },
        { key: 'significance', label: 'Significance scientifique' },
        { key: 'language', label: 'Langue et style' },
        { key: 'format', label: 'Format et mise en page' },
    ];
    
    document.getElementById('reviewModalTitle').innerHTML = `<i class="fas fa-pen-alt" style="color:var(--gold);margin-right:8px"></i>Évaluation — ${a.ref}`;
    document.getElementById('reviewModalBody').innerHTML = `
    <!-- Add this to the review form page -->
<div class="evaluation-panel" style="background: var(--bg); border-radius: var(--radius); padding: 20px; margin-bottom: 24px;">
    <h3 style="color: var(--navy); margin-bottom: 16px;">
        <i class="fas fa-robot"></i> Analyse automatique de l'article
    </h3>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <!-- Similarity Card -->
        <div style="background: white; border-radius: var(--radius-sm); padding: 16px; border: 1px solid var(--border);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <div>
                    <i class="fas fa-copy" style="color: var(--accent);"></i>
                    <strong style="margin-left: 8px;">Similarité</strong>
                </div>
                <span class="badge" style="background: <?= ($similarityScore > 30) ? 'var(--danger-light)' : 'var(--success-light)' ?>; color: <?= ($similarityScore > 30) ? 'var(--danger)' : 'var(--success)' ?>;">
                    <?= $similarityScore ?>% <?= ($similarityScore > 30) ? '⚠️' : '✓' ?>
                </span>
            </div>
            <div class="progress-bar" style="height: 8px; background: var(--border); border-radius: 4px;">
                <div class="progress-fill" style="width: <?= min(100, $similarityScore) ?>%; background: <?= ($similarityScore > 30) ? 'var(--danger)' : 'var(--success)' ?>;"></div>
            </div>
            <?php if ($similarityScore > 30): ?>
                <div style="margin-top: 12px; padding: 8px; background: var(--danger-light); border-radius: var(--radius-sm); font-size: 12px;">
                    <i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i>
                    Similarité élevée détectée. Veuillez examiner attentivement.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- AI Detection Card -->
        <div style="background: white; border-radius: var(--radius-sm); padding: 16px; border: 1px solid var(--border);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <div>
                    <i class="fas fa-brain" style="color: var(--purple);"></i>
                    <strong style="margin-left: 8px;">IA Générée</strong>
                </div>
                <span class="badge" style="background: <?= ($aiScore > 40) ? 'var(--danger-light)' : 'var(--success-light)' ?>; color: <?= ($aiScore > 40) ? 'var(--danger)' : 'var(--success)' ?>;">
                    <?= $aiScore ?>% <?= ($aiScore > 40) ? '⚠️' : '✓' ?>
                </span>
            </div>
            <div class="progress-bar" style="height: 8px; background: var(--border); border-radius: 4px;">
                <div class="progress-fill" style="width: <?= min(100, $aiScore) ?>%; background: <?= ($aiScore > 40) ? 'var(--danger)' : 'var(--success)' ?>;"></div>
            </div>
            <?php if ($aiScore > 40): ?>
                <div style="margin-top: 12px; padding: 8px; background: var(--danger-light); border-radius: var(--radius-sm); font-size: 12px;">
                    <i class="fas fa-robot" style="color: var(--danger);"></i>
                    Probabilité élevée de contenu généré par IA. Vérification recommandée.
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($similarityScore > 30 || $aiScore > 40): ?>
    <div style="margin-top: 16px; padding: 12px; background: #fff3cd; border-radius: var(--radius-sm); border-left: 4px solid var(--warning);">
        <strong style="color: var(--warning);">⚠️ Alerte de qualité</strong>
        <p style="margin-top: 4px; font-size: 13px; color: var(--text-light);">
            Cet article présente des indicateurs de risque. Veuillez porter une attention particulière à l'originalité du contenu.
        </p>
    </div>
    <?php endif; ?>
</div>
        <div class="download-zone">
            <i class="fas fa-file-pdf download-icon"></i>
            <div class="download-info">
                <div class="download-name">${escapeHtml(a.file)}</div>
                <div class="download-meta">PDF · ${a.file_size || 'Taille inconnue'}</div>
            </div>
            <button class="download-btn" onclick="showToast('Fonctionnalité de téléchargement disponible','info')">
                <i class="fas fa-download"></i> Télécharger
            </button>
        </div>

        <div class="form-section">
            <div class="form-section-title"><i class="fas fa-star"></i> Critères scientifiques</div>
            <div class="criteria-grid">
                ${criteriaList.map(c => `
                    <div class="criteria-item">
                        <div class="criteria-label">${c.label}</div>
                        <div class="star-row" id="stars-${c.key}">
                            ${[1,2,3,4,5].map(n => `
                                <button class="star-btn ${ratings[id][c.key] >= n ? 'active' : ''}"
                                    onclick="setRating(${id},'${c.key}',${n})">★</button>
                            `).join('')}
                            <span class="star-value" id="sv-${c.key}">${ratings[id][c.key] > 0 ? ratings[id][c.key]+'/5' : '—'}</span>
                        </div>
                    </div>`).join('')}
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title"><i class="fas fa-comments"></i> Commentaires détaillés</div>
            <div class="form-group">
                <label class="form-label">Points forts <span>(obligatoire)</span></label>
                <textarea class="form-textarea" id="strengths" placeholder="Décrivez les aspects positifs..."></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Points faibles <span>(obligatoire)</span></label>
                <textarea class="form-textarea" id="weaknesses" placeholder="Décrivez les lacunes..."></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Suggestions d'amélioration <span>(optionnel)</span></label>
                <textarea class="form-textarea" id="suggestions" placeholder="Recommandations..."></textarea>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title"><i class="fas fa-gavel"></i> Décision finale</div>
            <div class="decision-grid">
                <div class="decision-card accept" onclick="selectDecision(this,'accept')">
                    <div class="decision-icon">✅</div>
                    <div><div class="decision-title">Accepté sans révision</div><div class="decision-sub">Article prêt pour publication</div></div>
                </div>
                <div class="decision-card minor" onclick="selectDecision(this,'minor')">
                    <div class="decision-icon">⚠️</div>
                    <div><div class="decision-title">Révisions mineures</div><div class="decision-sub">Corrections légères requises</div></div>
                </div>
                <div class="decision-card major" onclick="selectDecision(this,'major')">
                    <div class="decision-icon">🔄</div>
                    <div><div class="decision-title">Révisions majeures</div><div class="decision-sub">Travail important nécessaire</div></div>
                </div>
                <div class="decision-card reject" onclick="selectDecision(this,'reject')">
                    <div class="decision-icon">❌</div>
                    <div><div class="decision-title">Rejeté</div><div class="decision-sub">Article non acceptable</div></div>
                </div>
            </div>
        </div>
    `;
    openModal('reviewModal');
}

function setRating(artId, key, val) {
    if(!ratings[artId]) ratings[artId] = {};
    ratings[artId][key] = val;
    const row = document.getElementById('stars-' + key);
    row.querySelectorAll('.star-btn').forEach((btn, i) => {
        btn.classList.toggle('active', i < val);
    });
    document.getElementById('sv-' + key).textContent = val + '/5';
}

function selectDecision(card, val) {
    document.querySelectorAll('#reviewModal .decision-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    selectedDecision = val;
}

function submitReview() {
    const strengths = document.getElementById('strengths')?.value.trim();
    const weaknesses = document.getElementById('weaknesses')?.value.trim();
    const suggestions = document.getElementById('suggestions')?.value.trim() || '';
    
    if(!strengths) { showToast('Veuillez renseigner les points forts.', 'error'); return; }
    if(!weaknesses) { showToast('Veuillez renseigner les points faibles.', 'error'); return; }
    if(!selectedDecision) { showToast('Veuillez choisir une décision.', 'error'); return; }
    
    const recommendationMap = {
        'accept': 'accept',
        'minor': 'minor_revision',
        'major': 'major_revision',
        'reject': 'reject'
    };
    
    const btn = document.getElementById('submitReviewBtn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi...';
    btn.disabled = true;
    
    const formData = new FormData();
    formData.append('action', 'submit_evaluation');
    formData.append('article_id', activeArticleId);
    formData.append('originality', ratings[activeArticleId]?.originality || 0);
    formData.append('methodology', ratings[activeArticleId]?.methodology || 0);
    formData.append('quality', ratings[activeArticleId]?.quality || 0);
    formData.append('significance', ratings[activeArticleId]?.significance || 0);
    formData.append('language', ratings[activeArticleId]?.language || 0);
    formData.append('format', ratings[activeArticleId]?.format || 0);
    formData.append('strengths', strengths);
    formData.append('weaknesses', weaknesses);
    formData.append('suggestions', suggestions);
    formData.append('recommendation', recommendationMap[selectedDecision]);
    
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.ok) {
                showToast('Évaluation soumise avec succès !', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.msg || 'Erreur lors de l\'envoi', 'error');
            }
        })
        .catch(() => showToast('Erreur de communication', 'error'))
        .finally(() => {
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Soumettre l\'évaluation';
            btn.disabled = false;
        });
}

// ==================== RE-REVIEW MODAL ====================
function openReReviewModal(id) {
    const a = assignments.find(x => x.id === id);
    if (!a) return;
    activeArticleId = id;
    selectedReReviewDecision = null;
    
    document.getElementById('reReviewBody').innerHTML = `
        <div class="revised-banner">
            <div class="revised-banner-title"><i class="fas fa-sync-alt" style="margin-right:7px"></i>Version révisée reçue</div>
            <div class="revised-banner-text"><strong>Réponse de l'auteur :</strong> L'auteur a soumis une version révisée de l'article. Veuillez évaluer les modifications apportées.</div>
        </div>
        
        <div class="download-zone">
            <i class="fas fa-file-pdf download-icon" style="color:#5b6ef5"></i>
            <div class="download-info">
                <div class="download-name">${escapeHtml(a.file)}</div>
                <div class="download-meta">Version révisée</div>
            </div>
            <button class="download-btn" onclick="showToast('Téléchargement disponible','info')">
                <i class="fas fa-download"></i> Télécharger
            </button>
        </div>

        <div class="form-section">
            <div class="form-section-title"><i class="fas fa-gavel"></i> Décision finale</div>
            <div class="decision-grid">
                <div class="decision-card accept" onclick="selectReReviewDecision(this,'accept')">
                    <div class="decision-icon">✅</div>
                    <div><div class="decision-title">Accepter</div><div class="decision-sub">Les révisions sont satisfaisantes</div></div>
                </div>
                <div class="decision-card reject" onclick="selectReReviewDecision(this,'reject')">
                    <div class="decision-icon">❌</div>
                    <div><div class="decision-title">Rejeter</div><div class="decision-sub">Les corrections sont insuffisantes</div></div>
                </div>
            </div>
            <div class="form-group" style="margin-top:16px">
                <label class="form-label">Commentaire final <span>(optionnel)</span></label>
                <textarea class="form-textarea" id="reReviewComment" placeholder="Justification de votre décision..."></textarea>
            </div>
        </div>
    `;
    openModal('reReviewModal');
}

function selectReReviewDecision(card, val) {
    document.querySelectorAll('#reReviewModal .decision-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    selectedReReviewDecision = val;
}

function submitReReview() {
    if(!selectedReReviewDecision) { 
        showToast('Veuillez choisir une décision.', 'error'); 
        return; 
    }
    
    const comment = document.getElementById('reReviewComment')?.value.trim() || '';
    const btn = document.querySelector('#reReviewModal .btn-primary');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi...';
    btn.disabled = true;
    
    const formData = new FormData();
    formData.append('action', 'submit_rereview');
    formData.append('article_id', activeArticleId);
    formData.append('decision', selectedReReviewDecision);
    formData.append('comment', comment);
    
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.ok) {
                showToast('Décision finale enregistrée !', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.msg || 'Erreur lors de l\'envoi', 'error');
            }
        })
        .catch(() => showToast('Erreur de communication', 'error'))
        .finally(() => {
            btn.innerHTML = '<i class="fas fa-gavel"></i> Soumettre la décision finale';
            btn.disabled = false;
        });
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

// Initialize
document.querySelectorAll('.filter-tab').forEach(tab => {
    if(tab.classList.contains('active')) {
        currentFilter = tab.dataset.filter;
    }
});
</script>
</body>
</html>