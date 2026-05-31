<?php
// get_topics.php — Returns JSON topics for a given conference
header('Content-Type: application/json');

require_once 'config.php';

$conference_id = (int) ($_GET['conference_id'] ?? 0);

if ($conference_id <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT t.id, t.name 
         FROM topics t
         JOIN conference_topics ct ON t.id = ct.topic_id
         WHERE ct.conference_id = :cid
         ORDER BY t.name"
    );
    $stmt->execute([':cid' => $conference_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
    echo json_encode([]);
}