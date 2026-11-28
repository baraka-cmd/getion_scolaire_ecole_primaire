<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../services/PaymentReadService.php';
class PaymentReadController
{
    private PaymentReadService $service;
    public function __construct(?PaymentReadService $service = null) { $this->service = $service ?? new PaymentReadService(); }
    public function getViewData(string $page, array $query): array { return $this->service->getPageData($page, $query); }
public function getClassName(int $classId): ?string { return $this->service->className($classId); }
}