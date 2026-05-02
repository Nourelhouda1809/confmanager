<?php
// ============================================
// login.php - Connexion utilisateur
// ConfManager - Université Hassiba Benbouali de Chlef
// ============================================

// IMPORTANT: session_start() MUST be at the very top before ANY output
session_start();

require_once 'config/database.php';

// ============================================
// 1. CHECK IF ALREADY LOGGED IN
// ============================================

$alreadyLoggedIn = false;
$loggedInUser = '';
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    $alreadyLoggedIn = true;
    $loggedInUser = ($_SESSION['user_prenom'] ?? '') . ' ' . ($_SESSION['user_nom'] ?? '');
}

// ============================================
// 2. FUNCTIONS
// ============================================

function redirectToDashboard(string $role): void {
    switch ($role) {
        case 'gestionnaire':
            header('Location: admin_dashboard.php');
            break;
        case 'reviewer':
            header('Location: reviewer_dashboard.php');
            break;
        case 'chercheur':
        default:
            header('Location: submit_article.php');
            break;
    }
    exit();
}

function cleanInput(string $input): string {
    return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
}

// ============================================
// 3. INITIALIZATION
// ============================================

$error = '';
$success = '';

if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}

// ============================================
// 4. LOGIN FORM PROCESSING
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $email = cleanInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    // Validation
    if (empty($email)) {
        $error = 'Veuillez saisir votre adresse email.';
    } elseif (empty($password)) {
        $error = 'Veuillez saisir votre mot de passe.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format d\'email invalide.';
    } else {
        
        $database = new Database();
        $db = $database->getConnection();
        
        try {
            // Get user by email
            $query = "SELECT id, username, email, password, first_name, last_name, role, status 
                      FROM users 
                      WHERE email = :email";
            
            $stmt = $db->prepare($query);
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Check password - plain text comparison ONLY
                if ($password === $user['password']) {
                    
                    if ($user['status'] !== 'active') {
                        $error = 'Votre compte n\'est pas actif. Veuillez contacter l\'administrateur.';
                    } else {
                        // Store all session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_nom'] = $user['last_name'];
                        $_SESSION['user_prenom'] = $user['first_name'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['logged_in'] = true;
                        $_SESSION['login_time'] = time();
                        
                        // Update last login
                        $updateQuery = "UPDATE users SET last_login = NOW() WHERE id = :id";
                        $updateStmt = $db->prepare($updateQuery);
                        $updateStmt->execute([':id' => $user['id']]);
                        
                        // Remember me (optional)
                        if ($remember) {
                            $token = bin2hex(random_bytes(32));
                            $tokenQuery = "UPDATE users SET remember_token = :token WHERE id = :id";
                            $tokenStmt = $db->prepare($tokenQuery);
                            $tokenStmt->execute([
                                ':token' => $token,
                                ':id' => $user['id']
                            ]);
                            setcookie('remember_token', $token, time() + (86400 * 30), '/', '', false, true);
                        }
                        
                        // Redirect based on role
                        redirectToDashboard($user['role']);
                    }
                } else {
                    $error = 'Email ou mot de passe incorrect.';
                }
            } else {
                $error = 'Email ou mot de passe incorrect.';
            }
            
        } catch (PDOException $e) {
            $error = 'Erreur de connexion. Veuillez réessayer plus tard.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}

// ============================================
// 5. REMEMBER ME COOKIE CHECK
// ============================================

if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    
    $database = new Database();
    $db = $database->getConnection();
    $token = $_COOKIE['remember_token'];
    
    try {
        $query = "SELECT id, username, email, first_name, last_name, role, status, remember_token 
                  FROM users 
                  WHERE remember_token = :token";
        $stmt = $db->prepare($query);
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && $user['status'] === 'active') {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_nom'] = $user['last_name'];
            $_SESSION['user_prenom'] = $user['first_name'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['logged_in'] = true;
            
            redirectToDashboard($user['role']);
        }
    } catch (PDOException $e) {
        error_log("Remember token error: " . $e->getMessage());
    }
}

// ============================================
// 6. DISPLAY LOGIN PAGE
// ============================================
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #003366;
            --primary-dark: #002244;
            --secondary: #D4AF37;
            --secondary-light: #F9F5E8;
            --text-dark: #1A1A1A;
            --text-muted: #4A5568;
            --white: #FFFFFF;
            --danger: #DC2626;
            --success: #10B981;
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
        }

        body::before, body::after {
            content: '';
            position: absolute;
            width: 600px;
            height: 600px;
            background: rgba(212, 175, 55, 0.15);
            border-radius: 50%;
            filter: blur(100px);
            z-index: 0;
        }
        body::before { top: -20%; left: -10%; }
        body::after { bottom: -20%; right: -10%; }

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
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(12px);
            border-radius: 1.5rem;
            padding: 2.5rem 2rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
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
            width: 50px;
            height: 50px;
            background: var(--primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .logo-text {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
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

        .alert-info {
            background: #e0e7ff;
            border: 1px solid #bcc7fa;
            color: #3347bb;
            margin-bottom: 1.5rem;
            padding: 0.8rem 1rem;
            border-radius: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .form-group { margin-bottom: 1.5rem; }
        .form-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }
        .input-wrapper { position: relative; display: flex; align-items: center; }
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
        .checkbox-label input { width: 1.1rem; height: 1.1rem; accent-color: var(--primary); }
        .forgot-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
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
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            transition: all 0.3s ease;
        }
        .btn-login:hover { 
            background: var(--primary-dark); 
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        .signup-prompt {
            text-align: center;
            margin-top: 2rem;
            color: var(--text-muted);
        }
        .signup-prompt a { color: var(--primary); font-weight: 600; text-decoration: none; }
        .signup-prompt a:hover { text-decoration: underline; }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-size: 0.9rem;
            margin-bottom: 1rem;
            transition: color 0.3s ease;
        }
        .back-link:hover { color: white; }
        .alert {
            padding: 0.8rem 1rem;
            border-radius: 0.8rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        .alert-danger { background: #FEF2F2; border: 1px solid #FCA5A5; color: #991B1B; }
        .alert-success { background: #ECFDF5; border: 1px solid #6EE7B7; color: #065F46; }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            50% { transform: translateX(5px); }
            75% { transform: translateX(-3px); }
        }
        .shake { animation: shake 0.3s ease; }
        @media (max-width: 480px) {
            .login-card { padding: 1.8rem 1.2rem; }
            .logo-text { font-size: 1.8rem; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <a href="interface.html" class="back-link">
            <i class="fas fa-arrow-left"></i> Retour à l'accueil
        </a>

        <div class="login-card">
            <div class="login-header">
                <div class="logo-wrapper">
                    <div class="logo-icon"><i class="fas fa-book-open"></i></div>
                    <span class="logo-text">ConfManager</span>
                </div>
                <span class="badge">Plateforme Algérienne</span>
                <h1>Bienvenue</h1>
                <p>Connectez-vous à votre espace</p>
            </div>

            <?php if ($alreadyLoggedIn): ?>
            <div class="alert-info">
                <i class="fas fa-info-circle"></i>
                <span>Vous êtes déjà connecté en tant que <strong><?= htmlspecialchars($loggedInUser) ?></strong>.</span>
                <a href="logout.php" style="margin-left: auto; color: #3347bb;">Se déconnecter →</a>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label class="form-label">Adresse email</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" class="form-input" id="email" name="email" 
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" 
                               placeholder="votre.email@universite.dz" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Mot de passe</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" class="form-input" id="password" name="password" 
                               placeholder="••••••••" required>
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <i class="far fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="form-options">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember">
                        Se souvenir de moi
                    </label>
                    <a href="forgot-password.php" class="forgot-link">Mot de passe oublié ?</a>
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

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value.trim();
            if (email === '' || password === '') {
                e.preventDefault();
                this.classList.add('shake');
                setTimeout(() => this.classList.remove('shake'), 300);
            }
        });
    </script>
</body>
</html>