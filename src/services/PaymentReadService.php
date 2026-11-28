<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../repositories/PaymentReadRepository.php';

/** Prépare les modèles de lecture des pages financières. */
class PaymentReadService
{
    private PaymentReadRepository $repository;
    public function __construct(?PaymentReadRepository $repository = null) { $this->repository = $repository ?? new PaymentReadRepository(); }
    public function getPageData(string $page, array $query): array
    {
        $activeYear = $this->repository->activeYearId();
        $years = $this->repository->schoolYears();
        $yearId = (int) ($query['annee_id'] ?? $activeYear);
        if (!$this->repository->yearExists($yearId)) $yearId = $activeYear;
        $data = ['anneeActiveId' => $activeYear, 'annees' => $years, 'anneeId' => $yearId, 'classes' => $this->repository->activeClasses($yearId)];
        if ($page === 'liste') $data += $this->repository->paymentListStats($yearId);
        elseif ($page === 'impayes') $data += $this->repository->unpaidListStats($yearId);
        elseif ($page === 'frais') {
            $data['frais'] = $this->repository->feesForYear($yearId);
            $data['classes'] = $this->repository->feeTotals($yearId);
            $data['totalFrais'] = count($data['frais']);
            $data['totalEncaisseUsd'] = array_sum(array_column($data['frais'], 'encaisse_usd'));
            $data['totalEncaisseCdf'] = array_sum(array_column($data['frais'], 'encaisse_cdf'));
        } else throw new InvalidArgumentException('Page financière non prise en charge.');
        return $data;
    }
public function className(int $classId): ?string { return $this->repository->className($classId); }
}