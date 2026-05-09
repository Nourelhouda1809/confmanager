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
                die("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$connection;
    }
}

$db = Database::getConnection();
$reviewerId = $_SESSION['user_id'];

// ==================== CREATE NOTIFICATIONS TABLE IF NOT EXISTS ====================
$createNotifTable = "
    CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type ENUM('new-article', 'revised', 'reminder', 'info') NOT NULL,
        title VARCHAR(255),
        message TEXT NOT NULL,
        article_id INT,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_read (is_read)
    )
";
try {
    $db->exec($createNotifTable);
} catch (PDOException $e) {
    // Table might already exist
}

// ==================== AJAX HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    
    if ($action === 'mark_notification_read') {
        $notifId = (int)$_POST['notif_id'];
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notifId, $reviewerId]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'mark_all_notifications_read') {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$reviewerId]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// ==================== GET REVIEWER INFO ====================
$stmtUser = $db->prepare("SELECT first_name, last_name FROM users WHERE id = :id");
$stmtUser->execute([':id' => $reviewerId]);
$reviewer = $stmtUser->fetch();
$reviewerName = $reviewer ? $reviewer['first_name'] . ' ' . $reviewer['last_name'] : 'Évaluateur';

// ==================== GET ASSIGNED ARTICLES WITH EVALUATIONS ====================
$stmt = $db->prepare("
    SELECT 
        a.id,
        a.reference as ref,
        a.titre_fr as title,
        a.resume_fr as abstract,
        a.domaine as specialty,
        c.name_fr as conf,
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
     
        e.completed_date,
        a.fichier_principal as file,
        a.similarity_score,
        a.ai_score,
        CASE
            WHEN e.id IS NOT NULL THEN 'done'
            WHEN a.status = 'revised' THEN 'revised'
            ELSE 'pending'
        END AS display_status,
        CASE
            WHEN e.recommendation = 'accept' THEN 'accepted'
            WHEN e.recommendation = 'reject' THEN 'rejected'
            ELSE NULL
        END AS submitted_decision
    FROM article_reviewers ar
    JOIN articles a ON a.id = ar.article_id
    JOIN conferences c ON c.id = a.conference_id
    LEFT JOIN evaluations e ON e.article_id = a.id AND e.reviewer_id = ar.reviewer_id
    WHERE ar.reviewer_id = :reviewer_id
    ORDER BY deadline ASC
");

$stmt->execute([':reviewer_id' => $reviewerId]);
$assignments = $stmt->fetchAll();

// Calculate deadline info and status labels
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

// Get authors for each article
foreach ($assignments as &$a) {
    $stmtAuthors = $db->prepare("
        SELECT u.first_name, u.last_name 
        FROM users u 
        JOIN articles a2 ON a2.utilisateur_id = u.id 
        WHERE a2.id = :article_id
    ");
    $stmtAuthors->execute([':article_id' => $a['id']]);
    $authors = $stmtAuthors->fetchAll();
    $a['authors'] = implode(', ', array_map(function($author) {
        return $author['first_name'] . ' ' . $author['last_name'];
    }, $authors));
}
unset($a);

// Calculate statistics
$stats = [
    'accepted' => count(array_filter($assignments, fn($a) => $a['submitted_decision'] === 'accepted')),
    'rejected' => count(array_filter($assignments, fn($a) => $a['submitted_decision'] === 'rejected')),
    'new' => count(array_filter($assignments, fn($a) => $a['display_status'] === 'pending')),
    'revised' => count(array_filter($assignments, fn($a) => $a['display_status'] === 'revised'))
];

// Get pending articles for deadline strip and table
$pendingArticles = array_filter($assignments, fn($a) => $a['display_status'] !== 'done');
usort($pendingArticles, fn($a, $b) => strcmp($a['deadline'], $b['deadline']));
$pendingTop4 = array_slice(array_values($pendingArticles), 0, 4);
$deadlineTop5 = array_slice(array_values($pendingArticles), 0, 5);

// ==================== GET NOTIFICATIONS ====================
$stmtNotif = $db->prepare("
    SELECT id, type, message, is_read, created_at, article_id
    FROM notifications
    WHERE user_id = :user_id
    ORDER BY created_at DESC
    LIMIT 20
");
$stmtNotif->execute([':user_id' => $reviewerId]);
$notifications = $stmtNotif->fetchAll();

// Format notification time
foreach ($notifications as &$n) {
    $createdAt = new DateTime($n['created_at']);
    $now = new DateTime();
    $diff = $now->diff($createdAt);
    
    if ($diff->days == 0) {
        if ($diff->h == 0) {
            $n['time_ago'] = $diff->i . ' minute' . ($diff->i > 1 ? 's' : '');
        } else {
            $n['time_ago'] = $diff->h . ' heure' . ($diff->h > 1 ? 's' : '');
        }
    } elseif ($diff->days == 1) {
        $n['time_ago'] = 'Hier';
    } elseif ($diff->days < 7) {
        $n['time_ago'] = 'Il y a ' . $diff->days . ' jours';
    } else {
        $n['time_ago'] = $createdAt->format('d M Y');
    }
}
unset($n);

$unreadCount = count(array_filter($notifications, fn($n) => !$n['is_read']));
$unreadNew = count(array_filter($notifications, fn($n) => !$n['is_read'] && $n['type'] === 'new-article'));
$unreadRemind = count(array_filter($notifications, fn($n) => !$n['is_read'] && $n['type'] === 'reminder'));
$unreadRevised = count(array_filter($notifications, fn($n) => !$n['is_read'] && $n['type'] === 'revised'));

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
<title>ConfManager — Tableau de Bord Évaluateur</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600&family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
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
    --danger-light: #fee2e2;
    --success: #2a9d8f;
    --success-light: #d1fae5;
    --warning: #d4830a;
    --warning-light: #fed7aa;
    --purple: #5b6ef5;
    --shadow-sm: 0 1px 4px rgba(13,33,55,0.07);
    --shadow: 0 4px 20px rgba(13,33,55,0.10);
    --shadow-md: 0 8px 32px rgba(13,33,55,0.15);
    --radius: 8px;
    --radius-sm: 4px;
  }
    body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

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
    border-radius: var(--radius-sm); cursor: pointer; font-family: 'Inter', sans-serif;
    transition: all 0.15s; position: relative; text-decoration: none; display: inline-block;
  }
  .nav-link:hover { color: var(--navy); background: var(--bg); }
  .nav-link.active { color: var(--gold); font-weight: 500; }
  .nav-link.active::after {
    content: ''; position: absolute; bottom: -1px; left: 16px; right: 16px;
    height: 2px; background: var(--gold); border-radius: 2px 2px 0 0;
  }
  .topbar-right { display: flex; align-items: center; gap: 12px; }

  /* SEARCH */
  .search-wrap { position: relative; margin-right: 8px; }
  .search-input {
    width: 240px; padding: 8px 14px 8px 36px;
    border: 1px solid var(--border); border-radius: 20px;
    font-size: 13.5px; font-family: 'Inter', sans-serif;
    background: var(--bg); color: var(--text); outline: none; transition: all 0.15s;
  }
  .search-input::placeholder { color: var(--muted); }
  .search-input:focus { border-color: var(--accent); background: var(--white); box-shadow: 0 0 0 3px rgba(44,111,173,0.10); width: 280px; }
  .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 13px; pointer-events: none; }

  /* NOTIFICATION BELL */
  .notif-wrap { position: relative; }
  .notif-btn {
    width: 38px; height: 38px; border-radius: var(--radius-sm);
    border: 1px solid var(--border); background: var(--white);
    color: var(--text-light); cursor: pointer; font-size: 16px;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.15s; position: relative;
  }
  .notif-btn:hover { border-color: var(--navy); color: var(--navy); }
  .notif-btn.active { border-color: var(--gold); color: var(--gold); }
  .notif-badge {
    position: absolute; top: -5px; right: -5px;
    width: 18px; height: 18px; border-radius: 50%;
    background: var(--danger); color: white;
    font-size: 10px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid var(--white);
  }
  .notif-badge.hidden { display: none; }
  
  /* NOTIFICATION DROPDOWN */
  .notif-dropdown {
    position: absolute; top: calc(100% + 10px); right: 0;
    width: 400px; background: var(--white);
    border: 1px solid var(--border); border-radius: var(--radius);
    box-shadow: var(--shadow-md); z-index: 300; display: none; overflow: hidden;
    animation: fadeDown 0.18s ease;
  }
  .notif-dropdown.open { display: block; }
  .notif-dd-header {
    padding: 14px 18px 12px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
  }
  .notif-dd-title { font-size: 14px; font-weight: 600; color: var(--navy); }
  .notif-mark-all { font-size: 12px; color: var(--accent); cursor: pointer; border: none; background: none; font-family: 'Inter', sans-serif; }
  .notif-mark-all:hover { text-decoration: underline; }
  
  /* CATEGORY TABS */
  .notif-categories {
    display: flex; border-bottom: 1px solid var(--border);
    background: var(--bg);
  }
  .notif-cat-btn {
    flex: 1; padding: 10px 8px; border: none; background: none;
    font-family: 'Inter', sans-serif; font-size: 12px; font-weight: 500;
    color: var(--muted); cursor: pointer; transition: all 0.15s;
    display: flex; align-items: center; justify-content: center; gap: 6px;
  }
  .notif-cat-btn:hover { color: var(--navy); background: var(--white); }
  .notif-cat-btn.active { 
    color: var(--navy); background: var(--white); 
    border-bottom: 2px solid var(--gold); font-weight: 600;
  }
  .notif-cat-count {
    background: var(--danger); color: white; font-size: 10px;
    padding: 2px 5px; border-radius: 10px; min-width: 16px; text-align: center;
  }
  .notif-cat-count.zero { background: var(--muted); }
  
  .notif-list { max-height: 320px; overflow-y: auto; }
  .notif-item {
    padding: 14px 18px; border-bottom: 1px solid var(--border);
    cursor: pointer; transition: background 0.12s;
    display: flex; gap: 12px; align-items: flex-start;
  }
  .notif-item:last-child { border-bottom: none; }
  .notif-item:hover { background: #fafbfd; }
  .notif-item.unread { background: #f5f8ff; }
  .notif-item.unread:hover { background: #edf2fd; }
  .notif-icon-wrap {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; flex-shrink: 0;
  }
  .notif-icon-wrap.new-article { background: #eef3fb; color: var(--accent); }
  .notif-icon-wrap.revised     { background: #f0f4ff; color: #5b6ef5; }
  .notif-icon-wrap.reminder    { background: #fef8ec; color: var(--warning); }
  .notif-icon-wrap.info        { background: #e8f6f3; color: var(--success); }
  .notif-content { flex: 1; }
  .notif-msg { font-size: 13px; color: var(--text); line-height: 1.45; }
  .notif-msg strong { color: var(--navy); }
  .notif-time { font-size: 11px; color: var(--muted); margin-top: 3px; }
  .notif-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--accent); flex-shrink: 0; margin-top: 5px; }
  .notif-dot.hidden { visibility: hidden; }
  
  .notif-dd-footer {
    padding: 12px; border-top: 1px solid var(--border);
    text-align: center; background: var(--bg);
  }

  .logout-btn {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 18px; background: var(--bg);
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    font-size: 13.5px; font-weight: 500; color: var(--text-light);
    cursor: pointer; font-family: 'Inter', sans-serif;
    transition: all 0.15s; text-decoration: none;
  }
  .logout-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--white); }

  /* ─── PAGE ─── */
  .page { max-width: 1200px; margin: 0 auto; padding: 40px 32px 60px; }
  .page-header { margin-bottom: 28px; }
  .page-header-row { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 6px; }
  .page-header h1 { font-family: 'Libre Baskerville', serif; font-size: 32px; font-weight: 700; color: var(--navy); letter-spacing: -0.5px; }
  .page-header h1 em { font-style: italic; color: var(--gold); }
  .page-header p { font-size: 14px; color: var(--muted); margin-top: 4px; }

  /* ─── STATUS CARDS ─── */
  .status-cards-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }
  .status-card {
    background: var(--white); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 20px;
    box-shadow: var(--shadow-sm); cursor: pointer; transition: all 0.2s;
    text-decoration: none; display: block;
  }
  .status-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); border-color: var(--navy); }
  .status-card.accept { border-left: 4px solid var(--success); }
  .status-card.reject { border-left: 4px solid var(--danger); }
  .status-card.new { border-left: 4px solid var(--accent); }
  .status-card.revised { border-left: 4px solid var(--purple); }
  .status-card-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
  .status-card-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; }
  .status-card.accept .status-card-icon { background: #e8f6f3; color: var(--success); }
  .status-card.reject .status-card-icon { background: #fdf2f2; color: var(--danger); }
  .status-card.new .status-card-icon { background: #eef3fb; color: var(--accent); }
  .status-card.revised .status-card-icon { background: #f0f4ff; color: var(--purple); }
  .status-card-title { font-size: 13px; font-weight: 600; color: var(--navy); }
  .status-card-count { font-size: 28px; font-weight: 700; color: var(--navy); margin-top: 4px; }
  .status-card-label { font-size: 12px; color: var(--muted); }

  /* ─── EVALUATION SUMMARY CARDS ─── */
  .evaluation-summary {
    margin-bottom: 24px;
    background: var(--white);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--border);
  }
  .summary-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--navy);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
  }
  .eval-card {
    background: var(--bg);
    border-radius: var(--radius);
    padding: 16px;
    border: 1px solid var(--border);
    transition: all 0.2s;
  }
  .eval-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
    border-color: var(--accent);
  }
  .eval-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border);
  }
  .eval-ref {
    font-family: 'Monaco', monospace;
    font-size: 12px;
    font-weight: 600;
    color: var(--navy);
    background: var(--white);
    padding: 2px 8px;
    border-radius: 4px;
  }
  .eval-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 12px;
    line-height: 1.4;
  }
  .score-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 12px;
  }
  .score-label {
    color: var(--muted);
  }
  .score-value {
    font-weight: 600;
  }
  .score-value.low { color: var(--success); }
  .score-value.medium { color: var(--warning); }
  .score-value.high { color: var(--danger); }
  .progress-bar {
    height: 6px;
    background: var(--border);
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 12px;
  }
  .progress-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s ease;
  }
  .eval-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
  }
  .risk-badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
  }
  .risk-low {
    background: var(--success-light);
    color: var(--success);
  }
  .risk-medium {
    background: var(--warning-light);
    color: var(--warning);
  }
  .risk-high {
    background: var(--danger-light);
    color: var(--danger);
  }

  /* DEADLINE STRIP */
  .deadline-strip {
    background: var(--white); border: 1px solid var(--border);
    border-radius: var(--radius); box-shadow: var(--shadow-sm);
    padding: 16px 22px; margin-bottom: 24px;
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
  }
  .deadline-strip-title { font-size: 13px; font-weight: 600; color: var(--navy); white-space: nowrap; }
  .deadline-items { display: flex; gap: 12px; flex-wrap: wrap; flex: 1; }
  .deadline-item {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 14px; border-radius: 20px; font-size: 12.5px;
    border: 1px solid transparent; cursor: pointer; transition: all 0.15s;
  }
  .deadline-item:hover { transform: scale(1.02); }
  .deadline-item.urgent   { background: #fdf2f2; color: #8b2020; border-color: #f5b8b8; }
  .deadline-item.soon     { background: #fef8ec; color: #9a5f00; border-color: #f5d98a; }
  .deadline-item.ok       { background: #e8f6f3; color: #1a5f57; border-color: #9dd8d0; }
  .deadline-item i        { font-size: 11px; }

  /* ARTICLES TABLE */
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
  .article-ref { font-family: 'Monaco', monospace; font-size: 11px; color: var(--muted); background: var(--bg); padding: 2px 7px; border-radius: 3px; border: 1px solid var(--border); }
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
  .badge.done      { background: #e8f6f3; color: #1a5f57;  border: 1px solid #9dd8d0; }
  .badge.done      .badge-dot { background: var(--success); }
  .badge.revised   { background: #f0f4ff; color: #3347bb;  border: 1px solid #bcc7fa; }
  .badge.revised   .badge-dot { background: var(--purple); }
  .badge.accepted  { background: #e8f6f3; color: #1a5f57; border: 1px solid #9dd8d0; }
  .badge.accepted .badge-dot { background: var(--success); }
  .badge.rejected  { background: #fdf2f2; color: #8b2020; border: 1px solid #f5b8b8; }
  .badge.rejected .badge-dot { background: var(--danger); }

  /* ROW ACTIONS */
  .row-actions { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
  .action-btn {
    height: 30px; padding: 0 12px; border-radius: var(--radius-sm);
    border: 1px solid var(--border); background: none;
    color: var(--muted); cursor: pointer; font-size: 12px;
    display: inline-flex; align-items: center; gap: 5px;
    transition: all 0.15s; font-family: 'Inter', sans-serif; white-space: nowrap;
    text-decoration: none;
  }
  .action-btn:hover { background: var(--bg); color: var(--navy); border-color: var(--navy); }
  .action-btn.primary { background: var(--navy); color: var(--gold-light); border-color: var(--navy); }
  .action-btn.primary:hover { background: var(--navy-mid); }
  .action-btn.revised-btn { border-color: #bcc7fa; color: var(--purple); }
  .action-btn.revised-btn:hover { background: #f0f4ff; color: #3347bb; }

  /* MODALS */
  .modal-backdrop {
    position: fixed; inset: 0; background: rgba(13,33,55,0.5);
    backdrop-filter: blur(4px); z-index: 200;
    display: none; align-items: center; justify-content: center; padding: 20px;
  }
  .modal-backdrop.open { display: flex; }
  .modal {
    background: var(--white); border-radius: var(--radius);
    width: 100%; max-width: 650px; box-shadow: var(--shadow-md);
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
    transition: all 0.15s; flex-shrink: 0;
  }
  .modal-close:hover { background: var(--bg); color: var(--navy); }
  .modal-body { padding: 24px; }
  .modal-footer {
    padding: 16px 24px; border-top: 1px solid var(--border);
    display: flex; justify-content: flex-end; gap: 10px;
    position: sticky; bottom: 0; background: var(--white); z-index: 1;
  }
  .modal-detail-row { display: flex; gap: 10px; margin-bottom: 14px; align-items: flex-start; }
  .modal-detail-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); width: 110px; flex-shrink: 0; padding-top: 2px; }
  .modal-detail-value { font-size: 13.5px; color: var(--text); flex: 1; line-height: 1.5; }

  .btn-primary {
    background: var(--navy); color: var(--gold-light);
    border: none; border-radius: var(--radius-sm);
    padding: 11px 22px; font-size: 13.5px; font-weight: 500;
    font-family: 'Inter', sans-serif; cursor: pointer;
    transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none; white-space: nowrap;
  }
  .btn-primary:hover { background: var(--navy-mid); }
  .btn-secondary {
    background: none; color: var(--text-light);
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    padding: 9px 20px; font-size: 13.5px;
    font-family: 'Inter', sans-serif; cursor: pointer; transition: all 0.15s;
  }
  .btn-secondary:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }

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
    font-family: 'Inter', sans-serif; cursor: pointer; transition: all 0.15s;
    display: flex; align-items: center; gap: 7px; white-space: nowrap;
  }
  .download-btn:hover { background: var(--navy-mid); }

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

  .footer { background: var(--navy); color: rgba(255,255,255,0.45); text-align: center; padding: 22px; font-size: 13px; margin-top: 48px; }

  @keyframes fadeUp   { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:none} }
  @keyframes fadeDown { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:none} }
  @keyframes toastIn  { from{opacity:0;transform:translateX(30px)} to{opacity:1;transform:none} }
  @keyframes toastOut { from{opacity:1;transform:none} to{opacity:0;transform:translateX(30px)} }

  @media(max-width:900px) {
    .status-cards-row { grid-template-columns: repeat(2,1fr); }
    .table-head,.article-row { grid-template-columns: 2fr 120px 140px; }
    .td-domain,.th:nth-child(2) { display:none; }
    .summary-grid { grid-template-columns: 1fr; }
  }
  @media(max-width:650px) {
    .topbar { padding: 0 16px; }
    .search-wrap { display: none; }
    .page { padding: 24px 16px; }
    .status-cards-row { grid-template-columns: 1fr; }
    .notif-dropdown { width: 320px; right: -50px; }
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
    <a href="reviewer_dashboard.php" class="nav-link active">Tableau de bord</a>
    <a href="review_articles.php" class="nav-link">Articles assignés</a>
    <a href="reviewed_articles.php" class="nav-link">Articles évalués</a>
    <a href="profile.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <div class="search-wrap">
      <span class="search-icon"><i class="fas fa-search"></i></span>
      <input type="text" class="search-input" id="searchInput" placeholder="Rechercher…" onkeyup="searchArticles(event)">
    </div>

    <!-- NOTIFICATION BELL -->
    <div class="notif-wrap">
      <button class="notif-btn" id="notifBtn" onclick="toggleNotif(event)">
        <i class="fas fa-bell"></i>
        <span class="notif-badge <?= $unreadCount === 0 ? 'hidden' : '' ?>" id="notifBadge"><?= $unreadCount ?></span>
      </button>
      <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-dd-header">
          <span class="notif-dd-title">Notifications</span>
          <button class="notif-mark-all" onclick="markAllRead()">Tout marquer lu</button>
        </div>
        <div class="notif-categories">
          <button class="notif-cat-btn active" onclick="filterNotifDropdown('all', this)">
            <i class="fas fa-layer-group"></i> Tous
            <span class="notif-cat-count" id="cat-count-all"><?= $unreadCount ?></span>
          </button>
          <button class="notif-cat-btn" onclick="filterNotifDropdown('new-article', this)">
            <i class="fas fa-file-alt"></i> Nouveaux
            <span class="notif-cat-count <?= $unreadNew === 0 ? 'zero' : '' ?>" id="cat-count-new"><?= $unreadNew ?></span>
          </button>
          <button class="notif-cat-btn" onclick="filterNotifDropdown('reminder', this)">
            <i class="fas fa-clock"></i> Rappels
            <span class="notif-cat-count <?= $unreadRemind === 0 ? 'zero' : '' ?>" id="cat-count-reminder"><?= $unreadRemind ?></span>
          </button>
          <button class="notif-cat-btn" onclick="filterNotifDropdown('revised', this)">
            <i class="fas fa-sync-alt"></i> Modifiés
            <span class="notif-cat-count <?= $unreadRevised === 0 ? 'zero' : '' ?>" id="cat-count-revised"><?= $unreadRevised ?></span>
          </button>
        </div>
        <div class="notif-list" id="notifList">
          <?php foreach ($notifications as $n): ?>
            <div class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>" 
                 data-id="<?= $n['id'] ?>"
                 data-type="<?= htmlspecialchars($n['type']) ?>"
                 data-article-id="<?= $n['article_id'] ?>"
                 onclick="handleNotifClick(this)">
              <div class="notif-icon-wrap <?= htmlspecialchars($n['type']) ?>">
                <i class="fas <?= $n['type'] === 'new-article' ? 'fa-file-alt' : ($n['type'] === 'revised' ? 'fa-sync-alt' : ($n['type'] === 'reminder' ? 'fa-clock' : 'fa-info-circle')) ?>"></i>
              </div>
              <div class="notif-content">
                <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
                <div class="notif-time"><i class="fas fa-clock" style="margin-right:4px;opacity:.6"></i><?= htmlspecialchars($n['time_ago']) ?></div>
              </div>
              <div class="notif-dot <?= $n['is_read'] ? 'hidden' : '' ?>"></div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($notifications)): ?>
            <div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">
              <i class="fas fa-bell-slash" style="font-size:22px;margin-bottom:8px;display:block"></i>
              Aucune notification
            </div>
          <?php endif; ?>
        </div>
        <div class="notif-dd-footer">
          <span style="font-size:12px;color:var(--muted)">Cliquez sur une notification pour agir</span>
        </div>
      </div>
    </div>

    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</header>

<!-- MAIN CONTENT -->
<main class="page">
  <div class="page-header">
    <div class="page-header-row">
      <h1>Tableau de <em>bord</em></h1>
    </div>
    <p>Bienvenue, <?= htmlspecialchars($reviewerName) ?> — voici un aperçu de vos révisions</p>
  </div>

  <!-- STATUS CARDS -->
  <div class="status-cards-row">
    <a href="review_articles.php?filter=accepted" class="status-card accept">
      <div class="status-card-header">
        <div class="status-card-icon"><i class="fas fa-check-circle"></i></div>
        <div class="status-card-title">Articles acceptés</div>
      </div>
      <div class="status-card-count" id="dash-accept"><?= $stats['accepted'] ?></div>
      <div class="status-card-label">Évaluations positives</div>
    </a>
    <a href="review_articles.php?filter=rejected" class="status-card reject">
      <div class="status-card-header">
        <div class="status-card-icon"><i class="fas fa-times-circle"></i></div>
        <div class="status-card-title">Articles rejetés</div>
      </div>
      <div class="status-card-count" id="dash-reject"><?= $stats['rejected'] ?></div>
      <div class="status-card-label">Évaluations négatives</div>
    </a>
    <a href="review_articles.php?filter=new" class="status-card new">
      <div class="status-card-header">
        <div class="status-card-icon"><i class="fas fa-star"></i></div>
        <div class="status-card-title">Nouveaux articles</div>
      </div>
      <div class="status-card-count" id="dash-new"><?= $stats['new'] ?></div>
      <div class="status-card-label">À évaluer</div>
    </a>
    <a href="review_articles.php?filter=revised" class="status-card revised">
      <div class="status-card-header">
        <div class="status-card-icon"><i class="fas fa-sync-alt"></i></div>
        <div class="status-card-title">Versions révisées</div>
      </div>
      <div class="status-card-count" id="dash-revised"><?= $stats['revised'] ?></div>
      <div class="status-card-label">À re-évaluer</div>
    </a>
  </div>

  <!-- EVALUATION SUMMARY CARDS -->
  <?php if (!empty($assignments)): ?>
  <div class="evaluation-summary">
    <div class="summary-title">
      <i class="fas fa-chart-line" style="color: var(--accent);"></i>
      Évaluations automatiques
    </div>
    <div class="summary-grid">
      <?php foreach (array_slice($assignments, 0, 3) as $a): 
        $similarityScore = $a['similarity_score'] ?? 0;
        $aiScore = $a['ai_score'] ?? 0;
        $maxScore = max($similarityScore, $aiScore);
        if ($maxScore > 60) {
          $riskClass = 'high';
          $riskText = 'Élevé';
        } elseif ($maxScore > 30) {
          $riskClass = 'medium';
          $riskText = 'Moyen';
        } else {
          $riskClass = 'low';
          $riskText = 'Faible';
        }
      ?>
      <div class="eval-card">
        <div class="eval-card-header">
          <span class="eval-ref"><?= htmlspecialchars($a['ref']) ?></span>
          <?php if ($a['display_status'] === 'done'): ?>
            <span class="badge done" style="font-size: 10px;"><span class="badge-dot"></span>Évalué</span>
          <?php elseif ($a['display_status'] === 'revised'): ?>
            <span class="badge revised" style="font-size: 10px;"><span class="badge-dot"></span>Révisé</span>
          <?php else: ?>
            <span class="badge pending" style="font-size: 10px;"><span class="badge-dot"></span>En attente</span>
          <?php endif; ?>
        </div>
        <div class="eval-title"><?= htmlspecialchars(substr($a['title'], 0, 50)) . (strlen($a['title']) > 50 ? '...' : '') ?></div>
        <div class="score-row">
          <span class="score-label">Similarité</span>
          <span class="score-value <?= $similarityScore > 30 ? 'high' : ($similarityScore > 15 ? 'medium' : 'low') ?>"><?= $similarityScore ?>%</span>
        </div>
        <div class="progress-bar">
          <div class="progress-fill" style="width: <?= min(100, $similarityScore) ?>%; background: <?= $similarityScore > 30 ? 'var(--danger)' : ($similarityScore > 15 ? 'var(--warning)' : 'var(--success)') ?>;"></div>
        </div>
        <div class="score-row">
          <span class="score-label">IA détectée</span>
          <span class="score-value <?= $aiScore > 40 ? 'high' : ($aiScore > 20 ? 'medium' : 'low') ?>"><?= $aiScore ?>%</span>
        </div>
        <div class="progress-bar">
          <div class="progress-fill" style="width: <?= min(100, $aiScore) ?>%; background: <?= $aiScore > 40 ? 'var(--danger)' : ($aiScore > 20 ? 'var(--warning)' : 'var(--success)') ?>;"></div>
        </div>
        <div class="eval-footer">
          <span class="risk-badge risk-<?= $riskClass ?>">Risque <?= $riskText ?></span>
          <a href="review_article.php?id=<?= $a['id'] ?>" class="action-btn primary" style="padding: 6px 12px;">
            <i class="fas fa-play"></i> Évaluer
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- DEADLINES STRIP -->
  <div class="deadline-strip">
    <span class="deadline-strip-title"><i class="fas fa-clock" style="margin-right:6px;color:var(--warning)"></i>Échéances de révision</span>
    <div class="deadline-items" id="deadlineItems">
      <?php foreach ($deadlineTop5 as $a): ?>
        <span class="deadline-item <?= $a['deadline_urgency'] ?>" onclick="openDetailModal(<?= $a['id'] ?>)">
          <i class="fas <?= $a['deadline_urgency'] === 'urgent' ? 'fa-fire' : ($a['deadline_urgency'] === 'soon' ? 'fa-exclamation' : 'fa-check') ?>"></i>
          <?= htmlspecialchars($a['ref']) ?> · <?= htmlspecialchars($a['deadline_label']) ?>
        </span>
      <?php endforeach; ?>
      <?php if (empty($deadlineTop5)): ?>
        <span style="font-size:13px;color:var(--muted)">Aucune échéance en cours</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- CLOSEST DEADLINES TABLE -->
  <div style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between">
    <div style="font-size:15px;font-weight:600;color:var(--navy)">Prochaines échéances</div>
    <a href="review_articles.php" class="btn-secondary" style="padding:7px 16px;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;">
      Voir tous <i class="fas fa-arrow-right" style="margin-left:6px"></i>
    </a>
  </div>
  <div class="articles-table-wrap">
    <div class="table-head">
      <div class="th">Article</div>
      <div class="th">Spécialité</div>
      <div class="th">Échéance</div>
      <div class="th">Statut</div>
      <div class="th" style="text-align:right">Action</div>
    </div>
    <div id="homeArticleList">
      <?php foreach ($pendingTop4 as $a): ?>
        <div class="article-row">
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
          <div class="td td-domain"><?= htmlspecialchars($a['specialty'] ?? '—') ?></div>
          <div class="td">
            <span class="deadline-cell <?= $a['deadline_urgency'] ?>">
              <i class="fas fa-calendar-alt" style="margin-right:5px;opacity:.7"></i><?= htmlspecialchars($a['deadline_label']) ?>
            </span>
          </div>
          <div class="td">
            <span class="badge <?= $a['display_status'] ?>">
              <span class="badge-dot"></span><?= $a['status_label'] ?>
            </span>
          </div>
          <div class="td">
            <div class="row-actions">
              <?php if ($a['display_status'] === 'done'): ?>
                <button class="action-btn" onclick="openDetailModal(<?= $a['id'] ?>)"><i class="fas fa-eye"></i> Voir</button>
              <?php elseif ($a['display_status'] === 'revised'): ?>
                <a href="review_article.php?id=<?= $a['id'] ?>&action=rereview" class="action-btn revised-btn">
                  <i class="fas fa-sync-alt"></i> Re-évaluer
                </a>
              <?php else: ?>
                <a href="review_article.php?id=<?= $a['id'] ?>" class="action-btn primary">
                  <i class="fas fa-play"></i> Commencer
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($pendingTop4)): ?>
        <div style="padding:40px;text-align:center;color:var(--muted)">
          <i class="fas fa-inbox" style="font-size:32px;margin-bottom:12px;display:block"></i>
          Aucun article en attente
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- MODAL: ARTICLE DETAIL -->
<div class="modal-backdrop" id="articleDetailModal" onclick="closeOnBackdrop(event)">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Détail de l'article</div>
      <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="articleDetailBody"></div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal()">Fermer</button>
      <a href="#" class="btn-primary" id="detailActionBtn" style="display:none">Action</a>
    </div>
  </div>
</div>

<!-- TOAST -->
<div class="toast-wrap" id="toastWrap"></div>

<footer class="footer">© <?= date('Y') ?> ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés</footer>

<script>
// ==================== PHP DATA INJECTION ====================
const assignments = <?= json_encode($assignments) ?>;
let notifications = <?= json_encode($notifications) ?>;

let currentNotifFilter = 'all';

// ==================== SEARCH FUNCTION ====================
function searchArticles(event) {
    const searchTerm = event.target.value.toLowerCase();
    const rows = document.querySelectorAll('#homeArticleList .article-row');
    
    rows.forEach(row => {
        const title = row.querySelector('.article-title-main')?.textContent.toLowerCase() || '';
        const ref = row.querySelector('.article-ref')?.textContent.toLowerCase() || '';
        const conf = row.querySelector('.article-conf')?.textContent.toLowerCase() || '';
        
        if (title.includes(searchTerm) || ref.includes(searchTerm) || conf.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// ==================== ARTICLE DETAIL MODAL ====================
function openDetailModal(id) {
    const a = assignments.find(x => x.id == id);
    if (!a) return;
    
    const actionBtn = document.getElementById('detailActionBtn');
    if (a.display_status === 'done') {
        actionBtn.style.display = 'none';
    } else if (a.display_status === 'revised') {
        actionBtn.style.display = 'inline-flex';
        actionBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Re-évaluer';
        actionBtn.href = `review_article.php?id=${a.id}&action=rereview`;
    } else {
        actionBtn.style.display = 'inline-flex';
        actionBtn.innerHTML = '<i class="fas fa-play"></i> Commencer l\'évaluation';
        actionBtn.href = `review_article.php?id=${a.id}`;
    }

    const decisionHtml = a.submitted_decision ? `
        <div class="modal-detail-row">
            <span class="modal-detail-label">Décision</span>
            <span class="modal-detail-value">
                <span class="badge ${a.submitted_decision === 'accepted' ? 'accepted' : 'rejected'}">
                    <span class="badge-dot"></span>${a.submitted_decision === 'accepted' ? 'Accepté' : 'Rejeté'}
                </span>
            </span>
        </div>` : '';

    const similarityHtml = a.similarity_score !== null && a.similarity_score !== undefined ? `
        <div class="modal-detail-row">
            <span class="modal-detail-label">Similarité</span>
            <span class="modal-detail-value">
                <span class="score-value ${a.similarity_score > 30 ? 'high' : (a.similarity_score > 15 ? 'medium' : 'low')}">${a.similarity_score}%</span>
                <div class="progress-bar" style="width: 150px; display: inline-block; margin-left: 10px; vertical-align: middle;">
                    <div class="progress-fill" style="width: ${Math.min(100, a.similarity_score)}%; background: ${a.similarity_score > 30 ? 'var(--danger)' : (a.similarity_score > 15 ? 'var(--warning)' : 'var(--success)')};"></div>
                </div>
            </span>
        </div>` : '';

    const aiHtml = a.ai_score !== null && a.ai_score !== undefined ? `
        <div class="modal-detail-row">
            <span class="modal-detail-label">IA détectée</span>
            <span class="modal-detail-value">
                <span class="score-value ${a.ai_score > 40 ? 'high' : (a.ai_score > 20 ? 'medium' : 'low')}">${a.ai_score}%</span>
                <div class="progress-bar" style="width: 150px; display: inline-block; margin-left: 10px; vertical-align: middle;">
                    <div class="progress-fill" style="width: ${Math.min(100, a.ai_score)}%; background: ${a.ai_score > 40 ? 'var(--danger)' : (a.ai_score > 20 ? 'var(--warning)' : 'var(--success)')};"></div>
                </div>
            </span>
        </div>` : '';

    document.getElementById('articleDetailBody').innerHTML = `
        <div class="download-zone">
            <i class="fas fa-file-pdf download-icon"></i>
            <div class="download-info">
                <div class="download-name">${escapeHtml(a.file || 'document.pdf')}</div>
                <div class="download-meta">PDF · Assigné le ${a.assigned_date_formatted || '—'}</div>
            </div>
            ${a.file ? `<button class="download-btn" onclick="window.open('${escapeHtml(a.file)}', '_blank')"><i class="fas fa-download"></i> Télécharger</button>` : '<span class="download-meta">Fichier non disponible</span>'}
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Référence</span>
            <span class="modal-detail-value"><span class="article-ref">${escapeHtml(a.ref || '—')}</span></span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Titre</span>
            <span class="modal-detail-value" style="font-weight:600;color:var(--navy)">${escapeHtml(a.title || '—')}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Résumé</span>
            <span class="modal-detail-value" style="color:var(--text-light)">${escapeHtml(a.abstract || 'Aucun résumé disponible')}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Auteur(s)</span>
            <span class="modal-detail-value">${escapeHtml(a.authors || '—')}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Conférence</span>
            <span class="modal-detail-value">${escapeHtml(a.conf || '—')}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Spécialité</span>
            <span class="modal-detail-value">${escapeHtml(a.specialty || '—')}</span>
        </div>
        ${similarityHtml}
        ${aiHtml}
        <div class="modal-detail-row">
            <span class="modal-detail-label">Échéance</span>
            <span class="modal-detail-value"><span class="deadline-cell ${a.deadline_urgency || 'ok'}"><i class="fas fa-calendar-alt" style="margin-right:5px"></i>${a.deadline_label || '—'}</span></span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Statut</span>
            <span class="modal-detail-value"><span class="badge ${a.display_status || 'pending'}"><span class="badge-dot"></span>${a.status_label || 'À évaluer'}</span></span>
        </div>
        ${decisionHtml}
    `;
    openModal();
}

function openModal() { 
    document.getElementById('articleDetailModal').classList.add('open'); 
    document.body.style.overflow = 'hidden'; 
}

function closeModal() { 
    document.getElementById('articleDetailModal').classList.remove('open'); 
    document.body.style.overflow = ''; 
}

function closeOnBackdrop(e) { 
    if (e.target === document.getElementById('articleDetailModal')) closeModal(); 
}

// ==================== NOTIFICATIONS ====================
function renderNotifications() {
    let filtered = notifications;
    if (currentNotifFilter !== 'all') {
        filtered = notifications.filter(n => n.type === currentNotifFilter);
    }
    
    const container = document.getElementById('notifList');
    if (filtered.length === 0) {
        container.innerHTML = '<div style="padding:24px;text-align:center;color:var(--muted);font-size:13px"><i class="fas fa-bell-slash" style="font-size:22px;margin-bottom:8px;display:block"></i>Aucune notification</div>';
        return;
    }
    
    container.innerHTML = filtered.map(n => `
        <div class="notif-item ${n.is_read ? '' : 'unread'}" 
             data-id="${n.id}"
             data-type="${n.type}"
             data-article-id="${n.article_id}"
             onclick="handleNotifClick(this)">
            <div class="notif-icon-wrap ${n.type}">
                <i class="fas ${n.type === 'new-article' ? 'fa-file-alt' : (n.type === 'revised' ? 'fa-sync-alt' : (n.type === 'reminder' ? 'fa-clock' : 'fa-info-circle'))}"></i>
            </div>
            <div class="notif-content">
                <div class="notif-msg">${escapeHtml(n.message)}</div>
                <div class="notif-time"><i class="fas fa-clock" style="margin-right:4px;opacity:.6"></i>${escapeHtml(n.time_ago)}</div>
            </div>
            <div class="notif-dot ${n.is_read ? 'hidden' : ''}"></div>
        </div>
    `).join('');
}

function filterNotifDropdown(type, btn) {
    document.querySelectorAll('.notif-cat-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentNotifFilter = type;
    renderNotifications();
}

function updateBadge() {
    const unread = notifications.filter(n => !n.is_read).length;
    const badge = document.getElementById('notifBadge');
    badge.textContent = unread;
    badge.classList.toggle('hidden', unread === 0);
}

function toggleNotif(e) {
    e.stopPropagation();
    const dropdown = document.getElementById('notifDropdown');
    const btn = document.getElementById('notifBtn');
    dropdown.classList.toggle('open');
    btn.classList.toggle('active', dropdown.classList.contains('open'));
}

function handleNotifClick(el) {
    const notifId = parseInt(el.dataset.id);
    const articleId = parseInt(el.dataset.articleId);
    const type = el.dataset.type;
    
    // Mark as read via AJAX
    const formData = new FormData();
    formData.append('action', 'mark_notification_read');
    formData.append('notif_id', notifId);
    
    fetch(window.location.href, { method: 'POST', body: formData })
        .catch(() => console.log('Error marking notification as read'));
    
    const notif = notifications.find(n => n.id === notifId);
    if (notif) notif.is_read = true;
    
    el.classList.remove('unread');
    el.querySelector('.notif-dot')?.classList.add('hidden');
    updateBadge();
    
    document.getElementById('notifDropdown').classList.remove('open');
    document.getElementById('notifBtn').classList.remove('active');
    
    if (articleId) {
        const a = assignments.find(x => x.id === articleId);
        if (a) {
            if (type === 'revised') {
                window.location.href = `review_article.php?id=${articleId}&action=rereview`;
            } else if (a.display_status !== 'done') {
                window.location.href = `review_article.php?id=${articleId}`;
            } else {
                openDetailModal(articleId);
            }
        }
    }
}

function markAllRead() {
    const formData = new FormData();
    formData.append('action', 'mark_all_notifications_read');
    
    fetch(window.location.href, { method: 'POST', body: formData })
        .catch(() => console.log('Error marking all notifications as read'));
    
    notifications.forEach(n => n.is_read = true);
    renderNotifications();
    updateBadge();
    document.querySelectorAll('.notif-cat-count').forEach(el => {
        el.textContent = '0';
        el.classList.add('zero');
    });
}

document.addEventListener('click', e => {
    const dd = document.getElementById('notifDropdown');
    const btn = document.getElementById('notifBtn');
    if (dd && btn && !btn.contains(e.target) && !dd.contains(e.target)) {
        dd.classList.remove('open');
        btn.classList.remove('active');
    }
});

// ==================== TOAST ====================
function showToast(msg, type = 'info') {
    const wrap = document.getElementById('toastWrap');
    const t = document.createElement('div');
    const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', info: 'fa-info-circle' };
    t.className = `toast ${type}`;
    t.innerHTML = `<i class="fas ${icons[type] || 'fa-info-circle'}"></i> ${msg}`;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// Initialize
renderNotifications();
</script>
</body>
</html>