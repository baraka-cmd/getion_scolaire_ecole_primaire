<?php

defined('ACCESS') or die('Accès interdit');

require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantDocumentKnowledgeService.php';

/**
 * Endpoints du pipeline documentaire. Les extraits sont retournés au backend
 * uniquement après les contrôles d'accès; aucune source privée n'est exposée
 * par une requête de recherche non autorisée.
 */
final class AssistantKnowledgeController
{
    private Security $security;
    private AssistantDocumentKnowledgeService $knowledge;

    public function __construct()
    {
        $this->security = Security::getInstance();
        $this->knowledge = new AssistantDocumentKnowledgeService();
    }

    public function index(): void
    {
        requireLogin();
        $this->security->requirePermission('documents.creer');
        if (!hasRole(['Admin', 'Directeur'])) {
            jsonResponse(false, 'Seule l’administration peut indexer les documents de référence.', [], 403);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken(csrfTokenFromRequest())) {
            jsonResponse(false, 'Requête invalide ou session expirée.', [], 419);
        }
        try {
            $documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT);
            if ($documentId === false || $documentId === null || $documentId < 1) {
                throw new InvalidArgumentException('Identifiant de document invalide.');
            }
            $result = $this->knowledge->indexDocument((int) $documentId);
            jsonResponse(true, 'Document textuel indexé.', $result);
        } catch (InvalidArgumentException | DomainException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (RuntimeException $e) {
            jsonResponse(false, $e->getMessage(), [], 404);
        } catch (Throwable $e) {
            error_log('Assistant knowledge indexing: ' . $e->getMessage());
            jsonResponse(false, 'L’indexation documentaire a échoué.', [], 500);
        }
    }

    public function search(): void
    {
        requireLogin();
        $this->security->requirePermission('assistant.knowledge.consulter');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken(csrfTokenFromRequest())) {
            jsonResponse(false, 'Requête invalide ou session expirée.', [], 419);
        }
        try {
            $query = trim((string) ($_POST['question'] ?? ''));
            if ($query === '' || mb_strlen($query, 'UTF-8') > 1000) {
                throw new InvalidArgumentException('La question doit contenir entre 1 et 1 000 caractères.');
            }
            $sources = $this->knowledge->searchForUser(
                (int) $_SESSION['utilisateur_id'],
                $query,
                hasRole(['Admin', 'Directeur'])
            );
            jsonResponse(true, $sources === [] ? 'Aucun passage documentaire pertinent indexé.' : 'Passages documentaires récupérés.', [
                'query' => $query,
                'sources' => $sources,
                'source_count' => count($sources),
                'grounded' => $sources !== [],
            ]);
        } catch (InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (Throwable $e) {
            error_log('Assistant knowledge search: ' . $e->getMessage());
            jsonResponse(false, 'La recherche documentaire est momentanément indisponible.', [], 503);
        }
    }
}
