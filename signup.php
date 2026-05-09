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
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? 'chercheur';
    $acceptTerms = isset($_POST['accept_terms']);

    // Validation
    $errors = [];
    
    if (empty($firstName) || empty($lastName)) {
        $errors[] = "First and last name are required";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    if (strlen($password) < 4) {
        $errors[] = "Password must be at least 4 characters";
    }
    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match";
    }
    if (!$acceptTerms) {
        $errors[] = "You must accept the terms";
    }

    // Check email exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        $errors[] = "Email already registered";
    }

    // Reviewer code check
    if ($role === 'reviewer') {
        $reviewerCode = $_POST['reviewer_code'] ?? '';
        if ($reviewerCode !== 'REVIEWER123') {
            $errors[] = "Invalid reviewer code";
        }
    }

    if (empty($errors)) {
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Get role-specific fields
        $labo = !empty($_POST['labo']) ? $_POST['labo'] : null;
        $grade = !empty($_POST['grade']) ? $_POST['grade'] : null;
        $keywords = !empty($_POST['keywords']) ? $_POST['keywords'] : null;
        $institution = !empty($_POST['institution']) ? $_POST['institution'] : null;
        $department = !empty($_POST['department']) ? $_POST['department'] : null;
        $service = !empty($_POST['service']) ? $_POST['service'] : null;
        $reviewerCode = !empty($_POST['reviewer_code']) ? $_POST['reviewer_code'] : null;

        // Insert user
        $sql = "INSERT INTO users 
                (first_name, last_name, email, password, role, 
                 labo, grade, keywords, institution, department, service, reviewer_code) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        
        try {
            $stmt->execute([
                $firstName, $lastName, $email, $hashedPassword, $role,
                $labo, $grade, $keywords, $institution, $department, $service, $reviewerCode
            ]);
            
            // ===== AUTO LOGIN AFTER SUCCESSFUL REGISTRATION =====
            // Get the newly created user ID
            $userId = $pdo->lastInsertId();
            
            // Set session variables (auto-login)
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_name'] = $firstName . ' ' . $lastName;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_role'] = $role;
            
            // Redirect directly to role-based dashboard (NOT login page)
            redirect(getRoleRedirect($role));
            // =====================================================
            
        } catch (PDOException $e) {
            $message = "Registration failed. Please try again.";
            $messageType = "error";
        }
    } else {
        $message = implode('<br>', $errors);
        $messageType = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ConfManager - Inscription</title>
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

        .signup-container {
            width: 100%;
            max-width: 600px;
            position: relative;
            z-index: 10;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .signup-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            border-radius: 1.5rem;
            padding: 2.5rem 2rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(212, 175, 55, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .signup-header {
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

        .role-selector {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            background: var(--bg-navy);
            padding: 0.5rem;
            border-radius: 3rem;
        }

        .role-option {
            flex: 1;
            padding: 0.8rem 0.5rem;
            border: none;
            background: transparent;
            border-radius: 2rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.95rem;
        }

        .role-option.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 10px rgba(0, 51, 102, 0.2);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.4rem;
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

        .form-input, .form-select {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.8rem;
            border: 2px solid #E2E8F0;
            border-radius: 1rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
            font-family: 'Inter', sans-serif;
        }

        .form-input:focus, .form-select:focus {
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

        .role-specific {
            background: var(--bg-navy);
            padding: 1.2rem;
            border-radius: 1rem;
            margin: 1rem 0 1.5rem;
            border-left: 4px solid var(--secondary);
        }

        .role-specific-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1rem;
        }

        .role-specific-title i {
            color: var(--secondary);
        }

        .terms {
            margin: 1.5rem 0 1.2rem;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            color: var(--text-muted);
            font-size: 0.9rem;
            cursor: pointer;
        }

        .checkbox-label input[type="checkbox"] {
            width: 1.1rem; height: 1.1rem;
            accent-color: var(--primary);
        }

        .checkbox-label a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .btn-signup {
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

        .btn-signup:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .login-prompt {
            text-align: center;
            margin-top: 1.8rem;
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        .login-prompt a {
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

        .message {
            padding: 0.8rem 1rem;
            border-radius: 0.8rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-size: 0.9rem;
        }

        .message.success {
            background: #ECFDF5;
            border: 1px solid #6EE7B7;
            color: #065F46;
        }

        .message.error {
            background: #FEF2F2;
            border: 1px solid #FCA5A5;
            color: #991B1B;
        }

        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full-width { grid-column: span 1; }
            .role-selector { flex-direction: column; border-radius: 2rem; }
            .signup-card { padding: 1.8rem 1.2rem; }
        }
    </style>
</head>
<body>
    <div class="signup-container">
        <a href="interface.html" class="back-link">
            <i class="fas fa-arrow-left"></i> Retour
        </a>

        <div class="signup-card">
            <div class="signup-header">
                <div class="logo-wrapper">
                    <div class="logo-icon"><i class="fas fa-book-open"></i></div>
                    <span class="logo-text">ConfManager</span>
                </div>
                <span class="badge">Plateforme Algérienne</span>
                <h1 style="font-family: 'Playfair Display'; font-size: 1.8rem; color: var(--primary-dark);">Créer un compte</h1>
                <p style="color: var(--text-muted);">Choisissez votre profil pour commencer</p>
            </div>

            <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <span><?php echo $message; ?></span>
            </div>
            <?php endif; ?>

            <div class="role-selector">
                <button type="button" class="role-option active" data-role="chercheur" onclick="switchRole('chercheur', this)">
                    <i class="fas fa-user-graduate"></i> Chercheur
                </button>
                <button type="button" class="role-option" data-role="gestionnaire" onclick="switchRole('gestionnaire', this)">
                    <i class="fas fa-calendar-alt"></i> Gestionnaire
                </button>
                <button type="button" class="role-option" data-role="reviewer" onclick="switchRole('reviewer', this)">
                    <i class="fas fa-crown"></i> Reviewer
                </button>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="role" id="roleInput" value="chercheur">

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Prénom</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" name="first_name" class="form-input" placeholder="Votre prénom" required 
                                   value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nom</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" name="last_name" class="form-input" placeholder="Votre nom" required
                                   value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="form-group full-width">
                    <label class="form-label">Email institutionnel</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" name="email" class="form-input" placeholder="votre email" required
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Mot de passe</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="password" class="form-input" id="password" placeholder="••••••••" required>
                            <button type="button" class="password-toggle" onclick="togglePassword('password', 'toggleIcon1')">
                                <i class="far fa-eye" id="toggleIcon1"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirmer</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="confirm_password" class="form-input" id="confirmPassword" placeholder="••••••••" required>
                            <button type="button" class="password-toggle" onclick="togglePassword('confirmPassword', 'toggleIcon2')">
                                <i class="far fa-eye" id="toggleIcon2"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="role-specific" id="roleSpecificArea"></div>

                <div class="terms">
                    <label class="checkbox-label">
                        <input type="checkbox" name="accept_terms" required>
                        <span>J'accepte les <a href="condition.html">conditions générales</a> et la <a href="politique.html">politique de confidentialité</a></span>
                    </label>
                </div>

                <button type="submit" class="btn-signup">
                    <span>S'inscrire</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>

            <div class="login-prompt">
                Déjà un compte ? <a href="login.php">Se connecter</a>
            </div>

            <div style="text-align: center; margin-top: 1.5rem; font-size: 0.75rem; color: #94A3B8;">
                <i class="fas fa-map-marker-alt"></i> Université Hassiba Benbouali de Chlef
            </div>
        </div>
    </div>

    <script>
        function switchRole(role, btn) {
            document.querySelectorAll('.role-option').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('roleInput').value = role;

            const area = document.getElementById('roleSpecificArea');
            let html = '';

            if (role === 'chercheur') {
                html = `
                    <div class="role-specific-title"><i class="fas fa-flask"></i> Informations chercheur</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Laboratoire de recherche</label>
                            <div class="input-wrapper">
                                <i class="fas fa-microscope input-icon"></i>
                                <input type="text" name="labo" class="form-input" placeholder="Ex: LISIA" value="<?php echo htmlspecialchars($_POST['labo'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Grade</label>
                            <div class="input-wrapper">
                                <i class="fas fa-graduation-cap input-icon"></i>
                                <select name="grade" class="form-select">
                                    <option value="">Sélectionnez</option>
                                    <option value="Doctorant" <?php echo (($_POST['grade'] ?? '') === 'Doctorant') ? 'selected' : ''; ?>>Doctorant</option>
                                    <option value="Professeur" <?php echo (($_POST['grade'] ?? '') === 'Professeur') ? 'selected' : ''; ?>>Professeur</option>
                                    <option value="Chercheur post-doc" <?php echo (($_POST['grade'] ?? '') === 'Chercheur post-doc') ? 'selected' : ''; ?>>Chercheur post-doc</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group full-width">
                        <label class="form-label">Domaines de recherche (mots-clés)</label>
                        <div class="input-wrapper">
                            <i class="fas fa-tags input-icon"></i>
                            <input type="text" name="keywords" class="form-input" placeholder="IA, cybersécurité, énergie..." value="<?php echo htmlspecialchars($_POST['keywords'] ?? ''); ?>">
                        </div>
                    </div>
                `;
            } else if (role === 'gestionnaire') {
                html = `
                    <div class="role-specific-title"><i class="fas fa-calendar-check"></i> Informations gestionnaire</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Institution/Université</label>
                            <div class="input-wrapper">
                                <i class="fas fa-university input-icon"></i>
                                <input type="text" name="institution" class="form-input" placeholder="Université de Chlef" value="<?php echo htmlspecialchars($_POST['institution'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Département</label>
                            <div class="input-wrapper">
                                <i class="fas fa-building input-icon"></i>
                                <input type="text" name="department" class="form-input" placeholder="Informatique" value="<?php echo htmlspecialchars($_POST['department'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                `;
            } else if (role === 'reviewer') {
                html = `
                    <div class="role-specific-title"><i class="fas fa-shield-alt"></i> Informations reviewer</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Code d'accès reviewer</label>
                            <div class="input-wrapper">
                                <i class="fas fa-key input-icon"></i>
                                <input type="password" name="reviewer_code" class="form-input" placeholder="Code secret">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Service / Direction</label>
                            <div class="input-wrapper">
                                <i class="fas fa-sitemap input-icon"></i>
                                <input type="text" name="service" class="form-input" placeholder="Direction des études" value="<?php echo htmlspecialchars($_POST['service'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                `;
            }
            area.innerHTML = html;
        }

        document.addEventListener('DOMContentLoaded', () => {
            switchRole('chercheur', document.querySelector('.role-option.active'));
        });

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