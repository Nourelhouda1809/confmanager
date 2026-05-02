<?php
// forgot-password.php - Mot de passe oublié
session_start();
require_once 'config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Veuillez saisir votre adresse email.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email invalide.';
    } else {
        $database = new Database();
        $db = $database->getConnection();
        
        try {
            // Check if user exists
            $query = "SELECT id, email FROM users WHERE email = :email";
            $stmt = $db->prepare($query);
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Generate reset token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Store token in database
                $insertQuery = "INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, :expires)";
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->execute([':email' => $email, ':token' => $token, ':expires' => $expires]);
                
                // In production, send email here
                // For demo, just show the reset link
                $reset_link = "reset-password.php?token=" . $token;
                $success = "Un lien de réinitialisation a été envoyé à votre adresse email.<br>
                            <small>(Demo: <a href='$reset_link'>$reset_link</a>)</small>";
            } else {
                // Don't reveal if email doesn't exist for security
                $success = "Si un compte existe avec cet email, vous recevrez un lien de réinitialisation.";
            }
        } catch (PDOException $e) {
            $error = 'Erreur technique. Veuillez réessayer plus tard.';
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
    <title>ConfManager - Mot de passe oublié</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #003366;
            --primary-dark: #002244;
            --secondary: #D4AF37;
            --text-muted: #4A5568;
            --white: #FFFFFF;
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
        
        .forgot-container {
            max-width: 450px;
            width: 100%;
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .forgot-card {
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
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            margin-bottom: 1rem;
            transition: color 0.2s;
        }
        
        .back-link:hover {
            color: var(--secondary);
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
    <div class="forgot-container">
        <a href="login.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        
        <div class="forgot-card">
            <div class="logo-wrapper">
                <div class="logo-icon"><i class="fas fa-book-open"></i></div>
                <span class="logo-text">ConfManager</span>
            </div>
            
            <h1>Mot de passe oublié ?</h1>
            <p class="subtitle">Entrez votre email pour recevoir un lien de réinitialisation</p>
            
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
            
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Adresse email</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" class="form-input" name="email" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit">
                    <span>Envoyer le lien</span>
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        </div>
    </div>
</body>
</html>