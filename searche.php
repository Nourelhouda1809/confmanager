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
        
        <!-- FILTRES (disciplines depuis la BDD) -->
        <div class="filters-container">
            <div class="filter-chip active" data-filter="all">Tous</div>
            <?php
            // FIX: use $pdo from config.php (not non-existent config/database.php)
            // FIX: correct column is 'disciplines' (not 'categorie')
            if (!isset($pdo)) require_once __DIR__ . '/config.php';
            try {
                $catStmt = $pdo->query("SELECT DISTINCT disciplines FROM conferences WHERE disciplines IS NOT NULL AND disciplines != '' ORDER BY disciplines");
                $allDisciplines = $catStmt->fetchAll(PDO::FETCH_COLUMN);
                // disciplines can be comma-separated e.g. "AI, NLP, CS"
                $unique = [];
                foreach ($allDisciplines as $d) {
                    foreach (array_map('trim', explode(',', $d)) as $item) {
                        if ($item !== '') $unique[$item] = true;
                    }
                }
                ksort($unique);
                foreach (array_keys($unique) as $disc) {
                    $safe = htmlspecialchars($disc, ENT_QUOTES, 'UTF-8');
                    echo "<div class='filter-chip' data-filter='" . strtolower($safe) . "'>" . $safe . "</div>\n";
                }
            } catch (PDOException $e) {
                // silent fail — only "Tous" chip shown
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

// Données de démonstration (fallback si BDD inaccessible)
const demoConferences = [
    {
        id: 1,
        titre: "Conférence Internationale sur l'Intelligence Artificielle",
        description: "Explorez les dernières avancées en IA et apprentissage automatique avec des experts mondiaux.",
        lieu: "Oran, Algérie",
        date_debut: "2026-09-15",
        date_fin: "2026-09-17",
        image_url: "https://images.unsplash.com/photo-1591453089816-0fbb971b454c?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80",
        statut: "open",
        articles_count: 24,
        categorie: "Informatique"
    },
    {
        id: 2,
        titre: "Séminaire National sur la Cybersécurité",
        description: "Actualités et innovations dans le domaine de la cybersécurité et des réseaux.",
        lieu: "Chlef, Algérie",
        date_debut: "2026-11-05",
        date_fin: "2026-11-08",
        image_url: "https://images.unsplash.com/photo-1550751827-4bd374c3f58b?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80",
        statut: "soon",
        articles_count: 18,
        categorie: "Cybersecurity"
    }
];

// FIX: Fetch depuis conferences.php (action=get_conferences) — api/get_conferences.php n'existe pas
async function loadConferences() {
    const spinner = document.getElementById('loadingSpinner');
    const grid = document.getElementById('conferencesGrid');
    const noResults = document.getElementById('noResults');
    
    if (spinner) spinner.style.display = 'block';
    if (grid) grid.style.opacity = '0.5';
    if (noResults) noResults.style.display = 'none';
    
    try {
        const formData = new FormData();
        formData.append('action', 'get_conferences');
        
        const response = await fetch('conferences.php', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success && data.data) {
            const today = new Date().toISOString().split('T')[0];

            // FIX: map real DB columns to the display names used in renderConferences
            // DB columns: name_fr, name_en, location, start_date, end_date,
            //             submission_start_date, submission_deadline, disciplines, articles_count
            let confs = data.data.map(c => {
                // Compute statut from dates
                let statut = 'planning';
                if (c.end_date < today) {
                    statut = 'past';
                } else if (c.submission_start_date <= today && c.submission_deadline >= today) {
                    statut = 'open';
                } else if (c.submission_deadline < today) {
                    statut = 'call';
                } else {
                    statut = 'soon';
                }
                return {
                    id:            c.id,
                    titre:         c.name_fr || c.name_en || 'Sans titre',
                    description:   c.requirements || '',
                    lieu:          c.location || 'Lieu non spécifié',
                    date_debut:    c.start_date,
                    date_fin:      c.end_date,
                    image_url:     null,
                    articles_count: parseInt(c.articles_count) || 0,
                    categorie:     c.disciplines || '',
                    statut:        statut
                };
            });

            // Filter by tab (upcoming / past)
            if (currentTab === 'upcoming') {
                confs = confs.filter(c => c.statut !== 'past');
            } else if (currentTab === 'past') {
                confs = confs.filter(c => c.statut === 'past');
            }

            // Filter by discipline chip
            if (currentFilter !== 'all') {
                confs = confs.filter(c =>
                    c.categorie && c.categorie.toLowerCase().split(',').map(s => s.trim()).some(d => d.includes(currentFilter.toLowerCase()))
                );
            }

            // Filter by search query
            if (searchQuery.trim()) {
                const q = searchQuery.toLowerCase();
                confs = confs.filter(c =>
                    (c.titre        && c.titre.toLowerCase().includes(q)) ||
                    (c.lieu         && c.lieu.toLowerCase().includes(q))  ||
                    (c.description  && c.description.toLowerCase().includes(q)) ||
                    (c.categorie    && c.categorie.toLowerCase().includes(q))
                );
            }

            allConferences = confs;
        } else {
            allConferences = filterDemoData();
        }
    } catch (error) {
        console.error('Erreur de chargement:', error);
        allConferences = filterDemoData();
    } finally {
        if (spinner) spinner.style.display = 'none';
        if (grid) grid.style.opacity = '1';
        renderConferences(true);
    }
}

// Données de démonstration filtrées (fallback)
function filterDemoData() {
    let filtered = [...demoConferences];
    if (currentTab === 'upcoming') {
        filtered = filtered.filter(c => c.statut !== 'past');
    } else if (currentTab === 'past') {
        filtered = filtered.filter(c => c.statut === 'past');
    }
    if (currentFilter !== 'all') {
        filtered = filtered.filter(c =>
            c.categorie && c.categorie.toLowerCase().includes(currentFilter.toLowerCase())
        );
    }
    if (searchQuery.trim() !== '') {
        const query = searchQuery.toLowerCase().trim();
        filtered = filtered.filter(c =>
            (c.titre        && c.titre.toLowerCase().includes(query)) ||
            (c.description  && c.description.toLowerCase().includes(query)) ||
            (c.lieu         && c.lieu.toLowerCase().includes(query))
        );
    }
    return filtered;
}

// Render les conférences (design identique à l'original)
function renderConferences(resetVisibility = true) {
    const grid = document.getElementById('conferencesGrid');
    const noResults = document.getElementById('noResults');
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    const resultCountSpan = document.querySelector('#resultCount span');
    
    if (!grid) return;
    
    if (resultCountSpan) {
        resultCountSpan.textContent = allConferences.length;
    }
    
    if (resetVisibility) {
        visibleItems = 4;
    }
    
    const visibleData = allConferences.slice(0, visibleItems);
    
    if (loadMoreBtn) {
        loadMoreBtn.style.display = visibleItems < allConferences.length ? 'block' : 'none';
    }
    
    if (allConferences.length === 0) {
        grid.innerHTML = '';
        if (noResults) noResults.style.display = 'block';
        if (loadMoreBtn) loadMoreBtn.style.display = 'none';
    } else {
        if (noResults) noResults.style.display = 'none';
        
        grid.innerHTML = visibleData.map(conf => {
            let badgeClass = 'planning';
            let badgeText = 'En préparation';
            if (conf.statut === 'open')     { badgeClass = 'open';     badgeText = 'Inscriptions ouvertes'; }
            else if (conf.statut === 'soon') { badgeClass = 'soon';     badgeText = 'Bientôt'; }
            else if (conf.statut === 'call') { badgeClass = 'call';     badgeText = 'Appel à communications'; }
            else if (conf.statut === 'past') { badgeClass = 'past';     badgeText = 'Terminée'; }
            
            const dateStr = formatDate(conf.date_debut, conf.date_fin);
            const imgUrl = conf.image_url || 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=800&q=80';
            
            return `
                <div class="conference-card">
                    <div class="card-image">
                        <img src="${imgUrl}" alt="${conf.titre}" loading="lazy">
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

// Formater la date (identique à l'original)
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

// Voir détails conférence
function viewConference(id) {
    // FIX: conference_details.php n'existe pas — rediriger vers submit_article.php
    window.location.href = `submit_article.php?conference_id=${id}`;
}

function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    loadConferences();
}

function switchFilter(filter) {
    currentFilter = filter;
    document.querySelectorAll('.filter-chip').forEach(chip => {
        chip.classList.toggle('active', chip.dataset.filter === filter);
    });
    loadConferences();
}

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

function loadMore() {
    visibleItems += 4;
    renderConferences(false);
}

// Initialisation
document.addEventListener('DOMContentLoaded', function() {
    loadConferences();
    
    const input = document.getElementById('searchInput');
    const clearBtn = document.getElementById('searchClear');
    
    if (input) {
        let timeout = null;
        input.addEventListener('input', function() {
            searchQuery = this.value;
            if (clearBtn) clearBtn.classList.toggle('visible', searchQuery.length > 0);
            clearTimeout(timeout);
            timeout = setTimeout(() => loadConferences(), 500);
        });
    }
    
    if (clearBtn) clearBtn.addEventListener('click', clearSearch);
    
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() { switchTab(this.dataset.tab); });
    });
    
    document.querySelectorAll('.filter-chip').forEach(chip => {
        chip.addEventListener('click', function() { switchFilter(this.dataset.filter); });
    });
    
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    if (loadMoreBtn) loadMoreBtn.addEventListener('click', loadMore);
});
</script>