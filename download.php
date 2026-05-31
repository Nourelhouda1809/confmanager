<?php
require_once 'config.php';
requireRole('reviewer');

$articleId = (int)($_GET['id'] ?? 0);
$version = $_GET['version'] ?? 'new';
$reviewerId = getCurrentUserId();

// Verify reviewer has access to this article
$stmt = $pdo->prepare("
    SELECT a.file_path, a.title 
    FROM article_reviewers ar
    JOIN articles a ON ar.article_id = a.id
    WHERE ar.article_id = ? AND ar.evaluator_id = ?
");
$stmt->execute([$articleId, $reviewerId]);
$article = $stmt->fetch();

if (!$article || empty($article['file_path'])) {
    http_response_code(403);
    die('Access denied or file not found');
}

// Prepend app root so relative paths like 'articles/art_xxx.pdf' resolve correctly
$filePath = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($article['file_path'], '/');
// Strip double 'uploads/' if file_path already contains it
if (strpos($filePath, UPLOAD_DIR) === false && strpos($article['file_path'], 'uploads/') === 0) {
    $filePath = __DIR__ . '/' . $article['file_path'];
}

// For old version, try to find original file
if ($version === 'old' && strpos($filePath, '_revised') !== false) {
    $oldPath = str_replace('_revised', '', $filePath);
    if (file_exists($oldPath)) {
        $filePath = $oldPath;
    }
}

if (!file_exists($filePath)) {
    http_response_code(404);
    die('File not found on server');
}

// Send file
$filename = basename($filePath);
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filePath) ?: 'application/pdf';
finfo_close($finfo);

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');

readfile($filePath);
exit;