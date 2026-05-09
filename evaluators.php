<?php
/**
 * evaluators.php - Backend API for ConfManager Evaluator Management
 * 
 * Endpoints:
 *   GET  ?action=stats              - Get evaluator counts by status
 *   GET  ?action=list             - List evaluators with pagination/filter
 *   GET  ?action=topics           - List all topics
 *   GET  ?action=conferences      - List all conferences
 *   GET  ?action=articles         - List available articles for assignment
 *   POST ?action=add               - Add new evaluator
 *   POST ?action=assign            - Assign articles to evaluator
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database configuration - UPDATE THESE VALUES
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'confmanager');
define('DB_USER', 'root');        // Change to your DB user
define('DB_PASS', '');            // Change to your DB password
define('DB_CHARSET', 'utf8mb4');

// Response helper
function jsonResponse($success, $data = null, $error = null) {
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'error' => $error
    ]);
    exit;
}

// Database connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    jsonResponse(false, null, 'Database connection failed: ' . $e->getMessage());
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================================
// GET STATS
// ============================================================================
if ($action === 'stats' && $method === 'GET') {
    try {
        // Total reviewers
        $total = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'reviewer'")->fetchColumn();
        
        // Active reviewers
        $active = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'reviewer' AND status = 'active'")->fetchColumn();
        
        // Pending reviewers
        $pending = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'reviewer' AND status = 'pending'")->fetchColumn();
        
        // Blocked reviewers
        $blocked = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'reviewer' AND status = 'blocked'")->fetchColumn();
        
        jsonResponse(true, [
            'total' => (int)$total,
            'active' => (int)$active,
            'pending' => (int)$pending,
            'blocked' => (int)$blocked
        ]);
    } catch (PDOException $e) {
        jsonResponse(false, null, $e->getMessage());
    }
}

// ============================================================================
// LIST EVALUATORS
// ============================================================================
elseif ($action === 'list' && $method === 'GET') {
    $filter = $_GET['filter'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = 10;
    $offset = ($page - 1) * $perPage;
    
    try {
        $where = ["u.role = 'reviewer'"];
        $params = [];
        
        // Status filter
        if ($filter !== 'all' && in_array($filter, ['active', 'pending', 'blocked'])) {
            $where[] = "u.status = :status";
            $params[':status'] = $filter;
        }
        
        // Search filter
        if (!empty($search)) {
            $where[] = "(u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search OR u.institution LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Count total
        $countSql = "SELECT COUNT(*) FROM users u WHERE $whereClause";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = $countStmt->fetchColumn();
        $totalPages = ceil($totalItems / $perPage);
        
        // Fetch evaluators
        $sql = "SELECT 
                    u.id,
                    CONCAT(u.first_name, ' ', u.last_name) as name,
                    u.email,
                    u.institution,
                    u.grade,
                    u.labo,
                    u.keywords as specialties_raw,
                    u.status,
                    u.created_at
                FROM users u
                WHERE $whereClause
                ORDER BY u.created_at DESC
                LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $evaluators = $stmt->fetchAll();
        
        // Process each evaluator
        foreach ($evaluators as &$eval) {
            // Parse specialties from keywords (comma-separated)
            $eval['specialties'] = [];
            if (!empty($eval['specialties_raw'])) {
                $eval['specialties'] = array_map('trim', explode(',', $eval['specialties_raw']));
            }
            unset($eval['specialties_raw']);
            
            // Fetch assigned articles with reviewer counts
            $articleSql = "SELECT 
                                a.id,
                                a.title,
                                a.author,
                                a.submission_date,
                                a.status as article_status,
                                t.name as topic,
                                c.name_fr as conference_name,
                                (SELECT COUNT(*) FROM article_reviewers ar2 WHERE ar2.article_id = a.id) as reviewer_count
                            FROM article_reviewers ar
                            JOIN articles a ON ar.article_id = a.id
                            LEFT JOIN topics t ON a.topic_id = t.id
                            LEFT JOIN conferences c ON a.conference_id = c.id
                            WHERE ar.evaluator_id = :eval_id
                            ORDER BY ar.assigned_at DESC";
            
            $articleStmt = $pdo->prepare($articleSql);
            $articleStmt->execute([':eval_id' => $eval['id']]);
            $eval['assignedArticles'] = $articleStmt->fetchAll();
        }
        
        jsonResponse(true, $evaluators, null, [
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'totalItems' => (int)$totalItems,
                'totalPages' => $totalPages
            ]
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, null, $e->getMessage());
    }
}

// ============================================================================
// LIST TOPICS
// ============================================================================
elseif ($action === 'topics' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT id, name FROM topics ORDER BY name ASC");
        jsonResponse(true, $stmt->fetchAll());
    } catch (PDOException $e) {
        jsonResponse(false, null, $e->getMessage());
    }
}

// ============================================================================
// LIST CONFERENCES
// ============================================================================
elseif ($action === 'conferences' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT id, name_fr as name FROM conferences ORDER BY created_at DESC");
        jsonResponse(true, $stmt->fetchAll());
    } catch (PDOException $e) {
        jsonResponse(false, null, $e->getMessage());
    }
}

// ============================================================================
// LIST ARTICLES FOR ASSIGNMENT
// ============================================================================
elseif ($action === 'articles' && $method === 'GET') {
    $evaluatorId = intval($_GET['evaluator_id'] ?? 0);
    $conferenceFilter = $_GET['conference'] ?? 'all';
    $topicFilter = $_GET['topic'] ?? 'all';
    
    if ($evaluatorId <= 0) {
        jsonResponse(false, null, 'Invalid evaluator ID');
    }
    
    try {
        $where = ["a.status IN ('new', 'assigned', 'review')"];
        $params = [':eval_id' => $evaluatorId];
        
        // Conference filter
        if ($conferenceFilter !== 'all' && is_numeric($conferenceFilter)) {
            $where[] = "a.conference_id = :conf_id";
            $params[':conf_id'] = intval($conferenceFilter);
        }
        
        // Topic filter
        if ($topicFilter !== 'all' && is_numeric($topicFilter)) {
            $where[] = "a.topic_id = :topic_id";
            $params[':topic_id'] = intval($topicFilter);
        }
        
        // CRITICAL FIX: Only show articles with LESS than 2 reviewers
        // AND articles not already assigned to this evaluator
        $whereClause = implode(' AND ', $where);
        
        $sql = "SELECT 
                    a.id,
                    a.title,
                    a.author,
                    a.submission_date,
                    a.status,
                    t.name as topic,
                    c.name_fr as conference_name,
                    (SELECT COUNT(*) FROM article_reviewers ar2 WHERE ar2.article_id = a.id) as reviewer_count,
                    EXISTS(SELECT 1 FROM article_reviewers ar3 WHERE ar3.article_id = a.id AND ar3.evaluator_id = :eval_id) as is_assigned
                FROM articles a
                LEFT JOIN topics t ON a.topic_id = t.id
                LEFT JOIN conferences c ON a.conference_id = c.id
                WHERE $whereClause
                HAVING reviewer_count < 2 OR is_assigned = 1
                ORDER BY a.submission_date DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $articles = $stmt->fetchAll();
        
        // Convert boolean to proper types
        foreach ($articles as &$article) {
            $article['reviewer_count'] = (int)$article['reviewer_count'];
            $article['is_assigned'] = (bool)$article['is_assigned'];
            // Format date for frontend
            $article['submission_date'] = date('Y-m-d', strtotime($article['submission_date']));
        }
        
        jsonResponse(true, $articles);
        
    } catch (PDOException $e) {
        jsonResponse(false, null, $e->getMessage());
    }
}

// ============================================================================
// ADD EVALUATOR
// ============================================================================
elseif ($action === 'add' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(false, null, 'Invalid JSON input');
    }
    
    // Validation
    $firstName = trim($input['first_name'] ?? '');
    $lastName = trim($input['last_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $institution = trim($input['institution'] ?? '');
    $grade = trim($input['grade'] ?? '');
    $labo = trim($input['labo'] ?? '');
    $keywords = trim($input['keywords'] ?? '');
    
    if (empty($firstName) || empty($lastName) || empty($email)) {
        jsonResponse(false, null, 'First name, last name and email are required');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, null, 'Invalid email format');
    }
    
    try {
        // Check if email already exists
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $checkStmt->execute([':email' => $email]);
        if ($checkStmt->fetch()) {
            jsonResponse(false, null, 'An evaluator with this email already exists');
        }
        
        // Generate password hash (temporary password - evaluator will reset)
        $tempPassword = bin2hex(random_bytes(8));
        $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
        
        // Generate reviewer code
        $reviewerCode = 'REV' . strtoupper(substr(md5(uniqid()), 0, 6));
        
        // Insert new reviewer
        $sql = "INSERT INTO users 
                (first_name, last_name, email, password, role, status, institution, grade, labo, keywords, reviewer_code, created_at) 
                VALUES 
                (:first_name, :last_name, :email, :password, 'reviewer', 'active', :institution, :grade, :labo, :keywords, :reviewer_code, NOW())";
        
        $stmt = $pdo->prepare($sql);
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
        
        $newId = $pdo->lastInsertId();
        
        // TODO: Send invitation email here
        // mail($email, 'ConfManager - Invitation', "Your reviewer code: $reviewerCode\nTemp password: $tempPassword");
        
        jsonResponse(true, [
            'id' => $newId,
            'reviewer_code' => $reviewerCode,
            'message' => 'Evaluator added successfully'
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, null, $e->getMessage());
    }
}

// ============================================================================
// ASSIGN ARTICLES
// ============================================================================
elseif ($action === 'assign' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(false, null, 'Invalid JSON input');
    }
    
    $evaluatorId = intval($input['evaluator_id'] ?? 0);
    $articleIds = $input['article_ids'] ?? [];
    
    if ($evaluatorId <= 0 || empty($articleIds) || !is_array($articleIds)) {
        jsonResponse(false, null, 'Invalid evaluator ID or article IDs');
    }
    
    // Sanitize article IDs
    $articleIds = array_map('intval', $articleIds);
    $articleIds = array_filter($articleIds, fn($id) => $id > 0);
    
    if (empty($articleIds)) {
        jsonResponse(false, null, 'No valid article IDs provided');
    }
    
    try {
        $pdo->beginTransaction();
        
        $assigned = 0;
        $skipped = [];
        
        foreach ($articleIds as $articleId) {
            // Check if article exists and has less than 2 reviewers
            $checkStmt = $pdo->prepare("
                SELECT a.status,
                       (SELECT COUNT(*) FROM article_reviewers WHERE article_id = a.id) as reviewer_count
                FROM articles a
                WHERE a.id = :article_id
                FOR UPDATE
            ");
            $checkStmt->execute([':article_id' => $articleId]);
            $article = $checkStmt->fetch();
            
            if (!$article) {
                $skipped[] = ['id' => $articleId, 'reason' => 'Article not found'];
                continue;
            }
            
            if ($article['reviewer_count'] >= 2) {
                $skipped[] = ['id' => $articleId, 'reason' => 'Article already has 2 reviewers'];
                continue;
            }
            
            // Check if already assigned to this evaluator
            $dupStmt = $pdo->prepare("
                SELECT id FROM article_reviewers 
                WHERE article_id = :article_id AND evaluator_id = :eval_id
            ");
            $dupStmt->execute([
                ':article_id' => $articleId,
                ':eval_id' => $evaluatorId
            ]);
            
            if ($dupStmt->fetch()) {
                $skipped[] = ['id' => $articleId, 'reason' => 'Already assigned to this evaluator'];
                continue;
            }
            
            // Insert assignment
            $insertStmt = $pdo->prepare("
                INSERT INTO article_reviewers (article_id, evaluator_id, assigned_at)
                VALUES (:article_id, :eval_id, NOW())
            ");
            $insertStmt->execute([
                ':article_id' => $articleId,
                ':eval_id' => $evaluatorId
            ]);
            
            // Update article status if needed
            $newCount = $article['reviewer_count'] + 1;
            if ($newCount >= 2 && $article['status'] === 'new') {
                $updateStmt = $pdo->prepare("
                    UPDATE articles SET status = 'assigned' WHERE id = :article_id
                ");
                $updateStmt->execute([':article_id' => $articleId]);
            }
            
            $assigned++;
        }
        
        $pdo->commit();
        
        jsonResponse(true, [
            'assigned' => $assigned,
            'skipped' => $skipped,
            'message' => "$assigned article(s) assigned successfully"
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        jsonResponse(false, null, $e->getMessage());
    }
}

// ============================================================================
// UNKNOWN ACTION
// ============================================================================
else {
    jsonResponse(false, null, 'Unknown action or method: ' . $action);
}>
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
  @keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }
  @keyframes spin { to { transform: rotate(360deg); } }
  @media (max-width: 900px) { .topbar { padding: 0 16px; } .nav-links { display: none; } .page { padding: 24px 16px; } }
</style>
<base target="_blank">
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
      if (!res.success) throw new Error(res.error);
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
      else { showToast(res.error || 'Erreur', 'error'); }
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

  loadStats(); loadEvaluators();
</script>
</body>
</html>