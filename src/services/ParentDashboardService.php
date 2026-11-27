<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../repositories/ParentDashboardRepository.php';

class ParentDashboardService
{
    private ParentDashboardRepository $repository;
    public function __construct(?ParentDashboardRepository $repository = null)
    {
        $this->repository = $repository ?? new ParentDashboardRepository();
    }

    public function getDashboardData(int $userId): array
    {
        $parent = $this->repository->findParentByUser($userId);
        $parentId = (int) ($parent['id'] ?? 0);
        $termId = $this->repository->activeTermId();
        $children = [];
        if ($parentId > 0) {
            $children = $this->repository->childrenForParent($parentId);
            foreach ($children as &$child) {
                // IDs come from the parent-filtered repository result, never from client input.
                $studentId = (int) $child['id'];
                $child['moyenne'] = $this->repository->childLatestAverage($studentId);
                $child['absences'] = $this->repository->childAbsenceCount($studentId, $termId);
                $child['retards'] = $this->repository->childLateCount($studentId, $termId);
                $child['paiement_du'] = $this->repository->childOutstandingPayment($studentId);
            }
            unset($child);
        }

        return [
            'parentInfo' => $parent,
            'parentId' => $parentId,
            'mesEnfants' => $children,
            'trimestreActif' => $termId,
            'prochainsEvenements' => $this->repository->upcomingEvents(),
            'messagesNonLus' => $this->repository->unreadMessages($userId),
            'notifsNonLues' => $this->repository->unreadNotifications($userId),
        ];
    }
}
