<?php

defined('ACCESS') or die('Accès interdit');

require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantSecurityActionService.php';

final class AssistantSecurityActionController
{
    private Security $security;
    private AssistantSecurityActionService $actions;

    public function __construct()
    {
        $this->security = Security::getInstance();
        $this->actions = new AssistantSecurityActionService();
    }

    public function audit(): void
    {
        requireLogin();
        $this->security->requirePermission('assistant.security.audit');
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            jsonResponse(false, 'Méthode non autorisée.', [], 405);
        }
        jsonResponse(true, 'Audit du registre des capacités effectué.', [
            'audit' => $this->actions->auditCapabilities(),
        ]);
    }

    public function proposeNote(): void
    {
        requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken(csrfTokenFromRequest())) {
            jsonResponse(false, 'Requête invalide ou session expirée.', [], 419);
        }
        try {
            $evaluationId = filter_var($_POST['evaluation_id'] ?? null, FILTER_VALIDATE_INT);
            $studentId = filter_var($_POST['eleve_id'] ?? null, FILTER_VALIDATE_INT);
            $subjectId = filter_var($_POST['sous_matiere_id'] ?? null, FILTER_VALIDATE_INT);
            $raw = $_POST['valeur'] ?? null;
            if ($raw !== null && !is_string($raw)) {
                throw new InvalidArgumentException('Valeur de note invalide.');
            }
            $result = $this->actions->proposeNote(
                (int) $evaluationId,
                (int) $studentId,
                (int) $subjectId,
                $raw === '' ? null : $raw
            );
            jsonResponse(true, 'Action préparée. Aucune note n’a encore été modifiée. Confirmez dans le délai indiqué.', $result, 201);
        } catch (InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (DomainException $e) {
            jsonResponse(false, $e->getMessage(), [], 403);
        } catch (Throwable $e) {
            error_log('Assistant action proposal: ' . $e->getMessage());
            jsonResponse(false, 'La demande d’action n’a pas pu être préparée.', [], 500);
        }
    }

    public function confirmNote(): void
    {
        requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken(csrfTokenFromRequest())) {
            jsonResponse(false, 'Requête invalide ou session expirée.', [], 419);
        }
        try {
            $id = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
            $token = $_POST['confirmation_token'] ?? '';
            if ($id === false || $id === null || !is_string($token)) {
                throw new InvalidArgumentException('Demande de confirmation invalide.');
            }
            $result = $this->actions->confirmNote((int) $id, $token);
            jsonResponse(true, 'Action confirmée et exécutée par le service métier.', $result);
        } catch (InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (DomainException $e) {
            jsonResponse(false, $e->getMessage(), [], 409);
        } catch (RuntimeException $e) {
            jsonResponse(false, 'Demande introuvable ou non autorisée.', [], 404);
        } catch (Throwable $e) {
            error_log('Assistant action confirmation: ' . $e->getMessage());
            jsonResponse(false, 'L’action n’a pas pu être exécutée.', [], 500);
        }
    }
}
