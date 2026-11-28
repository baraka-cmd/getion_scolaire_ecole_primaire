<?php
/**
 * Point d'entrée backend de l'assistant scolaire.
 *
 * Les requêtes métier seront branchées dans des handlers PHP dédiés,
 * jamais dans du SQL fourni par l'IA.
 */

defined('ACCESS') or die('Accès interdit');

final class AssistantController
{
    private Security $security;
    private AssistantProviderInterface $provider;
    private AssistantRepository $repository;
    private AssistantImportService $importService;
    private AssistantBusinessExecutor $businessExecutor;
    private AssistantDataAnalysisService $dataAnalysisService;

    public function __construct()
    {
        $this->security = Security::getInstance();
        $this->provider = AssistantProviderFactory::create();
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantOrchestrationContract.php';
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantOrchestrator.php';
        $this->repository = new AssistantRepository();
        $this->importService = new AssistantImportService();
        $this->businessExecutor = new AssistantBusinessExecutor($this->repository);
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantDataAnalysisService.php';
        $this->dataAnalysisService = new AssistantDataAnalysisService($this->security, $this->businessExecutor);
    }

    public function upload(): void
    {
        $this->requireImportRequest();
        try {
            $conversationId = (int) ($_POST['conversation_id'] ?? 0);
            if ($conversationId === 0) {
                $conversationId = $this->repository->createConversation((int) $_SESSION['utilisateur_id']);
            }
            $result = $this->importService->uploadPreview(
                (int) $_SESSION['utilisateur_id'],
                $conversationId,
                $_FILES['file'] ?? []
            );
            $result['conversation_id'] = $conversationId;
            jsonResponse(true, 'Fichier analysé. Vérifiez l’aperçu avant de confirmer.', $result);
        } catch (InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (RuntimeException $e) {
            error_log('Assistant upload: ' . $e->getMessage());
            jsonResponse(false, $e->getMessage(), [], 400);
        } catch (Throwable $e) {
            error_log('Assistant upload unexpected: ' . $e->getMessage());
            jsonResponse(false, 'Le fichier n’a pas pu être traité.', [], 500);
        }
    }

    public function confirmImport(): void
    {
        $this->requireImportRequest();
        try {
            $result = $this->importService->confirm(
                (int) $_SESSION['utilisateur_id'],
                (int) ($_POST['import_id'] ?? 0)
            );
            jsonResponse(true, 'Import terminé.', $result);
        } catch (InvalidArgumentException | RuntimeException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (Throwable $e) {
            error_log('Assistant import: ' . $e->getMessage());
            jsonResponse(false, 'L’import n’a pas pu être terminé.', [], 500);
        }
    }

    public function cancelImport(): void
    {
        $this->requireImportRequest();
        try {
            $this->importService->cancel(
                (int) $_SESSION['utilisateur_id'],
                (int) ($_POST['import_id'] ?? 0)
            );
            jsonResponse(true, 'Aperçu supprimé.');
        } catch (InvalidArgumentException | RuntimeException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (Throwable $e) {
            error_log('Assistant cancel import: ' . $e->getMessage());
            jsonResponse(false, 'L’aperçu n’a pas pu être supprimé.', [], 500);
        }
    }

    private function requireImportRequest(): void
    {
        requireLogin();
        $this->security->requirePermission('assistant.importer');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken(csrfTokenFromRequest())) {
            jsonResponse(false, 'Requête invalide ou session expirée.', [], 419);
        }
    }

    public function history(): void
    {
        requireLogin();
        $this->security->requirePermission('assistant.consulter');
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            jsonResponse(false, 'Méthode non autorisée.', [], 405);
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $userId = (int) $_SESSION['utilisateur_id'];
        $this->repository->purgeExpiredConversations();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $conversationId = max(0, (int) ($_GET['conversation_id'] ?? 0));
        if ($conversationId > 0 && !$this->repository->conversationBelongsToUser($conversationId, $userId)) {
            jsonResponse(false, 'Conversation introuvable ou expirée.', [], 404);
        }

        $messages = $conversationId > 0
            ? $this->repository->listMessages($userId, $conversationId)
            : [];
        foreach ($messages as &$storedMessage) {
            if (($storedMessage['role'] ?? '') !== 'assistant' || !is_string($storedMessage['metadata_json'] ?? null)) {
                continue;
            }
            $metadata = json_decode($storedMessage['metadata_json'], true);
            if (is_array($metadata) && is_array($metadata['result'] ?? null)) {
                if (!isset($metadata['display'])) {
                    $metadata['display'] = AssistantDisplayContract::fromResult($metadata['result']);
                }
                $metadata['suggestions'] = $this->assistantFollowupSuggestions($metadata['result']);
                $storedMessage['metadata_json'] = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        unset($storedMessage);

        jsonResponse(true, 'Historique chargé.', [
            'conversations' => $this->repository->listConversations($userId, $page),
            'messages' => $messages,
        ]);
    }

    public function deleteConversation(): void
    {
        requireLogin();
        $this->security->requirePermission('assistant.supprimer');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken(csrfTokenFromRequest())) {
            jsonResponse(false, 'Requête invalide ou session expirée.', [], 419);
        }

        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            jsonResponse(false, 'Conversation invalide.', [], 422);
        }

        $userId = (int) $_SESSION['utilisateur_id'];
        if (!$this->repository->conversationBelongsToUser($conversationId, $userId)) {
            jsonResponse(false, 'Conversation introuvable ou expirée.', [], 404);
        }

        $this->repository->deleteConversation($conversationId, $userId);
        jsonResponse(true, 'Conversation supprimée.', ['conversation_id' => $conversationId]);
    }

    public function classify(): void
    {
        requireLogin();
        $this->security->requirePermission('assistant.consulter');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Méthode non autorisée.', [], 405);
        }

        $csrfToken = csrfTokenFromRequest();
        if (!verifyCsrfToken($csrfToken)) {
            jsonResponse(false, 'Session expirée. Veuillez rafraîchir la page.', [], 419);
        }

        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message === '') {
            jsonResponse(false, 'Veuillez saisir une question.', [], 422);
        }
        if (mb_strlen($message, 'UTF-8') > ASSISTANT_MAX_MESSAGE_LENGTH) {
            jsonResponse(false, 'La question est trop longue.', [], 422);
        }

        try {
            $userId = (int) $_SESSION['utilisateur_id'];
            $this->repository->purgeExpiredConversations();
            if (!$this->repository->consumeRateLimit($userId)) {
                jsonResponse(false, 'Limite de demandes atteinte. Veuillez réessayer plus tard.', [], 429);
            }

            $conversationId = (int) ($_POST['conversation_id'] ?? 0);
            if ($conversationId > 0 && !$this->repository->conversationBelongsToUser($conversationId, $userId)) {
                jsonResponse(false, 'Conversation introuvable ou expirée.', [], 404);
            }
            if ($conversationId === 0) {
                $conversationId = $this->repository->createConversation($userId);
            }
            $conversationContext = $this->repository->recentConversationContext($userId, $conversationId);
            $this->repository->addMessage($conversationId, 'user', $message);

            $allowedIntents = AssistantIntentCatalog::allowedFor($this->security);
            $classification = $this->provider->classify($message, $allowedIntents, $conversationContext);
            $classification = AssistantExecutionContract::normalizeClassification($classification, $allowedIntents);
            $orchestrator = new AssistantOrchestrator();
            $classification['orchestration'] = $orchestrator->buildPlan($message, $classification, $allowedIntents);
            $intent = $classification['intent'];
            $normalizedMessage = strtr(mb_strtolower($message, 'UTF-8'), [
                'à' => 'a', 'â' => 'a', 'ä' => 'a',
                'ç' => 'c',
                'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
                'î' => 'i', 'ï' => 'i',
                'ô' => 'o', 'ö' => 'o',
                'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            ]);
            $asksGenderCounts = preg_match(
                '/\b(combien|nombre|repartition)\b.*\b(garcons?|filles?)\b|\b(garcons?|filles?)\b.*\b(combien|nombre)\b/i',
                $normalizedMessage
            ) === 1;
            $asksStudentList = preg_match(
                '/\b(liste|noms?|eleves?)\b.*\b(eleves?|ecole|classe)\b|\b(eleves?)\b.*\b(liste|noms?)\b/i',
                $normalizedMessage
            ) === 1;
            $asksStudentCount = preg_match(
                '/\b(combien|nombre|effectif|total)\b.*\b(eleves?|ecole)\b/i',
                $normalizedMessage
            ) === 1;
            if ($asksStudentCount) {
                $asksStudentList = false;
            }
            $asksClassCount = preg_match(
                '/\b(combien|nombre|total)\b.*\b(classes?|classe)\b|\b(classes?|classe)\b.*\b(enregistrees?|enregistrees?|combien|nombre)\b/i',
                $normalizedMessage
            ) === 1;
            $asksSubjects = preg_match('/\b(matieres?|cours|disciplines?)\b/i', $normalizedMessage) === 1;
            $asksParents = preg_match('/\b(parents?|tuteurs?|responsables?)\b/i', $normalizedMessage) === 1;
            $asksMultiDomain = $asksSubjects && $asksParents;
            $asksPayments = preg_match('/\b(paiements?|pay[eé]s?|impay[eé]s?|frais|scolarit[eé]|solde|recouvrement)\b/i', $normalizedMessage) === 1;
            $asksAbsences = preg_match('/\b(absences?|absent|presence|présence|justifi[eé]e?s?)\b/i', $normalizedMessage) === 1;
            if (
                $asksAbsences
                && preg_match('/\b(non\s+justifi[eé]e?s?|injustifi[eé]e?s?)\b/i', $normalizedMessage) === 1
            ) {
                $classification['parameters']['statut_absence'] = 'Non justifiée';
            }
            $asksResults = preg_match('/\b(resultats?|notes?|moyennes?|reussi(?:e|es|s)?|reussite|echou(?:e|es|s)?|echec|admis|proclamation|bulletins?|points?|rang|classement|meilleur(?:e)?|premier|suivant)\b/i', $normalizedMessage) === 1;
            $classTerm = $classification['parameters']['classe'] ?? null;
            if (!is_string($classTerm) || trim($classTerm) === '') {
                if (preg_match('/\b(premiere|premier|1ere|1e|deuxieme|deuxieme|2e|troisieme|3e)\s+(annee|primaire|classe)\b/i', $normalizedMessage, $match)) {
                    $classTerm = $match[0];
                }
            }
            $periodTerm = $classification['parameters']['periode'] ?? null;
            if (!is_string($periodTerm) || trim($periodTerm) === '') {
                if (preg_match('/\b(premier|1er|1e|deuxieme|2e|troisieme|3e)\s+trimestre\b/i', $normalizedMessage, $periodMatch)) {
                    $periodTerm = $periodMatch[0];
                }
            }
            if (is_string($classTerm) && trim($classTerm) !== '') {
                $classification['parameters']['classe'] = trim($classTerm);
            }
            if (is_string($periodTerm) && trim($periodTerm) !== '') {
                $classification['parameters']['periode'] = trim($periodTerm);
            }
            $normalizedParameters = AssistantParameterNormalizer::normalize(
                $classification['parameters'],
                $message,
                $this->repository
            );
            if ($normalizedParameters['clarification'] !== '') {
                $this->repository->releaseRateLimit($userId);
                $clarification = $normalizedParameters['clarification'];
                $this->repository->addMessage(
                    $conversationId,
                    'assistant',
                    $clarification,
                    $classification['intent'],
                    ['needs_clarification' => true, 'parameters' => $classification['parameters']]
                );
                jsonResponse(true, $clarification, $this->assistantPayload(
                    AssistantExecutionContract::NEEDS_CLARIFICATION,
                    $clarification,
                    $classification,
                    [
                        'classification' => $classification,
                        'conversation_id' => $conversationId,
                        'conversation_title' => $this->repository->getConversationTitle($conversationId, $userId),
                    ]
                ));
            }
            $classification['parameters'] = $normalizedParameters['parameters'];

            if (
                $classification['status'] === AssistantExecutionContract::NEEDS_CLARIFICATION
                && !$asksGenderCounts
                && !$asksStudentList
                && !$asksClassCount
                && !$asksSubjects
                && !$asksParents
                && !$asksPayments
                && !$asksAbsences
                && !$asksResults
            ) {
                $this->repository->releaseRateLimit($userId);
                $clarification = $classification['clarification'] !== ''
                    ? $classification['clarification']
                    : 'Pouvez-vous préciser votre demande ?';
                $this->repository->addMessage(
                    $conversationId,
                    'assistant',
                    $clarification,
                    $classification['intent'],
                    ['needs_clarification' => true]
                );
                jsonResponse(true, $clarification, $this->assistantPayload(
                    AssistantExecutionContract::NEEDS_CLARIFICATION,
                    $clarification,
                    $classification,
                    [
                        'classification' => $classification,
                        'conversation_id' => $conversationId,
                        'conversation_title' => $this->repository->getConversationTitle($conversationId, $userId),
                    ]
                ));
            }

            if (
                $intent === 'inconnu'
                && (
                    (($asksGenderCounts || $asksStudentList || $asksStudentCount) && isset($allowedIntents['eleves']))
                    || ($asksClassCount && isset($allowedIntents['classes']))
                    || ($asksSubjects && isset($allowedIntents['matieres']))
                    || ($asksParents && isset($allowedIntents['parents']))
                    || ($asksPayments && isset($allowedIntents['paiements']))
                    || ($asksAbsences && isset($allowedIntents['absences']))
                    || ($asksResults && isset($allowedIntents['resultats']))
                )
            ) {
                $intent = $asksMultiDomain ? 'multi_domaine'
                    : ($asksPayments ? 'paiements'
                    : ($asksAbsences ? 'absences'
                    : ($asksResults ? 'resultats'
                    : ($asksClassCount ? 'classes'
                    : ($asksSubjects ? 'matieres' : ($asksParents ? 'parents' : 'eleves'))))));
                $classification['intent'] = $intent;
                $classification['needs_clarification'] = false;
                $classification['status'] = AssistantExecutionContract::READY;
                $classification['capability'] = array_key_first(
                    is_array($allowedIntents[$intent]['capabilities'] ?? null)
                        ? $allowedIntents[$intent]['capabilities']
                        : []
                ) ?? '';
            }

            if ($intent === 'inconnu' && !($asksSubjects || $asksParents || $asksPayments || $asksAbsences || $asksResults)) {
                $this->repository->releaseRateLimit($userId);
                $unknownMessage = $classification['clarification'] !== ''
                    ? $classification['clarification']
                    : 'Je peux actuellement consulter les élèves, les classes et certains indicateurs scolaires. '
                        . 'La demande sur les matières et les parents nécessite encore un module métier dédié. '
                        . 'Les permissions autorisent l’accès, mais elles ne remplacent pas ce module.';
                $this->repository->addMessage(
                    $conversationId,
                    'assistant',
                    $unknownMessage,
                    $intent,
                    ['needs_clarification' => $classification['needs_clarification']]
                );
                jsonResponse(true, $unknownMessage, $this->assistantPayload(
                    AssistantExecutionContract::UNSUPPORTED,
                    $unknownMessage,
                    $classification,
                    [
                    'classification' => $classification,
                    'conversation_id' => $conversationId,
                    'conversation_title' => $this->repository->getConversationTitle($conversationId, $userId),
                    ]
                ));
            }

            $permission = $allowedIntents[$intent]['permission'] ?? '';
            if ($asksPayments || $asksAbsences || $asksResults) {
                $permission = $asksPayments
                    ? 'paiements.consulter'
                    : ($asksAbsences ? 'absences.consulter' : 'resultats.consulter');
            }
            if ($asksMultiDomain) {
                if (!$this->security->can('matieres.consulter') || !$this->security->can('parents.consulter')) {
                    jsonResponse(false, 'Vous devez avoir les permissions de consulter les matières et les parents pour cette demande.', [], 403);
                }
                $classId = isset($classification['parameters']['classe_id'])
                    ? (int) $classification['parameters']['classe_id']
                    : null;
                $subjects = $this->repository->listSubjects($classId);
                $parents = $this->repository->listParents($classId);
                $assistantMessage = 'J’ai trouvé ' . count($subjects) . ' matière(s) et ' . count($parents) . ' parent(s) lié(s)'
                    . ($classTerm ? ' à la classe demandée.' : ' pour l’année scolaire active.');
                $result = ['type' => 'subjects_and_parents', 'class' => $classTerm, 'subjects' => $subjects, 'parents' => $parents];
                $this->repository->addMessage($conversationId, 'assistant', $assistantMessage, 'multi_domaine', [
                    'permission' => ['matieres.consulter', 'parents.consulter'],
                    'result' => $result,
                ]);
                jsonResponse(true, $assistantMessage, $this->assistantPayload(
                    AssistantExecutionContract::COMPLETED,
                    $assistantMessage,
                    ['intent' => 'multi_domaine', 'parameters' => $classification['parameters']],
                    [
                    'classification' => ['intent' => 'multi_domaine', 'parameters' => $classification['parameters']],
                    'permission' => ['matieres.consulter', 'parents.consulter'],
                    'conversation_id' => $conversationId,
                    'conversation_title' => $this->repository->getConversationTitle($conversationId, $userId),
                    'result' => $result,
                    ]
                ));
            }
            if ($permission === '' && ($asksSubjects || $asksParents)) {
                $permission = $asksSubjects ? 'matieres.consulter' : 'parents.consulter';
            }
            if ($permission === '' || !$this->security->can($permission)) {
                jsonResponse(false, 'Vous n’avez pas la permission de consulter ces informations.', [], 403);
            }

            if ($asksSubjects || $asksParents) {
                if ($asksSubjects && !$this->security->can('matieres.consulter')) {
                    jsonResponse(false, 'Vous n’avez pas la permission de consulter les matières.', [], 403);
                }
                if ($asksParents && !$this->security->can('parents.consulter')) {
                    jsonResponse(false, 'Vous n’avez pas la permission de consulter les parents.', [], 403);
                }
                $classId = isset($classification['parameters']['classe_id'])
                    ? (int) $classification['parameters']['classe_id']
                    : null;
                $result = ['type' => $asksSubjects ? 'subjects' : 'parents', 'class' => $classTerm];
                if ($asksSubjects) {
                    $result['items'] = $this->repository->listSubjects($classId);
                    $assistantMessage = count($result['items']) . ' matière(s) active(s)'
                        . ($classTerm ? ' pour la classe demandée.' : ' pour l’année scolaire active.');
                } else {
                    $result['items'] = $this->repository->listParents($classId);
                    $assistantMessage = count($result['items']) . ' parent(s) lié(s)'
                        . ($classTerm ? ' à la classe demandée.' : ' pour l’année scolaire active.');
                }
                $resultPermission = $asksSubjects ? 'matieres.consulter' : 'parents.consulter';
                $this->repository->addMessage($conversationId, 'assistant', $assistantMessage, $intent, [
                    'permission' => $resultPermission,
                    'result' => $result,
                ]);
                jsonResponse(true, $assistantMessage, $this->assistantPayload(
                    AssistantExecutionContract::COMPLETED,
                    $assistantMessage,
                    ['intent' => $intent, 'parameters' => $classification['parameters']],
                    [
                    'classification' => ['intent' => $intent, 'parameters' => $classification['parameters']],
                    'permission' => $resultPermission,
                    'conversation_id' => $conversationId,
                    'conversation_title' => $this->repository->getConversationTitle($conversationId, $userId),
                    'result' => $result,
                    ]
                ));
            }

            if ($asksPayments || $asksAbsences || $asksResults) {
                $domainPermission = $asksPayments
                    ? 'paiements.consulter'
                    : ($asksAbsences ? 'absences.consulter' : 'resultats.consulter');
                if (!$this->security->can($domainPermission)) {
                    jsonResponse(false, 'Vous n’avez pas la permission de consulter ces informations.', [], 403);
                }
                $businessExecution = $this->dataAnalysisService->execute(
                    $intent,
                    (string) ($classification['capability'] ?? ''),
                    $classification['parameters']
                );
                if ($businessExecution !== null) {
                    $assistantMessage = $businessExecution['message'];
                    $result = $businessExecution['result'];
                    $this->repository->addMessage($conversationId, 'assistant', $assistantMessage, $intent, [
                        'permission' => $businessExecution['permission'],
                        'capability' => $classification['capability'] ?? '',
                        'parameters' => $classification['parameters'],
                        'result' => $result,
                        'display' => AssistantDisplayContract::fromResult($result),
                    ]);
                    jsonResponse(true, $assistantMessage, $this->assistantPayload(
                        AssistantExecutionContract::COMPLETED,
                        $assistantMessage,
                        $classification,
                        [
                            'classification' => $classification,
                            'permission' => $businessExecution['permission'],
                            'conversation_id' => $conversationId,
                            'conversation_title' => $this->repository->getConversationTitle($conversationId, $userId),
                            'result' => $result,
                        ]
                    ));
                }
                $classId = isset($classification['parameters']['classe_id'])
                    ? (int) $classification['parameters']['classe_id']
                    : null;
                $result = ['type' => $asksPayments ? 'payment_summary' : ($asksAbsences ? 'absence_summary' : 'result_summary')];
                if ($asksPayments) {
                    $paymentStatus = $classification['parameters']['statut_paiement'] ?? null;
                    if (preg_match('/\b(impay[eé]s?|partiel(?:le)?s?|pay[eé]s?)\b/i', $normalizedMessage, $statusMatch)) {
                        $paymentStatus = str_contains(mb_strtolower($statusMatch[1], 'UTF-8'), 'impay')
                            ? 'Impayé'
                            : (str_contains(mb_strtolower($statusMatch[1], 'UTF-8'), 'part')
                                ? 'Partiel' : 'Payé');
                    }
                    $result['summary'] = $this->repository->paymentSummary($classId, $paymentStatus);
                    $summary = $result['summary'];
                    $assistantMessage = sprintf(
                        'Paiements : %.2f attendu, %.2f payé et %.2f restant pour %d élève(s).',
                        (float) ($summary['attendu'] ?? 0),
                        (float) ($summary['paye'] ?? 0),
                        (float) ($summary['reste'] ?? 0),
                        (int) ($summary['eleves'] ?? 0)
                    );
                } elseif ($asksAbsences) {
                    $startDate = $classification['parameters']['periode_debut'] ?? null;
                    $endDate = $classification['parameters']['periode_fin'] ?? null;
                    if (preg_match('/\bce mois|ce mois-ci|mois en cours\b/i', $normalizedMessage)) {
                        $startDate = date('Y-m-01');
                        $endDate = date('Y-m-t');
                    } elseif (preg_match('/\bcette semaine|semaine en cours\b/i', $normalizedMessage)) {
                        $startDate = date('Y-m-d', strtotime('monday this week'));
                        $endDate = date('Y-m-d', strtotime('sunday this week'));
                    }
                    $result['summary'] = $this->repository->absenceSummary($classId, $startDate, $endDate);
                    $summary = $result['summary'];
                    $assistantMessage = sprintf(
                        'Absences : %d au total, dont %d justifiée(s) et %d non justifiée(s).',
                        (int) ($summary['total'] ?? 0),
                        (int) ($summary['justifiees'] ?? 0),
                        (int) ($summary['non_justifiees'] ?? 0)
                    );
                } else {
                    $trimesterId = isset($classification['parameters']['trimestre_id'])
                        ? (int) $classification['parameters']['trimestre_id']
                        : null;
                    $decision = null;
                    if (($classification['parameters']['statut_resultat'] ?? '') === 'Réussi') {
                        $decision = 'Passe';
                    } elseif (($classification['parameters']['statut_resultat'] ?? '') === 'Échoué') {
                        $decision = '__failure__';
                    }
                    if (preg_match('/\b(admis|reussi(?:e|es|s)?|echou(?:e|es|s)?|echec)\b/i', $normalizedMessage, $decisionMatch)) {
                        $decision = preg_match('/admis|reussi/i', $decisionMatch[1]) ? 'Passe' : '__failure__';
                    }
                    $topOnly = preg_match('/\b(points?|meilleur(?:e)?|premier|classement|rang|suivant)\b/i', $normalizedMessage) === 1;
                    $result['summary'] = $this->repository->resultSummary($classId, $trimesterId, $decision, $topOnly);
                    $summary = $result['summary'];
                    $assistantMessage = sprintf(
                        'Résultats : %d élève(s), moyenne générale %.2f, %d réussi(s) et %d échoué(s).',
                        (int) ($summary['total'] ?? 0),
                        (float) ($summary['moyenne'] ?? 0),
                        (int) ($summary['reussis'] ?? 0),
                        (int) ($summary['echoues'] ?? 0)
                    );
                }
                $this->repository->addMessage($conversationId, 'assistant', $assistantMessage, $intent, [
                    'permission' => $domainPermission,
                    'result' => $result,
                ]);
                jsonResponse(true, $assistantMessage, $this->assistantPayload(
                    AssistantExecutionContract::COMPLETED,
                    $assistantMessage,
                    ['intent' => $intent, 'parameters' => $classification['parameters']],
                    [
                    'classification' => ['intent' => $intent, 'parameters' => $classification['parameters']],
                    'permission' => $domainPermission,
                    'conversation_id' => $conversationId,
                    'conversation_title' => $this->repository->getConversationTitle($conversationId, $userId),
                    'result' => $result,
                    ]
                ));
            }

            if (($asksGenderCounts || $asksStudentList || $asksStudentCount) && !$this->security->can('eleves.consulter')) {
                jsonResponse(false, 'Vous n’avez pas la permission de consulter les élèves.', [], 403);
            }

            if ($asksClassCount && !$this->security->can('classes.consulter')) {
                jsonResponse(false, 'Vous n’avez pas la permission de consulter les classes.', [], 403);
            }

            if ($asksClassCount) {
                $classCount = $this->repository->countActiveClasses();
                $assistantMessage = sprintf(
                    'Il y a actuellement %d classe%s enregistrée%s pour l’année scolaire active.',
                    $classCount,
                    $classCount === 1 ? '' : 's',
                    $classCount === 1 ? '' : 's'
                );
                $this->repository->addMessage(
                    $conversationId,
                    'assistant',
                    $assistantMessage,
                    'classes',
                    ['permission' => 'classes.consulter', 'result_type' => 'class_count', 'result' => ['count' => $classCount]]
                );
                jsonResponse(true, $assistantMessage, $this->assistantPayload(
                    AssistantExecutionContract::COMPLETED,
                    $assistantMessage,
                    ['intent' => 'classes', 'parameters' => $classification['parameters']],
                    [
                    'classification' => ['intent' => 'classes', 'parameters' => $classification['parameters']],
                    'permission' => 'classes.consulter',
                    'conversation_id' => $conversationId,
                    'conversation_title' => $this->repository->getConversationTitle($conversationId, $userId),
                    'result' => ['type' => 'class_count', 'count' => $classCount],
                    ]
                ));
            }

            if ($asksGenderCounts) {
                $counts = $this->repository->countActiveStudentsByGender();
                $assistantMessage = sprintf(
                    'Pour l’année scolaire active, il y a %d garçon%s et %d fille%s, soit %d élève%s au total.',
                    $counts['garcons'],
                    $counts['garcons'] === 1 ? '' : 's',
                    $counts['filles'],
                    $counts['filles'] === 1 ? '' : 's',
                    $counts['total'],
                    $counts['total'] === 1 ? '' : 's'
                );
                $this->repository->addMessage(
                    $conversationId,
                    'assistant',
                    $assistantMessage,
                    'eleves',
                    ['permission' => 'eleves.consulter', 'result_type' => 'student_gender_counts', 'result' => ['type' => 'student_gender_counts', 'counts' => $counts]]
                );
                jsonResponse(true, $assistantMessage, $this->assistantPayload(
                    AssistantExecutionContract::COMPLETED,
                    $assistantMessage,
                    ['intent' => 'eleves', 'parameters' => $classification['parameters']],
                    [
                    'classification' => $classification,
                    'permission' => 'eleves.consulter',
                    'conversation_id' => $conversationId,
                    'conversation_title' => $this->repository->getConversationTitle($conversationId, $userId),
                    'result' => ['type' => 'student_gender_counts', 'counts' => $counts],
                    ]
                ));
            }

            if ($asksStudentCount) {
                $studentCount = $this->repository->countActiveStudents();
                $assistantMessage = sprintf(
                    'L’école compte actuellement %d élève%s inscrit%s pour l’année scolaire active.',
                    $studentCount,
                    $studentCount === 1 ? '' : 's',
                    $studentCount === 1 ? '' : 's'
                );
                $result = ['type' => 'student_count', 'count' => $studentCount];
                $this->repository->addMessage(
                    $conversationId,
                    'assistant',
                    $assistantMessage,
                    'eleves',
                    [
                        'permission' => 'eleves.consulter',
                        'parameters' => $classification['parameters'],
                        'result' => $result,
                        'display' => AssistantDisplayContract::fromResult($result),
                    ]
                );
                jsonResponse(true, $assistantMessage, $this->assistantPayload(
                    AssistantExecutionContract::COMPLETED,
                    $assistantMessage,
                    ['intent' => 'eleves', 'parameters' => $classification['parameters']],
                    [
                        'classification' => ['intent' => 'eleves', 'parameters' => $classification['parameters']],
                        'permission' => 'eleves.consulter',
                        'conversation_id' => $conversationId,
                        'conversation_title' => $this->repository->getConversationTitle($conversationId, $userId),
                        'result' => $result,
                    ]
                ));
            }

            if ($asksStudentList) {
                $students = $this->repository->listActiveStudents();
                $lines = array_map(
                    static fn (array $student): string => '- ' . $student['prenom'] . ' ' . $student['nom']
                        . ($student['classe'] ? ' (' . $student['classe'] . ')' : ''),
                    $students
                );
                $assistantMessage = $students === []
                    ? 'Aucun élève actif n’a été trouvé pour l’année scolaire active.'
                    : "Voici les élèves inscrits pour l’année scolaire active :\n" . implode("\n", $lines);
                if (count($students) >= 100) {
                    $assistantMessage .= "\n\nLa liste est limitée aux 100 premiers élèves.";
                }
                $this->repository->addMessage(
                    $conversationId,
                    'assistant',
                    $assistantMessage,
                    'eleves',
                    ['permission' => 'eleves.consulter', 'result_type' => 'student_list', 'result' => ['type' => 'student_list', 'items' => $students]]
                );
                jsonResponse(true, $assistantMessage, $this->assistantPayload(
                    AssistantExecutionContract::COMPLETED,
                    $assistantMessage,
                    ['intent' => 'eleves', 'parameters' => $classification['parameters']],
                    [
                    'classification' => $classification,
                    'permission' => 'eleves.consulter',
                    'conversation_id' => $conversationId,
                    'conversation_title' => $this->repository->getConversationTitle($conversationId, $userId),
                    'result' => ['type' => 'student_list', 'items' => $students],
                    ]
                ));
            }

            if (
                $intent === 'rapports'
                && preg_match('/\b(combien|nombre|effectif|total)\b.*\b(élèves|eleves|élève|eleve)\b/iu', $message)
            ) {
                $studentCount = $this->repository->countActiveStudents();
                $assistantMessage = sprintf(
                    'L’école compte actuellement %d élève%s inscrit%s pour l’année scolaire active.',
                    $studentCount,
                    $studentCount === 1 ? '' : 's',
                    $studentCount === 1 ? '' : 's'
                );
                $this->repository->addMessage(
                    $conversationId,
                    'assistant',
                    $assistantMessage,
                    $intent,
                    ['permission' => $permission, 'parameters' => $classification['parameters'], 'result_type' => 'student_count', 'result' => ['type' => 'student_count', 'count' => $studentCount]]
                );
                jsonResponse(true, $assistantMessage, $this->assistantPayload(
                    AssistantExecutionContract::COMPLETED,
                    $assistantMessage,
                    $classification,
                    [
                    'classification' => $classification,
                    'permission' => $permission,
                    'conversation_id' => $conversationId,
                    'conversation_title' => $this->repository->getConversationTitle($conversationId, $userId),
                    'result' => ['type' => 'student_count', 'count' => $studentCount],
                    ]
                ));
            }

            $this->repository->addMessage(
                $conversationId,
                'assistant',
                'Demande reconnue. La recherche sécurisée sera exécutée par le module métier.',
                $intent,
                ['permission' => $permission, 'parameters' => $classification['parameters']]
            );
            jsonResponse(true, 'Demande reconnue. La recherche sécurisée sera exécutée par le module métier.', $this->assistantPayload(
                AssistantExecutionContract::READY,
                'Demande reconnue. La recherche sécurisée sera exécutée par le module métier.',
                $classification,
                [
                'classification' => $classification,
                'permission' => $permission,
                'conversation_id' => $conversationId,
                'conversation_title' => $this->repository->getConversationTitle($conversationId, $userId),
                ]
            ));
        } catch (InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage(), AssistantExecutionContract::response(
                AssistantExecutionContract::UNSUPPORTED,
                'Les paramètres de la demande sont invalides.'
            ), 422);
        } catch (DomainException $e) {
            jsonResponse(false, 'Vous n’avez pas la permission d’utiliser cette capacité.', AssistantExecutionContract::response(
                AssistantExecutionContract::UNSUPPORTED,
                'Permission refusée.'
            ), 403);
        } catch (RuntimeException $e) {
            error_log('Assistant classification: ' . $e->getMessage());
            $message = 'Le service de l’assistant est momentanément indisponible.';
            jsonResponse(false, $message, AssistantExecutionContract::response(
                AssistantExecutionContract::EXECUTION_FAILED,
                $message
            ), 503);
        } catch (JsonException $e) {
            error_log('Assistant JSON: ' . $e->getMessage());
            jsonResponse(false, 'La demande n’a pas pu être traitée.', AssistantExecutionContract::response(
                AssistantExecutionContract::EXECUTION_FAILED,
                'La demande n’a pas pu être traitée.'
            ), 502);
        } catch (PDOException $e) {
            error_log('Assistant database: ' . $e->getMessage());
            jsonResponse(false, 'Le service de données est momentanément indisponible.', AssistantExecutionContract::response(
                AssistantExecutionContract::EXECUTION_FAILED,
                'Le service de données est momentanément indisponible.'
            ), 503);
        }
    }

    /**
     * Ajoute le contrat standard sans supprimer les champs historiques consommés
     * par le frontend existant.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $legacyPayload
     * @return array<string, mixed>
     */
    private function assistantPayload(
        string $status,
        string $message,
        array $classification,
        array $legacyPayload
    ): array {
        $result = is_array($legacyPayload['result'] ?? null) ? $legacyPayload['result'] : [];
        $intent = (string) ($classification['intent'] ?? 'inconnu');
        $capability = (string) ($classification['capability'] ?? '');
        if ($capability === '' && class_exists('AssistantCapabilityRegistry')) {
            $capability = array_key_first(AssistantCapabilityRegistry::forIntent($intent)) ?? '';
        }
        $parameters = is_array($classification['parameters'] ?? null) ? $classification['parameters'] : [];
        if ($result !== [] && !isset($legacyPayload['display'])) {
            $legacyPayload['display'] = AssistantDisplayContract::fromResult($result);
        }
        if ($result !== [] && !isset($legacyPayload['suggestions'])) {
            $legacyPayload['suggestions'] = $this->assistantFollowupSuggestions($result);
        }

        return array_merge(
            AssistantExecutionContract::response(
                $status,
                $message,
                $intent,
                $capability,
                $parameters,
                $result
            ),
            $legacyPayload,
            [
                'quota' => $this->repository->rateLimitStatus((int) $_SESSION['utilisateur_id']),
            ]
        );
    }

    /**
     * Suggestions limitées aux capacités métier connues et autorisées.
     * Elles restent des questions de suivi : le backend revalide chaque nouvelle demande.
     *
     * @param array<string,mixed> $result
     * @return array<int,array{label:string,prompt:string}>
     */
    private function assistantFollowupSuggestions(array $result): array
    {
        $type = (string) ($result['type'] ?? '');
        $options = match ($type) {
            'payment_summary' => [
                ['permission' => 'paiements.consulter', 'label' => 'Voir les élèves avec un solde', 'prompt' => 'Liste les élèves qui ont encore un solde impayé pour ces filtres.'],
            ],
            'absence_summary' => [
                ['permission' => 'absences.consulter', 'label' => 'Détailler les absences', 'prompt' => 'Affiche la liste des absences pour ces mêmes filtres.'],
            ],
            'result_summary' => [
                ['permission' => 'resultats.consulter', 'label' => 'Voir les meilleurs élèves', 'prompt' => 'Affiche les meilleurs élèves pour cette même période et cette même classe.'],
                ['permission' => 'resultats.consulter', 'label' => 'Voir les élèves en difficulté', 'prompt' => 'Liste les élèves en échec pour cette même période et cette même classe.'],
            ],
            'student_count' => [
                ['permission' => 'eleves.consulter', 'label' => 'Répartition par genre', 'prompt' => 'Donne la répartition des élèves par genre pour les mêmes filtres.'],
                ['permission' => 'eleves.consulter', 'label' => 'Consulter la liste', 'prompt' => 'Affiche la liste des élèves correspondant aux mêmes filtres.'],
            ],
            'class_count' => [
                ['permission' => 'classes.consulter', 'label' => 'Voir les classes', 'prompt' => 'Liste les classes de la même année scolaire.'],
            ],
            default => [],
        };

        $allowed = [];
        foreach ($options as $option) {
            if ($this->security->can($option['permission'])) {
                $allowed[] = ['label' => $option['label'], 'prompt' => $option['prompt']];
            }
        }
        return array_slice($allowed, 0, 3);
    }
}
