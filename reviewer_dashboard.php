<?php
require_once 'config.php';
requireRole('reviewer');

$reviewerId = getCurrentUserId();
$user = getCurrentUser($pdo);

// Fetch assigned articles for this reviewer
$stmt = $pdo->prepare("
    SELECT a.*, c.name_fr as conference_name, c.name_en, t.name as topic_name,
           ar.assigned_at, ar.completed_at,
           r.recommendation as submitted_decision, r.comment as review_comment
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

// Format assignments for frontend
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

    // Determine status
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
        'fileSize' => '2.0 Mo', // Would calculate from actual file
        'deadline' => $a['review_end_date'] ?? date('Y-m-d', strtotime('+30 days')),
        'deadlineLabel' => $deadlineLabel,
        'deadlineUrgency' => $deadlineUrgency,
        'status' => $status,
        'statusLabel' => $statusLabel,
        'assignedDate' => formatDateFr($a['assigned_at']),
        'isRevised' => ($a['status'] === 'revision'),
        'submittedDecision' => $a['submitted_decision'],
        'authorResponse' => $a['review_comment'] ?? ''
    ];
}

// Count statistics
$counts = ['accepted' => 0, 'rejected' => 0, 'new' => 0, 'revised' => 0];
foreach ($formattedAssignments as $a) {
    if ($a['status'] === 'done') {
        if ($a['submittedDecision'] === 'accept') $counts['accepted']++;
        elseif ($a['submittedDecision'] === 'reject') $counts['rejected']++;
    } elseif ($a['status'] === 'pending') {
        $counts['new']++;
    } elseif ($a['status'] === 'revised') {
        $counts['revised']++;
    }
}

// Fetch notifications
$notifications = getUnreadNotifications($reviewerId, 10);
$notifCount = countUnreadNotifications($reviewerId);

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Tableau de Bord</title>
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
  .search-wrap { position: relative; margin-right: 8px; }
  .search-input { width: 240px; padding: 8px 14px 8px 36px; border: 1px solid var(--border); border-radius: 20px; font-size: 13.5px; font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); outline: none; transition: all 0.15s; }
  .search-input:focus { border-color: var(--accent); background: var(--white); box-shadow: 0 0 0 3px rgba(44,111,173,0.10); width: 280px; }
  .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 13px; pointer-events: none; }
  .notif-wrap { position: relative; }
  .notif-btn { width: 38px; height: 38px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--white); color: var(--text-light); cursor: pointer; font-size: 16px; display: flex; align-items: center; justify-content: center; transition: all 0.15s; position: relative; }
  .notif-btn:hover { border-color: var(--navy); color: var(--navy); }
  .notif-btn.active { border-color: var(--gold); color: var(--gold); }
  .notif-badge { position: absolute; top: -5px; right: -5px; width: 18px; height: 18px; border-radius: 50%; background: var(--danger); color: white; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; border: 2px solid var(--white); }
  .notif-badge.hidden { display: none; }
  .notif-dropdown { position: absolute; top: calc(100% + 10px); right: 0; width: 400px; background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-md); z-index: 300; display: none; overflow: hidden; animation: fadeDown 0.18s ease; }
  .notif-dropdown.open { display: block; }
  .notif-dd-header { padding: 14px 18px 12px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
  .notif-dd-title { font-size: 14px; font-weight: 600; color: var(--navy); }
  .notif-mark-all { font-size: 12px; color: var(--accent); cursor: pointer; border: none; background: none; font-family: 'DM Sans', sans-serif; }
  .notif-mark-all:hover { text-decoration: underline; }
  .notif-categories { display: flex; border-bottom: 1px solid var(--border); background: var(--bg); }
  .notif-cat-btn { flex: 1; padding: 10px 8px; border: none; background: none; font-family: 'DM Sans', sans-serif; font-size: 12px; font-weight: 500; color: var(--muted); cursor: pointer; transition: all 0.15s; display: flex; align-items: center; justify-content: center; gap: 6px; }
  .notif-cat-btn:hover { color: var(--navy); background: var(--white); }
  .notif-cat-btn.active { color: var(--navy); background: var(--white); border-bottom: 2px solid var(--gold); font-weight: 600; }
  .notif-cat-count { background: var(--danger); color: white; font-size: 10px; padding: 2px 5px; border-radius: 10px; min-width: 16px; text-align: center; }
  .notif-cat-count.zero { background: var(--muted); }
  .notif-list { max-height: 320px; overflow-y: auto; }
  .notif-item { padding: 14px 18px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background 0.12s; display: flex; gap: 12px; align-items: flex-start; }
  .notif-item:last-child { border-bottom: none; }
  .notif-item:hover { background: #fafbfd; }
  .notif-item.unread { background: #f5f8ff; }
  .notif-item.unread:hover { background: #edf2fd; }
  .notif-icon-wrap { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
  .notif-icon-wrap.new-article { background: #eef3fb; color: var(--accent); }
  .notif-icon-wrap.revised { background: #f0f4ff; color: #5b6ef5; }
  .notif-icon-wrap.reminder { background: #fef8ec; color: var(--warning); }
  .notif-icon-wrap.info { background: #e8f6f3; color: var(--success); }
  .notif-content { flex: 1; }
  .notif-msg { font-size: 13px; color: var(--text); line-height: 1.45; }
  .notif-msg strong { color: var(--navy); }
  .notif-time { font-size: 11px; color: var(--muted); margin-top: 3px; }
  .notif-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--accent); flex-shrink: 0; margin-top: 5px; }
  .notif-dot.hidden { visibility: hidden; }
  .notif-dd-footer { padding: 12px; border-top: 1px solid var(--border); text-align: center; background: var(--bg); }
  .logout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 18px; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 500; color: var(--text-light); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all 0.15s; text-decoration: none; }
  .logout-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--white); }
  .page { max-width: 1100px; margin: 0 auto; padding: 40px 32px 60px; }
  .page-header { margin-bottom: 28px; }
  .page-header-row { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 6px; }
  .page-header h1 { font-family: 'Libre Baskerville', serif; font-size: 32px; font-weight: 700; color: var(--navy); letter-spacing: -0.5px; }
  .page-header h1 em { font-style: italic; color: var(--gold); }
  .page-header p { font-size: 14px; color: var(--muted); margin-top: 4px; }
  .status-cards-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }
  .status-card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; box-shadow: var(--shadow-sm); cursor: pointer; transition: all 0.2s; position: relative; overflow: hidden; text-decoration: none; display: block; }
  .status-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); border-color: var(--navy); }
  .status-card.accept { border-left: 4px solid var(--success); }
  .status-card.reject { border-left: 4px solid var(--danger); }
  .status-card.new { border-left: 4px solid var(--accent); }
  .status-card.revised { border-left: 4px solid #5b6ef5; }
  .status-card-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
  .status-card-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; }
  .status-card.accept .status-card-icon { background: #e8f6f3; color: var(--success); }
  .status-card.reject .status-card-icon { background: #fdf2f2; color: var(--danger); }
  .status-card.new .status-card-icon { background: #eef3fb; color: var(--accent); }
  .status-card.revised .status-card-icon { background: #f0f4ff; color: #5b6ef5; }
  .status-card-title { font-size: 13px; font-weight: 600; color: var(--navy); }
  .status-card-count { font-size: 28px; font-weight: 700; color: var(--navy); margin-top: 4px; }
  .status-card-label { font-size: 12px; color: var(--muted); }
  .deadline-strip { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 16px 22px; margin-bottom: 24px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
  .deadline-strip-title { font-size: 13px; font-weight: 600; color: var(--navy); white-space: nowrap; }
  .deadline-items { display: flex; gap: 12px; flex-wrap: wrap; flex: 1; }
  .deadline-item { display: flex; align-items: center; gap: 8px; padding: 7px 14px; border-radius: 20px; font-size: 12.5px; border: 1px solid transparent; cursor: pointer; transition: all 0.15s; }
  .deadline-item:hover { transform: scale(1.02); }
  .deadline-item.urgent { background: #fdf2f2; color: #8b2020; border-color: #f5b8b8; }
  .deadline-item.soon { background: #fef8ec; color: #9a5f00; border-color: #f5d98a; }
  .deadline-item.ok { background: #e8f6f3; color: #1a5f57; border-color: #9dd8d0; }
  .deadline-item i { font-size: 11px; }
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
  .action-btn { height: 30px; padding: 0 10px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: none; color: var(--muted); cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 5px; transition: all 0.15s; font-family: 'DM Sans', sans-serif; white-space: nowrap; text-decoration: none; }
  .action-btn:hover { background: var(--bg); color: var(--navy); border-color: var(--navy); }
  .action-btn.primary { background: var(--navy); color: var(--gold-light); border-color: var(--navy); }
  .action-btn.primary:hover { background: var(--navy-mid); }
  .action-btn.revised-btn { border-color: #bcc7fa; color: #5b6ef5; }
  .action-btn.revised-btn:hover { background: #f0f4ff; color: #3347bb; }
  .modal-backdrop { position: fixed; inset: 0; background: rgba(13,33,55,0.5); backdrop-filter: blur(4px); z-index: 200; display: none; align-items: center; justify-content: center; padding: 20px; }
  .modal-backdrop.open { display: flex; }
  .modal { background: var(--white); border-radius: var(--radius); width: 100%; max-width: 580px; box-shadow: var(--shadow-md); animation: fadeUp 0.2s ease; max-height: 90vh; overflow-y: auto; }
  .modal.modal-wide { max-width: 700px; }
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
  .btn-secondary { background: none; color: var(--text-light); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 9px 20px; font-size: 13.5px; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s; }
  .btn-secondary:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }
  .download-zone { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px 20px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
  .download-icon { font-size: 28px; color: var(--danger); flex-shrink: 0; }
  .download-info { flex: 1; }
  .download-name { font-size: 13.5px; font-weight: 600; color: var(--navy); }
  .download-meta { font-size: 12px; color: var(--muted); margin-top: 2px; }
  .download-btn { padding: 8px 18px; background: var(--navy); color: var(--gold-light); border: none; border-radius: var(--radius-sm); font-size: 13px; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s; display: flex; align-items: center; gap: 7px; white-space: nowrap; }
  .download-btn:hover { background: var(--navy-mid); }
  .toast-wrap { position: fixed; bottom: 28px; right: 28px; display: flex; flex-direction: column; gap: 10px; z-index: 500; pointer-events: none; }
  .toast { background: var(--navy); color: white; padding: 12px 18px; border-radius: var(--radius); font-size: 13.5px; box-shadow: var(--shadow-md); display: flex; align-items: center; gap: 10px; animation: toastIn 0.25s ease, toastOut 0.25s ease 3.5s forwards; pointer-events: auto; max-width: 380px; }
  .toast.success { background: var(--success); }
  .toast.error { background: var(--danger); }
  .toast i { font-size: 15px; }
  .footer { background: var(--navy); color: rgba(255,255,255,0.45); text-align: center; padding: 22px; font-size: 13px; margin-top: 48px; }
  @keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:none} }
  @keyframes fadeDown { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:none} }
  @keyframes toastIn { from{opacity:0;transform:translateX(30px)} to{opacity:1;transform:none} }
  @keyframes toastOut { from{opacity:1;transform:none} to{opacity:0;transform:translateX(30px)} }
  @media(max-width:900px) { .status-cards-row { grid-template-columns: repeat(2,1fr); } .table-head,.article-row { grid-template-columns: 2fr 120px 140px; } .td-domain,.th:nth-child(2) { display:none; } }
  @media(max-width:650px) { .topbar { padding: 0 16px; } .search-wrap { display: none; } .page { padding: 24px 16px; } .status-cards-row { grid-template-columns: 1fr; } .notif-dropdown { width: 320px; right: -50px; } }
</style>
</head>
<body>

<header class="topbar">
  <a class="brand" href="reviewer_dashboard.php">
    <div class="brand-icon">📋</div>
    <span class="brand-name">ConfManager</span>
  </a>
  <nav class="nav-links">
    <a href="reviewer_dashboard.php" class="nav-link active">Tableau de bord</a>
    <a href="articles.php" class="nav-link">Articles assignés</a>
    <a href="base-articles.php" class="nav-link">Base d'articles</a>
    <a href="profile.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <div class="search-wrap">
      <span class="search-icon"><i class="fas fa-search"></i></span>
      <input type="text" class="search-input" placeholder="Rechercher…" oninput="searchArticles(this.value)">
    </div>
    <div class="notif-wrap">
      <button class="notif-btn" id="notifBtn" onclick="toggleNotif(event)">
        <i class="fas fa-bell"></i>
        <span class="notif-badge <?php echo $notifCount == 0 ? 'hidden' : ''; ?>" id="notifBadge"><?php echo $notifCount; ?></span>
      </button>
      <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-dd-header">
          <span class="notif-dd-title">Notifications</span>
          <button class="notif-mark-all" onclick="markAllRead()">Tout marquer lu</button>
        </div>
        <div class="notif-categories">
          <button class="notif-cat-btn active" onclick="filterNotifDropdown('all', this)">
            <i class="fas fa-layer-group"></i> Tous
            <span class="notif-cat-count" id="cat-count-all"><?php echo $notifCount; ?></span>
          </button>
          <button class="notif-cat-btn" onclick="filterNotifDropdown('new-article', this)">
            <i class="fas fa-file-alt"></i> Nouveaux
            <span class="notif-cat-count zero" id="cat-count-new">0</span>
          </button>
          <button class="notif-cat-btn" onclick="filterNotifDropdown('reminder', this)">
            <i class="fas fa-clock"></i> Rappels
            <span class="notif-cat-count zero" id="cat-count-reminder">0</span>
          </button>
          <button class="notif-cat-btn" onclick="filterNotifDropdown('revised', this)">
            <i class="fas fa-sync-alt"></i> Modifiés
            <span class="notif-cat-count zero" id="cat-count-revised">0</span>
          </button>
        </div>
        <div class="notif-list" id="notifList">
          <?php foreach ($notifications as $n): ?>
          <div class="notif-item <?php echo $n['is_read'] ? '' : 'unread'; ?>" onclick="handleNotifClick(<?php echo $n['id']; ?>)">
            <div class="notif-icon-wrap <?php echo $n['type']; ?>"><i class="fas fa-info-circle"></i></div>
            <div class="notif-content">
              <div class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></div>
              <div class="notif-time"><i class="fas fa-clock" style="margin-right:4px;opacity:.6"></i><?php echo formatDateFr($n['created_at']); ?></div>
            </div>
            <div class="notif-dot <?php echo $n['is_read'] ? 'hidden' : ''; ?>"></div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($notifications)): ?>
          <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px">
            <i class="fas fa-bell-slash" style="font-size:24px;opacity:.3;display:block;margin-bottom:8px"></i>
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

<main class="page">
  <div class="page-header">
    <div class="page-header-row">
      <h1>Tableau de <em>bord</em></h1>
    </div>
    <p>Bienvenue, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?> — voici un aperçu de vos révisions</p>
  </div>

  <div class="status-cards-row">
    <a href="articles.php?filter=accepted" class="status-card accept">
      <div class="status-card-header">
        <div class="status-card-icon"><i class="fas fa-check-circle"></i></div>
        <div class="status-card-title">Articles acceptés</div>
      </div>
      <div class="status-card-count" id="dash-accept"><?php echo $counts['accepted']; ?></div>
      <div class="status-card-label">Évaluations positives</div>
    </a>
    <a href="articles.php?filter=rejected" class="status-card reject">
      <div class="status-card-header">
        <div class="status-card-icon"><i class="fas fa-times-circle"></i></div>
        <div class="status-card-title">Articles rejetés</div>
      </div>
      <div class="status-card-count" id="dash-reject"><?php echo $counts['rejected']; ?></div>
      <div class="status-card-label">Évaluations négatives</div>
    </a>
    <a href="articles.php?filter=new" class="status-card new">
      <div class="status-card-header">
        <div class="status-card-icon"><i class="fas fa-star"></i></div>
        <div class="status-card-title">Nouveaux articles</div>
      </div>
      <div class="status-card-count" id="dash-new"><?php echo $counts['new']; ?></div>
      <div class="status-card-label">À évaluer</div>
    </a>
    <a href="articles.php?filter=revised" class="status-card revised">
      <div class="status-card-header">
        <div class="status-card-icon"><i class="fas fa-sync-alt"></i></div>
        <div class="status-card-title">Versions révisées</div>
      </div>
      <div class="status-card-count" id="dash-revised"><?php echo $counts['revised']; ?></div>
      <div class="status-card-label">À re-évaluer</div>
    </a>
  </div>

  <div class="deadline-strip">
    <span class="deadline-strip-title"><i class="fas fa-clock" style="margin-right:6px;color:var(--warning)"></i>Échéances de révision</span>
    <div class="deadline-items" id="deadlineItems">
      <?php 
      $pending = array_filter($formattedAssignments, fn($a) => $a['status'] !== 'done');
      usort($pending, fn($a, $b) => strcmp($a['deadline'], $b['deadline']));
      $pending = array_slice($pending, 0, 5);
      foreach ($pending as $a): 
        $cls = $a['deadlineUrgency'] === 'urgent' ? 'urgent' : ($a['deadlineUrgency'] === 'soon' ? 'soon' : 'ok');
        $icon = $cls === 'urgent' ? 'fa-fire' : ($cls === 'soon' ? 'fa-exclamation' : 'fa-check');
      ?>
      <span class="deadline-item <?php echo $cls; ?>" onclick="openDetailModal(<?php echo $a['id']; ?>)">
        <i class="fas <?php echo $icon; ?>"></i> <?php echo $a['ref']; ?> · <?php echo $a['deadlineLabel']; ?>
      </span>
      <?php endforeach; ?>
      <?php if (empty($pending)): ?>
      <span style="color:var(--muted);font-size:13px">Aucune échéance prochaine</span>
      <?php endif; ?>
    </div>
  </div>

  <div style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between">
    <div style="font-size:15px;font-weight:600;color:var(--navy)">Prochaines échéances</div>
    <a href="articles.php" class="btn-secondary" style="padding:7px 16px;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;">
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
      <?php
      $displayAssignments = array_filter($formattedAssignments, fn($a) => $a['status'] !== 'done');
      usort($displayAssignments, fn($a, $b) => strcmp($a['deadline'], $b['deadline']));
      $displayAssignments = array_slice($displayAssignments, 0, 4);
      foreach ($displayAssignments as $a):
        $dlClass = $a['deadlineUrgency'] === 'urgent' ? 'deadline-cell urgent' : ($a['deadlineUrgency'] === 'soon' ? 'deadline-cell soon' : 'deadline-cell ok');
        $statusClass = $a['status'] === 'done' ? 'done' : ($a['status'] === 'revised' ? 'revised' : 'pending');
        if($a['status'] === 'done') {
          $actionBtn = '<button class="action-btn" onclick="openDetailModal(' . $a['id'] . ')"><i class="fas fa-eye"></i> Voir</button>';
        } elseif($a['status'] === 'revised') {
          $actionBtn = '<a href="articles.php?action=rereview&id=' . $a['id'] . '" class="action-btn revised-btn"><i class="fas fa-sync-alt"></i> Re-évaluer</a>';
        } else {
          $actionBtn = '<a href="articles.php?action=review&id=' . $a['id'] . '" class="action-btn primary"><i class="fas fa-play"></i> Commencer</a>';
        }
        $revBadge = $a['isRevised'] ? '<span class="badge revised" style="font-size:10px;padding:2px 7px"><span class="badge-dot"></span>Révisé</span>' : '';
      ?>
      <div class="article-row" data-status="<?php echo $a['status']; ?>" data-title="<?php echo strtolower($a['title']); ?>" data-deadline="<?php echo $a['deadline']; ?>">
        <div class="td">
          <div class="article-title-main"><?php echo htmlspecialchars($a['title']); ?></div>
          <div class="article-meta-row">
            <span class="article-ref"><?php echo htmlspecialchars($a['ref']); ?></span>
            <span class="article-conf"><?php echo htmlspecialchars($a['conf']); ?></span>
            <?php echo $revBadge; ?>
          </div>
        </div>
        <div class="td td-domain"><?php echo htmlspecialchars($a['specialty']); ?></div>
        <div class="td"><span class="<?php echo $dlClass; ?>"><i class="fas fa-calendar-alt" style="margin-right:5px;opacity:.7"></i><?php echo htmlspecialchars($a['deadlineLabel']); ?></span></div>
        <div class="td"><span class="badge <?php echo $statusClass; ?>"><span class="badge-dot"></span><?php echo htmlspecialchars($a['statusLabel']); ?></span></div>
        <div class="td"><div class="row-actions"><?php echo $actionBtn; ?></div></div>
      </div>
      <?php
      endforeach;
      if (empty($displayAssignments)):
      ?>
      <div style="text-align:center;padding:40px;color:var(--muted);font-size:14px">
        <i class="fas fa-check-circle" style="font-size:32px;opacity:.3;display:block;margin-bottom:12px;color:var(--success)"></i>
        Tous les articles ont été évalués !
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<div class="modal-backdrop" id="articleDetailModal" onclick="closeOnBackdrop(event)">
  <div class="modal modal-wide">
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

<div class="toast-wrap" id="toastWrap"></div>

<footer class="footer">© 2026 ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés</footer>

<script>
const assignments = <?php echo json_encode($formattedAssignments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function renderRow(a) {
  const dlClass = a.deadlineUrgency === 'urgent' ? 'deadline-cell urgent' : a.deadlineUrgency === 'soon' ? 'deadline-cell soon' : 'deadline-cell ok';
  let actionBtn = '';
  if(a.status === 'done') {
    actionBtn = `<button class="action-btn" onclick="openDetailModal(${a.id})"><i class="fas fa-eye"></i> Voir</button>`;
  } else if(a.status === 'revised') {
    actionBtn = `<a href="articles.php?action=rereview&id=${a.id}" class="action-btn revised-btn"><i class="fas fa-sync-alt"></i> Re-évaluer</a>`;
  } else {
    actionBtn = `<a href="articles.php?action=review&id=${a.id}" class="action-btn primary"><i class="fas fa-play"></i> Commencer</a>`;
  }
  return `
  <div class="article-row" data-status="${a.status}" data-title="${a.title.toLowerCase()}" data-deadline="${a.deadline}">
    <div class="td">
      <div class="article-title-main">${a.title}</div>
      <div class="article-meta-row">
        <span class="article-ref">${a.ref}</span>
        <span class="article-conf">${a.conf}</span>
        ${a.isRevised ? `<span class="badge revised" style="font-size:10px;padding:2px 7px"><span class="badge-dot"></span>Révisé</span>` : ''}
      </div>
    </div>
    <div class="td td-domain">${a.specialty}</div>
    <div class="td"><span class="${dlClass}"><i class="fas fa-calendar-alt" style="margin-right:5px;opacity:.7"></i>${a.deadlineLabel}</span></div>
    <div class="td"><span class="badge ${a.status}"><span class="badge-dot"></span>${a.statusLabel}</span></div>
    <div class="td"><div class="row-actions">${actionBtn}</div></div>
  </div>`;
}

function openDetailModal(id) {
  const a = assignments.find(x => x.id === id);
  if (!a) return;
  activeArticleId = id;
  const actionBtn = document.getElementById('detailActionBtn');
  if(a.status === 'done') {
    actionBtn.style.display = 'none';
  } else if(a.status === 'revised') {
    actionBtn.style.display = 'inline-flex';
    actionBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Re-évaluer';
    actionBtn.href = `articles.php?action=rereview&id=${a.id}`;
  } else {
    actionBtn.style.display = 'inline-flex';
    actionBtn.innerHTML = '<i class="fas fa-play"></i> Commencer l\'évaluation';
  }
  document.getElementById('articleDetailBody').innerHTML = `
    <div class="download-zone">
      <i class="fas fa-file-pdf download-icon"></i>
      <div class="download-info">
        <div class="download-name">${a.file}</div>
        <div class="download-meta">PDF · ${a.fileSize} · Soumis le ${a.assignedDate}</div>
      </div>
      <a href="download.php?id=${a.id}" class="download-btn">
        <i class="fas fa-download"></i> Télécharger
      </a>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Référence</span>
      <span class="modal-detail-value"><span class="article-ref">${a.ref}</span></span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Titre</span>
      <span class="modal-detail-value" style="font-weight:600;color:var(--navy)">${a.title}</span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Résumé</span>
      <span class="modal-detail-value" style="color:var(--text-light)">${a.abstract || 'Non disponible'}</span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Auteur(s)</span>
      <span class="modal-detail-value">${a.authors}</span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Conférence</span>
      <span class="modal-detail-value">${a.conf}</span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Échéance</span>
      <span class="modal-detail-value"><span class="deadline-cell ${a.deadlineUrgency}"><i class="fas fa-calendar-alt" style="margin-right:5px"></i>${a.deadlineLabel}</span></span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Statut</span>
      <span class="modal-detail-value"><span class="badge ${a.status}"><span class="badge-dot"></span>${a.statusLabel}</span></span>
    </div>
    ${a.submittedDecision ? `
    <div class="modal-detail-row">
      <span class="modal-detail-label">Décision</span>
      <span class="modal-detail-value"><span class="badge ${a.submittedDecision === 'accept' ? 'done' : 'pending'}">
        <span class="badge-dot"></span>${a.submittedDecision === 'accept' ? 'Accepté' : 'Rejeté'}
      </span></span>
    </div>` : ''}
  `;
  openModal();
}

function openModal() { document.getElementById('articleDetailModal').classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeModal() { document.getElementById('articleDetailModal').classList.remove('open'); document.body.style.overflow = ''; }
function closeOnBackdrop(e) { if(e.target === document.getElementById('articleDetailModal')) closeModal(); }

function searchArticles(q) {
  document.querySelectorAll('#homeArticleList .article-row').forEach(row => {
    row.style.display = row.dataset.title.includes(q.toLowerCase()) ? '' : 'none';
  });
}

function toggleNotif(e) {
  e.stopPropagation();
  const dropdown = document.getElementById('notifDropdown');
  const btn = document.getElementById('notifBtn');
  dropdown.classList.toggle('open');
  btn.classList.toggle('active', dropdown.classList.contains('open'));
}

function markAllRead() {
  fetch('api.php?action=markAllRead').then(() => {
    document.querySelectorAll('.notif-item').forEach(n => n.classList.remove('unread'));
    document.querySelectorAll('.notif-dot').forEach(d => d.classList.add('hidden'));
    document.getElementById('notifBadge').classList.add('hidden');
  });
}

function handleNotifClick(notifId) {
  window.location.href = 'articles.php';
}

function filterNotifDropdown(type, btn) {
  document.querySelectorAll('.notif-cat-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}

document.addEventListener('click', e => {
  const dd = document.getElementById('notifDropdown');
  const btn = document.getElementById('notifBtn');
  if(!btn.contains(e.target) && !dd.contains(e.target)) {
    dd.classList.remove('open');
    btn.classList.remove('active');
  }
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

let activeArticleId = null;
<?php 
$flash = getFlash();
if ($flash): 
?>
showToast("<?php echo addslashes($flash['message']); ?>", "<?php echo $flash['type']; ?>");
<?php endif; ?>
</script>
</body>
</html>