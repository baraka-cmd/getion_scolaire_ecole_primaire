<?php
declare(strict_types=1);
$viewPaths = [
 'public/views/modules/paiements/liste.php',
 'public/views/modules/paiements/impayes.php',
 'public/views/modules/frais/liste.php',
];
foreach ($viewPaths as $path) {
 $source = file_get_contents(__DIR__ . '/../' . $path);
 if (!is_string($source)) throw new RuntimeException("Unreadable view: {$path}");
 if (preg_match('/\$db\s*->\s*(?:fetchAll|fetchOne|fetchColumn|insert|update|delete)\s*\(/', $source)) {
  throw new RuntimeException("Direct database access remains in financial view: {$path}");
 }
 if (!str_contains($source, 'PaymentReadController') || !str_contains($source, 'getViewData')) {
  throw new RuntimeException("Financial view does not delegate read model: {$path}");
 }
}
$repository = file_get_contents(__DIR__ . '/../src/repositories/PaymentReadRepository.php');
$service = file_get_contents(__DIR__ . '/../src/services/PaymentReadService.php');
$controller = file_get_contents(__DIR__ . '/../src/controllers/PaymentReadController.php');
foreach (['paymentListStats','unpaidListStats','feesForYear','yearExists','schoolYears'] as $method) {
 if (!str_contains($repository, 'function ' . $method)) throw new RuntimeException("Repository query method missing: {$method}");
}
if (!str_contains($service, 'PaymentReadRepository') || !str_contains($controller, 'PaymentReadService')) {
 throw new RuntimeException('Financial read MVC dependency chain is incomplete.');
}
foreach (['finance_annulations','finance_affectations','GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0)','devise_recue'] as $invariant) {
 if (!str_contains($repository, $invariant)) throw new RuntimeException("Finance query invariant missing: {$invariant}");
}
echo "Financial views MVC read-model smoke: OK\n";
