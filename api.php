<?php
require_once 'config.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$userId = getCurrentUserId();

switch ($action) {
    case 'markAllRead':
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $success = $stmt->execute([$userId]);
        echo json_encode(['success' => $success]);
        break;

    case 'markRead':
        $notifId = (int)($_GET['id'] ?? 0);
        $success = markNotificationRead($notifId, $userId);
        echo json_encode(['success' => $success]);
        break;

    case 'getNotifications':
        $notifications = getUnreadNotifications($userId, 20);
        echo json_encode(['success' => true, 'data' => $notifications, 'count' => countUnreadNotifications($userId)]);
        break;

    case 'getArticle':
        $articleId = (int)($_GET['id'] ?? 0);
        $article = getArticleById($articleId);
        if ($article) {
            // Check if reviewer has access
            $stmt = $pdo->prepare("SELECT 1 FROM article_reviewers WHERE article_id = ? AND evaluator_id = ?");
            $stmt->execute([$articleId, $userId]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => true, 'data' => $article]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Article not found']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}