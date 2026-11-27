<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../repositories/AccountantDashboardRepository.php';

class AccountantDashboardService
{
    private AccountantDashboardRepository $repository;
    public function __construct(?AccountantDashboardRepository $repository = null) { $this->repository = $repository ?? new AccountantDashboardRepository(); }
    public function getDashboardData(): array
    {
        $yearId = $this->repository->activeYearId();
        $monthly = $this->repository->monthlyReceipts($yearId);
        $monthlyByKey = [];
        foreach ($monthly as $row) $monthlyByKey[$row['mois']] = (float) $row['equivalent_usd'];
        $chartRows = [];
        $cursor = new DateTimeImmutable('first day of this month');
        for ($offset = 5; $offset >= 0; $offset--) {
            $month = $cursor->modify('-' . $offset . ' months');
            $key = $month->format('Y-m');
            $chartRows[] = ['label' => $month->format('m/Y'), 'amount' => round($monthlyByKey[$key] ?? 0, 2)];
        }
        $studentStateRows = $this->repository->studentDebtStates($yearId);
        $states = ['soldee' => 0, 'partielle' => 0, 'impayee' => 0];
        foreach ($studentStateRows as $state) {
            if (isset($states[$state['etat']])) $states[$state['etat']] = (int) $state['nombre'];
        }
        return [
            'anneeActive' => $yearId,
            'anneeLibelle' => $this->repository->yearLabel($yearId),
            'soldes' => $this->repository->balances($yearId),
            'receiptsMonth' => $this->repository->receiptsThisMonth($yearId),
            'nbEcheancesRetard' => $this->repository->overdueReceivables($yearId),
            'suivisAVenir' => $this->repository->scheduledFollowups($yearId),
            'monthlyReceipts' => $monthly,
            'monthlyChartRows' => $chartRows,
            'studentStateCounts' => $states,
            'classBalances' => $this->repository->classBalances($yearId),
            'studentStates' => $studentStateRows,
            'derniersVersements' => $this->repository->latestReceipts($yearId),
        ];
    }
}