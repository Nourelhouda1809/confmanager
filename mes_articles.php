<?php
require_once 'config.php';
requireAuth();

$userId = getCurrentUserId();
$user = getCurrentUser($pdo);

// ─── Fetch user's articles with conference info ───
$stmt = $pdo->prepare("
    SELECT 
        a.id, a.title, a.abstract, a.keywords, a.file_path, a.status,
        a.final_decision, a.final_comment, a.reject_reason,
        a.submission_date, a.created_at,
        c.name_fr as conf_name, c.name_en as conf_name_en,
        t.name as topic_name
    FROM articles a
    LEFT JOIN conferences c ON a.conference_id = c.id
    LEFT JOIN topics t ON a.topic_id = t.id
    WHERE a.author_email = ? OR a.author = ?
    ORDER BY a.created_at DESC
");
$stmt->execute([$user['email'], $user['first_name'] . ' ' . $user['last_name']]);
$articles = $stmt->fetchAll();

// ─── Fetch evaluations for each article ───
$articleIds = array_column($articles, 'id');
$evaluations = [];
if (!empty($articleIds)) {
    $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
    $stmt = $pdo->prepare("
        SELECT r.*, u.first_name, u.last_name, u.role
        FROM reviews r
        JOIN users u ON r.evaluator_id = u.id
        WHERE r.article_id IN ($placeholders)
    ");
    $stmt->execute($articleIds);
    $allReviews = $stmt->fetchAll();
    foreach ($allReviews as $rev) {
        $evaluations[$rev['article_id']][] = $rev;
    }
}

// ─── Fetch notifications ───
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 20
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

// ─── Calculate stats ───
$stats = ['total' => 0, 'review' => 0, 'accepted' => 0, 'revision' => 0, 'rejected' => 0];
foreach ($articles as $a) {
    $stats['total']++;
    $status = $a['final_decision'] ?? $a['status'];
    if (isset($stats[$status])) $stats[$status]++;
}

// ─── Helper: Get sentiment from recommendation ───
function getSentiment($recommendation) {
    $map = ['accept' => 'positive', 'minor_revision' => 'neutral', 'major_revision' => 'neutral', 'reject' => 'negative'];
    return $map[$recommendation] ?? 'neutral';
}

// ─── Helper: Stars from recommendation ───
function getStars($recommendation) {
    $map = ['accept' => 5, 'minor_revision' => 4, 'major_revision' => 3, 'reject' => 2];
    return $map[$recommendation] ?? 3;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Mes articles</title>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root {
    --navy: #0d2137; --navy-mid: #1a3a5c; --gold: #c9a84c; --gold-light: #e2c97e;
    --bg: #f0f2f5; --white: #ffffff; --border: #e2e8f0; --muted: #7a8fa6;
    --text: #1a2e44; --text-light: #4a607a; --accent: #2c6fad;
    --danger: #d94040; --success: #2a9d8f; --warning: #d4830a;
    --shadow-sm: 0 1px 4px rgba(13,33,55,0.07);
    --shadow: 0 4px 20px rgba(13,33,55,0.10);
    --shadow-md: 0 8px 32px rgba(13,33,55,0.15);
    --radius: 8px; --radius-sm: 4px;
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
    border-radius: 6px; display: flex; align-items: center;
    justify-content: center; color: white; font-size: 16px;
  }
  .brand-name { font-size: 18px; font-weight: 600; color: var(--navy); letter-spacing: -0.3px; }
  .nav-links { display: flex; align-items: center; gap: 4px; flex: 1; }
  .nav-link {
    padding: 8px 16px; font-size: 14px; font-weight: 400;
    color: var(--text-light); background: none; border: none;
    border-radius: var(--radius-sm); cursor: pointer;
    font-family: 'DM Sans', sans-serif; transition: all 0.15s; position: relative;
    text-decoration: none; display: inline-block;
  }
  .nav-link:hover { color: var(--navy); background: var(--bg); }
  .nav-link.active { color: var(--gold); font-weight: 500; }
  .nav-link.active::after {
    content: ''; position: absolute; bottom: -1px; left: 16px; right: 16px;
    height: 2px; background: var(--gold); border-radius: 2px 2px 0 0;
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
  .notif-wrap { position: relative; }
  .notif-btn {
    width: 38px; height: 38px; border-radius: var(--radius-sm);
    border: 1px solid var(--border); background: var(--white);
    color: var(--text-light); cursor: pointer; font-size: 16px;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.15s; position: relative;
  }
  .notif-btn:hover { border-color: var(--navy); color: var(--navy); }
  .notif-badge {
    position: absolute; top: -5px; right: -5px;
    width: 18px; height: 18px; border-radius: 50%;
    background: var(--danger); color: white;
    font-size: 10px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid var(--white);
  }
  .notif-badge.hidden { display: none; }
  .notif-dropdown {
    position: absolute; top: calc(100% + 10px); right: 0;
    width: 360px; background: var(--white);
    border: 1px solid var(--border); border-radius: var(--radius);
    box-shadow: var(--shadow-md); z-index: 200;
    display: none; overflow: hidden;
    animation: fadeDown 0.18s ease;
  }
  .notif-dropdown.open { display: block; }
  .notif-dd-header {
    padding: 14px 18px 12px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
  }
  .notif-dd-title { font-size: 14px; font-weight: 600; color: var(--navy); }
  .notif-mark-all {
    font-size: 12px; color: var(--accent); cursor: pointer;
    border: none; background: none; font-family: 'DM Sans', sans-serif;
  }
  .notif-mark-all:hover { text-decoration: underline; }
  .notif-list { max-height: 340px; overflow-y: auto; }
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
  .notif-icon-wrap.accepted { background: #e8f6f3; color: var(--success); }
  .notif-icon-wrap.rejected { background: #fdf2f2; color: var(--danger); }
  .notif-icon-wrap.revision { background: #f0f4ff; color: #5b6ef5; }
  .notif-icon-wrap.info { background: #eef3fb; color: var(--accent); }
  .notif-content { flex: 1; }
  .notif-msg { font-size: 13px; color: var(--text); line-height: 1.45; }
  .notif-msg strong { color: var(--navy); }
  .notif-time { font-size: 11px; color: var(--muted); margin-top: 3px; }
  .notif-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--accent); flex-shrink: 0; margin-top: 5px;
  }
  .notif-dot.hidden { visibility: hidden; }
  .logout-btn {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 18px; background: var(--bg);
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    font-size: 13.5px; font-weight: 500; color: var(--text-light);
    cursor: pointer; font-family: 'DM Sans', sans-serif;
    transition: all 0.15s; text-decoration: none;
  }
  .logout-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--white); }

  .page { max-width: 1100px; margin: 0 auto; padding: 40px 32px 60px; }
  .page-header { margin-bottom: 28px; }
  .page-header-row {
    display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 6px;
  }
  .page-header h1 {
    font-family: 'Libre Baskerville', serif; font-size: 32px; font-weight: 700;
    color: var(--navy); letter-spacing: -0.5px;
  }
  .page-header h1 em { font-style: italic; color: var(--gold); }
  .page-header p { font-size: 14px; color: var(--muted); margin-top: 4px; }
  .btn-primary {
    background: var(--navy); color: var(--gold-light); border: none; border-radius: var(--radius-sm);
    padding: 11px 22px; font-size: 13.5px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s;
    display: inline-flex; align-items: center; gap: 8px; text-decoration: none; white-space: nowrap;
  }
  .btn-primary:hover { background: var(--navy-mid); transform: translateY(-1px); box-shadow: var(--shadow); }

  .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }
  .stat-card {
    background: var(--white); border: 1px solid var(--border); border-radius: var(--radius);
    padding: 18px 20px; box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 14px;
  }
  .stat-icon {
    width: 42px; height: 42px; border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0;
  }
  .stat-icon.total { background: #eef3fb; color: var(--accent); }
  .stat-icon.review { background: #fef8ec; color: var(--warning); }
  .stat-icon.accepted { background: #e8f6f3; color: var(--success); }
  .stat-icon.revision { background: #f0f4ff; color: #5b6ef5; }
  .stat-value { font-size: 22px; font-weight: 700; color: var(--navy); line-height: 1; margin-bottom: 2px; }
  .stat-label { font-size: 12px; color: var(--muted); }

  .filters-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
  .filter-tabs {
    display: flex; gap: 4px; background: var(--white);
    border: 1px solid var(--border); border-radius: 20px; padding: 4px; box-shadow: var(--shadow-sm);
  }
  .filter-tab {
    padding: 7px 16px; font-size: 13px; font-weight: 500; color: var(--muted);
    background: none; border: none; border-radius: 16px; cursor: pointer;
    font-family: 'DM Sans', sans-serif; transition: all 0.15s;
    display: flex; align-items: center; gap: 6px;
  }
  .filter-tab:hover { color: var(--navy); }
  .filter-tab.active { background: var(--navy); color: var(--gold-light); }
  .filter-count {
    font-size: 10px; font-weight: 700;
    background: rgba(255,255,255,0.2); padding: 1px 5px; border-radius: 8px;
  }
  .filter-tab:not(.active) .filter-count { background: var(--bg); color: var(--muted); }
  .filters-right { margin-left: auto; display: flex; gap: 10px; }
  .sort-select {
    padding: 8px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm);
    font-size: 13px; font-family: 'DM Sans', sans-serif; color: var(--text-light);
    background: var(--white); outline: none; cursor: pointer;
  }
  .sort-select:focus { border-color: var(--accent); }

  .articles-table-wrap {
    background: var(--white); border: 1px solid var(--border);
    border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden;
  }
  .table-head {
    display: grid; grid-template-columns: 2fr 1fr 1fr 130px 120px;
    background: var(--bg); border-bottom: 1px solid var(--border);
  }
  .th {
    padding: 12px 18px; font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 1px; color: var(--muted);
  }
  .th:first-child { padding-left: 24px; }
  .th:last-child { padding-right: 24px; text-align: right; }
  .article-row {
    display: grid; grid-template-columns: 2fr 1fr 1fr 130px 120px;
    border-bottom: 1px solid var(--border); transition: background 0.12s; align-items: center;
  }
  .article-row:last-child { border-bottom: none; }
  .article-row:hover { background: #fafbfd; }
  .td { padding: 16px 18px; }
  .td:first-child { padding-left: 24px; }
  .td:last-child { padding-right: 24px; }
  .article-title-main { font-size: 14px; font-weight: 600; color: var(--navy); margin-bottom: 4px; line-height: 1.35; }
  .article-meta-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .article-ref {
    font-family: 'DM Mono', monospace; font-size: 11px; color: var(--muted);
    background: var(--bg); padding: 2px 7px; border-radius: 3px; border: 1px solid var(--border);
  }
  .article-conf { font-size: 12px; color: var(--text-light); }
  .td-domain { font-size: 13px; color: var(--text-light); }
  .td-date { font-size: 13px; color: var(--muted); font-variant-numeric: tabular-nums; }

  .badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 600; white-space: nowrap;
  }
  .badge-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
  .badge.review { background: #fef8ec; color: #9a5f00; border: 1px solid #f5d98a; }
  .badge.review .badge-dot { background: var(--warning); }
  .badge.accepted { background: #e8f6f3; color: #1a5f57; border: 1px solid #9dd8d0; }
  .badge.accepted .badge-dot { background: var(--success); }
  .badge.rejected { background: #fdf2f2; color: #8b2020; border: 1px solid #f5b8b8; }
  .badge.rejected .badge-dot { background: var(--danger); }
  .badge.revision { background: #f0f4ff; color: #3347bb; border: 1px solid #bcc7fa; }
  .badge.revision .badge-dot { background: #5b6ef5; }
  .badge.new, .badge.assigned { background: var(--bg); color: var(--muted); border: 1px solid var(--border); }
  .badge.new .badge-dot, .badge.assigned .badge-dot { background: var(--muted); }

  .row-actions { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
  .action-btn {
    width: 30px; height: 30px; border-radius: var(--radius-sm);
    border: 1px solid var(--border); background: none; color: var(--muted);
    cursor: pointer; font-size: 12px; display: flex; align-items: center; justify-content: center;
    transition: all 0.15s;
  }
  .action-btn:hover { background: var(--bg); color: var(--navy); border-color: var(--navy); }
  .action-btn.revision-action { border-color: #bcc7fa; color: #5b6ef5; }
  .action-btn.revision-action:hover { background: #f0f4ff; color: #3347bb; border-color: #3347bb; }

  .pagination {
    display: flex; align-items: center; justify-content: space-between;
    margin-top: 24px; padding: 0 4px;
  }
  .page-info { font-size: 13px; color: var(--muted); }
  .page-btns { display: flex; gap: 4px; }
  .page-btn {
    width: 34px; height: 34px; border: 1px solid var(--border); border-radius: var(--radius-sm);
    background: var(--white); color: var(--text-light); font-size: 13px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-family: 'DM Sans', sans-serif; transition: all 0.15s;
  }
  .page-btn:hover { border-color: var(--navy); color: var(--navy); }
  .page-btn.active { background: var(--navy); color: var(--gold-light); border-color: var(--navy); }

  .modal-backdrop {
    position: fixed; inset: 0; background: rgba(13,33,55,0.5); backdrop-filter: blur(4px);
    z-index: 200; display: none; align-items: center; justify-content: center; padding: 20px;
  }
  .modal-backdrop.open { display: flex; }
  .modal {
    background: var(--white); border-radius: var(--radius); width: 100%; max-width: 580px;
    box-shadow: var(--shadow-md); animation: fadeUp 0.2s ease; max-height: 90vh; overflow-y: auto;
  }
  .modal.modal-wide { max-width: 700px; }
  .modal-header {
    padding: 20px 24px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; background: var(--white); z-index: 1;
  }
  .modal-title { font-family: 'Libre Baskerville', serif; font-size: 18px; color: var(--navy); }
  .modal-close {
    width: 30px; height: 30px; border-radius: var(--radius-sm); border: 1px solid var(--border);
    background: none; color: var(--muted); cursor: pointer; font-size: 14px;
    display: flex; align-items: center; justify-content: center; transition: all 0.15s; flex-shrink: 0;
  }
  .modal-close:hover { background: var(--bg); color: var(--navy); }
  .modal-body { padding: 24px; }
  .modal-detail-row { display: flex; gap: 10px; margin-bottom: 14px; align-items: flex-start; }
  .modal-detail-label {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: 1px; color: var(--muted); width: 110px; flex-shrink: 0; padding-top: 2px;
  }
  .modal-detail-value { font-size: 13.5px; color: var(--text); flex: 1; line-height: 1.5; }
  .modal-footer {
    padding: 16px 24px; border-top: 1px solid var(--border);
    display: flex; justify-content: flex-end; gap: 10px;
    position: sticky; bottom: 0; background: var(--white);
  }
  .btn-secondary {
    background: none; color: var(--text-light); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: 9px 20px; font-size: 13.5px;
    font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s;
  }
  .btn-secondary:hover { border-color: var(--navy); color: var(--navy); background: var(--bg); }
  .btn-danger {
    background: var(--danger); color: white; border: none; border-radius: var(--radius-sm);
    padding: 9px 20px; font-size: 13.5px; font-family: 'DM Sans', sans-serif;
    cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; gap: 7px;
  }
  .btn-danger:hover { background: #b83030; }

  .revision-section { margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
  .revision-title {
    font-size: 14px; font-weight: 600; color: var(--navy);
    margin-bottom: 6px; display: flex; align-items: center; gap: 8px;
  }
  .revision-subtitle { font-size: 12.5px; color: var(--muted); margin-bottom: 14px; }
  .upload-zone {
    border: 2px dashed var(--border); border-radius: var(--radius);
    padding: 28px; text-align: center; cursor: pointer;
    transition: all 0.2s; position: relative; background: var(--bg);
  }
  .upload-zone:hover, .upload-zone.drag-over { border-color: var(--accent); background: #f0f5fb; }
  .upload-zone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
  .upload-icon { font-size: 32px; margin-bottom: 10px; color: var(--muted); }
  .upload-text { font-size: 13.5px; font-weight: 500; color: var(--text-light); margin-bottom: 4px; }
  .upload-sub { font-size: 12px; color: var(--muted); }
  .upload-link { color: var(--accent); text-decoration: underline; cursor: pointer; }
  .file-chosen {
    margin-top: 12px; display: none;
    background: #e8f6f3; border: 1px solid #9dd8d0;
    border-radius: var(--radius-sm); padding: 10px 14px;
    display: flex; align-items: center; gap: 10px;
  }
  .file-chosen.visible { display: flex; }
  .file-chosen-name { font-size: 13px; font-weight: 500; color: #1a5f57; flex: 1; }
  .file-chosen-size { font-size: 11px; color: var(--muted); }
  .file-chosen-remove {
    background: none; border: none; color: var(--muted);
    cursor: pointer; font-size: 13px; padding: 2px;
  }
  .file-chosen-remove:hover { color: var(--danger); }
  .revision-comment-label { font-size: 12.5px; font-weight: 500; color: var(--text-light); margin-bottom: 6px; margin-top: 14px; }
  .revision-comment-input {
    width: 100%; border: 1px solid var(--border); border-radius: var(--radius-sm);
    padding: 10px 14px; font-size: 13px; font-family: 'DM Sans', sans-serif;
    color: var(--text); resize: vertical; min-height: 80px; outline: none;
    background: var(--white); transition: border-color 0.15s;
  }
  .revision-comment-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(44,111,173,0.08); }

  .eval-reviewer {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 14px 0; border-bottom: 1px solid var(--border);
  }
  .eval-reviewer:last-child { border-bottom: none; padding-bottom: 0; }
  .reviewer-avatar {
    width: 36px; height: 36px; border-radius: 50%; background: var(--navy);
    color: var(--gold-light); display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 600; flex-shrink: 0;
  }
  .reviewer-info { flex: 1; }
  .reviewer-name { font-size: 13px; font-weight: 600; color: var(--navy); margin-bottom: 2px; }
  .reviewer-role { font-size: 11px; color: var(--muted); margin-bottom: 8px; }
  .reviewer-comment {
    font-size: 13px; color: var(--text-light); line-height: 1.55;
    background: var(--bg); padding: 10px 14px; border-radius: var(--radius-sm);
    border-left: 3px solid var(--border); margin-bottom: 8px;
  }
  .reviewer-comment.positive { border-left-color: var(--success); }
  .reviewer-comment.negative { border-left-color: var(--danger); }
  .reviewer-comment.neutral { border-left-color: #5b6ef5; }
  .score-row { display: flex; gap: 12px; flex-wrap: wrap; }
  .score-item { display: flex; flex-direction: column; gap: 3px; }
  .score-label { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
  .score-stars { color: var(--gold); font-size: 12px; }
  .score-stars.low { color: var(--danger); }

  .toast-wrap {
    position: fixed; bottom: 28px; right: 28px;
    display: flex; flex-direction: column; gap: 10px; z-index: 500; pointer-events: none;
  }
  .toast {
    background: var(--navy); color: white; padding: 12px 18px; border-radius: var(--radius);
    font-size: 13.5px; box-shadow: var(--shadow-md); display: flex; align-items: center; gap: 10px;
    animation: toastIn 0.25s ease, toastOut 0.25s ease 3.5s forwards;
    pointer-events: auto; max-width: 360px;
  }
  .toast.success { background: var(--success); }
  .toast.error { background: var(--danger); }
  .toast i { font-size: 15px; }

  .empty-state {
    text-align: center; padding: 64px 32px;
    background: var(--white); border: 1px solid var(--border);
    border-radius: var(--radius); box-shadow: var(--shadow-sm);
  }
  .empty-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.4; }
  .empty-title { font-family: 'Libre Baskerville', serif; font-size: 20px; color: var(--navy); margin-bottom: 8px; }
  .empty-sub { font-size: 14px; color: var(--muted); margin-bottom: 24px; }

  .footer {
    background: var(--navy); color: rgba(255,255,255,0.45);
    text-align: center; padding: 22px; font-size: 13px; margin-top: 48px;
  }

  @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
  @keyframes fadeDown { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
  @keyframes toastIn { from { opacity: 0; transform: translateX(30px); } to { opacity: 1; transform: none; } }
  @keyframes toastOut { from { opacity: 1; transform: none; } to { opacity: 0; transform: translateX(30px); } }

  @media (max-width: 900px) {
    .stats-row { grid-template-columns: repeat(2, 1fr); }
    .table-head, .article-row { grid-template-columns: 2fr 1fr 130px 100px; }
    .td-domain, .th:nth-child(3) { display: none; }
    .search-input { width: 160px; }
  }
  @media (max-width: 650px) {
    .topbar { padding: 0 16px; }
    .nav-links { display: none; }
    .search-wrap { display: none; }
    .page { padding: 24px 16px; }
    .stats-row { grid-template-columns: repeat(2, 1fr); }
    .table-head, .article-row { grid-template-columns: 2fr 130px 80px; }
    .th:nth-child(4), .td:nth-child(4) { display: none; }
    .filters-right { display: none; }
  }
</style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
  <a class="brand" href="index.php">
    <div class="brand-icon">📋</div>
    <span class="brand-name">ConfManager</span>
  </a>
  <nav class="nav-links">
    <a href="submit_article.php" class="nav-link">Soumettre des articles</a>
    <a href="mes_articles.php" class="nav-link active">Mes articles</a>
    <a href="profile.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <div class="search-wrap">
      <span class="search-icon"><i class="fas fa-search"></i></span>
      <input type="text" class="search-input" id="articleSearch" placeholder="Rechercher un article..." oninput="filterArticles()">
    </div>

    <!-- NOTIFICATION BELL -->
    <div class="notif-wrap">
      <button class="notif-btn" id="notifBtn" onclick="toggleNotifications(event)" title="Notifications">
        <i class="fas fa-bell"></i>
        <span class="notif-badge <?php echo count(array_filter($notifications, fn($n) => !$n['is_read'])) === 0 ? 'hidden' : ''; ?>" id="notifBadge">
          <?php echo count(array_filter($notifications, fn($n) => !$n['is_read'])); ?>
        </span>
      </button>
      <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-dd-header">
          <span class="notif-dd-title">Notifications</span>
          <button class="notif-mark-all" onclick="markAllRead()">Tout marquer comme lu</button>
        </div>
        <div class="notif-list" id="notifList">
          <?php foreach ($notifications as $notif): 
            $notifType = $notif['type'] ?? 'info';
            $notifIcons = ['accepted' => 'fa-check-circle', 'rejected' => 'fa-times-circle', 'revision' => 'fa-pencil-alt', 'info' => 'fa-info-circle'];
            $icon = $notifIcons[$notifType] ?? 'fa-info-circle';
          ?>
          <div class="notif-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" 
               id="notif-<?php echo $notif['id']; ?>" 
               onclick="handleNotifClick(<?php echo $notif['id']; ?>, <?php echo $notif['article_id'] ?? 'null'; ?>)">
            <div class="notif-icon-wrap <?php echo $notifType; ?>">
              <i class="fas <?php echo $icon; ?>"></i>
            </div>
            <div class="notif-content">
              <div class="notif-msg"><?php echo htmlspecialchars($notif['message']); ?></div>
              <div class="notif-time"><i class="fas fa-clock" style="margin-right:4px;opacity:.6"></i><?php echo formatDateFr($notif['created_at']); ?></div>
            </div>
            <div class="notif-dot <?php echo $notif['is_read'] ? 'hidden' : ''; ?>"></div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($notifications)): ?>
          <div style="padding: 24px; text-align: center; color: var(--muted); font-size: 13px;">
            Aucune notification
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <a href="logout.php" class="logout-btn">
      <i class="fas fa-sign-out-alt"></i> Déconnexion
    </a>
  </div>
</header>

<!-- PAGE -->
<main class="page">

  <div class="page-header">
    <div class="page-header-row">
      <h1>Mes <em>articles</em></h1>
      <a href="submit_article.php" class="btn-primary">
        <i class="fas fa-plus"></i> Nouvel article
      </a>
    </div>
    <p>Suivez l'état de vos soumissions, consultez les évaluations et gérez vos révisions</p>
  </div>

  <!-- STATS -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-icon total"><i class="fas fa-layer-group"></i></div>
      <div>
        <div class="stat-value" id="statTotal"><?php echo $stats['total']; ?></div>
        <div class="stat-label">Total soumis</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon review"><i class="fas fa-hourglass-half"></i></div>
      <div>
        <div class="stat-value" id="statReview"><?php echo $stats['review']; ?></div>
        <div class="stat-label">En évaluation</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon accepted"><i class="fas fa-check-circle"></i></div>
      <div>
        <div class="stat-value" id="statAccepted"><?php echo $stats['accepted']; ?></div>
        <div class="stat-label">Acceptés</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon revision"><i class="fas fa-pencil-alt"></i></div>
      <div>
        <div class="stat-value" id="statRevision"><?php echo $stats['revision']; ?></div>
        <div class="stat-label">En révision</div>
      </div>
    </div>
  </div>

  <!-- FILTERS -->
  <div class="filters-bar">
    <div class="filter-tabs" id="filterTabs">
      <button class="filter-tab active" data-filter="all" onclick="setFilter(this,'all')">
        Tous <span class="filter-count" id="cnt-all"><?php echo $stats['total']; ?></span>
      </button>
      <button class="filter-tab" data-filter="review" onclick="setFilter(this,'review')">
        Évaluation <span class="filter-count" id="cnt-review"><?php echo $stats['review']; ?></span>
      </button>
      <button class="filter-tab" data-filter="accepted" onclick="setFilter(this,'accepted')">
        Acceptés <span class="filter-count" id="cnt-accepted"><?php echo $stats['accepted']; ?></span>
      </button>
      <button class="filter-tab" data-filter="revision" onclick="setFilter(this,'revision')">
        Révision <span class="filter-count" id="cnt-revision"><?php echo $stats['revision']; ?></span>
      </button>
      <button class="filter-tab" data-filter="rejected" onclick="setFilter(this,'rejected')">
        Rejetés <span class="filter-count" id="cnt-rejected"><?php echo $stats['rejected']; ?></span>
      </button>
    </div>
    <div class="filters-right">
      <select class="sort-select" onchange="sortArticles(this.value)">
        <option value="date-desc">Plus récent</option>
        <option value="date-asc">Plus ancien</option>
        <option value="title">Titre A→Z</option>
      </select>
    </div>
  </div>

  <!-- LIST VIEW -->
  <div id="listView">
    <div class="articles-table-wrap">
      <div class="table-head">
        <div class="th">Article</div>
        <div class="th">Conférence</div>
        <div class="th">Domaine</div>
        <div class="th">Statut</div>
        <div class="th" style="text-align:right">Actions</div>
      </div>
      <div id="articleListBody">
        <?php if (empty($articles)): ?>
        <div class="empty-state" style="padding: 48px;">
          <div class="empty-icon">📄</div>
          <div class="empty-title">Aucun article soumis</div>
          <div class="empty-sub">Vous n'avez pas encore soumis d'article. Commencez dès maintenant !</div>
          <a href="submit_article.php" class="btn-primary"><i class="fas fa-plus"></i> Soumettre un article</a>
        </div>
        <?php else: ?>
        <?php foreach ($articles as $article): 
          $status = $article['final_decision'] ?? $article['status'];
          $label = getStatusLabel($status);
          $articleEvals = $evaluations[$article['id']] ?? [];
          $hasEvals = !empty($articleEvals);
        ?>
        <div class="article-row" data-status="<?php echo $status; ?>" 
             data-title="<?php echo htmlspecialchars(strtolower($article['title'])); ?>" 
             data-date="<?php echo $article['created_at']; ?>" 
             data-id="<?php echo $article['id']; ?>">
          <div class="td">
            <div class="article-title-main"><?php echo htmlspecialchars($article['title']); ?></div>
            <div class="article-meta-row">
              <span class="article-ref"><?php echo generateRef($article['id']); ?></span>
              <span class="article-conf"><?php echo htmlspecialchars($article['conf_name'] ?? '—'); ?></span>
            </div>
          </div>
          <div class="td td-domain"><?php echo htmlspecialchars($article['topic_name'] ?? '—'); ?></div>
          <div class="td td-date"><?php echo formatDateFr($article['submission_date']); ?></div>
          <div class="td"><span class="badge <?php echo $status; ?>"><span class="badge-dot"></span><?php echo $label; ?></span></div>
          <div class="td">
            <div class="row-actions">
              <button class="action-btn" title="Voir le détail" onclick="openDetailModal(<?php echo $article['id']; ?>)"><i class="fas fa-eye"></i></button>
              <?php if ($hasEvals): ?>
              <button class="action-btn" title="Voir les évaluations" onclick="openEvalModal(<?php echo $article['id']; ?>)" style="color:var(--gold);border-color:var(--gold-light)"><i class="fas fa-star"></i></button>
              <?php endif; ?>
              <?php if ($status === 'revision'): ?>
              <button class="action-btn revision-action" title="Soumettre la révision" onclick="openRevisionModal(<?php echo $article['id']; ?>)"><i class="fas fa-upload"></i></button>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- PAGINATION -->
  <div class="pagination">
    <span class="page-info" id="pageInfo">Affichage de 1–<?php echo count($articles); ?> sur <?php echo count($articles); ?> article<?php echo count($articles) > 1 ? 's' : ''; ?></span>
    <div class="page-btns">
      <button class="page-btn" disabled><i class="fas fa-chevron-left"></i></button>
      <button class="page-btn active">1</button>
      <button class="page-btn" disabled><i class="fas fa-chevron-right"></i></button>
    </div>
  </div>

</main>

<!-- DETAIL MODAL -->
<div class="modal-backdrop" id="detailModal" onclick="closeOnBackdrop(event,'detailModal')">
  <div class="modal modal-wide">
    <div class="modal-header">
      <div class="modal-title">Détail de l'article</div>
      <button class="modal-close" onclick="closeModal('detailModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="detailModalBody"></div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('detailModal')">Fermer</button>
      <a class="btn-primary" id="detailDownloadBtn" href="#" style="text-decoration:none;">
        <i class="fas fa-download"></i> Télécharger
      </a>
    </div>
  </div>
</div>

<!-- REVISION MODAL -->
<div class="modal-backdrop" id="revisionModal" onclick="closeOnBackdrop(event,'revisionModal')">
  <div class="modal modal-wide">
    <div class="modal-header">
      <div class="modal-title" id="revisionModalTitle"><i class="fas fa-pencil-alt" style="color:#5b6ef5;margin-right:8px"></i>Soumettre une révision</div>
      <button class="modal-close" onclick="closeModal('revisionModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="revisionModalBody"></div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('revisionModal')">Annuler</button>
      <button class="btn-primary" id="revisionSubmitBtn" onclick="submitRevision()">
        <i class="fas fa-paper-plane"></i> Envoyer la révision
      </button>
    </div>
  </div>
</div>

<!-- EVALUATION MODAL -->
<div class="modal-backdrop" id="evalModal" onclick="closeOnBackdrop(event,'evalModal')">
  <div class="modal modal-wide">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-star" style="color:var(--gold);margin-right:8px"></i>Évaluations des relecteurs</div>
      <button class="modal-close" onclick="closeModal('evalModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="evalModalBody"></div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('evalModal')">Fermer</button>
    </div>
  </div>
</div>

<!-- TOAST CONTAINER -->
<div class="toast-wrap" id="toastWrap"></div>

<footer class="footer">
  © 2026 ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés
</footer>

<script>
/* ═══════════════════════════════════════════════
   ARTICLE DATA FROM PHP
═══════════════════════════════════════════════ */
const articles = <?php echo json_encode(array_map(function($a) use ($evaluations) {
    $status = $a['final_decision'] ?? $a['status'];
    $evals = [];
    foreach ($evaluations[$a['id']] ?? [] as $rev) {
        $evals[] = [
            'name' => $rev['first_name'] . ' ' . $rev['last_name'],
            'role' => $rev['role'] === 'reviewer' ? 'Relecteur' : 'Relecteur principal',
            'comment' => $rev['comment'],
            'sentiment' => getSentiment($rev['recommendation']),
            'scores' => [
                ['label' => 'Recommandation', 'stars' => getStars($rev['recommendation'])]
            ]
        ];
    }
    return [
        'id' => (int)$a['id'],
        'ref' => generateRef($a['id']),
        'title' => $a['title'],
        'conf' => $a['conf_name'] ?? '—',
        'domain' => $a['topic_name'] ?? '—',
        'type' => 'Article complet',
        'date' => formatDateFr($a['submission_date']),
        'dateSort' => $a['created_at'],
        'status' => $status,
        'label' => getStatusLabel($status),
        'keywords' => $a['keywords'] ?? '',
        'abstract' => $a['abstract'] ?? '',
        'file_path' => $a['file_path'],
        'revisionComment' => $a['final_comment'] ?? '',
        'evaluations' => $evals
    ];
}, $articles)); ?>;

const notifications = <?php echo json_encode(array_map(function($n) {
    return [
        'id' => (int)$n['id'],
        'type' => $n['type'] ?? 'info',
        'msg' => $n['message'],
        'time' => formatDateFr($n['created_at']),
        'read' => (bool)$n['is_read'],
        'articleId' => $n['article_id'] ? (int)$n['article_id'] : null
    ];
}, $notifications)); ?>;

const notifIcons = { accepted: 'fa-check-circle', rejected: 'fa-times-circle', revision: 'fa-pencil-alt', info: 'fa-info-circle' };

/* ═══════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════ */
let currentFilter = 'all';
let currentRevisionId = null;
let chosenFile = null;

/* ═══════════════════════════════════════════════
   INIT
═══════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  updateCounters();
  updatePageInfo();
});

/* ═══════════════════════════════════════════════
   COUNTERS
═══════════════════════════════════════════════ */
function updateCounters() {
  const counts = { all: articles.length, review: 0, accepted: 0, revision: 0, rejected: 0 };
  articles.forEach(a => { if(counts[a.status] !== undefined) counts[a.status]++; });
  Object.keys(counts).forEach(k => {
    const el = document.getElementById('cnt-' + k);
    if(el) el.textContent = counts[k];
  });
  document.getElementById('statTotal').textContent    = counts.all;
  document.getElementById('statReview').textContent   = counts.review;
  document.getElementById('statAccepted').textContent = counts.accepted;
  document.getElementById('statRevision').textContent = counts.revision;
}

function updatePageInfo() {
  const visible = articles.filter(a => (currentFilter === 'all' || a.status === currentFilter)).length;
  document.getElementById('pageInfo').textContent =
    `Affichage de 1–${visible} sur ${visible} article${visible > 1 ? 's' : ''}`;
}

/* ═══════════════════════════════════════════════
   FILTERS / SORT / SEARCH
═══════════════════════════════════════════════ */
function setFilter(btn, filter) {
  document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  currentFilter = filter;
  applyFilters();
  updatePageInfo();
}

function filterArticles() { applyFilters(); }

function applyFilters() {
  const q = document.getElementById('articleSearch').value.toLowerCase();
  document.querySelectorAll('#articleListBody .article-row').forEach(el => {
    const show = (currentFilter === 'all' || el.dataset.status === currentFilter) && 
                 (!q || el.dataset.title.includes(q));
    el.style.display = show ? '' : 'none';
  });
}

function sortArticles(val) {
  const container = document.getElementById('articleListBody');
  const items = [...container.querySelectorAll('.article-row')];
  items.sort((a, b) => {
    if(val === 'title')    return a.dataset.title.localeCompare(b.dataset.title);
    if(val === 'date-asc') return a.dataset.date.localeCompare(b.dataset.date);
    return b.dataset.date.localeCompare(a.dataset.date);
  });
  items.forEach(r => container.appendChild(r));
}

/* ═══════════════════════════════════════════════
   DETAIL MODAL
═══════════════════════════════════════════════ */
function openDetailModal(id) {
  const a = articles.find(x => x.id === id);
  if (!a) return;
  
  document.getElementById('detailModalBody').innerHTML = `
    <div class="modal-detail-row">
      <span class="modal-detail-label">Référence</span>
      <span class="modal-detail-value"><span class="article-ref">${a.ref}</span></span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Titre</span>
      <span class="modal-detail-value" style="font-weight:600;color:var(--navy)">${escapeHtml(a.title)}</span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Résumé</span>
      <span class="modal-detail-value" style="color:var(--text-light)">${escapeHtml(a.abstract || 'Aucun résumé disponible.')}</span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Conférence</span>
      <span class="modal-detail-value">${escapeHtml(a.conf)}</span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Domaine</span>
      <span class="modal-detail-value">${escapeHtml(a.domain)}</span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Date soumission</span>
      <span class="modal-detail-value">${a.date}</span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Mots-clés</span>
      <span class="modal-detail-value" style="color:var(--muted);font-size:13px">${escapeHtml(a.keywords || '—')}</span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Statut</span>
      <span class="modal-detail-value"><span class="badge ${a.status}"><span class="badge-dot"></span>${a.label}</span></span>
    </div>
    ${a.status === 'revision' && a.revisionComment ? `
    <div style="margin-top:16px;padding:14px 16px;background:#f5f7ff;border:1px solid #bcc7fa;border-radius:var(--radius-sm);border-left:4px solid #5b6ef5">
      <div style="font-size:12px;font-weight:600;color:#3347bb;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px"><i class="fas fa-comment-dots" style="margin-right:6px"></i>Commentaires du comité</div>
      <div style="font-size:13px;color:var(--text-light);line-height:1.6">${escapeHtml(a.revisionComment)}</div>
    </div>
    ` : ''}
  `;
  
  const downloadBtn = document.getElementById('detailDownloadBtn');
  if (a.file_path) {
    // Use raw file path for download, don't escape HTML entities in URLs
    downloadBtn.href = a.file_path;
    downloadBtn.setAttribute('download', a.file_path.split('/').pop());
    downloadBtn.style.display = 'inline-flex';
  } else {
    downloadBtn.style.display = 'none';
  }
  
  openModal('detailModal');
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

/* ═══════════════════════════════════════════════
   EVALUATION MODAL
═══════════════════════════════════════════════ */
function openEvalModal(id) {
  const a = articles.find(x => x.id === id);
  if (!a) return;
  
  const starsHTML = (n) => {
    let s = '';
    for(let i=1;i<=5;i++) s += `<i class="fas fa-star" style="color:${i<=n?'var(--gold)':'var(--border)'}"></i>`;
    return s;
  };

  if(!a.evaluations.length) {
    document.getElementById('evalModalBody').innerHTML = `
      <div style="text-align:center;padding:40px 20px;color:var(--muted)">
        <i class="fas fa-hourglass-half" style="font-size:36px;margin-bottom:14px;display:block;opacity:.4"></i>
        <div style="font-size:15px;font-weight:500;color:var(--navy);margin-bottom:6px">Évaluation en cours</div>
        <div style="font-size:13px">Les évaluations ne sont pas encore disponibles pour cet article.</div>
      </div>`;
  } else {
    document.getElementById('evalModalBody').innerHTML = `
      <div style="margin-bottom:16px;padding:12px 16px;background:var(--bg);border-radius:var(--radius-sm);border:1px solid var(--border)">
        <div style="font-size:13px;font-weight:600;color:var(--navy)">${escapeHtml(a.title)}</div>
        <div style="font-size:12px;color:var(--muted);margin-top:3px">${a.ref} · ${escapeHtml(a.conf)}</div>
      </div>
      ${a.evaluations.map(ev => `
        <div class="eval-reviewer">
          <div class="reviewer-avatar">${ev.name.split(' ').map(w=>w[0]).join('').slice(0,2)}</div>
          <div class="reviewer-info">
            <div class="reviewer-name">${escapeHtml(ev.name)}</div>
            <div class="reviewer-role">${escapeHtml(ev.role)}</div>
            <div class="reviewer-comment ${ev.sentiment}">${escapeHtml(ev.comment)}</div>
            <div class="score-row">
              ${ev.scores.map(sc => `
                <div class="score-item">
                  <span class="score-label">${sc.label}</span>
                  <span>${starsHTML(sc.stars)}</span>
                </div>
              `).join('')}
            </div>
          </div>
        </div>
      `).join('')}
    `;
  }
  openModal('evalModal');
}

/* ═══════════════════════════════════════════════
   REVISION MODAL
═══════════════════════════════════════════════ */
function openRevisionModal(id) {
  const a = articles.find(x => x.id === id);
  if (!a) return;
  
  currentRevisionId = id;
  chosenFile = null;

  document.getElementById('revisionModalBody').innerHTML = `
    <div style="margin-bottom:18px;padding:14px 16px;background:#f5f7ff;border:1px solid #bcc7fa;border-radius:var(--radius-sm);border-left:4px solid #5b6ef5">
      <div style="font-size:12px;font-weight:600;color:#3347bb;margin-bottom:5px;text-transform:uppercase;letter-spacing:.8px"><i class="fas fa-comment-dots" style="margin-right:6px"></i>Retours du comité</div>
      <div style="font-size:13px;color:var(--text-light);line-height:1.6">${escapeHtml(a.revisionComment || 'Veuillez consulter les commentaires des relecteurs pour effectuer les corrections demandées.')}</div>
    </div>

    <div style="margin-bottom:18px;padding:12px 16px;background:var(--bg);border-radius:var(--radius-sm);border:1px solid var(--border)">
      <div style="font-size:12px;color:var(--muted);margin-bottom:2px">Article concerné</div>
      <div style="font-size:13.5px;font-weight:600;color:var(--navy)">${escapeHtml(a.title)}</div>
      <div style="font-size:12px;color:var(--muted);margin-top:3px">${a.ref} · ${escapeHtml(a.conf)}</div>
    </div>

    <form id="revisionForm" enctype="multipart/form-data">
      <input type="hidden" name="article_id" value="${id}">
      <div class="revision-section" style="border-top:none;padding-top:0;margin-top:0">
        <div class="revision-title"><i class="fas fa-file-pdf" style="color:var(--danger)"></i> Nouveau fichier PDF révisé</div>
        <div class="revision-subtitle">Téléversez la version corrigée de votre article (PDF uniquement, max 15 Mo)</div>

        <div class="upload-zone" id="uploadZone"
          ondragover="handleDragOver(event)"
          ondragleave="handleDragLeave(event)"
          ondrop="handleDrop(event)">
          <input type="file" name="pdf_file" id="pdfUpload" accept=".pdf" onchange="handleFileSelect(event)">
          <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
          <div class="upload-text">Glisser-déposer le PDF ici</div>
          <div class="upload-sub">ou <span class="upload-link">cliquer pour parcourir</span></div>
        </div>

        <div class="file-chosen" id="fileChosen">
          <i class="fas fa-file-pdf" style="color:var(--danger);font-size:18px"></i>
          <span class="file-chosen-name" id="fileChosenName">—</span>
          <span class="file-chosen-size" id="fileChosenSize">—</span>
          <button type="button" class="file-chosen-remove" onclick="removeFile()" title="Supprimer"><i class="fas fa-times"></i></button>
        </div>

        <div class="revision-comment-label">Message pour le relecteur <span style="color:var(--muted)">(optionnel)</span></div>
        <textarea class="revision-comment-input" name="revision_message" id="revisionMessage" placeholder="Expliquez les modifications apportées, répondez aux commentaires du comité..."></textarea>
      </div>
    </form>
  `;

  openModal('revisionModal');
}

function handleDragOver(e) {
  e.preventDefault();
  document.getElementById('uploadZone').classList.add('drag-over');
}
function handleDragLeave(e) {
  document.getElementById('uploadZone').classList.remove('drag-over');
}
function handleDrop(e) {
  e.preventDefault();
  document.getElementById('uploadZone').classList.remove('drag-over');
  const file = e.dataTransfer.files[0];
  if(file) processFile(file);
}
function handleFileSelect(e) {
  const file = e.target.files[0];
  if(file) processFile(file);
}

function processFile(file) {
  if(file.type !== 'application/pdf') {
    showToast('Seuls les fichiers PDF sont acceptés.', 'error');
    return;
  }
  if(file.size > 15 * 1024 * 1024) {
    showToast('Le fichier dépasse la limite de 15 Mo.', 'error');
    return;
  }
  chosenFile = file;
  const mb = (file.size / (1024*1024)).toFixed(2);
  document.getElementById('fileChosenName').textContent = file.name;
  document.getElementById('fileChosenSize').textContent = mb + ' Mo';
  document.getElementById('fileChosen').classList.add('visible');
  document.getElementById('uploadZone').style.borderColor = 'var(--success)';
  document.getElementById('uploadZone').style.background  = '#e8f6f3';
}

function removeFile() {
  chosenFile = null;
  document.getElementById('fileChosen').classList.remove('visible');
  document.getElementById('pdfUpload').value = '';
  document.getElementById('uploadZone').style.borderColor = '';
  document.getElementById('uploadZone').style.background  = '';
}

function submitRevision() {
  if(!chosenFile) {
    showToast("Veuillez sélectionner un fichier PDF avant d'envoyer.", "error");
    return;
  }

  const btn = document.getElementById("revisionSubmitBtn");
  const originalHtml = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi en cours…';
  btn.disabled = true;

  const formData = new FormData(document.getElementById("revisionForm"));
  formData.append("action", "submit_revision");

  fetch("api_revisions.php", {
    method: "POST",
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if(data.success) {
      const a = articles.find(x => x.id === currentRevisionId);
      if(a) { 
        a.status = "review"; 
        a.label = "En évaluation"; 
      }
      closeModal("revisionModal");
      showToast(data.message || "Révision envoyée avec succès !", "success");
      setTimeout(() => location.reload(), 1500);
    } else {
      showToast(data.error || "Erreur lors de l'envoi.", "error");
    }
  })
  .catch(err => {
    console.error("Error:", err);
    showToast("Erreur réseau. Veuillez réessayer.", "error");
  })
  .finally(() => {
    btn.innerHTML = originalHtml;
    btn.disabled = false;
  });
}

/* ═══════════════════════════════════════════════
   NOTIFICATIONS
═══════════════════════════════════════════════ */
function toggleNotifications(e) {
  e.stopPropagation();
  document.getElementById('notifDropdown').classList.toggle('open');
}

function handleNotifClick(notifId, articleId) {
  // Mark as read via AJAX
  fetch('api_notifications.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=mark_read&id=' + notifId
  });

  // Update UI
  const notifEl = document.getElementById('notif-' + notifId);
  if(notifEl) {
    notifEl.classList.remove('unread');
    const dot = notifEl.querySelector('.notif-dot');
    if(dot) dot.classList.add('hidden');
  }
  updateBadge();
  document.getElementById('notifDropdown').classList.remove('open');

  if(articleId !== null) openDetailModal(articleId);
}

function markAllRead() {
  fetch('api_notifications.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=mark_all_read'
  });

  document.querySelectorAll('.notif-item').forEach(el => {
    el.classList.remove('unread');
    const dot = el.querySelector('.notif-dot');
    if(dot) dot.classList.add('hidden');
  });
  updateBadge();
}

function updateBadge() {
  const unread = document.querySelectorAll('.notif-item.unread').length;
  const badge = document.getElementById('notifBadge');
  badge.textContent = unread;
  badge.classList.toggle('hidden', unread === 0);
}

document.addEventListener('click', e => {
  const dropdown = document.getElementById('notifDropdown');
  const btn = document.getElementById('notifBtn');
  if(!btn.contains(e.target) && !dropdown.contains(e.target)) {
    dropdown.classList.remove('open');
  }
});

/* ═══════════════════════════════════════════════
   MODAL HELPERS
═══════════════════════════════════════════════ */
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
  if(e.key === 'Escape') {
    ['detailModal','revisionModal','evalModal'].forEach(closeModal);
  }
});

/* ═══════════════════════════════════════════════
   TOAST
═══════════════════════════════════════════════ */
function showToast(msg, type = 'info') {
  const wrap = document.getElementById('toastWrap');
  const toast = document.createElement('div');
  const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', info: 'fa-info-circle' };
  toast.className = `toast ${type}`;
  toast.innerHTML = `<i class="fas ${icons[type] || 'fa-info-circle'}"></i> ${msg}`;
  wrap.appendChild(toast);
  setTimeout(() => toast.remove(), 4000);
}
</script>
</body>
</html>