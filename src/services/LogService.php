<?php
defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/LogRepository.php';

class LogService
{
    private LogRepository $repository;

    public function __construct(?LogRepository $repository = null)
    {
        $this->repository = $repository ?? new LogRepository();
    }

    public function listFiltered(string $where, array $params, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $total = $this->repository->countFiltered($where, $params);
        return [
            'logs' => $this->repository->listFiltered($where, $params, $perPage, ($page - 1) * $perPage),
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $perPage),
        ];
    }

    public function exportFiltered(string $where, array $params): array
    {
        return $this->repository->exportFiltered($where, $params);
    }

    public function statistics(): array { return $this->repository->statistics(); }
    public function topActions(): array { return $this->repository->topActions(); }
    public function topUsers(): array { return $this->repository->topUsers(); }

    public function activityLastSevenDays(): array
    {
        $activity = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $activity[] = [
                'date' => $date,
                'label' => date('d/m', strtotime($date)),
                'jour' => jourEnFrancais($date),
                'nb' => $this->repository->countOnDate($date),
            ];
        }
        return $activity;
    }

    public function activeUsers(): array { return $this->repository->activeUsers(); }
    public function distinctActions(): array { return array_column($this->repository->distinctActions(), 'action'); }
    public function distinctTables(): array { return array_column($this->repository->distinctTables(), 'table_concernee'); }
}
