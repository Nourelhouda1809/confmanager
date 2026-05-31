<?php
require_once 'config.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Invalid request method'], 405);
}

$action = $_POST['action'] ?? '';

if ($action === 'submit_revision') {
    $articleId = $_POST['article_id'] ?? null;
    $message = trim($_POST['revision_message'] ?? '');
    $userId = getCurrentUserId();

    if (!$articleId) {
        jsonResponse(['success' => false, 'error' => 'Article ID manquant']);
    }

    // Verify article belongs to user
    $stmt = $pdo->prepare("SELECT * FROM articles WHERE id = ? AND (author_email = ? OR author = ?)");
    $user = getCurrentUser($pdo);
    $stmt->execute([$articleId, $user['email'], $user['first_name'] . ' ' . $user['last_name']]);
    $article = $stmt->fetch();

    if (!$article) {
        jsonResponse(['success' => false, 'error' => 'Article non trouvé ou non autorisé']);
    }

    if ($article['status'] !== 'revision') {
        jsonResponse(['success' => false, 'error' => 'Cet article ne nécessite pas de révision']);
    }

    // Handle file upload
    if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'error' => 'Veuillez sélectionner un fichier PDF']);
    }

    $upload = uploadFile($_FILES['pdf_file']);
    if (!$upload['success']) {
        jsonResponse(['success' => false, 'error' => $upload['error']]);
    }

    try {
        $pdo->beginTransaction();

        // Update article status back to review and update file path
        $stmt = $pdo->prepare("
            UPDATE articles 
            SET status = 'review', 
                file_path = ?, 
                final_comment = CONCAT(COALESCE(final_comment, ''), '\n\n[Révision ', NOW(), '] ', ?)
            WHERE id = ?
        ");
        $stmt->execute([$upload['path'], $message, $articleId]);

        // Add notification for managers
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, message, article_id, created_at) 
            SELECT c.user_id, 'info', CONCAT('Révision soumise pour l'article : ', ?), ?, NOW()
            FROM conferences c 
            WHERE c.id = ?
        ");
        $stmt->execute([$article['title'], $articleId, $article['conference_id']]);

        $pdo->commit();
        jsonResponse(['success' => true, 'message' => 'Révision envoyée avec succès !']);

    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()]);
    }
}

jsonResponse(['success' => false, 'error' => 'Action non reconnue']);
?>