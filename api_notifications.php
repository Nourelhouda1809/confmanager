<?php
require_once 'config.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$userId = getCurrentUserId();

if ($action === 'mark_read') {
    $notifId = $_POST['id'] ?? null;

    if ($notifId) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notifId, $userId]);
    }
    jsonResponse(['success' => true]);
}

if ($action === 'mark_all_read') {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$userId]);
    jsonResponse(['success' => true]);
}

if ($action === 'get_unread_count') {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    jsonResponse(['success' => true, 'count' => (int)$result['count']]);
}

jsonResponse(['success' => false, 'error' => 'Action non reconnue']);
?>