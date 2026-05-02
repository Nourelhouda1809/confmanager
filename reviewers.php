<?php
// ─────────────────────────────────────────────
//  reviewer_dashboard.php
//  Tableau de bord — Évaluateur (Reviewer)
// ─────────────────────────────────────────────
session_start();

// ── Auth guard ──────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'reviewer') {
    header('Location: login.php');
    exit;
}

// ── DB connection ────────────────────────────
require_once 'config/db.php'; // PDO $pdo

$reviewerId = $_SESSION['user_id'];

// ── Reviewer info ────────────────────────────
$stmtUser = $pdo->prepare("SELECT nom, prenom FROM users WHERE id = :id");
$stmtUser->execute([':id' => $reviewerId]);
$reviewer = $stmtUser->fetch(PDO::FETCH_ASSOC);
$reviewerName = $reviewer ? "Pr. {$reviewer['prenom']} {$reviewer['nom']}" : 'Évaluateur';

// ── Assignments ──────────────────────────────
// Fetch all articles assigned to this reviewer with their status
$stmtAssign = $pdo->prepare("
    SELECT
        a.id,
        a.reference              AS ref,
        a.titre                  AS title,
        a.titre_ar               AS title_ar,
        a.resume                 AS abstract,
        a.domaine                AS specialty,
        c.nom                    AS conf,
        e.decision               AS submitted_decision,
        e.date_evaluation        AS eval_date,
        CASE
            WHEN e.id IS NOT NULL THEN 'done'
            WHEN a.statut = 'revised' THEN 'revised'
            ELSE 'pending'
        END                      AS status,
        r.date_limite            AS deadline,
        r.date_assignation       AS assigned_date,
        f.nom_fichier            AS file,
        f.taille                 AS file_size
    FROM reviewers r
    JOIN articles a  ON a.id = r.article_id
    JOIN conferences c ON c.id = a.conference_id
    LEFT JOIN evaluations e ON e.article_id = a.id AND e.reviewer_id = r.reviewer_id
    LEFT JOIN fichiers f ON f.article_id = a.id AND f.type = 'final'
    WHERE r.reviewer_id = :rid
    ORDER BY r.date_limite ASC
");
$stmtAssign->execute([':rid' => $reviewerId]);
$assignments = $stmtAssign->fetchAll(PDO::FETCH_ASSOC);

// ── Compute deadline urgency helper ──────────
function deadlineInfo(string $deadline): array {
    $today = new DateTime('today');
    $due   = new DateTime($deadline);
    $diff  = (int)$today->diff($due)->format('%r%a'); // negative = past

    if ($diff < 0) {
        return ['label' => 'Expiré',           'urgency' => 'ok'];
    } elseif ($diff <= 2) {
        return ['label' => "Dans {$diff} jour" . ($diff > 1 ? 's' : ''), 'urgency' => 'urgent'];
    } elseif ($diff <= 7) {
        return ['label' => "Dans {$diff} jours", 'urgency' => 'soon'];
    } else {
        return ['label' => "Dans {$diff} jours", 'urgency' => 'ok'];
    }
}

// ── Counts for KPI cards ─────────────────────
$counts = ['accepted' => 0, 'rejected' => 0, 'new' => 0, 'revised' => 0];
foreach ($assignments as &$a) {
    $dl = deadlineInfo($a['deadline']);
    $a['deadline_label']   = $dl['label'];
    $a['deadline_urgency'] = $dl['urgency'];

    $statusLabel = match($a['status']) {
        'done'    => 'Évalué',
        'revised' => 'À re-évaluer',
        default   => 'À évaluer',
    };
    $a['status_label'] = $statusLabel;

    if ($a['status'] === 'done') {
        if ($a['submitted_decision'] === 'accepted') $counts['accepted']++;
        else                                         $counts['rejected']++;
    } elseif ($a['status'] === 'revised') {
        $counts['revised']++;
    } else {
        $counts['new']++;
    }
}
unset($a);

// ── Notifications ─────────────────────────────
$stmtNotif = $pdo->prepare("
    SELECT id, type, message, lu, created_at, article_id
    FROM notifications
    WHERE user_id = :uid
    ORDER BY created_at DESC
    LIMIT 20
");
$stmtNotif->execute([':uid' => $reviewerId]);
$notifications = $stmtNotif->fetchAll(PDO::FETCH_ASSOC);

$unreadCount   = count(array_filter($notifications, fn($n) => !$n['lu']));
$unreadNew     = count(array_filter($notifications, fn($n) => !$n['lu'] && $n['type'] === 'new-article'));
$unreadRemind  = count(array_filter($notifications, fn($n) => !$n['lu'] && $n['type'] === 'reminder'));
$unreadRevised = count(array_filter($notifications, fn($n) => !$n['lu'] && $n['type'] === 'revised'));

// ── Pending (non-done) sorted by deadline ────
$pending = array_filter($assignments, fn($a) => $a['status'] !== 'done');
usort($pending, fn($a, $b) => strcmp($a['deadline'], $b['deadline']));
$pendingTop4 = array_slice(array_values($pending), 0, 4);
$deadlineTop5 = array_slice(array_values($pending), 0, 5);

// ── Helper: format date ───────────────────────
function fmtDate(?string $d): string {
    if (!$d) return '—';
    return (new DateTime($d))->format('d M Y');
}

// ── CSRF token ────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConfManager — Tableau de Bord</title>
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
    --shadow-md: 0 8px 32px rgba(13,33,55,0.15);
    --radius: 8px;
    --radius-sm: 4px;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

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
    border-radius: var(--radius-sm); cursor: pointer; font-family: 'DM Sans', sans-serif;
    transition: all 0.15s; position: relative; text-decoration: none; display: inline-block;
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
  .search-input:focus { border-color: var(--accent); background: var(--white); box-shadow: 0 0 0 3px rgba(44,111,173,0.10); width: 280px; }
  .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 13px; pointer-events: none; }

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
  .notif-mark-all { font-size: 12px; color: var(--accent); cursor: pointer; border: none; background: none; font-family: 'DM Sans', sans-serif; }
  .notif-mark-all:hover { text-decoration: underline; }

  .notif-categories {
    display: flex; border-bottom: 1px solid var(--border);
    background: var(--bg);
  }
  .notif-cat-btn {
    flex: 1; padding: 10px 8px; border: none; background: none;
    font-family: 'DM Sans', sans-serif; font-size: 12px; font-weight: 500;
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
    cursor: pointer; font-family: 'DM Sans', sans-serif;
    transition: all 0.15s; text-decoration: none;
  }
  .logout-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--white); }

  /* ─── PAGE ─── */
  .page { max-width: 1100px; margin: 0 auto; padding: 40px 32px 60px; }
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
    position: relative; overflow: hidden; text-decoration: none; display: block;
  }
  .status-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); border-color: var(--navy); }
  .status-card.accept  { border-left: 4px solid var(--success); }
  .status-card.reject  { border-left: 4px solid var(--danger); }
  .status-card.new     { border-left: 4px solid var(--accent); }
  .status-card.revised { border-left: 4px solid #5b6ef5; }
  .status-card-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
  .status-card-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; }
  .status-card.accept  .status-card-icon { background: #e8f6f3; color: var(--success); }
  .status-card.reject  .status-card-icon { background: #fdf2f2; color: var(--danger); }
  .status-card.new     .status-card-icon { background: #eef3fb; color: var(--accent); }
  .status-card.revised .status-card-icon { background: #f0f4ff; color: #5b6ef5; }
  .status-card-title { font-size: 13px; font-weight: 600; color: var(--navy); }
  .status-card-count { font-size: 28px; font-weight: 700; color: var(--navy); margin-top: 4px; }
  .status-card-label { font-size: 12px; color: var(--muted); }

  /* ─── DEADLINE STRIP ─── */
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
  .deadline-item.urgent { background: #fdf2f2; color: #8b2020; border-color: #f5b8b8; }
  .deadline-item.soon   { background: #fef8ec; color: #9a5f00; border-color: #f5d98a; }
  .deadline-item.ok     { background: #e8f6f3; color: #1a5f57; border-color: #9dd8d0; }
  .deadline-item i      { font-size: 11px; }

  /* ─── ARTICLES TABLE ─── */
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
  .article-ref { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--muted); background: var(--bg); padding: 2px 7px; border-radius: 3px; border: 1px solid var(--border); }
  .article-conf   { font-size: 12px; color: var(--text-light); }
  .td-domain      { font-size: 13px; color: var(--text-light); }
  .deadline-cell  { font-size: 12.5px; font-weight: 500; }
  .deadline-cell.urgent { color: var(--danger); }
  .deadline-cell.soon   { color: var(--warning); }
  .deadline-cell.ok     { color: var(--success); }

  .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
  .badge-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
  .badge.pending   { background: #fef8ec; color: #9a5f00;  border: 1px solid #f5d98a; }
  .badge.pending   .badge-dot { background: var(--warning); }
  .badge.reviewing { background: #eef3fb; color: #1a4a7a;  border: 1px solid #b3cdf0; }
  .badge.reviewing .badge-dot { background: var(--accent); }
  .badge.done      { background: #e8f6f3; color: #1a5f57;  border: 1px solid #9dd8d0; }
  .badge.done      .badge-dot { background: var(--success); }
  .badge.revised   { background: #f0f4ff; color: #3347bb;  border: 1px solid #bcc7fa; }
  .badge.revised   .badge-dot { background: #5b6ef5; }

  .row-actions { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
  .action-btn {
    height: 30px; padding: 0 10px; border-radius: var(--radius-sm);
    border: 1px solid var(--border); background: none;
    color: var(--muted); cursor: pointer; font-size: 12px;
    display: flex; align-items: center; gap: 5px;
    transition: all 0.15s; font-family: 'DM Sans', sans-serif; white-space: nowrap;
    text-decoration: none;
  }
  .action-btn:hover { background: var(--bg); color: var(--navy); border-color: var(--navy); }
  .action-btn.primary { background: var(--navy); color: var(--gold-light); border-color: var(--navy); }
  .action-btn.primary:hover { background: var(--navy-mid); }
  .action-btn.revised-btn { border-color: #bcc7fa; color: #5b6ef5; }
  .action-btn.revised-btn:hover { background: #f0f4ff; color: #3347bb; }

  /* ─── MODALS ─── */
  .modal-backdrop {
    position: fixed; inset: 0; background: rgba(13,33,55,0.5);
    backdrop-filter: blur(4px); z-index: 200;
    display: none; align-items: center; justify-content: center; padding: 20px;
  }
  .modal-backdrop.open { display: flex; }
  .modal {
    background: var(--white); border-radius: var(--radius);
    width: 100%; max-width: 580px; box-shadow: var(--shadow-md);
    animation: fadeUp 0.2s ease; max-height: 90vh; overflow-y: auto;
  }
  .modal.modal-wide { max-width: 700px; }
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
    position: sticky; bottom: 0; background: var(--white);
  }
  .modal-detail-row { display: flex; gap: 10px; margin-bottom: 14px; align-items: flex-start; }
  .modal-detail-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); width: 110px; flex-shrink: 0; padding-top: 2px; }
  .modal-detail-value { font-size: 13.5px; color: var(--text); flex: 1; line-height: 1.5; }

  .btn-primary {
    background: var(--navy); color: var(--gold-light);
    border: none; border-radius: var(--radius-sm);
    padding: 11px 22px; font-size: 13.5px; font-weight: 500;
    font-family: 'DM Sans', sans-serif; cursor: pointer;
    transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none; white-space: nowrap;
  }
  .btn-primary:hover { background: var(--navy-mid); }
  .btn-secondary {
    background: none; color: var(--text-light);
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    padding: 9px 20px; font-size: 13.5px;
    font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s;
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
    font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.15s;
    display: flex; align-items: center; gap: 7px; white-space: nowrap; text-decoration: none;
  }
  .download-btn:hover { background: var(--navy-mid); }

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
  <a class="brand" href="reviewer_dashboard.php">
    <div class="brand-icon">📋</div>
    <span class="brand-name">ConfManager</span>
  </a>
  <nav class="nav-links">
    <a href="reviewer_dashboard.php" class="nav-link active">Tableau de bord</a>
    <a href="articles.php" class="nav-link">Articles assignés</a>
    <a href="base-articles.php" class="nav-link">Base d'articles</a>
    <a href="profile_r.php" class="nav-link">Profil</a>
  </nav>
  <div class="topbar-right">
    <div class="search-wrap">
      <span class="search-icon"><i class="fas fa-search"></i></span>
      <input type="text" class="search-input" placeholder="Rechercher…" id="searchInput">
    </div>

    <!-- NOTIFICATION BELL -->
    <div class="notif-wrap">
      <button class="notif-btn" id="notifBtn" onclick="toggleNotif(event)">
        <i class="fas fa-bell"></i>
        <span class="notif-badge<?= $unreadCount === 0 ? ' hidden' : '' ?>" id="notifBadge">
          <?= $unreadCount ?>
        </span>
      </button>
      <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-dd-header">
          <span class="notif-dd-title">Notifications</span>
          <button class="notif-mark-all" onclick="markAllRead()">Tout marquer lu</button>
        </div>
        <div class="notif-categories">
          <button class="notif-cat-btn active" onclick="filterNotifDropdown('all', this)">
            <i class="fas fa-layer-group"></i> Tous
            <span class="notif-cat-count<?= $unreadCount === 0 ? ' zero' : '' ?>" id="cat-count-all">
              <?= $unreadCount ?>
            </span>
          </button>
          <button class="notif-cat-btn" onclick="filterNotifDropdown('new-article', this)">
            <i class="fas fa-file-alt"></i> Nouveaux
            <span class="notif-cat-count<?= $unreadNew === 0 ? ' zero' : '' ?>" id="cat-count-new">
              <?= $unreadNew ?>
            </span>
          </button>
          <button class="notif-cat-btn" onclick="filterNotifDropdown('reminder', this)">
            <i class="fas fa-clock"></i> Rappels
            <span class="notif-cat-count<?= $unreadRemind === 0 ? ' zero' : '' ?>" id="cat-count-reminder">
              <?= $unreadRemind ?>
            </span>
          </button>
          <button class="notif-cat-btn" onclick="filterNotifDropdown('revised', this)">
            <i class="fas fa-sync-alt"></i> Modifiés
            <span class="notif-cat-count<?= $unreadRevised === 0 ? ' zero' : '' ?>" id="cat-count-revised">
              <?= $unreadRevised ?>
            </span>
          </button>
        </div>
        <div class="notif-list" id="notifList">
          <?php foreach ($notifications as $n): ?>
            <?php
              $typeColor = match($n['type']) {
                'new-article' => 'new-article',
                'revised'     => 'revised',
                'reminder'    => 'reminder',
                default       => 'info',
              };
              $typeIcon = match($n['type']) {
                'new-article' => 'fa-file-alt',
                'revised'     => 'fa-sync-alt',
                'reminder'    => 'fa-clock',
                default       => 'fa-check-circle',
              };
              $timeAgo = (new DateTime($n['created_at']))->diff(new DateTime())->days;
              $timeLabel = $timeAgo === 0 ? "Aujourd'hui" : "Il y a {$timeAgo} jour" . ($timeAgo > 1 ? 's' : '');
            ?>
            <div class="notif-item <?= $n['lu'] ? '' : 'unread' ?>"
                 data-id="<?= (int)$n['id'] ?>"
                 data-type="<?= htmlspecialchars($n['type']) ?>"
                 data-article-id="<?= (int)($n['article_id'] ?? 0) ?>"
                 onclick="handleNotifClick(this)">
              <div class="notif-icon-wrap <?= $typeColor ?>">
                <i class="fas <?= $typeIcon ?>"></i>
              </div>
              <div class="notif-content">
                <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
                <div class="notif-time"><i class="fas fa-clock" style="margin-right:4px;opacity:.6"></i><?= $timeLabel ?></div>
              </div>
              <div class="notif-dot <?= $n['lu'] ? 'hidden' : '' ?>"></div>
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

<!-- ═══════ TABLEAU DE BORD ═══════ -->
<main class="page">
  <div class="page-header">
    <div class="page-header-row">
      <h1>Tableau de <em>bord</em></h1>
    </div>
    <p>Bienvenue, <?= htmlspecialchars($reviewerName) ?> — voici un aperçu de vos révisions</p>
  </div>

  <!-- KPI CARDS -->
  <div class="status-cards-row">
    <a href="articles.php?filter=accepted" class="status-card accept">
      <div class="status-card-header">
        <div class="status-card-icon"><i class="fas fa-check-circle"></i></div>
        <div class="status-card-title">Articles acceptés</div>
      </div>
      <div class="status-card-count"><?= $counts['accepted'] ?></div>
      <div class="status-card-label">Évaluations positives</div>
    </a>
    <a href="articles.php?filter=rejected" class="status-card reject">
      <div class="status-card-header">
        <div class="status-card-icon"><i class="fas fa-times-circle"></i></div>
        <div class="status-card-title">Articles rejetés</div>
      </div>
      <div class="status-card-count"><?= $counts['rejected'] ?></div>
      <div class="status-card-label">Évaluations négatives</div>
    </a>
    <a href="articles.php?filter=new" class="status-card new">
      <div class="status-card-header">
        <div class="status-card-icon"><i class="fas fa-star"></i></div>
        <div class="status-card-title">Nouveaux articles</div>
      </div>
      <div class="status-card-count"><?= $counts['new'] ?></div>
      <div class="status-card-label">À évaluer</div>
    </a>
    <a href="articles.php?filter=revised" class="status-card revised">
      <div class="status-card-header">
        <div class="status-card-icon"><i class="fas fa-sync-alt"></i></div>
        <div class="status-card-title">Versions révisées</div>
      </div>
      <div class="status-card-count"><?= $counts['revised'] ?></div>
      <div class="status-card-label">À re-évaluer</div>
    </a>
  </div>

  <!-- DEADLINE STRIP -->
  <div class="deadline-strip">
    <span class="deadline-strip-title">
      <i class="fas fa-clock" style="margin-right:6px;color:var(--warning)"></i>Échéances de révision
    </span>
    <div class="deadline-items">
      <?php foreach ($deadlineTop5 as $a):
        $cls  = $a['deadline_urgency'];
        $icon = $cls === 'urgent' ? 'fa-fire' : ($cls === 'soon' ? 'fa-exclamation' : 'fa-check');
      ?>
        <span class="deadline-item <?= $cls ?>"
              onclick="openDetailModal(<?= (int)$a['id'] ?>)">
          <i class="fas <?= $icon ?>"></i>
          <?= htmlspecialchars($a['ref']) ?> · <?= htmlspecialchars($a['deadline_label']) ?>
        </span>
      <?php endforeach; ?>
      <?php if (empty($deadlineTop5)): ?>
        <span style="font-size:13px;color:var(--muted)">Aucune échéance en cours</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- UPCOMING DEADLINES TABLE -->
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
    <?php if (empty($pendingTop4)): ?>
      <div style="padding:32px;text-align:center;color:var(--muted);font-size:14px">
        <i class="fas fa-inbox" style="font-size:28px;margin-bottom:10px;display:block"></i>
        Aucun article en attente
      </div>
    <?php endif; ?>
    <?php foreach ($pendingTop4 as $a):
      $dlClass = match($a['deadline_urgency']) {
        'urgent' => 'deadline-cell urgent',
        'soon'   => 'deadline-cell soon',
        default  => 'deadline-cell ok',
      };
      $isRevised = ($a['status'] === 'revised');
    ?>
    <div class="article-row">
      <div class="td">
        <div class="article-title-main"><?= htmlspecialchars($a['title']) ?></div>
        <div class="article-meta-row">
          <span class="article-ref"><?= htmlspecialchars($a['ref']) ?></span>
          <span class="article-conf"><?= htmlspecialchars($a['conf']) ?></span>
          <?php if ($isRevised): ?>
            <span class="badge revised" style="font-size:10px;padding:2px 7px">
              <span class="badge-dot"></span>Révisé
            </span>
          <?php endif; ?>
        </div>
      </div>
      <div class="td td-domain"><?= htmlspecialchars($a['specialty']) ?></div>
      <div class="td">
        <span class="<?= $dlClass ?>">
          <i class="fas fa-calendar-alt" style="margin-right:5px;opacity:.7"></i>
          <?= htmlspecialchars($a['deadline_label']) ?>
        </span>
      </div>
      <div class="td">
        <span class="badge <?= htmlspecialchars($a['status']) ?>">
          <span class="badge-dot"></span><?= htmlspecialchars($a['status_label']) ?>
        </span>
      </div>
      <div class="td">
        <div class="row-actions">
          <?php if ($a['status'] === 'revised'): ?>
            <a href="articles.php?action=rereview&id=<?= (int)$a['id'] ?>" class="action-btn revised-btn">
              <i class="fas fa-sync-alt"></i> Re-évaluer
            </a>
          <?php else: ?>
            <a href="articles.php?action=review&id=<?= (int)$a['id'] ?>" class="action-btn primary">
              <i class="fas fa-play"></i> Commencer
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</main>

<!-- MODAL: ARTICLE DETAIL -->
<div class="modal-backdrop" id="articleDetailModal" onclick="closeOnBackdrop(event)">
  <div class="modal modal-wide">
    <div class="modal-header">
      <div class="modal-title">Détail de l'article</div>
      <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="articleDetailBody">
      <div style="text-align:center;padding:30px;color:var(--muted)">
        <i class="fas fa-spinner fa-spin" style="font-size:22px"></i>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal()">Fermer</button>
      <a href="#" class="btn-primary" id="detailActionBtn" style="display:none">Action</a>
    </div>
  </div>
</div>

<!-- TOAST -->
<div class="toast-wrap" id="toastWrap"></div>

<footer class="footer">
  © 2026 ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés
</footer>

<!-- ═══════ JS (FETCH-BASED — no page reload for modal/notifs) ═══════ -->
<script>
const CSRF = <?= json_encode($csrf) ?>;

/* Pass PHP assignments data to JS for modal use */
const assignments = <?= json_encode(array_values($assignments)) ?>;
const notifData   = <?= json_encode(array_values($notifications)) ?>;

let currentNotifFilter = 'all';

/* ── NOTIFICATION FILTERING (client-side) ─────────────────────── */
function renderNotifications() {
  const list = document.getElementById('notifList');
  const items = list.querySelectorAll('.notif-item');
  items.forEach(item => {
    const type = item.dataset.type;
    item.style.display = (currentNotifFilter === 'all' || type === currentNotifFilter) ? '' : 'none';
  });
}

function filterNotifDropdown(type, btn) {
  document.querySelectorAll('.notif-cat-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  currentNotifFilter = type;
  renderNotifications();
}

/* ── MARK ALL READ ────────────────────────────────────────────── */
function markAllRead() {
  fetch('api/notifications_mark_all.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'csrf=' + encodeURIComponent(CSRF)
  }).then(() => {
    document.querySelectorAll('.notif-item').forEach(el => {
      el.classList.remove('unread');
      el.querySelector('.notif-dot')?.classList.add('hidden');
    });
    ['all','new','reminder','revised'].forEach(k => {
      const el = document.getElementById('cat-count-' + k);
      if (el) { el.textContent = '0'; el.classList.add('zero'); }
    });
    const badge = document.getElementById('notifBadge');
    badge.textContent = '0';
    badge.classList.add('hidden');
  });
}

/* ── HANDLE SINGLE NOTIF CLICK ───────────────────────────────── */
function handleNotifClick(el) {
  const notifId   = parseInt(el.dataset.id);
  const articleId = parseInt(el.dataset.articleId);
  const type      = el.dataset.type;

  // Mark as read via API
  fetch('api/notification_read.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'csrf=' + encodeURIComponent(CSRF) + '&id=' + notifId
  });

  el.classList.remove('unread');
  el.querySelector('.notif-dot')?.classList.add('hidden');

  // Decrement badge
  const badge = document.getElementById('notifBadge');
  let count = parseInt(badge.textContent) || 0;
  if (count > 0) count--;
  badge.textContent = count;
  if (count === 0) badge.classList.add('hidden');

  document.getElementById('notifDropdown').classList.remove('open');
  document.getElementById('notifBtn').classList.remove('active');

  // Navigate or open modal
  if (articleId) {
    const a = assignments.find(x => x.id === articleId);
    if (a) {
      if (type === 'revised') {
        window.location.href = 'articles.php?action=rereview&id=' + articleId;
      } else if (a.status !== 'done') {
        window.location.href = 'articles.php?action=review&id=' + articleId;
      } else {
        openDetailModal(articleId);
      }
    }
  }
}

/* ── TOGGLE NOTIF DROPDOWN ───────────────────────────────────── */
function toggleNotif(e) {
  e.stopPropagation();
  const dd  = document.getElementById('notifDropdown');
  const btn = document.getElementById('notifBtn');
  dd.classList.toggle('open');
  btn.classList.toggle('active', dd.classList.contains('open'));
}

document.addEventListener('click', e => {
  const dd  = document.getElementById('notifDropdown');
  const btn = document.getElementById('notifBtn');
  if (!btn.contains(e.target) && !dd.contains(e.target)) {
    dd.classList.remove('open');
    btn.classList.remove('active');
  }
});

/* ── ARTICLE DETAIL MODAL ────────────────────────────────────── */
function openDetailModal(id) {
  const a = assignments.find(x => x.id === id);
  if (!a) return;

  const actionBtn = document.getElementById('detailActionBtn');
  if (a.status === 'done') {
    actionBtn.style.display = 'none';
  } else if (a.status === 'revised') {
    actionBtn.style.display = 'inline-flex';
    actionBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Re-évaluer';
    actionBtn.href = 'articles.php?action=rereview&id=' + a.id;
  } else {
    actionBtn.style.display = 'inline-flex';
    actionBtn.innerHTML = '<i class="fas fa-play"></i> Commencer l\'évaluation';
    actionBtn.href = 'articles.php?action=review&id=' + a.id;
  }

  const dlClass = a.deadline_urgency === 'urgent' ? 'urgent' :
                  a.deadline_urgency === 'soon'   ? 'soon'   : 'ok';

  const decisionRow = a.submitted_decision ? `
    <div class="modal-detail-row">
      <span class="modal-detail-label">Décision</span>
      <span class="modal-detail-value">
        <span class="badge ${a.submitted_decision === 'accepted' ? 'done' : 'pending'}">
          <span class="badge-dot"></span>
          ${a.submitted_decision === 'accepted' ? 'Accepté' : 'Rejeté'}
        </span>
      </span>
    </div>` : '';

  document.getElementById('articleDetailBody').innerHTML = `
    <div class="download-zone">
      <i class="fas fa-file-pdf download-icon"></i>
      <div class="download-info">
        <div class="download-name">${a.file || 'fichier.pdf'}</div>
        <div class="download-meta">PDF · ${a.file_size || '—'} · Assigné le ${a.assigned_date || '—'}</div>
      </div>
      <a href="uploads/${encodeURIComponent(a.file || '')}" download class="download-btn">
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
      <span class="modal-detail-value" style="color:var(--text-light)">${a.abstract || '—'}</span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Conférence</span>
      <span class="modal-detail-value">${a.conf}</span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Spécialité</span>
      <span class="modal-detail-value">${a.specialty}</span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Échéance</span>
      <span class="modal-detail-value">
        <span class="deadline-cell ${dlClass}">
          <i class="fas fa-calendar-alt" style="margin-right:5px"></i>${a.deadline_label}
        </span>
      </span>
    </div>
    <div class="modal-detail-row">
      <span class="modal-detail-label">Statut</span>
      <span class="modal-detail-value">
        <span class="badge ${a.status}"><span class="badge-dot"></span>${a.status_label}</span>
      </span>
    </div>
    ${decisionRow}
  `;

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

/* ── TOAST ───────────────────────────────────────────────────── */
function showToast(msg, type = 'info') {
  const wrap  = document.getElementById('toastWrap');
  const icons = { success:'fa-check-circle', error:'fa-exclamation-circle', info:'fa-info-circle' };
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.innerHTML = `<i class="fas ${icons[type] || 'fa-info-circle'}"></i> ${msg}`;
  wrap.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}
</script>
</body>
</html>
