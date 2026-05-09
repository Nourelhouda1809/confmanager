<?php
// confmanager/conferences.php
// Conference & Article Management System

session_start();

// Database configuration
$db_host = 'localhost';
$db_name = 'confmanager';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ==================== AUTHENTICATION CHECK ====================
// DEBUG: Log session state
error_log("DEBUG: Session user_id = " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET'));

// For development: auto-set session if not present or invalid
$session_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

// If session user_id is 0 or not set, find a valid user from the database
if ($session_user_id <= 0) {
    try {
        // Get the first gestionnaire user (preferably)
        $user_check = $pdo->query("SELECT id, role FROM users WHERE role = 'gestionnaire' AND status = 'active' ORDER BY id ASC LIMIT 1");
        $user_row = $user_check->fetch();

        // If no gestionnaire, get any active user
        if (!$user_row) {
            $user_check = $pdo->query("SELECT id, role FROM users WHERE status = 'active' ORDER BY id ASC LIMIT 1");
            $user_row = $user_check->fetch();
        }

        // If still no user, get ANY user
        if (!$user_row) {
            $user_check = $pdo->query("SELECT id, role FROM users ORDER BY id ASC LIMIT 1");
            $user_row = $user_check->fetch();
        }

        if ($user_row) {
            $_SESSION['user_id'] = intval($user_row['id']);
            $_SESSION['role'] = $user_row['role'];
            $session_user_id = intval($user_row['id']);
            error_log("DEBUG: Auto-set user_id to " . $session_user_id);
        }
    } catch (PDOException $e) {
        error_log("DEBUG: Error finding user: " . $e->getMessage());
    }
}

// Final validation - ensure user exists in database
$user_id = 1; // Default fallback
$user_role = 'gestionnaire';

try {
    $check = $pdo->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
    $check->execute([$session_user_id > 0 ? $session_user_id : 1]);
    $user_row = $check->fetch();

    if ($user_row) {
        $user_id = intval($user_row['id']);
        $user_role = $user_row['role'];
        $_SESSION['user_id'] = $user_id;
        $_SESSION['role'] = $user_role;
    } else {
        // User doesn't exist - use user 1 (Nourelhouda from your DB)
        $user_id = 1;
        $_SESSION['user_id'] = 1;
        $_SESSION['role'] = 'gestionnaire';
        error_log("DEBUG: User $session_user_id not found, using default user 1");
    }
} catch (PDOException $e) {
    error_log("DEBUG: Database error: " . $e->getMessage());
    $user_id = 1;
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'gestionnaire';
}

error_log("DEBUG: Final user_id = $user_id, role = $user_role");

// ==================== AJAX HANDLERS ====================
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'get_conferences') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("
            SELECT c.*, 
                GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') as topic_names,
                (SELECT COUNT(*) FROM articles WHERE conference_id = c.id) as articles_count
            FROM conferences c
            LEFT JOIN conference_topics ct ON c.id = ct.conference_id
            LEFT JOIN topics t ON ct.topic_id = t.id
            GROUP BY c.id
            ORDER BY c.created_at DESC
        ");
        $conferences = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $conferences]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_topics') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT * FROM topics ORDER BY name ASC");
        $topics = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $topics]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_conference') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'] ?? null;
        $name_fr = trim($_POST['name_fr'] ?? '');
        $name_en = trim($_POST['name_en'] ?? '');
        $type = $_POST['type'] ?? 'Conference';
        $disciplines = trim($_POST['disciplines'] ?? '');
        $organizer = trim($_POST['organizer'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $submission_start_date = $_POST['submission_start_date'] ?? '';
        $submission_deadline = $_POST['submission_deadline'] ?? '';
        $review_start_date = $_POST['review_start_date'] ?? '';
        $review_end_date = $_POST['review_end_date'] ?? '';
        $requirements = trim($_POST['requirements'] ?? '');
        $max_articles = intval($_POST['max_articles'] ?? 40);
        $topic_ids = $_POST['topic_ids'] ?? [];

        if (empty($name_en) || empty($start_date) || empty($end_date) || 
            empty($submission_start_date) || empty($submission_deadline) || 
            empty($review_start_date) || empty($review_end_date)) {
            echo json_encode(['success' => false, 'error' => 'Required fields missing']);
            exit;
        }

        if (new DateTime($submission_start_date) > new DateTime($submission_deadline)) {
            echo json_encode(['success' => false, 'error' => "Submission opening must be before deadline."]);
            exit;
        }
        if (new DateTime($submission_deadline) > new DateTime($review_start_date)) {
            echo json_encode(['success' => false, 'error' => "Review start must be after submission deadline."]);
            exit;
        }
        if (new DateTime($review_start_date) > new DateTime($review_end_date)) {
            echo json_encode(['success' => false, 'error' => "Review end must be after review start."]);
            exit;
        }
        if (new DateTime($review_end_date) > new DateTime($start_date)) {
            echo json_encode(['success' => false, 'error' => "Review must end before conference starts."]);
            exit;
        }

        $pdo->beginTransaction();

        if ($id) {
            $stmt = $pdo->prepare("
                UPDATE conferences SET 
                    name_fr = ?, name_en = ?, type = ?, disciplines = ?, organizer = ?, location = ?,
                    start_date = ?, end_date = ?, submission_start_date = ?, submission_deadline = ?,
                    review_start_date = ?, review_end_date = ?, requirements = ?, max_articles = ?
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$name_fr, $name_en, $type, $disciplines, $organizer, $location,
                $start_date, $end_date, $submission_start_date, $submission_deadline,
                $review_start_date, $review_end_date, $requirements, $max_articles, $id, $user_id]);
            $conf_id = $id;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO conferences (user_id, name_fr, name_en, type, disciplines, organizer, location,
                    start_date, end_date, submission_start_date, submission_deadline,
                    review_start_date, review_end_date, requirements, max_articles)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([intval($user_id), $name_fr, $name_en, $type, $disciplines, $organizer, $location,
                $start_date, $end_date, $submission_start_date, $submission_deadline,
                $review_start_date, $review_end_date, $requirements, $max_articles]);
            $conf_id = $pdo->lastInsertId();
        }

        $pdo->prepare("DELETE FROM conference_topics WHERE conference_id = ?")->execute([$conf_id]);
        if (!empty($topic_ids)) {
            $insert_stmt = $pdo->prepare("INSERT INTO conference_topics (conference_id, topic_id) VALUES (?, ?)");
            foreach ($topic_ids as $tid) {
                $insert_stmt->execute([$conf_id, intval($tid)]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'id' => $conf_id]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_conference') {
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM conferences WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_articles') {
    header('Content-Type: application/json');
    try {
        $conf_id = intval($_GET['conference_id'] ?? 0);
        $topic_filter = $_GET['topic_filter'] ?? '';

        $sql = "
            SELECT a.*, t.name as topic_name 
            FROM articles a
            LEFT JOIN topics t ON a.topic_id = t.id
            WHERE a.conference_id = ?
        ";
        $params = [$conf_id];

        if (!empty($topic_filter)) {
            $sql .= " AND a.topic_id = ?";
            $params[] = intval($topic_filter);
        }

        $sql .= " ORDER BY a.submission_date DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $articles = $stmt->fetchAll();

        $topic_stmt = $pdo->prepare("
            SELECT t.id, t.name FROM topics t
            JOIN conference_topics ct ON t.id = ct.topic_id
            WHERE ct.conference_id = ?
            ORDER BY t.name
        ");
        $topic_stmt->execute([$conf_id]);
        $available_topics = $topic_stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $articles, 'topics' => $available_topics]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'update_article_status') {
    header('Content-Type: application/json');
    try {
        $article_id = intval($_POST['article_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $assigned_to = trim($_POST['assigned_to'] ?? '');
        $reject_reason = trim($_POST['reject_reason'] ?? '');

        $stmt = $pdo->prepare("
            UPDATE articles SET status = ?, assigned_to = ?, reject_reason = ? WHERE id = ?
        ");
        $stmt->execute([$status, $assigned_to, $reject_reason, $article_id]);

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'add_topic') {
    header('Content-Type: application/json');
    try {
        $name = trim($_POST['name'] ?? '');
        $conf_id = intval($_POST['conference_id'] ?? 0);

        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Topic name required']);
            exit;
        }

        $check = $pdo->prepare("SELECT id FROM topics WHERE name = ?");
        $check->execute([$name]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Topic already exists']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO topics (name) VALUES (?)");
        $stmt->execute([$name]);
        $topic_id = $pdo->lastInsertId();

        if ($conf_id > 0) {
            $link = $pdo->prepare("INSERT IGNORE INTO conference_topics (conference_id, topic_id) VALUES (?, ?)");
            $link->execute([$conf_id, $topic_id]);
        }

        echo json_encode(['success' => true, 'id' => $topic_id, 'name' => $name]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ==================== FETCH DATA FOR PAGE RENDERING ====================
$topics_stmt = $pdo->query("SELECT * FROM topics ORDER BY name ASC");
$all_topics = $topics_stmt->fetchAll();

$conf_stmt = $pdo->query("
    SELECT c.*, 
        GROUP_CONCAT(DISTINCT t.id ORDER BY t.name SEPARATOR ',') as topic_ids,
        GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ',') as topic_names,
        (SELECT COUNT(*) FROM articles WHERE conference_id = c.id) as articles_count
    FROM conferences c
    LEFT JOIN conference_topics ct ON c.id = ct.conference_id
    LEFT JOIN topics t ON ct.topic_id = t.id
    GROUP BY c.id
    ORDER BY c.created_at DESC
");
$conferences = $conf_stmt->fetchAll();

$articles_stmt = $pdo->query("
    SELECT a.*, t.name as topic_name, c.name_fr as conference_name
    FROM articles a
    LEFT JOIN topics t ON a.topic_id = t.id
    LEFT JOIN conferences c ON a.conference_id = c.id
    ORDER BY a.submission_date DESC
");
$articles = $articles_stmt->fetchAll();

$active_count = 0;
$upcoming_count = 0;
$closed_count = 0;
$today = new DateTime();

foreach ($conferences as $conf) {
    $sub_end = new DateTime($conf['submission_deadline']);
    $sub_start = new DateTime($conf['submission_start_date']);

    if ($today > $sub_end) {
        $closed_count++;
    } elseif ($today >= $sub_start) {
        $active_count++;
    } else {
        $upcoming_count++;
    }
}

$total_articles = count($articles);

$topics_json = json_encode($all_topics);
$conferences_json = json_encode($conferences);
$articles_json = json_encode($articles);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager</title>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root {
    --navy: #0d2137; --navy-mid: #1a3a5c; --gold: #c9a84c; --gold-light: #e2c97e;
    --bg: #f0f2f5; --white: #ffffff; --border: #e2e8f0; --muted: #7a8fa6;
    --text: #1a2e44; --text-light: #4a607a; --accent: #2c6fad;
    --danger: #d94040; --success: #2a9d8f; --warning: #d4830a; --purple: #5b6ef5;
    --shadow-sm: 0 1px 4px rgba(13,33,55,0.07); --shadow: 0 4px 20px rgba(13,33,55,0.10);
    --shadow-md: 0 8px 32px rgba(13,33,55,0.15); --radius: 8px; --radius-sm: 4px;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

  .topbar {
    background: var(--white); border-bottom: 1px solid var(--border);
    padding: 0 40px; height: 62px; display: flex; align-items: center;
    position: sticky; top: 0; z-index: 100; box-shadow: var(--shadow-sm);
  }
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

  .page { max-width: 1400px; margin: 0 auto; padding: 40px 32px 60px; }
  .page-header { margin-bottom: 28px; }
  .page-header-row { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 6px; }
  .page-header h1 { font-family: 'Libre Baskerville', serif; font-size: 32px; font-weight: 700; color: var(--navy); letter-spacing: -0.5px; }
  .page-header h1 em { font-style: italic; color: var(--gold); }
  .page-header p { font-size: 14px; color: var(--muted); margin-top: 4px; }

  .header-actions { display: flex; gap: 10px; align-items: center; }

  .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 32px; }
  .stat-card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 14px; }
  .stat-icon { width: 44px; height: 44px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
  .stat-icon.active { background: rgba(42,157,143,0.1); color: var(--success); }
  .stat-icon.upcoming { background: rgba(212,131,10,0.1); color: var(--warning); }
  .stat-icon.closed { background: rgba(122,143,166,0.1); color: var(--muted); }
  .stat-icon.total { background: rgba(44,111,173,0.1); color: var(--accent); }
  .stat-value { font-size: 22px; font-weight: 700; color: var(--navy); line-height: 1; margin-bottom: 2px; }
  .stat-label { font-size: 12px; color: var(--muted); }

  .filters-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
  .filter-tabs { display: flex; gap: 4px; background: var(--white); border: 1px solid var(--border); border-radius: 30px; padding: 4px; }
  .filter-tab { padding: 8px 20px; font-size: 13px; font-weight: 500; color: var(--muted); background: none; border: none; border-radius: 30px; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all 0.15s; }
  .filter-tab:hover { color: var(--navy); background: var(--bg); }
  .filter-tab.active { background: var(--navy); color: var(--gold-light); }
  .filter-count { font-size: 11px; background: rgba(255,255,255,0.2); padding: 1px 6px; border-radius: 12px; margin-left: 6px; }
  .filter-tab:not(.active) .filter-count { background: var(--bg); color: var(--muted); }
  .toolbar-right { display: flex; gap: 10px; align-items: center; }
  .sort-select { padding: 8px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; font-family: 'DM Sans', sans-serif; color: var(--text-light); background: var(--white); cursor: pointer; outline: none; }

  .conference-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 20px; margin-bottom: 32px; }
  .conf-card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; transition: all 0.2s; box-shadow: var(--shadow-sm); }
  .conf-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
  .conf-card-header { padding: 20px; border-bottom: 1px solid var(--border); background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%); color: white; position: relative; }
  .conf-card-header h3 { font-size: 17px; font-weight: 600; margin-bottom: 6px; font-family: 'Libre Baskerville', serif; padding-right: 80px; }
  .conf-card-header .conf-type { font-size: 12px; opacity: 0.8; display: inline-block; background: rgba(255,255,255,0.2); padding: 3px 10px; border-radius: 20px; }
  .conf-status { position: absolute; top: 20px; right: 20px; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
  .conf-status.active { background: var(--success); color: white; }
  .conf-status.upcoming { background: var(--warning); color: var(--navy); }
  .conf-status.closed { background: var(--muted); color: white; }
  .conf-card-body { padding: 18px 20px; }
  .dates-block { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; }
  .date-item { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px 10px; }
  .date-item-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); margin-bottom: 3px; font-weight: 600; }
  .date-item-value { font-size: 12.5px; font-weight: 600; color: var(--navy); }
  .date-item-value.range { font-size: 11.5px; }
  .date-item.full-width { grid-column: 1 / -1; }
  .date-item.highlight-deadline { border-color: rgba(201,168,76,0.4); background: rgba(201,168,76,0.06); }
  .date-item.highlight-deadline .date-item-label { color: var(--gold); }
  .submission-status-badge { display: flex; align-items: center; gap: 6px; font-size: 11.5px; margin-top: 10px; padding: 6px 10px; border-radius: var(--radius-sm); font-weight: 500; }
  .submission-status-badge.open { background: rgba(42,157,143,0.1); color: var(--success); }
  .submission-status-badge.closed { background: rgba(217,64,64,0.1); color: var(--danger); }
  .submission-status-badge.not-yet { background: rgba(212,131,10,0.08); color: var(--warning); }
  .conf-card-footer { padding: 14px 20px; background: var(--bg); display: flex; gap: 10px; border-top: 1px solid var(--border); }
  .btn-sm { flex: 1; padding: 8px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 500; cursor: pointer; transition: all 0.15s; text-align: center; font-family: 'DM Sans', sans-serif; border: none; }
  .btn-outline { background: transparent; border: 1px solid var(--border) !important; color: var(--text-light); }
  .btn-outline:hover { border-color: var(--navy) !important; color: var(--navy); background: var(--white); }
  .btn-outline:disabled { opacity: 0.5; cursor: not-allowed; border-color: var(--border) !important; color: var(--muted); }
  .btn-primary-sm { background: var(--navy); border: none; color: var(--gold-light); }
  .btn-primary-sm:hover { background: var(--navy-mid); }

  .articles-section { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); margin-top: 20px; overflow: hidden; animation: slideDown 0.3s ease; }
  @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
  .articles-header { background: var(--navy); color: white; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
  .articles-header h2 { font-family: 'Libre Baskerville', serif; font-size: 18px; }
  .articles-header .close-btn { background: rgba(255,255,255,0.2); border: none; color: white; width: 32px; height: 32px; border-radius: var(--radius-sm); cursor: pointer; font-size: 16px; transition: all 0.15s; }
  .articles-header .close-btn:hover { background: rgba(255,255,255,0.3); }
  .articles-toolbar { padding: 16px 24px; background: var(--bg); border-bottom: 1px solid var(--border); display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
  .articles-content { padding: 0; }
  .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .data-table th { text-align: left; padding: 14px 18px; background: var(--bg); font-weight: 600; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; border-bottom: 1px solid var(--border); }
  .data-table td { padding: 14px 18px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
  .data-table tr:hover { background: #fafbfd; }
  .data-table tr:last-child td { border-bottom: none; }
  .article-title { font-weight: 600; color: var(--navy); margin-bottom: 4px; }
  .article-meta { font-size: 11px; color: var(--muted); }
  .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
  .badge-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
  .badge.new { background: #f0f4ff; color: #3347bb; border: 1px solid #bcc7fa; }
  .badge.new .badge-dot { background: var(--purple); }
  .badge.review { background: #fef8ec; color: #9a5f00; border: 1px solid #f5d98a; }
  .badge.review .badge-dot { background: var(--warning); }
  .badge.accepted { background: #e8f6f3; color: #1a5f57; border: 1px solid #9dd8d0; }
  .badge.accepted .badge-dot { background: var(--success); }
  .badge.rejected { background: #fdf2f2; color: #8b2020; border: 1px solid #f5b8b8; }
  .badge.rejected .badge-dot { background: var(--danger); }
  .badge.assigned { background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; }
  .badge.assigned .badge-dot { background: var(--purple); }
  .actions { display: flex; gap: 6px; align-items: center; }
  .action-btn { width: 32px; height: 32px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--white); color: var(--muted); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; transition: all 0.15s; }
  .action-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }
  .action-btn.danger:hover { border-color: var(--danger); color: var(--danger); }
  .action-btn.success:hover { border-color: var(--success); color: var(--success); }

  .btn-primary { background: var(--navy); color: var(--gold-light); border: none; border-radius: var(--radius-sm); padding: 10px 22px; font-size: 13.5px; font-weight: 500; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
  .btn-primary:hover { background: var(--navy-mid); transform: translateY(-1px); box-shadow: var(--shadow); }

  .btn-add-topic {
    background: var(--white); color: var(--navy); border: 1px solid var(--gold);
    border-radius: var(--radius-sm); padding: 10px 22px; font-size: 13.5px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s;
    display: inline-flex; align-items: center; gap: 8px;
  }
  .btn-add-topic:hover { background: rgba(201,168,76,0.08); transform: translateY(-1px); box-shadow: var(--shadow); }

  .btn-secondary { background: none; color: var(--text-light); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 9px 18px; font-size: 13px; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s; }
  .btn-secondary:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }

  .modal-backdrop { position: fixed; inset: 0; background: rgba(13,33,55,0.5); backdrop-filter: blur(4px); z-index: 200; display: none; align-items: center; justify-content: center; }
  .modal-backdrop.open { display: flex; }
  .modal { background: var(--white); border-radius: var(--radius); width: 100%; max-width: 820px; box-shadow: var(--shadow-md); animation: fadeUp 0.2s ease; max-height: 92vh; overflow-y: auto; }
  .modal.large { max-width: 1000px; }
  .modal.small { max-width: 500px; }
  .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; background: var(--white); z-index: 10; }
  .modal-title { font-family: 'Libre Baskerville', serif; font-size: 18px; color: var(--navy); }
  .modal-close { width: 30px; height: 30px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: none; color: var(--muted); cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; }
  .modal-close:hover { background: var(--bg); color: var(--navy); }
  .modal-body { padding: 24px; }
  .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; position: sticky; bottom: 0; background: var(--white); z-index: 10; }

  .form-section { margin-bottom: 24px; }
  .form-section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); padding-bottom: 10px; border-bottom: 1px solid var(--border); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
  .form-section-title i { color: var(--gold); }
  .form-grid { display: grid; gap: 16px; }
  .form-grid.cols-2 { grid-template-columns: 1fr 1fr; }
  .form-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
  .form-group { display: flex; flex-direction: column; gap: 6px; }
  .field-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); }
  .field-required { color: var(--danger); margin-left: 3px; }
  .form-input, .form-select, .form-textarea { padding: 11px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-family: 'DM Sans', sans-serif; font-size: 14px; color: var(--text); background: var(--white); transition: all 0.15s; outline: none; }
  .form-input:focus, .form-select:focus, .form-textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(44,111,173,0.12); }
  .form-textarea { resize: vertical; min-height: 80px; }
  .notice-box { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px 16px; font-size: 12.5px; color: var(--muted); line-height: 1.6; display: flex; gap: 10px; align-items: flex-start; }
  .field-hint { font-size: 11px; color: var(--muted); margin-top: -2px; font-style: italic; }

  .topics-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 10px; }
  .topic-row {
    display: flex; align-items: center; gap: 8px;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: 8px 12px;
    animation: fadeUp 0.15s ease;
  }
  .topic-row select { flex: 1; padding: 6px 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-family: 'DM Sans', sans-serif; font-size: 13px; color: var(--text); background: var(--white); outline: none; }
  .topic-row select:focus { border-color: var(--accent); }
  .topic-remove-btn {
    width: 28px; height: 28px; border-radius: var(--radius-sm); border: 1px solid #f5b8b8;
    background: #fdf2f2; color: var(--danger); cursor: pointer; display: flex;
    align-items: center; justify-content: center; font-size: 11px; flex-shrink: 0;
    transition: all 0.15s;
  }
  .topic-remove-btn:hover { background: var(--danger); color: white; }
  .btn-add-another-topic {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; font-size: 12.5px; font-weight: 500;
    background: rgba(201,168,76,0.08); color: var(--navy);
    border: 1px dashed var(--gold); border-radius: var(--radius-sm);
    cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all 0.15s;
  }
  .btn-add-another-topic:hover { background: rgba(201,168,76,0.16); }
  .topics-empty-hint { font-size: 12px; color: var(--muted); font-style: italic; padding: 8px 0; }

  .add-topic-modal-icon {
    width: 48px; height: 48px; background: rgba(201,168,76,0.1);
    border-radius: var(--radius); display: flex; align-items: center;
    justify-content: center; font-size: 22px; margin-bottom: 16px;
  }

  .article-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
  .detail-card { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px; }
  .detail-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
  .detail-value { font-size: 14px; color: var(--text); font-weight: 500; }
  .abstract-box { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px; margin: 16px 0; line-height: 1.6; }

  .pagination { display: flex; justify-content: center; gap: 8px; margin: 20px; }
  .page-btn { padding: 8px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--white); color: var(--text-light); cursor: pointer; font-size: 13px; transition: all 0.15s; }
  .page-btn:hover { border-color: var(--navy); color: var(--navy); }
  .page-btn.active { background: var(--navy); color: var(--gold-light); border-color: var(--navy); }
  .page-btn:disabled { opacity: 0.5; cursor: not-allowed; }

  .footer { background: var(--navy); color: rgba(255,255,255,0.45); text-align: center; padding: 22px; margin-top: 48px; font-size: 13px; }
  .hidden { display: none !important; }
  @keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }

  .topic-badge-inline {
    display: inline-flex; align-items: center; gap: 4px;
    background: rgba(201,168,76,0.1); color: var(--navy);
    padding: 2px 8px; border-radius: 10px; font-size: 10.5px;
    border: 1px solid rgba(201,168,76,0.3); margin-top: 4px;
  }

  .topic-filter-select {
    padding: 8px 14px; border: 1px solid var(--border);
    border-radius: var(--radius-sm); font-size: 13px;
    font-family: 'DM Sans', sans-serif; color: var(--text-light);
    background: var(--white); cursor: pointer; outline: none;
    min-width: 180px;
  }
  .topic-filter-select:focus { border-color: var(--accent); }

  @media (max-width: 900px) {
    .stats-row { grid-template-columns: repeat(2, 1fr); }
    .form-grid.cols-2, .form-grid.cols-3, .article-detail-grid { grid-template-columns: 1fr; }
    .topbar { padding: 0 16px; } .nav-links { display: none; }
    .page { padding: 24px 16px; } .dates-block { grid-template-columns: 1fr; }
    .header-actions { flex-wrap: wrap; }
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
    <a href="conferences.php" class="nav-link active">Conferences & Articles</a>
    <a href="evaluators.html" class="nav-link">Evaluators</a>
    <a href="final_decisions.php" class="nav-link">Final Decision</a>
    <a href="profile.php" class="nav-link">Profile</a>
  </nav>
  <div class="topbar-right">
    <div class="search-wrap">
      <span class="search-icon">🔍</span>
      <input type="text" class="search-input" id="searchConference" placeholder="Search a conference...">
    </div>
    <a href="logout.php" class="logout-btn"><span>↪</span> Logout</a>
  </div>
</header>

<main class="page">
  <div id="conferencesView">
    <div class="page-header">
      <div class="page-header-row">
        <h1>Management <em>of conferences</em></h1>
        <div class="header-actions">
          <button class="btn-add-topic" id="openAddTopicModal">
            <i class="fas fa-tags"></i> Add Topic
          </button>
          <button class="btn-primary" id="openCreateConfModal">
            <i class="fas fa-plus"></i> Create Conference
          </button>
        </div>
      </div>
      <p>Click "View Articles" to manage and evaluate submissions</p>
    </div>

    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon active"><i class="fas fa-calendar-check"></i></div>
        <div><div class="stat-value" id="activeCount"><?php echo $active_count; ?></div><div class="stat-label">Active Conferences</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon upcoming"><i class="fas fa-hourglass-half"></i></div>
        <div><div class="stat-value" id="upcomingCount"><?php echo $upcoming_count; ?></div><div class="stat-label">Upcoming</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon closed"><i class="fas fa-check-circle"></i></div>
        <div><div class="stat-value" id="closedCount"><?php echo $closed_count; ?></div><div class="stat-label">Closed</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon total"><i class="fas fa-file-alt"></i></div>
        <div><div class="stat-value" id="totalArticlesCount"><?php echo $total_articles; ?></div><div class="stat-label">Total Articles</div></div>
      </div>
    </div>

    <div class="filters-bar">
      <div class="filter-tabs">
        <button class="filter-tab active" data-filter="all">All <span class="filter-count" id="allCount"><?php echo count($conferences); ?></span></button>
        <button class="filter-tab" data-filter="active">Active <span class="filter-count" id="activeFilterCount"><?php echo $active_count; ?></span></button>
        <button class="filter-tab" data-filter="upcoming">Upcoming <span class="filter-count" id="upcomingFilterCount"><?php echo $upcoming_count; ?></span></button>
        <button class="filter-tab" data-filter="closed">Closed <span class="filter-count" id="closedFilterCount"><?php echo $closed_count; ?></span></button>
      </div>
      <div class="toolbar-right">
        <select class="sort-select" id="sortSelect">
          <option value="date-desc">Most Recent</option>
          <option value="date-asc">Oldest</option>
          <option value="name">Name A→Z</option>
          <option value="articles">Articles (desc)</option>
        </select>
      </div>
    </div>

    <div class="conference-grid" id="conferenceGrid"></div>
    <div class="pagination" id="confPagination"></div>
  </div>

  <div id="articlesView" class="hidden">
    <div class="page-header">
      <div class="page-header-row">
        <button class="btn-secondary" onclick="backToConferences()"><i class="fas fa-arrow-left"></i> Back to Conferences</button>
        <h1 id="articlesTitle">Articles <em>submitted</em></h1>
      </div>
      <p id="articlesSubtitle">Manage and evaluate articles for this conference</p>
    </div>
    <div class="articles-section">
      <div class="articles-header">
        <h2><i class="fas fa-folder-open"></i> <span id="currentConfName">Conference</span></h2>
        <button class="close-btn" onclick="backToConferences()"><i class="fas fa-times"></i></button>
      </div>
      <div class="articles-toolbar">
        <div class="search-wrap" style="flex: 1; max-width: 300px;">
          <span class="search-icon">🔍</span>
          <input type="text" class="search-input" id="searchArticle" placeholder="Search an article..." style="width: 100%;">
        </div>
        <select class="topic-filter-select" id="articleTopicFilter">
          <option value="">All Topics</option>
        </select>
        <select class="sort-select" id="articleSortSelect">
          <option value="date-desc">Most Recent</option>
          <option value="date-asc">Oldest</option>
          <option value="title">Title A→Z</option>
        </select>
      </div>
      <div class="articles-content">
        <table class="data-table">
          <thead><tr><th>Article / Author</th><th>Topic</th><th>Submission Date</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody id="articlesTableBody"></tbody>
        </table>
      </div>
      <div class="pagination" id="articlePagination"></div>
    </div>
  </div>
</main>

<footer class="footer">© 2026 ConfManager · Hassiba Benbouali University of Chlef · All rights reserved</footer>

<!-- MODAL: CREATE/EDIT CONFERENCE -->
<div id="confModal" class="modal-backdrop">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="modalTitle">Create Conference</div>
      <button class="modal-close" onclick="closeModal('confModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form id="conferenceForm">
        <input type="hidden" id="confId" value="">
        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-info-circle"></i> General Information</div>
          <div class="form-grid cols-2">
            <div class="form-group">
              <label class="field-label">Name (French) <span class="field-required">*</span></label>
              <input type="text" class="form-input" id="confNameFr" placeholder="ex: International Conference on AI">
            </div>
            <div class="form-group">
              <label class="field-label">Name (English) <span class="field-required">*</span></label>
              <input type="text" class="form-input" id="confNameEn" placeholder="ex: International Conference on AI">
            </div>
            <div class="form-group">
              <label class="field-label">Event Type</label>
              <select class="form-select" id="confType">
                <option value="Conference">Conference</option>
                <option value="Seminar">Seminar</option>
                <option value="Colloquium">Colloquium</option>
              </select>
            </div>
            <div class="form-group">
              <label class="field-label">Accepted Disciplines</label>
              <input type="text" class="form-input" id="confDisciplines" placeholder="Computer Science, AI, Education...">
            </div>
            <div class="form-group">
              <label class="field-label">Organizer</label>
              <input type="text" class="form-input" id="confOrganizer" placeholder="University / Institute">
            </div>
            <div class="form-group">
              <label class="field-label">Location</label>
              <input type="text" class="form-input" id="confLocation" placeholder="City, Country">
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-calendar-alt"></i> Conference Dates</div>
          <div class="form-grid cols-2">
            <div class="form-group">
              <label class="field-label">Start Date <span class="field-required">*</span></label>
              <input type="date" class="form-input" id="confStartDate">
            </div>
            <div class="form-group">
              <label class="field-label">End Date <span class="field-required">*</span></label>
              <input type="date" class="form-input" id="confEndDate">
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-file-upload"></i> Article Submission</div>
          <div class="form-grid cols-2">
            <div class="form-group">
              <label class="field-label">Submission Opening Date <span class="field-required">*</span></label>
              <input type="date" class="form-input" id="confSubmissionStartDate">
              <span class="field-hint">Start of the period when authors can submit</span>
            </div>
            <div class="form-group">
              <label class="field-label">Submission Deadline <span class="field-required">*</span></label>
              <input type="date" class="form-input" id="confSubmissionDeadline">
              <span class="field-hint">Deadline for new submissions</span>
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-search"></i> Review Period</div>
          <div class="form-grid cols-2">
            <div class="form-group">
              <label class="field-label">Review Start <span class="field-required">*</span></label>
              <input type="date" class="form-input" id="confReviewStartDate">
              <span class="field-hint">When reviewers begin their work</span>
            </div>
            <div class="form-group">
              <label class="field-label">Review End <span class="field-required">*</span></label>
              <input type="date" class="form-input" id="confReviewEndDate">
              <span class="field-hint">Deadline for returning evaluations</span>
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-tags"></i> Conference Topics</div>
          <div id="confTopicsList" class="topics-list"></div>
          <p id="confTopicsEmpty" class="topics-empty-hint">No topic selected. Click the button below to add one.</p>
          <button type="button" class="btn-add-another-topic" onclick="addTopicRow()">
            <i class="fas fa-plus"></i> Add Topic
          </button>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-file-alt"></i> Publication Requirements</div>
          <div class="form-group">
            <textarea class="form-textarea" id="confRequirements" placeholder="Publication conditions, indexing, etc..."></textarea>
          </div>
        </div>

        <div class="notice-box">
          <i class="fas fa-info-circle"></i>
          <span>After registration, the conference will be visible to researchers. Submissions will open automatically on the defined opening date.</span>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('confModal')">Cancel</button>
      <button class="btn-primary" id="saveConferenceBtn"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- MODAL: ADD TOPIC -->
<div id="addTopicModal" class="modal-backdrop">
  <div class="modal small">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-tag" style="color:var(--gold);margin-right:8px;"></i> Add New Topic</div>
      <button class="modal-close" onclick="closeModal('addTopicModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group" style="margin-bottom:16px;">
        <label class="field-label">Topic Name <span class="field-required">*</span></label>
        <input type="text" class="form-input" id="newTopicName" placeholder="ex: Machine Learning, IoT, Linguistics...">
      </div>
      <div class="form-group">
        <label class="field-label">Conference (optional)</label>
        <select class="form-select" id="newTopicConference">
          <option value="">— General Topic —</option>
        </select>
        <span class="field-hint">Leave empty to create a reusable topic for all conferences</span>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('addTopicModal')">Cancel</button>
      <button class="btn-primary" onclick="saveNewTopic()"><i class="fas fa-plus"></i> Add Topic</button>
    </div>
  </div>
</div>

<!-- MODAL: ARTICLE DETAILS -->
<div id="articleDetailModal" class="modal-backdrop">
  <div class="modal large">
    <div class="modal-header">
      <div class="modal-title">Article Details</div>
      <button class="modal-close" onclick="closeModal('articleDetailModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="articleDetailContent"></div>
    <div class="modal-footer">
      <a id="downloadPdfBtn" class="btn-primary" href="#" target="_blank"><i class="fas fa-download"></i> Download PDF</a>
      <button class="btn-secondary" onclick="closeModal('articleDetailModal')">Close</button>
    </div>
  </div>
</div>

<!-- MODAL: REJECT ARTICLE -->
<div id="rejectModal" class="modal-backdrop">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" style="color: var(--danger);"><i class="fas fa-times-circle"></i> Reject Article</div>
      <button class="modal-close" onclick="closeModal('rejectModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div id="rejectArticleInfo" style="background: var(--bg); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 16px;"></div>
      <div class="form-group">
        <label class="field-label">Rejection Reason <span class="field-required">*</span></label>
        <textarea class="form-textarea" id="rejectReason" placeholder="Explain to the author why their article is rejected..." rows="4"></textarea>
      </div>
      <div class="notice-box" style="background: #fdf2f2; border-color: #f5b8b8; margin-top: 12px;">
        <i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i>
        <span style="color: var(--danger);">This action is final. The author will be notified by email of the rejection with your reason.</span>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('rejectModal')">Cancel</button>
      <button class="btn-primary" style="background: var(--danger);" onclick="confirmReject()"><i class="fas fa-paper-plane"></i> Send Rejection</button>
    </div>
  </div>
</div>
<script>
// ==================== DATA FROM PHP ====================
const dbTopics = <?php echo $topics_json; ?>;
const dbConferences = <?php echo $conferences_json; ?>;
const dbArticles = <?php echo $articles_json; ?>;

let topicsData = dbTopics;
let topics = dbTopics.map(t => t.name);

let conferences = dbConferences.map(c => ({
  id: parseInt(c.id),
  nameFr: c.name_fr,
  nameEn: c.name_en,
  type: c.type,
  disciplines: c.disciplines || '',
  organizer: c.organizer || '',
  location: c.location || '',
  startDate: c.start_date,
  endDate: c.end_date,
  submissionStartDate: c.submission_start_date,
  submissionDeadline: c.submission_deadline,
  reviewStartDate: c.review_start_date,
  reviewEndDate: c.review_end_date,
  requirements: c.requirements || '',
  articlesCount: parseInt(c.articles_count) || 0,
  maxArticles: parseInt(c.max_articles) || 40,
  status: computeStatusFromDates(c.submission_start_date, c.submission_deadline),
  createdAt: c.created_at ? c.created_at.split(' ')[0] : new Date().toISOString().split('T')[0],
  confTopics: c.topic_names ? c.topic_names.split(', ') : []
}));

let articles = dbArticles.map(a => ({
  id: parseInt(a.id),
  conferenceId: parseInt(a.conference_id),
  title: a.title,
  author: a.author,
  authorInstitution: a.author_institution || '',
  authorEmail: a.author_email || '',
  submissionDate: a.submission_date,
  status: a.status,
  abstract: a.abstract || '',
  keywords: a.keywords || '',
  file: a.file_path || '',
  domain: a.topic_name || 'Unclassified',
  topicId: a.topic_id,
  topicName: a.topic_name || 'Unclassified',
  assignedTo: a.assigned_to || '',
  rejectReason: a.reject_reason || ''
}));

function computeStatusFromDates(subStart, subEnd) {
  const today = new Date(); today.setHours(0,0,0,0);
  const start = subStart ? new Date(subStart) : null;
  const end = subEnd ? new Date(subEnd) : null;
  if (end && today > end) return 'closed';
  if (start && today >= start) return 'active';
  return 'upcoming';
}

let currentFilter='all', currentSort='date-desc', currentPage=1, itemsPerPage=6, editId=null;
let currentConferenceId=null, currentArticleId=null;
let articleSearchTerm='', articleSort='date-desc', articlePage=1, articlesPerPage=5;
let articleTopicFilter = '';
let currentArticleTopics = [];

// ==================== TOPIC ROWS in conf form ====================
function buildTopicOptions(selectedValue='') {
  let opts = '<option value="">— Select a topic —</option>';
  topicsData.forEach(t => {
    const isSelected = (t.name === selectedValue || t.id == selectedValue) ? 'selected' : '';
    opts += `<option value="${t.id}" ${isSelected}>${t.name}</option>`;
  });
  return opts;
}

function refreshTopicsEmptyHint() {
  const rows = document.querySelectorAll('#confTopicsList .topic-row');
  document.getElementById('confTopicsEmpty').style.display = rows.length ? 'none' : 'block';
}

function addTopicRow(selectedValue='') {
  const list = document.getElementById('confTopicsList');
  const rowId = 'trow_' + Date.now();
  const div = document.createElement('div');
  div.className = 'topic-row';
  div.id = rowId;
  div.innerHTML = `
    <select>${buildTopicOptions(selectedValue)}</select>
    <button type="button" class="topic-remove-btn" onclick="removeTopicRow('${rowId}')" title="Remove">
      <i class="fas fa-times"></i>
    </button>`;
  list.appendChild(div);
  refreshTopicsEmptyHint();
}

function removeTopicRow(rowId) {
  const el = document.getElementById(rowId);
  if (el) el.remove();
  refreshTopicsEmptyHint();
}

function getSelectedTopicIds() {
  const selects = document.querySelectorAll('#confTopicsList .topic-row select');
  const values = [];
  selects.forEach(s => { if (s.value) values.push(parseInt(s.value)); });
  return [...new Set(values)];
}

function loadTopicsIntoForm(topicsArr) {
  document.getElementById('confTopicsList').innerHTML = '';
  (topicsArr || []).forEach(t => {
    const topicObj = topicsData.find(td => td.name === t);
    addTopicRow(topicObj ? topicObj.id : '');
  });
  refreshTopicsEmptyHint();
}

// ==================== ADD TOPIC MODAL ====================
function openAddTopicModal() {
  document.getElementById('newTopicName').value = '';
  const sel = document.getElementById('newTopicConference');
  sel.innerHTML = '<option value="">— General Topic —</option>';
  conferences.forEach(c => {
    sel.innerHTML += `<option value="${c.id}">${c.nameFr}</option>`;
  });
  openModal('addTopicModal');
}

function saveNewTopic() {
  const name = document.getElementById('newTopicName').value.trim();
  if (!name) { alert('Please enter a topic name.'); return; }

  const confId = parseInt(document.getElementById('newTopicConference').value) || 0;

  fetch('conferences.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `action=add_topic&name=${encodeURIComponent(name)}&conference_id=${confId}`
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      topicsData.push({ id: data.id, name: data.name });
      topics.push(data.name);
      if (confId > 0) {
        const conf = conferences.find(c => c.id === confId);
        if (conf) { conf.confTopics = conf.confTopics || []; conf.confTopics.push(data.name); }
      }
      closeModal('addTopicModal');
      alert(`Topic "${data.name}" added successfully!`);
    } else {
      alert(data.error || 'Error adding topic');
    }
  })
  .catch(err => alert('Network error: ' + err));
}

// ==================== HELPERS ====================
function fmt(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-US', { day:'2-digit', month:'short', year:'numeric' });
}
function computeStatus(conf) {
  const today = new Date(); today.setHours(0,0,0,0);
  const subStart = conf.submissionStartDate ? new Date(conf.submissionStartDate) : null;
  const subEnd   = conf.submissionDeadline  ? new Date(conf.submissionDeadline)  : null;
  if (subEnd && today > subEnd) return 'closed';
  if (subStart && today >= subStart) return 'active';
  return 'upcoming';
}
function submissionWindowStatus(conf) {
  const today = new Date(); today.setHours(0,0,0,0);
  const subStart = conf.submissionStartDate ? new Date(conf.submissionStartDate) : null;
  const subEnd   = conf.submissionDeadline  ? new Date(conf.submissionDeadline)  : null;
  if (subEnd && today > subEnd) return 'closed';
  if (subStart && today < subStart) return 'not-yet';
  return 'open';
}
function updateConferenceStatuses() {
  conferences.forEach(c => {
    c.status = computeStatus(c);
    c.articlesCount = articles.filter(a => a.conferenceId === c.id).length;
  });
}
function isEditAllowed(conf) {
  if (!conf.submissionStartDate) return true;
  const today = new Date(); today.setHours(0,0,0,0);
  return today <= new Date(conf.submissionStartDate);
}

// ==================== FILTER / SORT ====================
function getFilteredConferences() {
  let filtered = [...conferences];
  if (currentFilter !== 'all') filtered = filtered.filter(c => c.status === currentFilter);
  const q = document.getElementById('searchConference')?.value.toLowerCase() || '';
  if (q) filtered = filtered.filter(c => c.nameFr.toLowerCase().includes(q) || c.nameEn.toLowerCase().includes(q));
  switch(currentSort) {
    case 'date-desc': filtered.sort((a,b)=>new Date(b.createdAt)-new Date(a.createdAt)); break;
    case 'date-asc':  filtered.sort((a,b)=>new Date(a.createdAt)-new Date(b.createdAt)); break;
    case 'name':      filtered.sort((a,b)=>a.nameFr.localeCompare(b.nameFr)); break;
    case 'articles':  filtered.sort((a,b)=>b.articlesCount-a.articlesCount); break;
  }
  return filtered;
}

// ==================== STATS ====================
function renderStats() {
  const active=conferences.filter(c=>c.status==='active').length;
  const upcoming=conferences.filter(c=>c.status==='upcoming').length;
  const closed=conferences.filter(c=>c.status==='closed').length;
  document.getElementById('activeCount').textContent=active;
  document.getElementById('upcomingCount').textContent=upcoming;
  document.getElementById('closedCount').textContent=closed;
  document.getElementById('totalArticlesCount').textContent=articles.length;
  document.getElementById('allCount').textContent=conferences.length;
  document.getElementById('activeFilterCount').textContent=active;
  document.getElementById('upcomingFilterCount').textContent=upcoming;
  document.getElementById('closedFilterCount').textContent=closed;
}

// ==================== RENDER CONFERENCES ====================
function renderConferences() {
  const filtered=getFilteredConferences();
  const start=(currentPage-1)*itemsPerPage;
  const paginated=filtered.slice(start,start+itemsPerPage);
  let html='';
  paginated.forEach(conf=>{
    const statusMap={active:['active','Active'],upcoming:['upcoming','Upcoming'],closed:['closed','Closed']};
    const [sc,st]=statusMap[conf.status]||['closed','Closed'];
    const canEdit=isEditAllowed(conf);
    const winStatus=submissionWindowStatus(conf);
    const winLabels={open:['open','fas fa-unlock-alt','Submissions Open'],'not-yet':['not-yet','fas fa-hourglass-start',`Opens ${fmt(conf.submissionStartDate)}`],closed:['closed','fas fa-lock','Submissions Closed']};
    const [wc,wi,wl]=winLabels[winStatus];
    const topicsHtml = conf.confTopics && conf.confTopics.length
      ? conf.confTopics.map(t=>`<span style="background:rgba(201,168,76,0.1);color:var(--navy);padding:2px 8px;border-radius:10px;font-size:10.5px;border:1px solid rgba(201,168,76,0.3);">${t}</span>`).join('')
      : '<span style="color:var(--muted);font-size:11px;font-style:italic;">No topic</span>';

    html+=`<div class="conf-card" data-id="${conf.id}">
      <div class="conf-card-header">
        <h3>${conf.nameFr}</h3>
        <div class="conf-type">${conf.type}</div>
        <span class="conf-status ${sc}">${st}</span>
      </div>
      <div class="conf-card-body">
        <div class="dates-block">
          <div class="date-item full-width" style="background:rgba(13,33,55,0.04);border-color:rgba(13,33,55,0.12);">
            <div class="date-item-label"><i class="fas fa-calendar-days" style="color:var(--navy);margin-right:4px;"></i>Conference Dates</div>
            <div class="date-item-value range">${fmt(conf.startDate)} → ${fmt(conf.endDate)}</div>
          </div>
          <div class="date-item highlight-deadline">
            <div class="date-item-label">Submission Opens</div>
            <div class="date-item-value">${fmt(conf.submissionStartDate)}</div>
          </div>
          <div class="date-item highlight-deadline">
            <div class="date-item-label">Submission Deadline</div>
            <div class="date-item-value">${fmt(conf.submissionDeadline)}</div>
          </div>
          <div class="date-item full-width">
            <div class="date-item-label"><i class="fas fa-magnifying-glass" style="color:var(--purple);margin-right:4px;"></i>Review Period</div>
            <div class="date-item-value range">${fmt(conf.reviewStartDate)} → ${fmt(conf.reviewEndDate)}</div>
          </div>
        </div>
        <div class="conf-stats">
          <div class="stat-row"><span class="stat-label-stat">Disciplines</span><span class="stat-value-stat">${conf.disciplines}</span></div>
          <div class="stat-row"><span class="stat-label-stat">Organizer</span><span class="stat-value-stat">${conf.organizer}</span></div>
          <div class="stat-row"><span class="stat-label-stat">Location</span><span class="stat-value-stat">${conf.location}</span></div>
        </div>
        <div style="margin:10px 0 6px;">
          <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);margin-bottom:5px;font-weight:600;">
            <i class="fas fa-tags" style="margin-right:4px;color:var(--gold);"></i>Topics
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:4px;">${topicsHtml}</div>
        </div>
        <div class="submission-status-badge ${wc}"><i class="${wi}"></i> ${wl}</div>
        <div style="font-size:11px;color:var(--muted);margin-top:6px;display:flex;align-items:center;gap:5px;">
          <i class="fas fa-${canEdit?'pencil':'ban'}" style="color:${canEdit?'var(--success)':'var(--danger)'}"></i>
          ${canEdit?`Editable until ${fmt(conf.submissionStartDate)}`:`Editing closed since ${fmt(conf.submissionStartDate)}`}
        </div>
      </div>
      <div class="conf-card-footer">
        <button class="btn-sm btn-outline" onclick="editConference(${conf.id})" ${!canEdit?'disabled title="Editing deadline passed"':''}>
          <i class="fas fa-edit"></i> Edit
        </button>
        <button class="btn-sm btn-primary-sm" onclick="viewConferenceArticles(${conf.id})">
          <i class="fas fa-folder-open"></i> View Articles (${conf.articlesCount})
        </button>
      </div>
    </div>`;
  });
  document.getElementById('conferenceGrid').innerHTML = html || '<div style="text-align:center;padding:40px;color:var(--muted);">No conferences found.</div>';
  renderPagination(filtered.length,'confPagination',currentPage,changePage);
}

// ==================== PAGINATION ====================
function renderPagination(totalItems,containerId,currentPageNum,callback) {
  const perPage=containerId==='confPagination'?itemsPerPage:articlesPerPage;
  const total=Math.ceil(totalItems/perPage);
  const container=document.getElementById(containerId);
  if(total<=1){container.innerHTML='';return;}
  let html=`<button class="page-btn" onclick="${callback.name}(${currentPageNum-1})" ${currentPageNum===1?'disabled':''}><i class="fas fa-chevron-left"></i></button>`;
  for(let i=1;i<=Math.min(total,5);i++) html+=`<button class="page-btn ${currentPageNum===i?'active':''}" onclick="${callback.name}(${i})">${i}</button>`;
  if(total>5) html+=`<span style="padding:8px;">...</span><button class="page-btn" onclick="${callback.name}(${total})">${total}</button>`;
  html+=`<button class="page-btn" onclick="${callback.name}(${currentPageNum+1})" ${currentPageNum===total?'disabled':''}><i class="fas fa-chevron-right"></i></button>`;
  container.innerHTML=html;
}
function changePage(page){currentPage=page;renderConferences();}

// ==================== ARTICLES ====================
function viewConferenceArticles(confId) {
  currentConferenceId=confId;
  const conf=conferences.find(c=>c.id===confId);
  document.getElementById('currentConfName').textContent=conf.nameFr;
  document.getElementById('articlesTitle').innerHTML=`Articles <em>${conf.nameFr}</em>`;
  document.getElementById('conferencesView').classList.add('hidden');
  document.getElementById('articlesView').classList.remove('hidden');
  articlePage=1; articleSearchTerm=''; articleTopicFilter='';
  document.getElementById('searchArticle').value='';
  document.getElementById('articleTopicFilter').value='';

  // Populate topic filter dropdown with conference topics
  const topicFilter = document.getElementById('articleTopicFilter');
  topicFilter.innerHTML = '<option value="">All Topics</option>';

  if (conf.confTopics && conf.confTopics.length > 0) {
    conf.confTopics.forEach(topicName => {
      const topicObj = topicsData.find(t => t.name === topicName);
      if (topicObj) {
        topicFilter.innerHTML += `<option value="${topicObj.id}">${topicObj.name}</option>`;
      }
    });
  }

  renderArticles();
}

function backToConferences() {
  document.getElementById('articlesView').classList.add('hidden');
  document.getElementById('conferencesView').classList.remove('hidden');
  currentConferenceId=null; renderConferences();
}

function getFilteredArticles() {
  let filtered=articles.filter(a=>a.conferenceId===currentConferenceId);
  if(articleSearchTerm) filtered=filtered.filter(a=>a.title.toLowerCase().includes(articleSearchTerm)||a.author.toLowerCase().includes(articleSearchTerm));
  if(articleTopicFilter) filtered=filtered.filter(a=>a.topicId == articleTopicFilter);
  switch(articleSort){
    case 'date-desc':filtered.sort((a,b)=>new Date(b.submissionDate)-new Date(a.submissionDate));break;
    case 'date-asc':filtered.sort((a,b)=>new Date(a.submissionDate)-new Date(b.submissionDate));break;
    case 'title':filtered.sort((a,b)=>a.title.localeCompare(b.title));break;
  }
  return filtered;
}

function renderArticles() {
  const filtered=getFilteredArticles();
  const start=(articlePage-1)*articlesPerPage;
  const paginated=filtered.slice(start,start+articlesPerPage);
  let html='';
  paginated.forEach(article=>{
    const badgeText={new:'New',review:'Under Review',accepted:'Accepted',rejected:'Rejected',revision:'Revision',assigned:'Assigned to Reviewer'}[article.status]||article.status;

    const topicBadge = article.topicName && article.topicName !== 'Unclassified' 
      ? `<span class="topic-badge-inline"><i class="fas fa-tag" style="font-size:9px;"></i> ${article.topicName}</span>`
      : '';

    let actionButtons='';
    if(article.status==='accepted'||article.status==='assigned'){
      actionButtons=`<button class="action-btn" onclick="viewArticleDetails(${article.id})" title="View details"><i class="fas fa-eye"></i></button>`;
      if(article.assignedTo) actionButtons+=`<span style="font-size:11px;color:var(--purple);margin-left:8px;"><i class="fas fa-user-check"></i> ${article.assignedTo}</span>`;
    } else if(article.status==='rejected'){
      actionButtons=`<button class="action-btn" onclick="viewArticleDetails(${article.id})" title="View details"><i class="fas fa-eye"></i></button><span style="font-size:11px;color:var(--danger);margin-left:8px;"><i class="fas fa-ban"></i> Rejected</span>`;
    } else {
      actionButtons=`<button class="action-btn" onclick="viewArticleDetails(${article.id})" title="View details"><i class="fas fa-eye"></i></button><button class="action-btn success" onclick="acceptArticle(${article.id})" title="Accept"><i class="fas fa-check"></i></button><button class="action-btn danger" onclick="openRejectModal(${article.id})" title="Reject"><i class="fas fa-times"></i></button>`;
    }

    html+=`<tr>
      <td>
        <div class="article-title">${article.title}</div>
        <div class="article-meta">${article.author} · ${article.authorInstitution}</div>
        ${topicBadge}
      </td>
      <td><span class="topic-badge-inline"><i class="fas fa-tag" style="font-size:9px;"></i> ${article.topicName || 'N/A'}</span></td>
      <td>${fmt(article.submissionDate)}</td>
      <td><span class="badge ${article.status}"><span class="badge-dot"></span>${badgeText}</span></td>
      <td><div class="actions">${actionButtons}</div></td>
    </tr>`;
  });

  document.getElementById('articlesTableBody').innerHTML=html||'<tr><td colspan="5" style="text-align:center;padding:40px;">No articles found</td></tr>';
  renderPagination(filtered.length,'articlePagination',articlePage,changeArticlePage);
}

function changeArticlePage(page){articlePage=page;renderArticles();}

function acceptArticle(id){
  const article=articles.find(a=>a.id===id);
  if(!article)return;
  if(!confirm(`Accept article "${article.title}" ?`))return;

  const reviewers={'Informatique':'Dr. Karim Tech','Intelligence Artificielle':'Dr. AI Expert','Robotique':'Prof. Samir Bot','Linguistique':'Dr. Amina Lang','Langue arabe':'Dr. Arabic Spec','Education':'Prof. Fatima Edu','IoT':'Dr. Yacine Net','Sécurité informatique':'Dr. Security Lead','Big Data':'Dr. Data Master','Cloud Computing':'Prof. Cloud Expert','Ingénierie':'Dr. Eng Pro','Mathématiques':'Prof. Math Prof',default:'Dr. General Reviewer'};

  const assignedTo = reviewers[article.topicName] || reviewers.default;

  fetch('conferences.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `action=update_article_status&article_id=${id}&status=assigned&assigned_to=${encodeURIComponent(assignedTo)}`
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      article.status='assigned';
      article.assignedTo=assignedTo;
      renderArticles(); updateConferenceStatuses(); renderStats();
      alert(`Article accepted and assigned to ${assignedTo}`);
    } else {
      alert(data.error || 'Error updating article');
    }
  })
  .catch(err => alert('Network error: ' + err));
}

function openRejectModal(id){
  currentArticleId=id;
  const a=articles.find(x=>x.id===id);
  document.getElementById('rejectArticleInfo').innerHTML=`<strong style="color:var(--navy);">${a.title}</strong><br><span style="font-size:12px;color:var(--muted);">Author: ${a.author} (${a.authorEmail})</span>`;
  document.getElementById('rejectReason').value='';
  openModal('rejectModal');
}

function confirmReject(){
  const article=articles.find(a=>a.id===currentArticleId);
  if(!article)return;
  const reason=document.getElementById('rejectReason').value.trim();
  if(!reason){alert('Please provide a rejection reason.');return;}

  fetch('conferences.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `action=update_article_status&article_id=${currentArticleId}&status=rejected&reject_reason=${encodeURIComponent(reason)}`
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      article.status='rejected';
      article.rejectReason=reason;
      closeModal('rejectModal');
      renderArticles();
      updateConferenceStatuses();
      renderStats();
      alert(`Article rejected. An email has been sent to ${article.authorEmail}.`);
    } else {
      alert(data.error || 'Error rejecting article');
    }
  })
  .catch(err => alert('Network error: ' + err));
}

// ==================== ARTICLE DETAIL ====================
function viewArticleDetails(id){
  const article=articles.find(a=>a.id===id);if(!article)return;
  const conf=conferences.find(c=>c.id===article.conferenceId);
  let statusHtml='';
  if(article.status==='accepted'||article.status==='assigned') statusHtml=`<span style="color:var(--success);font-weight:600;"><i class="fas fa-check-circle"></i> Accepted${article.assignedTo?' — assigned to '+article.assignedTo:''}</span>`;
  else if(article.status==='rejected') statusHtml=`<span style="color:var(--danger);font-weight:600;"><i class="fas fa-times-circle"></i> Rejected</span><div style="margin-top:8px;padding:12px;background:#fdf2f2;border-radius:var(--radius-sm);border-left:3px solid var(--danger);"><strong style="font-size:11px;color:var(--danger);">REASON:</strong><br><span style="font-size:13px;">${article.rejectReason}</span></div>`;
  else statusHtml=`<span style="color:var(--warning);font-weight:600;"><i class="fas fa-clock"></i> Pending</span>`;

  document.getElementById('articleDetailContent').innerHTML=`<div class="article-detail-grid">
    <div class="detail-card"><div class="detail-label">Title</div><div class="detail-value">${article.title}</div></div>
    <div class="detail-card"><div class="detail-label">Author</div><div class="detail-value">${article.author}</div><div style="font-size:12px;color:var(--muted);margin-top:4px;"><i class="fas fa-envelope"></i> ${article.authorEmail}<br><i class="fas fa-university"></i> ${article.authorInstitution}</div></div>
    <div class="detail-card"><div class="detail-label">Conference</div><div class="detail-value">${conf.nameFr}</div></div>
    <div class="detail-card"><div class="detail-label">Submission Date</div><div class="detail-value">${fmt(article.submissionDate)}</div></div>
    <div class="detail-card"><div class="detail-label">Topic</div><div class="detail-value"><span class="topic-badge-inline"><i class="fas fa-tag" style="font-size:9px;"></i> ${article.topicName || 'N/A'}</span></div></div>
    <div class="detail-card"><div class="detail-label">Status</div><div class="detail-value">${statusHtml}</div></div>
  </div>
  <div><div class="detail-label" style="margin-bottom:8px;">Keywords</div><div style="display:flex;gap:8px;flex-wrap:wrap;">${article.keywords.split(',').map(k=>`<span style="background:var(--bg);padding:4px 12px;border-radius:20px;font-size:12px;color:var(--text-light);">${k.trim()}</span>`).join('')}</div></div>
  <div class="abstract-box"><div class="detail-label" style="margin-bottom:8px;">Abstract</div><p style="color:var(--text);line-height:1.8;">${article.abstract}</p></div>`;

  document.getElementById('downloadPdfBtn').href=article.file;
  document.getElementById('downloadPdfBtn').download=article.file;
  openModal('articleDetailModal');
}

// ==================== MODAL ====================
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){
  document.getElementById(id).classList.remove('open');
  if(id==='confModal'){editId=null;document.getElementById('conferenceForm').reset();document.getElementById('confTopicsList').innerHTML='';refreshTopicsEmptyHint();}
}

// ==================== CONFERENCE FORM ====================
function openConfModal(editMode=false,conf=null){
  document.getElementById('modalTitle').textContent=editMode?'Edit Conference':'Create Conference';
  if(editMode&&conf){
    document.getElementById('confId').value=conf.id;
    document.getElementById('confNameFr').value=conf.nameFr;
    document.getElementById('confNameEn').value=conf.nameEn;
    document.getElementById('confType').value=conf.type;
    document.getElementById('confDisciplines').value=conf.disciplines;
    document.getElementById('confOrganizer').value=conf.organizer;
    document.getElementById('confLocation').value=conf.location;
    document.getElementById('confStartDate').value=conf.startDate;
    document.getElementById('confEndDate').value=conf.endDate;
    document.getElementById('confSubmissionStartDate').value=conf.submissionStartDate||'';
    document.getElementById('confSubmissionDeadline').value=conf.submissionDeadline||'';
    document.getElementById('confReviewStartDate').value=conf.reviewStartDate||'';
    document.getElementById('confReviewEndDate').value=conf.reviewEndDate||'';
    document.getElementById('confRequirements').value=conf.requirements;
    loadTopicsIntoForm(conf.confTopics||[]);
  } else {
    document.getElementById('confId').value='';
    document.getElementById('conferenceForm').reset();
    document.getElementById('confTopicsList').innerHTML='';
    refreshTopicsEmptyHint();
  }
  openModal('confModal');
}

function editConference(id){
  const conf=conferences.find(c=>c.id===id);if(!conf)return;
  if(!isEditAllowed(conf)){alert('The editing deadline for this conference has passed.');return;}
  editId=id;openConfModal(true,conf);
}

function saveConference(){
  const id = document.getElementById('confId').value;
  const nameFr=document.getElementById('confNameFr').value.trim();
  const nameEn=document.getElementById('confNameEn').value.trim();
  const startDate=document.getElementById('confStartDate').value;
  const endDate=document.getElementById('confEndDate').value;
  const submissionStartDate=document.getElementById('confSubmissionStartDate').value;
  const submissionDeadline=document.getElementById('confSubmissionDeadline').value;
  const reviewStartDate=document.getElementById('confReviewStartDate').value;
  const reviewEndDate=document.getElementById('confReviewEndDate').value;

  if(!nameEn||!startDate||!endDate||!submissionStartDate||!submissionDeadline||!reviewStartDate||!reviewEndDate){
    alert('Please fill in all required fields (*).'); return;
  }
  if(new Date(submissionStartDate)>new Date(submissionDeadline)){alert("Submission opening must be before deadline.");return;}
  if(new Date(submissionDeadline)>new Date(reviewStartDate)){alert("Review start must be after submission deadline.");return;}
  if(new Date(reviewStartDate)>new Date(reviewEndDate)){alert("Review end must be after review start.");return;}
  if(new Date(reviewEndDate)>new Date(startDate)){alert("Review must end before conference starts.");return;}

  const topicIds = getSelectedTopicIds();

  const formData = new URLSearchParams();
  formData.append('action', 'save_conference');
  if (id) formData.append('id', id);
  formData.append('name_fr', nameFr);
  formData.append('name_en', nameEn);
  formData.append('type', document.getElementById('confType').value);
  formData.append('disciplines', document.getElementById('confDisciplines').value);
  formData.append('organizer', document.getElementById('confOrganizer').value);
  formData.append('location', document.getElementById('confLocation').value);
  formData.append('start_date', startDate);
  formData.append('end_date', endDate);
  formData.append('submission_start_date', submissionStartDate);
  formData.append('submission_deadline', submissionDeadline);
  formData.append('review_start_date', reviewStartDate);
  formData.append('review_end_date', reviewEndDate);
  formData.append('requirements', document.getElementById('confRequirements').value);
  topicIds.forEach(tid => formData.append('topic_ids[]', tid));

  fetch('conferences.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: formData.toString()
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      // Refresh page to get updated data from server
      window.location.reload();
    } else {
      alert(data.error || 'Error saving conference');
    }
  })
  .catch(err => alert('Network error: ' + err));
}

// ==================== EVENT LISTENERS ====================
document.getElementById('openCreateConfModal').addEventListener('click',()=>openConfModal(false));
document.getElementById('saveConferenceBtn').addEventListener('click',saveConference);
document.getElementById('openAddTopicModal').addEventListener('click',openAddTopicModal);

document.querySelectorAll('.filter-tab').forEach(tab=>{
  tab.addEventListener('click',()=>{
    document.querySelectorAll('.filter-tab').forEach(t=>t.classList.remove('active'));
    tab.classList.add('active'); currentFilter=tab.dataset.filter; currentPage=1; renderConferences();
  });
});
document.getElementById('sortSelect').addEventListener('change',e=>{currentSort=e.target.value;currentPage=1;renderConferences();});
document.getElementById('searchConference').addEventListener('input',()=>{currentPage=1;renderConferences();});
document.getElementById('searchArticle').addEventListener('input',e=>{articleSearchTerm=e.target.value.toLowerCase();articlePage=1;renderArticles();});
document.getElementById('articleSortSelect').addEventListener('change',e=>{articleSort=e.target.value;articlePage=1;renderArticles();});
document.getElementById('articleTopicFilter').addEventListener('change',e=>{articleTopicFilter=e.target.value;articlePage=1;renderArticles();});
document.querySelectorAll('.modal-backdrop').forEach(backdrop=>{
  backdrop.addEventListener('click',e=>{if(e.target===backdrop)backdrop.classList.remove('open');});
});

// ==================== INIT ====================
updateConferenceStatuses();renderStats();renderConferences();

Object.assign(window,{
  editConference,viewConferenceArticles,backToConferences,
  viewArticleDetails,acceptArticle,openRejectModal,confirmReject,
  closeModal,changePage,changeArticlePage,addTopicRow,removeTopicRow,saveNewTopic
});
</script>
</body>
</html>