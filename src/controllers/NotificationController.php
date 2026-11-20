<?php
/**
 * Fichier : src/controllers/NotificationController.php
 * Rôle    : Gestion des notifications — Création, envoi, consultation
 */

defined('ACCESS') or die('Accès interdit');

require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'NotificationPushService.php';

class NotificationController
{
    private Database $db;

    private const TYPES_NOTIFICATION = ['Information', 'Alerte', 'Rappel', 'Urgence', 'Succès'];
    private const STATUTS_INSCRIPTION_ELIGIBLES = "'Actif','Redoublant'";

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function handleAjax(): void
    {
        if (!can('notifications.consulter')) {
            jsonResponse(false, 'Accès refusé.', [], 403);
        }

        if (!isAjax() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            jsonResponse(false, 'Requête invalide.', [], 400);
        }

        if (!verifyCsrfToken(csrfTokenFromRequest())) {
            jsonResponse(false, 'Votre session a expiré. Actualisez la page puis réessayez.', [], 419);
        }

        switch (post('action', '')) {
            case 'getListe':
                $this->getListe();
                break;
            case 'getConversation':
                $this->getConversation();
                break;
            case 'marquerLue':
                $this->marquerLue();
                break;
            case 'marquerToutesLues':
                $this->marquerToutesLues();
                break;
            case 'supprimer':
                $this->supprimer();
                break;
            case 'envoyer':
                $this->envoyer();
                break;
            case 'getCount':
                $this->getCount();
                break;
            case 'getParentsByClasse':
                $this->getParentsByClasse();
                break;
            case 'registerPushSubscription':
                $this->registerPushSubscription();
                break;
            case 'removePushSubscription':
                $this->removePushSubscription();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    private function getListe(): void
    {
        $page = max(1, (int) post('page', 1));
        $perPage = defined('ITEMS_PER_PAGE') ? max(1, (int) ITEMS_PER_PAGE) : 15;
        $type = post('type', '');
        $lu = post('lu', '');
        $recherche = post('recherche', '');
        $userId = (int) ($_SESSION['utilisateur_id'] ?? 0);

        if ($type !== '' && !in_array($type, self::TYPES_NOTIFICATION, true)) {
            jsonResponse(false, 'Type de notification invalide.', [], 422);
        }
        if ($lu !== '' && !in_array($lu, ['0', '1'], true)) {
            jsonResponse(false, 'Filtre de lecture invalide.', [], 422);
        }

        if (hasRole(['Admin', 'Directeur'])) {
            $conditions = [
                'EXISTS (SELECT 1 FROM notifications n0 WHERE n0.utilisateur_id = u.id)'
            ];
            $params = [];

            if ($recherche !== '') {
                $conditions[] = "(
                    CONCAT(u.prenom, ' ', u.nom) LIKE :search_name
                    OR u.login LIKE :search_login
                    OR EXISTS (
                        SELECT 1 FROM notifications ns
                        WHERE ns.utilisateur_id = u.id
                          AND (ns.titre LIKE :search_title OR ns.message LIKE :search_message)
                    )
                )";
                $search = '%' . $recherche . '%';
                $params += [
                    'search_name' => $search,
                    'search_login' => $search,
                    'search_title' => $search,
                    'search_message' => $search
                ];
            }
            if ($type !== '') {
                $conditions[] = 'EXISTS (
                    SELECT 1 FROM notifications nt
                    WHERE nt.utilisateur_id = u.id AND nt.type_notification = :filter_type
                )';
                $params['filter_type'] = $type;
            }
            if ($lu !== '') {
                $conditions[] = $lu === '0'
                    ? 'EXISTS (
                        SELECT 1 FROM notifications nr
                        WHERE nr.utilisateur_id = u.id AND nr.est_lue = 0
                    )'
                    : 'NOT EXISTS (
                        SELECT 1 FROM notifications nr
                        WHERE nr.utilisateur_id = u.id AND nr.est_lue = 0
                    )';
            }

            $where = implode(' AND ', $conditions);
            $total = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM utilisateurs u WHERE {$where}",
                $params
            );
            $offset = ($page - 1) * $perPage;
            $conversations = $this->db->fetchAll(
                "SELECT u.id AS destinataire_id,
                        CONCAT(u.prenom, ' ', u.nom) AS destinataire_nom,
                        u.login AS destinataire_login,
                        COUNT(n.id) AS total_notifications,
                        SUM(CASE WHEN n.est_lue = 0 THEN 1 ELSE 0 END) AS non_lues,
                        DATE_FORMAT(MAX(n.date_envoi), '%d/%m/%Y à %H:%i') AS dernier_envoi,
                        (SELECT nl.titre FROM notifications nl
                         WHERE nl.utilisateur_id = u.id
                         ORDER BY nl.date_envoi DESC, nl.id DESC LIMIT 1) AS dernier_titre,
                        (SELECT nl.message FROM notifications nl
                         WHERE nl.utilisateur_id = u.id
                         ORDER BY nl.date_envoi DESC, nl.id DESC LIMIT 1) AS dernier_message
                 FROM utilisateurs u
                 INNER JOIN notifications n ON n.utilisateur_id = u.id
                 WHERE {$where}
                 GROUP BY u.id, u.prenom, u.nom, u.login
                 ORDER BY MAX(n.date_envoi) DESC, u.id DESC
                 LIMIT {$offset}, {$perPage}",
                $params
            );

            jsonResponse(true, 'Conversations récupérées.', [
                'mode' => 'admin',
                'conversations' => $conversations,
                'total' => $total,
                'page' => $page,
                'pages' => (int) ceil($total / $perPage),
                'stats' => $this->getStats(null)
            ]);
        }

        $conditions = ['n.utilisateur_id = :uid'];
        $params = ['uid' => $userId];
        if ($type !== '') {
            $conditions[] = 'n.type_notification = :type';
            $params['type'] = $type;
        }
        if ($lu !== '') {
            $conditions[] = 'n.est_lue = :lu';
            $params['lu'] = (int) $lu;
        }
        if ($recherche !== '') {
            $conditions[] = '(n.titre LIKE :search_title OR n.message LIKE :search_message)';
            $search = '%' . $recherche . '%';
            $params['search_title'] = $search;
            $params['search_message'] = $search;
        }

        $where = implode(' AND ', $conditions);
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM notifications n WHERE {$where}",
            $params
        );
        $offset = ($page - 1) * $perPage;
        $notifications = $this->db->fetchAll(
            "SELECT n.*,
                    DATE_FORMAT(n.date_envoi, '%d/%m/%Y') AS date_format,
                    TIME_FORMAT(n.date_envoi, '%H:%i') AS heure_format,
                    DATE_FORMAT(n.date_lecture, '%d/%m/%Y à %H:%i') AS date_lecture_format
             FROM notifications n
             WHERE {$where}
             ORDER BY n.date_envoi DESC, n.id DESC
             LIMIT {$offset}, {$perPage}",
            $params
        );

        jsonResponse(true, 'Notifications récupérées.', [
            'mode' => 'personnel',
            'notifications' => $notifications,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / $perPage),
            'stats' => $this->getStats($userId)
        ]);
    }

    private function getConversation(): void
    {
        if (!hasRole(['Admin', 'Directeur'])) {
            jsonResponse(false, 'Accès refusé.', [], 403);
        }

        $recipientId = (int) post('destinataire_id', 0);
        if ($recipientId <= 0) {
            jsonResponse(false, 'Destinataire invalide.', [], 422);
        }

        $recipient = $this->db->fetchOne(
            "SELECT id, CONCAT(prenom, ' ', nom) AS nom, login
             FROM utilisateurs WHERE id = :id",
            ['id' => $recipientId]
        );
        if (!$recipient) {
            jsonResponse(false, 'Destinataire introuvable.', [], 404);
        }

        $page = max(1, (int) post('page', 1));
        $perPage = 20;
        $total = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM notifications WHERE utilisateur_id = :uid',
            ['uid' => $recipientId]
        );
        $offset = ($page - 1) * $perPage;
        $notifications = $this->db->fetchAll(
            "SELECT n.id, n.titre, n.message, n.type_notification, n.lien, n.origine,
                    n.est_lue, n.date_envoi, n.date_lecture, n.campagne_id,
                    DATE_FORMAT(n.date_envoi, '%d/%m/%Y') AS date_format,
                    TIME_FORMAT(n.date_envoi, '%H:%i') AS heure_format,
                    DATE_FORMAT(n.date_lecture, '%d/%m/%Y à %H:%i') AS date_lecture_format,
                    c.type_destinataire, c.nb_destinataires,
                    q.statut AS statut_email, q.tentatives AS tentatives_email,
                    q.envoye_le AS email_envoye_le, q.derniere_erreur AS erreur_email
             FROM notifications n
             LEFT JOIN notification_campaigns c ON c.id = n.campagne_id
             LEFT JOIN notification_email_queue q ON q.notification_id = n.id
             WHERE n.utilisateur_id = :uid
             ORDER BY n.date_envoi DESC, n.id DESC
             LIMIT {$offset}, {$perPage}",
            ['uid' => $recipientId]
        );

        jsonResponse(true, 'Historique du destinataire récupéré.', [
            'destinataire' => $recipient,
            'notifications' => $notifications,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / $perPage)
        ]);
    }

    private function marquerLue(): void
    {
        $notificationId = (int) post('notification_id', 0);
        if ($notificationId <= 0) {
            jsonResponse(false, 'ID notification invalide.', [], 400);
        }

        $userId = (int) ($_SESSION['utilisateur_id'] ?? 0);
        $updated = $this->db->update(
            "UPDATE notifications
             SET est_lue = 1, date_lecture = NOW()
             WHERE id = :id AND utilisateur_id = :uid AND est_lue = 0",
            ['id' => $notificationId, 'uid' => $userId]
        );
        if ($updated === 0) {
            $owned = $this->db->fetchColumn(
                'SELECT COUNT(*) FROM notifications WHERE id = :id AND utilisateur_id = :uid',
                ['id' => $notificationId, 'uid' => $userId]
            );
            if (!(int) $owned) {
                jsonResponse(false, 'Notification introuvable.', [], 404);
            }
        }

        jsonResponse(true, 'Notification marquée comme lue.');
    }

    private function marquerToutesLues(): void
    {
        $this->db->update(
            "UPDATE notifications
             SET est_lue = 1, date_lecture = NOW()
             WHERE utilisateur_id = :uid AND est_lue = 0",
            ['uid' => (int) ($_SESSION['utilisateur_id'] ?? 0)]
        );

        jsonResponse(true, 'Toutes vos notifications sont marquées comme lues.');
    }

    private function supprimer(): void
    {
        $notificationId = (int) post('notification_id', 0);
        if ($notificationId <= 0) {
            jsonResponse(false, 'ID notification invalide.', [], 400);
        }

        $deleted = $this->db->delete(
            'DELETE FROM notifications WHERE id = :id AND utilisateur_id = :uid',
            ['id' => $notificationId, 'uid' => (int) ($_SESSION['utilisateur_id'] ?? 0)]
        );
        if ($deleted === 0) {
            jsonResponse(false, 'Notification introuvable ou accès refusé.', [], 404);
        }

        jsonResponse(true, 'Notification supprimée.');
    }

    private function envoyer(): void
    {
        requirePermission('notifications.envoyer');

        $destinataireType = post('destinataire_type', '');
        $classeId = (int) post('classe_id', 0);
        $parentId = (int) post('parent_id', 0);
        $titre = post('titre', '');
        $message = post('message', '');
        $typeNotif = post('type_notification', 'Information');
        $envoyerEmail = post('envoyer_email', '0');
        $requestKey = post('request_key', '');

        if (!in_array($destinataireType, ['tous', 'classe', 'parent'], true)) {
            jsonResponse(false, 'Type de destinataire invalide.', [], 422);
        }
        $titleLength = $this->unicodeLength($titre);
        $messageLength = $this->unicodeLength($message);
        if (isBlank($titre) || $titleLength === null || $titleLength > 255) {
            jsonResponse(false, 'Le titre est obligatoire et ne peut dépasser 255 caractères.', [], 422);
        }
        if (isBlank($message) || $messageLength === null || $messageLength > 16000 || strlen($message) > 64000) {
            jsonResponse(false, 'Le message est obligatoire et ne peut dépasser 16 000 caractères.', [], 422);
        }
        if (!in_array($typeNotif, self::TYPES_NOTIFICATION, true)) {
            jsonResponse(false, 'Type de notification invalide.', [], 422);
        }
        if (!in_array($envoyerEmail, ['0', '1'], true)) {
            jsonResponse(false, 'Option email invalide.', [], 422);
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $requestKey)) {
            jsonResponse(false, 'Identifiant de demande invalide. Rechargez la page.', [], 422);
        }
        if ($destinataireType !== 'tous' && $classeId <= 0) {
            jsonResponse(false, 'Veuillez sélectionner une classe active.', [], 422);
        }
        if ($destinataireType === 'parent' && $parentId <= 0) {
            jsonResponse(false, 'Veuillez sélectionner un parent.', [], 422);
        }

        $creatorId = (int) ($_SESSION['utilisateur_id'] ?? 0);
        $existingCampaign = $this->db->fetchOne(
            "SELECT id, nb_destinataires FROM notification_campaigns
             WHERE created_by = :creator AND request_key = :request_key",
            ['creator' => $creatorId, 'request_key' => $requestKey]
        );
        if ($existingCampaign) {
            jsonResponse(true, 'Cet envoi a déjà été pris en compte.', [
                'campaign_id' => (int) $existingCampaign['id'],
                'destinataires' => (int) $existingCampaign['nb_destinataires'],
                'duplicate' => true
            ]);
        }

        $activeYearId = (int) $this->db->fetchColumn(
            'SELECT annee_scolaire_active_id FROM parametres_ecole WHERE id = 1'
        );
        if ($activeYearId <= 0) {
            jsonResponse(false, 'Aucune année scolaire active n’est configurée.', [], 409);
        }

        $params = ['year_id' => $activeYearId];
        $classCondition = '';
        if ($destinataireType !== 'tous') {
            $class = $this->db->fetchOne(
                'SELECT id FROM classes WHERE id = :class_id AND annee_scolaire_id = :year_id',
                ['class_id' => $classeId, 'year_id' => $activeYearId]
            );
            if (!$class) {
                jsonResponse(false, 'La classe sélectionnée ne fait pas partie de l’année active.', [], 422);
            }
            $classCondition = ' AND i.classe_id = :class_id';
            $params['class_id'] = $classeId;
        }

        $recipientCondition = '';
        if ($destinataireType === 'parent') {
            $recipientCondition = ' AND pt.id = :parent_id';
            $params['parent_id'] = $parentId;
        }

        $recipients = $this->db->fetchAll(
            "SELECT u.id, MAX(pt.email) AS email
             FROM utilisateurs u
             INNER JOIN parents_tuteurs pt ON pt.utilisateur_id = u.id
             INNER JOIN eleve_parent ep ON ep.parent_id = pt.id
             INNER JOIN inscriptions i ON i.eleve_id = ep.eleve_id
             WHERE u.est_actif = 1
               AND i.annee_scolaire_id = :year_id
               AND i.statut IN (" . self::STATUTS_INSCRIPTION_ELIGIBLES . ")
               {$classCondition}
               {$recipientCondition}
             GROUP BY u.id",
            $params
        );

        if ($recipients === []) {
            jsonResponse(false, 'Aucun parent avec un compte actif et un élève inscrit dans l’année/classe sélectionnée.', [], 404);
        }

        $emailPending = 0;
        $emailMissing = 0;
        $pushQueued = 0;
        try {
            $this->db->beginTransaction();
            $campaignId = $this->db->insert(
                "INSERT INTO notification_campaigns
                    (created_by, request_key, type_destinataire, classe_id, nb_destinataires, email_demande)
                 VALUES (:creator, :request_key, :target_type, :class_id, :recipient_count, :email_requested)",
                [
                    'creator' => $creatorId,
                    'request_key' => $requestKey,
                    'target_type' => $destinataireType,
                    'class_id' => $destinataireType === 'tous' ? null : $classeId,
                    'recipient_count' => count($recipients),
                    'email_requested' => (int) $envoyerEmail
                ]
            );

            foreach ($recipients as $recipient) {
                $notificationId = $this->db->insert(
                    "INSERT INTO notifications
                        (utilisateur_id, campagne_id, titre, message, type_notification, date_envoi)
                     VALUES (:uid, :campaign_id, :title, :message, :type, NOW())",
                    [
                        'uid' => $recipient['id'],
                        'campaign_id' => $campaignId,
                        'title' => $titre,
                        'message' => $message,
                        'type' => $typeNotif
                    ]
                );

                $pushQueued += NotificationPushService::enqueueForNotification(
                    $this->db,
                    $notificationId,
                    (int) $recipient['id']
                );

                if ($envoyerEmail === '1') {
                    $email = trim((string) ($recipient['email'] ?? ''));
                    $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
                    $status = $validEmail ? 'en_attente' : 'sans_email';
                    $this->db->insert(
                        "INSERT INTO notification_email_queue
                            (notification_id, destinataire_email, statut)
                         VALUES (:notification_id, :email, :status)",
                        [
                            'notification_id' => $notificationId,
                            'email' => $validEmail ? $email : null,
                            'status' => $status
                        ]
                    );
                    $validEmail ? $emailPending++ : $emailMissing++;
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                $duplicateCampaign = $this->db->fetchOne(
                    "SELECT id, nb_destinataires FROM notification_campaigns
                     WHERE created_by = :creator AND request_key = :request_key",
                    ['creator' => $creatorId, 'request_key' => $requestKey]
                );
                if ($duplicateCampaign) {
                    jsonResponse(true, 'Cet envoi a déjà été pris en compte.', [
                        'campaign_id' => (int) $duplicateCampaign['id'],
                        'destinataires' => (int) $duplicateCampaign['nb_destinataires'],
                        'duplicate' => true
                    ]);
                }
            }
            error_log('Erreur création campagne de notifications: ' . $e->getMessage());
            jsonResponse(false, 'La campagne n’a pas pu être enregistrée. Aucune notification n’a été envoyée.', [], 500);
        }

        $summary = count($recipients) . ' notification(s) enregistrée(s).';
        $summary .= ' ' . $pushQueued . ' livraison(s) push mise(s) en file.';
        if ($pushQueued === 0) {
            $summary .= ' Aucun appareil parent n’est enregistré pour les push.';
        }
        if ($envoyerEmail === '1') {
            $summary .= ' ' . $emailPending . ' email(s) placé(s) en file d’attente.';
            if ($emailMissing > 0) {
                $summary .= ' ' . $emailMissing . ' parent(s) sans adresse email valide.';
            }
        }

        logActivity('Envoi', 'notifications', $campaignId, $summary);
        jsonResponse(true, $summary, [
            'campaign_id' => $campaignId,
            'destinataires' => count($recipients),
            'emails_en_attente' => $emailPending,
            'sans_email' => $emailMissing,
            'push_en_attente' => $pushQueued
        ]);
    }

    private function getCount(): void
    {
        $userId = (int) ($_SESSION['utilisateur_id'] ?? 0);
        $count = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM notifications WHERE utilisateur_id = :uid AND est_lue = 0',
            ['uid' => $userId]
        );
        jsonResponse(true, 'Compteur récupéré.', ['count' => $count]);
    }

    private function registerPushSubscription(): void
    {
        if (!hasRole('Parent')) {
            jsonResponse(false, 'Seuls les comptes parents peuvent enregistrer un appareil.', [], 403);
        }
        if (!NotificationPushService::isConfigured()) {
            jsonResponse(false, 'Les notifications push ne sont pas encore configurées sur le serveur.', [], 503);
        }

        $userId = (int) ($_SESSION['utilisateur_id'] ?? 0);
        $eligible = $this->db->exists(
            "SELECT u.id
             FROM utilisateurs u
             INNER JOIN parents_tuteurs pt ON pt.utilisateur_id = u.id
             INNER JOIN eleve_parent ep ON ep.parent_id = pt.id
             INNER JOIN inscriptions i ON i.eleve_id = ep.eleve_id
             WHERE u.id = :user_id
               AND u.est_actif = 1
               AND i.annee_scolaire_id = (
                   SELECT annee_scolaire_active_id FROM parametres_ecole WHERE id = 1
               )
               AND i.statut IN (" . self::STATUTS_INSCRIPTION_ELIGIBLES . ')
             LIMIT 1',
            ['user_id' => $userId]
        );
        if (!$eligible) {
            jsonResponse(false, 'Votre compte parent n’est pas lié à un élève actif ou redoublant de l’année scolaire active.', [], 403);
        }

        $rawSubscription = $_POST['subscription'] ?? '';
        if (!is_string($rawSubscription) || strlen($rawSubscription) > 8192) {
            jsonResponse(false, 'Données d’abonnement invalides ou trop volumineuses.', [], 422);
        }
        $subscription = json_decode($rawSubscription, true);
        if (!is_array($subscription)) {
            jsonResponse(false, 'Données d’abonnement invalides.', [], 422);
        }

        try {
            $result = (new NotificationPushService($this->db))->register($userId, $subscription);
        } catch (InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (DomainException $e) {
            jsonResponse(false, $e->getMessage(), [], 409);
        } catch (LengthException $e) {
            jsonResponse(false, $e->getMessage(), [], 429);
        } catch (Throwable $e) {
            error_log('Enregistrement abonnement push impossible: ' . $e->getMessage());
            jsonResponse(false, 'L’appareil n’a pas pu être enregistré. Réessayez plus tard.', [], 500);
        }

        jsonResponse(true, 'Cet appareil est enregistré pour les prochaines notifications push.', [
            'subscription_id' => $result['id'],
            'updated' => $result['updated']
        ]);
    }

    private function removePushSubscription(): void
    {
        if (!hasRole('Parent')) {
            jsonResponse(false, 'Seuls les comptes parents peuvent gérer leurs appareils.', [], 403);
        }

        $endpoint = $_POST['endpoint'] ?? '';
        if (!is_string($endpoint) || strlen($endpoint) > 2048) {
            jsonResponse(false, 'Adresse d’abonnement invalide.', [], 422);
        }

        try {
            $removed = (new NotificationPushService($this->db))->remove(
                (int) ($_SESSION['utilisateur_id'] ?? 0),
                $endpoint
            );
        } catch (InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (Throwable $e) {
            error_log('Suppression abonnement push impossible: ' . $e->getMessage());
            jsonResponse(false, 'Cet appareil n’a pas pu être désactivé. Réessayez plus tard.', [], 500);
        }

        jsonResponse(true, $removed
            ? 'Cet appareil ne recevra plus de notifications push.'
            : 'Aucun abonnement de cet appareil n’était enregistré.');
    }

    private function getParentsByClasse(): void
    {
        requirePermission('notifications.envoyer');

        $classeId = (int) post('classe_id', 0);
        $activeYearId = (int) $this->db->fetchColumn(
            'SELECT annee_scolaire_active_id FROM parametres_ecole WHERE id = 1'
        );
        if ($classeId <= 0 || $activeYearId <= 0) {
            jsonResponse(false, 'Classe ou année active invalide.', [], 422);
        }

        $parents = $this->db->fetchAll(
            "SELECT DISTINCT pt.id, pt.nom, pt.prenom, pt.telephone_principal, pt.email,
                    u.id AS utilisateur_id
             FROM parents_tuteurs pt
             INNER JOIN utilisateurs u ON u.id = pt.utilisateur_id AND u.est_actif = 1
             INNER JOIN eleve_parent ep ON ep.parent_id = pt.id
             INNER JOIN inscriptions i ON i.eleve_id = ep.eleve_id
             INNER JOIN classes c ON c.id = i.classe_id
             WHERE i.classe_id = :class_id
               AND i.annee_scolaire_id = :year_id
               AND c.annee_scolaire_id = :class_year_id
               AND i.statut IN (" . self::STATUTS_INSCRIPTION_ELIGIBLES . ")
             ORDER BY pt.nom, pt.prenom",
            [
                'class_id' => $classeId,
                'year_id' => $activeYearId,
                'class_year_id' => $activeYearId
            ]
        );

        jsonResponse(true, 'Parents récupérés.', ['parents' => $parents]);
    }

    private function getStats(?int $userId): array
    {
        $where = $userId === null ? '' : ' WHERE utilisateur_id = :uid';
        $params = $userId === null ? [] : ['uid' => $userId];

        return [
            'total' => (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM notifications{$where}",
                $params
            ),
            'non_lues' => (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM notifications{$where}" . ($where === '' ? ' WHERE' : ' AND') . ' est_lue = 0',
                $params
            ),
            'lues' => (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM notifications{$where}" . ($where === '' ? ' WHERE' : ' AND') . ' est_lue = 1',
                $params
            ),
            'alertes' => (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM notifications{$where}" . ($where === '' ? ' WHERE' : ' AND') . " type_notification IN ('Alerte','Urgence')",
                $params
            )
        ];
    }

    private function unicodeLength(string $value): ?int
    {
        $length = preg_match_all('/./us', $value);
        return $length === false ? null : $length;
    }

    public static function creerNotification(
        int $parentUserId,
        string $titre,
        string $message,
        string $type = 'Information',
        ?string $lien = null
    ): void {
        try {
            $db = Database::getInstance();
            $notificationId = $db->insert(
                "INSERT INTO notifications
                    (utilisateur_id, titre, message, type_notification, lien, origine, date_envoi)
                 VALUES (:uid, :titre, :message, :type, :lien, 'automatique', NOW())",
                [
                    'uid' => $parentUserId,
                    'titre' => $titre,
                    'message' => $message,
                    'type' => $type,
                    'lien' => $lien
                ]
            );
            try {
                self::enqueuePush($db, $notificationId, $parentUserId);
            } catch (Throwable $e) {
                error_log('Mise en file push de notification automatique impossible: ' . $e->getMessage());
            }
        } catch (Throwable $e) {
            error_log('Erreur création notification automatique: ' . $e->getMessage());
        }
    }

    private static function enqueuePush(Database $db, int $notificationId, int $userId): int
    {
        return NotificationPushService::enqueueForNotification($db, $notificationId, $userId);
    }

    public static function notifierParentsEleve(
        int $eleveId,
        string $titre,
        string $message,
        string $type = 'Information'
    ): void {
        try {
            $db = Database::getInstance();
            $parents = $db->fetchAll(
                "SELECT DISTINCT pt.utilisateur_id
                 FROM parents_tuteurs pt
                 INNER JOIN eleve_parent ep ON ep.parent_id = pt.id
                 INNER JOIN utilisateurs u ON u.id = pt.utilisateur_id AND u.est_actif = 1
                 WHERE ep.eleve_id = :student_id",
                ['student_id' => $eleveId]
            );

            foreach ($parents as $parent) {
                self::creerNotification((int) $parent['utilisateur_id'], $titre, $message, $type);
            }
        } catch (Throwable $e) {
            error_log('Erreur notification parents élève: ' . $e->getMessage());
        }
    }

    public function getClassesActives(): array
    {
        return $this->db->fetchAll(
            "SELECT c.* FROM classes c
             WHERE c.annee_scolaire_id = (
                 SELECT annee_scolaire_active_id FROM parametres_ecole WHERE id = 1
             )
             ORDER BY c.niveau"
        );
    }

    public function countParentsEligibles(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT u.id)
             FROM utilisateurs u
             INNER JOIN parents_tuteurs pt ON pt.utilisateur_id = u.id
             INNER JOIN eleve_parent ep ON ep.parent_id = pt.id
             INNER JOIN inscriptions i ON i.eleve_id = ep.eleve_id
             WHERE u.est_actif = 1
               AND i.annee_scolaire_id = (
                   SELECT annee_scolaire_active_id FROM parametres_ecole WHERE id = 1
               )
               AND i.statut IN (" . self::STATUTS_INSCRIPTION_ELIGIBLES . ')'
        );
    }

    public function getNotificationsHeader(): array
    {
        $userId = (int) ($_SESSION['utilisateur_id'] ?? 0);
        return $this->db->fetchAll(
            "SELECT id, titre, message, type_notification, lien,
                    DATE_FORMAT(date_envoi, '%d/%m/%Y %H:%i') AS date_format
             FROM notifications
             WHERE utilisateur_id = :uid AND est_lue = 0
             ORDER BY date_envoi DESC, id DESC
             LIMIT 5",
            ['uid' => $userId]
        );
    }
}
