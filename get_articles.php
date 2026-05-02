<?php
// get_articles.php - Récupération des articles pour une conférence
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_GET['conference_id'])) {
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit;
}

$database = new Database();
$db = $database->getConnection();
$conferenceId = (int)$_GET['conference_id'];

try {
    $query = "SELECT a.*, u.first_name, u.last_name 
              FROM articles a
              LEFT JOIN users u ON a.user_id = u.id
              WHERE a.conference_id = :conference_id
              ORDER BY a.submission_date DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':conference_id' => $conferenceId]);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [];
    foreach ($articles as $article) {
        $result[] = [
            'id' => $article['id'],
            'title' => $article['title'],
            'author' => ($article['first_name'] ?? '') . ' ' . ($article['last_name'] ?? 'Inconnu'),
            'submission_date' => date('Y-m-d', strtotime($article['submission_date'] ?? $article['created_at'] ?? 'now')),
            'status' => $article['status'] ?? 'pending'
        ];
    }
    
    echo json_encode(['success' => true, 'articles' => $result]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>