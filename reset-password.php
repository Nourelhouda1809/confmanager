<?php
// reset-password.php - Réinitialisation du mot de passe
session_start();
require_once 'config/database.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$valid_token = false;
$email = '';

if (empty($token)) {
    header('Location: forgot-password.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Verify token
$query = "SELECT email, expires_at FROM password_resets 
          WHERE token = :token AND used = 0 AND expires_at > NOW()";
$stmt = $db->prepare($query);
$stmt->execute([':token' => $token]);
$reset = $stmt->fetch(PDO::FETCH_ASSOC);

if ($reset) {
    $valid_token = true;
    $email = $reset['email'];
} else {
    $error = 'Lien de réinitialisation invalide ou expiré.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($password)) {
        $error = 'Veuillez saisir un mot de passe.';
    } elseif (strlen($password) < 6) {
        $error = 'Le mot de passe doit contenir au moins 6 caractères.';
    } elseif ($password !== $confirm_password) {
        $error = 'Les mots de passe ne correspondent pas.';
    } else {
        try {
            // Update user password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $updateQuery = "UPDATE users SET password = :password WHERE email = :email";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([':password' => $hashed_password, ':email' => $email]);
            
            // Mark token as used
            $updateTokenQuery = "UPDATE password_resets SET used = 1 WHERE token = :token";
            $updateTokenStmt = $db->prepare($updateTokenQuery);
            $updateTokenStmt->execute([':token' => $token]);
            
            $success = 'Mot de passe modifié avec succès ! Vous pouvez maintenant vous connecter.';
            
            // Redirect after 3 seconds
            header("refresh:3;url=login.php");
        } catch (PDOException $e) {
            $error = 'Erreur technique. Veuillez réessayer.';
            error_log("Password reset error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ConfManager - Nouveau mot de passe</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #003366;
            --primary-dark: #002244;
            --secondary: #D4AF37;
            --text-muted: #4A5568;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        
        .reset-container {
            max-width: 450px;
            width: 100%;
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .reset-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(12px);
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
        }
        
        .logo-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        
        .logo-icon {
            width: 45px;
            height: 45px;
            background: var(--primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
        }
        
        .logo-text {
            font-family: 'Playfair Display', serif;
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        h1 {
            font-size: 1.5rem;
            text-align: center;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
        }
        
        .subtitle {
            text-align: center;
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1.2rem;
        }
        
        .form-label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.4rem;
            color: var(--text-muted);
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary);
            opacity: 0.7;
        }
        
        .form-input {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.8rem;
            border: 2px solid #E2E8F0;
            border-radius: 1rem;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: white;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94A3B8;
            cursor: pointer;
        }
        
        .btn-submit {
            width: 100%;
            padding: 0.9rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 1rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .btn-submit:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 0.8rem 1rem;
            border-radius: 0.8rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-size: 0.9rem;
        }
        
        .alert-danger {
            background: #FEF2F2;
            border: 1px solid #FCA5A5;
            color: #991B1B;
        }
        
        .alert-success {
            background: #ECFDF5;
            border: 1px solid #6EE7B7;
            color: #065F46;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="reset-card">
            <div class="logo-wrapper">
                <div class="logo-icon"><i class="fas fa-book-open"></i></div>
                <span class="logo-text">ConfManager</span>
            </div>
            
            <h1>Nouveau mot de passe</h1>
            <p class="subtitle"><?php echo $valid_token ? 'Choisissez un nouveau mot de passe' : 'Lien invalide'; ?></p>
            
            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error; ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $success; ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($valid_token && !$success): ?>
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Nouveau mot de passe</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" class="form-input" id="password" name="password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('password', 'toggleIcon1')">
                            <i class="far fa-eye" id="toggleIcon1"></i>
                        </button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Confirmer le mot de passe</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" class="form-input" id="confirm_password" name="confirm_password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', 'toggleIcon2')">
                            <i class="far fa-eye" id="toggleIcon2"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit">
                    <span>Modifier le mot de passe</span>
                    <i class="fas fa-check"></i>
                </button>
            </form>
            <?php elseif (!$valid_token && !$success): ?>
            <a href="forgot-password.php" class="btn-submit" style="text-decoration: none; display: flex;">
                <span>Demander un nouveau lien</span>
                <i class="fas fa-arrow-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>