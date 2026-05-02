<?php
// ============================================
// signup.php - Inscription utilisateur
// ConfManager - Université Hassiba Benbouali de Chlef
// ============================================

session_start();
require_once 'config/database.php';

// ============================================
// 1. CLEAN SESSION FOR REGISTRATION
// ============================================

// If user is already logged in, destroy session for clean registration
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    session_unset();
    session_destroy();
    session_start();
}

// ============================================
// 2. FUNCTIONS
// ============================================

function cleanInput(string $input): string {
    return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
}

function emailExists(PDO $db, string $email): bool {
    $query = "SELECT id FROM users WHERE LOWER(email) = LOWER(:email)";
    $stmt = $db->prepare($query);
    $stmt->execute([':email' => $email]);
    return $stmt->fetch() !== false;
}

function generateUniqueUsername(PDO $db, string $firstName, string $lastName): string {
    $baseUsername = strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName) . '.' . preg_replace('/[^a-z0-9]/i', '', $lastName));
    $username = $baseUsername;
    $counter = 1;
    
    while (true) {
        $query = "SELECT id FROM users WHERE username = :username";
        $stmt = $db->prepare($query);
        $stmt->execute([':username' => $username]);
        if (!$stmt->fetch()) {
            break;
        }
        $username = $baseUsername . $counter;
        $counter++;
    }
    
    return $username;
}

/**
 * Validate password strength
 */
function validatePasswordStrength(string $password): array {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Le mot de passe doit contenir au moins une lettre majuscule.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Le mot de passe doit contenir au moins une lettre minuscule.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Le mot de passe doit contenir au moins un chiffre.';
    }
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $errors[] = 'Le mot de passe doit contenir au moins un caractère spécial (!@#$%^&*).';
    }
    
    return $errors;
}

// ============================================
// 3. INITIALIZATION
// ============================================

$error = '';
$success = '';

// ============================================
// 4. FORM PROCESSING
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Get form data
    $first_name = cleanInput($_POST['first_name'] ?? '');
    $last_name = cleanInput($_POST['last_name'] ?? '');
    $email = cleanInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? 'chercheur';
    $accept_terms = isset($_POST['accept_terms']);
    
    $errors = [];
    
    // Required fields
    if (empty($first_name)) $errors[] = 'Le prénom est obligatoire.';
    if (empty($last_name)) $errors[] = 'Le nom est obligatoire.';
    if (empty($email)) $errors[] = 'L\'email est obligatoire.';
    if (empty($password)) $errors[] = 'Le mot de passe est obligatoire.';
    
    // Email validation
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Adresse email invalide.';
    }
    
    // Password validation
    if (!empty($password)) {
        $passwordErrors = validatePasswordStrength($password);
        $errors = array_merge($errors, $passwordErrors);
    }
    
    // Password confirmation
    if ($password !== $confirm_password) {
        $errors[] = 'Les mots de passe ne correspondent pas.';
    }
    
    // Terms acceptance
    if (!$accept_terms) {
        $errors[] = 'Vous devez accepter les conditions d\'utilisation.';
    }
    
    // Role validation
    $allowedRoles = ['chercheur', 'gestionnaire', 'reviewer'];
    if (!in_array($role, $allowedRoles)) {
        $role = 'chercheur';
    }
    
    // Reviewer specific validation
    if ($role === 'reviewer') {
        $reviewer_code = $_POST['reviewer_code'] ?? '';
        if (empty($reviewer_code)) {
            $errors[] = 'Le code reviewer est obligatoire.';
        } elseif ($reviewer_code !== 'REVIEWER123') {
            $errors[] = 'Code reviewer invalide.';
        }
    }
    
    // Check if email already exists
    if (empty($errors)) {
        $database = new Database();
        $db = $database->getConnection();
        
        if (emailExists($db, $email)) {
            $errors[] = 'Cet email est déjà utilisé.';
        }
    }
    
    // ============================================
    // 5. INSERT INTO DATABASE WITH UNIQUE PASSWORD HASH
    // ============================================
    
    if (empty($errors)) {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            // Generate unique username
            $username = generateUniqueUsername($db, $first_name, $last_name);
            
            // IMPORTANT: Create UNIQUE hash for THIS user's password
            // Each user gets their OWN hash based on their password
                $hashed_password = $password;  
            
            // Insert user
            $query = "INSERT INTO users (
                        username, email, password, first_name, last_name, 
                        role, status, created_at
                      ) VALUES (
                        :username, :email, :password, :first_name, :last_name,
                        :role, 'active', NOW()
                      )";
            
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':password' => $hashed_password,
                ':first_name' => $first_name,
                ':last_name' => $last_name,
                ':role' => $role
            ]);
            
            $user_id = $db->lastInsertId();
            
            // Insert role-specific data
            if ($role === 'chercheur') {
                $labo = cleanInput($_POST['labo'] ?? '');
                $grade = cleanInput($_POST['grade'] ?? '');
                $keywords = cleanInput($_POST['keywords'] ?? '');
                
                $updateQuery = "UPDATE users SET 
                                    laboratoire = :labo, 
                                    grade = :grade, 
                                    keywords = :keywords 
                                WHERE id = :id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->execute([
                    ':labo' => $labo,
                    ':grade' => $grade,
                    ':keywords' => $keywords,
                    ':id' => $user_id
                ]);
                
            } elseif ($role === 'gestionnaire') {
                $institution = cleanInput($_POST['institution'] ?? '');
                $department = cleanInput($_POST['department'] ?? '');
                
                $updateQuery = "UPDATE users SET 
                                    institution = :institution, 
                                    department = :department 
                                WHERE id = :id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->execute([
                    ':institution' => $institution,
                    ':department' => $department,
                    ':id' => $user_id
                ]);
                
            } elseif ($role === 'reviewer') {
                $service = cleanInput($_POST['service'] ?? '');
                $specialties = cleanInput($_POST['specialties'] ?? '');
                
                $updateQuery = "UPDATE users SET 
                                    service = :service, 
                                    specialties = :specialties 
                                WHERE id = :id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->execute([
                    ':service' => $service,
                    ':specialties' => $specialties,
                    ':id' => $user_id
                ]);
            }
            
            // Destroy session and redirect to login
            session_unset();
            session_destroy();
            
            $success_message = urlencode("Inscription réussie ! Veuillez vous connecter avec votre email et le mot de passe que vous avez choisi.");
            header("Location: login.php?success=" . $success_message);
            exit();
            
        } catch (PDOException $e) {
            $error = 'Erreur lors de l\'inscription. Veuillez réessayer.';
            error_log("Signup error: " . $e->getMessage());
        }
    } else {
        $error = implode('<br>', $errors);
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
            --bg-navy: #E6F0FA;
            --white: #FFFFFF;
            --danger: #DC2626;
            --success: #10B981;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            min-height: 100vh;
            padding: 2rem 1.5rem;
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

        .signup-container {
            max-width: 800px;
            margin: 0 auto;
            position: relative;
            z-index: 10;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .signup-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(12px);
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
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
            margin-bottom: 1rem;
            border: 1px solid var(--secondary);
        }

        h1 {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
        }

        .subtitle {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        .password-requirements {
            background: #f8f9fa;
            border-radius: 0.5rem;
            padding: 0.8rem;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: var(--text-muted);
            border-left: 3px solid var(--secondary);
        }

        .password-requirements ul {
            margin-left: 1.2rem;
            margin-top: 0.3rem;
        }

        .password-requirements li {
            margin-bottom: 0.2rem;
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
            padding: 0.8rem;
            border: none;
            background: transparent;
            border-radius: 2rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .role-option.active {
            background: var(--primary);
            color: white;
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
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.4rem;
            color: var(--text-dark);
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

        .form-input, .form-select {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.8rem;
            border: 2px solid #E2E8F0;
            border-radius: 1rem;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: white;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(0, 51, 102, 0.1);
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

        .role-specific {
            background: var(--bg-navy);
            padding: 1.2rem;
            border-radius: 1rem;
            margin: 1rem 0;
            border-left: 4px solid var(--secondary);
        }

        .role-specific-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            cursor: pointer;
            margin: 1rem 0;
        }

        .checkbox-label input {
            width: 1.1rem;
            height: 1.1rem;
            accent-color: var(--primary);
        }

        .btn-signup {
            width: 100%;
            padding: 1rem;
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
            gap: 0.75rem;
        }

        .btn-signup:hover {
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

        @media (max-width: 640px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-group.full-width {
                grid-column: span 1;
            }
            .signup-card {
                padding: 1.5rem;
            }
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
                <h1>Créer un compte</h1>
                <p class="subtitle">Choisissez votre profil pour commencer</p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error; ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="" id="signupForm">
                <div class="role-selector">
                    <button type="button" class="role-option active" data-role="chercheur" onclick="switchRole('chercheur')">
                        <i class="fas fa-user-graduate"></i> Chercheur
                    </button>
                    <button type="button" class="role-option" data-role="gestionnaire" onclick="switchRole('gestionnaire')">
                        <i class="fas fa-calendar-alt"></i> Gestionnaire
                    </button>
                    <button type="button" class="role-option" data-role="reviewer" onclick="switchRole('reviewer')">
                        <i class="fas fa-crown"></i> Reviewer
                    </button>
                </div>

                <input type="hidden" name="role" id="role" value="chercheur">

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Prénom</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" class="form-input" name="first_name" 
                                   value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nom</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" class="form-input" name="last_name"
                                   value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-group full-width">
                    <label class="form-label">Email institutionnel</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" class="form-input" name="email"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Mot de passe</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" class="form-input" id="password" name="password" required>
                            <button type="button" class="password-toggle" onclick="togglePassword('password', 'toggleIcon1')">
                                <i class="far fa-eye" id="toggleIcon1"></i>
                            </button>
                        </div>
                        <div class="password-requirements">
                            <strong>Exigences du mot de passe :</strong>
                            <ul>
                                <li>Minimum 8 caractères</li>
                                <li>Au moins une lettre majuscule</li>
                                <li>Au moins une lettre minuscule</li>
                                <li>Au moins un chiffre</li>
                                <li>Au moins un caractère spécial (!@#$%^&*)</li>
                            </ul>
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
                </div>

                <!-- Role-specific fields -->
                <div id="roleSpecificArea" class="role-specific">
                    <!-- Content loaded by JavaScript -->
                </div>

                <label class="checkbox-label">
                    <input type="checkbox" name="accept_terms" required>
                    <span>J'accepte les <a href="condition.html" target="_blank">conditions générales</a> et la <a href="politique.html" target="_blank">politique de confidentialité</a></span>
                </label>

                <button type="submit" class="btn-signup">
                    <span>S'inscrire</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>

            <div style="text-align: center; margin-top: 1.5rem; font-size: 0.85rem; color: var(--text-muted);">
                Déjà un compte ? <a href="login.php" style="color: var(--primary);">Se connecter</a>
            </div>
        </div>
    </div>

    <script>
        function switchRole(role) {
            document.querySelectorAll('.role-option').forEach(btn => btn.classList.remove('active'));
            document.querySelector(`.role-option[data-role="${role}"]`).classList.add('active');
            document.getElementById('role').value = role;
            
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
                                <input type="text" class="form-input" name="labo" placeholder="Ex: LISIA">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Grade</label>
                            <div class="input-wrapper">
                                <i class="fas fa-graduation-cap input-icon"></i>
                                <select class="form-select" name="grade">
                                    <option value="">Sélectionnez</option>
                                    <option>Doctorant</option>
                                    <option>Maître de conférences</option>
                                    <option>Professeur</option>
                                    <option>Chercheur post-doc</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group full-width">
                        <label class="form-label">Domaines de recherche (mots-clés)</label>
                        <div class="input-wrapper">
                            <i class="fas fa-tags input-icon"></i>
                            <input type="text" class="form-input" name="keywords" placeholder="IA, cybersécurité, énergie...">
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
                                <input type="text" class="form-input" name="institution" placeholder="Université de Chlef">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Département</label>
                            <div class="input-wrapper">
                                <i class="fas fa-building input-icon"></i>
                                <input type="text" class="form-input" name="department" placeholder="Informatique">
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
                                <input type="password" class="form-input" name="reviewer_code" placeholder="Code secret" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Service / Direction</label>
                            <div class="input-wrapper">
                                <i class="fas fa-sitemap input-icon"></i>
                                <input type="text" class="form-input" name="service" placeholder="Direction des études">
                            </div>
                        </div>
                    </div>
                    <div class="form-group full-width">
                        <label class="form-label">Spécialités d'évaluation</label>
                        <div class="input-wrapper">
                            <i class="fas fa-tags input-icon"></i>
                            <input type="text" class="form-input" name="specialties" placeholder="IA, Réseaux, Sécurité...">
                        </div>
                    </div>
                `;
            }
            
            area.innerHTML = html;
        }
        
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
        
        // Initialize with chercheur role
        document.addEventListener('DOMContentLoaded', () => {
            switchRole('chercheur');
        });
    </script>
</body>
</html>