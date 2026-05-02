<!-- === SECTION RECHERCHE === -->
<section class="search-section" id="search">
    <div class="container">
        <div class="section-header">
            <!-- TABS EN HAUT (PROCHAINES / PASSÉES) -->
            <div class="tabs-container">
                <button class="tab-btn active" data-tab="upcoming"><i class="fas fa-calendar"></i> Prochaines Conférences</button>
                <button class="tab-btn" data-tab="past"><i class="fas fa-clock"></i> Conférences Passées</button>
            </div>
            <h2 class="section-title">Rechercher des Conférences</h2>
            <p class="section-desc">Trouvez facilement les conférences qui vous intéressent par titre, lieu ou mots-clés.</p>
        </div>
        
        <!-- COMPTEUR DE RÉSULTATS -->
        <div class="results-count" id="resultCount">
            <span>0</span> conférences trouvées
        </div>
        
        <div class="search-box">
            <i class="fas fa-search search-icon"></i>
            <input type="text" class="search-input" id="searchInput" placeholder="Rechercher par titre, lieu, mots-clés...">
            <button class="search-clear" id="searchClear" type="button"><i class="fas fa-times"></i></button>
        </div>
        
        <!-- FILTRES (Informatique, Médecine, etc.) -->
        <div class="filters-container">
            <div class="filter-chip active" data-filter="all">Tous</div>
            <?php
            // Récupérer les catégories depuis la base de données
            require_once 'config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            
            try {
                // Vérifier si la table conferences existe et a des données
                $checkQuery = "SHOW TABLES LIKE 'conferences'";
                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->execute();
                
                if ($checkStmt->rowCount() > 0) {
                    $catQuery = "SELECT DISTINCT categorie FROM conferences WHERE categorie IS NOT NULL AND categorie != '' ORDER BY categorie";
                    $catStmt = $db->prepare($catQuery);
                    $catStmt->execute();
                    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($categories)) {
                        foreach($categories as $cat) {
                            $categorie = htmlspecialchars($cat['categorie']);
                            echo "<div class='filter-chip' data-filter='".strtolower($categorie)."'>".$categorie."</div>";
                        }
                    } else {
                        // Catégories par défaut si aucune en base
                        $defaultCategories = ['Informatique', 'Médecine', 'Physique', 'Chimie', 'Biologie'];
                        foreach($defaultCategories as $cat) {
                            echo "<div class='filter-chip' data-filter='".strtolower($cat)."'>".$cat."</div>";
                        }
                    }
                } else {
                    // Table n'existe pas, utiliser catégories par défaut
                    $defaultCategories = ['Informatique', 'Médecine', 'Physique', 'Chimie', 'Biologie'];
                    foreach($defaultCategories as $cat) {
                        echo "<div class='filter-chip' data-filter='".strtolower($cat)."'>".$cat."</div>";
                    }
                }
            } catch (PDOException $e) {
                // En cas d'erreur, utiliser catégories par défaut
                $defaultCategories = ['Informatique', 'Médecine', 'Physique', 'Chimie', 'Biologie'];
                foreach($defaultCategories as $cat) {
                    echo "<div class='filter-chip' data-filter='".strtolower($cat)."'>".$cat."</div>";
                }
            }
            ?>
        </div>
        
        <!-- GRILLE DES CONFÉRENCES -->
        <div class="conferences-grid" id="conferencesGrid">
            <!-- Les conférences seront chargées dynamiquement via JavaScript -->
        </div>
        
        <!-- BOUTON CHARGER PLUS -->
        <button class="load-more-btn" id="loadMoreBtn" style="display: none;">
            Charger plus de conférences <i class="fas fa-chevron-down"></i>
        </button>
        
        <!-- LOADING SPINNER -->
        <div class="loading-spinner" id="loadingSpinner" style="display: none;">
            <div class="spinner"></div>
        </div>
        
        <!-- AUCUN RÉSULTAT -->
        <div class="no-results" id="noResults" style="display: none;">
            <i class="fas fa-search"></i>
            <h3>Aucune conférence trouvée</h3>
            <p>Essayez avec d'autres termes de recherche</p>
        </div>
    </div>
</section>

<style>
    /* === STYLES POUR LA SECTION RECHERCHE === */
    .search-section {
        padding: 6rem 0;
        background: linear-gradient(135deg, #f5f7fa 0%, #e9edf5 100%);
        position: relative;
    }

    .search-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-image: 
            radial-gradient(circle at 20% 30%, rgba(0, 51, 102, 0.03) 0%, transparent 8%),
            radial-gradient(circle at 80% 70%, rgba(212, 175, 55, 0.03) 0%, transparent 10%),
            repeating-linear-gradient(45deg, rgba(0, 51, 102, 0.02) 0px, rgba(0, 51, 102, 0.02) 1px, transparent 1px, transparent 12px);
        pointer-events: none;
    }

    .container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 2rem;
        position: relative;
        z-index: 1;
    }

    .section-header {
        text-align: center;
        margin-bottom: 2rem;
        position: relative;
        z-index: 1;
    }

    .section-title {
        font-size: 2.5rem;
        font-weight: 700;
        color: #003366;
        margin-bottom: 1rem;
        font-family: 'Playfair Display', serif;
    }

    .section-desc {
        color: #4A5568;
        max-width: 600px;
        margin: 0 auto;
        font-size: 1.1rem;
    }

    /* TABS EN HAUT */
    .tabs-container {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin-bottom: 2rem;
        flex-wrap: wrap;
    }

    .tab-btn {
        padding: 0.875rem 2rem;
        border-radius: 3rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        background: rgba(255,255,255,0.8);
        backdrop-filter: blur(5px);
        color: #4A5568;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 2px solid transparent;
    }

    .tab-btn.active {
        background: #003366;
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0,51,102,0.3);
        border-color: #003366;
    }

    .tab-btn:hover:not(.active) {
        background: white;
        color: #003366;
        transform: translateY(-2px);
        border-color: #003366;
    }

    /* COMPTEUR DE RÉSULTATS */
    .results-count {
        text-align: center;
        color: #4A5568;
        margin-bottom: 1.5rem;
        font-size: 1rem;
        padding: 0.5rem 1.5rem;
        background: rgba(255,255,255,0.5);
        border-radius: 2rem;
        max-width: 300px;
        margin-left: auto;
        margin-right: auto;
        backdrop-filter: blur(5px);
        border: 1px solid rgba(0,51,102,0.1);
    }
    
    .results-count span {
        font-weight: 700;
        color: #003366;
        font-size: 1.3rem;
        margin-right: 0.3rem;
    }

    .search-box {
        max-width: 600px;
        margin: 0 auto 2rem;
        position: relative;
        z-index: 1;
    }

    .search-input {
        width: 100%;
        padding: 1rem 1rem 1rem 3rem;
        border: 2px solid #E2E8F0;
        border-radius: 1rem;
        font-size: 1rem;
        transition: all 0.3s ease;
        background: rgba(255,255,255,0.9);
        backdrop-filter: blur(5px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }

    .search-input:focus {
        outline: none;
        border-color: #003366;
        box-shadow: 0 0 0 4px rgba(0,51,102,0.1);
        background: white;
    }

    .search-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #94A3B8;
        font-size: 1.1rem;
    }

    .search-clear {
        position: absolute;
        right: 1rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #94A3B8;
        cursor: pointer;
        display: none;
        padding: 0.5rem;
        border-radius: 50%;
        transition: all 0.3s ease;
        font-size: 1rem;
    }

    .search-clear:hover {
        background: #E2E8F0;
        color: #003366;
    }

    .search-clear.visible {
        display: block;
    }

    /* FILTRES */
    .filters-container {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        justify-content: center;
        margin-bottom: 2rem;
        position: relative;
        z-index: 1;
    }
    
    .filter-chip {
        padding: 0.6rem 1.5rem;
        background: white;
        border-radius: 2rem;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        font-size: 0.95rem;
        border: 1px solid transparent;
    }
    
    .filter-chip:hover {
        background: #e6f0fa;
        transform: translateY(-2px);
    }
    
    .filter-chip.active {
        background: #003366;
        color: white;
        border-color: #003366;
    }

    .conferences-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.5rem;
        position: relative;
        z-index: 1;
        min-height: 400px;
    }

    .conference-card {
        background: white;
        border-radius: 1rem;
        overflow: hidden;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        transition: all 0.4s ease;
        position: relative;
        border: 1px solid rgba(0,51,102,0.05);
        animation: fadeIn 0.5s ease-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .conference-card:hover {
        box-shadow: 0 12px 40px rgba(0,51,102,0.2);
        transform: translateY(-8px);
    }

    .card-image {
        position: relative;
        height: 200px;
        overflow: hidden;
    }

    .card-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.6s ease;
    }

    .conference-card:hover .card-image img {
        transform: scale(1.1);
    }

    .card-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(to top, rgba(0,0,0,0.6), transparent);
    }

    .card-badge {
        position: absolute;
        top: 1rem;
        left: 1rem;
        padding: 0.375rem 0.75rem;
        border-radius: 2rem;
        font-size: 0.75rem;
        font-weight: 600;
        z-index: 2;
    }

    .card-badge.open { background: #22C55E; color: white; }
    .card-badge.soon { background: #D4AF37; color: #002244; }
    .card-badge.call { background: #3B82F6; color: white; }
    .card-badge.planning { background: #6B7280; color: white; }
    .card-badge.past { background: #64748B; color: white; }

    .card-date {
        position: absolute;
        bottom: 1rem;
        left: 1rem;
        color: white;
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        z-index: 2;
    }

    .card-content {
        padding: 1.5rem;
        background: white;
    }

    .card-title {
        font-size: 1.125rem;
        font-weight: 600;
        color: #003366;
        margin-bottom: 0.75rem;
        line-height: 1.4;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        line-clamp: 2;
        box-orient: vertical;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .card-desc {
        font-size: 0.875rem;
        color: #4A5568;
        margin-bottom: 0.75rem;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .card-location {
        font-size: 0.875rem;
        color: #4A5568;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .card-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: 1rem;
        border-top: 1px solid #E2E8F0;
    }

    .card-articles {
        font-size: 0.875rem;
        color: #4A5568;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .card-link {
        font-size: 0.875rem;
        color: #003366;
        text-decoration: none;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.25rem;
        transition: all 0.3s ease;
        cursor: pointer;
        background: none;
        border: none;
    }

    .card-link:hover {
        gap: 0.75rem;
        color: #D4AF37;
    }

    /* BOUTON CHARGER PLUS */
    .load-more-btn {
        display: block;
        margin: 2.5rem auto 0;
        padding: 0.875rem 2.5rem;
        background: white;
        border: 2px solid #003366;
        color: #003366;
        border-radius: 2rem;
        cursor: pointer;
        transition: all 0.3s;
        font-size: 1rem;
        font-weight: 500;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .load-more-btn:hover {
        background: #003366;
        color: white;
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0,51,102,0.3);
    }
    
    .load-more-btn i {
        margin-left: 0.5rem;
        transition: transform 0.3s;
    }
    
    .load-more-btn:hover i {
        transform: translateY(3px);
    }

    /* LOADING SPINNER */
    .loading-spinner {
        text-align: center;
        padding: 2rem;
    }
    
    .spinner {
        width: 50px;
        height: 50px;
        border: 5px solid #E2E8F0;
        border-top-color: #003366;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    .no-results {
        text-align: center;
        padding: 4rem 0;
        color: #4A5568;
        position: relative;
        z-index: 1;
    }

    .no-results i {
        font-size: 4rem;
        margin-bottom: 1rem;
        color: #CBD5E1;
    }

    /* RESPONSIVE */
    @media (max-width: 1200px) {
        .conferences-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 992px) {
        .conferences-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .section-title {
            font-size: 2rem;
        }
        .conferences-grid {
            grid-template-columns: 1fr;
        }
        .tabs-container {
            flex-direction: column;
            align-items: center;
        }
        .tab-btn {
            width: 100%;
            max-width: 300px;
            justify-content: center;
        }
    }
</style>

<script>
// Variables globales
let currentTab = 'upcoming';
let currentFilter = 'all';
let searchQuery = '';
let visibleItems = 4;
let allConferences = [];

// Données de démonstration (au cas où l'API ne fonctionne pas)
const demoConferences = [
    {
        id: 1,
        titre: "Conférence Internationale sur l'Intelligence Artificielle 2024",
        description: "Explorez les dernières avancées en IA et apprentissage automatique avec des experts mondiaux.",
        lieu: "Paris, France",
        date_debut: "2024-10-15",
        date_fin: "2024-10-17",
        image_url: "https://images.unsplash.com/photo-1591453089816-0fbb971b454c?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80",
        statut: "open",
        articles_count: 24,
        categorie: "Informatique"
    },
    {
        id: 2,
        titre: "Symposium sur les Maladies Infectieuses",
        description: "Actualités et innovations dans le traitement des maladies infectieuses émergentes.",
        lieu: "Lyon, France",
        date_debut: "2024-11-05",
        date_fin: "2024-11-07",
        image_url: "https://images.unsplash.com/photo-1579684385127-1ef15d508118?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80",
        statut: "soon",
        articles_count: 18,
        categorie: "Médecine"
    },
    {
        id: 3,
        titre: "Congrès de Cardiologie Francophone",
        description: "Les dernières recommandations et innovations en cardiologie.",
        lieu: "Marseille, France",
        date_debut: "2024-12-01",
        date_fin: "2024-12-03",
        image_url: "https://images.unsplash.com/photo-1631815588090-d4bfec5b1ccb?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80",
        statut: "call",
        articles_count: 32,
        categorie: "Médecine"
    },
    {
        id: 4,
        titre: "Journées Francophones de Recherche Biomédicale",
        description: "Partage des dernières découvertes en recherche biomédicale.",
        lieu: "Toulouse, France",
        date_debut: "2025-01-15",
        date_fin: "2025-01-17",
        image_url: "https://images.unsplash.com/photo-1532094349884-543bc11b234d?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80",
        statut: "planning",
        articles_count: 0,
        categorie: "Biologie"
    },
    {
        id: 5,
        titre: "Conférence Annuelle de Médecine Interne 2023",
        description: "Revue des avancées majeures en médecine interne.",
        lieu: "Bordeaux, France",
        date_debut: "2023-03-12",
        date_fin: "2023-03-14",
        image_url: "https://images.unsplash.com/photo-1581056771107-24ca5f033842?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80",
        statut: "past",
        articles_count: 45,
        categorie: "Médecine"
    },
    {
        id: 6,
        titre: "Conférence sur la Physique Quantique",
        description: "Avancées en physique quantique et applications.",
        lieu: "Grenoble, France",
        date_debut: "2023-09-18",
        date_fin: "2023-09-20",
        image_url: "https://images.unsplash.com/photo-1581595220892-b1f941f7c3e2?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80",
        statut: "past",
        articles_count: 27,
        categorie: "Physique"
    }
];

// Fonction pour charger les conférences depuis l'API
async function loadConferences() {
    const spinner = document.getElementById('loadingSpinner');
    const grid = document.getElementById('conferencesGrid');
    const noResults = document.getElementById('noResults');
    
    if (spinner) spinner.style.display = 'block';
    if (grid) grid.style.opacity = '0.5';
    if (noResults) noResults.style.display = 'none';
    
    try {
        // Construire l'URL avec tous les paramètres
        const url = `api/get_conferences.php?tab=${currentTab}&filter=${encodeURIComponent(currentFilter)}&search=${encodeURIComponent(searchQuery)}`;
        console.log('Chargement depuis:', url); // Pour déboguer
        
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success) {
            allConferences = data.data;
            console.log('Conférences chargées:', allConferences); // Pour déboguer
        } else {
            console.error('Erreur API:', data.message);
            // Utiliser les données de démonstration et filtrer localement
            allConferences = filterDemoData();
        }
    } catch (error) {
        console.error('Erreur de chargement, utilisation des données démo:', error);
        // Utiliser les données de démonstration et filtrer localement
        allConferences = filterDemoData();
    } finally {
        if (spinner) spinner.style.display = 'none';
        if (grid) grid.style.opacity = '1';
        renderConferences(true);
    }
}

// Fonction pour filtrer les données de démonstration
function filterDemoData() {
    let filtered = [...demoConferences];
    
    // Filtrer par onglet (prochaines/passées)
    if (currentTab === 'upcoming') {
        filtered = filtered.filter(c => c.statut !== 'past');
    } else if (currentTab === 'past') {
        filtered = filtered.filter(c => c.statut === 'past');
    }
    
    // Filtrer par catégorie
    if (currentFilter !== 'all') {
        filtered = filtered.filter(c => 
            c.categorie && c.categorie.toLowerCase() === currentFilter.toLowerCase()
        );
    }
    
    // Filtrer par recherche
    if (searchQuery.trim() !== '') {
        const query = searchQuery.toLowerCase().trim();
        filtered = filtered.filter(c => 
            (c.titre && c.titre.toLowerCase().includes(query)) ||
            (c.description && c.description.toLowerCase().includes(query)) ||
            (c.lieu && c.lieu.toLowerCase().includes(query))
        );
    }
    
    return filtered;
}

// Fonction pour render les conférences
function renderConferences(resetVisibility = true) {
    const grid = document.getElementById('conferencesGrid');
    const noResults = document.getElementById('noResults');
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    const resultCountSpan = document.querySelector('#resultCount span');
    
    if (!grid) return;
    
    // Mettre à jour le compteur
    if (resultCountSpan) {
        resultCountSpan.textContent = allConferences.length;
    }
    
    // Réinitialiser la visibilité si demandé
    if (resetVisibility) {
        visibleItems = 4;
    }
    
    // Prendre seulement les éléments visibles
    const visibleData = allConferences.slice(0, visibleItems);
    
    // Afficher ou masquer le bouton "Charger plus"
    if (loadMoreBtn) {
        loadMoreBtn.style.display = visibleItems < allConferences.length ? 'block' : 'none';
    }
    
    // Afficher ou masquer le message "aucun résultat"
    if (allConferences.length === 0) {
        grid.innerHTML = '';
        if (noResults) noResults.style.display = 'block';
        if (loadMoreBtn) loadMoreBtn.style.display = 'none';
    } else {
        if (noResults) noResults.style.display = 'none';
        
        // Générer le HTML des cartes
        grid.innerHTML = visibleData.map(conf => {
            // Déterminer la classe du badge
            let badgeClass = 'planning';
            let badgeText = 'En préparation';
            
            if (conf.statut === 'open') {
                badgeClass = 'open';
                badgeText = 'Inscriptions ouvertes';
            } else if (conf.statut === 'soon') {
                badgeClass = 'soon';
                badgeText = 'Bientôt';
            } else if (conf.statut === 'call') {
                badgeClass = 'call';
                badgeText = 'Appel à communications';
            } else if (conf.statut === 'past') {
                badgeClass = 'past';
                badgeText = 'Terminée';
            } else if (conf.statut === 'planning') {
                badgeClass = 'planning';
                badgeText = 'En préparation';
            }
            
            // Formater la date
            const dateStr = formatDate(conf.date_debut, conf.date_fin);
            
            return `
                <div class="conference-card">
                    <div class="card-image">
                        <img src="${conf.image_url || 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=800&q=80'}" alt="${conf.titre}" loading="lazy">
                        <div class="card-overlay"></div>
                        <span class="card-badge ${badgeClass}">${badgeText}</span>
                        <div class="card-date">
                            <i class="far fa-calendar-alt"></i> ${dateStr}
                        </div>
                    </div>
                    <div class="card-content">
                        <h3 class="card-title">${conf.titre || 'Titre non disponible'}</h3>
                        <p class="card-desc">${conf.description || 'Aucune description'}</p>
                        <div class="card-location">
                            <i class="fas fa-map-marker-alt"></i> ${conf.lieu || 'Lieu non spécifié'}
                        </div>
                        <div class="card-footer">
                            <span class="card-articles">
                                <i class="far fa-file-alt"></i> ${conf.articles_count || 0} articles
                            </span>
                            <button class="card-link" onclick="viewConference(${conf.id})">
                                Voir plus <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }
}

// Fonction pour formater la date
function formatDate(date_debut, date_fin) {
    if (!date_debut) return 'Date non spécifiée';
    
    try {
        const options = { day: 'numeric', month: 'long', year: 'numeric' };
        const debut = new Date(date_debut);
        
        if (isNaN(debut.getTime())) return 'Date non spécifiée';
        
        if (date_fin) {
            const fin = new Date(date_fin);
            if (!isNaN(fin.getTime())) {
                if (debut.getMonth() === fin.getMonth() && debut.getFullYear() === fin.getFullYear()) {
                    return `${debut.getDate()}-${fin.getDate()} ${debut.toLocaleDateString('fr-FR', { month: 'long' })} ${debut.getFullYear()}`;
                } else {
                    return `${debut.toLocaleDateString('fr-FR', options)} - ${fin.toLocaleDateString('fr-FR', options)}`;
                }
            }
        }
        return debut.toLocaleDateString('fr-FR', options);
    } catch (e) {
        return 'Date non spécifiée';
    }
}

// Fonction pour voir les détails d'une conférence
function viewConference(id) {
    window.location.href = `conference_details.php?id=${id}`;
}

// Fonction pour changer d'onglet
function switchTab(tab) {
    currentTab = tab;
    
    document.querySelectorAll('.tab-btn').forEach(btn => {
        if (btn.dataset.tab === tab) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    
    loadConferences();
}

// Fonction pour changer de filtre
function switchFilter(filter) {
    currentFilter = filter;
    
    document.querySelectorAll('.filter-chip').forEach(chip => {
        if (chip.dataset.filter === filter) {
            chip.classList.add('active');
        } else {
            chip.classList.remove('active');
        }
    });
    
    loadConferences();
}

// Fonction pour effacer la recherche
function clearSearch() {
    const input = document.getElementById('searchInput');
    const clearBtn = document.getElementById('searchClear');
    
    if (input) {
        input.value = '';
        searchQuery = '';
        if (clearBtn) clearBtn.classList.remove('visible');
        loadConferences();
    }
}

// Fonction pour charger plus
function loadMore() {
    visibleItems += 4;
    renderConferences(false);
}

// Initialisation
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM chargé, initialisation...');
    
    // Charger les conférences
    loadConferences();
    
    // Search input
    const input = document.getElementById('searchInput');
    const clearBtn = document.getElementById('searchClear');
    
    if (input) {
        let timeout = null;
        input.addEventListener('input', function() {
            searchQuery = this.value;
            if (clearBtn) {
                clearBtn.classList.toggle('visible', searchQuery.length > 0);
            }
            
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                loadConferences();
            }, 500);
        });
    }
    
    if (clearBtn) {
        clearBtn.addEventListener('click', clearSearch);
    }
    
    // Tabs
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            switchTab(this.dataset.tab);
        });
    });
    
    // Filters - CORRECTION IMPORTANTE ICI
    document.querySelectorAll('.filter-chip').forEach(chip => {
        chip.addEventListener('click', function() {
            const filter = this.dataset.filter;
            console.log('Filtre cliqué:', filter); // Pour déboguer
            switchFilter(filter);
        });
    });
    
    // Load More
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', loadMore);
    }
});
</script>