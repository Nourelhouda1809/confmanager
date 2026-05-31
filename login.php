<?php

require_once 'config.php';

$message = '';
$messageType = '';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect(getRoleRedirect($_SESSION['user_role']));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $message = "Email and password are required";
        $messageType = "error";
    } else {
        // Find user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Login successful - set session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            
            // Redirect based on role
            redirect(getRoleRedirect($user['role']));
        } else {
            $message = "Email or password incorrect";
            $messageType = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ConfManager - Connexion</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary: #003366;
            --primary-light: #005599;
            --primary-dark: #002244;
            --secondary: #D4AF37;
            --secondary-light: #F9F5E8;
            --secondary-dark: #B49129;
            --text-dark: #1A1A1A;
            --text-muted: #4A5568;
            --bg-light: #F5F5F5;
            --bg-navy: #E6F0FA;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            top: -20%; left: -10%;
            width: 600px; height: 600px;
            background: rgba(212, 175, 55, 0.15);
            border-radius: 50%;
            filter: blur(100px);
            z-index: 0;
        }

        body::after {
            content: '';
            position: absolute;
            bottom: -20%; right: -10%;
            width: 600px; height: 600px;
            background: rgba(212, 175, 55, 0.15);
            border-radius: 50%;
            filter: blur(100px);
            z-index: 0;
        }

        .login-container {
            width: 100%;
            max-width: 480px;
            position: relative;
            z-index: 10;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            border-radius: 1.5rem;
            padding: 2.5rem 2rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(212, 175, 55, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .logo-icon {
            width: 50px; height: 50px;
            background: var(--primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 4px 12px rgba(0, 51, 102, 0.3);
        }

        .logo-text {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -0.5px;
        }

        .badge {
            display: inline-block;
            padding: 0.3rem 1rem;
            background: var(--secondary-light);
            color: var(--primary-dark);
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 2rem;
            margin-bottom: 1.2rem;
            border: 1px solid var(--secondary);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            color: var(--primary);
            font-size: 1rem;
            opacity: 0.7;
        }

        .form-input {
            width: 100%;
            padding: 0.9rem 1rem 0.9rem 2.8rem;
            border: 2px solid #E2E8F0;
            border-radius: 1rem;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(0, 51, 102, 0.1);
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            background: none;
            border: none;
            color: #94A3B8;
            cursor: pointer;
            font-size: 1.1rem;
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.8rem;
            font-size: 0.9rem;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            cursor: pointer;
        }

        .forgot-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .forgot-link:hover {
            color: var(--secondary-dark);
            text-decoration: underline;
        }

        .btn-login {
            width: 100%;
            padding: 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 1rem;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            box-shadow: 0 4px 12px rgba(0, 51, 102, 0.2);
        }

        .btn-login:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .signup-prompt {
            text-align: center;
            margin-top: 2rem;
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        .signup-prompt a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .back-link:hover { color: var(--secondary); }

        .error-message {
            background: #FEF2F2;
            border: 1px solid #FCA5A5;
            color: #991B1B;
            padding: 0.8rem 1rem;
            border-radius: 0.8rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-size: 0.9rem;
        }

        .success-message {
            background: #ECFDF5;
            border: 1px solid #6EE7B7;
            color: #065F46;
            padding: 0.8rem 1rem;
            border-radius: 0.8rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-size: 0.9rem;
        }

        @media (max-width: 480px) {
            .login-card { padding: 1.8rem 1.2rem; }
            .logo-text { font-size: 1.8rem; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <a href="index.html" class="back-link">
            <i class="fas fa-arrow-left"></i> Retour à l'accueil
        </a>

        <div class="login-card">
            <div class="login-header">
                <div class="logo-wrapper">
                    <div class="logo-icon"><i class="fas fa-book-open"></i></div>
                    <span class="logo-text">ConfManager</span>
                </div>
                <span class="badge">Plateforme Algérienne</span>
                <h1 style="font-family: 'Playfair Display'; font-size: 1.8rem; color: var(--primary-dark);">Bienvenue</h1>
                <p style="color: var(--text-muted);">Connectez-vous à votre espace</p>
            </div>

            <?php if ($message): ?>
            <div class="<?php echo $messageType === 'success' ? 'success-message' : 'error-message'; ?>">
                <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <span><?php echo $message; ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Adresse email</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" name="email" class="form-input" placeholder="votre email" required
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Mot de passe</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="password" class="form-input" id="password" placeholder="••••••••" required>
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <i class="far fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="form-options">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember" checked> Se souvenir de moi
                    </label>
                    <a href="forgot-password.html" class="forgot-link">Mot de passe oublié ?</a>
                </div>

                <button type="submit" class="btn-login">
                    <span>Se connecter</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>

            <div class="signup-prompt">
                Pas encore de compte ? <a href="signup.php">Créer un compte</a>
            </div>

            <div style="text-align: center; margin-top: 1.5rem; font-size: 0.75rem; color: #94A3B8;">
                <i class="fas fa-map-marker-alt"></i> Université Hassiba Benbouali de Chlef
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>