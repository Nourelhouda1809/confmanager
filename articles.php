<?php
require_once 'config.php';
requireRole('reviewer');

$reviewerId = getCurrentUserId();
$action = $_GET['action'] ?? '';
$articleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token de sécurité invalide');
        redirect('articles.php');
    }

    $articleId = (int)($_POST['article_id'] ?? 0);
    $reviewType = $_POST['review_type'] ?? 'first';

    if ($reviewType === 'first') {
        // First review submission
        $originality = (int)($_POST['originality'] ?? 0);
        $methodology = (int)($_POST['methodology'] ?? 0);
        $quality = (int)($_POST['quality'] ?? 0);
        $significance = (int)($_POST['significance'] ?? 0);
        $language = (int)($_POST['language'] ?? 0);
        $format = (int)($_POST['format'] ?? 0);
        $strengths = cleanInput($_POST['strengths'] ?? '');
        $weaknesses = cleanInput($_POST['weaknesses'] ?? '');
        $suggestions = cleanInput($_POST['suggestions'] ?? '');
        $decision = $_POST['decision'] ?? '';

        if (!in_array($decision, ['accept', 'minor_revision', 'major_revision', 'reject'])) {
            setFlash('error', 'Décision invalide');
            redirect('articles.php?action=review&id=' . $articleId);
        }

        $comment = "Points forts:
$strengths

Points faibles:
$weaknesses

Suggestions:
$suggestions

";
        $comment .= "Notes: Originalité=$originality/5, Méthodologie=$methodology/5, Qualité=$quality/5, ";
        $comment .= "Significance=$significance/5, Langue=$language/5, Format=$format/5";

        // Insert review
        $stmt = $pdo->prepare("INSERT INTO reviews (article_id, evaluator_id, comment, recommendation, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$articleId, $reviewerId, $comment, $decision]);

        // Update article status
        if ($decision === 'major_revision') {
            $newStatus = 'revision';
            $stmt = $pdo->prepare("UPDATE articles SET status = 'revision', final_decision = 'revision' WHERE id = ?");
            $stmt->execute([$articleId]);

            // Notify author
            $stmt = $pdo->prepare("SELECT author_email, title FROM articles WHERE id = ?");
            $stmt->execute([$articleId]);
            $article = $stmt->fetch();
            if ($article) {
                sendNotification(
                    $article['author_email'] ? getUserIdByEmail($article['author_email']) : 0,
                    'revision',
                    "Révisions majeures demandées pour : " . $article['title'],
                    $articleId
                );
            }
            setFlash('success', 'Révisions majeures demandées. L\'auteur a été notifié.');
        } else {
            // accept -> accepted, minor_revision -> revision (needs small fixes), reject -> rejected
            $finalDecision = ($decision === 'accept') ? 'accepted' : (($decision === 'minor_revision') ? 'revision' : 'rejected');
            $newStatus = ($decision === 'accept') ? 'accepted' : (($decision === 'minor_revision') ? 'revision' : 'rejected');
            $stmt = $pdo->prepare("UPDATE articles SET status = ?, final_decision = ? WHERE id = ?");
            $stmt->execute([$newStatus, $finalDecision, $articleId]);

            // Update article_reviewers
            $stmt = $pdo->prepare("UPDATE article_reviewers SET completed_at = NOW() WHERE article_id = ? AND evaluator_id = ?");
            $stmt->execute([$articleId, $reviewerId]);

            setFlash('success', 'Évaluation soumise avec succès !');
        }

        redirect('articles.php');

    } elseif ($reviewType === 'rereview') {
        // Re-review submission
        $decision = $_POST['decision'] ?? '';
        $finalComment = cleanInput($_POST['final_comment'] ?? '');

        if (!in_array($decision, ['accept', 'reject'])) {
            setFlash('error', 'Décision finale invalide');
            redirect('articles.php?action=rereview&id=' . $articleId);
        }

        $comment = "Ré-évaluation après révision:
$finalComment";

        // Insert new review
        $stmt = $pdo->prepare("INSERT INTO reviews (article_id, evaluator_id, comment, recommendation, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$articleId, $reviewerId, $comment, $decision]);

        // Update article
        $finalDecision = $decision === 'accept' ? 'accepted' : 'rejected';
        $stmt = $pdo->prepare("UPDATE articles SET status = 'review', final_decision = ?, final_comment = ?, decision_date = NOW(), decided_by = ? WHERE id = ?");
        $stmt->execute([$finalDecision, $finalComment, $reviewerId, $articleId]);

        // Update article_reviewers
        $stmt = $pdo->prepare("UPDATE article_reviewers SET completed_at = NOW() WHERE article_id = ? AND evaluator_id = ?");
        $stmt->execute([$articleId, $reviewerId]);

        // Notify author
        $stmt = $pdo->prepare("SELECT author_email, title FROM articles WHERE id = ?");
        $stmt->execute([$articleId]);
        $article = $stmt->fetch();
        if ($article) {
            $notifType = $decision === 'accept' ? 'accepted' : 'rejected';
            $notifMsg = $decision === 'accept' 
                ? "Félicitations ! Votre article a été accepté : " . $article['title']
                : "Votre article a été rejeté : " . $article['title'];
            sendNotification(
                $article['author_email'] ? getUserIdByEmail($article['author_email']) : 0,
                $notifType,
                $notifMsg,
                $articleId
            );
        }

        setFlash('success', 'Décision finale soumise avec succès !');
        redirect('articles.php');
    }
}

// Fetch all assigned articles
$stmt = $pdo->prepare("
    SELECT a.*, c.name_fr as conference_name, c.name_en, c.review_end_date, t.name as topic_name,
           ar.assigned_at, ar.completed_at,
           r.recommendation as submitted_decision, r.comment as review_comment, r.created_at as review_date
    FROM article_reviewers ar
    JOIN articles a ON ar.article_id = a.id
    LEFT JOIN conferences c ON a.conference_id = c.id
    LEFT JOIN topics t ON a.topic_id = t.id
    LEFT JOIN reviews r ON r.article_id = a.id AND r.evaluator_id = ?
    WHERE ar.evaluator_id = ?
    ORDER BY a.submission_date DESC
");
$stmt->execute([$reviewerId, $reviewerId]);
$assignments = $stmt->fetchAll();

$formattedAssignments = [];
foreach ($assignments as $a) {
    $deadline = new DateTime($a['review_end_date'] ?? '+30 days');
    $now = new DateTime();
    $diff = $now->diff($deadline);
    $daysLeft = $diff->days * ($diff->invert ? -1 : 1);

    if ($daysLeft < 0) {
        $deadlineLabel = 'Terminé';
        $deadlineUrgency = 'ok';
    } elseif ($daysLeft <= 2) {
        $deadlineLabel = 'Dans ' . $daysLeft . ' jour' . ($daysLeft > 1 ? 's' : '');
        $deadlineUrgency = 'urgent';
    } elseif ($daysLeft <= 7) {
        $deadlineLabel = 'Dans ' . $daysLeft . ' jours';
        $deadlineUrgency = 'soon';
    } else {
        $deadlineLabel = 'Dans ' . $daysLeft . ' jours';
        $deadlineUrgency = 'ok';
    }

    if ($a['submitted_decision']) {
        $status = 'done';
        $statusLabel = 'Évalué';
    } elseif ($a['status'] === 'revision') {
        $status = 'revised';
        $statusLabel = 'À re-évaluer';
    } else {
        $status = 'pending';
        $statusLabel = 'À évaluer';
    }

    $formattedAssignments[] = [
        'id' => $a['id'],
        'ref' => generateRef($a['id']),
        'title' => $a['title'],
        'conf' => $a['conference_name'] ?? $a['name_en'],
        'specialty' => $a['topic_name'] ?? 'Non spécifié',
        'abstract' => $a['abstract'] ?? '',
        'authors' => $a['author'],
        'file' => basename($a['file_path'] ?? 'article.pdf'),
        'fileSize' => '2.0 Mo',
        'deadline' => $a['review_end_date'] ?? date('Y-m-d', strtotime('+30 days')),
        'deadlineLabel' => $deadlineLabel,
        'deadlineUrgency' => $deadlineUrgency,
        'status' => $status,
        'statusLabel' => $statusLabel,
        'assignedDate' => formatDateFr($a['assigned_at']),
        'isRevised' => ($a['status'] === 'revision'),
        'submittedDecision' => $a['submitted_decision'],
        'authorResponse' => $a['review_comment'] ?? '',
        'revisedDate' => $a['review_date'] ? formatDateFr($a['review_date']) : ''
    ];
}

// Count statistics for filters
$counts = ['all' => count($formattedAssignments), 'pending' => 0, 'revised' => 0, 'done' => 0];
foreach ($formattedAssignments as $a) {
    if ($a['status'] === 'pending') $counts['pending']++;
    elseif ($a['status'] === 'revised') $counts['revised']++;
    elseif ($a['status'] === 'done') $counts['done']++;
}

// Get current article for modal if action specified
$currentArticle = null;
if ($articleId > 0 && $action) {
    $currentArticle = array_values(array_filter($formattedAssignments, fn($a) => $a['id'] === $articleId))[0] ?? null;
}

function renderRow($a) {
    $dlClass = $a['deadlineUrgency'] === 'urgent' ? 'deadline-cell urgent' : ($a['deadlineUrgency'] === 'soon' ? 'deadline-cell soon' : 'deadline-cell ok');
    if ($a['status'] === 'done') {
        $actionBtn = '<button class="action-btn" onclick="openDetailModal(' . $a['id'] . ')"><i class="fas fa-eye"></i> Voir</button>';
    } elseif ($a['status'] === 'revised') {
        $actionBtn = '<button class="action-btn revised-btn" onclick="openReReviewModal(' . $a['id'] . ')"><i class="fas fa-sync-alt"></i> Re-évaluer</button>';
    } else {
        $actionBtn = '<button class="action-btn primary" onclick="openReviewForm(' . $a['id'] . ')"><i class="fas fa-play"></i> Commencer</button>';
    }
    return '
    <div class="article-row" data-status="' . $a['status'] . '" data-title="' . strtolower(htmlspecialchars($a['title'])) . '" data-deadline="' . $a['deadline'] . '">
      <div class="td">
        <div class="article-title-main">' . htmlspecialchars($a['title']) . '</div>
        <div class="article-meta-row">
          <span class="article-ref">' . $a['ref'] . '</span>
          <span class="article-conf">' . htmlspecialchars($a['conf']) . '</span>
          ' . ($a['isRevised'] ? '<span class="badge revised" style="font-size:10px;padding:2px 7px"><span class="badge-dot"></span>Révisé</span>' : '') . '
        </div>
      </div>
      <div class="td td-domain">' . htmlspecialchars($a['specialty']) . '</div>
      <div class="td"><span class="' . $dlClass . '"><i class="fas fa-calendar-alt" style="margin-right:5px;opacity:.7"></i>' . $a['deadlineLabel'] . '</span></div>
      <div class="td"><span class="badge ' . $a['status'] . '"><span class="badge-dot"></span>' . $a['statusLabel'] . '</span></div>
      <div class="td"><div class="row-actions">' . $actionBtn . '</div></div>
    </div>';
}

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Articles Assignés</title>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root {
    --navy: #0d2137; --navy-mid: #1a3a5c; --gold: #c9a84c; --gold-light: #e2c97e;
    --bg: #f0f2f5; --white: #ffffff; --border: #e2e8f0; --muted: #7a8fa6;
    --text: #1a2e44; --text-light: #4a607a; --accent: #2c6fad;
    --danger: #d94040; --success: #2a9d8f; --warning: #d4830a;
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
  .logout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 18px; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 500; color: var(--text-light); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all 0.15s; text-decoration: none; }
  .logout-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--white); }
  .page { max-width: 1100px; margin: 0 auto; padding: 40px 32px 60px; }
  .page-header { margin-bottom: 28px; }
  .page-header-row { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 6px; }
  .page-header h1 { font-family: 'Libre Baskerville', serif; font-size: 32px; font-weight: 700; color: var(--navy); letter-spacing: -0.5px; }
  .page-header h1 em { font-style: italic; color: var(--gold); }
  .page-header p { font-size: 14px; color: var(--muted); margin-top: 4px; }
  .filters-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
  .filter-tabs { display: flex; gap: 4px; background: var(--white); border: 1px solid var(--border); border-radius: 20px; padding: 4px; box-shadow: var(--shadow-sm); }
  .filter-tab { padding: 7px 16px; font-size: 13px; font-weight: 500; color: var(--muted); background: none; border: none; border-radius: 16px; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all 0.15s; display: flex; align-items: center; gap: 6px; }
  .filter-tab:hover { color: var(--navy); }
  .filter-tab.active { background: var(--navy); color: var(--gold-light); }
  .filter-count { font-size: 10px; font-weight: 700; background: rgba(255,255,255,0.2); padding: 1px 5px; border-radius: 8px; }
  .filter-tab:not(.active) .filter-count { background: var(--bg); color: var(--muted); }
  .filters-right { margin-left: auto; display: flex; gap: 10px; }
  .sort-select { padding: 8px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; font-family: 'DM Sans', sans-serif; color: var(--text-light); background: var(--white); outline: none; cursor: pointer; }
  .articles-table-wrap { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
  .table-head { display: grid; grid-template-columns: 2.2fr 1fr 1fr 120px 140px; background: var(--bg); border-bottom: 1px solid var(--border); }
  .th { padding: 12px 18px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); }
  .th:first-child { padding-left: 24px; }
  .th:last-child { padding-right: 24px; text-align: right; }
  .article-row { display: grid; grid-template-columns: 2.2fr 1fr 1fr 120px 140px; border-bottom: 1px solid var(--border); transition: background 0.12s; align-items: center; }
  .article-row:last-child { border-bottom: none; }
  .article-row:hover { background: #fafbfd; }
  .td { padding: 16px 18px; }
  .td:first-child { padding-left: 24px; }
  .td:last-child { padding-right: 24px; }
  .article-title-main { font-size: 14px; font-weight: 600; color: var(--navy); margin-bottom: 4px; line-height: 1.35; }
  .article-meta-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .article-ref { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--muted); background: var(--bg); padding: 2px 7px; border-radius: 3px; border: 1px solid var(--border); }
  .article-conf { font-size: 12px; color: var(--text-light); }
  .td-domain { font-size: 13px; color: var(--text-light); }
  .deadline-cell { font-size: 12.5px; font-weight: 500; }
  .deadline-cell.urgent { color: var(--danger); }
  .deadline-cell.soon { color: var(--warning); }
  .deadline-cell.ok { color: var(--success); }
  .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
  .badge-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
  .badge.pending { background: #fef8ec; color: #9a5f00; border: 1px solid #f5d98a; }
  .badge.pending .badge-dot { background: var(--warning); }
  .badge.reviewing { background: #eef3fb; color: #1a4a7a; border: 1px solid #b3cdf0; }
  .badge.reviewing .badge-dot { background: var(--accent); }
  .badge.done { background: #e8f6f3; color: #1a5f57; border: 1px solid #9dd8d0; }
  .badge.done .badge-dot { background: var(--success); }
  .badge.revised { background: #f0f4ff; color: #3347bb; border: 1px solid #bcc7fa; }
  .badge.revised .badge-dot { background: #5b6ef5; }
  .row-actions { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
  .action-btn { height: 30px; padding: 0 10px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: none; color: var(--muted); cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 5px; transition: all 0.15s; font-family: 'DM Sans', sans-serif; white-space: nowrap; }
  .action-btn:hover { background: var(--bg); color: var(--navy); border-color: var(--navy); }
  .action-btn.primary { background: var(--navy); color: var(--gold-light); border-color: var(--navy); }
  .action-btn.primary:hover { background: var(--navy-mid); }
  .action-btn.revised-btn { border-color: #bcc7fa; color: #5b6ef5; }
  .action-btn.revised-btn:hover { background: #f0f4ff; color: #3347bb; }
  .pagination { display: flex; align-items: center; justify-content: space-between; margin-top: 24px; padding: 0 4px; }
  .page-info { font-size: 13px; color: var(--muted); }
  .page-btns { display: flex; gap: 4px; }
  .page-btn { width: 34px; height: 34px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--white); color: var(--text-light); font-size: 13px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-family: 'DM Sans', sans-serif; transition: all 0.15s; }
  .page-btn:hover { border-color: var(--navy); color: var(--navy); }
  .page-btn.active { background: var(--navy); color: var(--gold-light); border-color: var(--navy); }
  .modal-backdrop { position: fixed; inset: 0; background: rgba(13,33,55,0.5); backdrop-filter: blur(4px); z-index: 200; display: none; align-items: center; justify-content: center; padding: 20px; }
  .modal-backdrop.open { display: flex; }
  .modal { background: var(--white); border-radius: var(--radius); width: 100%; max-width: 580px; box-shadow: var(--shadow-md); animation: fadeUp 0.2s ease; max-height: 90vh; overflow-y: auto; }
  .modal.modal-wide { max-width: 700px; }
  .modal.modal-xl { max-width: 900px; }
  .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; background: var(--white); z-index: 1; }
  .modal-title { font-family: 'Libre Baskerville', serif; font-size: 18px; color: var(--navy); }
  .modal-close { width: 30px; height: 30px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: none; color: var(--muted); cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; transition: all 0.15s; flex-shrink: 0; }
  .modal-close:hover { background: var(--bg); color: var(--navy); }
  .modal-body { padding: 24px; }
  .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; position: sticky; bottom: 0; background: var(--white); }
  .modal-detail-row { display: flex; gap: 10px; margin-bottom: 14px; align-items: flex-start; }
  .modal-detail-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); width: 110px; flex-shrink: 0; padding-top: 2px; }
  .modal-detail-value { font-size: 13.5px; color: var(--text); flex: 1; line-height: 1.5; }
  .btn-primary { background: var(--navy); color: var(--gold-light); border: none; border-radius: var(--radius-sm); padding: 11px 22px; font-size: 13.5px; font-weight: 500; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; white-space: nowrap; }
  .btn-primary:hover { background: var(--navy-mid); }
  .btn-primary:disabled { opacity: .5; cursor: not-allowed; }
  .btn-secondary { background: none; color: var(--text-light); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 9px 20px; font-size: 13.5px; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s; }
  .btn-secondary:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }
  .form-section { margin-bottom: 28px; }
  .form-section-title { font-size: 13px; font-weight: 600; color: var(--navy); text-transform: uppercase; letter-spacing: .8px; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 8px; }
  .form-section-title i { color: var(--gold); }
  .criteria-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .criteria-item { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 16px; }
  .criteria-label { font-size: 12.5px; font-weight: 500; color: var(--text-light); margin-bottom: 10px; }
  .star-row { display: flex; gap: 6px; }
  .star-btn { font-size: 20px; cursor: pointer; color: var(--border); background: none; border: none; padding: 0; transition: all 0.1s; line-height: 1; }
  .star-btn.active, .star-btn:hover { color: var(--gold); }
  .star-value { font-size: 12px; color: var(--muted); margin-left: 6px; align-self: center; font-family: 'DM Mono', monospace; }
  .form-group { margin-bottom: 16px; }
  .form-label { font-size: 12.5px; font-weight: 500; color: var(--text-light); margin-bottom: 6px; display: block; }
  .form-label span { color: var(--muted); font-weight: 400; }
  .form-textarea { width: 100%; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px 14px; font-size: 13px; font-family: 'DM Sans', sans-serif; color: var(--text); resize: vertical; min-height: 80px; outline: none; background: var(--white); transition: border-color 0.15s; }
  .form-textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(44,111,173,0.08); }
  .decision-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .decision-card { padding: 14px 16px; border: 2px solid var(--border); border-radius: var(--radius); cursor: pointer; transition: all 0.2s; display: flex; align-items: flex-start; gap: 12px; }
  .decision-card:hover { border-color: var(--accent); background: #f5f8ff; }
  .decision-card.selected { border-color: var(--navy); background: #f0f4fb; }
  .decision-card.selected.accept { border-color: var(--success); background: #e8f6f3; }
  .decision-card.selected.minor { border-color: var(--warning); background: #fef8ec; }
  .decision-card.selected.major { border-color: #5b6ef5; background: #f0f4ff; }
  .decision-card.selected.reject { border-color: var(--danger); background: #fdf2f2; }
  .decision-icon { font-size: 22px; flex-shrink: 0; margin-top: 2px; }
  .decision-title { font-size: 13px; font-weight: 600; color: var(--navy); margin-bottom: 3px; }
  .decision-sub { font-size: 11.5px; color: var(--muted); line-height: 1.4; }
  .download-zone { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px 20px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
  .download-icon { font-size: 28px; color: var(--danger); flex-shrink: 0; }
  .download-info { flex: 1; }
  .download-name { font-size: 13.5px; font-weight: 600; color: var(--navy); }
  .download-meta { font-size: 12px; color: var(--muted); margin-top: 2px; }
  .download-btn { padding: 8px 18px; background: var(--navy); color: var(--gold-light); border: none; border-radius: var(--radius-sm); font-size: 13px; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s; display: flex; align-items: center; gap: 7px; white-space: nowrap; }
  .download-btn:hover { background: var(--navy-mid); }
  .version-compare { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; margin-bottom: 20px; }
  .version-tabs { display: flex; gap: 8px; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 12px; }
  .version-tab { padding: 8px 16px; border: none; background: none; font-family: 'DM Sans', sans-serif; font-size: 13px; color: var(--muted); cursor: pointer; border-radius: var(--radius-sm); transition: all 0.15s; }
  .version-tab:hover { color: var(--navy); background: var(--white); }
  .version-tab.active { background: var(--navy); color: var(--gold-light); }
  .version-content { display: none; }
  .version-content.active { display: block; }
  .revised-banner { background: #f0f4ff; border: 1px solid #bcc7fa; border-left: 4px solid #5b6ef5; border-radius: var(--radius-sm); padding: 14px 18px; margin-bottom: 18px; }
  .revised-banner-title { font-size: 13px; font-weight: 600; color: #3347bb; margin-bottom: 4px; }
  .revised-banner-text { font-size: 12.5px; color: var(--text-light); line-height: 1.55; }
  .toast-wrap { position: fixed; bottom: 28px; right: 28px; display: flex; flex-direction: column; gap: 10px; z-index: 500; pointer-events: none; }
  .toast { background: var(--navy); color: white; padding: 12px 18px; border-radius: var(--radius); font-size: 13.5px; box-shadow: var(--shadow-md); display: flex; align-items: center; gap: 10px; animation: toastIn 0.25s ease, toastOut 0.25s ease 3.5s forwards; pointer-events: auto; max-width: 380px; }
  .toast.success { background: var(--success); }
  .toast.error { background: var(--danger); }
  .toast i { font-size: 15px; }
  .footer { background: var(--navy); color: rgba(255,255,255,0.45); text-align: center; padding: 22px; font-size: 13px; margin-top: 48px; }
  @keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:none} }
  @keyframes toastIn { from{opacity:0;transform:translateX(30px)} to{opacity:1;transform:none} }
  @keyframes toastOut { from{opacity:1;transform:none} to{opacity:0;transform:translateX(30px)} }
  @media(max-width:900px) { .table-head,.article-row { grid-template-columns: 2fr 120px 140px; } .td-domain,.th:nth-child(2) { display:none; } .criteria-grid { grid-template-columns: 1fr; } }
  @media(max-width:650px) { .topbar { padding: 0 16px; } .page { padding: 24px 16px; } .decision-grid { grid-template-columns: 1fr; } .filters-right { display: none; } }
</style>
</head>
<body>

<header class="topbar">
  <a class="brand" href="reviewer_dashboard.php">
    <div class="brand-icon">📋</div>
    <span class="brand-name">ConfManager</span>
  </a>
  <nav class="nav-links">
    <a href="reviewer_dashboard.php" class="nav-link">Tableau de bord</a>
    <a href="articles.php" class="nav-link active">Articles assignés</a>
    <a href="base-articles.php" class="nav-link">Base d'articles</a>
    <a href="profile.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</header>

<main class="page">
  <div class="page-header">
    <div class="page-header-row">
      <h1>Articles <em>assignés</em></h1>
    </div>
    <p>Articles qui vous ont été confiés pour évaluation</p>
  </div>

  <div class="filters-bar">
    <div class="filter-tabs">
      <button class="filter-tab active" data-filter="all" onclick="setFilter(this,'all')">Tous <span class="filter-count" id="cnt-all"><?php echo $counts['all']; ?></span></button>
      <button class="filter-tab" data-filter="pending" onclick="setFilter(this,'pending')">À évaluer <span class="filter-count" id="cnt-pending"><?php echo $counts['pending']; ?></span></button>
      <button class="filter-tab" data-filter="revised" onclick="setFilter(this,'revised')">À re-évaluer <span class="filter-count" id="cnt-revised"><?php echo $counts['revised']; ?></span></button>
      <button class="filter-tab" data-filter="done" onclick="setFilter(this,'done')">Évalués <span class="filter-count" id="cnt-done"><?php echo $counts['done']; ?></span></button>
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
      <?php foreach ($formattedAssignments as $a): ?>
        <?php echo renderRow($a); ?>
      <?php endforeach; ?>
      <?php if (empty($formattedAssignments)): ?>
      <div style="text-align:center;padding:40px;color:var(--muted);font-size:14px">
        <i class="fas fa-inbox" style="font-size:32px;opacity:.3;display:block;margin-bottom:12px"></i>
        Aucun article assigné
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="pagination">
    <span class="page-info" id="pageInfo">Affichage de 1–<?php echo count($formattedAssignments); ?> sur <?php echo count($formattedAssignments); ?> articles</span>
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
    <form method="POST" action="articles.php" id="reviewForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="review_type" value="first">
      <input type="hidden" name="article_id" id="reviewArticleId" value="">
      <input type="hidden" name="decision" id="reviewDecision" value="">
      <div class="modal-body" id="reviewModalBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('reviewModal')">Annuler</button>
        <button type="submit" class="btn-primary" id="submitReviewBtn">
          <i class="fas fa-paper-plane"></i> Soumettre l'évaluation
        </button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: RE-REVIEW (REVISED VERSION) -->
<div class="modal-backdrop" id="reReviewModal" onclick="closeOnBackdrop(event, 'reReviewModal')">
  <div class="modal modal-xl">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-sync-alt" style="color:var(--gold);margin-right:8px"></i>Re-évaluation — Version révisée</div>
      <button class="modal-close" onclick="closeModal('reReviewModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="articles.php" id="reReviewForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="review_type" value="rereview">
      <input type="hidden" name="article_id" id="reReviewArticleId" value="">
      <input type="hidden" name="decision" id="reReviewDecision" value="">
      <div class="modal-body" id="reReviewBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('reReviewModal')">Annuler</button>
        <button type="submit" class="btn-primary" id="submitReReviewBtn">
          <i class="fas fa-gavel"></i> Soumettre la décision finale
        </button>
      </div>
    </form>
  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<footer class="footer">© 2026 ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés</footer>

<script>
const assignments = <?php echo json_encode($formattedAssignments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let currentFilter = 'all';
let activeArticleId = null;
const ratings = {};
let selectedDecision = null;
let selectedReReviewDecision = null;

function setFilter(btn, filter) {
  document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  currentFilter = filter;
  applyFilter();
}

function applyFilter() {
  document.querySelectorAll('#articleListBody .article-row').forEach(row => {
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

function openDetailModal(id) {
  const a = assignments.find(x => x.id === id);
  activeArticleId = id;
  document.getElementById('detailBody').innerHTML = `
    <div class="download-zone">
      <i class="fas fa-file-pdf download-icon"></i>
      <div class="download-info">
        <div class="download-name">${a.file}</div>
        <div class="download-meta">PDF · ${a.fileSize} · Soumis le ${a.assignedDate}</div>
      </div>
      <a href="download.php?id=${a.id}" class="download-btn"><i class="fas fa-download"></i> Télécharger</a>
    </div>
    <div class="modal-detail-row"><span class="modal-detail-label">Référence</span><span class="modal-detail-value"><span class="article-ref">${a.ref}</span></span></div>
    <div class="modal-detail-row"><span class="modal-detail-label">Titre</span><span class="modal-detail-value" style="font-weight:600;color:var(--navy)">${a.title}</span></div>
    <div class="modal-detail-row"><span class="modal-detail-label">Résumé</span><span class="modal-detail-value" style="color:var(--text-light)">${a.abstract || 'Non disponible'}</span></div>
    <div class="modal-detail-row"><span class="modal-detail-label">Auteur(s)</span><span class="modal-detail-value">${a.authors}</span></div>
    <div class="modal-detail-row"><span class="modal-detail-label">Conférence</span><span class="modal-detail-value">${a.conf}</span></div>
    <div class="modal-detail-row"><span class="modal-detail-label">Échéance</span><span class="modal-detail-value"><span class="deadline-cell ${a.deadlineUrgency}"><i class="fas fa-calendar-alt" style="margin-right:5px"></i>${a.deadlineLabel}</span></span></div>
    <div class="modal-detail-row"><span class="modal-detail-label">Statut</span><span class="modal-detail-value"><span class="badge ${a.status}"><span class="badge-dot"></span>${a.statusLabel}</span></span></div>
    ${a.submittedDecision ? `<div class="modal-detail-row"><span class="modal-detail-label">Décision</span><span class="modal-detail-value"><span class="badge ${a.submittedDecision === 'accept' ? 'done' : 'pending'}"><span class="badge-dot"></span>${a.submittedDecision === 'accept' ? 'Accepté' : 'Rejeté'}</span></span></div>` : ''}
  `;
  openModal('detailModal');
}

function openReviewForm(id) {
  const a = assignments.find(x => x.id === id);
  activeArticleId = id;
  document.getElementById('reviewArticleId').value = id;
  if(!ratings[id]) ratings[id] = { originality:0, methodology:0, quality:0, significance:0, language:0, format:0 };
  selectedDecision = null;
  document.getElementById('reviewDecision').value = '';

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
    <div class="download-zone">
      <i class="fas fa-file-pdf download-icon"></i>
      <div class="download-info"><div class="download-name">${a.file}</div><div class="download-meta">${a.fileSize}</div></div>
      <a href="download.php?id=${a.id}" class="download-btn"><i class="fas fa-download"></i> Télécharger</a>
    </div>
    <div class="form-section">
      <div class="form-section-title"><i class="fas fa-star"></i> Critères scientifiques</div>
      <div class="criteria-grid">
        ${criteriaList.map(c => `
          <div class="criteria-item">
            <div class="criteria-label">${c.label}</div>
            <div class="star-row" id="stars-${c.key}">
              ${[1,2,3,4,5].map(n => `
                <button type="button" class="star-btn ${ratings[id][c.key] >= n ? 'active' : ''}" onclick="setRating(${id},'${c.key}',${n})">★</button>
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
        <textarea class="form-textarea" name="strengths" id="strengths" placeholder="Décrivez les aspects positifs..." required></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Points faibles <span>(obligatoire)</span></label>
        <textarea class="form-textarea" name="weaknesses" id="weaknesses" placeholder="Décrivez les lacunes..." required></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Suggestions d'amélioration <span>(optionnel)</span></label>
        <textarea class="form-textarea" name="suggestions" id="suggestions" placeholder="Recommandations..."></textarea>
      </div>
    </div>
    <div class="form-section">
      <div class="form-section-title"><i class="fas fa-gavel"></i> Décision finale</div>
      <div class="decision-grid">
        <div class="decision-card accept" onclick="selectDecision(this,'accept')">
          <div class="decision-icon">✅</div>
          <div><div class="decision-title">Accepté sans révision</div><div class="decision-sub">Article prêt pour publication</div></div>
        </div>
        <div class="decision-card minor" onclick="selectDecision(this,'minor_revision')">
          <div class="decision-icon">⚠️</div>
          <div><div class="decision-title">Révisions mineures</div><div class="decision-sub">Corrections légères requises</div></div>
        </div>
        <div class="decision-card major" onclick="selectDecision(this,'major_revision')">
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
  document.getElementById('reviewDecision').value = val;
}

function openReReviewModal(id) {
  const a = assignments.find(x => x.id === id);
  activeArticleId = id;
  document.getElementById('reReviewArticleId').value = id;
  selectedReReviewDecision = null;
  document.getElementById('reReviewDecision').value = '';

  document.getElementById('reReviewBody').innerHTML = `
    <div class="revised-banner">
      <div class="revised-banner-title"><i class="fas fa-sync-alt" style="margin-right:7px"></i>Version révisée reçue le ${a.revisedDate || 'Récemment'}</div>
      <div class="revised-banner-text"><strong>Réponse de l'auteur :</strong> ${a.authorResponse || 'L\'auteur a soumis une version révisée de l\'article.'}</div>
    </div>
    <div class="version-compare">
      <div class="version-tabs">
        <button type="button" class="version-tab active" onclick="switchVersion('old', this)">📄 Ancienne version</button>
        <button type="button" class="version-tab" onclick="switchVersion('new', this)">📄 Nouvelle version</button>
      </div>
      <div class="version-content active" id="version-old">
        <div class="download-zone" style="margin-bottom:0">
          <i class="fas fa-file-pdf download-icon"></i>
          <div class="download-info">
            <div class="download-name">${a.file.replace('_revised', '')}</div>
            <div class="download-meta">Version originale</div>
          </div>
          <a href="download.php?id=${a.id}&version=old" class="download-btn"><i class="fas fa-download"></i> Télécharger</a>
        </div>
      </div>
      <div class="version-content" id="version-new">
        <div class="download-zone" style="margin-bottom:0;background:#f0f4ff;border-color:#bcc7fa">
          <i class="fas fa-file-pdf download-icon" style="color:#5b6ef5"></i>
          <div class="download-info">
            <div class="download-name">${a.file}</div>
            <div class="download-meta">Version révisée · <span style="color:#5b6ef5;font-weight:600">NOUVEAU</span></div>
          </div>
          <a href="download.php?id=${a.id}&version=new" class="download-btn"><i class="fas fa-download"></i> Télécharger</a>
        </div>
      </div>
    </div>
    <div class="form-section">
      <div class="form-section-title"><i class="fas fa-comment-dots"></i> Réponse de l'auteur</div>
      <div style="background:var(--bg);padding:16px;border-radius:var(--radius-sm);border:1px solid var(--border);line-height:1.6;font-size:13px;color:var(--text-light)">
        ${a.authorResponse || 'L\'auteur a soumis une version révisée avec les corrections demandées.'}
      </div>
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
        <textarea class="form-textarea" name="final_comment" id="reReviewComment" placeholder="Justification de votre décision..."></textarea>
      </div>
    </div>
  `;
  openModal('reReviewModal');
}

function switchVersion(version, btn) {
  document.querySelectorAll('.version-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.version-content').forEach(c => c.classList.remove('active'));
  document.getElementById('version-' + version).classList.add('active');
}

function selectReReviewDecision(card, val) {
  document.querySelectorAll('#reReviewModal .decision-card').forEach(c => c.classList.remove('selected'));
  card.classList.add('selected');
  selectedReReviewDecision = val;
  document.getElementById('reReviewDecision').value = val;
}

function openModal(id) { document.getElementById(id).classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }
function closeOnBackdrop(e, id) { if(e.target === document.getElementById(id)) closeModal(id); }

document.addEventListener('keydown', e => {
  if(e.key === 'Escape') ['detailModal','reviewModal','reReviewModal'].forEach(id => closeModal(id));
});

function showToast(msg, type = 'info') {
  const wrap = document.getElementById('toastWrap');
  const t = document.createElement('div');
  const icons = { success:'fa-check-circle', error:'fa-exclamation-circle', info:'fa-info-circle' };
  t.className = `toast ${type}`;
  t.innerHTML = `<i class="fas ${icons[type]||'fa-info-circle'}"></i> ${msg}`;
  wrap.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}

// Form validation
function validateReviewForm() {
  const strengths = document.getElementById('strengths')?.value.trim();
  const weaknesses = document.getElementById('weaknesses')?.value.trim();
  const decision = document.getElementById('reviewDecision').value;

  if(!strengths) { showToast('Veuillez renseigner les points forts.', 'error'); return false; }
  if(!weaknesses) { showToast('Veuillez renseigner les points faibles.', 'error'); return false; }
  if(!decision) { showToast('Veuillez choisir une décision.', 'error'); return false; }
  return true;
}

function validateReReviewForm() {
  const decision = document.getElementById('reReviewDecision').value;
  if(!decision) { showToast('Veuillez choisir une décision finale.', 'error'); return false; }
  return true;
}

document.getElementById('reviewForm')?.addEventListener('submit', function(e) {
  if (!validateReviewForm()) e.preventDefault();
});

document.getElementById('reReviewForm')?.addEventListener('submit', function(e) {
  if (!validateReReviewForm()) e.preventDefault();
});

<?php 
$flash = getFlash();
if ($flash): 
?>
showToast("<?php echo addslashes($flash['message']); ?>", "<?php echo $flash['type']; ?>");
<?php endif; ?>

// Auto-open modals from URL params
<?php if ($action === 'review' && $currentArticle): ?>
document.addEventListener('DOMContentLoaded', () => openReviewForm(<?php echo $currentArticle['id']; ?>));
<?php elseif ($action === 'rereview' && $currentArticle): ?>
document.addEventListener('DOMContentLoaded', () => openReReviewModal(<?php echo $currentArticle['id']; ?>));
<?php endif; ?>
</script>
</body>
</html>