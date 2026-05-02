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

// ==================== HELPER FUNCTIONS ====================
function generateUniqueUsername($firstName, $lastName) {
    $db = Database::getConnection();
    
    // Base username: firstname.lastname
    $base = strtolower(trim($firstName . '.' . $lastName));
    $base = preg_replace('/[^a-z0-9.]/', '', $base);
    $base = substr($base, 0, 40);
    
    $username = $base;
    $counter = 1;
    
    // Ensure uniqueness
    while (true) {
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if (!$stmt->fetch()) {
            break;
        }
        $username = $base . $counter;
        $counter++;
    }
    
    return $username;
}

// ==================== DATABASE FUNCTIONS ====================

// Get all reviewers (users with role 'reviewer')
function getAllReviewers() {
    $db = Database::getConnection();
    $stmt = $db->query("SELECT 
                        id, 
                        username,
                        first_name, 
                        last_name, 
                        email, 
                        institution, 
                        specialties,
                        status,
                        created_at
                        FROM users 
                        WHERE role = 'reviewer' 
                        ORDER BY created_at DESC");
    $reviewers = $stmt->fetchAll();
    
    // Get assigned articles count for each reviewer
    foreach ($reviewers as &$reviewer) {
        $stmt2 = $db->prepare("SELECT COUNT(*) as count FROM article_reviewers WHERE reviewer_id = ?");
        $stmt2->execute([$reviewer['id']]);
        $reviewer['assigned_articles_count'] = $stmt2->fetch()['count'];
        
        $stmt3 = $db->prepare("SELECT COUNT(*) as count FROM article_reviewers WHERE reviewer_id = ? AND status = 'completed'");
        $stmt3->execute([$reviewer['id']]);
        $reviewer['completed_reviews_count'] = $stmt3->fetch()['count'];
    }
    
    return $reviewers;
}

// Get reviewer by ID
function getReviewerById($id) {
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT 
                        id, 
                        first_name, 
                        last_name, 
                        email, 
                        institution, 
                        specialties,
                        status
                        FROM users 
                        WHERE id = ? AND role = 'reviewer'");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Create new reviewer (user with role 'reviewer')
function createReviewer($data) {
    $db = Database::getConnection();
    
    // Generate a unique username
    $username = generateUniqueUsername($data['first_name'], $data['last_name']);
    
    // Generate temporary password (will be reset by user)
    $tempPassword = bin2hex(random_bytes(8));
    $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
    
    $sql = "INSERT INTO users (username, first_name, last_name, email, institution, specialties, role, status, password, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'reviewer', 'invited', ?, NOW())";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $username,
        $data['first_name'],
        $data['last_name'],
        $data['email'],
        $data['institution'],
        $data['specialties'],
        $hashedPassword
    ]);
    
    $userId = $db->lastInsertId();
    
    // Create invitation token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    // Check if evaluator_invitations table exists, if not create it
    try {
        $createTable = "CREATE TABLE IF NOT EXISTS evaluator_invitations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) NOT NULL,
            token VARCHAR(255) NOT NULL,
            first_name VARCHAR(50),
            last_name VARCHAR(50),
            institution VARCHAR(255),
            specialties TEXT,
            status ENUM('pending', 'accepted', 'expired') DEFAULT 'pending',
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_email (email)
        )";
        $db->exec($createTable);
        
        $inviteSql = "INSERT INTO evaluator_invitations (email, token, first_name, last_name, institution, specialties, expires_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?)";
        $inviteStmt = $db->prepare($inviteSql);
        $inviteStmt->execute([
            $data['email'],
            $token,
            $data['first_name'],
            $data['last_name'],
            $data['institution'],
            $data['specialties'],
            $expiresAt
        ]);
    } catch (Exception $e) {
        // Table might already exist, continue
    }
    
    // In production, send email with invitation link
    $inviteLink = "http://" . $_SERVER['HTTP_HOST'] . "/accept_invitation.php?token=" . $token;
    
    return [
        'user_id' => $userId, 
        'username' => $username,
        'temp_password' => $tempPassword, 
        'invite_link' => $inviteLink
    ];
}

// Get all articles
function getAllArticles() {
    $db = Database::getConnection();
    
    // Check if article_reviewers table exists
    try {
        $createTable = "CREATE TABLE IF NOT EXISTS article_reviewers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            article_id INT NOT NULL,
            reviewer_id INT NOT NULL,
            assigned_date DATE NOT NULL,
            status ENUM('pending', 'accepted', 'completed', 'declined') DEFAULT 'pending',
            completed_date DATE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
            FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_assignment (article_id, reviewer_id),
            INDEX idx_article (article_id),
            INDEX idx_reviewer (reviewer_id)
        )";
        $db->exec($createTable);
    } catch (Exception $e) {
        // Table might already exist
    }
    
    $sql = "SELECT 
            a.id,
            a.titre_fr as title,
            a.conference_id as conferenceId,
            c.name_fr as conferenceName,
            u.first_name as author,
            a.domaine as domain,
            a.statut as status,
            a.date_soumission as submissionDate,
            (SELECT COUNT(*) FROM article_reviewers ar WHERE ar.article_id = a.id) as reviewerCount,
            (SELECT GROUP_CONCAT(reviewer_id) FROM article_reviewers ar WHERE ar.article_id = a.id) as reviewerIds
            FROM articles a
            JOIN users u ON a.utilisateur_id = u.id
            JOIN conferences c ON a.conference_id = c.id
            ORDER BY a.date_soumission DESC";
    
    $stmt = $db->query($sql);
    $articles = $stmt->fetchAll();
    
    // Parse reviewer IDs
    foreach ($articles as &$article) {
        if ($article['reviewerIds']) {
            $article['reviewers'] = explode(',', $article['reviewerIds']);
        } else {
            $article['reviewers'] = [];
        }
    }
    
    return $articles;
}

// Get assigned articles for a specific reviewer
function getReviewerAssignedArticles($reviewerId) {
    $db = Database::getConnection();
    $sql = "SELECT 
            a.id,
            a.titre_fr as title,
            a.conference_id as conferenceId,
            c.name_fr as conferenceName,
            a.domaine as domain,
            a.date_soumission as submissionDate,
            ar.status as reviewStatus,
            ar.assigned_date as assignedDate,
            ar.completed_date as completedDate
            FROM article_reviewers ar
            JOIN articles a ON ar.article_id = a.id
            JOIN conferences c ON a.conference_id = c.id
            WHERE ar.reviewer_id = ?
            ORDER BY ar.assigned_date DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$reviewerId]);
    return $stmt->fetchAll();
}

// Assign articles to reviewer
function assignArticlesToReviewer($reviewerId, $articleIds) {
    $db = Database::getConnection();
    $successCount = 0;
    $errors = [];
    
    foreach ($articleIds as $articleId) {
        // Check if article already has 2 reviewers
        $checkSql = "SELECT COUNT(*) as count FROM article_reviewers WHERE article_id = ?";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([$articleId]);
        $currentCount = $checkStmt->fetch()['count'];
        
        if ($currentCount >= 2) {
            $errors[] = "Article $articleId already has 2 reviewers";
            continue;
        }
        
        // Check if already assigned to this reviewer
        $existsSql = "SELECT COUNT(*) as count FROM article_reviewers WHERE article_id = ? AND reviewer_id = ?";
        $existsStmt = $db->prepare($existsSql);
        $existsStmt->execute([$articleId, $reviewerId]);
        if ($existsStmt->fetch()['count'] > 0) {
            continue; // Already assigned
        }
        
        // Assign article
        $sql = "INSERT INTO article_reviewers (article_id, reviewer_id, assigned_date, status) VALUES (?, ?, CURDATE(), 'pending')";
        $stmt = $db->prepare($sql);
        $stmt->execute([$articleId, $reviewerId]);
        
        // Update article status if it was new
        $updateArticleSql = "UPDATE articles SET statut = 'en_evaluation' WHERE id = ? AND statut = 'en_attente'";
        $updateStmt = $db->prepare($updateArticleSql);
        $updateStmt->execute([$articleId]);
        
        $successCount++;
    }
    
    return ['success' => $successCount, 'errors' => $errors];
}

// Get conferences list
function getConferences() {
    $db = Database::getConnection();
    $stmt = $db->query("SELECT id, name_fr as nameFr, disciplines FROM conferences ORDER BY created_at DESC");
    return $stmt->fetchAll();
}

// Get unique domains from articles
function getUniqueDomains() {
    $db = Database::getConnection();
    $stmt = $db->query("SELECT DISTINCT domaine FROM articles WHERE domaine IS NOT NULL AND domaine != '' ORDER BY domaine");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ==================== AJAX ENDPOINTS ====================

// Handle all AJAX requests
if (!empty($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    
    // Get all reviewers
    if ($action === 'get_reviewers') {
        try {
            $reviewers = getAllReviewers();
            $formattedReviewers = [];
            foreach ($reviewers as $r) {
                $formattedReviewers[] = [
                    'id' => $r['id'],
                    'name' => $r['first_name'] . ' ' . $r['last_name'],
                    'email' => $r['email'],
                    'institution' => $r['institution'] ?? '',
                    'specialties' => $r['specialties'] ? explode(',', $r['specialties']) : [],
                    'status' => $r['status'],
                    'assignedArticles' => [],
                    'completedArticles' => []
                ];
            }
            echo json_encode(['ok' => true, 'reviewers' => $formattedReviewers]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }
    
    // Get all articles
    if ($action === 'get_articles') {
        try {
            $articles = getAllArticles();
            echo json_encode(['ok' => true, 'articles' => $articles]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }
    
    // Get conferences
    if ($action === 'get_conferences') {
        try {
            $conferences = getConferences();
            echo json_encode(['ok' => true, 'conferences' => $conferences]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }
    
    // Get domains
    if ($action === 'get_domains') {
        try {
            $domains = getUniqueDomains();
            echo json_encode(['ok' => true, 'domains' => $domains]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }
    
    // Add new reviewer
    if ($action === 'add_reviewer') {
        $required = ['first_name', 'last_name', 'email'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                echo json_encode(['ok' => false, 'msg' => "Champ requis manquant: $field"]);
                exit;
            }
        }
        
        $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
        if (!$email) {
            echo json_encode(['ok' => false, 'msg' => 'Email invalide']);
            exit;
        }
        
        // Check if email already exists
        $db = Database::getConnection();
        $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $checkStmt->execute([$email]);
        if ($checkStmt->fetch()) {
            echo json_encode(['ok' => false, 'msg' => 'Cet email est déjà utilisé']);
            exit;
        }
        
        $data = [
            'first_name' => htmlspecialchars($_POST['first_name']),
            'last_name' => htmlspecialchars($_POST['last_name']),
            'email' => $email,
            'institution' => htmlspecialchars($_POST['institution'] ?? ''),
            'specialties' => htmlspecialchars($_POST['specialties'] ?? '')
        ];
        
        try {
            $result = createReviewer($data);
            echo json_encode(['ok' => true, 'msg' => 'Évaluateur ajouté avec succès', 'data' => $result]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => 'Erreur: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Assign articles to reviewer
    if ($action === 'assign_articles') {
        $reviewerId = (int)($_POST['reviewer_id'] ?? 0);
        $articleIds = json_decode($_POST['article_ids'] ?? '[]', true);
        
        if (!$reviewerId || empty($articleIds)) {
            echo json_encode(['ok' => false, 'msg' => 'Données invalides']);
            exit;
        }
        
        try {
            $result = assignArticlesToReviewer($reviewerId, $articleIds);
            echo json_encode(['ok' => true, 'success' => $result['success'], 'errors' => $result['errors']]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }
    
    echo json_encode(['ok' => false, 'msg' => 'Action inconnue: ' . $action]);
    exit;
}

// ==================== LOAD INITIAL DATA ====================
$reviewers = getAllReviewers();
$articles = getAllArticles();
$conferences = getConferences();
$domains = getUniqueDomains();

// Format reviewers for JavaScript
$formattedReviewers = [];
foreach ($reviewers as $r) {
    $formattedReviewers[] = [
        'id' => $r['id'],
        'name' => $r['first_name'] . ' ' . $r['last_name'],
        'email' => $r['email'],
        'institution' => $r['institution'] ?? '',
        'specialties' => $r['specialties'] ? explode(',', $r['specialties']) : [],
        'status' => $r['status'],
        'assignedArticles' => [],
        'completedArticles' => []
    ];
}

// Get assigned articles for each reviewer
foreach ($formattedReviewers as &$reviewer) {
    $assigned = getReviewerAssignedArticles($reviewer['id']);
    $reviewer['assignedArticles'] = array_column($assigned, 'id');
    $reviewer['completedArticles'] = array_column(array_filter($assigned, function($a) {
        return $a['reviewStatus'] === 'completed';
    }), 'id');
}

$jsReviewers = json_encode($formattedReviewers, JSON_UNESCAPED_UNICODE);
$jsArticles = json_encode($articles, JSON_UNESCAPED_UNICODE);
$jsConferences = json_encode($conferences, JSON_UNESCAPED_UNICODE);
$jsDomains = json_encode($domains, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Gestion des évaluateurs</title>
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

  /* ✨ animation */
  transition: all 0.3s ease;
}

.stat-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 12px 30px rgba(13,33,55,0.12);
  border-color: rgba(201,168,76,0.4); /* gold subtle */
}
  .stat-icon {
    width: 44px; height: 44px; border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
  }
  .stat-icon.total { background: rgba(44,111,173,0.1); color: var(--accent); }
  .stat-icon.active { background: rgba(42,157,143,0.1); color: var(--success); }
  .stat-icon.pending { background: rgba(212,131,10,0.1); color: var(--warning); }
  .stat-icon.invited { background: rgba(91,110,245,0.1); color: var(--purple); }
  .stat-value { font-size: 22px; font-weight: 700; color: var(--navy); line-height: 1; margin-bottom: 2px; }
  .stat-label { font-size: 12px; color: var(--muted); }

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
  .filter-count {
    font-size: 11px;
    background: rgba(255,255,255,0.2);
    padding: 1px 6px;
    border-radius: 12px;
    margin-left: 6px;
  }
  .filter-tab:not(.active) .filter-count { background: var(--bg); color: var(--muted); }
  
  .toolbar-right {
    display: flex;
    gap: 10px;
    align-items: center;
  }
  .sort-select {
    padding: 8px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-family: 'DM Sans', sans-serif;
    color: var(--text-light);
    background: var(--white);
    cursor: pointer;
    outline: none;
  }

  .table-wrapper {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow-x: auto;
    margin-bottom: 32px;
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

  .badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
  }
  .badge-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
  .badge.active { background: #e8f6f3; color: #1a5f57; border: 1px solid #9dd8d0; }
  .badge.active .badge-dot { background: var(--success); }
  .badge.pending { background: #fef8ec; color: #9a5f00; border: 1px solid #f5d98a; }
  .badge.pending .badge-dot { background: var(--warning); }
  .badge.invited { background: #f0f4ff; color: #3347bb; border: 1px solid #bcc7fa; }
  .badge.invited .badge-dot { background: var(--purple); }

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

  .modal-backdrop {
    position: fixed; inset: 0;
    background: rgba(13,33,55,0.5); backdrop-filter: blur(4px);
    z-index: 200; display: none; align-items: center; justify-content: center;
  }
  .modal-backdrop.open { display: flex; }
  .modal {
    background: var(--white); border-radius: var(--radius);
    width: 100%; max-width: 600px; box-shadow: var(--shadow-md);
    animation: fadeUp 0.2s ease; max-height: 90vh; overflow-y: auto;
  }
  .modal.large { max-width: 1000px; }
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

  .form-group { margin-bottom: 20px; }
  .field-label {
    font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted);
    display: block; margin-bottom: 6px;
  }
  .field-required { color: var(--danger); margin-left: 3px; }
  .form-input, .form-select, .form-textarea {
    width: 100%;
    padding: 11px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    color: var(--text);
    background: var(--white);
    transition: all 0.15s;
    outline: none;
  }
  .form-input:focus, .form-select:focus, .form-textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.12);
  }
  .notice-box {
    background: var(--bg); border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 12px 16px; font-size: 12.5px;
    color: var(--muted); line-height: 1.6;
    display: flex; gap: 10px; align-items: flex-start;
  }

  .assign-list {
    max-height: 400px;
    overflow-y: auto;
  }
  .assign-item {
    display: flex;
    align-items: center;
    padding: 12px;
    border-bottom: 1px solid var(--border);
    gap: 12px;
  }
  .assign-item:hover { background: var(--bg); }
  .assign-checkbox { width: 20px; height: 20px; cursor: pointer; }
  .assign-info { flex: 1; }
  .assign-title { font-weight: 600; font-size: 13px; color: var(--navy); }
  .assign-meta { font-size: 11px; color: var(--muted); margin-top: 2px; }
  .assign-domain {
    background: var(--bg);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 10px;
    color: var(--text-light);
  }

  .reviewer-count {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
  }
  .reviewer-count.complete { background: #e8f6f3; color: var(--success); }
  .reviewer-count.incomplete { background: #fef8ec; color: var(--warning); }
  .reviewer-count.full { background: #fdf2f2; color: var(--danger); }

  .pagination {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 20px;
  }
  .page-btn {
    padding: 8px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--white);
    color: var(--text-light);
    cursor: pointer;
    font-size: 13px;
  }
  .page-btn:hover { border-color: var(--navy); color: var(--navy); }
  .page-btn.active { background: var(--navy); color: var(--gold-light); }
  .page-btn:disabled { opacity: 0.5; cursor: not-allowed; }

  .loading {
    text-align: center;
    padding: 40px;
    color: var(--muted);
  }
  .loading i {
    font-size: 32px;
    margin-bottom: 12px;
    display: inline-block;
    animation: spin 1s linear infinite;
  }
  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }

  .footer {
    background: var(--navy); color: rgba(255,255,255,0.45);
    text-align: center; padding: 22px; margin-top: 48px; font-size: 13px;
  }
  @keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }
  
  @media (max-width: 900px) {
    .stats-row { grid-template-columns: repeat(2, 1fr); }
    .topbar { padding: 0 16px; }
    .nav-links { display: none; }
    .page { padding: 24px 16px; }
  }
</style>
</head>
<body>

<header class="topbar">
  <a class="brand" href="#">
  <div class="logo-wrapper">
    <div class="logo-icon"><i class="fas fa-book-open"></i></div>
    <span class="logo-text">ConfManager</span>
  </div>
</a>
  <nav class="nav-links">
    <a href="admin_dashboard.php" class="nav-link">Conférences & Articles</a>
    <a href="evaluators.php" class="nav-link active">Évaluateurs</a>
    <a href="final_decisions.php" class="nav-link">Décision Finale</a>
    <a href="admin_profile.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <div class="search-wrap">
     
      <input type="text" class="search-input" id="searchEvaluator" placeholder="Rechercher un évaluateur...">
    </div>
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

  <div class="stats-row">
    <div class="stat-card"><div class="stat-icon total"><i class="fas fa-users"></i></div><div><div class="stat-value" id="totalCount">0</div><div class="stat-label">Total évaluateurs</div></div></div>
    <div class="stat-card"><div class="stat-icon active"><i class="fas fa-check-circle"></i></div><div><div class="stat-value" id="activeCount">0</div><div class="stat-label">Actifs</div></div></div>
    <div class="stat-card"><div class="stat-icon pending"><i class="fas fa-hourglass-half"></i></div><div><div class="stat-value" id="pendingCount">0</div><div class="stat-label">En attente</div></div></div>
    <div class="stat-card"><div class="stat-icon invited"><i class="fas fa-envelope"></i></div><div><div class="stat-value" id="invitedCount">0</div><div class="stat-label">Invités</div></div></div>
  </div>

  <div class="filters-bar">
    <div class="filter-tabs">
      <button class="filter-tab active" data-filter="all">Tous <span class="filter-count" id="filterAllCount">0</span></button>
      <button class="filter-tab" data-filter="active">Actifs <span class="filter-count" id="filterActiveCount">0</span></button>
      <button class="filter-tab" data-filter="pending">En attente <span class="filter-count" id="filterPendingCount">0</span></button>
      <button class="filter-tab" data-filter="invited">Invités <span class="filter-count" id="filterInvitedCount">0</span></button>
    </div>
    <div class="toolbar-right">
      <select class="sort-select" id="sortSelect">
        <option value="name">Nom A→Z</option>
        <option value="evaluations">Évaluations (desc)</option>
        <option value="assigned">Articles assignés</option>
      </select>
    </div>
  </div>

  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr><th>Évaluateur</th><th>Contact</th><th>Spécialités</th><th>Statut</th><th>Articles assignés</th><th>Action</th></tr>
      </thead>
      <tbody id="evaluatorTableBody"><tr><td colspan="6" class="loading"><i class="fas fa-spinner"></i><br>Chargement......</td></tr></tbody>
    </table>
  </div>
  <div class="pagination" id="pagination"></div>
</main>

<footer class="footer">© <?= date('Y') ?> ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés</footer>

<!-- MODAL AJOUT ÉVALUATEUR -->
<div id="evaluatorModal" class="modal-backdrop">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Ajouter un évaluateur</div>
      <button class="modal-close" onclick="closeEvaluatorModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form id="evaluatorForm">
        <div class="form-group">
          <label class="field-label">Prénom <span class="field-required">*</span></label>
          <input type="text" class="form-input" id="evalFirstName" placeholder="Prénom">
        </div>
        <div class="form-group">
          <label class="field-label">Nom <span class="field-required">*</span></label>
          <input type="text" class="form-input" id="evalLastName" placeholder="Nom">
        </div>
        <div class="form-group">
          <label class="field-label">Adresse e-mail <span class="field-required">*</span></label>
          <input type="email" class="form-input" id="evalEmail" placeholder="prenom.nom@universite.dz">
        </div>
        <div class="form-group">
          <label class="field-label">Institution</label>
          <input type="text" class="form-input" id="evalInstitution" placeholder="Université / Centre de recherche">
        </div>
        <div class="form-group">
          <label class="field-label">Spécialités (séparées par des virgules)</label>
          <input type="text" class="form-input" id="evalSpecialties" placeholder="ex: IA, NLP, Machine Learning">
        </div>
        <div class="notice-box">
          <i class="fas fa-info-circle"></i>
          <span>Un email d'invitation sera envoyé à l'évaluateur pour l'informer de son rôle et lui donner accès à la plateforme.</span>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeEvaluatorModal()">Annuler</button>
      <button class="btn-primary" id="saveEvaluatorBtn"><i class="fas fa-save"></i> Enregistrer et inviter</button>
    </div>
  </div>
</div>

<!-- MODAL ASSIGNER DES ARTICLES -->
<div id="assignModal" class="modal-backdrop">
  <div class="modal large">
    <div class="modal-header">
      <div class="modal-title">Assigner des articles à <span id="assignEvalName"></span></div>
      <button class="modal-close" onclick="closeAssignModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div style="margin-bottom: 16px; display: flex; gap: 12px;">
        <select class="sort-select" id="filterConference" style="flex:1;"><option value="all">Toutes les conférences</option></select>
        <select class="sort-select" id="filterDomain" style="flex:1;"><option value="all">Tous les domaines</option></select>
      </div>
      <div class="notice-box" style="background:#e0e7ff; border-color:#c7d2fe; margin-bottom:16px;">
        <i class="fas fa-info-circle" style="color:var(--purple);"></i>
        <span><strong>Règle:</strong> Chaque article doit avoir exactement <strong>2 évaluateurs</strong>.</span>
      </div>
      <div class="assign-list" id="assignList"><div class="loading"><i class="fas fa-spinner"></i><br>Chargement...</div></div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeAssignModal()">Annuler</button>
      <button class="btn-primary" id="confirmAssignBtn"><i class="fas fa-check"></i> Assigner</button>
    </div>
  </div>
</div>

<script>
// ==================== DATA ====================
let evaluators = <?= $jsReviewers ?>;
let availableArticles = <?= $jsArticles ?>;
let conferences = <?= $jsConferences ?>;
let domainsData = <?= $jsDomains ?>;

// ==================== STATE ====================
let currentFilter = 'all';
let currentSort = 'name';
let currentPage = 1;
let itemsPerPage = 10;
let currentEvaluatorId = null;
let assignFilterConference = 'all';
let assignFilterDomain = 'all';

// ==================== SweetAlert Helper ====================
function showSuccess(title, text) {
    Swal.fire({
        title: title,
        text: text,
        icon: 'success',
        confirmButtonText: 'OK',
        confirmButtonColor: '#0d2137',
        showClass: { popup: '' },
        hideClass: { popup: '' }
    });
}

function showError(title, text) {
    Swal.fire({
        title: title,
        text: text,
        icon: 'error',
        confirmButtonText: 'OK',
        confirmButtonColor: '#0d2137',
        showClass: { popup: '' },
        hideClass: { popup: '' }
    });
}

function showInfo(title, text) {
    Swal.fire({
        title: title,
        text: text,
        icon: 'info',
        confirmButtonText: 'OK',
        confirmButtonColor: '#0d2137',
        showClass: { popup: '' },
        hideClass: { popup: '' }
    });
}

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

function getStatusBadge(status) {
    const badges = {
        active: '<span class="badge active"><span class="badge-dot"></span>Actif</span>',
        pending: '<span class="badge pending"><span class="badge-dot"></span>En attente</span>',
        invited: '<span class="badge invited"><span class="badge-dot"></span>Invité</span>'
    };
    return badges[status] || badges.invited;
}

function getReviewerCountBadge(article) {
    const count = article.reviewerCount || 0;
    if (count >= 2) return `<span class="reviewer-count full"><i class="fas fa-check-circle"></i> Complet (${count}/2)</span>`;
    if (count === 1) return `<span class="reviewer-count incomplete"><i class="fas fa-user-clock"></i> 1/2 évaluateurs</span>`;
    return `<span class="reviewer-count incomplete"><i class="fas fa-user-plus"></i> 0/2 évaluateurs</span>`;
}

// ==================== RENDER ====================
function updateStats() {
    const total = evaluators.length;
    const active = evaluators.filter(e => e.status === 'active').length;
    const pending = evaluators.filter(e => e.status === 'pending').length;
    const invited = evaluators.filter(e => e.status === 'invited').length;
    
    document.getElementById('totalCount').textContent = total;
    document.getElementById('activeCount').textContent = active;
    document.getElementById('pendingCount').textContent = pending;
    document.getElementById('invitedCount').textContent = invited;
    document.getElementById('filterAllCount').textContent = total;
    document.getElementById('filterActiveCount').textContent = active;
    document.getElementById('filterPendingCount').textContent = pending;
    document.getElementById('filterInvitedCount').textContent = invited;
}

function getFilteredEvaluators() {
    let filtered = [...evaluators];
    if (currentFilter !== 'all') filtered = filtered.filter(e => e.status === currentFilter);
    
    const searchTerm = document.getElementById('searchEvaluator')?.value.toLowerCase() || '';
    if (searchTerm) {
        filtered = filtered.filter(e => 
            e.name.toLowerCase().includes(searchTerm) || 
            e.email.toLowerCase().includes(searchTerm) ||
            e.specialties.some(s => s.toLowerCase().includes(searchTerm))
        );
    }
    
    switch(currentSort) {
        case 'name': filtered.sort((a, b) => a.name.localeCompare(b.name)); break;
        case 'assigned': filtered.sort((a, b) => b.assignedArticles.length - a.assignedArticles.length); break;
    }
    return filtered;
}

function renderTable() {
    let filtered = getFilteredEvaluators();
    const start = (currentPage - 1) * itemsPerPage;
    const paginated = filtered.slice(start, start + itemsPerPage);
    
    let html = '';
    for (const eval_ of paginated) {
        let articlesHtml = '';
        if (eval_.assignedArticles && eval_.assignedArticles.length > 0) {
            articlesHtml = '<div style="display:flex;flex-direction:column;gap:6px;">';
            for (const articleId of eval_.assignedArticles) {
                const article = availableArticles.find(a => a.id === articleId);
                if (article) {
                    articlesHtml += `<div style="font-size:12px;padding:4px 8px;background:var(--bg);border-radius:4px;">
                        <div style="font-weight:500;">${escapeHtml(article.title.substring(0,40))}</div>
                        <div>${getReviewerCountBadge(article)}</div>
                    </div>`;
                }
            }
            articlesHtml += '</div>';
        } else {
            articlesHtml = '<span style="color:var(--muted);font-size:12px;">Aucun article</span>';
        }
        
        html += `<tr>
            <td><strong>${escapeHtml(eval_.name)}</strong><br><span style="font-size:11px;color:var(--muted);">${escapeHtml(eval_.institution)}</span></td>
            <td style="font-size:12px;">${escapeHtml(eval_.email)}</td>
            <td>${eval_.specialties.map(s => `<span style="background:var(--bg);padding:2px 8px;border-radius:4px;margin:2px;display:inline-block;font-size:11px;">${escapeHtml(s)}</span>`).join('')}</td>
            <td>${getStatusBadge(eval_.status)}</td>
            <td>${articlesHtml}</td>
            <td>${eval_.status === 'active' ? `<button class="action-btn" onclick="assignArticles(${eval_.id})" title="Assigner"><i class="fas fa-tasks"></i></button>` : '<span style="font-size:11px;color:var(--muted);">Non disponible</span>'}</td>
        </tr>`;
    }
    
    document.getElementById('evaluatorTableBody').innerHTML = html || '<tr><td colspan="6" style="text-align:center;padding:40px;">Aucun évaluateur</td></tr>';
    
    const totalPages = Math.ceil(filtered.length / itemsPerPage);
    renderPagination(totalPages);
}

function renderPagination(totalPages) {
    const div = document.getElementById('pagination');
    if (totalPages <= 1) { div.innerHTML = ''; return; }
    
    let html = `<button class="page-btn" onclick="changePage(${currentPage-1})" ${currentPage===1?'disabled':''}><i class="fas fa-chevron-left"></i></button>`;
    for (let i = 1; i <= Math.min(totalPages, 5); i++) {
        html += `<button class="page-btn ${currentPage===i?'active':''}" onclick="changePage(${i})">${i}</button>`;
    }
    if (totalPages > 5) html += `<span style="padding:8px;">...</span><button class="page-btn" onclick="changePage(${totalPages})">${totalPages}</button>`;
    html += `<button class="page-btn" onclick="changePage(${currentPage+1})" ${currentPage===totalPages?'disabled':''}><i class="fas fa-chevron-right"></i></button>`;
    div.innerHTML = html;
}

function render() { updateStats(); renderTable(); }
function changePage(page) { currentPage = page; render(); }

// ==================== MODAL FUNCTIONS ====================
function openEvaluatorModal() {
    document.getElementById('evaluatorForm').reset();
    document.getElementById('evaluatorModal').classList.add('open');
}
function closeEvaluatorModal() { document.getElementById('evaluatorModal').classList.remove('open'); }

function saveEvaluator() {
    const firstName = document.getElementById('evalFirstName').value.trim();
    const lastName = document.getElementById('evalLastName').value.trim();
    const email = document.getElementById('evalEmail').value.trim();
    const institution = document.getElementById('evalInstitution').value.trim();
    const specialties = document.getElementById('evalSpecialties').value;
    
    if (!firstName || !lastName || !email) {
        showError('Champs manquants', 'Veuillez remplir tous les champs obligatoires.');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_reviewer');
    formData.append('first_name', firstName);
    formData.append('last_name', lastName);
    formData.append('email', email);
    formData.append('institution', institution);
    formData.append('specialties', specialties);
    
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { 
                showError('Erreur', data.msg || 'Erreur serveur'); 
                return; 
            }
            showSuccess('Succès', 'Évaluateur ajouté avec succès!');
            setTimeout(() => location.reload(), 1500);
        })
        .catch(err => { 
            console.error(err); 
            showError('Erreur', 'Erreur de communication avec le serveur.'); 
        });
}

// ==================== ASSIGNMENT FUNCTIONS ====================
function assignArticles(id) {
    const evaluator = evaluators.find(e => e.id === id);
    if (!evaluator || evaluator.status !== 'active') {
        showError('Non autorisé', 'Cet évaluateur n\'est pas actif.');
        return;
    }
    
    currentEvaluatorId = id;
    document.getElementById('assignEvalName').textContent = evaluator.name;
    
    // Populate filters
    const confSelect = document.getElementById('filterConference');
    confSelect.innerHTML = '<option value="all">Toutes les conférences</option>';
    conferences.forEach(c => { confSelect.innerHTML += `<option value="${c.id}">${escapeHtml(c.nameFr)}</option>`; });
    
    const domainSelect = document.getElementById('filterDomain');
    domainSelect.innerHTML = '<option value="all">Tous les domaines</option>';
    domainsData.forEach(d => { domainSelect.innerHTML += `<option value="${d}">${escapeHtml(d)}</option>`; });
    
    renderAssignmentList();
    document.getElementById('assignModal').classList.add('open');
}

function renderAssignmentList() {
    const evaluator = evaluators.find(e => e.id === currentEvaluatorId);
    if (!evaluator) return;
    
    let filtered = availableArticles.filter(a => {
        if ((a.reviewerCount || 0) >= 2) return false;
        if (assignFilterConference !== 'all' && a.conferenceId !== parseInt(assignFilterConference)) return false;
        if (assignFilterDomain !== 'all' && a.domain !== assignFilterDomain) return false;
        return true;
    });
    
    if (filtered.length === 0) {
        document.getElementById('assignList').innerHTML = '<div style="text-align:center;padding:40px;">Aucun article disponible</div>';
        return;
    }
    
    let html = '';
    for (const article of filtered) {
        const isAssigned = evaluator.assignedArticles.includes(article.id);
        const matchesSpecialty = evaluator.specialties.some(s => 
            (article.domain || '').toLowerCase().includes(s.toLowerCase())
        );
        
        html += `<div class="assign-item" style="${matchesSpecialty ? 'background:rgba(44,111,173,0.05);' : ''}">
            <input type="checkbox" class="assign-checkbox" data-article-id="${article.id}" ${isAssigned ? 'checked disabled' : ''}>
            <div class="assign-info">
                <div class="assign-title">${escapeHtml(article.title)}</div>
                <div class="assign-meta">${escapeHtml(article.author)} · ${escapeHtml(article.domain)}</div>
                <div>${getReviewerCountBadge(article)}</div>
            </div>
            ${matchesSpecialty && !isAssigned ? '<div><span class="badge" style="background:var(--accent);color:white;">Correspond</span></div>' : ''}
        </div>`;
    }
    document.getElementById('assignList').innerHTML = html;
}

function closeAssignModal() {
    document.getElementById('assignModal').classList.remove('open');
    currentEvaluatorId = null;
}

function confirmAssign() {
    if (!currentEvaluatorId) return;
    
    const selected = [];
    document.querySelectorAll('.assign-checkbox:checked:not(:disabled)').forEach(cb => {
        selected.push(parseInt(cb.dataset.articleId));
    });
    
    if (selected.length === 0) { 
        showError('Sélection requise', 'Veuillez sélectionner au moins un article.');
        return; 
    }
    
    const formData = new FormData();
    formData.append('action', 'assign_articles');
    formData.append('reviewer_id', currentEvaluatorId);
    formData.append('article_ids', JSON.stringify(selected));
    
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { 
                showError('Erreur', data.msg || 'Erreur serveur'); 
                return; 
            }
            showSuccess('Succès', `${data.success} article(s) assigné(s) avec succès!`);
            setTimeout(() => location.reload(), 1500);
        })
        .catch(err => { 
            console.error(err); 
            showError('Erreur', 'Erreur de communication avec le serveur.'); 
        });
}

// ==================== EVENT LISTENERS ====================
document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        currentFilter = tab.dataset.filter;
        currentPage = 1;
        render();
    });
});

document.getElementById('sortSelect').addEventListener('change', e => { currentSort = e.target.value; currentPage = 1; render(); });
document.getElementById('searchEvaluator').addEventListener('input', () => { currentPage = 1; render(); });
document.getElementById('openAddEvaluatorModal').addEventListener('click', openEvaluatorModal);
document.getElementById('saveEvaluatorBtn').addEventListener('click', saveEvaluator);
document.getElementById('confirmAssignBtn').addEventListener('click', confirmAssign);
document.getElementById('filterConference').addEventListener('change', () => renderAssignmentList());
document.getElementById('filterDomain').addEventListener('change', () => renderAssignmentList());

document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', e => { if (e.target === backdrop) backdrop.classList.remove('open'); });
});

// ==================== GLOBALS ====================
window.assignArticles = assignArticles;
window.changePage = changePage;
window.closeEvaluatorModal = closeEvaluatorModal;
window.closeAssignModal = closeAssignModal;

// ==================== INIT ====================
render();
</script>
</body>
</html>