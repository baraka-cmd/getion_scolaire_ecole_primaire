<?php
/**
 * Fichier : public/index.php
 * Rôle    : Routeur frontal — Point d'entrée unique
 */


define('ACCESS', true);

require_once __DIR__ . '/../src/config/app.php';

// Convertit les anciennes URL générées avec /gestion_scolaire vers le dossier courant.
$legacyBasePath = '/gestion_scolaire';
$currentBasePath = rtrim((string) parse_url(BASE_URL, PHP_URL_PATH), '/');
ob_start(static function (string $output) use ($legacyBasePath, $currentBasePath): string {
    if ($currentBasePath === $legacyBasePath) {
        return $output;
    }

    // Ne remplacer que l'ancien dossier comme segment d'URL complet.
    // Sans cette limite, /gestion_scolaire_ecole_primaire devient
    // /gestion_scolaire_ecole_primaire_ecole_primaire.
    return str_replace($legacyBasePath . '/', $currentBasePath . '/', $output);
});


ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);


// Récupérer l'URL
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = rtrim((string) parse_url(BASE_URL, PHP_URL_PATH), '/');
$routeBasePath = $basePath;
if ($basePath === '' || ($requestUri !== $basePath && !str_starts_with($requestUri, $basePath . '/'))) {
    $routeBasePath = $legacyBasePath;
}
$route = str_replace($routeBasePath, '', $requestUri);
$route = '/' . trim($route, '/');

if (isLoggedIn() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Security::getInstance()->validateCsrf();
}

// ============================================================
// ROUTES
// ============================================================
// ============================================================
// ROUTES
// ============================================================
$routes = [
    '/manifest.webmanifest' => function() {
        $manifest = [
            'name' => ECOLE_NOM,
            'short_name' => 'École',
            'start_url' => BASE_URL . 'login',
            'scope' => BASE_URL,
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => APP_COLOR_THEMES['sapphire']['primary'],
            'icons' => [[
                'src' => ASSETS_URL . 'images/pwa-icon.svg',
                'sizes' => 'any',
                'type' => 'image/svg+xml',
                'purpose' => 'any maskable'
            ]]
        ];
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: no-cache');
        echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    },
    '/service-worker.js' => function() {
        $basePath = rtrim((string) parse_url(BASE_URL, PHP_URL_PATH), '/');
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Service-Worker-Allowed: ' . ($basePath === '' ? '/' : $basePath . '/'));
        readfile(PUBLIC_PATH . 'service-worker.js');
        exit;
    },
    '/api/assistant/actions/prepare' => function() {
        require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AssistantActionController.php';
        (new AssistantActionController())->prepare();
    },
    '/api/assistant/actions/confirm' => function() {
        require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AssistantActionController.php';
        (new AssistantActionController())->confirm();
    },
    '/api/assistant/actions/grade/prepare' => function() {
        require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AssistantSecureActionController.php';
        (new AssistantSecureActionController())->prepareGrade();
    },
    '/api/assistant/actions/grade/confirm' => function() {
        require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AssistantSecureActionController.php';
        (new AssistantSecureActionController())->confirmGrade();
    },
    '/api/assistant/knowledge/index' => function() {
        require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AssistantKnowledgeController.php';
        (new AssistantKnowledgeController())->index();
    },
    '/api/assistant/knowledge/search' => function() {
        require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AssistantKnowledgeController.php';
        (new AssistantKnowledgeController())->search();
    },
    '/api/assistant/classify' => function() {
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantProviderInterface.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantIntentCatalog.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantCapabilityRegistry.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantParameterNormalizer.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantBusinessExecutor.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantDisplayContract.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantRuntimeHealth.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantResponseWriter.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantExecutionContract.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'GeminiService.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantProviderFactory.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantRepository.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantImportService.php';
        require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AssistantController.php';
        (new AssistantController())->classify();
    },
    '/api/assistant/history' => function() {
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantProviderInterface.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantIntentCatalog.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantCapabilityRegistry.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantParameterNormalizer.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantBusinessExecutor.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantDisplayContract.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantRuntimeHealth.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantResponseWriter.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantExecutionContract.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'GeminiService.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantProviderFactory.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantRepository.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantImportService.php';
        require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AssistantController.php';
        try {
            (new AssistantController())->history();
        } catch (Throwable $e) {
            error_log('Assistant history: ' . $e->getMessage());
            jsonResponse(false, 'L’historique est momentanément indisponible.', [], 500);
        }
    },
    '/api/assistant/delete' => function() {
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantProviderInterface.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantIntentCatalog.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'GeminiService.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantProviderFactory.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantRepository.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantImportService.php';
        require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AssistantController.php';
        (new AssistantController())->deleteConversation();
    },
    '/api/assistant/upload' => function() {
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantIntentCatalog.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'GeminiService.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantProviderInterface.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantProviderFactory.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantRepository.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantImportService.php';
        require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AssistantController.php';
        (new AssistantController())->upload();
    },
    '/api/assistant/import/confirm' => function() {
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantIntentCatalog.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'GeminiService.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantProviderInterface.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantProviderFactory.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantRepository.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantImportService.php';
        require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AssistantController.php';
        (new AssistantController())->confirmImport();
    },
    '/api/assistant/import/cancel' => function() {
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantIntentCatalog.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'GeminiService.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantProviderInterface.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantProviderFactory.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantRepository.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantImportService.php';
        require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AssistantController.php';
        (new AssistantController())->cancelImport();
    },

    // Auth
    '/'                          => 'views/auth/login.php',
    '/login'                     => 'views/auth/login.php',
    '/logout'                    => 'views/auth/logout.php',
    '/forgot-password' => 'views/auth/forgot-password.php',
    '/reset-password' => 'views/auth/reset-password.php',
    
    // Dashboard
    '/dashboard'                 => 'views/dashboard/index.php',
    '/assistant'                 => 'views/assistant/index.php',
    
    // Rôles et permissions
    '/roles'                     => 'views/modules/roles/liste.php',
    
    // Utilisateurs
    '/utilisateurs'              => 'views/modules/utilisateurs/liste.php',
    '/utilisateurs/ajouter'      => 'views/modules/utilisateurs/ajouter.php',
    
    // Élèves
    '/eleves'                    => 'views/modules/eleves/liste.php',
    '/eleves/ajouter'            => 'views/modules/eleves/ajouter.php',
    '/eleves/import'             => 'views/modules/eleves/import.php',
    '/eleves/export'             => 'views/modules/eleves/export.php',
    
    // Parents
    '/parents'                   => 'views/modules/parents/liste.php',
    '/parents/ajouter'           => 'views/modules/parents/ajouter.php',
    
    // Enseignants
    '/enseignants'               => 'views/modules/enseignants/liste.php',
    '/enseignants/ajouter'       => 'views/modules/enseignants/ajouter.php',
    '/enseignants/affectations'  => 'views/modules/enseignants/affectations.php',
    
    // Classes
    '/classes'                   => 'views/modules/classes/liste.php',
    '/classes/ajouter'           => 'views/modules/classes/ajouter.php',
    
    // Matières
    '/matieres'                  => 'views/modules/matieres/liste.php',
    '/matieres/ajouter'          => 'views/modules/matieres/ajouter.php',
    
    // Inscriptions
    '/inscriptions'              => 'views/modules/inscriptions/liste.php',
    '/inscriptions/nouvelle'     => 'views/modules/inscriptions/nouvelle.php',
    '/inscriptions/reinscription' => 'views/modules/inscriptions/reinscription.php',
    
   

// ============================================================
// APRÈS (nouvelles routes à AJOUTER)
// ============================================================
'/notes'                     => 'views/modules/notes/liste.php',
'/notes/ajouter'             => 'views/modules/notes/ajouter.php',
'/notes/saisie'              => 'views/modules/notes/saisie.php',
'/notes/consulter'           => 'views/modules/notes/consulter.php',
    
    // Ajouter dans les routes statiques
'/resultats'                 => 'views/modules/resultats/consulter.php',
'/resultats/consulter'       => 'views/modules/resultats/consulter.php',
'/resultats/proclamation'    => 'views/modules/resultats/proclamation.php',
'/resultats/proces-verbaux'  => 'views/modules/resultats/proces_verbaux.php',
'/resultats/deliberations'   => 'views/modules/resultats/deliberations.php',
'/bulletins'                 => 'views/modules/bulletins/consulter.php',
'/bulletins/consulter'       => 'views/modules/bulletins/consulter.php',
'/bulletins/generer'         => 'views/modules/bulletins/generer.php',
'/bulletins/imprimer'        => 'views/modules/bulletins/imprimer.php',
    
    // Emploi du temps
'/emploi-du-temps'           => 'views/modules/emploi/consulter.php',
'/emploi-du-temps/ajouter'   => 'views/modules/emploi/consulter.php',

// Absences
'/absences'                  => 'views/modules/absences/liste.php',
'/absences/marquer'          => 'views/modules/absences/pointage.php',
'/absences/appel'            => 'views/modules/absences/pointage.php',
'/absences/registre'         => 'views/modules/absences/registre.php',
'/absences/justifier'        => 'views/modules/absences/justifier.php',
'/absences/liste' => 'views/modules/absences/liste.php',

// Retards
'/retards'                   => 'views/modules/retards/liste.php',
'/retards/enregistrer'       => 'views/modules/retards/enregistrer.php',
    
    // Discipline
    '/discipline'                => 'views/modules/discipline/liste.php',
    '/discipline/ajouter'        => 'views/modules/discipline/ajouter.php',
    '/discipline/sanctions'      => 'views/modules/discipline/sanctions.php',
    
    // Paiements
    '/paiements'                 => 'views/modules/paiements/liste.php',
    '/paiements/enregistrer'     => 'views/modules/paiements/enregistrer.php',
    '/paiements/impayes'         => 'views/modules/paiements/impayes.php',
    '/paiements/factures'        => 'views/modules/paiements/factures.php',
    '/paiements/recu'            => 'views/modules/paiements/recu.php',
    
    // Frais scolaires
    '/frais-scolaires'           => 'views/modules/frais/liste.php',
    
    // Cantine
    '/cantine'                   => 'views/modules/cantine/liste.php',
    
    // Transport
    '/transport'                 => 'views/modules/transport/liste.php',
    
    // Calendrier
    '/calendrier'                => 'views/modules/calendrier/index.php',
    '/calendrier/ajouter'        => 'views/modules/calendrier/ajouter.php',
    
    // Messages
    '/messages'                  => 'views/modules/messages/boite.php',
    '/messages/envoyer'          => 'views/modules/messages/envoyer.php',
    
    // Documents
    '/documents'                 => 'views/modules/documents/liste.php',
    '/documents/upload'          => 'views/modules/documents/upload.php',
    
    // Notifications
    '/notifications'             => 'views/modules/notifications/liste.php',
    '/notifications/envoyer'     => 'views/modules/notifications/envoyer.php',
    
    // Rapports
    '/rapports/effectifs'        => 'views/modules/rapports/effectifs.php',
    '/rapports/statistiques'     => 'views/modules/rapports/statistiques.php',
    '/rapports/financiers'       => 'views/modules/rapports/financiers.php',
    '/rapports/personnalises'    => 'views/modules/rapports/personnalises.php',
    
    // Paramètres
    '/parametres'                => 'views/modules/parametres/ecole.php',
    '/parametres/annee'          => 'views/modules/parametres/annee.php',
    
    // Sauvegardes
    '/sauvegardes'               => 'views/modules/sauvegardes/liste.php',
    
    // Logs
    '/logs'                      => 'views/modules/logs/liste.php',
    
    // Profil
    '/profil'                    => 'views/modules/utilisateurs/profil.php',
    '/profil/modifier'           => 'views/modules/utilisateurs/modifier.php',
    '/profil/mot-de-passe'       => 'views/modules/utilisateurs/changer_mot_de_passe.php',
    
    // Années scolaires
    '/annees-scolaires'          => 'views/modules/annees/liste.php',
    '/annees-scolaires/ajouter'  => 'views/modules/annees/ajouter.php',
    
    // Aide
    '/aide'                      => 'views/modules/aide/index.php',
    
    // Pages statiques
    '/confidentialite'           => 'views/modules/static/confidentialite.php',
    '/conditions'                => 'views/modules/static/conditions.php',
    '/contact'                   => 'views/modules/static/contact.php',
    
    

 
    // ============================================================
// ROUTES ENSEIGNANT
// ============================================================
'/enseignant/eleves'        => 'views/enseignant/modules/eleves.php',
'/enseignant/notes'         => 'views/enseignant/modules/notes.php',
'/enseignant/absences'      => 'views/enseignant/modules/absences.php',
'/enseignant/emploi'        => 'views/enseignant/modules/emploi.php',
'/enseignant/resultats'     => 'views/enseignant/modules/resultats.php',
'/enseignant/bulletins'     => 'views/enseignant/modules/bulletins.php',

// ============================================================
// ROUTES PARENT
// ============================================================
'/parent/enfants'       => 'views/parent/modules/enfants.php',
'/parent/bulletins'     => 'views/parent/modules/bulletins.php',
'/parent/absences'      => 'views/parent/modules/absences.php',
'/parent/paiements'     => 'views/parent/modules/paiements.php',
'/parent/retards'       => 'views/parent/modules/retards.php',

// ============================================================
// ROUTES COMPTABLE
// ============================================================
'/comptable/paiements'  => 'views/comptable/modules/paiements.php',
'/comptable/enregistrer' => 'views/comptable/modules/enregistrer.php',
'/comptable/impayes'    => 'views/comptable/modules/impayes.php',
'/comptable/frais'      => 'views/comptable/modules/frais.php',
'/comptable/rapport-financier' => 'views/comptable/modules/rapport_financier.php',


'/documents/telecharger/(\d+)' => function($matches) {
    require_once SRC_PATH . 'controllers/DocumentController.php';
    (new DocumentController())->telecharger((int)$matches[1]);
},


'/sauvegardes/telecharger/(\d+)'  => function($matches) {
    require_once SRC_PATH . 'controllers/SauvegardeController.php';
    (new SauvegardeController())->telecharger((int)$matches[1]);
},

    // --- AJOUTE TES ROUTES GOOGLE ICI ---
    '/auth/google' => function() {
        require_once SRC_PATH . 'controllers/AuthController.php';
        $authController = new AuthController();
        $authController->redirectToGoogle();
    },
    
    '/auth/google-callback' => function() {
        require_once SRC_PATH . 'services/GoogleAuthService.php';
        require_once SRC_PATH . 'controllers/AuthController.php';
        $loginBase = preg_replace('#/auth/google-callback2?$#', '', GOOGLE_REDIRECT_URI);
        $loginUrl = rtrim((string) $loginBase, '/') . '/login';

        if (isset($_GET['error'])) {
            unset($_SESSION['oauth2state'], $_SESSION['oauth2state_created_at'], $_SESSION['oauth2pkce_verifier']);
            redirect($loginUrl . '?error=google_cancelled');
        }

        $state = $_GET['state'] ?? '';
        $state = is_string($state) ? $state : '';
        $expectedState = (string) ($_SESSION['oauth2state'] ?? '');
        $stateCreatedAt = (int) ($_SESSION['oauth2state_created_at'] ?? 0);
        $codeVerifier = (string) ($_SESSION['oauth2pkce_verifier'] ?? '');
        unset($_SESSION['oauth2state'], $_SESSION['oauth2state_created_at'], $_SESSION['oauth2pkce_verifier']);
        if (
            $state === ''
            || $expectedState === ''
            || !preg_match('/^[a-f0-9]{64}$/', $codeVerifier)
            || $stateCreatedAt < time() - 600
            || $stateCreatedAt > time() + 60
            || !hash_equals($expectedState, $state)
        ) {
            redirect($loginUrl . '?error=invalid_state');
        }

        $code = $_GET['code'] ?? '';
        if (!is_string($code) || $code === '') {
            redirect($loginUrl . '?error=no_code');
        }

        $googleService = new GoogleAuthService();
        $accessToken   = $googleService->getAccessToken($code, $codeVerifier);

        if (!$accessToken) {
            redirect($loginUrl . '?error=token_failed');
        }

        $googleUser = $googleService->getUserInfo($accessToken);

        if (!$googleUser) {
            redirect($loginUrl . '?error=userinfo_failed');
        }
        if (($googleUser['email_verified'] ?? false) !== true) {
            redirect($loginUrl . '?error=google_email_unverified');
        }

        $authController = new AuthController();
        $authController->handleGoogleCallback($googleUser);
    }



];

// ============================================================
// ROUTES DYNAMIQUES (avec paramètres)
// ============================================================
$dynamicRoutes = [
    // Rôle : /roles/gerer/5
    '#^/roles/gerer/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/roles/gerer.php';
    },
    // Utilisateur fiche : /utilisateurs/fiche/7
    '#^/utilisateurs/fiche/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/utilisateurs/fiche.php';
    },
    // Utilisateur modifier : /utilisateurs/modifier/7
    '#^/utilisateurs/modifier/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/utilisateurs/modifier.php';
    },
    // Élève fiche : /eleves/fiche/15
    '#^/eleves/fiche/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/eleves/fiche.php';
    },
    // Élève modifier : /eleves/modifier/15
    '#^/eleves/modifier/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/eleves/modifier.php';
    },
    // Enseignant fiche : /enseignants/fiche/3
    '#^/enseignants/fiche/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/enseignants/fiche.php';
    },
    // Enseignant modifier : /enseignants/modifier/3
    '#^/enseignants/modifier/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/enseignants/modifier.php';
    },
    // Parent fiche : /parents/fiche/8
    '#^/parents/fiche/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/parents/fiche.php';
    },
    // Parent modifier : /parents/modifier/8
    '#^/parents/modifier/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/parents/modifier.php';
    },
    // Matière modifier : /matieres/modifier/10
    '#^/matieres/modifier/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/matieres/modifier.php';
    },
    // Année scolaire modifier : /annees-scolaires/modifier/3
    '#^/annees-scolaires/modifier/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/annees/modifier.php';
    },
    // Classe détail : /classes/detail/13
    '#^/classes/detail/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/classes/detail.php';
    },
    // Classe modifier : /classes/modifier/13
    '#^/classes/modifier/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/classes/modifier.php';
    },
    // Message conversation : /messages/5
    '#^/messages/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/messages/conversation.php';
    },
    // Paiement reçu : /paiements/recu/12
    '#^/paiements/recu/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/paiements/recu.php';
    },
    // Bulletin élève : /bulletins/eleve/25
    '#^/bulletins/eleve/(\d+)$#' => function($matches) {
        $_GET['eleve_id'] = $matches[1];
        require_once __DIR__ . '/views/modules/bulletins/consulter.php';
    },
    // Notes saisie : /notes/saisie/113
    '#^/notes/saisie/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/notes/saisie.php';
    },
    // Notes modifier : /notes/ajouter/113
    '#^/notes/ajouter/(\d+)$#' => function($matches) {
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/views/modules/notes/ajouter.php';
    },
];

$routes['/auth/google-callback2'] = $routes['/auth/google-callback'];

// ============================================================
// ROUTAGE
// ============================================================
// ============================================================
// ROUTAGE (CORRIGÉ)
// ============================================================
if (isset($routes[$route])) {
    $target = $routes[$route];

    // Si la route est une fonction anonyme (Closure) comme pour Google OAuth
    if (is_callable($target)) {
        $target();
    } 
    // Si la route est un chemin vers un fichier PHP statique
    elseif (is_string($target)) {
        require_once __DIR__ . '/' . $target;
    }
} else {
    $matched = false;
    foreach ($dynamicRoutes as $pattern => $handler) {
        if (preg_match($pattern, $route, $matches)) {
            // Si c'est une fonction (Closure)
            if (is_callable($handler)) {
                $handler($matches);
            } 
            // Si c'est un chemin vers un fichier PHP statique (string)
            elseif (is_string($handler)) {
                $_GET['matches'] = $matches; // Passe les paramètres regex
                require_once __DIR__ . '/' . $handler;
            }
            
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        // Servir les fichiers statiques
        $filePath = __DIR__ . $route;
        if (file_exists($filePath) && is_file($filePath)) {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'css'   => 'text/css',
                'js'    => 'application/javascript',
                'json'  => 'application/json',
                'png'   => 'image/png',
                'jpg'   => 'image/jpeg',
                'jpeg'  => 'image/jpeg',
                'gif'   => 'image/gif',
                'svg'   => 'image/svg+xml',
                'ico'   => 'image/x-icon',
                'woff'  => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf'   => 'font/ttf',
                'pdf'   => 'application/pdf',
                'csv'   => 'text/csv',
                'xls'   => 'application/vnd.ms-excel',
                'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];

            if (isset($mimeTypes[$extension])) {
                header('Content-Type: ' . $mimeTypes[$extension]);
            }

            $cacheExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf'];
            if (in_array($extension, $cacheExtensions)) {
                $cacheTime = 604800;
                header('Cache-Control: public, max-age=' . $cacheTime);
                header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $cacheTime) . ' GMT');
            }

            readfile($filePath);
            exit;
        }

        // La présentation de l'erreur appartient à une vue dédiée.
        http_response_code(404);
        require_once __DIR__ . '/views/errors/404.php';
        exit;
    }
}