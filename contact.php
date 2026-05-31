<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contact — ConfManager</title>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root {
    --navy: #0d2137;
    --navy-mid: #1a3a5c;
    --gold: #c9a84c;
    --gold-light: #e2c97e;
    --bg: #f0f2f5;
    --white: #ffffff;
    --border: #e2e8f0;
    --muted: #7a8fa6;
    --text: #1a2e44;
    --text-light: #4a607a;
    --accent: #2c6fad;
    --danger: #d94040;
    --success: #2a9d8f;
    --warning: #d4830a;
    --purple: #5b6ef5;
    --shadow-sm: 0 1px 4px rgba(13,33,55,0.07);
    --shadow: 0 4px 20px rgba(13,33,55,0.10);
    --shadow-md: 0 8px 32px rgba(13,33,55,0.15);
    --radius: 8px;
    --radius-sm: 4px;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
  }

  /* ─── PAGE ─── */
  .page { max-width: 1200px; margin: 0 auto; padding: 60px 32px; }
  
  .page-header {
    margin-bottom: 48px;
    text-align: center;
  }
  .page-header h1 {
    font-family: 'Libre Baskerville', serif;
    font-size: 42px;
    font-weight: 700;
    color: var(--navy);
    letter-spacing: -0.5px;
  }
  .page-header h1 em { 
    font-style: italic; 
    color: var(--gold); 
  }
  .page-header p {
    font-size: 16px;
    color: var(--muted);
    margin-top: 12px;
    max-width: 600px;
    margin-left: auto;
    margin-right: auto;
  }

  /* ─── CONTACT GRID ─── */
  .contact-grid {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 32px;
    margin-bottom: 48px;
  }

  /* ─── CONTACT INFO CARD ─── */
  .contact-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 28px;
    box-shadow: var(--shadow-sm);
  }
  .card-title {
    font-family: 'Libre Baskerville', serif;
    font-size: 22px;
    color: var(--navy);
    margin-bottom: 24px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--gold);
    display: inline-block;
  }
  .info-item {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    margin-bottom: 28px;
  }
  .info-icon {
    width: 44px;
    height: 44px;
    background: rgba(201,168,76,0.1);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gold);
    font-size: 18px;
    flex-shrink: 0;
  }
  .info-content h3 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-light);
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .info-content p, .info-content a {
    font-size: 14px;
    color: var(--text);
    text-decoration: none;
    transition: color 0.2s;
  }
  .info-content a:hover { color: var(--gold); }
  
  .map-container {
    margin-top: 20px;
    border-radius: var(--radius-sm);
    overflow: hidden;
    height: 180px;
  }
  .map-container iframe {
    width: 100%;
    height: 100%;
    border: 0;
  }

  /* ─── CONTACT FORM ─── */
  .form-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 32px;
    box-shadow: var(--shadow-sm);
  }
  .form-title {
    font-family: 'Libre Baskerville', serif;
    font-size: 22px;
    color: var(--navy);
    margin-bottom: 28px;
  }
  .form-group {
    margin-bottom: 22px;
  }
  .form-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--muted);
    margin-bottom: 8px;
  }
  .form-group input,
  .form-group textarea,
  .form-group select {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    color: var(--text);
    background: var(--white);
    transition: all 0.15s;
    outline: none;
  }
  .form-group input:focus,
  .form-group textarea:focus,
  .form-group select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(44,111,173,0.12);
  }
  .form-group textarea {
    min-height: 130px;
    resize: vertical;
  }
  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
  }
  .btn-submit {
    background: var(--navy);
    color: var(--gold-light);
    border: none;
    border-radius: var(--radius-sm);
    padding: 12px 28px;
    font-size: 14px;
    font-weight: 600;
    font-family: 'DM Sans', sans-serif;
    cursor: pointer;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 10px;
  }
  .btn-submit:hover {
    background: var(--navy-mid);
    transform: translateY(-1px);
    box-shadow: var(--shadow);
  }
  .required-note {
    font-size: 11px;
    color: var(--muted);
    margin-top: 16px;
  }
  .required-star { color: var(--danger); }

  /* ─── ALERTS ─── */
  .alert {
    padding: 14px 18px;
    border-radius: var(--radius-sm);
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
  }
  .alert-success {
    background: #e8f6f3;
    border: 1px solid #9dd8d0;
    color: #1a5f57;
  }
  .alert-error {
    background: #fdf2f2;
    border: 1px solid #f5b8b8;
    color: #8b2020;
  }
  .alert i { font-size: 18px; }

  /* ─── FAQ SECTION ─── */
  .faq-section {
    margin-top: 48px;
  }
  .section-title {
    font-family: 'Libre Baskerville', serif;
    font-size: 32px;
    color: var(--navy);
    text-align: center;
    margin-bottom: 36px;
  }
  .section-title em { 
    color: var(--gold); 
    font-style: italic; 
  }
  .faq-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 24px;
  }
  .faq-item {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 22px;
    transition: all 0.2s;
  }
  .faq-item:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
  }
  .faq-question {
    font-weight: 600;
    color: var(--navy);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .faq-question i { 
    color: var(--gold); 
    font-size: 18px; 
  }
  .faq-answer {
    font-size: 13px;
    color: var(--text-light);
    line-height: 1.5;
    margin-left: 28px;
  }

  /* ─── SOCIAL SECTION ─── */
  .social-section {
    margin-top: 48px;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 36px;
    text-align: center;
  }
  .social-title {
    font-family: 'Libre Baskerville', serif;
    font-size: 22px;
    color: var(--navy);
    margin-bottom: 28px;
  }
  .social-links {
    display: flex;
    justify-content: center;
    gap: 20px;
  }
  .social-link {
    width: 48px;
    height: 48px;
    background: var(--bg);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--navy);
    font-size: 18px;
    text-decoration: none;
    transition: all 0.2s;
  }
  .social-link:hover {
    background: var(--gold);
    color: var(--navy);
    transform: translateY(-3px);
  }

  /* ─── BACK BUTTON ─── */
  .back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--gold);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 32px;
    transition: color 0.2s;
  }
  .back-link:hover { 
    color: var(--gold-light); 
  }

  /* ─── FOOTER ─── */
  .footer {
    background: var(--navy);
    color: rgba(255,255,255,0.45);
    text-align: center;
    padding: 28px;
    margin-top: 60px;
    font-size: 13px;
  }

  /* ─── RESPONSIVE ─── */
  @media (max-width: 900px) {
    .page { padding: 32px 20px; }
    .contact-grid {
      grid-template-columns: 1fr;
    }
    .form-row {
      grid-template-columns: 1fr;
      gap: 16px;
    }
    .faq-grid {
      grid-template-columns: 1fr;
    }
    .page-header h1 { font-size: 32px; }
    .section-title { font-size: 26px; }
  }
</style>
</head>
<body>

<main class="page">
  <!-- BACK BUTTON -->
  <a href="index.html" class="back-link">
    <i class="fas fa-arrow-left"></i> Retour à l'accueil
  </a>

  <!-- PAGE HEADER -->
  <div class="page-header">
    <h1>Contactez<em>-nous</em></h1>
    <p>Nous sommes là pour répondre à toutes vos questions sur la plateforme ConfManager</p>
  </div>

  <!-- ALERT CONTAINER -->
  <div id="alertContainer"></div>

  <!-- CONTACT GRID -->
  <div class="contact-grid">
    <!-- CONTACT INFO CARD -->
    <div class="contact-card">
      <h2 class="card-title">Informations</h2>
      
      <div class="info-item">
        <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
        <div class="info-content">
          <h3>Adresse</h3>
          <p>Université Hassiba Benbouali de Chlef<br>Faculté des Sciences Exactes et Informatique<br>Chlef 02000, Algérie</p>
        </div>
      </div>

      <div class="info-item">
        <div class="info-icon"><i class="fas fa-phone"></i></div>
        <div class="info-content">
          <h3>Téléphone</h3>
          <p><a href="tel:+213562679539">+213 562 67 95 39</a></p>
          <p style="font-size: 11px; color: var(--muted);">Lun-Ven, 9h-17h</p>
        </div>
      </div>

      <div class="info-item">
        <div class="info-icon"><i class="fas fa-envelope"></i></div>
        <div class="info-content">
          <h3>Email</h3>
          <p><a href="mailto:benhouda1809@gmail.com">benhouda1809@gmail.com</a></p>
          <p><a href="mailto:inesmostafoui@gmail.com">inesmostafoui@gmail.com</a></p>
        </div>
      </div>

      <div class="map-container">
        <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d25834.715571592336!2d1.319658!3d36.163055!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x1285e3a5b5b5b5b5%3A0x5b5b5b5b5b5b5b5b!2sUniversit%C3%A9%20Hassiba%20Benbouali%20de%20Chlef!5e0!3m2!1sfr!2sdz!4v1620000000000!5m2!1sfr!2sdz" 
                allowfullscreen="" loading="lazy"></iframe>
      </div>
    </div>

    <!-- CONTACT FORM -->
    <div class="form-card">
      <h2 class="form-title">Envoyez-nous un message</h2>
      
      <form id="contactForm">
        <div class="form-row">
          <div class="form-group">
            <label>Nom complet <span class="required-star">*</span></label>
            <input type="text" id="nom" name="nom" placeholder="Prénom Nom">
          </div>
          <div class="form-group">
            <label>Email <span class="required-star">*</span></label>
            <input type="email" id="email" name="email" placeholder="prenom.nom@universite.dz">
          </div>
        </div>

        <div class="form-group">
          <label>Sujet <span class="required-star">*</span></label>
          <select id="sujet" name="sujet">
            <option value="">Choisissez un sujet</option>
            <option value="question">Question générale</option>
            <option value="support">Support technique</option>
            <option value="conference">Organisation de conférence</option>
            <option value="soumission">Soumission d'article</option>
            <option value="partenariat">Partenariat</option>
            <option value="autre">Autre</option>
          </select>
        </div>

        <div class="form-group">
          <label>Message <span class="required-star">*</span></label>
          <textarea id="message" name="message" placeholder="Votre message... (minimum 10 caractères)"></textarea>
        </div>

        <button type="submit" class="btn-submit">
          <i class="fas fa-paper-plane"></i> Envoyer le message
        </button>
        <p class="required-note"><span class="required-star">*</span> Champs obligatoires</p>
      </form>
    </div>
  </div>

  <!-- FAQ SECTION -->
  <div class="faq-section">
    <h2 class="section-title">Questions <em>fréquentes</em></h2>
    <div class="faq-grid">
      <div class="faq-item">
        <div class="faq-question"><i class="fas fa-question-circle"></i> Comment soumettre un article ?</div>
        <div class="faq-answer">Pour soumettre un article, vous devez d'abord créer un compte chercheur, puis vous rendre sur la page de la conférence concernée et cliquer sur "Soumettre un article".</div>
      </div>
      <div class="faq-item">
        <div class="faq-question"><i class="fas fa-question-circle"></i> Comment devenir évaluateur ?</div>
        <div class="faq-answer">Les évaluateurs sont sélectionnés par les organisateurs de conférences. Vous pouvez postuler via votre espace personnel ou contacter directement l'organisateur.</div>
      </div>
      <div class="faq-item">
        <div class="faq-question"><i class="fas fa-question-circle"></i> La plateforme est-elle gratuite ?</div>
        <div class="faq-answer">Oui, ConfManager est entièrement gratuite pour les chercheurs. Les organisateurs de conférences bénéficient également d'une version gratuite avec des fonctionnalités de base.</div>
      </div>
      <div class="faq-item">
        <div class="faq-question"><i class="fas fa-question-circle"></i> Comment fonctionne la détection de similarité ?</div>
        <div class="faq-answer">Notre système analyse automatiquement chaque article soumis et le compare avec notre base de données d'articles scientifiques pour détecter d'éventuelles similitudes.</div>
      </div>
    </div>
  </div>

  <!-- SOCIAL SECTION -->
  <div class="social-section">
    <h2 class="social-title">Suivez-nous</h2>
    <div class="social-links">
      <a href="#" class="social-link" target="_blank"><i class="fab fa-facebook-f"></i></a>
      <a href="#" class="social-link" target="_blank"><i class="fab fa-twitter"></i></a>
      <a href="#" class="social-link" target="_blank"><i class="fab fa-linkedin-in"></i></a>
      <a href="#" class="social-link" target="_blank"><i class="fab fa-youtube"></i></a>
      <a href="#" class="social-link" target="_blank"><i class="fab fa-github"></i></a>
    </div>
  </div>
</main>

<footer class="footer">
  © 2026 ConfManager · Université Hassiba Benbouali de Chlef · Tous droits réservés
</footer>

<script>
  // Gestion de l'envoi du formulaire
  document.getElementById('contactForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const nom = document.getElementById('nom').value.trim();
    const email = document.getElementById('email').value.trim();
    const sujet = document.getElementById('sujet').value;
    const message = document.getElementById('message').value.trim();
    
    // Validation
    if (!nom || !email || !sujet || !message) {
      showAlert('Veuillez remplir tous les champs obligatoires.', 'error');
      return;
    }
    
    if (message.length < 10) {
      showAlert('Le message doit contenir au moins 10 caractères.', 'error');
      return;
    }
    
    if (!email.includes('@') || !email.includes('.')) {
      showAlert('Veuillez entrer une adresse email valide.', 'error');
      return;
    }
    
    // Simulation d'envoi
    showAlert('Message envoyé avec succès ! Nous vous répondrons dans les plus brefs délais.', 'success');
    document.getElementById('contactForm').reset();
  });
  
  function showAlert(message, type) {
    const alertContainer = document.getElementById('alertContainer');
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type}`;
    alertDiv.innerHTML = `
      <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
      <div><strong>${type === 'success' ? 'Succès' : 'Erreur'}</strong><br>${message}</div>
    `;
    alertContainer.innerHTML = '';
    alertContainer.appendChild(alertDiv);
    
    setTimeout(() => {
      alertDiv.style.opacity = '0';
      setTimeout(() => alertDiv.remove(), 300);
    }, 5000);
  }
</script>
</body>
</html>