<?php

defined('ACCESS') or die('Accès interdit');

/**
 * Exécute les capacités de lecture avec des données calculées par PHP/SQL.
 */
final class AssistantBusinessExecutor
{
    private AssistantRepository $repository;

    public function __construct(AssistantRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{result: array<string, mixed>, message: string, permission: string}|null
     */
    public function execute(string $capability, array $parameters): ?array
    {
        $classId = isset($parameters['classe_id']) ? (int) $parameters['classe_id'] : null;
        $yearId = isset($parameters['annee_scolaire_id']) ? (int) $parameters['annee_scolaire_id'] : null;

        return match ($capability) {
            'payments.summary' => $this->paymentSummary($classId, $yearId, $parameters['statut_paiement'] ?? null),
            'payments.unpaid_students' => $this->unpaidStudents($classId, $yearId),
            'attendance.summary' => $this->attendanceSummary(
                $classId,
                $yearId,
                $parameters['periode_debut'] ?? null,
                $parameters['periode_fin'] ?? null
            ),
            'attendance.list' => $this->attendanceList(
                $classId,
                $yearId,
                $parameters['periode_debut'] ?? null,
                $parameters['periode_fin'] ?? null,
                $parameters['statut_absence'] ?? null,
                $parameters['limite'] ?? 100
            ),
            'results.summary' => $this->resultSummary(
                $classId,
                $yearId,
                $parameters['trimestre_id'] ?? null,
                $parameters['statut_resultat'] ?? null,
                false
            ),
            'results.top_students' => $this->resultSummary(
                $classId,
                $yearId,
                $parameters['trimestre_id'] ?? null,
                null,
                true
            ),
            'results.failed_students' => $this->resultSummary(
                $classId,
                $yearId,
                $parameters['trimestre_id'] ?? null,
                'Échoué',
                false
            ),
            'results.proclamation' => $this->proclamation(
                $classId,
                $yearId,
                $parameters['trimestre_id'] ?? null
            ),
            default => null,
        };
    }

    /** @return array{result: array<string, mixed>, message: string, permission: string} */
    private function paymentSummary(?int $classId, ?int $yearId, ?string $status): array
    {
        $result = ['type' => 'payment_summary', 'summary' => $this->repository->paymentSummary($classId, $status, $yearId)];
        $summary = $result['summary'];
        return [
            'result' => $result,
            'message' => AssistantResponseWriter::write($result),
            'permission' => 'paiements.consulter',
        ];
    }

    /** @return array{result: array<string, mixed>, message: string, permission: string} */
    private function unpaidStudents(?int $classId, ?int $yearId): array
    {
        $items = $this->repository->listUnpaidStudents($classId, $yearId);
        return [
            'result' => ['type' => 'payment_students', 'items' => $items],
            'message' => AssistantResponseWriter::write(['type' => 'payment_students', 'items' => $items]),
            'permission' => 'paiements.consulter',
        ];
    }

    /** @return array{result: array<string, mixed>, message: string, permission: string} */
    private function attendanceSummary(?int $classId, ?int $yearId, ?string $start, ?string $end): array
    {
        $result = ['type' => 'absence_summary', 'summary' => $this->repository->absenceSummary($classId, $start, $end, $yearId)];
        $summary = $result['summary'];
        return [
            'result' => $result,
            'message' => AssistantResponseWriter::write($result),
            'permission' => 'absences.consulter',
        ];
    }

    /** @return array{result: array<string, mixed>, message: string, permission: string} */
    private function attendanceList(
        ?int $classId,
        ?int $yearId,
        ?string $start,
        ?string $end,
        ?string $status,
        mixed $limit
    ): array {
        $items = $this->repository->listAbsences($classId, $start, $end, $status, $yearId, (int) $limit);
        return [
            'result' => ['type' => 'absence_list', 'items' => $items],
            'message' => AssistantResponseWriter::write(['type' => 'absence_list', 'items' => $items]),
            'permission' => 'absences.consulter',
        ];
    }

    /** @return array{result: array<string, mixed>, message: string, permission: string} */
    private function resultSummary(
        ?int $classId,
        ?int $yearId,
        ?int $trimesterId,
        ?string $status,
        bool $topOnly
    ): array {
        $decision = $status === 'Réussi' ? 'Passe' : ($status === 'Échoué' ? '__failure__' : null);
        $result = ['type' => $topOnly ? 'result_ranking' : 'result_summary'];
        $result['summary'] = $this->repository->resultSummary($classId, $trimesterId, $decision, $topOnly, $yearId);
        $summary = $result['summary'];
        $message = AssistantResponseWriter::write($result);
        if ($status === 'Échoué' && $message === '') {
            $message = count($summary['items'] ?? []) . ' élève(s) ayant échoué ont été trouvé(s).';
        }
        return ['result' => $result, 'message' => $message, 'permission' => 'resultats.consulter'];
    }

    /** @return array{result: array<string, mixed>, message: string, permission: string} */
    private function proclamation(?int $classId, ?int $yearId, ?int $trimesterId): array
    {
        $result = [
            'type' => 'proclamation',
            'summary' => $this->repository->resultSummary($classId, $trimesterId, null, false, $yearId),
        ];

        return [
            'result' => $result,
            'message' => AssistantResponseWriter::write($result),
            'permission' => 'resultats.consulter',
        ];
    }
}
