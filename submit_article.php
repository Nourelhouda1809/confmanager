<?php
// ============================================
// submit_article.php - Soumission d'article
// ConfManager - Université Hassiba Benbouali de Chlef
// ============================================

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';

// ============================================
// 1. VERIFICATION DE L'AUTHENTIFICATION
// ============================================

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Role-based access control
$allowedRoles = ['chercheur'];
if (!in_array($_SESSION['role'], $allowedRoles)) {
    header('Location: ' . match($_SESSION['role']) {
        'reviewer' => 'reviewer_dashboard.php',
        'gestionnaire' => 'admin_dashboard.php',
        default => 'login.php'
    });
    exit();
}

$database = new Database();
$db = $database->getConnection();
$userId = $_SESSION['user_id'];
$userNom = $_SESSION['user_nom'] ?? 'Utilisateur';
$userPrenom = $_SESSION['user_prenom'] ?? '';
$userInitials = strtoupper(substr($userPrenom, 0, 1) . substr($userNom, 0, 1));
$userEmail = $_SESSION['user_email'] ?? '';

// ============================================
// 2. CONFIGURATION CONSTANTS
// ============================================

define('UPLOAD_ARTICLE_DIR', __DIR__ . '/uploads/articles/');
define('UPLOAD_SUPP_DIR', __DIR__ . '/uploads/supplementaires/');
define('MAX_FILE_SIZE_ARTICLE', 20 * 1024 * 1024);
define('MAX_FILE_SIZE_SUPP', 50 * 1024 * 1024);
define('SIMILARITY_THRESHOLD_WARNING', 30);
define('SIMILARITY_THRESHOLD_SUSPICIOUS', 60);

// Create upload directories if they don't exist
foreach ([UPLOAD_ARTICLE_DIR, UPLOAD_SUPP_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Domaines de recherche
$DOMAINES = [
    'Informatique & IA', 'Intelligence Artificielle', 'Machine Learning',
    'Data Science', 'Cybersécurité', 'Réseaux & Télécommunications',
    'IoT & Systèmes Embarqués', 'Traitement d\'images', 'Traitement du langage naturel',
    'Robotique', 'Génie Logiciel', 'Base de données', 'Cloud Computing',
    'Blockchain', 'Réalité virtuelle', 'Bioinformatique', 'Mathématiques appliquées',
    'Physique théorique', 'Chimie organique', 'Biologie moléculaire',
    'Études islamiques', 'Langue & Littérature arabes', 'Éducation & Pédagogie',
    'Sciences sociales', 'Économie', 'Droit', 'Autre'
];

$TYPES_PRESENTATION = [
    'Présentation orale', 'Poster', "Article d'atelier", 'Article complet', 'Communication courte'
];

// ============================================
// 3. SIMILARITY DETECTION FUNCTIONS
// ============================================

function findSimilarArticlesByKeywords($db, $title, $keywords, $excludeId = null) {
    $similarArticles = [];
    
    $keywordArray = array_map('trim', explode(',', $keywords));
    $keywordTerms = [];
    foreach ($keywordArray as $kw) {
        if (strlen($kw) > 3) {
            $keywordTerms[] = $kw;
        }
    }
    
    $conditions = [];
    $params = [];
    
    $titleWords = array_slice(explode(' ', $title), 0, 10);
    foreach ($titleWords as $idx => $word) {
        if (strlen($word) > 4) {
            $conditions[] = "titre_fr LIKE :title_" . $idx;
            $params[':title_' . $idx] = "%$word%";
        }
    }
    
    foreach ($keywordTerms as $idx => $kw) {
        $conditions[] = "mots_cles LIKE :kw_$idx";
        $params[":kw_$idx"] = "%$kw%";
    }
    
    if (empty($conditions)) {
        return [];
    }
    
    $whereClause = implode(" OR ", $conditions);
    
    $sql = "
        SELECT 
            id, 
            reference, 
            titre_fr as title,
            resume_fr as abstract,
            mots_cles as keywords,
            domaine as domain,
            date_soumission as submission_date,
            status
        FROM articles 
        WHERE ($whereClause)
    ";
    
    if ($excludeId) {
        $sql .= " AND id != :exclude_id";
        $params[':exclude_id'] = $excludeId;
    }
    
    $sql .= " LIMIT 10";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($results as &$article) {
        $matchScore = 0;
        $totalChecks = 0;
        
        foreach ($titleWords as $word) {
            if (strlen($word) > 4 && stripos($article['title'], $word) !== false) {
                $matchScore += 15;
            }
            $totalChecks++;
        }
        
        foreach ($keywordTerms as $kw) {
            if (stripos($article['keywords'], $kw) !== false) {
                $matchScore += 20;
            }
            $totalChecks++;
        }
        
        $article['simple_score'] = min(100, round($matchScore / max(1, $totalChecks) * 2));
        $article['match_type'] = 'keyword_match';
    }
    
    usort($results, function($a, $b) {
        return $b['simple_score'] - $a['simple_score'];
    });
    
    return array_slice($results, 0, 5);
}

function calculateTextSimilarity($text1, $text2) {
    if (empty($text1) || empty($text2)) {
        return 0;
    }
    
    $text1 = strtolower(trim($text1));
    $text2 = strtolower(trim($text2));
    
    $words1 = preg_split('/[\s,.;!?()\[\]{}\"\'\-]+/', $text1, -1, PREG_SPLIT_NO_EMPTY);
    $words2 = preg_split('/[\s,.;!?()\[\]{}\"\'\-]+/', $text2, -1, PREG_SPLIT_NO_EMPTY);
    
    $words1 = array_filter($words1, function($w) { return strlen($w) >= 3; });
    $words2 = array_filter($words2, function($w) { return strlen($w) >= 3; });
    
    if (empty($words1) || empty($words2)) {
        return 0;
    }
    
    $uniqueWords1 = array_unique($words1);
    $uniqueWords2 = array_unique($words2);
    
    $intersection = count(array_intersect($uniqueWords1, $uniqueWords2));
    $union = count($uniqueWords1) + count($uniqueWords2) - $intersection;
    $jaccardScore = ($union > 0) ? ($intersection / $union) * 100 : 0;
    
    $tf1 = array_count_values($words1);
    $tf2 = array_count_values($words2);
    
    $allTerms = array_unique(array_merge(array_keys($tf1), array_keys($tf2)));
    
    $dotProduct = 0;
    $norm1 = 0;
    $norm2 = 0;
    
    foreach ($allTerms as $term) {
        $freq1 = $tf1[$term] ?? 0;
        $freq2 = $tf2[$term] ?? 0;
        $dotProduct += $freq1 * $freq2;
        $norm1 += $freq1 * $freq1;
        $norm2 += $freq2 * $freq2;
    }
    
    $cosineScore = ($norm1 > 0 && $norm2 > 0) ? ($dotProduct / (sqrt($norm1) * sqrt($norm2))) * 100 : 0;
    
    $len1 = strlen($text1);
    $len2 = strlen($text2);
    $levScore = 0;
    
    if ($len1 > 50 && $len2 > 50) {
        $maxLen = max($len1, $len2);
        $distance = levenshtein($text1, $text2);
        $levScore = (1 - ($distance / $maxLen)) * 100;
    }
    
    $finalScore = ($jaccardScore * 0.3) + ($cosineScore * 0.5) + ($levScore * 0.2);
    
    return min(100, round($finalScore, 2));
}

function findSimilarArticlesByContent($db, $title, $abstract, $keywords, $excludeId = null) {
    $similarArticles = [];
    
    $keywordArray = array_map('trim', explode(',', $keywords));
    $keywordConditions = [];
    $params = [];
    
    foreach (array_slice($keywordArray, 0, 5) as $idx => $kw) {
        if (strlen($kw) > 3) {
            $keywordConditions[] = "mots_cles LIKE :kw_$idx";
            $params[":kw_$idx"] = "%$kw%";
        }
    }
    
    if (empty($keywordConditions)) {
        $sql = "SELECT id, reference, titre_fr as title, resume_fr as abstract, mots_cles as keywords 
                FROM articles ORDER BY date_soumission DESC LIMIT 50";
        $stmt = $db->prepare($sql);
        $stmt->execute();
    } else {
        $whereClause = implode(" OR ", $keywordConditions);
        $sql = "SELECT id, reference, titre_fr as title, resume_fr as abstract, mots_cles as keywords 
                FROM articles WHERE ($whereClause) ORDER BY date_soumission DESC LIMIT 50";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }
    
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $combinedText = $title . " " . $abstract;
    
    foreach ($candidates as $candidate) {
        if ($excludeId && $candidate['id'] == $excludeId) {
            continue;
        }
        
        $candidateText = $candidate['title'] . " " . ($candidate['abstract'] ?? '');
        
        if (strlen($candidateText) < 50) {
            continue;
        }
        
        $score = calculateTextSimilarity($combinedText, $candidateText);
        
        if ($score > 15) {
            $similarArticles[] = [
                'id' => $candidate['id'],
                'reference' => $candidate['reference'],
                'title' => $candidate['title'],
                'abstract' => $candidate['abstract'],
                'keywords' => $candidate['keywords'],
                'similarity_score' => $score,
                'risk_level' => getRiskLevel($score)
            ];
        }
    }
 usort($similarArticles, function($a, $b) {
    $scoreA = $a['similarity_score'] ?? 0;
    $scoreB = $b['similarity_score'] ?? 0;

    return $scoreB - $scoreA;
});
    return array_slice($similarArticles, 0, 10);
}

function getRiskLevel($score) {
    if ($score >= 60) {
        return 'high';
    } elseif ($score >= 30) {
        return 'medium';
    } else {
        return 'low';
    }
}

function getRiskBadgeHTML($riskLevel) {
    $badges = [
        'low' => '<span class="risk-badge risk-low"><i class="fas fa-shield-alt"></i> Risque faible</span>',
        'medium' => '<span class="risk-badge risk-medium"><i class="fas fa-exclamation-triangle"></i> Risque moyen</span>',
        'high' => '<span class="risk-badge risk-high"><i class="fas fa-times-circle"></i> Risque élevé</span>'
    ];
    return $badges[$riskLevel] ?? $badges['low'];
}

function getSimilarityBarHTML($score) {
    $score = min(100, max(0, $score));
    $color = '#10b981';
    if ($score >= 60) {
        $color = '#ef4444';
    } elseif ($score >= 30) {
        $color = '#f59e0b';
    }
    
    return '
        <div class="similarity-bar-container">
            <div class="similarity-bar-label">
                <span>Similarité</span>
                <span class="similarity-score-value" style="color: ' . $color . ';">' . round($score) . '%</span>
            </div>
            <div class="similarity-bar">
                <div class="similarity-bar-fill" style="width: ' . $score . '%; background: ' . $color . ';"></div>
            </div>
        </div>
    ';
}

function performSimilarityAnalysis($db, $articleId, $title, $abstract, $keywords) {
    $similarArticles = findSimilarArticlesByContent($db, $title, $abstract, $keywords, $articleId);
    
    $highestScore = 0;
    $bestMatch = null;
    
   foreach ($similarArticles as $article) {

    $score = $article['similarity_score'] ?? 0;

    if ($score > $highestScore) {
        $highestScore = $score;
        $bestMatch = $article;
    }
}
    
    if ($highestScore >= 60) {
        $status = 'suspicious';
        $flagged = 1;
    } elseif ($highestScore >= 30) {
        $status = 'warning';
        $flagged = 1;
    } else {
        $status = 'safe';
        $flagged = 0;
    }
    
    try {
        $columns = $db->query("SHOW COLUMNS FROM articles")->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('similarity_score', $columns)) {
            $updateStmt = $db->prepare("
                UPDATE articles 
                SET similarity_score = :score,
                    similarity_flagged = :flagged,
                    similarity_status = :status
                WHERE id = :id
            ");
            
            $updateStmt->execute([
                ':score' => $highestScore,
                ':flagged' => $flagged,
                ':status' => $status,
                ':id' => $articleId
            ]);
        }
    } catch (PDOException $e) {
        error_log("Failed to save similarity results: " . $e->getMessage());
    }
    
    return [
        'highest_score' => $highestScore,
        'status' => $status,
        'flagged' => $flagged,
        'similar_articles' => $similarArticles,
        'best_match' => $bestMatch
    ];
}

function renderSimilarArticlesWarning($similarArticles) {
    if (empty($similarArticles)) {
        return '';
    }
    
    $highestScore = $similarArticles[0]['similarity_score'] ?? 0;
    $riskLevel = getRiskLevel($highestScore);
    $riskClass = $riskLevel === 'high' ? 'danger' : ($riskLevel === 'medium' ? 'warning' : 'info');
    
    $html = '
    <div class="similarity-warning-card similarity-warning-' . $riskClass . '">
        <div class="warning-header">
            <i class="fas ' . ($riskLevel === 'high' ? 'fa-exclamation-triangle' : 'fa-info-circle') . '"></i>
            <div class="warning-title">Attention: Articles similaires détectés</div>
            <button type="button" class="warning-close" onclick="this.closest(\'.similarity-warning-card\').remove()">&times;</button>
        </div>
        <div class="warning-body">
            <p>Notre système a détecté ' . count($similarArticles) . ' article(s) similaire(s) dans la base de données :</p>
            <div class="similar-articles-list">
    ';
    
  foreach ($similarArticles as $article) {

    $score = $article['similarity_score'] ?? 0;
    $articleRisk = getRiskLevel($score);

    $html .= '
        <div class="similar-article-item">
            <div class="similar-article-info">
                <div class="similar-article-title">' . htmlspecialchars($article['title'] ?? 'Sans titre') . '</div>
                <div class="similar-article-meta">
                    <span class="similar-article-ref">📄 ' . htmlspecialchars($article['reference'] ?? '-') . '</span>
                    ' . getRiskBadgeHTML($articleRisk) . '
                </div>
            </div>
            ' . getSimilarityBarHTML($score) . '
        </div>
    ';
}
    
    $html .= '
            </div>
            <div class="warning-note">
                <i class="fas fa-gavel"></i> 
                <strong>Note:</strong> Veuillez vérifier que votre article est original. Un score de similarité élevé peut entraîner 
                le rejet automatique de votre soumission.
            </div>
        </div>
    </div>';
    
    return $html;
}

// ============================================
// 4. HELPER FUNCTIONS
// ============================================

function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function uploadFile($file, $targetDir, $allowedExtensions, $maxSize) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions)) {
        return false;
    }
    if ($file['size'] > $maxSize) {
        return false;
    }
    
    $safeName = uniqid('art_', true) . '.' . $ext;
    $targetPath = $targetDir . $safeName;
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return $safeName;
    }
    return false;
}

function validateCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ============================================
// 5. CSRF TOKEN INITIALIZATION
// ============================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============================================
// 6. FETCH OPEN CONFERENCES
// ============================================
$conferences = [];
try {
    $stmt = $db->prepare("
        SELECT id, name_fr, name_en, submission_start_date, submission_deadline,
            review_start_date, review_end_date, start_date, end_date, location, organizer
        FROM conferences
        WHERE submission_start_date <= CURDATE()
        AND submission_deadline >= CURDATE()
        ORDER BY submission_deadline ASC
    ");
    $stmt->execute();
    $conferences = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Fetch conferences error: " . $e->getMessage());
    $conferences = [];
}

// ============================================
// 7. FORM PROCESSING
// ============================================
$errors = [];
$success = false;
$refNumber = '';
$oldData = [];
$similarArticlesFound = [];
$similarityAnalysisResults = null;
$showSimilarityWarning = false;

$currentStep = 1;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $currentStep = (int)($_POST['current_step'] ?? 1);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    }
    
    $conferenceId = (int)($_POST['conference_id'] ?? 0);
    $domaine = trim($_POST['domaine'] ?? '');
    $typePresentation = trim($_POST['type_presentation'] ?? '');
    $titreFr = trim($_POST['titre_fr'] ?? '');
    $titreEn = trim($_POST['titre_en'] ?? '');
    $titreAr = trim($_POST['titre_ar'] ?? '');
    $resumeFr = trim($_POST['resume_fr'] ?? '');
    $resumeEn = trim($_POST['resume_en'] ?? '');
    $motsCles = trim($_POST['mots_cles'] ?? '');
    
    $coNoms = $_POST['co_nom'] ?? [];
    $coEmails = $_POST['co_email'] ?? [];
    $coInstitutions = $_POST['co_institution'] ?? [];
    
    $chkOriginal = isset($_POST['chk_original']);
    $chkTerms = isset($_POST['chk_terms']);
    $chkAuthors = isset($_POST['chk_authors']);
    
    $oldData = compact('conferenceId', 'domaine', 'typePresentation', 'titreFr', 'titreEn', 'titreAr', 'resumeFr', 'resumeEn', 'motsCles');
    
    if (empty($errors)) {
        if ($conferenceId <= 0) $errors[] = 'Veuillez sélectionner une conférence.';
        if (empty($domaine)) $errors[] = 'Veuillez sélectionner un domaine de recherche.';
        if (mb_strlen($titreFr) < 5) $errors[] = 'Le titre en français est obligatoire (min. 5 caractères).';
        if (mb_strlen($titreEn) < 5) $errors[] = 'Le titre en anglais est obligatoire (min. 5 caractères).';
        if (mb_strlen($resumeFr) < 50) $errors[] = 'Le résumé en français est obligatoire (min. 50 caractères).';
        if (mb_strlen($resumeEn) < 50) $errors[] = "L'abstract en anglais est obligatoire (min. 50 caractères).";
        if (empty($motsCles)) $errors[] = 'Les mots-clés sont obligatoires.';
        if (!$chkOriginal) $errors[] = "Vous devez confirmer l'originalité de l'article.";
        if (!$chkTerms) $errors[] = 'Vous devez accepter les conditions de publication.';
        
        if ($conferenceId > 0) {
            $checkStmt = $db->prepare("
                SELECT id FROM conferences 
                WHERE id = :id 
                AND submission_start_date <= CURDATE() 
                AND submission_deadline >= CURDATE()
            ");
            $checkStmt->execute([':id' => $conferenceId]);
            if (!$checkStmt->fetch()) {
                $errors[] = 'La conférence sélectionnée ne prend plus de soumissions.';
            }
        }
    }
    
    $fichierPrincipal = '';
    if (empty($errors) && isset($_FILES['fichier_principal'])) {
        $uploaded = uploadFile(
            $_FILES['fichier_principal'],
            UPLOAD_ARTICLE_DIR,
            ['pdf', 'doc', 'docx'],
            MAX_FILE_SIZE_ARTICLE
        );
        if ($uploaded) {
            $fichierPrincipal = 'articles/' . $uploaded;
        } else {
            $errors[] = 'Le fichier principal est invalide ou dépasse 20 Mo.';
        }
    } elseif (empty($errors)) {
        $errors[] = 'Le fichier de l\'article (PDF ou Word) est obligatoire.';
    }
    
    $fichiersSupp = [];
    if (empty($errors) && !empty($_FILES['fichiers_supp']['name'][0])) {
        $allowedSupp = ['pdf', 'doc', 'docx', 'zip', 'png', 'jpg', 'jpeg', 'xlsx', 'csv', 'ppt', 'pptx'];
        foreach ($_FILES['fichiers_supp']['name'] as $idx => $name) {
            if ($_FILES['fichiers_supp']['error'][$idx] !== UPLOAD_ERR_OK) continue;
            
            $file = [
                'name' => $_FILES['fichiers_supp']['name'][$idx],
                'type' => $_FILES['fichiers_supp']['type'][$idx],
                'tmp_name' => $_FILES['fichiers_supp']['tmp_name'][$idx],
                'error' => $_FILES['fichiers_supp']['error'][$idx],
                'size' => $_FILES['fichiers_supp']['size'][$idx],
            ];
            
            $uploaded = uploadFile($file, UPLOAD_SUPP_DIR, $allowedSupp, MAX_FILE_SIZE_SUPP);
            if ($uploaded) {
                $fichiersSupp[] = 'supplementaires/' . $uploaded;
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            $refNumber = 'ART-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6));
            
            $sql = "INSERT INTO articles (
                        reference, utilisateur_id, conference_id, domaine, type_presentation,
                        titre_fr, titre_en, titre_ar, resume_fr, resume_en, mots_cles,
                        fichier_principal, status, date_soumission
                    ) VALUES (
                        :ref, :uid, :cid, :dom, :tp,
                        :tfr, :ten, :tar, :rfr, :ren, :mk,
                        :fp, 'en_attente', NOW()
                    )";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':ref' => $refNumber,
                ':uid' => $userId,
                ':cid' => $conferenceId,
                ':dom' => $domaine,
                ':tp' => $typePresentation,
                ':tfr' => $titreFr,
                ':ten' => $titreEn,
                ':tar' => $titreAr,
                ':rfr' => $resumeFr,
                ':ren' => $resumeEn,
                ':mk' => $motsCles,
                ':fp' => $fichierPrincipal,
            ]);
            
            $articleId = (int)$db->lastInsertId();
            
            if (!empty($fichiersSupp)) {
                $sqlFile = "INSERT INTO article_fichiers (article_id, chemin, type) VALUES (:aid, :ch, 'supplementaire')";
                $stmtFile = $db->prepare($sqlFile);
                foreach ($fichiersSupp as $chemin) {
                    $stmtFile->execute([':aid' => $articleId, ':ch' => $chemin]);
                }
            }
            
            $sqlCo = "INSERT INTO article_coauteurs (article_id, nom, email, institution) VALUES (:aid, :nom, :email, :inst)";
            $stmtCo = $db->prepare($sqlCo);
            foreach ($coNoms as $idx => $nom) {
                $nom = trim($nom);
                if ($nom === '') continue;
                $stmtCo->execute([
                    ':aid' => $articleId,
                    ':nom' => $nom,
                    ':email' => trim($coEmails[$idx] ?? ''),
                    ':inst' => trim($coInstitutions[$idx] ?? ''),
                ]);
            }
            
            $similarArticlesFound = findSimilarArticlesByKeywords($db, $titreFr, $motsCles, $articleId);
            $similarityAnalysisResults = performSimilarityAnalysis($db, $articleId, $titreFr, $resumeFr, $motsCles);
            
            $db->commit();
            $success = true;
            
            if ($similarityAnalysisResults && isset($similarityAnalysisResults['highest_score']) && $similarityAnalysisResults['highest_score'] >= SIMILARITY_THRESHOLD_WARNING) {
                $showSimilarityWarning = true;
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = "Erreur serveur: " . $e->getMessage();
            error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ConfManager — Soumettre un article</title>
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
            --radius: 8px;
            --radius-sm: 4px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

        .topbar {
            background: var(--white); border-bottom: 1px solid var(--border);
            padding: 0 40px; height: 62px; display: flex; align-items: center;
            position: sticky; top: 0; z-index: 100; box-shadow: var(--shadow-sm);
        }
        .brand { display: flex; align-items: center; gap: 10px; margin-right: 48px; text-decoration: none; }
        .brand-icon {
            width: 34px; height: 34px; background: var(--navy);
            border-radius: 6px; display: flex; align-items: center; justify-content: center;
            color: white; font-size: 16px;
        }
        .brand-name { font-size: 18px; font-weight: 600; color: var(--navy); letter-spacing: -0.3px; }
        .nav-links { display: flex; align-items: center; gap: 4px; flex: 1; }
        .nav-link {
            padding: 8px 16px; font-size: 14px; font-weight: 400;
            color: var(--text-light); background: none; border: none;
            border-radius: var(--radius-sm); font-family: 'DM Sans', sans-serif;
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
            cursor: pointer; text-decoration: none; transition: all 0.15s;
        }
        .logout-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--white); }

        .logo-wrapper { display: flex; align-items: center; gap: 8px; }
        .logo-icon {
            width: 36px; height: 36px; background: var(--navy);
            border-radius: 8px; display: flex; align-items: center; justify-content: center;
            color: white; font-size: 16px;
        }
        .logo-text {
            font-family: 'Libre Baskerville', serif;
            font-size: 18px; font-weight: 700; color: var(--navy);
        }

        .page { max-width: 1100px; margin: 0 auto; padding: 40px 32px 60px; }
        .page-header { margin-bottom: 32px; }
        .page-header h1 {
            font-family: 'Libre Baskerville', serif;
            font-size: 32px; font-weight: 700; color: var(--navy);
            letter-spacing: -0.5px; margin-bottom: 6px;
        }
        .page-header h1 em { font-style: italic; color: var(--gold); }
        .page-header p { font-size: 14px; color: var(--muted); }

        .layout-single { max-width: 860px; margin: 0 auto; }

        .success-banner {
            background: #e8f6f3; border: 1px solid #9dd8d0;
            border-radius: var(--radius); padding: 18px 22px;
            display: none; align-items: flex-start; gap: 14px; margin-bottom: 24px;
        }
        .success-banner.show { display: flex; }
        .success-icon {
            font-size: 18px; background: var(--success); color: white;
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        .success-title { font-weight: 600; color: #1a5f57; font-size: 14px; margin-bottom: 4px; }
        .success-ref {
            font-family: 'DM Mono', monospace; font-size: 12px;
            background: #c5ede8; padding: 2px 8px; border-radius: 3px;
            font-weight: 600; color: #0b3b36;
        }
        .success-msg { font-size: 13px; color: #1a5f57; margin-top: 4px; }

        .error-box {
            background: #fdf2f2; border: 1px solid #f5b8b8;
            border-radius: var(--radius); padding: 16px 20px; margin-bottom: 24px;
        }
        .error-box ul { padding-left: 18px; }
        .error-box li { font-size: 13.5px; color: var(--danger); margin-bottom: 4px; }

        .similarity-warning-card {
            border-radius: var(--radius); margin-bottom: 24px;
            overflow: hidden; animation: slideDown 0.3s ease;
        }
        .similarity-warning-info { background: #eef3fb; border-left: 4px solid var(--accent); }
        .similarity-warning-warning { background: #fef8ec; border-left: 4px solid var(--warning); }
        .similarity-warning-danger { background: #fdf2f2; border-left: 4px solid var(--danger); }

        .warning-header {
            display: flex; align-items: center; gap: 12px;
            padding: 16px 20px; border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        .similarity-warning-info .warning-header { background: rgba(44,111,173,0.08); }
        .similarity-warning-warning .warning-header { background: rgba(212,131,10,0.08); }
        .similarity-warning-danger .warning-header { background: rgba(217,64,64,0.08); }
        .warning-header i { font-size: 20px; }
        .similarity-warning-info .warning-header i { color: var(--accent); }
        .similarity-warning-warning .warning-header i { color: var(--warning); }
        .similarity-warning-danger .warning-header i { color: var(--danger); }
        .warning-title { font-weight: 600; font-size: 14px; flex: 1; }
        .similarity-warning-info .warning-title { color: var(--accent); }
        .similarity-warning-warning .warning-title { color: var(--warning); }
        .similarity-warning-danger .warning-title { color: var(--danger); }
        .warning-close {
            background: none; border: none; font-size: 24px;
            cursor: pointer; color: var(--muted); padding: 0;
            line-height: 1; width: 28px; height: 28px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%; transition: all 0.15s;
        }
        .warning-close:hover { background: rgba(0,0,0,0.1); color: var(--text); }
        .warning-body { padding: 20px; }
        .warning-body > p { font-size: 13px; color: var(--text); margin-bottom: 16px; }
        .similar-articles-list { margin: 16px 0; display: flex; flex-direction: column; gap: 16px; }
        .similar-article-item {
            background: var(--white); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 14px 16px;
            transition: all 0.15s;
        }
        .similar-article-item:hover { box-shadow: var(--shadow-sm); border-color: var(--accent); }
        .similar-article-info { margin-bottom: 12px; }
        .similar-article-title { font-weight: 600; color: var(--navy); font-size: 13px; margin-bottom: 6px; }
        .similar-article-meta { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .similar-article-ref { font-size: 11px; color: var(--muted); font-family: monospace; }

        .risk-badge {
            padding: 4px 10px; border-radius: 20px; font-size: 11px;
            font-weight: 600; display: inline-flex; align-items: center; gap: 5px;
        }
        .risk-low { background: #d1fae5; color: #065f46; }
        .risk-medium { background: #fed7aa; color: #92400e; }
        .risk-high { background: #fee2e2; color: #991b1b; }

        .similarity-bar-container { margin-top: 8px; }
        .similarity-bar-label {
            display: flex; justify-content: space-between;
            font-size: 11px; margin-bottom: 5px; color: var(--muted);
        }
        .similarity-score-value { font-weight: 600; }
        .similarity-bar {
            height: 6px; background: var(--border);
            border-radius: 3px; overflow: hidden;
        }
        .similarity-bar-fill {
            height: 100%; border-radius: 3px; transition: width 0.5s ease;
        }
        .warning-note {
            margin-top: 16px; padding: 12px; background: var(--bg);
            border-radius: var(--radius-sm); font-size: 12px;
            color: var(--text-light); display: flex; align-items: flex-start; gap: 10px;
        }
        .warning-note i { color: var(--warning); margin-top: 2px; }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-card {
            background: var(--white); border: 1px solid var(--border);
            border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow-sm);
        }
        .profile-banner {
            height: 120px;
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%);
        }
        .profile-meta { padding: 0 32px 24px; position: relative; }
        .profile-avatar-wrap { display: inline-block; margin-top: -30px; margin-bottom: 10px; }
        .profile-avatar {
            width: 64px; height: 64px; border-radius: 50%;
            background: var(--navy-mid); border: 3px solid var(--white);
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; font-weight: 700; color: var(--white);
        }
        .profile-name { font-size: 18px; font-weight: 600; color: var(--navy); }
        .profile-email { font-size: 13px; color: var(--muted); margin-top: 2px; }

        .section-header {
            display: flex; align-items: center; gap: 10px;
            padding: 18px 28px; border-bottom: 1px solid var(--border);
            border-top: 1px solid var(--border);
        }
        .section-header-icon {
            width: 30px; height: 30px; background: var(--gold);
            border-radius: 50%; display: flex; align-items: center;
            justify-content: center; color: var(--navy); font-size: 14px;
        }
        .section-header-title { font-size: 15px; font-weight: 600; color: var(--navy); }

        .steps-bar {
            display: flex; align-items: center; background: var(--bg);
            border: 1px solid var(--border); border-radius: 40px;
            padding: 5px; margin-bottom: 32px;
        }
        .step-pill {
            flex: 1; display: flex; align-items: center;
            justify-content: center; gap: 8px; padding: 10px 12px;
            border-radius: 30px; transition: all 0.2s; cursor: default;
        }
        .step-pill.active { background: var(--white); box-shadow: var(--shadow-sm); }
        .step-num {
            width: 26px; height: 26px; border-radius: 50%;
            background: var(--border); color: var(--muted);
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 600;
        }
        .step-pill.active .step-num { background: var(--navy); color: var(--gold-light); }
        .step-pill.done .step-num { background: var(--gold); color: white; }
        .step-txt {
            font-size: 12px; font-weight: 500; color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .step-pill.active .step-txt { color: var(--navy); }
        .step-pill.done .step-txt { color: var(--gold); }

        .form-body { padding: 28px; }
        .mini-section-title {
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 1.2px; color: var(--muted); margin-bottom: 14px;
            margin-top: 24px; display: flex; align-items: center; gap: 8px;
        }
        .mini-section-title::after { content: ''; flex: 1; height: 1px; background: var(--border); }
        .mini-section-title:first-child { margin-top: 0; }

        .form-grid { display: grid; gap: 20px; margin-bottom: 20px; }
        .form-grid.cols-2 { grid-template-columns: 1fr 1fr; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }

        .field-label-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
        .field-label { font-size: 11px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }
        .field-required { color: var(--danger); }

        .form-input, .form-select, .form-textarea {
            padding: 11px 14px; border: 1px solid var(--border);
            border-radius: var(--radius-sm); font-family: 'DM Sans', sans-serif;
            font-size: 14px; color: var(--text); background: var(--white);
            transition: all 0.15s; outline: none;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: var(--accent); box-shadow: 0 0 0 3px rgba(44,111,173,0.12);
        }
        .form-textarea { resize: vertical; min-height: 120px; line-height: 1.6; }
        .char-count { font-size: 11px; color: var(--muted); text-align: right; }

        .conf-dates-info {
            display: none; background: var(--bg); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 14px 16px; margin-top: 12px;
            font-size: 13px;
        }
        .conf-dates-info.show { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .conf-date-item .cdi-label {
            font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px;
            color: var(--muted); font-weight: 600; margin-bottom: 3px;
        }
        .conf-date-item .cdi-value { font-size: 13px; font-weight: 600; color: var(--navy); }

        .coauthor-row {
            display: grid; grid-template-columns: 1fr 1fr 1fr auto;
            gap: 12px; background: var(--bg); padding: 16px;
            border-radius: var(--radius-sm); border: 1px solid var(--border);
            margin-bottom: 10px; align-items: end;
        }
        .remove-btn {
            height: 40px; width: 40px; background: none;
            border: 1px solid var(--border); border-radius: var(--radius-sm);
            cursor: pointer; color: var(--danger); font-size: 14px;
            display: flex; align-items: center; justify-content: center;
        }
        .remove-btn:hover { background: var(--danger); color: white; border-color: var(--danger); }
        .add-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px; background: none; border: 1px dashed var(--gold);
            border-radius: var(--radius-sm); color: var(--gold); font-size: 13px;
            cursor: pointer; transition: all 0.15s; margin-top: 4px;
        }
        .add-btn:hover { background: #fef9ed; border-style: solid; }

        .upload-zone {
            border: 2px dashed var(--border); border-radius: var(--radius);
            padding: 28px; text-align: center; cursor: pointer;
            transition: all 0.2s; background: var(--bg); position: relative;
            margin-bottom: 12px;
        }
        .upload-zone:hover, .upload-zone.dragover { border-color: var(--gold); background: #fef9ed; }
        .upload-zone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .upload-icon { font-size: 28px; margin-bottom: 8px; color: var(--gold); }
        .upload-label { font-size: 14px; font-weight: 500; color: var(--text-light); margin-bottom: 4px; }
        .upload-hint { font-size: 11px; color: var(--muted); }

        .file-list { display: flex; flex-direction: column; gap: 8px; }
        .file-item {
            display: flex; align-items: center; gap: 10px;
            background: var(--bg); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 10px 14px; font-size: 12.5px;
        }
        .file-icon { color: var(--accent); font-size: 14px; }
        .file-name { flex: 1; font-family: 'DM Mono', monospace; font-size: 12px; color: var(--text); word-break: break-all; }
        .file-size { color: var(--muted); font-size: 11px; margin-right: 8px; }
        .file-remove {
            cursor: pointer; color: var(--danger); font-size: 14px;
            background: none; border: none; padding: 4px;
        }
        .file-remove:hover { color: #ff4444; }

        .checkbox-group { display: flex; flex-direction: column; gap: 14px; margin: 20px 0; }
        .checkbox-item { display: flex; align-items: flex-start; gap: 12px; cursor: pointer; }
        .checkbox-item input[type="checkbox"] { width: 17px; height: 17px; margin-top: 2px; accent-color: var(--navy); cursor: pointer; }

        .btn-primary {
            background: var(--navy); color: var(--gold-light); border: none;
            border-radius: var(--radius-sm); padding: 11px 26px;
            font-size: 14px; font-weight: 500; cursor: pointer;
            transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-primary:hover { background: var(--navy-mid); transform: translateY(-1px); box-shadow: var(--shadow); }
        .btn-secondary {
            background: none; color: var(--text-light);
            border: 1px solid var(--border); border-radius: var(--radius-sm);
            padding: 11px 22px; font-size: 14px; font-weight: 400; cursor: pointer;
            transition: all 0.15s;
        }
        .btn-secondary:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }

        .form-actions {
            display: flex; justify-content: flex-end; gap: 12px;
            margin-top: 28px; padding-top: 22px; border-top: 1px solid var(--border);
        }

        .notice-box {
            background: var(--bg); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 14px 16px;
            font-size: 12.5px; color: var(--muted); line-height: 1.7;
            margin-top: 16px;
        }

        .step-content { display: none; animation: fadeUp 0.22s ease; }
        .step-content.active { display: block; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }

        .footer {
            background: var(--navy); color: rgba(255,255,255,0.45);
            text-align: center; padding: 22px; margin-top: 48px; font-size: 13px;
        }

        @media (max-width: 900px) {
            .form-grid.cols-2 { grid-template-columns: 1fr; }
            .coauthor-row { grid-template-columns: 1fr; }
            .conf-dates-info.show { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .topbar { padding: 0 16px; }
            .nav-links { display: none; }
            .page { padding: 24px 16px; }
            .form-body { padding: 20px 16px; }
            .profile-meta { padding: 0 16px 20px; }
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
        <a href="submit_article.php" class="nav-link active">Soumettre un article</a>
        <a href="mes_articles.php" class="nav-link">Mes articles</a>
        <a href="profile.php" class="nav-link">Profil</a>
    </nav>
    <div class="topbar-right">
        <a href="logout.php" class="logout-btn"><span>↪</span> Déconnexion</a>
    </div>
</header>

<main class="page">
    <?php if ($success): ?>
    <div class="success-banner show">
        <div class="success-icon">✓</div>
        <div>
            <div class="success-title">Article soumis avec succès !</div>
            <div class="success-msg">Votre numéro de référence : <span class="success-ref"><?= e($refNumber) ?></span></div>
            <div class="success-msg">Vous recevrez une notification par email lorsque l'évaluation commencera.</div>
            <?php if ($similarityAnalysisResults && isset($similarityAnalysisResults['highest_score']) && $similarityAnalysisResults['highest_score'] > 0): ?>
            <div class="success-msg" style="margin-top: 8px;">
                <strong>Score de similarité:</strong> 
                <span style="color: <?= isset($similarityAnalysisResults['highest_score']) && $similarityAnalysisResults['highest_score'] >= 60 ? 'var(--danger)' : (isset($similarityAnalysisResults['highest_score']) && $similarityAnalysisResults['highest_score'] >= 30 ? 'var(--warning)' : 'var(--success)') ?>">
                    <?= isset($similarityAnalysisResults['highest_score']) ? round($similarityAnalysisResults['highest_score']) : '0' ?>%
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($success && $showSimilarityWarning && !empty($similarArticlesFound)): ?>
        <?= renderSimilarArticlesWarning($similarArticlesFound) ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="error-box">
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="page-header">
        <h1>Soumettre <em>un article</em></h1>
        <p>Remplissez tous les champs obligatoires pour soumettre votre article à une conférence.</p>
    </div>

    <div class="layout-single">
        <div class="form-card">
            <div class="profile-banner"></div>
            <div class="profile-meta">
                <div class="profile-avatar-wrap">
                    <div class="profile-avatar"><?= e($userInitials) ?></div>
                </div>
                <div class="profile-name"><?= e($userPrenom) ?> <?= e($userNom) ?></div>
                <div class="profile-email"><?= e($userEmail) ?></div>
            </div>

            <div class="section-header">
                <div class="section-header-icon">✦</div>
                <div class="section-header-title">Nouvelle soumission</div>
            </div>

            <form method="POST" enctype="multipart/form-data" id="mainForm" novalidate class="form-body">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="current_step" id="currentStepInput" value="<?= $currentStep ?>">

                <div class="steps-bar">
                    <div class="step-pill" id="pill1"><div class="step-num">1</div><div class="step-txt">Conférence</div></div>
                    <div class="step-pill" id="pill2"><div class="step-num">2</div><div class="step-txt">Informations</div></div>
                    <div class="step-pill" id="pill3"><div class="step-num">3</div><div class="step-txt">Fichiers</div></div>
                    <div class="step-pill" id="pill4"><div class="step-num">4</div><div class="step-txt">Confirmation</div></div>
                </div>

                <!-- Step 1 -->
                <div class="step-content" id="sc1">
                    <div class="mini-section-title">Sélection de la conférence</div>
                    <div class="zonelass="form-group" style="margin-bottom:20px">
                        <div class="field-label-row">
                            <span class="field-label">Conférence <span class="field-required">*</span></span>
                        </div>
                        <select class="form-select" name="conference_id" id="selectConference" required onchange="showConfDates(this)">
                            <option value="">— Choisissez une conférence —</option>
                            <?php if (!empty($conferences)): ?>
                                <?php foreach ($conferences as $conf): ?>
                                    <option value="<?= (int)$conf['id'] ?>" <?= ($oldData['conferenceId'] ?? '') == $conf['id'] ? 'selected' : '' ?>
                                            data-sub-start="<?= e($conf['submission_start_date'] ?? '') ?>"
                                            data-sub-end="<?= e($conf['submission_deadline'] ?? '') ?>"
                                            data-conf-start="<?= e($conf['start_date'] ?? '') ?>"
                                            data-conf-end="<?= e($conf['end_date'] ?? '') ?>"
                                            data-location="<?= e($conf['location'] ?? '') ?>"
                                            data-organizer="<?= e($conf['organizer'] ?? '') ?>">
                                        <?= e($conf['name_fr']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option disabled>⚠️ Aucune conférence ouverte actuellement</option>
                            <?php endif; ?>
                        </select>
                        <div class="conf-dates-info" id="confDatesInfo">
                            <div class="conf-date-item"><div class="cdi-label">Ouverture soumissions</div><div class="cdi-value" id="cdiSubStart">—</div></div>
                            <div class="conf-date-item"><div class="cdi-label">Clôture soumissions</div><div class="cdi-value" id="cdiSubEnd">—</div></div>
                            <div class="conf-date-item"><div class="cdi-label">Statut</div><div class="cdi-value" id="cdiStatus">—</div></div>
                        </div>
                    </div>
                    <div class="form-grid cols-2">
                        <div class="form-group">
                            <div class="field-label-row"><span class="field-label">Domaine de recherche <span class="field-required">*</span></span></div>
                            <select class="form-select" name="domaine" required>
                                <option value="">— Sélectionnez un domaine —</option>
                                <?php foreach ($DOMAINES as $d): ?>
                                    <option value="<?= e($d) ?>" <?= ($oldData['domaine'] ?? '') === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <div class="field-label-row"><span class="field-label">Type de présentation</span></div>
                            <select class="form-select" name="type_presentation">
                                <?php foreach ($TYPES_PRESENTATION as $tp): ?>
                                    <option value="<?= e($tp) ?>" <?= ($oldData['typePresentation'] ?? '') === $tp ? 'selected' : '' ?>><?= e($tp) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-primary" onclick="if(validateStep1()) goToStep(2)">Continuer →</button>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="step-content" id="sc2">
                    <div class="mini-section-title">Informations de l'article</div>
                    <div class="form-group">
                        <div class="field-label-row"><span class="field-label">Titre en français <span class="field-required">*</span></span></div>
                        <input class="form-input" type="text" name="titre_fr" id="titreFr" value="<?= e($oldData['titreFr'] ?? '') ?>" placeholder="Titre de l'article en français" oninput="updateCharCount(this, 'countFr', 200)">
                        <div class="char-count"><span id="countFr"><?= mb_strlen($oldData['titreFr'] ?? '') ?></span> / 200</div>
                    </div>
                    <div class="form-group">
                        <div class="field-label-row"><span class="field-label">Titre en anglais <span class="field-required">*</span></span></div>
                        <input class="form-input" type="text" name="titre_en" id="titreEn" value="<?= e($oldData['titreEn'] ?? '') ?>" placeholder="Title in English" oninput="updateCharCount(this, 'countEn', 200)">
                        <div class="char-count"><span id="countEn"><?= mb_strlen($oldData['titreEn'] ?? '') ?></span> / 200</div>
                    </div>
                    <div class="form-group">
                        <div class="field-label-row"><span class="field-label">Titre en arabe <span style="color:var(--muted);font-weight:400;">(optionnel)</span></span></div>
                        <input class="form-input" dir="rtl" type="text" name="titre_ar" value="<?= e($oldData['titreAr'] ?? '') ?>" placeholder="عنوان المقالة باللغة العربية" oninput="updateCharCount(this, 'countAr', 200)">
                        <div class="char-count"><span id="countAr"><?= mb_strlen($oldData['titreAr'] ?? '') ?></span> / 200</div>
                    </div>
                    <div class="form-grid cols-2">
                        <div class="form-group">
                            <div class="field-label-row"><span class="field-label">Résumé en français <span class="field-required">*</span></span></div>
                            <textarea class="form-textarea" name="resume_fr" id="resumeFr" placeholder="Résumé de l'article en français (min. 50 caractères)..." oninput="updateCharCount(this, 'countAbsFr', 500)"><?= e($oldData['resumeFr'] ?? '') ?></textarea>
                            <div class="char-count"><span id="countAbsFr"><?= mb_strlen($oldData['resumeFr'] ?? '') ?></span> / 500</div>
                        </div>
                        <div class="form-group">
                            <div class="field-label-row"><span class="field-label">Abstract in English <span class="field-required">*</span></span></div>
                            <textarea class="form-textarea" name="resume_en" id="resumeEn" placeholder="Abstract in English (min. 50 characters)..." oninput="updateCharCount(this, 'countAbsEn', 500)"><?= e($oldData['resumeEn'] ?? '') ?></textarea>
                            <div class="char-count"><span id="countAbsEn"><?= mb_strlen($oldData['resumeEn'] ?? '') ?></span> / 500</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="field-label-row"><span class="field-label">Mots-clés <span class="field-required">*</span></span></div>
                        <input class="form-input" type="text" name="mots_cles" id="motsCles" value="<?= e($oldData['motsCles'] ?? '') ?>" placeholder="ex: intelligence artificielle, apprentissage automatique, NLP">
                        <div class="char-count" style="text-align:left;">Séparez par des virgules — 3 à 8 mots-clés recommandés</div>
                    </div>

                    <div class="mini-section-title">Co-auteurs <span style="font-weight:400;text-transform:none;">(optionnel)</span></div>
                    <div id="coauthorList"></div>
                    <button type="button" class="add-btn" onclick="addCoauthor()">＋ Ajouter un co-auteur</button>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="goToStep(1)">← Retour</button>
                        <button type="button" class="btn-primary" onclick="if(validateStep2()) goToStep(3)">Continuer →</button>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="step-content" id="sc3">
                    <div class="mini-section-title">Fichier principal</div>
                    <div class="form-group">
                        <div class="field-label-row"><span class="field-label">Article (PDF ou Word) <span class="field-required">*</span></span></div>
                        <div class="upload-zone" id="mainZone">
                            <input type="file" name="fichier_principal" id="mainFile" accept=".pdf,.doc,.docx" onchange="handleFile(this, 'mainFiles', false)">
                            <div class="upload-icon">📄</div>
                            <div class="upload-label">Cliquez ou glissez-déposez votre article</div>
                            <div class="upload-hint">PDF, DOC ou DOCX — Max 20 Mo</div>
                        </div>
                        <div class="file-list" id="mainFiles"></div>
                    </div>

                    <div class="mini-section-title">Fichiers supplémentaires <span style="font-weight:400;text-transform:none;">(optionnel)</span></div>
                    <div class="form-group">
                        <div class="upload-zone" id="suppZone">
                            <input type="file" name="fichiers_supp[]" id="suppFiles" accept=".pdf,.doc,.docx,.zip,.png,.jpg,.jpeg,.xlsx,.csv" multiple onchange="handleMultipleFiles(this, 'suppFilesList')">
                            <div class="upload-icon">📎</div>
                            <div class="upload-label">Données, images, annexes…</div>
                            <div class="upload-hint">PDF, Word, ZIP, images, Excel — Max 50 Mo par fichier</div>
                        </div>
                        <div class="file-list" id="suppFilesList"></div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="goToStep(2)">← Retour</button>
                        <button type="button" class="btn-primary" onclick="if(validateStep3()) goToStep(4)">Continuer →</button>
                    </div>
                </div>

                <!-- Step 4 -->
                <div class="step-content" id="sc4">
                    <div class="mini-section-title">Déclarations obligatoires</div>
                    <div class="checkbox-group">
                        <label class="checkbox-item"><input type="checkbox" name="chk_original" id="chkOriginal" <?= isset($_POST['chk_original']) ? 'checked' : '' ?>><span>Je certifie que cet article est original et n'a pas été publié ni soumis simultanément dans une autre revue ou conférence.</span></label>
                        <label class="checkbox-item"><input type="checkbox" name="chk_terms" id="chkTerms" <?= isset($_POST['chk_terms']) ? 'checked' : '' ?>><span>J'accepte les conditions de publication et le processus d'évaluation par les pairs de cette conférence.</span></label>
                        <label class="checkbox-item"><input type="checkbox" name="chk_authors" id="chkAuthors" <?= isset($_POST['chk_authors']) ? 'checked' : '' ?>><span>Tous les co-auteurs ont été informés et ont consenti à cette soumission.</span></label>
                    </div>
                    <div class="notice-box">En soumettant cet article, vous reconnaissez que toutes les informations fournies sont exactes et complètes. Toute falsification peut entraîner le rejet immédiat et la notification de votre institution.</div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="goToStep(3)">← Retour</button>
                        <button type="submit" class="btn-primary">✓ Soumettre l'article</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</main>

<footer class="footer">
    © <?= date('Y') ?> ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés
</footer>

<script>
    let currentStep = 1;
    const TOTAL_STEPS = 4;

    document.addEventListener('DOMContentLoaded', () => {
        const savedStep = parseInt(document.getElementById('currentStepInput').value);
        if (savedStep >= 1 && savedStep <= TOTAL_STEPS) {
            goToStep(savedStep);
        } else {
            goToStep(1);
        }
    });

    function goToStep(next) {
        const currentStepElem = document.getElementById('sc' + currentStep);
        if (currentStepElem) currentStepElem.classList.remove('active');
        
        const currentPill = document.getElementById('pill' + currentStep);
        if (currentPill) {
            currentPill.classList.remove('active');
            if (next > currentStep) {
                currentPill.classList.add('done');
            } else {
                currentPill.classList.remove('done');
            }
        }
        
        currentStep = next;
        
        const nextStepElem = document.getElementById('sc' + currentStep);
        if (nextStepElem) nextStepElem.classList.add('active');
        
        const nextPill = document.getElementById('pill' + currentStep);
        if (nextPill) {
            nextPill.classList.add('active');
            nextPill.classList.remove('done');
        }
        
        const stepInput = document.getElementById('currentStepInput');
        if (stepInput) stepInput.value = currentStep;
        
        document.getElementById('mainForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function validateStep1() {
        const conference = document.getElementById('selectConference').value;
        const domaine = document.querySelector('select[name="domaine"]').value;
        
        if (!conference) {
            alert('Veuillez sélectionner une conférence.');
            document.getElementById('selectConference').focus();
            return false;
        }
        
        if (!domaine) {
            alert('Veuillez sélectionner un domaine de recherche.');
            document.querySelector('select[name="domaine"]').focus();
            return false;
        }
        
        return true;
    }

    function validateStep2() {
        const titreFr = document.getElementById('titreFr').value.trim();
        const titreEn = document.getElementById('titreEn').value.trim();
        const resumeFr = document.getElementById('resumeFr').value.trim();
        const resumeEn = document.getElementById('resumeEn').value.trim();
        const motsCles = document.getElementById('motsCles').value.trim();
        
        if (titreFr.length < 5) {
            alert('Le titre en français doit contenir au moins 5 caractères.');
            document.getElementById('titreFr').focus();
            return false;
        }
        
        if (titreEn.length < 5) {
            alert('Le titre en anglais doit contenir au moins 5 caractères.');
            document.getElementById('titreEn').focus();
            return false;
        }
        
        if (resumeFr.length < 50) {
            alert('Le résumé en français doit contenir au moins 50 caractères.');
            document.getElementById('resumeFr').focus();
            return false;
        }
        
        if (resumeEn.length < 50) {
            alert('L\'abstract en anglais doit contenir au moins 50 caractères.');
            document.getElementById('resumeEn').focus();
            return false;
        }
        
        if (!motsCles) {
            alert('Veuillez saisir des mots-clés.');
            document.getElementById('motsCles').focus();
            return false;
        }
        
        return true;
    }

    function validateStep3() {
        const mainFile = document.getElementById('mainFile');
        
        if (!mainFile.files || mainFile.files.length === 0) {
            alert('Veuillez télécharger le fichier principal de l\'article (PDF ou Word).');
            return false;
        }
        
        const file = mainFile.files[0];
        const ext = file.name.split('.').pop().toLowerCase();
        const allowedExts = ['pdf', 'doc', 'docx'];
        
        if (!allowedExts.includes(ext)) {
            alert('Le fichier principal doit être au format PDF, DOC ou DOCX.');
            return false;
        }
        
        if (file.size > 20 * 1024 * 1024) {
            alert('Le fichier principal ne doit pas dépasser 20 Mo.');
            return false;
        }
        
        return true;
    }

    function updateCharCount(input, countId, max) {
        const len = input.value.length;
        const el = document.getElementById(countId);
        if (el) {
            el.textContent = len;
            el.style.color = len > max ? 'var(--danger)' : 'var(--muted)';
        }
    }

    function showConfDates(select) {
        const opt = select.options[select.selectedIndex];
        const box = document.getElementById('confDatesInfo');
        if (!opt.value) {
            box.classList.remove('show');
            return;
        }
        const subStart = opt.dataset.subStart || '';
        const subEnd = opt.dataset.subEnd || '';
        document.getElementById('cdiSubStart').textContent = subStart ? fmtDate(subStart) : '—';
        document.getElementById('cdiSubEnd').textContent = subEnd ? fmtDate(subEnd) : '—';
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const start = subStart ? new Date(subStart) : null;
        const end = subEnd ? new Date(subEnd) : null;
        const statusEl = document.getElementById('cdiStatus');
        if (end && today > end) {
            statusEl.textContent = 'Clôturées';
            statusEl.style.color = 'var(--danger)';
        } else if (start && today < start) {
            statusEl.textContent = 'Pas encore ouvertes';
            statusEl.style.color = 'var(--warning)';
        } else {
            statusEl.textContent = 'Ouvertes ✓';
            statusEl.style.color = 'var(--success)';
        }
        box.classList.add('show');
    }

    function fmtDate(iso) {
        const [y, m, d] = iso.split('-');
        const months = ['jan.', 'fév.', 'mar.', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sep.', 'oct.', 'nov.', 'déc.'];
        return `${parseInt(d)} ${months[parseInt(m) - 1]} ${y}`;
    }

    function handleFile(input, listId, multiple) {
        const list = document.getElementById(listId);
        if (!multiple) list.innerHTML = '';
        
        if (input.files && input.files.length > 0) {
            const file = input.files[0];
            const div = document.createElement('div');
            div.className = 'file-item';
            const size = (file.size / 1024 / 1024).toFixed(2);
            div.innerHTML = `
                <span class="file-icon">📄</span>
                <span class="file-name">${escapeHtml(file.name)}</span>
                <span class="file-size">${size} Mo</span>
                <button type="button" class="file-remove" onclick="clearMainFile()">✕</button>
            `;
            list.appendChild(div);
        }
    }
    
    function handleMultipleFiles(input, listId) {
        const list = document.getElementById(listId);
        list.innerHTML = '';
        
        if (input.files && input.files.length > 0) {
            Array.from(input.files).forEach(file => {
                const div = document.createElement('div');
                div.className = 'file-item';
                const size = (file.size / 1024 / 1024).toFixed(2);
                div.innerHTML = `
                    <span class="file-icon">📎</span>
                    <span class="file-name">${escapeHtml(file.name)}</span>
                    <span class="file-size">${size} Mo</span>
                    <button type="button" class="file-remove" onclick="this.parentElement.remove()">✕</button>
                `;
                list.appendChild(div);
            });
        }
    }
    
    function clearMainFile() {
        const mainFileInput = document.getElementById('mainFile');
        mainFileInput.value = '';
        document.getElementById('mainFiles').innerHTML = '';
    }

    function addCoauthor() {
        const list = document.getElementById('coauthorList');
        const div = document.createElement('div');
        div.className = 'coauthor-row';
        div.innerHTML = `
            <div class="form-group"><div class="field-label-row"><span class="field-label">Nom complet</span></div><input class="form-input" type="text" name="co_nom[]" placeholder="Nom du co-auteur"></div>
            <div class="form-group"><div class="field-label-row"><span class="field-label">Email</span></div><input class="form-input" type="email" name="co_email[]" placeholder="email@institution.dz"></div>
            <div class="form-group"><div class="field-label-row"><span class="field-label">Institution</span></div><input class="form-input" type="text" name="co_institution[]" placeholder="Université / Institut"></div>
            <button type="button" class="remove-btn" onclick="this.closest('.coauthor-row').remove()">✕</button>
        `;
        list.appendChild(div);
    }

    document.querySelectorAll('.upload-zone').forEach(zone => {
        zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
        zone.addEventListener('drop', e => {
            e.preventDefault();
            zone.classList.remove('dragover');
            const input = zone.querySelector('input[type="file"]');
            if (input && e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change'));
            }
        });
    });

    function escapeHtml(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    document.addEventListener('DOMContentLoaded', () => {
        const sel = document.getElementById('selectConference');
        if (sel && sel.value) showConfDates(sel);
        
        <?php if (!empty($coNoms)): ?>
            <?php foreach ($coNoms as $idx => $nom): ?>
                <?php if (!empty($nom)): ?>
                    addCoauthor();
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    });
</script>

</body>
</html>