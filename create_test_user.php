<?php
// create_test_user.php - Run this to create a test user with correct password
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Delete existing test user if exists
$delete = "DELETE FROM users WHERE email = 'test@univ.dz'";
$db->exec($delete);

// Create new test user
$username = 'test.user';
$email = 'test@univ.dz';
$password = 'password123';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
$first_name = 'Test';
$last_name = 'User';
$role = 'chercheur';

$query = "INSERT INTO users (username, email, password, first_name, last_name, role, status, created_at) 
          VALUES (:username, :email, :password, :first_name, :last_name, :role, 'active', NOW())";

$stmt = $db->prepare($query);
$result = $stmt->execute([
    ':username' => $username,
    ':email' => $email,
    ':password' => $hashed_password,
    ':first_name' => $first_name,
    ':last_name' => $last_name,
    ':role' => $role
]);

if ($result) {
    echo "✅ Test user created successfully!\n";
    echo "Email: test@univ.dz\n";
    echo "Password: password123\n";
    echo "Role: chercheur\n";
} else {
    echo "❌ Error creating test user\n";
}
?>