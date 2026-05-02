<?php
// test_login.php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$email = 'sarah.mansouri@univ-chlef.dz';
$password = 'password123';

$query = "SELECT id, email, password, role FROM users WHERE email = :email";
$stmt = $db->prepare($query);
$stmt->execute([':email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    echo "Utilisateur trouvé : " . $user['email'] . "<br>";
    echo "Rôle : " . $user['role'] . "<br>";
    
    if (password_verify($password, $user['password'])) {
        echo "✅ Mot de passe correct !<br>";
    } else {
        echo "❌ Mot de passe incorrect.<br>";
        echo "Hash stocké : " . $user['password'] . "<br>";
    }
} else {
    echo "❌ Utilisateur non trouvé.<br>";
}
?>