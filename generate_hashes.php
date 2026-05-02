<?php
// generate_hashes.php - Run this file once to generate correct password hashes
// Place this file in your project root and access it via browser, then copy the output

echo "<pre>";
echo "=== Password Hashes for ConfManager ===\n\n";

$passwords = [
    'password123' => 'password123',
    'admin123' => 'admin123',
    'test123' => 'test123',
];

foreach ($passwords as $label => $password) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    echo "Password: {$password}\n";
    echo "Hash for {$label}: {$hash}\n\n";
}

echo "\n=== SQL INSERT Statements ===\n\n";

// Generate SQL for the users
$users = [
    ['ahmed.benali', 'ahmed.benali@univ-chlef.dz', 'Ahmed', 'Benali', 'reviewer', 'Université de Chlef'],
    ['sarah.mansouri', 'sarah.mansouri@univ-chlef.dz', 'Sarah', 'Mansouri', 'chercheur', 'Université de Chlef'],
    ['karim.tech', 'karim.tech@univ-alger.dz', 'Karim', 'Tech', 'reviewer', "Université d'Alger"],
    ['fatima.zahra', 'fatima.zahra@univ-oran.dz', 'Fatima', 'Zahra', 'gestionnaire', "Université d'Oran"],
];

$hashForPassword123 = password_hash('password123', PASSWORD_DEFAULT);

foreach ($users as $user) {
    echo "INSERT INTO users (username, email, password, first_name, last_name, role, institution, status, created_at) VALUES\n";
    echo "('{$user[0]}', '{$user[1]}', '{$hashForPassword123}', '{$user[2]}', '{$user[3]}', '{$user[4]}', '{$user[5]}', 'active', NOW());\n\n";
}

echo "</pre>";
?>