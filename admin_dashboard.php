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
                die("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$connection;
    }
}

// ==================== DATABASE FUNCTIONS ====================

function getAllConferences() {
    $db = Database::getConnection();
    $stmt = $db->query("
        SELECT 
            c.id, 
            c.name_fr as nameFr, 
            c.name_en as nameEn, 
            c.type, 
            c.disciplines, 
            c.organizer, 
            c.location,
            c.start_date as startDate, 
            c.end_date as endDate, 
            c.submission_start_date as submissionStartDate, 
            c.submission_deadline as submissionDeadline,
            c.review_start_date as reviewStartDate, 
            c.review_end_date as reviewEndDate, 
            c.requirements, 
            c.max_articles as maxArticles,
            c.articles_count as articlesCount,
            c.created_at as createdAt,
            GROUP_CONCAT(DISTINCT ct.topic_name ORDER BY ct.topic_name SEPARATOR ', ') as topics
        FROM conferences c
        LEFT JOIN conference_topics ct ON c.id = ct.conference_id
        GROUP BY c.id
        ORDER BY c.created_at DESC
    ");
    return $stmt->fetchAll();
}

function getConferenceById($id) {
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT * FROM conferences WHERE id = ?");
    $stmt->execute([$id]);
    $result = $stmt->fetch();
    if ($result) {
        $result['nameFr'] = $result['name_fr'];
        $result['nameEn'] = $result['name_en'];
        $result['startDate'] = $result['start_date'];
        $result['endDate'] = $result['end_date'];
        $result['submissionStartDate'] = $result['submission_start_date'];
        $result['submissionDeadline'] = $result['submission_deadline'];
        $result['reviewStartDate'] = $result['review_start_date'];
        $result['reviewEndDate'] = $result['review_end_date'];
        $result['maxArticles'] = $result['max_articles'];
        $result['articlesCount'] = $result['articles_count'];
        
        $topicStmt = $db->prepare("SELECT topic_name FROM conference_topics WHERE conference_id = ? ORDER BY topic_name");
        $topicStmt->execute([$id]);
        $topics = $topicStmt->fetchAll(PDO::FETCH_COLUMN);
        $result['topics'] = $topics;
    }
    return $result;
}

function createConference($data) {
    $db = Database::getConnection();
    $sql = "INSERT INTO conferences (name_fr, name_en, type, disciplines, organizer, location, 
            start_date, end_date, submission_start_date, submission_deadline, review_start_date, 
            review_end_date, requirements, max_articles, articles_count, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $data['nameFr'],
        $data['nameEn'],
        $data['type'],
        $data['disciplines'],
        $data['organizer'],
        $data['location'],
        $data['startDate'],
        $data['endDate'],
        $data['submissionStartDate'],
        $data['submissionDeadline'],
        $data['reviewStartDate'],
        $data['reviewEndDate'],
        $data['requirements'],
        $data['maxArticles'],
        $data['createdAt']
    ]);
    
    $newId = $db->lastInsertId();
    
    if (!empty($data['topics']) && is_array($data['topics'])) {
        $topicStmt = $db->prepare("INSERT INTO conference_topics (conference_id, topic_name) VALUES (?, ?)");
        foreach ($data['topics'] as $topic) {
            if (!empty(trim($topic))) {
                $topicStmt->execute([$newId, trim($topic)]);
            }
        }
    }
    
    return $newId;
}

function updateConference($id, $data) {
    $db = Database::getConnection();
    $sql = "UPDATE conferences SET 
            name_fr = ?, name_en = ?, type = ?, disciplines = ?, organizer = ?, 
            location = ?, start_date = ?, end_date = ?, submission_start_date = ?, 
            submission_deadline = ?, review_start_date = ?, review_end_date = ?, 
            requirements = ?, max_articles = ? 
            WHERE id = ?";
    
    $stmt = $db->prepare($sql);
    $result = $stmt->execute([
        $data['nameFr'],
        $data['nameEn'],
        $data['type'],
        $data['disciplines'],
        $data['organizer'],
        $data['location'],
        $data['startDate'],
        $data['endDate'],
        $data['submissionStartDate'],
        $data['submissionDeadline'],
        $data['reviewStartDate'],
        $data['reviewEndDate'],
        $data['requirements'],
        $data['maxArticles'],
        $id
    ]);
    
    if (isset($data['topics'])) {
        $deleteStmt = $db->prepare("DELETE FROM conference_topics WHERE conference_id = ?");
        $deleteStmt->execute([$id]);
        
        $topicStmt = $db->prepare("INSERT INTO conference_topics (conference_id, topic_name) VALUES (?, ?)");
        foreach ($data['topics'] as $topic) {
            if (!empty(trim($topic))) {
                $topicStmt->execute([$id, trim($topic)]);
            }
        }
    }
    
    return $result;
}

function getAllArticles() {
    $db = Database::getConnection();
    $stmt = $db->query("SELECT 
                        a.id,
                        a.conference_id as conferenceId,
                        a.titre_fr as title,
                        u.first_name as author,
                        u.institution as authorInstitution,
                        u.email as authorEmail,
                        a.date_soumission as submissionDate,
                        a.status as status,
                        a.resume_fr as abstract,
                        a.mots_cles as keywords,
                        a.fichier_principal as file,
                        a.domaine as domain,
                        a.rejection_reason as rejectReason,
                        a.ai_score,
                        a.risk_level,
                        a.ai_analysis_date,
                        '' as assignedTo
                        FROM articles a
                        JOIN users u ON a.utilisateur_id = u.id
                        ORDER BY a.date_soumission DESC");
    return $stmt->fetchAll();
}

function getArticlesByConference($conferenceId) {
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT 
                        a.id,
                        a.conference_id as conferenceId,
                        a.titre_fr as title,
                        u.first_name as author,
                        u.institution as authorInstitution,
                        u.email as authorEmail,
                        a.date_soumission as submissionDate,
                        a.status as status,
                        a.resume_fr as abstract,
                        a.mots_cles as keywords,
                        a.fichier_principal as file,
                        a.domaine as domain,
                        a.rejection_reason as rejectReason,
                        a.ai_score,
                        a.risk_level,
                        a.ai_analysis_date,
                        '' as assignedTo
                        FROM articles a
                        JOIN users u ON a.utilisateur_id = u.id
                        WHERE a.conference_id = ?
                        ORDER BY a.date_soumission DESC");
    $stmt->execute([$conferenceId]);
    return $stmt->fetchAll();
}

function getArticleById($id) {
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT 
                        a.id,
                        a.conference_id as conferenceId,
                        a.titre_fr as title,
                        u.first_name as author,
                        u.institution as authorInstitution,
                        u.email as authorEmail,
                        a.date_soumission as submissionDate,
                        a.status as status,
                        a.resume_fr as abstract,
                        a.mots_cles as keywords,
                        a.fichier_principal as file,
                        a.domaine as domain,
                        a.rejection_reason as rejectReason,
                        a.ai_score,
                        a.risk_level,
                        a.ai_analysis_date,
                        '' as assignedTo
                        FROM articles a
                        JOIN users u ON a.utilisateur_id = u.id
                        WHERE a.id = ?");
    $stmt->execute([$id]);
    $result = $stmt->fetch();
    
    if ($result) {
        $result['ai_score'] = $result['ai_score'] ?? null;
        $result['risk_level'] = $result['risk_level'] ?? 'pending';
        $result['ai_analysis_date'] = $result['ai_analysis_date'] ?? null;
    }
    return $result;
}

function acceptArticle($id, $assignedTo) {
    $db = Database::getConnection();
    $stmt = $db->prepare("UPDATE articles SET statut = 'assigne' WHERE id = ?");
    return $stmt->execute([$id]);
}

function rejectArticle($id, $reason) {
    $db = Database::getConnection();
    $stmt = $db->prepare("UPDATE articles SET statut = 'refuse', rejection_reason = ? WHERE id = ?");
    return $stmt->execute([$reason, $id]);
}

function getArticleCountByConference($conferenceId) {
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM articles WHERE conference_id = ?");
    $stmt->execute([$conferenceId]);
    $result = $stmt->fetch();
    return $result['count'];
}

function updateConferenceArticleCount($conferenceId) {
    $db = Database::getConnection();
    $count = getArticleCountByConference($conferenceId);
    $stmt = $db->prepare("UPDATE conferences SET articles_count = ? WHERE id = ?");
    $stmt->execute([$count, $conferenceId]);
    return $count;
}

function computeStatus($conf) {
    $today    = new DateTime('today');
    $subStart = $conf['submissionStartDate'] ? new DateTime($conf['submissionStartDate']) : null;
    $subEnd   = $conf['submissionDeadline']  ? new DateTime($conf['submissionDeadline'])  : null;

    if ($subEnd   && $today > $subEnd)   return 'closed';
    if ($subStart && $today >= $subStart) return 'active';
    return 'upcoming';
}

function isEditAllowed($conf) {
    if (empty($conf['submissionStartDate'])) return true;
    $today    = new DateTime('today');
    $subStart = new DateTime($conf['submissionStartDate']);
    return $today <= $subStart;
}

// ==================== AI ANALYSIS FUNCTIONS ====================

function analyzeArticleWithAI($articleId) {
    $article = getArticleById($articleId);
    if (!$article) {
        return ['success' => false, 'error' => 'Article non trouvé'];
    }
    
    $abstract = $article['abstract'] ?? '';
    $title = $article['title'] ?? '';
    $content = $title . ' ' . $abstract;
    
    $score = calculateAIScore($content);
    
    if ($score < 30) {
        $riskLevel = 'clean';
    } elseif ($score < 60) {
        $riskLevel = 'medium';
    } else {
        $riskLevel = 'high';
    }
    
    $db = Database::getConnection();
    $stmt = $db->prepare("UPDATE articles SET ai_score = ?, risk_level = ?, ai_analysis_date = NOW() WHERE id = ?");
    $result = $stmt->execute([$score, $riskLevel, $articleId]);
    
    if ($result) {
        return [
            'success' => true,
            'ai_score' => $score,
            'risk_level' => $riskLevel
        ];
    } else {
        return ['success' => false, 'error' => 'Erreur lors de l\'enregistrement'];
    }
}

function calculateAIScore($text) {
    $patterns = [
        '/d\'ailleurs|cependant|néanmoins|par conséquent|en outre/iu' => 2,
        '/en effet|par exemple|notamment|particulièrement|généralement/iu' => 2,
        '/(premièrement|deuxièmement|troisièmement|ensuite|finalement)/i' => 3,
        '/(selon|d\'après|comme le mentionne|comme indiqué|comme le souligne)/i' => 3,
        '/(probablement|possiblement|apparemment|certainement|essentiellement)/i' => 2,
        '/(contribution|cette étude|nous proposons|nous présentons|nous analysons)/i' => 4,
        '/(notre recherche|cette recherche|l\'objectif de cette étude)/i' => 4,
        '/\b\w{15,}\b/' => 3,
        '/[A-Z]{3,}/' => 2,
        '/(par ailleurs|en revanche|au contraire|toutefois|cependant|en conséquence)/i' => 2
    ];
    
    $score = 0;
    $totalWeight = 0;
    
    foreach ($patterns as $pattern => $weight) {
        preg_match_all($pattern, $text, $matches);
        $count = count($matches[0] ?? []);
        $score += $count * $weight;
        $totalWeight += $weight;
    }
    
    $normalizedScore = min(100, max(0, round(($score / max(1, $totalWeight)) * 20)));
    $normalizedScore = min(100, max(0, $normalizedScore + rand(-10, 10)));
    
    return $normalizedScore;
}

function getAIAnalysisStats() {
    $db = Database::getConnection();
    
    $analyzedCount = $db->query("SELECT COUNT(*) as count FROM articles WHERE ai_score IS NOT NULL")->fetch()['count'];
    $cleanCount = $db->query("SELECT COUNT(*) as count FROM articles WHERE risk_level = 'clean'")->fetch()['count'];
    $mediumCount = $db->query("SELECT COUNT(*) as count FROM articles WHERE risk_level = 'medium'")->fetch()['count'];
    $highCount = $db->query("SELECT COUNT(*) as count FROM articles WHERE risk_level = 'high'")->fetch()['count'];
    $pendingCount = $db->query("SELECT COUNT(*) as count FROM articles WHERE risk_level = 'pending' OR ai_score IS NULL")->fetch()['count'];
    
    return [
        'analyzed' => $analyzedCount,
        'clean' => $cleanCount,
        'medium' => $mediumCount,
        'high' => $highCount,
        'pending' => $pendingCount,
        'total' => $analyzedCount + $pendingCount
    ];
}

function getAllAIAnalyses() {
    $db = Database::getConnection();
    $stmt = $db->query("
        SELECT 
            a.id,
            a.reference,
            a.titre_fr as title,
            a.ai_score,
            a.risk_level,
            a.ai_analysis_date,
            a.conference_id,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as author_name,
            u.email as author_email,
            c.name_fr as conference_name
        FROM articles a
        JOIN users u ON a.utilisateur_id = u.id
        JOIN conferences c ON a.conference_id = c.id
        ORDER BY COALESCE(a.ai_analysis_date, a.date_soumission) DESC
    ");
    return $stmt->fetchAll();
}

// ==================== TOPIC MANAGEMENT AJAX ENDPOINTS ====================
if (!empty($_POST['action']) && $_POST['action'] === 'add_topic') {
    header('Content-Type: application/json');
    
    $conferenceId = (int)($_POST['conference_id'] ?? 0);
    $topicName = trim($_POST['topic_name'] ?? '');
    
    if ($conferenceId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Veuillez sélectionner une conférence.']);
        exit;
    }
    
    if (empty($topicName)) {
        echo json_encode(['ok' => false, 'msg' => 'Veuillez saisir un thème.']);
        exit;
    }
    
    try {
        $topicDb = Database::getConnection();
        
        $checkStmt = $topicDb->prepare("SELECT id FROM conference_topics WHERE conference_id = ? AND topic_name = ?");
        $checkStmt->execute([$conferenceId, $topicName]);
        if ($checkStmt->fetch()) {
            echo json_encode(['ok' => false, 'msg' => 'Ce thème existe déjà pour cette conférence.']);
            exit;
        }
        
        $insertStmt = $topicDb->prepare("INSERT INTO conference_topics (conference_id, topic_name) VALUES (?, ?)");
        $insertStmt->execute([$conferenceId, $topicName]);
        
        echo json_encode(['ok' => true, 'msg' => 'Thème ajouté avec succès.', 'topic_id' => $topicDb->lastInsertId()]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => 'Erreur base de données: ' . $e->getMessage()]);
    }
    exit;
}

// ==================== AI ANALYSIS AJAX ENDPOINTS ====================
if (!empty($_GET['action']) && $_GET['action'] === 'analyze_pending') {
    header('Content-Type: application/json');
    $db = Database::getConnection();
    $pendingStmt = $db->query("SELECT id FROM articles WHERE ai_score IS NULL OR risk_level = 'pending'");
    $pendingArticles = $pendingStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $analyzed = 0;
    $errors = 0;
    
    foreach ($pendingArticles as $articleId) {
        $result = analyzeArticleWithAI($articleId);
        if ($result['success']) {
            $analyzed++;
        } else {
            $errors++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'analyzed' => $analyzed,
        'errors' => $errors,
        'total' => count($pendingArticles)
    ]);
    exit;
}

if (!empty($_GET['action']) && $_GET['action'] === 'analyze_single') {
    header('Content-Type: application/json');
    $articleId = (int)($_GET['article_id'] ?? 0);
    
    if ($articleId <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID d\'article invalide']);
        exit;
    }
    
    $result = analyzeArticleWithAI($articleId);
    echo json_encode($result);
    exit;
}

// ==================== STANDARD AJAX ENDPOINTS ====================

if (!empty($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'save_conference') {
        $required = ['nameFr','nameEn','startDate','endDate',
                     'submissionStartDate','submissionDeadline',
                     'reviewStartDate','reviewEndDate'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                echo json_encode(['ok' => false, 'msg' => "Champ requis manquant: $field"]);
                exit;
            }
        }

        $sStart = new DateTime($_POST['submissionStartDate']);
        $sEnd   = new DateTime($_POST['submissionDeadline']);
        $rStart = new DateTime($_POST['reviewStartDate']);
        $rEnd   = new DateTime($_POST['reviewEndDate']);
        $cStart = new DateTime($_POST['startDate']);

        if ($sStart > $sEnd)  { echo json_encode(['ok'=>false,'msg'=>"L'ouverture des soumissions doit être avant la clôture."]); exit; }
        if ($sEnd   > $rStart){ echo json_encode(['ok'=>false,'msg'=>"L'évaluation doit commencer après la clôture des soumissions."]); exit; }
        if ($rStart > $rEnd)  { echo json_encode(['ok'=>false,'msg'=>"La fin d'évaluation doit être après le début."]); exit; }
        if ($rEnd   > $cStart){ echo json_encode(['ok'=>false,'msg'=>"L'évaluation doit se terminer avant la conférence."]); exit; }

        $editId = (int)($_POST['editId'] ?? 0);
        
        $topics = [];
        if (isset($_POST['topics'])) {
            $topics = json_decode($_POST['topics'], true);
            if (!is_array($topics)) {
                $topics = [];
            }
        }
        
        $data = [
            'nameFr'              => htmlspecialchars($_POST['nameFr']),
            'nameEn'              => htmlspecialchars($_POST['nameEn']),
            'type'                => htmlspecialchars($_POST['type'] ?? 'Conférence'),
            'disciplines'         => htmlspecialchars($_POST['disciplines'] ?? ''),
            'organizer'           => htmlspecialchars($_POST['organizer'] ?? ''),
            'location'            => htmlspecialchars($_POST['location'] ?? ''),
            'startDate'           => $_POST['startDate'],
            'endDate'             => $_POST['endDate'],
            'submissionStartDate' => $_POST['submissionStartDate'],
            'submissionDeadline'  => $_POST['submissionDeadline'],
            'reviewStartDate'     => $_POST['reviewStartDate'],
            'reviewEndDate'       => $_POST['reviewEndDate'],
            'requirements'        => htmlspecialchars($_POST['requirements'] ?? ''),
            'maxArticles'         => (int)($_POST['maxArticles'] ?? 40),
            'createdAt'           => $_POST['createdAt'] ?? date('Y-m-d'),
            'topics'              => $topics
        ];

        try {
            if ($editId > 0) {
                updateConference($editId, $data);
                $data['id'] = $editId;
                $data['articlesCount'] = updateConferenceArticleCount($editId);
            } else {
                $newId = createConference($data);
                $data['id'] = $newId;
                $data['articlesCount'] = 0;
            }
            echo json_encode(['ok' => true, 'data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => 'Erreur base de données: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'accept_article') {
        $id = (int)($_POST['id'] ?? 0);
        $domain = htmlspecialchars($_POST['domain'] ?? '');
        $reviewers = [
            'Informatique' => 'Dr. Karim Tech',
            'Robotique'    => 'Prof. Samir Bot',
            'Linguistique' => 'Dr. Amina Lang',
            'Éducation'    => 'Prof. Fatima Edu',
            'IoT'          => 'Dr. Yacine Net',
        ];
        $assignedTo = $reviewers[$domain] ?? 'Dr. General Reviewer';

        try {
            acceptArticle($id, $assignedTo);
            echo json_encode(['ok' => true, 'assignedTo' => $assignedTo]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => 'Erreur base de données: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'reject_article') {
        $id     = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $email  = htmlspecialchars($_POST['email'] ?? '');

        if (!$reason) {
            echo json_encode(['ok' => false, 'msg' => 'Motif du rejet requis.']);
            exit;
        }

        try {
            rejectArticle($id, $reason);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => 'Erreur base de données: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ==================== LOAD DATA FROM DATABASE ====================
$conferences = getAllConferences();
$articles = getAllArticles();
$aiAnalyses = getAllAIAnalyses();
$aiStats = getAIAnalysisStats();

foreach ($conferences as &$conf) {
    $conf['status'] = computeStatus($conf);
}
unset($conf);

$jsConferences = json_encode(array_values($conferences), JSON_UNESCAPED_UNICODE);
$jsArticles    = json_encode(array_values($articles),    JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Gestion des conférences et articles avec IA</title>
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
    --purple: #5b6ef5;
    --shadow-sm: 0 1px 4px rgba(13,33,55,0.07);
    --shadow: 0 4px 20px rgba(13,33,55,0.10);
    --shadow-md: 0 8px 32px rgba(13,33,55,0.15);
    --radius: 8px;
    --radius-sm: 4px;
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
    display: flex;
    align-items: center;
    gap: 10px;
    margin-right: 48px;
    text-decoration: none;
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
  
  .nav-links { display: flex; align-items: center; gap: 4px; flex: 1; }
  
  .nav-link {
    padding: 8px 16px;
    font-size: 14px;
    font-weight: 400;
    color: var(--text-light);
    background: none;
    border: none;
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    transition: all 0.15s;
    position: relative;
    text-decoration: none;
    display: inline-block;
  }
  
  .nav-link:hover { color: var(--navy); background: var(--bg); }
  .nav-link.active { color: var(--gold); font-weight: 500; }
  .nav-link.active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 16px;
    right: 16px;
    height: 2px;
    background: var(--gold);
    border-radius: 2px 2px 0 0;
  }
  
  .topbar-right { display: flex; align-items: center; gap: 12px; }
  .search-wrap { position: relative; margin-right: 8px; }
  .search-input {
    width: 240px;
    padding: 8px 14px 8px 36px;
    border: 1px solid var(--border);
    border-radius: 20px;
    font-size: 13.5px;
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    outline: none;
    transition: all 0.15s;
  }
  .search-input::placeholder { color: var(--muted); }
  .search-input:focus {
    border-color: var(--accent);
    background: var(--white);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.10);
    width: 280px;
  }
  .search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    font-size: 13px;
    pointer-events: none;
  }
  
  .logout-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 18px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 13.5px;
    font-weight: 500;
    color: var(--text-light);
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    transition: all 0.15s;
    text-decoration: none;
  }
  .logout-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--white); }

  .page { max-width: 1400px; margin: 0 auto; padding: 40px 32px 60px; }
  .page-header { margin-bottom: 28px; }
  .page-header-row {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 6px;
  }
  .page-header h1 {
    font-family: 'Libre Baskerville', serif;
    font-size: 32px;
    font-weight: 700;
    color: var(--navy);
    letter-spacing: -0.5px;
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
  }
  
  .stat-icon {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
  }
  .stat-icon.active   { background: rgba(42,157,143,0.1);  color: var(--success); }
  .stat-icon.upcoming { background: rgba(212,131,10,0.1);  color: var(--warning); }
  .stat-icon.closed   { background: rgba(122,143,166,0.1); color: var(--muted); }
  .stat-icon.total    { background: rgba(44,111,173,0.1);  color: var(--accent); }
  
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

  .toolbar-right { display: flex; gap: 10px; align-items: center; }
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

  .conference-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
  }
  
  .conf-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    transition: all 0.2s;
    box-shadow: var(--shadow-sm);
  }
  .conf-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
  
  .conf-card-header {
    padding: 20px;
    border-bottom: 1px solid var(--border);
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%);
    color: white;
    position: relative;
  }
  .conf-card-header h3 {
    font-size: 17px;
    font-weight: 600;
    margin-bottom: 6px;
    font-family: 'Libre Baskerville', serif;
    padding-right: 80px;
  }
  .conf-card-header .conf-type {
    font-size: 12px;
    opacity: 0.8;
    display: inline-block;
    background: rgba(255,255,255,0.2);
    padding: 3px 10px;
    border-radius: 20px;
  }
  .conf-status {
    position: absolute;
    top: 20px;
    right: 20px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
  }
  .conf-status.active   { background: var(--success); color: white; }
  .conf-status.upcoming { background: var(--warning);  color: var(--navy); }
  .conf-status.closed   { background: var(--muted);    color: white; }

  .conf-card-body { padding: 18px 20px; }

  .dates-block {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 14px;
  }
  .date-item {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 8px 10px;
  }
  .date-item-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--muted);
    margin-bottom: 3px;
    font-weight: 600;
  }
  .date-item-value { font-size: 12.5px; font-weight: 600; color: var(--navy); }
  .date-item-value.range { font-size: 11.5px; }
  .date-item.full-width { grid-column: 1 / -1; }
  .date-item.highlight-deadline {
    border-color: rgba(201,168,76,0.4);
    background: rgba(201,168,76,0.06);
  }
  .date-item.highlight-deadline .date-item-label { color: var(--gold); }
  .date-item.highlight-deadline .date-item-value { color: var(--navy); }

  .conf-stats {
    margin: 14px 0;
    padding: 12px 0;
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
  }
  .stat-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; }
  .stat-row:last-child { margin-bottom: 0; }
  .stat-label-stat { color: var(--muted); }
  .stat-value-stat { font-weight: 600; color: var(--navy); }
  .progress-label {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: var(--muted);
    margin-bottom: 5px;
  }
  .progress-bar { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; }
  .progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--navy), var(--gold));
    border-radius: 3px;
  }

  .topics-container {
    margin-top: 12px;
    padding-top: 8px;
    border-top: 1px solid var(--border);
  }
  
  .topics-label {
    font-size: 11px;
    color: var(--muted);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 5px;
  }
  
  .topics-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }
  
  .topic-tag {
    background: var(--bg);
    padding: 3px 10px;
    border-radius: 15px;
    font-size: 11px;
    color: var(--text-light);
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }
  
  .topic-tag i {
    font-size: 9px;
    color: var(--purple);
  }
  
  .no-topics {
    font-size: 11px;
    color: var(--muted);
  }

  .topic-row {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
    align-items: center;
  }
  
  .topic-row .topic-select {
    flex: 1;
    padding: 11px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    color: var(--text);
    background: var(--white);
    transition: all 0.15s;
    outline: none;
    cursor: pointer;
  }
  
  .topic-row .topic-select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.12);
  }
  
  .topic-row .btn-icon {
    width: 36px;
    height: 36px;
    flex-shrink: 0;
    background: none;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.15s;
  }
  
  .topic-row .btn-icon:hover {
    border-color: var(--danger);
    color: var(--danger);
    background: rgba(217,64,64,0.05);
  }

  .submission-status-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    margin-top: 10px;
    padding: 6px 10px;
    border-radius: var(--radius-sm);
    font-weight: 500;
  }
  .submission-status-badge.open    { background: rgba(42,157,143,0.1);  color: var(--success); }
  .submission-status-badge.closed  { background: rgba(217,64,64,0.1);   color: var(--danger); }
  .submission-status-badge.not-yet { background: rgba(212,131,10,0.08); color: var(--warning); }

  .conf-card-footer {
    padding: 14px 20px;
    background: var(--bg);
    display: flex;
    gap: 10px;
    border-top: 1px solid var(--border);
  }
  .btn-sm {
    flex: 1;
    padding: 8px;
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
    text-align: center;
    font-family: 'DM Sans', sans-serif;
    border: none;
  }
  .btn-outline {
    background: transparent;
    border: 1px solid var(--border) !important;
    color: var(--text-light);
  }
  .btn-outline:hover { border-color: var(--navy) !important; color: var(--navy); background: var(--white); }
  .btn-outline:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    border-color: var(--border) !important;
    color: var(--muted);
  }
  .btn-primary-sm { background: var(--navy); border: none; color: var(--gold-light); }
  .btn-primary-sm:hover { background: var(--navy-mid); }

  .articles-section {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-top: 20px;
    overflow: hidden;
    animation: slideDown 0.3s ease;
  }
  @keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  
  .articles-header {
    background: var(--navy);
    color: white;
    padding: 16px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .articles-header h2 { font-family: 'Libre Baskerville', serif; font-size: 18px; }
  .articles-header .close-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-size: 16px;
    transition: all 0.15s;
  }
  .articles-header .close-btn:hover { background: rgba(255,255,255,0.3); }
  
  .articles-toolbar {
    padding: 16px 24px;
    background: var(--bg);
    border-bottom: 1px solid var(--border);
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
  }
  .articles-content { padding: 0; }

  .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
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
  
  .article-title { font-weight: 600; color: var(--navy); margin-bottom: 4px; }
  .article-meta  { font-size: 11px; color: var(--muted); }

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
  .badge.new      { background: #f0f4ff; color: #3347bb; border: 1px solid #bcc7fa; }
  .badge.new .badge-dot { background: var(--purple); }
  .badge.review   { background: #fef8ec; color: #9a5f00; border: 1px solid #f5d98a; }
  .badge.review .badge-dot { background: var(--warning); }
  .badge.accepted { background: #e8f6f3; color: #1a5f57; border: 1px solid #9dd8d0; }
  .badge.accepted .badge-dot { background: var(--success); }
  .badge.rejected { background: #fdf2f2; color: #8b2020; border: 1px solid #f5b8b8; }
  .badge.rejected .badge-dot { background: var(--danger); }
  .badge.assigned { background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; }
  .badge.assigned .badge-dot { background: var(--purple); }

  .actions { display: flex; gap: 6px; align-items: center; }
  .action-btn {
    width: 32px;
    height: 32px;
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
  .action-btn:hover        { border-color: var(--navy);    color: var(--navy); background: var(--bg); }
  .action-btn.danger:hover { border-color: var(--danger);  color: var(--danger); }
  .action-btn.success:hover{ border-color: var(--success); color: var(--success); }

  .btn-primary {
    background: var(--navy);
    color: var(--gold-light);
    border: none;
    border-radius: var(--radius-sm);
    padding: 10px 22px;
    font-size: 13.5px;
    font-weight: 500;
    font-family: 'DM Sans', sans-serif;
    cursor: pointer;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
  }
  .btn-primary:hover { background: var(--navy-mid); transform: translateY(-1px); box-shadow: var(--shadow); }
  .btn-secondary {
    background: none;
    color: var(--text-light);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 9px 18px;
    font-size: 13px;
    font-family: 'DM Sans', sans-serif;
    cursor: pointer;
    transition: all 0.15s;
  }
  .btn-secondary:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }

  .modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(13,33,55,0.5);
    backdrop-filter: blur(4px);
    z-index: 200;
    display: none;
    align-items: center;
    justify-content: center;
  }
  .modal-backdrop.open { display: flex; }
  .modal {
    background: var(--white);
    border-radius: var(--radius);
    width: 100%;
    max-width: 820px;
    box-shadow: var(--shadow-md);
    animation: fadeUp 0.2s ease;
    max-height: 92vh;
    overflow-y: auto;
  }
  .modal.large { max-width: 1000px; }
  .modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    background: var(--white);
    z-index: 10;
  }
  .modal-title { font-family: 'Libre Baskerville', serif; font-size: 18px; color: var(--navy); }
  .modal-close {
    width: 30px;
    height: 30px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: none;
    color: var(--muted);
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .modal-close:hover { background: var(--bg); color: var(--navy); }
  .modal-body { padding: 24px; }
  .modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    position: sticky;
    bottom: 0;
    background: var(--white);
    z-index: 10;
  }

  .form-section { margin-bottom: 24px; }
  .form-section-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .form-section-title i { color: var(--gold); }
  .form-grid { display: grid; gap: 16px; }
  .form-grid.cols-2 { grid-template-columns: 1fr 1fr; }
  .form-group { display: flex; flex-direction: column; gap: 6px; }
  .field-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
  }
  .field-required { color: var(--danger); margin-left: 3px; }
  .form-input, .form-select, .form-textarea {
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
  .form-textarea { resize: vertical; min-height: 80px; }
  .notice-box {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 12px 16px;
    font-size: 12.5px;
    color: var(--muted);
    line-height: 1.6;
    display: flex;
    gap: 10px;
    align-items: flex-start;
  }
  .field-hint { font-size: 11px; color: var(--muted); margin-top: -2px; font-style: italic; }

  .article-detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
  }
  .detail-card {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 16px;
  }
  .detail-label {
    font-size: 11px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
  }
  .detail-value { font-size: 14px; color: var(--text); font-weight: 500; }
  .abstract-box {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 16px;
    margin: 16px 0;
    line-height: 1.6;
  }
  .pdf-viewer {
    width: 100%;
    height: 400px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    margin-top: 16px;
  }

  .pagination { display: flex; justify-content: center; gap: 8px; margin: 20px; }
  .page-btn {
    padding: 8px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--white);
    color: var(--text-light);
    cursor: pointer;
    font-size: 13px;
    transition: all 0.15s;
  }
  .page-btn:hover  { border-color: var(--navy); color: var(--navy); }
  .page-btn.active { background: var(--navy); color: var(--gold-light); border-color: var(--navy); }
  .page-btn:disabled { opacity: 0.5; cursor: not-allowed; }

  .footer {
    background: var(--navy);
    color: rgba(255,255,255,0.45);
    text-align: center;
    padding: 22px;
    margin-top: 48px;
    font-size: 13px;
  }

  .hidden { display: none !important; }
  @keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }

  /* AI Analysis Section Styles */
  .ai-analysis-section {
    margin-top: 60px;
    margin-bottom: 40px;
    animation: fadeIn 0.5s ease-out;
  }
  
  @keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
  }
  
  .ai-stats-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 16px;
    margin-bottom: 30px;
  }
  
  .risk-badge {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  
  .risk-clean {
    background: #d1fae5;
    color: #065f46;
  }
  
  .risk-medium {
    background: #fed7aa;
    color: #92400e;
  }
  
  .risk-high {
    background: #fee2e2;
    color: #991b1b;
  }
  
  .risk-pending {
    background: #f1f5f9;
    color: #475569;
  }
  
  .score-indicator {
    display: inline-flex;
    align-items: center;
    gap: 10px;
  }
  
  .score-bar {
    width: 80px;
    height: 6px;
    background: #e2e8f0;
    border-radius: 3px;
    overflow: hidden;
  }
  
  .score-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s ease;
  }
  
  .ai-table-container {
    background: white;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
  }
  
  .filter-select {
    padding: 8px 15px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: white;
    cursor: pointer;
  }
  
  .ai-score {
    font-weight: 600;
    font-size: 14px;
  }
  
  .ai-score .low { color: var(--success); }
  .ai-score .medium { color: var(--warning); }
  .ai-score .high { color: var(--danger); }

  @media (max-width: 900px) {
    .stats-row, .ai-stats-grid { grid-template-columns: repeat(2, 1fr); }
    .form-grid.cols-2, .article-detail-grid { grid-template-columns: 1fr; }
    .topbar { padding: 0 16px; }
    .nav-links { display: none; }
    .page { padding: 24px 16px; }
    .dates-block { grid-template-columns: 1fr; }
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
    <a href="admin_dashboard.php" class="nav-link active">Conférences &amp; Articles</a>
    <a href="evaluators.php" class="nav-link">Évaluateurs</a>
    <a href="final_decisions.php" class="nav-link">Décision Finale</a>
    <a href="admin_profile.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <div class="search-wrap">
      <input type="text" class="search-input" id="searchConference" placeholder="Rechercher une conférence...">
    </div>
    <a href="logout.php" class="logout-btn"><span>↪</span> Déconnexion</a>
  </div>
</header>

<main class="page">
  <!-- CONFERENCES LIST VIEW -->
  <div id="conferencesView">
    <div class="page-header">
      <div class="page-header-row">
        <h1>Gestion <em>des conférences</em></h1>
        <div style="display: flex; gap: 10px;">
          <button class="btn-primary" id="openCreateConfModal">
            <i class="fas fa-plus"></i> Créer une conférence
          </button>
          <button class="btn-primary" id="openAddTopicBtn" style="background: var(--purple);">
            <i class="fas fa-tag"></i> Ajouter un thème
          </button>
        </div>
      </div>
      <p>Cliquez sur "Voir les articles" pour gérer et évaluer les soumissions</p>
    </div>

    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon active"><i class="fas fa-calendar-check"></i></div>
        <div><div class="stat-value" id="activeCount">0</div><div class="stat-label">Conférences actives</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon upcoming"><i class="fas fa-hourglass-half"></i></div>
        <div><div class="stat-value" id="upcomingCount">0</div><div class="stat-label">À venir</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon closed"><i class="fas fa-check-circle"></i></div>
        <div><div class="stat-value" id="closedCount">0</div><div class="stat-label">Clôturées</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon total"><i class="fas fa-file-alt"></i></div>
        <div><div class="stat-value" id="totalArticlesCount">0</div><div class="stat-label">Total articles</div></div>
      </div>
    </div>

    <div class="filters-bar">
      <div class="filter-tabs">
        <button class="filter-tab active" data-filter="all">Toutes <span class="filter-count" id="allCount">0</span></button>
        <button class="filter-tab" data-filter="active">Actives <span class="filter-count" id="activeFilterCount">0</span></button>
        <button class="filter-tab" data-filter="upcoming">À venir <span class="filter-count" id="upcomingFilterCount">0</span></button>
        <button class="filter-tab" data-filter="closed">Clôturées <span class="filter-count" id="closedFilterCount">0</span></button>
      </div>
      <div class="toolbar-right">
        <select class="sort-select" id="sortSelect">
          <option value="date-desc">Plus récent</option>
          <option value="date-asc">Plus ancien</option>
          <option value="name">Nom A→Z</option>
          <option value="articles">Articles (desc)</option>
        </select>
      </div>
    </div>

    <div class="conference-grid" id="conferenceGrid"></div>
    <div class="pagination" id="confPagination"></div>
  </div>

  <!-- ARTICLES VIEW -->
  <div id="articlesView" class="hidden">
    <div class="page-header">
      <div class="page-header-row">
        <button class="btn-secondary" onclick="backToConferences()">
          <i class="fas fa-arrow-left"></i> Retour aux conférences
        </button>
        <h1 id="articlesTitle">Articles <em>soumis</em></h1>
      </div>
      <p id="articlesSubtitle">Gérez et évaluez les articles de cette conférence</p>
    </div>

    <div class="articles-section">
      <div class="articles-header">
        <h2><i class="fas fa-folder-open"></i> <span id="currentConfName">Conférence</span></h2>
        <button class="close-btn" onclick="backToConferences()"><i class="fas fa-times"></i></button>
      </div>
      <div class="articles-toolbar">
        <div class="search-wrap" style="flex:1;max-width:300px;">
          <span class="search-icon">🔍</span>
          <input type="text" class="search-input" id="searchArticle" placeholder="Rechercher un article..." style="width:100%;">
        </div>
        <select class="sort-select" id="articleSortSelect">
          <option value="date-desc">Plus récent</option>
          <option value="date-asc">Plus ancien</option>
          <option value="title">Titre A→Z</option>
        </select>
      </div>
      <div class="articles-content">
        <table class="data-table">
          <thead>
            <tr>
              <th>Article / Auteur</th>
              <th>Date de soumission</th>
              <th>Statut</th>
              <th>Score IA</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="articlesTableBody"></tbody>
        </table>
      </div>
      <div class="pagination" id="articlePagination"></div>
    </div>
  </div>

  <!-- ==================== AI ANALYSIS SECTION ==================== -->
  <div class="ai-analysis-section">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
      <h2 style="font-family: 'Libre Baskerville', serif; font-size: 24px; color: var(--navy);">
       
        Analyse IA des articles - Détection de contenu généré par IA
      </h2>
      <button class="btn-primary" onclick="refreshAIScores()" style="padding: 8px 20px;">
        <i class="fas fa-sync-alt"></i> Analyser les articles en attente
      </button>
    </div>
    
    <div class="ai-stats-grid">
      <div class="stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
          <div>
            <div style="font-size: 28px; font-weight: 700;" id="totalAnalyzed"><?= $aiStats['analyzed'] ?></div>
            <div style="font-size: 12px; opacity: 0.9;">Articles analysés</div>
          </div>
          <i class="fas fa-chart-line" style="font-size: 32px; opacity: 0.8;"></i>
        </div>
      </div>
      <div class="stat-card" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
          <div>
            <div style="font-size: 28px; font-weight: 700;" id="cleanCount"><?= $aiStats['clean'] ?></div>
            <div style="font-size: 12px; opacity: 0.9;">Niveau Clean (&lt;30%)</div>
          </div>
          <i class="fas fa-check-circle" style="font-size: 32px; opacity: 0.8;"></i>
        </div>
      </div>
      <div class="stat-card" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white;">
        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
          <div>
            <div style="font-size: 28px; font-weight: 700;" id="mediumCount"><?= $aiStats['medium'] ?></div>
            <div style="font-size: 12px; opacity: 0.9;">Risque moyen (30-60%)</div>
          </div>
          <i class="fas fa-exclamation-triangle" style="font-size: 32px; opacity: 0.8;"></i>
        </div>
      </div>
      <div class="stat-card" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white;">
        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
          <div>
            <div style="font-size: 28px; font-weight: 700;" id="highCount"><?= $aiStats['high'] ?></div>
            <div style="font-size: 12px; opacity: 0.9;">Risque élevé (&gt;60%)</div>
          </div>
          <i class="fas fa-times-circle" style="font-size: 32px; opacity: 0.8;"></i>
        </div>
      </div>
      <div class="stat-card" style="background: linear-gradient(135deg, #64748b 0%, #475569 100%); color: white;">
        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
          <div>
            <div style="font-size: 28px; font-weight: 700;" id="pendingCount"><?= $aiStats['pending'] ?></div>
            <div style="font-size: 12px; opacity: 0.9;">En attente d'analyse</div>
          </div>
          <i class="fas fa-hourglass-half" style="font-size: 32px; opacity: 0.8;"></i>
        </div>
      </div>
    </div>

    <div class="ai-table-container">
      <div style="padding: 20px; background: var(--bg); border-bottom: 1px solid var(--border);">
        <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
          <div style="flex: 1; min-width: 250px;">
            <div class="search-wrap" style="position: relative;">
              <span class="search-icon" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%);"></span>
              <input type="text" id="aiSearchInput" class="search-input" placeholder="Rechercher par titre, auteur ou référence..." style="width: 100%; padding-left: 35px;">
            </div>
          </div>
          <select id="riskFilterSelect" class="filter-select">
            <option value="all">Tous les niveaux de risque</option>
            <option value="clean">Clean (&lt;30%)</option>
            <option value="medium">Risque moyen (30-60%)</option>
            <option value="high">Risque élevé (&gt;60%)</option>
            <option value="pending">Non analysé</option>
          </select>
          <select id="conferenceFilterSelect" class="filter-select">
            <option value="all">Toutes les conférences</option>
            <?php
            $confStmt = Database::getConnection()->query("SELECT id, name_fr FROM conferences ORDER BY name_fr");
            while ($conf = $confStmt->fetch(PDO::FETCH_ASSOC)) {
              echo '<option value="' . $conf['id'] . '">' . htmlspecialchars($conf['name_fr']) . '</option>';
            }
            ?>
          </select>
        </div>
      </div>
      
      <div style="overflow-x: auto;">
        <table class="data-table" style="width: 100%;">
          <thead>
            <tr>
              <th>Référence</th>
              <th>Titre de l'article</th>
              <th>Auteur</th>
              <th>Conférence</th>
              <th>Score IA</th>
              <th>Niveau de risque</th>
              <th>Date d'analyse</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="aiAnalysisTableBody">
            <?php if (empty($aiAnalyses)): ?>
              <tr>
                <td colspan="8" style="text-align: center; padding: 60px 20px;">
                  <i class="fas fa-chart-line" style="font-size: 48px; color: var(--muted); margin-bottom: 15px; display: block;"></i>
                  <p style="color: var(--muted);">Aucune analyse IA disponible pour le moment.</p>
                  <p style="color: var(--muted); font-size: 12px; margin-top: 10px;">Les articles seront analysés automatiquement après soumission.</p>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($aiAnalyses as $analysis): ?>
                <?php
                $riskConfig = [
                  'clean' => ['class' => 'risk-clean', 'icon' => 'fa-check-circle', 'text' => 'Clean'],
                  'medium' => ['class' => 'risk-medium', 'icon' => 'fa-exclamation-triangle', 'text' => 'Risque moyen'],
                  'high' => ['class' => 'risk-high', 'icon' => 'fa-times-circle', 'text' => 'Risque élevé'],
                  'pending' => ['class' => 'risk-pending', 'icon' => 'fa-clock', 'text' => 'Non analysé']
                ];
                $risk = $riskConfig[$analysis['risk_level']] ?? $riskConfig['pending'];
                $score = round($analysis['ai_score'] ?? 0);
                $scoreColor = $score < 30 ? '#10b981' : ($score < 60 ? '#f59e0b' : '#ef4444');
                $scoreClass = $score < 30 ? 'low' : ($score < 60 ? 'medium' : 'high');
                ?>
                <tr 
                  data-article-id="<?= $analysis['id'] ?>"
                  data-risk="<?= $analysis['risk_level'] ?? 'pending' ?>"
                  data-conference="<?= $analysis['conference_id'] ?>"
                  data-title="<?= strtolower(htmlspecialchars($analysis['title'] ?? '')) ?>"
                  data-author="<?= strtolower(htmlspecialchars($analysis['author_name'] ?? '')) ?>"
                  data-ref="<?= strtolower(htmlspecialchars($analysis['reference'] ?? '')) ?>">
                  <td><code style="background: var(--bg); padding: 4px 8px; border-radius: 4px; font-size: 12px;"><?= htmlspecialchars($analysis['reference'] ?? 'N/A') ?></code></td>
                  <td><strong><?= htmlspecialchars(substr($analysis['title'] ?? '', 0, 60)) ?><?= strlen($analysis['title'] ?? '') > 60 ? '...' : '' ?></strong></td>
                  <td><?= htmlspecialchars($analysis['author_name'] ?? 'N/A') ?></td>
                  <td><?= htmlspecialchars($analysis['conference_name'] ?? 'N/A') ?></td>
                  <td>
                    <div class="score-indicator">
                      <span class="ai-score <?= $scoreClass ?>" style="font-weight: 600; min-width: 40px;"><?= $score ?>%</span>
                      <div class="score-bar" style="width: 80px;">
                        <div class="score-fill" style="width: <?= $score ?>%; background: <?= $scoreColor ?>;"></div>
                      </div>
                    </div>
                  </td>
                  <td><span class="risk-badge <?= $risk['class'] ?>"><i class="fas <?= $risk['icon'] ?>"></i> <?= $risk['text'] ?></span></td>
                  <td style="font-size: 12px; color: var(--muted);"><?= $analysis['ai_analysis_date'] ? date('d/m/Y H:i', strtotime($analysis['ai_analysis_date'])) : '—' ?></td>
                  <td>
                    <button class="action-btn" onclick="viewAIAnalysisDetails(<?= $analysis['id'] ?>)" title="Voir détails">
                      <i class="fas fa-eye"></i>
                    </button>
                   
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<footer class="footer">
  © <?= date('Y') ?> ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés
</footer>

<!-- MODALS -->
<div id="topicModal" class="modal-backdrop">
  <div class="modal" style="max-width: 500px;">
    <div class="modal-header">
      <div class="modal-title" style="color: var(--purple);">
        <i class="fas fa-tag"></i> Ajouter un thème
      </div>
      <button class="modal-close" onclick="closeModal('topicModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form id="topicForm">
        <div class="form-group">
          <label class="field-label">Conférence <span class="field-required">*</span></label>
          <select class="form-select" id="topicConferenceId" required>
            <option value="">-- Sélectionnez une conférence --</option>
            <?php
            $confStmt = Database::getConnection()->query("SELECT id, name_fr FROM conferences ORDER BY name_fr");
            while ($conf = $confStmt->fetch(PDO::FETCH_ASSOC)) {
              echo '<option value="' . $conf['id'] . '">' . htmlspecialchars($conf['name_fr']) . '</option>';
            }
            ?>
          </select>
        </div>
        <div class="form-group">
          <label class="field-label">Thème / Domaine <span class="field-required">*</span></label>
          <input type="text" class="form-input" id="topicName" placeholder="Ex: Intelligence Artificielle, Machine Learning, IoT..." required>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('topicModal')">Annuler</button>
      <button class="btn-primary" id="saveTopicBtn" style="background: var(--purple);">
        <i class="fas fa-save"></i> Enregistrer le thème
      </button>
    </div>
  </div>
</div>

<div id="confModal" class="modal-backdrop">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="modalTitle">Créer une conférence</div>
      <button class="modal-close" onclick="closeModal('confModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form id="conferenceForm">
        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-info-circle"></i> Informations générales</div>
          <div class="form-grid cols-2">
            <div class="form-group">
              <label class="field-label">Nom (français) <span class="field-required">*</span></label>
              <input type="text" class="form-input" id="confNameFr" placeholder="ex: Conférence Internationale sur l'IA">
            </div>
            <div class="form-group">
              <label class="field-label">Nom (anglais) <span class="field-required">*</span></label>
              <input type="text" class="form-input" id="confNameEn" placeholder="ex: International Conference on AI">
            </div>
            <div class="form-group">
              <label class="field-label">Type d'événement</label>
              <select class="form-select" id="confType">
                <option value="Conférence">Conférence</option>
                <option value="Séminaire">Séminaire</option>
                <option value="Colloque">Colloque</option>
              </select>
            </div>
            <div class="form-group">
              <label class="field-label">Disciplines acceptées</label>
              <input type="text" class="form-input" id="confDisciplines" placeholder="Informatique, IA, Éducation...">
            </div>
            <div class="form-group">
              <label class="field-label">Organisateur</label>
              <input type="text" class="form-input" id="confOrganizer" placeholder="Université / Institut">
            </div>
            <div class="form-group">
              <label class="field-label">Lieu</label>
              <input type="text" class="form-input" id="confLocation" placeholder="Ville, Pays">
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-calendar-alt"></i> Dates de la conférence</div>
          <div class="form-grid cols-2">
            <div class="form-group">
              <label class="field-label">Date de début <span class="field-required">*</span></label>
              <input type="date" class="form-input" id="confStartDate">
            </div>
            <div class="form-group">
              <label class="field-label">Date de fin <span class="field-required">*</span></label>
              <input type="date" class="form-input" id="confEndDate">
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-file-upload"></i> Soumission des articles</div>
          <div class="form-grid cols-2">
            <div class="form-group">
              <label class="field-label">Date d'ouverture des soumissions <span class="field-required">*</span></label>
              <input type="date" class="form-input" id="confSubmissionStartDate">
            </div>
            <div class="form-group">
              <label class="field-label">Date limite de soumission <span class="field-required">*</span></label>
              <input type="date" class="form-input" id="confSubmissionDeadline">
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-search"></i> Période d'évaluation</div>
          <div class="form-grid cols-2">
            <div class="form-group">
              <label class="field-label">Début de l'évaluation <span class="field-required">*</span></label>
              <input type="date" class="form-input" id="confReviewStartDate">
            </div>
            <div class="form-group">
              <label class="field-label">Fin de l'évaluation <span class="field-required">*</span></label>
              <input type="date" class="form-input" id="confReviewEndDate">
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-file-alt"></i> Exigences de publication</div>
          <div class="form-group">
            <textarea class="form-textarea" id="confRequirements" placeholder="Conditions de publication, indexation, etc..."></textarea>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title">
            <i class="fas fa-tags" style="color: var(--purple);"></i> 
            Thèmes de la conférence
          </div>
          <div id="modalTopicsList"></div>
          <button type="button" class="btn-sm btn-outline" onclick="addTopicRow()" style="margin-top: 10px; width: auto; padding: 8px 16px;">
            <i class="fas fa-plus"></i> Ajouter un autre thème
          </button>
          <div class="field-hint" style="margin-top: 8px;">
            <i class="fas fa-info-circle"></i> Sélectionnez un thème existant ou tapez un nouveau thème.
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('confModal')">Annuler</button>
      <button class="btn-primary" id="saveConferenceBtn">Enregistrer</button>
    </div>
  </div>
</div>

<div id="articleDetailModal" class="modal-backdrop">
  <div class="modal large">
    <div class="modal-header">
      <div class="modal-title">Détails de l'article</div>
      <button class="modal-close" onclick="closeModal('articleDetailModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="articleDetailContent"></div>
    <div class="modal-footer">
      <a id="downloadPdfBtn" class="btn-primary" href="#" target="_blank">Télécharger PDF</a>
      <button class="btn-secondary" onclick="closeModal('articleDetailModal')">Fermer</button>
    </div>
  </div>
</div>

<div id="rejectModal" class="modal-backdrop">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" style="color:var(--danger);">Refuser l'article</div>
      <button class="modal-close" onclick="closeModal('rejectModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div id="rejectArticleInfo" style="background:var(--bg);padding:12px;border-radius:var(--radius-sm);margin-bottom:16px;"></div>
      <div class="form-group">
        <label class="field-label">Motif du rejet <span class="field-required">*</span></label>
        <textarea class="form-textarea" id="rejectReason" rows="4" placeholder="Expliquez à l'auteur pourquoi son article est refusé..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('rejectModal')">Annuler</button>
      <button class="btn-primary" style="background:var(--danger);" onclick="confirmReject()">Confirmer le rejet</button>
    </div>
  </div>
</div>

<div id="aiDetailModal" class="modal-backdrop">
  <div class="modal" style="max-width: 600px;">
    <div class="modal-header">
      <div class="modal-title">Analyse IA détaillée</div>
      <button class="modal-close" onclick="closeModal('aiDetailModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="aiDetailContent"></div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('aiDetailModal')">Fermer</button>
    </div>
  </div>
</div>

<script>
  let conferences = <?= $jsConferences ?>;
  let articles = <?= $jsArticles ?>;
  
  let currentFilter = 'all';
  let currentSort = 'date-desc';
  let currentPage = 1;
  const itemsPerPage = 6;
  let editId = null;
  let currentConferenceId = null;
  let currentArticleId = null;
  let articleSearchTerm = '';
  let articleSort = 'date-desc';
  let articlePage = 1;
  const articlesPerPage = 5;
  let aiSearchTimeout = null;
  
  const statusMapping = {
    'en_attente': 'new',
    'en_evaluation': 'review',
    'accepte': 'accepted',
    'refuse': 'rejected',
    'assigne': 'assigned',
    'revised': 'revision',
    'done': 'accepted',
    'decided': 'accepted'
  };
  
  function mapArticleStatus(article) {
    if (article.status && statusMapping[article.status]) {
      article.status = statusMapping[article.status];
    }
    return article;
  }
  
  articles = articles.map(mapArticleStatus);
  
  function fmt(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('fr-FR', { day:'2-digit', month:'short', year:'numeric' });
  }
  
  function computeStatus(conf) {
    const today = new Date(); today.setHours(0,0,0,0);
    const subStart = conf.submissionStartDate ? new Date(conf.submissionStartDate) : null;
    const subEnd = conf.submissionDeadline ? new Date(conf.submissionDeadline) : null;
    if (subEnd && today > subEnd) return 'closed';
    if (subStart && today >= subStart) return 'active';
    return 'upcoming';
  }
  
  function submissionWindowStatus(conf) {
    const today = new Date(); today.setHours(0,0,0,0);
    const subStart = conf.submissionStartDate ? new Date(conf.submissionStartDate) : null;
    const subEnd = conf.submissionDeadline ? new Date(conf.submissionDeadline) : null;
    if (subEnd && today > subEnd) return 'closed';
    if (subStart && today < subStart) return 'not-yet';
    return 'open';
  }
  
  function updateConferenceStatuses() {
    conferences.forEach(c => { c.status = computeStatus(c); });
  }
  
  function isEditAllowed(conf) {
    if (!conf.submissionStartDate) return true;
    const today = new Date(); today.setHours(0,0,0,0);
    return today <= new Date(conf.submissionStartDate);
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
  
  function getAllUniqueTopics() {
    const allTopics = new Set();
    conferences.forEach(conference => {
      if (conference.topics && conference.topics.trim() !== '') {
        conference.topics.split(', ').forEach(topic => {
          const trimmedTopic = topic.trim();
          if (trimmedTopic) allTopics.add(trimmedTopic);
        });
      }
    });
    return Array.from(allTopics).sort((a, b) => a.localeCompare(b));
  }
  
  function createTopicSelect(selectedValue = '') {
    const uniqueTopics = getAllUniqueTopics();
    const select = document.createElement('select');
    select.className = 'topic-select';
    select.name = 'topics[]';
    
    const placeholderOption = document.createElement('option');
    placeholderOption.value = '';
    placeholderOption.textContent = '-- Sélectionnez ou tapez un thème --';
    placeholderOption.disabled = true;
    placeholderOption.selected = !selectedValue;
    select.appendChild(placeholderOption);
    
    uniqueTopics.forEach(topic => {
      const option = document.createElement('option');
      option.value = topic;
      option.textContent = topic;
      if (selectedValue === topic) option.selected = true;
      select.appendChild(option);
    });
    
    const customOption = document.createElement('option');
    customOption.value = '__custom__';
    customOption.textContent = '+ Ajouter un nouveau thème...';
    customOption.style.color = 'var(--purple)';
    customOption.style.fontWeight = 'bold';
    select.appendChild(customOption);
    
    select.addEventListener('change', function() {
      if (this.value === '__custom__') {
        const customValue = prompt('Entrez le nouveau thème:', '');
        if (customValue && customValue.trim()) {
          const newTopic = customValue.trim();
          const existingOption = Array.from(this.options).find(opt => opt.value === newTopic);
          if (!existingOption) {
            const newOption = document.createElement('option');
            newOption.value = newTopic;
            newOption.textContent = newTopic;
            this.insertBefore(newOption, customOption);
            newOption.selected = true;
          } else {
            existingOption.selected = true;
          }
        } else {
          this.value = '';
        }
      }
    });
    
    return select;
  }
  
  function addTopicRow(selectedValue = '') {
    const topicsList = document.getElementById('modalTopicsList');
    const newRow = document.createElement('div');
    newRow.className = 'topic-row';
    
    const select = createTopicSelect(selectedValue);
    newRow.appendChild(select);
    
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn-icon';
    removeBtn.innerHTML = '<i class="fas fa-trash"></i>';
    removeBtn.onclick = function() { removeTopicRow(this); };
    newRow.appendChild(removeBtn);
    
    topicsList.appendChild(newRow);
    
    const rows = topicsList.querySelectorAll('.topic-row');
    rows.forEach(row => {
      const btn = row.querySelector('.btn-icon');
      if (btn) btn.style.display = rows.length === 1 ? 'none' : 'flex';
    });
    
    return newRow;
  }
  
  function removeTopicRow(button) {
    const row = button.closest('.topic-row');
    const topicsList = document.getElementById('modalTopicsList');
    if (topicsList.querySelectorAll('.topic-row').length > 1) {
      row.remove();
    }
    const rows = topicsList.querySelectorAll('.topic-row');
    rows.forEach(row => {
      const btn = row.querySelector('.btn-icon');
      if (btn) btn.style.display = rows.length === 1 ? 'none' : 'flex';
    });
  }
  
  function resetTopicsList() {
    const topicsList = document.getElementById('modalTopicsList');
    topicsList.innerHTML = '';
    addTopicRow('');
  }
  
  function loadExistingTopics(topicsArray) {
    const topicsList = document.getElementById('modalTopicsList');
    topicsList.innerHTML = '';
    if (topicsArray && topicsArray.length > 0) {
      topicsArray.forEach(topic => addTopicRow(topic));
    } else {
      addTopicRow('');
    }
  }
  
  function getFilteredConferences() {
    let filtered = [...conferences];
    if (currentFilter !== 'all') filtered = filtered.filter(c => c.status === currentFilter);
    const q = document.getElementById('searchConference')?.value.toLowerCase() || '';
    if (q) filtered = filtered.filter(c => (c.nameFr || '').toLowerCase().includes(q) || (c.nameEn || '').toLowerCase().includes(q));
    switch (currentSort) {
      case 'date-desc': filtered.sort((a,b) => new Date(b.createdAt) - new Date(a.createdAt)); break;
      case 'date-asc': filtered.sort((a,b) => new Date(a.createdAt) - new Date(b.createdAt)); break;
      case 'name': filtered.sort((a,b) => (a.nameFr || '').localeCompare(b.nameFr || '')); break;
      case 'articles': filtered.sort((a,b) => (b.articlesCount || 0) - (a.articlesCount || 0)); break;
    }
    return filtered;
  }
  
  function renderStats() {
    const active = conferences.filter(c => c.status === 'active').length;
    const upcoming = conferences.filter(c => c.status === 'upcoming').length;
    const closed = conferences.filter(c => c.status === 'closed').length;
    document.getElementById('activeCount').textContent = active;
    document.getElementById('upcomingCount').textContent = upcoming;
    document.getElementById('closedCount').textContent = closed;
    document.getElementById('totalArticlesCount').textContent = articles.length;
    document.getElementById('allCount').textContent = conferences.length;
    document.getElementById('activeFilterCount').textContent = active;
    document.getElementById('upcomingFilterCount').textContent = upcoming;
    document.getElementById('closedFilterCount').textContent = closed;
  }
  
  function renderPagination(totalItems, containerId, currentPageNum, callback) {
    const perPage = containerId === 'confPagination' ? itemsPerPage : articlesPerPage;
    const total = Math.ceil(totalItems / perPage);
    const container = document.getElementById(containerId);
    if (total <= 1) { container.innerHTML = ''; return; }
    
    let html = `<button class="page-btn" onclick="${callback.name}(${currentPageNum-1})" ${currentPageNum===1?'disabled':''}><i class="fas fa-chevron-left"></i></button>`;
    for (let i = 1; i <= Math.min(total,5); i++) {
      html += `<button class="page-btn ${currentPageNum===i?'active':''}" onclick="${callback.name}(${i})">${i}</button>`;
    }
    if (total > 5) html += `<span style="padding:8px;">...</span><button class="page-btn" onclick="${callback.name}(${total})">${total}</button>`;
    html += `<button class="page-btn" onclick="${callback.name}(${currentPageNum+1})" ${currentPageNum===total?'disabled':''}><i class="fas fa-chevron-right"></i></button>`;
    container.innerHTML = html;
  }
  
  function changePage(page) { currentPage = page; renderConferences(); }
  
  function renderConferences() {
    const filtered = getFilteredConferences();
    const start = (currentPage - 1) * itemsPerPage;
    const paginated = filtered.slice(start, start + itemsPerPage);
    
    let html = '';
    paginated.forEach(conf => {
      const percent = Math.min(100, ((conf.articlesCount || 0) / (conf.maxArticles || 40)) * 100);
      const statusMap = { active:['active','Active'], upcoming:['upcoming','À venir'], closed:['closed','Clôturée'] };
      const [sc, st] = statusMap[conf.status] || ['closed','Clôturée'];
      const canEdit = isEditAllowed(conf);
      const winStatus = submissionWindowStatus(conf);
      const winLabels = {
        open: ['open', 'fas fa-unlock-alt', 'Soumissions ouvertes'],
        'not-yet': ['not-yet', 'fas fa-hourglass-start', `Ouverture le ${fmt(conf.submissionStartDate)}`],
        closed: ['closed', 'fas fa-lock', 'Soumissions clôturées'],
      };
      const [wc, wi, wl] = winLabels[winStatus];
      
      let topicsHtml = '';
      if (conf.topics && conf.topics.trim() !== '') {
        const topics = conf.topics.split(', ');
        topicsHtml = `
          <div class="topics-container">
            <div class="topics-label"><i class="fas fa-tags" style="color: var(--purple);"></i> Thèmes associés:</div>
            <div class="topics-list">${topics.map(topic => `<span class="topic-tag"><i class="fas fa-tag"></i> ${escapeHtml(topic)}</span>`).join('')}</div>
          </div>`;
      } else {
        topicsHtml = `<div class="topics-container"><div class="topics-label"><i class="fas fa-tags" style="color: var(--purple);"></i> Thèmes associés:</div><div class="no-topics">Aucun thème</div></div>`;
      }
      
      html += `
        <div class="conf-card" data-id="${conf.id}">
          <div class="conf-card-header">
            <h3>${escapeHtml(conf.nameFr || '')}</h3>
            <div class="conf-type">${escapeHtml(conf.type || 'Conférence')}</div>
            <span class="conf-status ${sc}">${st}</span>
          </div>
          <div class="conf-card-body">
            <div class="dates-block">
              <div class="date-item full-width" style="background:rgba(13,33,55,0.04);border-color:rgba(13,33,55,0.12);">
                <div class="date-item-label"><i class="fas fa-calendar-days" style="color:var(--navy);margin-right:4px;"></i>Dates de la conférence</div>
                <div class="date-item-value range">${fmt(conf.startDate)} → ${fmt(conf.endDate)}</div>
              </div>
              <div class="date-item highlight-deadline"><div class="date-item-label">Ouverture soumissions</div><div class="date-item-value">${fmt(conf.submissionStartDate)}</div></div>
              <div class="date-item highlight-deadline"><div class="date-item-label">Clôture soumissions</div><div class="date-item-value">${fmt(conf.submissionDeadline)}</div></div>
              <div class="date-item full-width"><div class="date-item-label"><i class="fas fa-magnifying-glass" style="color:var(--purple);margin-right:4px;"></i>Période d'évaluation</div><div class="date-item-value range">${fmt(conf.reviewStartDate)} → ${fmt(conf.reviewEndDate)}</div></div>
            </div>
            <div class="conf-stats">
              <div class="stat-row"><span class="stat-label-stat">Disciplines</span><span class="stat-value-stat">${escapeHtml(conf.disciplines || '—')}</span></div>
              <div class="stat-row"><span class="stat-label-stat">Organisateur</span><span class="stat-value-stat">${escapeHtml(conf.organizer || '—')}</span></div>
              <div class="stat-row"><span class="stat-label-stat">Lieu</span><span class="stat-value-stat">${escapeHtml(conf.location || '—')}</span></div>
              <div class="progress-label" style="margin-top:8px;"><span>Articles reçus</span><span>${conf.articlesCount || 0} / ${conf.maxArticles || 40}</span></div>
              <div class="progress-bar"><div class="progress-fill" style="width:${percent}%"></div></div>
            </div>
            ${topicsHtml}
            <div class="submission-status-badge ${wc}"><i class="${wi}"></i> ${wl}</div>
            <div style="font-size:11px;color:var(--muted);margin-top:6px;display:flex;align-items:center;gap:5px;">
              <i class="fas fa-${canEdit ? 'pencil' : 'ban'}" style="color:${canEdit ? 'var(--success)' : 'var(--danger)'}"></i>
              ${canEdit ? `Modification possible jusqu'au ${fmt(conf.submissionStartDate)}` : `Modification fermée depuis le ${fmt(conf.submissionStartDate)}`}
            </div>
          </div>
          <div class="conf-card-footer">
            <button class="btn-sm btn-outline" onclick="editConference(${conf.id})" ${!canEdit ? 'disabled title="Délai de modification dépassé"' : ''}>
              <i class="fas fa-edit"></i> Modifier
            </button>
            <button class="btn-sm btn-primary-sm" onclick="viewConferenceArticles(${conf.id})">
              <i class="fas fa-folder-open"></i> Voir les articles (${conf.articlesCount || 0})
            </button>
          </div>
        </div>`;
    });
    
    document.getElementById('conferenceGrid').innerHTML = html || '<div style="text-align:center;padding:40px;color:var(--muted);">Aucune conférence trouvée.</div>';
    renderPagination(filtered.length, 'confPagination', currentPage, changePage);
  }
  
  function viewConferenceArticles(confId) {
    currentConferenceId = confId;
    const conf = conferences.find(c => c.id === confId);
    if (conf) {
      document.getElementById('currentConfName').textContent = conf.nameFr || '';
      document.getElementById('articlesTitle').innerHTML = `Articles <em>${escapeHtml(conf.nameFr || '')}</em>`;
    }
    document.getElementById('conferencesView').classList.add('hidden');
    document.getElementById('articlesView').classList.remove('hidden');
    articlePage = 1; articleSearchTerm = '';
    document.getElementById('searchArticle').value = '';
    renderArticles();
  }
  
  function backToConferences() {
    document.getElementById('articlesView').classList.add('hidden');
    document.getElementById('conferencesView').classList.remove('hidden');
    currentConferenceId = null;
    renderConferences();
  }
  
  function getFilteredArticles() {
    let filtered = articles.filter(a => a.conferenceId === currentConferenceId);
    if (articleSearchTerm) {
      filtered = filtered.filter(a => (a.title || '').toLowerCase().includes(articleSearchTerm) || (a.author || '').toLowerCase().includes(articleSearchTerm));
    }
    switch (articleSort) {
      case 'date-desc': filtered.sort((a,b) => new Date(b.submissionDate) - new Date(a.submissionDate)); break;
      case 'date-asc': filtered.sort((a,b) => new Date(a.submissionDate) - new Date(b.submissionDate)); break;
      case 'title': filtered.sort((a,b) => (a.title || '').localeCompare(b.title || '')); break;
    }
    return filtered;
  }
  
  function renderArticles() {
    const filtered = getFilteredArticles();
    const start = (articlePage - 1) * articlesPerPage;
    const paginated = filtered.slice(start, start + articlesPerPage);
    
    const badgeText = { new:'Nouveau', review:'En évaluation', accepted:'Accepté', rejected:'Refusé', revision:'Révision', assigned:'Assigné' };
    
    let html = '';
    paginated.forEach(article => {
      const label = badgeText[article.status] || article.status || 'Nouveau';
      let actionButtons = '';
      
      let aiScoreHtml = '';
      if (article.ai_score !== null && article.ai_score !== undefined) {
        const score = Math.round(article.ai_score);
        const scoreClass = score < 30 ? 'low' : (score < 60 ? 'medium' : 'high');
        const scoreColor = score < 30 ? '#10b981' : (score < 60 ? '#f59e0b' : '#ef4444');
        aiScoreHtml = `<div class="score-indicator"><span class="ai-score ${scoreClass}" style="font-size:12px;">${score}%</span><div class="score-bar" style="width:50px;"><div class="score-fill" style="width:${score}%; background:${scoreColor}"></div></div></div>`;
      } else {
        aiScoreHtml = '<span style="font-size:11px;color:var(--muted);"><i class="fas fa-clock"></i> En attente</span>';
      }
      
      if (article.status === 'accepted' || article.status === 'assigned') {
        actionButtons = `<button class="action-btn" onclick="viewArticleDetails(${article.id})" title="Voir détails"><i class="fas fa-eye"></i></button>`;
        if (article.assignedTo) actionButtons += `<span style="font-size:11px;color:var(--purple);margin-left:8px;"><i class="fas fa-user-check"></i> ${escapeHtml(article.assignedTo)}</span>`;
      } else if (article.status === 'rejected') {
        actionButtons = `<button class="action-btn" onclick="viewArticleDetails(${article.id})" title="Voir détails"><i class="fas fa-eye"></i></button><span style="font-size:11px;color:var(--danger);margin-left:8px;"><i class="fas fa-ban"></i> Refusé</span>`;
      } else {
        actionButtons = `<button class="action-btn" onclick="viewArticleDetails(${article.id})" title="Voir détails"><i class="fas fa-eye"></i></button><button class="action-btn success" onclick="acceptArticle(${article.id})" title="Accepter"><i class="fas fa-check"></i></button><button class="action-btn danger" onclick="openRejectModal(${article.id})" title="Refuser"><i class="fas fa-times"></i></button>`;
      }
      
      html += `<tr>
        <td><div class="article-title">${escapeHtml(article.title || '')}</div><div class="article-meta">${escapeHtml(article.author || '')} · ${escapeHtml(article.authorInstitution || '')}</div></td>
        <td>${fmt(article.submissionDate)}</div></div></td>
        <td><span class="badge ${article.status || 'new'}"><span class="badge-dot"></span>${label}</span></td>
        <td>${aiScoreHtml}</td>
        <td><div class="actions">${actionButtons}</div></td>
      </tr>`;
    });
    
    document.getElementById('articlesTableBody').innerHTML = html || '<tr><td colspan="5" style="text-align:center;padding:40px;">Aucun article trouvé</td><td style="display:none;"></td><td style="display:none;"></td><td style="display:none;"></td><td style="display:none;"></td></tr>';
    renderPagination(filtered.length, 'articlePagination', articlePage, changeArticlePage);
  }
  
  function changeArticlePage(page) { articlePage = page; renderArticles(); }
  
  function acceptArticle(id) {
    const article = articles.find(a => a.id === id);
    if (!article) return;
    if (!confirm(`Accepter l'article "${article.title}" ?\n\nIl sera assigné à un réviseur du domaine: ${article.domain}`)) return;
    
    const fd = new FormData();
    fd.append('action', 'accept_article');
    fd.append('id', id);
    fd.append('domain', article.domain);
    
    fetch(window.location.href, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (!data.ok) { alert(data.msg || 'Erreur serveur'); return; }
        article.status = 'assigned';
        article.assignedTo = data.assignedTo;
        renderArticles(); updateConferenceStatuses(); renderStats();
        alert(`Article accepté et assigné à ${article.assignedTo}`);
      })
      .catch(() => alert('Erreur de communication avec le serveur.'));
  }
  
  function openRejectModal(id) {
    currentArticleId = id;
    const a = articles.find(x => x.id === id);
    document.getElementById('rejectArticleInfo').innerHTML = `<strong style="color:var(--navy);">${escapeHtml(a.title || '')}</strong><br>
      <span style="font-size:12px;color:var(--muted);">Auteur: ${escapeHtml(a.author || '')} (${escapeHtml(a.authorEmail || '')})</span>`;
    document.getElementById('rejectReason').value = '';
    openModal('rejectModal');
  }
  
  function confirmReject() {
    const article = articles.find(a => a.id === currentArticleId);
    if (!article) return;
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) { alert('Veuillez indiquer le motif du rejet.'); return; }
    
    const fd = new FormData();
    fd.append('action', 'reject_article');
    fd.append('id', currentArticleId);
    fd.append('reason', reason);
    fd.append('email', article.authorEmail);
    
    fetch(window.location.href, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (!data.ok) { alert(data.msg || 'Erreur serveur'); return; }
        article.status = 'rejected';
        article.rejectReason = reason;
        closeModal('rejectModal');
        renderArticles(); updateConferenceStatuses(); renderStats();
        alert(`Article refusé. Un email a été envoyé à ${article.authorEmail}.`);
      })
      .catch(() => alert('Erreur de communication avec le serveur.'));
  }
  
  function viewArticleDetails(id) {
    const article = articles.find(a => a.id === id);
    if (!article) return;
    const conf = conferences.find(c => c.id === article.conferenceId);
    
    let statusHtml = '';
    if (article.status === 'accepted' || article.status === 'assigned') {
      statusHtml = `<span style="color:var(--success);font-weight:600;"><i class="fas fa-check-circle"></i> Accepté${article.assignedTo ? ' — assigné à ' + escapeHtml(article.assignedTo) : ''}</span>`;
    } else if (article.status === 'rejected') {
      statusHtml = `<span style="color:var(--danger);font-weight:600;"><i class="fas fa-times-circle"></i> Refusé</span>
        <div style="margin-top:8px;padding:12px;background:#fdf2f2;border-radius:var(--radius-sm);border-left:3px solid var(--danger);">
          <strong style="font-size:11px;color:var(--danger);">MOTIF DU REJET:</strong><br>
          <span style="font-size:13px;color:var(--text);">${escapeHtml(article.rejectReason)}</span>
        </div>`;
    } else {
      statusHtml = `<span style="color:var(--warning);font-weight:600;"><i class="fas fa-clock"></i> En attente</span>`;
    }
    
    let aiDetailsHtml = '';
    if (article.ai_score !== null && article.ai_score !== undefined) {
      const score = Math.round(article.ai_score);
      const riskText = article.risk_level === 'clean' ? 'Clean (<30%)' : (article.risk_level === 'medium' ? 'Risque moyen (30-60%)' : (article.risk_level === 'high' ? 'Risque élevé (>60%)' : 'Non évalué'));
      const riskColor = article.risk_level === 'clean' ? 'var(--success)' : (article.risk_level === 'medium' ? 'var(--warning)' : 'var(--danger)');
      aiDetailsHtml = `
        <div class="detail-card" style="background:linear-gradient(135deg, #667eea10 0%, #764ba210 100%); border-color: #667eea40;">
          <div class="detail-label"><i class="fas fa-robot"></i> Analyse IA</div>
          <div class="detail-value">
            <div><strong>Score de détection IA:</strong> <span style="color:${riskColor}">${score}%</span></div>
            <div><strong>Niveau de risque:</strong> <span style="color:${riskColor}">${riskText}</span></div>
            <div><strong>Date d'analyse:</strong> ${article.ai_analysis_date ? fmt(article.ai_analysis_date) : '—'}</div>
          </div>
        </div>`;
    } else {
      aiDetailsHtml = `
        <div class="detail-card" style="background:linear-gradient(135deg, #667eea10 0%, #764ba210 100%); border-color: #667eea40;">
          <div class="detail-label"><i class="fas fa-robot"></i> Analyse IA</div>
          <div class="detail-value">
            <span style="color:var(--muted);"><i class="fas fa-clock"></i> Non analysé</span>
            <button class="btn-sm btn-outline" onclick="reanalyzeArticle(${article.id})" style="margin-top:8px;width:auto;padding:4px 12px;">
              <i class="fas fa-play"></i> Analyser maintenant
            </button>
          </div>
        </div>`;
    }
    
    document.getElementById('articleDetailContent').innerHTML = `
      <div class="article-detail-grid">
        <div class="detail-card"><div class="detail-label">Titre</div><div class="detail-value">${escapeHtml(article.title || '')}</div></div>
        <div class="detail-card"><div class="detail-label">Auteur</div><div class="detail-value">${escapeHtml(article.author || '')}</div><div style="font-size:12px;color:var(--muted);margin-top:4px;"><i class="fas fa-envelope"></i> ${escapeHtml(article.authorEmail || '')}<br><i class="fas fa-university"></i> ${escapeHtml(article.authorInstitution || '')}</div></div>
        <div class="detail-card"><div class="detail-label">Conférence</div><div class="detail-value">${escapeHtml(conf ? conf.nameFr : '')}</div></div>
        <div class="detail-card"><div class="detail-label">Date de soumission</div><div class="detail-value">${fmt(article.submissionDate)}</div></div>
        <div class="detail-card"><div class="detail-label">Domaine</div><div class="detail-value">${escapeHtml(article.domain || '')}</div></div>
        <div class="detail-card"><div class="detail-label">Statut</div><div class="detail-value">${statusHtml}</div></div>
        ${aiDetailsHtml}
      </div>
      <div><div class="detail-label" style="margin-bottom:8px;">Mots-clés</div><div style="display:flex;gap:8px;flex-wrap:wrap;">${article.keywords ? article.keywords.split(',').map(k=>`<span style="background:var(--bg);padding:4px 12px;border-radius:20px;font-size:12px;color:var(--text-light);">${escapeHtml(k.trim())}</span>`).join('') : '<span>—</span>'}</div></div>
      <div class="abstract-box"><div class="detail-label" style="margin-bottom:8px;">Résumé</div><p style="color:var(--text);line-height:1.8;">${escapeHtml(article.abstract || 'Aucun résumé disponible.')}</p></div>
      <div style="background:var(--bg);padding:16px;border-radius:var(--radius-sm);"><div class="detail-label" style="margin-bottom:12px;"><i class="fas fa-file-pdf"></i> Document PDF</div><iframe class="pdf-viewer" src="https://docs.google.com/gview?embedded=1&url=${article.file || ''}"></iframe><p style="font-size:12px;color:var(--muted);margin-top:8px;text-align:center;">Si le PDF ne s'affiche pas, utilisez le bouton télécharger</p></div>`;
    document.getElementById('downloadPdfBtn').href = article.file || '#';
    openModal('articleDetailModal');
  }
  
  function filterAITable() {
    const searchTerm = document.getElementById('aiSearchInput')?.value.toLowerCase() || '';
    const riskFilter = document.getElementById('riskFilterSelect')?.value || 'all';
    const conferenceFilter = document.getElementById('conferenceFilterSelect')?.value || 'all';
    const rows = document.querySelectorAll('#aiAnalysisTableBody tr');
    let visibleCount = 0;
    
    rows.forEach(row => {
      if (row.cells && row.cells.length > 0 && row.id !== 'noResultMessage') {
        const title = row.getAttribute('data-title') || '';
        const author = row.getAttribute('data-author') || '';
        const ref = row.getAttribute('data-ref') || '';
        const risk = row.getAttribute('data-risk') || 'pending';
        const conference = row.getAttribute('data-conference') || '';
        
        let show = true;
        
        if (searchTerm && !title.includes(searchTerm) && !author.includes(searchTerm) && !ref.includes(searchTerm)) {
          show = false;
        }
        if (riskFilter !== 'all' && risk !== riskFilter) {
          show = false;
        }
        if (conferenceFilter !== 'all' && conference !== conferenceFilter) {
          show = false;
        }
        
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
      }
    });
    
    const tbody = document.getElementById('aiAnalysisTableBody');
    let noResultRow = document.getElementById('noResultMessage');
    
    if (visibleCount === 0 && rows.length > 0) {
      if (!noResultRow) {
        noResultRow = document.createElement('tr');
        noResultRow.id = 'noResultMessage';
        noResultRow.innerHTML = '<td colspan="8" style="text-align: center; padding: 60px 20px;"><i class="fas fa-search" style="font-size: 48px; color: var(--muted); margin-bottom: 15px; display: block;"></i><p style="color: var(--muted);">Aucun article ne correspond à vos critères.</p></td>';
        tbody.appendChild(noResultRow);
      }
    } else if (noResultRow) {
      noResultRow.remove();
    }
    
    updateAIStats();
  }
  
  function updateAIStats() {
    const rows = document.querySelectorAll('#aiAnalysisTableBody tr');
    let clean = 0, medium = 0, high = 0, pending = 0, total = 0;
    
    rows.forEach(row => {
      if (row.cells && row.cells.length > 0 && row.id !== 'noResultMessage' && row.style.display !== 'none') {
        const risk = row.getAttribute('data-risk');
        total++;
        if (risk === 'clean') clean++;
        else if (risk === 'medium') medium++;
        else if (risk === 'high') high++;
        else if (risk === 'pending') pending++;
      }
    });
    
    const totalAnalyzedElem = document.getElementById('totalAnalyzed');
    const cleanCountElem = document.getElementById('cleanCount');
    const mediumCountElem = document.getElementById('mediumCount');
    const highCountElem = document.getElementById('highCount');
    const pendingCountElem = document.getElementById('pendingCount');
    
    if (totalAnalyzedElem) totalAnalyzedElem.textContent = total;
    if (cleanCountElem) cleanCountElem.textContent = clean;
    if (mediumCountElem) mediumCountElem.textContent = medium;
    if (highCountElem) highCountElem.textContent = high;
    if (pendingCountElem) pendingCountElem.textContent = pending;
  }
  
  function refreshAIScores() {
    const button = event.target.closest('button');
    if (!button) return;
    const originalHtml = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyse en cours...';
    button.disabled = true;
    
    fetch(window.location.href + '?action=analyze_pending')
      .then(r => r.json())
      .then(result => {
        if (result.success) {
          alert(`Analyse terminée : ${result.analyzed || 0} articles analysés avec succès.`);
          location.reload();
        } else {
          alert('Erreur: ' + (result.error || 'Échec de l\'analyse'));
        }
      })
      .catch(error => {
        console.error('Error:', error);
        alert('Erreur de communication avec le serveur AI');
      })
      .finally(() => {
        button.innerHTML = originalHtml;
        button.disabled = false;
      });
  }
  
  function reanalyzeArticle(articleId) {
    if (!confirm('Voulez-vous vraiment ré-analyser cet article avec l\'IA ?')) return;
    
    const button = event.target.closest('button');
    if (!button) return;
    const originalHtml = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    button.disabled = true;
    
    fetch(window.location.href + '?action=analyze_single&article_id=' + articleId)
      .then(r => r.json())
      .then(result => {
        if (result.success) {
          alert(`Article ré-analysé avec succès. Score: ${result.ai_score}% - ${result.risk_level === 'clean' ? 'Clean' : (result.risk_level === 'medium' ? 'Risque moyen' : 'Risque élevé')}`);
          location.reload();
        } else {
          alert('Erreur: ' + (result.error || 'Échec de l\'analyse'));
        }
      })
      .catch(error => {
        console.error('Error:', error);
        alert('Erreur de communication avec le serveur AI');
      })
      .finally(() => {
        button.innerHTML = originalHtml;
        button.disabled = false;
      });
  }
  
  function viewAIAnalysisDetails(articleId) {
    const article = articles.find(a => a.id == articleId);
    if (!article) return;
    
    const score = article.ai_score ? Math.round(article.ai_score) : 0;
    const riskLevel = article.risk_level || 'pending';
    const riskText = riskLevel === 'clean' ? 'Clean (contenu original)' : (riskLevel === 'medium' ? 'Risque moyen' : (riskLevel === 'high' ? 'Risque élevé' : 'Non analysé'));
    const riskColor = riskLevel === 'clean' ? '#10b981' : (riskLevel === 'medium' ? '#f59e0b' : (riskLevel === 'high' ? '#ef4444' : '#64748b'));
    const analysisDate = article.ai_analysis_date ? new Date(article.ai_analysis_date).toLocaleString('fr-FR') : 'Non analysé';
    
    let recommendation = '';
    if (riskLevel === 'clean') {
      recommendation = 'Cet article semble être un contenu original. Il peut être accepté pour évaluation.';
    } else if (riskLevel === 'medium') {
      recommendation = 'Cet article présente des similitudes modérées avec du contenu généré par IA. Une vérification supplémentaire est recommandée.';
    } else if (riskLevel === 'high') {
      recommendation = 'ATTENTION : Cet article présente une forte probabilité d\'être généré par IA. Une investigation approfondie est nécessaire.';
    } else {
      recommendation = 'Cet article n\'a pas encore été analysé. Lancez une analyse IA pour obtenir un score.';
    }
    
    document.getElementById('aiDetailContent').innerHTML = `
      <div class="detail-card" style="margin-bottom: 20px;">
        <div class="detail-label">Titre</div>
        <div class="detail-value">${escapeHtml(article.title || 'N/A')}</div>
      </div>
      <div class="detail-card" style="margin-bottom: 20px;">
        <div class="detail-label">Auteur</div>
        <div class="detail-value">${escapeHtml(article.author || 'N/A')}</div>
      </div>
      <div style="background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%); padding: 20px; border-radius: var(--radius-sm); margin-bottom: 20px; text-align: center;">
        <div style="font-size: 48px; font-weight: 700; color: ${riskColor}; margin-bottom: 10px;">${score}%</div>
        <div style="font-size: 18px; font-weight: 600; color: ${riskColor}; margin-bottom: 10px;">${riskText}</div>
        <div class="score-bar" style="width: 200px; margin: 10px auto; height: 8px;">
          <div class="score-fill" style="width: ${score}%; background: ${riskColor};"></div>
        </div>
        <div style="font-size: 12px; color: var(--muted); margin-top: 10px;">Date d'analyse: ${analysisDate}</div>
      </div>
      <div class="notice-box">
        <i class="fas fa-info-circle"></i>
        <div><strong>Recommandation:</strong><br>${recommendation}</div>
      </div>
      <div style="margin-top: 20px; padding: 12px; background: var(--bg); border-radius: var(--radius-sm); font-size: 12px;">
        <i class="fas fa-chart-line"></i> <strong>Commentaires:</strong> Ce score est calculé en analysant la structure linguistique, la complexité du vocabulaire et les patterns de rédaction de l'article.
      </div>`;
    
    openModal('aiDetailModal');
  }
  
  document.getElementById('openAddTopicBtn')?.addEventListener('click', function() {
    document.getElementById('topicForm').reset();
    openModal('topicModal');
  });
  
  document.getElementById('saveTopicBtn')?.addEventListener('click', function() {
    const conferenceId = document.getElementById('topicConferenceId').value;
    const topicName = document.getElementById('topicName').value.trim();
    
    if (!conferenceId) {
      alert('Veuillez sélectionner une conférence.');
      return;
    }
    
    if (!topicName) {
      alert('Veuillez saisir un thème.');
      return;
    }
    
    const fd = new FormData();
    fd.append('action', 'add_topic');
    fd.append('conference_id', conferenceId);
    fd.append('topic_name', topicName);
    
    fetch(window.location.href, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.ok) {
          alert('Thème ajouté avec succès !');
          closeModal('topicModal');
          location.reload();
        } else {
          alert('Erreur: ' + (data.msg || 'Échec de l\'ajout'));
        }
      })
      .catch(error => {
        console.error('Error:', error);
        alert('Erreur de communication avec le serveur.');
      });
  });
  
  function openModal(id) { document.getElementById(id).classList.add('open'); }
  function closeModal(id) { 
    document.getElementById(id).classList.remove('open'); 
    if (id === 'confModal') { editId = null; document.getElementById('conferenceForm').reset(); }
  }
  
  function openConfModal(editMode = false, conf = null) {
    document.getElementById('modalTitle').textContent = editMode ? 'Modifier la conférence' : 'Créer une conférence';
    resetTopicsList();
    
    if (editMode && conf) {
      document.getElementById('confNameFr').value = conf.nameFr || '';
      document.getElementById('confNameEn').value = conf.nameEn || '';
      document.getElementById('confType').value = conf.type || 'Conférence';
      document.getElementById('confDisciplines').value = conf.disciplines || '';
      document.getElementById('confOrganizer').value = conf.organizer || '';
      document.getElementById('confLocation').value = conf.location || '';
      document.getElementById('confStartDate').value = conf.startDate || '';
      document.getElementById('confEndDate').value = conf.endDate || '';
      document.getElementById('confSubmissionStartDate').value = conf.submissionStartDate || '';
      document.getElementById('confSubmissionDeadline').value = conf.submissionDeadline || '';
      document.getElementById('confReviewStartDate').value = conf.reviewStartDate || '';
      document.getElementById('confReviewEndDate').value = conf.reviewEndDate || '';
      document.getElementById('confRequirements').value = conf.requirements || '';
      
      if (conf.topics && conf.topics !== '') {
        const topicsArray = conf.topics.split(', ');
        loadExistingTopics(topicsArray);
      }
    } else {
      document.getElementById('conferenceForm').reset();
      resetTopicsList();
    }
    openModal('confModal');
  }
  
  function editConference(id) {
    const conf = conferences.find(c => c.id === id);
    if (!conf) return;
    if (!isEditAllowed(conf)) {
      alert('Le délai de modification pour cette conférence est dépassé (soumissions déjà ouvertes).');
      return;
    }
    editId = id;
    openConfModal(true, conf);
  }
  
  function saveConference() {
    const fields = {
      nameFr: document.getElementById('confNameFr').value.trim(),
      nameEn: document.getElementById('confNameEn').value.trim(),
      type: document.getElementById('confType').value,
      disciplines: document.getElementById('confDisciplines').value,
      organizer: document.getElementById('confOrganizer').value,
      location: document.getElementById('confLocation').value,
      startDate: document.getElementById('confStartDate').value,
      endDate: document.getElementById('confEndDate').value,
      submissionStartDate: document.getElementById('confSubmissionStartDate').value,
      submissionDeadline: document.getElementById('confSubmissionDeadline').value,
      reviewStartDate: document.getElementById('confReviewStartDate').value,
      reviewEndDate: document.getElementById('confReviewEndDate').value,
      requirements: document.getElementById('confRequirements').value,
    };
    
    const topicSelects = document.querySelectorAll('#modalTopicsList select.topic-select');
    const topics = [];
    topicSelects.forEach(select => {
      const selectedValue = select.value;
      if (selectedValue && selectedValue !== '') {
        topics.push(selectedValue);
      }
    });
    
    const required = ['nameFr','nameEn','startDate','endDate','submissionStartDate','submissionDeadline','reviewStartDate','reviewEndDate'];
    for (const key of required) {
      if (!fields[key]) { alert('Veuillez remplir tous les champs obligatoires (*).'); return; }
    }
    
    const existing = editId ? conferences.find(c => c.id === editId) : null;
    const fd = new FormData();
    fd.append('action', 'save_conference');
    if (editId) {
      fd.append('editId', editId);
      fd.append('articlesCount', existing?.articlesCount ?? 0);
      fd.append('maxArticles', existing?.maxArticles ?? 40);
      fd.append('createdAt', existing?.createdAt ?? new Date().toISOString().split('T')[0]);
    } else {
      fd.append('articlesCount', 0);
      fd.append('maxArticles', 40);
      fd.append('createdAt', new Date().toISOString().split('T')[0]);
    }
    Object.entries(fields).forEach(([k,v]) => fd.append(k, v));
    fd.append('topics', JSON.stringify(topics));
    
    fetch(window.location.href, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (!data.ok) { alert(data.msg || 'Erreur serveur'); return; }
        const newConf = data.data;
        if (editId) {
          const index = conferences.findIndex(c => c.id === editId);
          if (index !== -1) { conferences[index] = { ...conferences[index], ...newConf }; }
        } else {
          conferences.unshift(newConf);
        }
        updateConferenceStatuses();
        renderStats();
        renderConferences();
        closeModal('confModal');
        alert(editId ? 'Conférence modifiée avec succès!' : 'Conférence créée avec succès!');
        setTimeout(() => location.reload(), 500);
      })
      .catch(() => alert('Erreur de communication avec le serveur.'));
  }
  
  document.getElementById('openCreateConfModal').addEventListener('click', () => openConfModal(false));
  document.getElementById('saveConferenceBtn').addEventListener('click', saveConference);
  
  document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      currentFilter = tab.dataset.filter;
      currentPage = 1;
      renderConferences();
    });
  });
  
  document.getElementById('sortSelect').addEventListener('change', e => { currentSort = e.target.value; currentPage = 1; renderConferences(); });
  document.getElementById('searchConference').addEventListener('input', () => { currentPage = 1; renderConferences(); });
  document.getElementById('searchArticle').addEventListener('input', e => { articleSearchTerm = e.target.value.toLowerCase(); articlePage = 1; renderArticles(); });
  document.getElementById('articleSortSelect').addEventListener('change', e => { articleSort = e.target.value; articlePage = 1; renderArticles(); });
  
  const aiSearchInput = document.getElementById('aiSearchInput');
  if (aiSearchInput) {
    aiSearchInput.addEventListener('keyup', function() {
      clearTimeout(aiSearchTimeout);
      aiSearchTimeout = setTimeout(() => filterAITable(), 300);
    });
  }
  
  const riskFilterSelect = document.getElementById('riskFilterSelect');
  if (riskFilterSelect) {
    riskFilterSelect.addEventListener('change', function() { filterAITable(); updateAIStats(); });
  }
  
  const conferenceFilterSelect = document.getElementById('conferenceFilterSelect');
  if (conferenceFilterSelect) {
    conferenceFilterSelect.addEventListener('change', function() { filterAITable(); updateAIStats(); });
  }
  
  document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', e => { if (e.target === backdrop) backdrop.classList.remove('open'); });
  });
  
  Object.assign(window, {
    editConference, viewConferenceArticles, backToConferences,
    viewArticleDetails, acceptArticle, openRejectModal, confirmReject,
    closeModal, changePage, changeArticlePage,
    addTopicRow, removeTopicRow,
    refreshAIScores, reanalyzeArticle, viewAIAnalysisDetails
  });
  
  updateConferenceStatuses();
  renderStats();
  renderConferences();
  setTimeout(() => filterAITable(), 100);
</script>
</body>
</html>