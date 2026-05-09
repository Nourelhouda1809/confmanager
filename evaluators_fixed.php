<?php
/**
 * ConfManager - Evaluator Management
 * Fixed version matching the actual database schema
 */

session_start();

// Database configuration - UPDATE THESE
$db_host = 'localhost';
$db_name = 'confmanager';
$db_user = 'root';
$db_pass = '';

// Connect to database
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}

// ==================== API MODE ====================
// If this is an AJAX/API request (has action parameter), handle it and exit
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($action) {
    header('Content-Type: application/json; charset=utf-8');

    // Auth check for API
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized - Please login', 'session_active' => false]);
        exit;
    }

    // Verify user is a gestionnaire
    $stmt = $pdo->prepare("SELECT id, role, email, first_name FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(403);
        echo json_encode(['error' => 'User not found in database', 'user_id' => $_SESSION['user_id']]);
        exit;
    }

    if ($user['role'] !== 'gestionnaire') {
        http_response_code(403);
        echo json_encode([
            'error' => 'Forbidden - Manager access required',
            'your_role' => $user['role'],
            'required' => 'gestionnaire',
            'user_id' => $_SESSION['user_id'],
            'email' => $user['email']
        ]);
        exit;
    }

    try {
        switch ($action) {
            case 'list':
                getEvaluators($pdo);
                break;
            case 'add':
                if ($method !== 'POST') {
                    http_response_code(405);
                    echo json_encode(['error' => 'POST required']);
                    exit;
                }
                addEvaluator($pdo);
                break;
            case 'articles':
                getArticles($pdo);
                break;
            case 'assign':
                if ($method !== 'POST') {
                    http_response_code(405);
                    echo json_encode(['error' => 'POST required']);
                    exit;
                }
                assignArticles($pdo);
                break;
            case 'stats':
                getStats($pdo);
                break;
            case 'topics':
                getTopics($pdo);
                break;
            case 'conferences':
                getConferences($pdo);
                break;
            case 'me':
                echo json_encode([
                    'success' => true,
                    'user_id' => $_SESSION['user_id'],
                    'role' => $user['role'],
                    'email' => $user['email'],
                    'name' => $user['first_name']
                ]);
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action. Use: list, add, articles, assign, stats, topics, conferences, me']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }

    // CRITICAL: Exit after API response so HTML is not output
    exit;
}

// ==================== HTML PAGE MODE ====================
// If no action parameter, show the HTML page (also requires auth)

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'gestionnaire') {
    http_response_code(403);
    die('<h1>Access Denied</h1><p>You must be a manager (gestionnaire) to access this page.</p><a href="logout.php">Logout</a>');
}

// ==================== FUNCTIONS ====================

function getEvaluators($pdo) {
    $search = $_GET['search'] ?? '';
    $filter = $_GET['filter'] ?? 'all';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 10;
    $offset = ($page - 1) * $perPage;

    $sql = "
        SELECT 
            u.id,
            CONCAT(u.first_name, ' ', u.last_name) as name,
            u.email,
            u.institution,
            u.grade,
            u.labo,
            u.status,
            u.role,
            u.keywords as specialties,
            COUNT(DISTINCT ar.article_id) as assigned_count
        FROM users u
        LEFT JOIN article_reviewers ar ON u.id = ar.evaluator_id
        WHERE u.role IN ('reviewer', 'gestionnaire')
    ";
    $params = [];

    if ($filter !== 'all') {
        $sql .= " AND u.status = :filter";
        $params[':filter'] = $filter;
    }

    if ($search) {
        $sql .= " AND (u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search OR u.keywords LIKE :search)";
        $params[':search'] = "%$search%";
    }

    $sql .= " GROUP BY u.id ORDER BY u.last_name, u.first_name LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($key, $val, $type);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $evaluators = $stmt->fetchAll();

    foreach ($evaluators as &$eval) {
        $eval['specialties'] = $eval['specialties'] ? array_map('trim', explode(',', $eval['specialties'])) : [];
        $eval['assignedArticles'] = getEvaluatorArticles($pdo, $eval['id']);
    }

    $countSql = "SELECT COUNT(*) FROM users WHERE role IN ('reviewer', 'gestionnaire')";
    $countParams = [];
    if ($filter !== 'all') {
        $countSql .= " AND status = :filter";
        $countParams[':filter'] = $filter;
    }
    if ($search) {
        $countSql .= " AND (first_name LIKE :search OR last_name LIKE :search OR email LIKE :search OR keywords LIKE :search)";
        $countParams[':search'] = "%$search%";
    }
    $countStmt = $pdo->prepare($countSql);
    foreach ($countParams as $key => $val) {
        $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $countStmt->bindValue($key, $val, $type);
    }
    $countStmt->execute();
    $total = $countStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'data' => $evaluators,
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => ceil($total / $perPage)
        ]
    ]);
}

function getEvaluatorArticles($pdo, $evaluatorId) {
    $stmt = $pdo->prepare("
        SELECT 
            a.id,
            a.title,
            a.author,
            t.name as topic,
            a.status,
            a.submission_date,
            c.name_fr as conference_name,
            COUNT(ar2.evaluator_id) as reviewer_count
        FROM articles a
        JOIN article_reviewers ar ON a.id = ar.article_id
        LEFT JOIN topics t ON a.topic_id = t.id
        LEFT JOIN conferences c ON a.conference_id = c.id
        LEFT JOIN article_reviewers ar2 ON a.id = ar2.article_id
        WHERE ar.evaluator_id = :eval_id
        GROUP BY a.id
        ORDER BY a.submission_date DESC
    ");
    $stmt->execute([':eval_id' => $evaluatorId]);
    return $stmt->fetchAll();
}

function addEvaluator($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);

    $firstName = trim($data['first_name'] ?? '');
    $lastName = trim($data['last_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $institution = trim($data['institution'] ?? '');
    $grade = trim($data['grade'] ?? '');
    $labo = trim($data['labo'] ?? '');
    $keywords = trim($data['keywords'] ?? '');
    $password = $data['password'] ?? bin2hex(random_bytes(4));

    if (!$firstName || !$lastName || !$email) {
        http_response_code(400);
        echo json_encode(['error' => 'First name, last name and email are required']);
        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email format']);
        return;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Email already exists']);
        return;
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("
        INSERT INTO users 
        (first_name, last_name, email, password, role, status, institution, grade, labo, keywords, reviewer_code)
        VALUES (:first_name, :last_name, :email, :password, 'reviewer', 'active', :institution, :grade, :labo, :keywords, :reviewer_code)
    ");

    $reviewerCode = 'REV-' . strtoupper(substr(md5(uniqid()), 0, 8));

    $stmt->execute([
        ':first_name' => $firstName,
        ':last_name' => $lastName,
        ':email' => $email,
        ':password' => $passwordHash,
        ':institution' => $institution,
        ':grade' => $grade,
        ':labo' => $labo,
        ':keywords' => $keywords,
        ':reviewer_code' => $reviewerCode
    ]);

    $evaluatorId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Evaluator added successfully',
        'id' => $evaluatorId,
        'reviewer_code' => $reviewerCode,
        'temp_password' => $password
    ]);
}

function getArticles($pdo) {
    $evaluatorId = (int)($_GET['evaluator_id'] ?? 0);
    $conference = $_GET['conference'] ?? 'all';
    $topic = $_GET['topic'] ?? 'all';

    $sql = "
        SELECT 
            a.id,
            a.title,
            a.author,
            a.author_institution,
            a.submission_date,
            a.status,
            t.id as topic_id,
            t.name as topic,
            c.id as conference_id,
            c.name_fr as conference_name,
            COUNT(DISTINCT ar.evaluator_id) as reviewer_count,
            EXISTS(
                SELECT 1 FROM article_reviewers 
                WHERE article_id = a.id AND evaluator_id = :eval_id
            ) as is_assigned
        FROM articles a
        LEFT JOIN topics t ON a.topic_id = t.id
        LEFT JOIN conferences c ON a.conference_id = c.id
        LEFT JOIN article_reviewers ar ON a.id = ar.article_id
        WHERE a.status NOT IN ('accepted', 'rejected')
    ";
    $params = [':eval_id' => $evaluatorId];

    if ($conference !== 'all') {
        $sql .= " AND a.conference_id = :conf_id";
        $params[':conf_id'] = (int)$conference;
    }

    if ($topic !== 'all') {
        $sql .= " AND a.topic_id = :topic_id";
        $params[':topic_id'] = (int)$topic;
    }

    $sql .= " GROUP BY a.id HAVING reviewer_count < 2 ORDER BY a.submission_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $articles = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $articles]);
}

function assignArticles($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $evaluatorId = (int)($data['evaluator_id'] ?? 0);
    $articleIds = $data['article_ids'] ?? [];

    if (!$evaluatorId || empty($articleIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'Evaluator ID and article IDs are required']);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, status FROM users WHERE id = :id AND role IN ('reviewer', 'gestionnaire')");
    $stmt->execute([':id' => $evaluatorId]);
    $evaluator = $stmt->fetch();

    if (!$evaluator) {
        http_response_code(404);
        echo json_encode(['error' => 'Evaluator not found']);
        return;
    }

    if ($evaluator['status'] !== 'active') {
        http_response_code(400);
        echo json_encode(['error' => 'Evaluator must be active to receive assignments']);
        return;
    }

    $pdo->beginTransaction();

    try {
        $assigned = [];
        $skipped = [];

        foreach ($articleIds as $articleId) {
            $articleId = (int)$articleId;

            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM article_reviewers WHERE article_id = :art_id");
            $stmt->execute([':art_id' => $articleId]);
            $count = $stmt->fetch()['count'];

            if ($count >= 2) {
                $stmt = $pdo->prepare("SELECT title FROM articles WHERE id = :id");
                $stmt->execute([':id' => $articleId]);
                $skipped[] = $stmt->fetch()['title'];
                continue;
            }

            $stmt = $pdo->prepare("
                SELECT 1 FROM article_reviewers 
                WHERE article_id = :art_id AND evaluator_id = :eval_id
            ");
            $stmt->execute([':art_id' => $articleId, ':eval_id' => $evaluatorId]);
            if ($stmt->fetch()) {
                continue;
            }

            $stmt = $pdo->prepare("
                INSERT INTO article_reviewers (article_id, evaluator_id, assigned_at)
                VALUES (:art_id, :eval_id, NOW())
            ");
            $stmt->execute([':art_id' => $articleId, ':eval_id' => $evaluatorId]);

            $stmt = $pdo->prepare("
                UPDATE articles 
                SET status = CASE 
                    WHEN status = 'new' THEN 'assigned'
                    ELSE status 
                END
                WHERE id = :id
            ");
            $stmt->execute([':id' => $articleId]);

            $assigned[] = $articleId;
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'assigned' => count($assigned),
            'skipped' => $skipped,
            'message' => count($assigned) . ' article(s) assigned successfully'
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function getStats($pdo) {
    $stats = [];
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role IN ('reviewer', 'gestionnaire')");
    $stats['total'] = $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) as active FROM users WHERE role IN ('reviewer', 'gestionnaire') AND status = 'active'");
    $stats['active'] = $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) as pending FROM users WHERE role IN ('reviewer', 'gestionnaire') AND status = 'pending'");
    $stats['pending'] = $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) as blocked FROM users WHERE role IN ('reviewer', 'gestionnaire') AND status = 'blocked'");
    $stats['blocked'] = $stmt->fetchColumn();
    echo json_encode(['success' => true, 'data' => $stats]);
}

function getTopics($pdo) {
    $stmt = $pdo->query("SELECT id, name FROM topics ORDER BY name");
    $topics = $stmt->fetchAll();
    echo json_encode(['success' => true, 'data' => $topics]);
}

function getConferences($pdo) {
    $stmt = $pdo->query("SELECT id, name_fr as name FROM conferences ORDER BY name_fr");
    $conferences = $stmt->fetchAll();
    echo json_encode(['success' => true, 'data' => $conferences]);
}
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
    --navy: #0d2137; --navy-mid: #1a3a5c; --gold: #c9a84c; --gold-light: #e2c97e;
    --bg: #f0f2f5; --white: #ffffff; --border: #e2e8f0; --muted: #7a8fa6;
    --text: #1a2e44; --text-light: #4a607a; --accent: #2c6fad; --danger: #d94040;
    --success: #2a9d8f; --warning: #d4830a; --purple: #5b6ef5;
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
  .page { max-width: 1400px; margin: 0 auto; padding: 40px 32px 60px; }
  .page-header { margin-bottom: 28px; }
  .page-header-row { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 6px; }
  .page-header h1 { font-family: 'Libre Baskerville', serif; font-size: 32px; font-weight: 700; color: var(--navy); letter-spacing: -0.5px; }
  .page-header h1 em { font-style: italic; color: var(--gold); }
  .page-header p { font-size: 14px; color: var(--muted); margin-top: 4px; }
  .filters-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
  .filter-tabs { display: flex; gap: 4px; background: var(--white); border: 1px solid var(--border); border-radius: 30px; padding: 4px; }
  .filter-tab { padding: 8px 20px; font-size: 13px; font-weight: 500; color: var(--muted); background: none; border: none; border-radius: 30px; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all 0.15s; }
  .filter-tab:hover { color: var(--navy); background: var(--bg); }
  .filter-tab.active { background: var(--navy); color: var(--gold-light); }
  .filter-count { font-size: 11px; background: rgba(255,255,255,0.2); padding: 1px 6px; border-radius: 12px; margin-left: 6px; }
  .filter-tab:not(.active) .filter-count { background: var(--bg); color: var(--muted); }
  .table-wrapper { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); overflow-x: auto; margin-bottom: 32px; }
  .data-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 900px; }
  .data-table th { text-align: left; padding: 14px 18px; background: var(--bg); font-weight: 600; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; border-bottom: 1px solid var(--border); }
  .data-table td { padding: 14px 18px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
  .data-table tr:hover { background: #fafbfd; }
  .data-table tr:last-child td { border-bottom: none; }
  .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
  .badge-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
  .badge.active { background: #e8f6f3; color: #1a5f57; border: 1px solid #9dd8d0; }
  .badge.active .badge-dot { background: var(--success); }
  .badge.pending { background: #fef8ec; color: #9a5f00; border: 1px solid #f5d98a; }
  .badge.pending .badge-dot { background: var(--warning); }
  .badge.blocked { background: #f0f4ff; color: #3347bb; border: 1px solid #bcc7fa; }
  .badge.blocked .badge-dot { background: var(--purple); }
  .btn-primary { background: var(--navy); color: var(--gold-light); border: none; border-radius: var(--radius-sm); padding: 10px 22px; font-size: 13.5px; font-weight: 500; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px; }
  .btn-primary:hover { background: var(--navy-mid); transform: translateY(-1px); box-shadow: var(--shadow); }
  .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
  .btn-secondary { background: none; color: var(--text-light); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 9px 18px; font-size: 13px; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s; }
  .btn-secondary:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }
  .action-btn { width: 32px; height: 32px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--white); color: var(--muted); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; transition: all 0.15s; margin: 0 2px; }
  .action-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }
  .modal-backdrop { position: fixed; inset: 0; background: rgba(13,33,55,0.5); backdrop-filter: blur(4px); z-index: 200; display: none; align-items: center; justify-content: center; }
  .modal-backdrop.open { display: flex; }
  .modal { background: var(--white); border-radius: var(--radius); width: 100%; max-width: 600px; box-shadow: var(--shadow-md); animation: fadeUp 0.2s ease; max-height: 90vh; overflow-y: auto; }
  .modal.large { max-width: 1000px; }
  .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
  .modal-title { font-family: 'Libre Baskerville', serif; font-size: 18px; color: var(--navy); }
  .modal-close { width: 30px; height: 30px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: none; color: var(--muted); cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; }
  .modal-close:hover { background: var(--bg); color: var(--navy); }
  .modal-body { padding: 24px; }
  .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; }
  .form-group { margin-bottom: 20px; }
  .field-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); display: block; margin-bottom: 6px; }
  .field-required { color: var(--danger); margin-left: 3px; }
  .form-input, .form-select, .form-textarea { width: 100%; padding: 11px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-family: 'DM Sans', sans-serif; font-size: 14px; color: var(--text); background: var(--white); transition: all 0.15s; outline: none; }
  .form-input:focus, .form-select:focus, .form-textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(44,111,173,0.12); }
  .form-textarea { resize: vertical; min-height: 80px; }
  .notice-box { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px 16px; font-size: 12.5px; color: var(--muted); line-height: 1.6; display: flex; gap: 10px; align-items: flex-start; }
  .assign-list { max-height: 400px; overflow-y: auto; }
  .assign-item { display: flex; align-items: center; padding: 12px; border-bottom: 1px solid var(--border); gap: 12px; }
  .assign-item:hover { background: var(--bg); }
  .assign-checkbox { width: 20px; height: 20px; cursor: pointer; }
  .assign-info { flex: 1; }
  .assign-title { font-weight: 600; font-size: 13px; color: var(--navy); }
  .assign-meta { font-size: 11px; color: var(--muted); margin-top: 2px; }
  .assign-topic { background: var(--bg); padding: 2px 8px; border-radius: 4px; font-size: 10px; color: var(--text-light); }
  .reviewer-count { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
  .reviewer-count.complete { background: #e8f6f3; color: var(--success); }
  .reviewer-count.incomplete { background: #fef8ec; color: var(--warning); }
  .reviewer-count.full { background: #fdf2f2; color: var(--danger); }
  .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
  .page-btn { padding: 8px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--white); color: var(--text-light); cursor: pointer; font-size: 13px; }
  .page-btn:hover { border-color: var(--navy); color: var(--navy); }
  .page-btn.active { background: var(--navy); color: var(--gold-light); }
  .page-btn:disabled { opacity: 0.5; cursor: not-allowed; }
  .footer { background: var(--navy); color: rgba(255,255,255,0.45); text-align: center; padding: 22px; margin-top: 48px; font-size: 13px; }
  .loading-overlay { position: fixed; inset: 0; background: rgba(255,255,255,0.8); display: none; align-items: center; justify-content: center; z-index: 300; }
  .loading-overlay.open { display: flex; }
  .spinner { width: 40px; height: 40px; border: 3px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: spin 0.8s linear infinite; }
  .toast { position: fixed; bottom: 24px; right: 24px; padding: 14px 24px; border-radius: var(--radius); color: white; font-weight: 500; z-index: 400; transform: translateY(100px); opacity: 0; transition: all 0.3s ease; box-shadow: var(--shadow-md); }
  .toast.show { transform: translateY(0); opacity: 1; }
  .toast.success { background: var(--success); }
  .toast.error { background: var(--danger); }
  .toast.warning { background: var(--warning); }
  .debug-info { background: #fff3cd; border: 1px solid #ffc107; border-radius: var(--radius-sm); padding: 12px 16px; margin-bottom: 20px; font-size: 12px; color: #856404; }
  @keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }
  @keyframes spin { to { transform: rotate(360deg); } }
  @media (max-width: 900px) { .topbar { padding: 0 16px; } .nav-links { display: none; } .page { padding: 24px 16px; } }
</style>
</head>
<body>

<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<header class="topbar">
  <a class="brand" href="#"><div class="brand-icon">📋</div><span class="brand-name">ConfManager</span></a>
  <nav class="nav-links">
    <a href="conferences.php" class="nav-link">Conférences & Articles</a>
    <a href="evaluators.php" class="nav-link active">Évaluateurs</a>
    <a href="final_decisions.html" class="nav-link">Décision Finale</a>
    <a href="profile.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" class="search-input" id="searchEvaluator" placeholder="Rechercher un évaluateur..."></div>
    <a href="logout.php" class="logout-btn"><span>↪</span> Déconnexion</a>
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

  <!-- Debug info panel - remove after testing -->
  <div class="debug-info" id="debugPanel" style="display: none;">
    <strong><i class="fas fa-bug"></i> Debug:</strong> <span id="debugText"></span>
  </div>

  <div class="filters-bar">
    <div class="filter-tabs">
      <button class="filter-tab active" data-filter="all">Tous <span class="filter-count" id="filterAllCount">0</span></button>
      <button class="filter-tab" data-filter="active">Actifs <span class="filter-count" id="filterActiveCount">0</span></button>
      <button class="filter-tab" data-filter="pending">En attente <span class="filter-count" id="filterPendingCount">0</span></button>
      <button class="filter-tab" data-filter="blocked">Bloqués <span class="filter-count" id="filterBlockedCount">0</span></button>
    </div>
  </div>

  <div class="table-wrapper">
    <table class="data-table">
      <thead><tr><th>Évaluateur</th><th>Contact</th><th>Spécialités</th><th>Statut</th><th>Articles assignés</th><th>Action</th></tr></thead>
      <tbody id="evaluatorTableBody"></tbody>
    </table>
  </div>

  <div class="pagination" id="pagination"></div>
</main>

<footer class="footer">© 2026 ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés</footer>

<!-- MODAL AJOUT -->
<div id="evaluatorModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="modalTitle">Ajouter un évaluateur</div>
      <button class="modal-close" onclick="closeEvaluatorModal()" aria-label="Fermer"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form id="evaluatorForm">
        <div class="form-group"><label class="field-label">Prénom <span class="field-required">*</span></label><input type="text" class="form-input" id="evalFirstName" placeholder="Prénom" aria-required="true"></div>
        <div class="form-group"><label class="field-label">Nom <span class="field-required">*</span></label><input type="text" class="form-input" id="evalLastName" placeholder="Nom" aria-required="true"></div>
        <div class="form-group"><label class="field-label">Adresse e-mail <span class="field-required">*</span></label><input type="email" class="form-input" id="evalEmail" placeholder="prenom.nom@universite.dz" aria-required="true"></div>
        <div class="form-group"><label class="field-label">Institution</label><input type="text" class="form-input" id="evalInstitution" placeholder="Université / Centre de recherche"></div>
        <div class="form-group"><label class="field-label">Grade</label><input type="text" class="form-input" id="evalGrade" placeholder="ex: Professeur, Doctorant, MCF"></div>
        <div class="form-group"><label class="field-label">Laboratoire</label><input type="text" class="form-input" id="evalLabo" placeholder="ex: LISIA, LIRE"></div>
        <div class="form-group"><label class="field-label">Spécialités (séparées par des virgules)</label><input type="text" class="form-input" id="evalSpecialties" placeholder="ex: IA, NLP, Machine Learning"></div>
        <div class="notice-box"><i class="fas fa-info-circle"></i><span>Un email d'invitation sera envoyé à l'évaluateur pour l'informer de son rôle et lui donner accès à la plateforme.</span></div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeEvaluatorModal()">Annuler</button>
      <button class="btn-primary" id="saveEvaluatorBtn"><i class="fas fa-save"></i> Enregistrer et inviter</button>
    </div>
  </div>
</div>

<!-- MODAL ASSIGN -->
<div id="assignModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="assignModalTitle">
  <div class="modal large">
    <div class="modal-header">
      <div class="modal-title">Assigner des articles à <span id="assignEvalName"></span></div>
      <button class="modal-close" onclick="closeAssignModal()" aria-label="Fermer"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div style="margin-bottom: 16px; display: flex; gap: 12px; align-items: center;">
        <select class="form-select" id="filterConference" style="flex: 1;"><option value="all">Toutes les conférences</option></select>
        <select class="form-select" id="filterTopic" style="flex: 1;"><option value="all">Tous les topics</option></select>
      </div>
      <div class="notice-box" style="background: #e0e7ff; border-color: #c7d2fe; margin-bottom: 16px;"><i class="fas fa-info-circle" style="color: var(--purple);"></i><span><strong>Règle:</strong> Chaque article doit avoir exactement <strong>2 évaluateurs</strong>. Les articles ayant déjà 2 évaluateurs seront masqués.</span></div>
      <div class="assign-list" id="assignList"></div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeAssignModal()">Annuler</button>
      <button class="btn-primary" id="confirmAssignBtn"><i class="fas fa-check"></i> Assigner les articles sélectionnés</button>
    </div>
  </div>
</div>

<script>
  let currentFilter = 'all', currentPage = 1, itemsPerPage = 10;
  let currentEvaluatorId = null, assignFilterConference = 'all', assignFilterTopic = 'all';

  function showLoading(show) { document.getElementById('loadingOverlay').classList.toggle('open', show); }
  function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    toast.textContent = message; toast.className = 'toast ' + type;
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => toast.classList.remove('show'), 3000);
  }
  function showDebug(info) {
    const panel = document.getElementById('debugPanel');
    document.getElementById('debugText').textContent = JSON.stringify(info);
    panel.style.display = 'block';
  }
  async function apiGet(action, params = {}) {
    const query = new URLSearchParams({ action, ...params });
    const res = await fetch(`evaluators.php?${query}`); return res.json();
  }
  async function apiPost(action, data) {
    const res = await fetch(`evaluators.php?action=${action}`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data)
    }); return res.json();
  }
  function getStatusBadge(status) {
    const badges = {
      active: '<span class="badge active"><span class="badge-dot"></span>Actif</span>',
      pending: '<span class="badge pending"><span class="badge-dot"></span>En attente</span>',
      blocked: '<span class="badge blocked"><span class="badge-dot"></span>Bloqué</span>'
    }; return badges[status] || badges.blocked;
  }
  function getReviewerCountBadge(count) {
    if (count >= 2) return `<span class="reviewer-count full"><i class="fas fa-check-circle"></i> Complet (${count}/2)</span>`;
    if (count === 1) return `<span class="reviewer-count incomplete"><i class="fas fa-user-clock"></i> 1/2 évaluateurs</span>`;
    return `<span class="reviewer-count incomplete"><i class="fas fa-user-plus"></i> 0/2 évaluateurs</span>`;
  }
  function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }

  async function checkSession() {
    try {
      const res = await apiGet('me');
      if (res.success) {
        console.log('Session OK:', res);
        showDebug({ session: 'OK', user: res.email, role: res.role });
      } else {
        console.error('Session error:', res);
        showDebug({ session: 'ERROR', error: res.error });
      }
    } catch (e) {
      console.error('Network error:', e);
      showDebug({ session: 'NETWORK ERROR', error: e.message });
    }
  }

  async function loadStats() {
    try { const res = await apiGet('stats'); if (res.success) {
      document.getElementById('filterAllCount').textContent = res.data.total;
      document.getElementById('filterActiveCount').textContent = res.data.active;
      document.getElementById('filterPendingCount').textContent = res.data.pending;
      document.getElementById('filterBlockedCount').textContent = res.data.blocked;
    }} catch (e) { console.error('Error loading stats:', e); }
  }

  async function loadEvaluators() {
    showLoading(true);
    try {
      const search = document.getElementById('searchEvaluator').value;
      const res = await apiGet('list', { filter: currentFilter, search: search, page: currentPage });
      if (!res.success) {
        if (res.error && res.error.includes('Forbidden')) {
          showDebug({ error: res.error, your_role: res.your_role, required: res.required, user_id: res.user_id });
        }
        throw new Error(res.error);
      }
      renderTable(res.data, res.pagination);
    } catch (e) {
      document.getElementById('evaluatorTableBody').innerHTML = 
        `<tr><td colspan="6" style="text-align:center; padding:40px; color: var(--danger);"><i class="fas fa-exclamation-triangle" style="font-size: 24px; display: block; margin-bottom: 8px;"></i>Erreur: ${escapeHtml(e.message)}</td></tr>`;
    } finally { showLoading(false); }
  }

  function renderTable(evaluators, pagination) {
    const tbody = document.getElementById('evaluatorTableBody');
    if (!evaluators || evaluators.length === 0) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:40px;"><i class="fas fa-search" style="font-size: 32px; color: var(--muted); margin-bottom: 12px; display: block;"></i>Aucun évaluateur trouvé</td></tr>';
      renderPagination({ totalPages: 1, page: 1 }); return;
    }
    let html = '';
    evaluators.forEach(eval_ => {
      let articlesHtml = '';
      if (eval_.assignedArticles && eval_.assignedArticles.length > 0) {
        articlesHtml = '<div style="display: flex; flex-direction: column; gap: 6px;">';
        eval_.assignedArticles.forEach(article => {
          articlesHtml += `<div style="font-size: 12px; padding: 4px 8px; background: var(--bg); border-radius: 4px;"><div style="font-weight: 500; color: var(--navy);">${escapeHtml(article.title.substring(0, 40))}${article.title.length > 40 ? '...' : ''}</div><div style="margin-top: 2px;">${getReviewerCountBadge(article.reviewer_count)}</div></div>`;
        }); articlesHtml += '</div>';
      } else { articlesHtml = '<span style="color: var(--muted); font-size: 12px;">Aucun article assigné</span>'; }
      const specs = Array.isArray(eval_.specialties) ? eval_.specialties : [];
      html += `<tr><td><strong>${escapeHtml(eval_.name)}</strong><br><span style="font-size:11px; color:var(--muted);">${escapeHtml(eval_.institution || '')}</span></td><td style="font-size:12px;">${escapeHtml(eval_.email)}</td><td>${specs.map(s => `<span class="badge" style="margin: 2px; background: var(--bg); color: var(--text-light); border: none;">${escapeHtml(s)}</span>`).join('')}</td><td>${getStatusBadge(eval_.status)}</td><td>${articlesHtml}</td><td>${eval_.status === 'active' ? `<button class="action-btn" onclick="assignArticles(${eval_.id}, '${escapeHtml(eval_.name)}')" title="Assigner des articles"><i class="fas fa-tasks"></i></button>` : `<span style="font-size: 11px; color: var(--muted);">Non disponible</span>`}</td></tr>`;
    });
    tbody.innerHTML = html;
    renderPagination(pagination);
  }

  function renderPagination(pagination) {
    const div = document.getElementById('pagination');
    const totalPages = pagination.totalPages;
    if (totalPages <= 1) { div.innerHTML = ''; return; }
    let html = `<button class="page-btn" onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}><i class="fas fa-chevron-left"></i></button>`;
    for (let i = 1; i <= totalPages; i++) {
      if (i === 1 || i === totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
        html += `<button class="page-btn ${currentPage === i ? 'active' : ''}" onclick="changePage(${i})">${i}</button>`;
      } else if (i === currentPage - 2 || i === currentPage + 2) { html += `<span style="padding:8px;">...</span>`; }
    }
    html += `<button class="page-btn" onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}><i class="fas fa-chevron-right"></i></button>`;
    div.innerHTML = html;
  }

  function openEvaluatorModal() { document.getElementById('evaluatorForm').reset(); document.getElementById('evaluatorModal').classList.add('open'); }
  function closeEvaluatorModal() { document.getElementById('evaluatorModal').classList.remove('open'); }

  async function saveEvaluator() {
    const firstName = document.getElementById('evalFirstName').value.trim();
    const lastName = document.getElementById('evalLastName').value.trim();
    const email = document.getElementById('evalEmail').value.trim();
    const institution = document.getElementById('evalInstitution').value.trim();
    const grade = document.getElementById('evalGrade').value.trim();
    const labo = document.getElementById('evalLabo').value.trim();
    const keywords = document.getElementById('evalSpecialties').value;
    if (!firstName || !lastName || !email) { showToast('Veuillez remplir tous les champs obligatoires.', 'error'); return; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showToast('Email invalide.', 'error'); return; }
    showLoading(true);
    try {
      const res = await apiPost('add', { first_name: firstName, last_name: lastName, email, institution, grade, labo, keywords });
      if (res.success) { showToast('Évaluateur ajouté avec succès !'); closeEvaluatorModal(); await loadStats(); await loadEvaluators(); }
      else {
        if (res.error && res.error.includes('Forbidden')) {
          showDebug({ error: res.error, your_role: res.your_role, required: res.required, user_id: res.user_id });
        }
        showToast(res.error || 'Erreur', 'error');
      }
    } catch (e) { showToast('Erreur réseau: ' + e.message, 'error'); }
    finally { showLoading(false); }
  }

  async function loadTopics() {
    try { const res = await apiGet('topics'); if (res.success) {
      const sel = document.getElementById('filterTopic');
      sel.innerHTML = '<option value="all">Tous les topics</option>';
      res.data.forEach(t => { sel.innerHTML += `<option value="${t.id}">${escapeHtml(t.name)}</option>`; });
    }} catch (e) { console.error('Error loading topics:', e); }
  }
  async function loadConferences() {
    try { const res = await apiGet('conferences'); if (res.success) {
      const sel = document.getElementById('filterConference');
      sel.innerHTML = '<option value="all">Toutes les conférences</option>';
      res.data.forEach(c => { sel.innerHTML += `<option value="${c.id}">${escapeHtml(c.name)}</option>`; });
    }} catch (e) { console.error('Error loading conferences:', e); }
  }

  async function assignArticles(id, name) {
    currentEvaluatorId = id; document.getElementById('assignEvalName').textContent = name;
    await loadTopics(); await loadConferences();
    assignFilterConference = 'all'; assignFilterTopic = 'all';
    document.getElementById('filterConference').value = 'all';
    document.getElementById('filterTopic').value = 'all';
    await renderAssignmentList();
    document.getElementById('assignModal').classList.add('open');
  }

  async function renderAssignmentList() {
    if (!currentEvaluatorId) return;
    showLoading(true);
    try {
      const res = await apiGet('articles', { evaluator_id: currentEvaluatorId, conference: assignFilterConference, topic: assignFilterTopic });
      if (!res.success) throw new Error(res.error);
      const articles = res.data; let html = '';
      if (articles.length === 0) {
        html = `<div style="text-align: center; padding: 40px; color: var(--muted);"><i class="fas fa-check-circle" style="font-size: 48px; color: var(--success); margin-bottom: 16px; display: block;"></i>Aucun article disponible.<br><small>Tous les articles ont déjà leurs 2 évaluateurs ou ne correspondent pas aux filtres.</small></div>`;
      } else {
        articles.forEach(article => {
          const isAssigned = article.is_assigned;
          const reviewerCount = article.reviewer_count || 0;
          let statusBadge = '';
          if (isAssigned) statusBadge = '<span style="color: var(--success); font-size: 11px;"><i class="fas fa-check"></i> Déjà assigné à vous</span>';
          else if (reviewerCount === 1) statusBadge = '<span style="color: var(--warning); font-size: 11px;"><i class="fas fa-exclamation-circle"></i> Il manque 1 évaluateur</span>';
          else statusBadge = '<span style="color: var(--purple); font-size: 11px;"><i class="fas fa-user-plus"></i> Aucun évaluateur</span>';
          html += `<div class="assign-item" style="${isAssigned ? 'opacity: 0.7;' : ''}"><input type="checkbox" class="assign-checkbox" data-article-id="${article.id}" ${isAssigned ? 'checked disabled' : ''}><div class="assign-info"><div class="assign-title">${escapeHtml(article.title)}</div><div class="assign-meta"><i class="fas fa-user"></i> ${escapeHtml(article.author)} · <i class="fas fa-calendar"></i> ${new Date(article.submission_date).toLocaleDateString('fr-FR')} · <span class="assign-topic">${escapeHtml(article.topic || 'Non classé')}</span></div><div style="margin-top: 4px; font-size: 11px; color: var(--muted);"><i class="fas fa-folder"></i> ${escapeHtml(article.conference_name || '')} · <span style="color: ${reviewerCount === 0 ? 'var(--purple)' : (reviewerCount === 1 ? 'var(--warning)' : 'var(--success)')}; font-weight: 600;">${reviewerCount}/2 évaluateurs</span></div></div><div style="text-align: right; min-width: 140px;">${statusBadge}</div></div>`;
        });
      }
      document.getElementById('assignList').innerHTML = html;
    } catch (e) {
      document.getElementById('assignList').innerHTML = `<div style="text-align: center; padding: 40px; color: var(--danger);"><i class="fas fa-exclamation-triangle" style="font-size: 32px; margin-bottom: 12px; display: block;"></i>Erreur: ${escapeHtml(e.message)}</div>`;
    } finally { showLoading(false); }
  }

  function closeAssignModal() { document.getElementById('assignModal').classList.remove('open'); currentEvaluatorId = null; assignFilterConference = 'all'; assignFilterTopic = 'all'; }

  async function confirmAssign() {
    if (!currentEvaluatorId) return;
    const selectedIds = [];
    document.querySelectorAll('.assign-checkbox:checked:not(:disabled)').forEach(cb => selectedIds.push(parseInt(cb.dataset.articleId)));
    if (selectedIds.length === 0) { showToast('Veuillez sélectionner au moins un article.', 'warning'); return; }
    showLoading(true);
    try {
      const res = await apiPost('assign', { evaluator_id: currentEvaluatorId, article_ids: selectedIds });
      if (res.success) {
        let msg = `${res.assigned} article(s) assigné(s).`;
        if (res.skipped && res.skipped.length > 0) msg += ` ${res.skipped.length} ignoré(s).`;
        showToast(msg); closeAssignModal(); await loadEvaluators();
      } else { showToast(res.error || 'Erreur', 'error'); }
    } catch (e) { showToast('Erreur réseau: ' + e.message, 'error'); }
    finally { showLoading(false); }
  }

  document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active'); currentFilter = tab.dataset.filter; currentPage = 1; loadEvaluators();
    });
  });
  document.getElementById('openAddEvaluatorModal').addEventListener('click', openEvaluatorModal);
  document.getElementById('saveEvaluatorBtn').addEventListener('click', saveEvaluator);
  document.getElementById('confirmAssignBtn').addEventListener('click', confirmAssign);
  document.getElementById('filterConference').addEventListener('change', (e) => { assignFilterConference = e.target.value; renderAssignmentList(); });
  document.getElementById('filterTopic').addEventListener('change', (e) => { assignFilterTopic = e.target.value; renderAssignmentList(); });
  document.getElementById('searchEvaluator').addEventListener('input', debounce(() => { currentPage = 1; loadEvaluators(); }, 300));
  document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) backdrop.classList.remove('open'); });
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeEvaluatorModal(); closeAssignModal(); } });
  function debounce(func, wait) { let timeout; return function(...args) { clearTimeout(timeout); timeout = setTimeout(() => func.apply(this, args), wait); }; }

  window.assignArticles = assignArticles;
  window.changePage = function(page) { currentPage = page; loadEvaluators(); };
  window.closeEvaluatorModal = closeEvaluatorModal;
  window.closeAssignModal = closeAssignModal;
  window.confirmAssign = confirmAssign;

  // Check session on load and load data
  checkSession();
  loadStats();
  loadEvaluators();
</script>
</body>
</html>