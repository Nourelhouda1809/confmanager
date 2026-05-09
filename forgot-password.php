<?php
// index.php - Page de demande de réinitialisation de mot de passe
session_start();
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mot de passe oublié | Design épuré</title>
  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    /* Votre CSS existant ici - inchangé */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    
    @import url('https://fonts.googleapis.com/css2?family=Inter:opsz@14..32&display=swap');

    body {
      background: #374f75;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
    }

    /* design principal : carte horizontale avec image */
    .reset-card {
      display: flex;
      max-width: 1000px;
      width: 100%;
      background: rgb(51, 39, 105);
      border-radius: 2.5rem;
      box-shadow: 0 30px 60px -20px rgba(0, 40, 80, 0.25);
      overflow: hidden;
      transition: all 0.3s ease;
    }

    /* panneau gauche : illustration / branding */
    .brand-panel {
      flex: 1.1;
      background: linear-gradient(145deg, #1a2b4c, #223b66);
      padding: 3rem 2.5rem;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      color: rgb(243, 243, 248);
      position: relative;
      isolation: isolate;
    }

    .brand-panel::after {
      content: '';
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at 20% 30%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
      pointer-events: none;
      z-index: 0;
    }

    .brand-content {
      position: relative;
      z-index: 2;
    }

    .logo {
      display: flex;
      align-items: center;
      gap: 10px;
      font-weight: 600;
      font-size: 1.4rem;
      margin-bottom: 3rem;
    }

    .logo i {
      font-size: 2rem;
      background: rgba(255,255,255,0.15);
      padding: 0.6rem;
      border-radius: 18px;
      backdrop-filter: blur(4px);
    }

    .brand-text h2 {
      font-size: 2rem;
      font-weight: 500;
      line-height: 1.3;
      margin-bottom: 1.5rem;
    }

    .brand-text h2 span {
      font-weight: 700;
      color: #ffd966;
      border-bottom: 3px solid #ffb347;
      padding-bottom: 4px;
    }

    .feature-list {
      margin-top: 2.5rem;
      list-style: none;
    }

    .feature-list li {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 1.2rem;
      font-weight: 400;
      opacity: 0.9;
    }

    .feature-list li i {
      width: 24px;
      color: #ffd966;
      font-size: 1.2rem;
    }

    .testimonial {
      margin-top: 2.5rem;
      padding-top: 1.8rem;
      border-top: 1px solid rgba(255,255,255,0.15);
      font-style: italic;
      font-weight: 300;
      font-size: 0.95rem;
      opacity: 0.8;
    }

    .testimonial i {
      color: #ffd966;
      margin-right: 6px;
    }

    /* panneau droit : formulaire */
    .form-panel {
      flex: 1;
      padding: 3rem 2.8rem;
      background: #ffffff;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .form-header {
      margin-bottom: 2.2rem;
    }

    .form-header .badge {
      background: #eef3fc;
      color: #1e3c72;
      font-size: 0.8rem;
      font-weight: 600;
      padding: 0.3rem 1rem;
      border-radius: 40px;
      display: inline-block;
      margin-bottom: 1rem;
      letter-spacing: 0.3px;
    }

    .form-header h3 {
      font-size: 2rem;
      font-weight: 600;
      color: #0e223b;
      margin-bottom: 0.5rem;
    }

    .form-header p {
      color: #5b6f88;
      font-size: 0.95rem;
    }

    /* groupe formulaire */
    .input-field {
      margin-bottom: 1.8rem;
    }

    .input-field label {
      display: block;
      font-weight: 500;
      font-size: 0.9rem;
      color: #1f3a5f;
      margin-bottom: 0.4rem;
    }

    .input-field label i {
      margin-right: 6px;
      color: #4870a2;
      width: 18px;
    }

    .input-wrapper {
      display: flex;
      align-items: center;
      background: #f1f5f9;
      border-radius: 60px;
      padding: 0.1rem 0.1rem 0.1rem 1.5rem;
      border: 2px solid transparent;
      transition: 0.2s;
    }

    .input-wrapper:focus-within {
      background: #ffffff;
      border-color: #1e3c72;
      box-shadow: 0 8px 18px -10px #1e3c7240;
    }

    .input-wrapper i {
      color: #62748c;
      font-size: 1rem;
    }

    .input-wrapper input {
      width: 100%;
      padding: 1rem 1rem 1rem 0.8rem;
      border: none;
      background: transparent;
      font-size: 1rem;
      outline: none;
      color: #0b2a44;
      font-weight: 500;
    }

    .input-wrapper input::placeholder {
      color: #95a7c0;
      font-weight: 400;
    }

    /* message dynamique */
    .status-message {
      min-height: 3rem;
      margin: 1rem 0 1.5rem 0;
      padding: 0.8rem 1.2rem;
      border-radius: 50px;
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 0.9rem;
      background: #f8fafd;
      border: 1px solid #e2eaf2;
      transition: 0.2s;
    }

    .status-message i {
      font-size: 1.1rem;
      width: 24px;
      text-align: center;
    }

    .status-message.success {
      background: #e2f3e8;
      border-color: #2e9b66;
      color: #11643c;
    }

    .status-message.error {
      background: #ffe9e9;
      border-color: #cc4b4b;
      color: #a52a2a;
    }

    .status-message.hidden {
      display: none;
    }

    /* bouton principal */
    .btn-primary {
      background: #1d3b5c;
      border: none;
      color: white;
      width: 100%;
      padding: 1rem;
      font-size: 1.1rem;
      font-weight: 600;
      border-radius: 60px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      cursor: pointer;
      box-shadow: 0 8px 18px -5px #0f2b48;
      transition: all 0.2s ease;
      margin: 0.8rem 0 2rem 0;
      border: 1px solid rgba(255,255,255,0.2);
    }

    .btn-primary:hover {
      background: #244e79;
      transform: translateY(-2px);
      box-shadow: 0 15px 25px -8px #123052;
    }

    .btn-primary:active {
      transform: translateY(1px);
      box-shadow: 0 5px 15px -3px #102b45;
    }

    .btn-primary i {
      font-size: 1.1rem;
    }

    /* lien retour */
    .back-link {
      text-align: center;
    }

    .back-link a {
      color: #4d6587;
      text-decoration: none;
      font-weight: 500;
      font-size: 0.95rem;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-bottom: 1px dashed transparent;
      transition: 0.2s;
    }

    .back-link a:hover {
      color: #1d3b5c;
      gap: 12px;
      border-bottom-color: #1d3b5c;
    }

    /* animation */
    @keyframes slideIn {
      0% { opacity: 0; transform: translateX(20px); }
      100% { opacity: 1; transform: translateX(0); }
    }

    .form-panel {
      animation: slideIn 0.4s ease-out;
    }

    /* responsive */
    @media (max-width: 750px) {
      .reset-card {
        flex-direction: column;
        border-radius: 2rem;
      }
      .brand-panel {
        padding: 2.5rem 2rem;
      }
      .form-panel {
        padding: 2.5rem 2rem;
      }
    }

    .rating {
      display: flex;
      gap: 8px;
      margin-top: 0.8rem;
      align-items: center;
    }
    .rating span {
      background: rgba(255,255,255,0.2);
      padding: 0.2rem 0.8rem;
      border-radius: 40px;
      font-size: 0.75rem;
    }

    /* Ajout pour le CSRF token */
    .csrf-token {
      display: none;
    }
  </style>
</head>
<body>
  <div class="reset-card">
    <!-- partie gauche : branding / présentation -->
    <div class="brand-panel">
      <div class="brand-content">
        <div class="logo">
          <i class="fas fa-shield-halved"></i>
          <span>SecureAccess</span>
        </div>
        <div class="brand-text">
          <h2>Mot de passe <span>oublié ?</span></h2>
          <p style="opacity: 0.8; line-height: 1.6;">Pas de panique. Réinitialisez-le en toute sécurité en quelques instants.</p>
        </div>
        <ul class="feature-list">
          <li><i class="fas fa-check-circle"></i> Lien de réinitialisation immédiat</li>
          <li><i class="fas fa-lock"></i> Chiffrement de bout en bout</li>
          <li><i class="fas fa-clock"></i> Valable 15 minutes</li>
        </ul>
        <div class="testimonial">
          <i class="fas fa-quote-right"></i> "J'ai récupéré mon compte en moins d'une minute. Interface claire et rassurante."
          <div class="rating"></div>
        </div>
      </div>
    </div>

    <!-- partie droite : formulaire -->
    <div class="form-panel">
      <div class="form-header">
        <div class="badge"><i class="far fa-envelope" style="margin-right: 5px;"></i> RÉINITIALISATION</div>
        <h3>Recevoir le lien</h3>
        <p>Nous enverrons un email à l'adresse associée à votre compte.</p>
      </div>

      <form id="resetForm" novalidate>
        <!-- CSRF Token pour la sécurité -->
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>" class="csrf-token">
        
        <!-- champ email avec icône intégrée -->
        <div class="input-field">
          <label for="email"><i class="far fa-envelope"></i> Adresse e-mail</label>
          <div class="input-wrapper">
            <i class="fas fa-envelope" style="margin-left: 0; margin-right: 8px;"></i>
            <input type="email" id="email" name="email" placeholder="votre email" required autofocus>
          </div>
        </div>

        <!-- message de statut -->
        <div id="liveMessage" class="status-message hidden" aria-live="polite">
          <i id="msgIcon" class="fas fa-info-circle"></i>
          <span id="msgText"></span>
        </div>

        <button type="submit" class="btn-primary" id="sendBtn">
          <i class="fas fa-paper-plane" id="btnIcon"></i> Envoyer le lien
        </button>

        <div class="back-link">
          <a href="login.php">
            <i class="fas fa-arrow-left"></i> Retour à la connexion
          </a>
        </div>
      </form>

      <!-- petite note discrète -->
      <p style="font-size: 0.7rem; color: #a0b2c9; margin-top: 2rem; text-align: center; border-top: 1px dashed #d9e2ef; padding-top: 1rem;">
        🔒 Nos serveurs ne stockent jamais votre mot de passe en clair.
      </p>
    </div>
  </div>

  <script>
(function() {
    const form = document.getElementById('resetForm');
    const emailInput = document.getElementById('email');
    const msgDiv = document.getElementById('liveMessage');
    const msgIcon = document.getElementById('msgIcon');
    const msgText = document.getElementById('msgText');
    const sendBtn = document.getElementById('sendBtn');
    const btnIcon = document.getElementById('btnIcon');

    function showMessage(type, text) {
        msgDiv.classList.remove('hidden', 'success', 'error');
        msgDiv.classList.add(type);
        msgIcon.className = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
        msgText.textContent = text;
    }

    function hideMessage() {
        msgDiv.classList.add('hidden');
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        hideMessage();

        const email = emailInput.value.trim();

        if (email === '') {
            showMessage('error', 'Veuillez saisir votre adresse e-mail.');
            emailInput.focus();
            return;
        }
        
        if (!isValidEmail(email)) {
            showMessage('error', "Format d'e-mail invalide. Vérifiez votre saisie.");
            emailInput.focus();
            return;
        }

        // Disable button during request
        sendBtn.disabled = true;
        sendBtn.style.opacity = '0.7';
        const originalIcon = btnIcon.className;
        btnIcon.className = 'fas fa-spinner fa-pulse';

        try {
            const formData = new FormData(form);

            const response = await fetch('reset_password.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                showMessage('success', data.message);
                emailInput.value = ''; // Clear email for security
            } else {
                showMessage('error', data.message);
            }
        } catch (error) {
            showMessage('error', 'Erreur réseau. Veuillez réessayer.');
            console.error('Error:', error);
        } finally {
            // Restore button
            sendBtn.disabled = false;
            sendBtn.style.opacity = '1';
            btnIcon.className = originalIcon;
        }
    });

    // Hide message when user starts typing
    emailInput.addEventListener('input', () => {
        if (!msgDiv.classList.contains('hidden') && msgDiv.classList.contains('error')) {
            hideMessage();
        }
    });
})();
  </script>
</body>
</html>