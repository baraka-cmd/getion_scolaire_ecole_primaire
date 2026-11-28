<?php

defined('ACCESS') or die('Accès interdit');

require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantActionGuardService.php';

final class AssistantActionController
{
    private AssistantActionGuardService $guard;

    public function __construct()
    {
        $this->guard = new AssistantActionGuardService();
    }

    public function prepare(): void
    {
        requireLogin();
        requirePermission('assistant.actions.confirmer');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken(csrfTokenFromRequest())) {
            jsonResponse(false, 'Requête invalide ou session expirée.', [], 419);
        }
        try {
            $payload = json_decode((string) ($_POST['payload'] ?? ''), true);
            if (!is_array($payload)) {
                throw new InvalidArgumentException('Paramètres de l’action invalides.');
            }
            $result = $this->guard->prepare(
                (int) $_SESSION['utilisateur_id'],
                (int) ($_POST['conversation_id'] ?? 0),
                trim((string) ($_POST['action_code'] ?? '')),
                $payload
            );
            jsonResponse(true, 'Action préparée pour confirmation.', $result);
        } catch (InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (RuntimeException $e) {
            jsonResponse(false, $e->getMessage(), [], 403);
        } catch (Throwable $e) {
            error_log('Assistant action prepare: ' . $e->getMessage());
            jsonResponse(false, 'La proposition d’action n’a pas pu être préparée.', [], 500);
        }
    }

    public function confirm(): void
    {
        requireLogin();
        requirePermission('assistant.actions.confirmer');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken(csrfTokenFromRequest())) {
            jsonResponse(false, 'Requête invalide ou session expirée.', [], 419);
        }
        try {
            $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
            if ($requestId === false || $requestId === null || $requestId < 1) {
                throw new InvalidArgumentException('Identifiant de proposition invalide.');
            }
            $result = $this->guard->confirm(
                (int) $_SESSION['utilisateur_id'],
                (int) $requestId,
                (string) ($_POST['confirmation'] ?? '')
            );
            jsonResponse(true, 'Confirmation vérifiée.', $result);
        } catch (InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (RuntimeException $e) {
            jsonResponse(false, $e->getMessage(), [], 403);
        } catch (Throwable $e) {
            error_log('Assistant action confirm: ' . $e->getMessage());
            jsonResponse(false, 'La confirmation n’a pas pu être enregistrée.', [], 500);
        }
    }
}
