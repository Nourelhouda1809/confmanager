<?php
// fix_passwords.php - Run this to fix all user passwords
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get all users
$query = "SELECT id, email FROM users";
$stmt = $db->prepare($query);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
foreach ($users as $user) {
    // Set password to 'password123' for all users
    $new_hash = password_hash('password123', PASSWORD_DEFAULT);
    
    $update = "UPDATE users SET password = :password WHERE id = :id";
    $updateStmt = $db->prepare($update);
    $result = $updateStmt->execute([
        ':password' => $new_hash,
        ':id' => $user['id']
    ]);
    
    if ($result) {
        $updated++;
        echo "✓ Updated password for: {$user['email']}\n";
    }
}

echo "\n✅ Updated {$updated} user(s).\n";
echo "All users now have password: password123\n";
?>