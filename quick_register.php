<?php
// quick_register.php - Simple registration form
require_once 'config/database.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $database = new Database();
    $db = $database->getConnection();
    
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $role = $_POST['role'] ?? 'chercheur';
    
    // Check if user exists
    $check = "SELECT id FROM users WHERE email = :email";
    $checkStmt = $db->prepare($check);
    $checkStmt->execute([':email' => $email]);
    
    if ($checkStmt->fetch()) {
        $error = "User already exists!";
    } else {
        $username = strtolower($first_name . '.' . $last_name);
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
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
            $message = "User created successfully! You can now login.";
        } else {
            $error = "Error creating user.";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Quick User Registration</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 500px; margin: 50px auto; padding: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #003366; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .success { color: green; padding: 10px; background: #e8f5e9; margin-bottom: 15px; }
        .error { color: red; padding: 10px; background: #ffebee; margin-bottom: 15px; }
    </style>
</head>
<body>
    <h1>Quick User Registration</h1>
    
    <?php if ($message): ?>
        <div class="success"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <label>First Name:</label>
            <input type="text" name="first_name" required>
        </div>
        
        <div class="form-group">
            <label>Last Name:</label>
            <input type="text" name="last_name" required>
        </div>
        
        <div class="form-group">
            <label>Email:</label>
            <input type="email" name="email" required>
        </div>
        
        <div class="form-group">
            <label>Password:</label>
            <input type="password" name="password" required>
        </div>
        
        <div class="form-group">
            <label>Role:</label>
            <select name="role">
                <option value="chercheur">Chercheur</option>
                <option value="gestionnaire">Gestionnaire</option>
                <option value="reviewer">Reviewer</option>
            </select>
        </div>
        
        <button type="submit">Register</button>
    </form>
    
    <p style="margin-top: 20px;">
        <a href="login.php">Go to Login</a>
    </p>
</body>
</html>