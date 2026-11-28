<?php

defined('ACCESS') or die('Accès interdit');

require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantSecureActionService.php';

final class AssistantSecureActionController
{
    private AssistantSecureActionService $actions;

    public function __construct()
    {
        $this->actions = new AssistantSecureActionService();
    }

    public function prepareGrade(): void
    {
        requireLogin();
        requirePermission('notes.saisir');
        $this->requirePostCsrf();
        try {
            $evaluationId = filter_var($_POST['evaluation_id'] ?? null, FILTER_VALIDATE_INT);
            $studentId = filter_var($_POST['eleve_id'] ?? null, FILTER_VALIDATE_INT);
            $subjectId = filter_var($_POST['sous_matiere_id'] ?? null, FILTER_VALIDATE_INT);
            if ($evaluationId === false || $studentId === false || $subjectId === false) {
                throw new InvalidArgumentException('Identifiants de note invalides.');
            }
            $result = $this->actions->prepareGrade(
                (int) $_SESSION['utilisateur_id'],
                (int) $evaluationId,
                (int) $studentId,
                (int) $subjectId,
                $_POST['valeur'] ?? null,
                $_POST['absence'] ?? 0
            );
            jsonResponse(true, 'Proposition préparée. Aucun changement n’a encore été effectué. Confirmez explicitement pour continuer.', $result, 201);
        } catch (InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (DomainException $e) {
            jsonResponse(false, $e->getMessage(), [], 403);
        } catch (Throwable $e) {
            error_log('Assistant action prepare: ' . $e->getMessage());
            jsonResponse(false, 'La proposition d’action n’a pas pu être préparée.', [], 500);
        }
    }

    public function confirmGrade(): void
    {
        requireLogin();
        requirePermission('assistant.actions.confirmer');
        requirePermission('notes.saisir');
        $this->requirePostCsrf();
        try {
            $actionId = filter_var($_POST['action_id'] ?? null, FILTER_VALIDATE_INT);
            $token = $_POST['confirmation_token'] ?? '';
            if ($actionId === false || !is_string($token)) {
                throw new InvalidArgumentException('Données de confirmation invalides.');
            }
            $result = $this->actions->confirmGrade((int) $_SESSION['utilisateur_id'], (int) $actionId, $token);
            jsonResponse(true, $result['message'], $result);
        } catch (InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (DomainException $e) {
            jsonResponse(false, $e->getMessage(), [], 409);
        } catch (Throwable $e) {
            error_log('Assistant action confirm: ' . $e->getMessage());
            jsonResponse(false, 'L’action n’a pas pu être confirmée ou exécutée.', [], 500);
        }
    }

    private function requirePostCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken(csrfTokenFromRequest())) {
            jsonResponse(false, 'Requête invalide ou session expirée.', [], 419);
        }
    }
}
