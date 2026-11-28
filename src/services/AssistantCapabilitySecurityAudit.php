<?php

defined('ACCESS') or die('Accès interdit');

/** Vérification en lecture seule du contrat permission/capacité de l'assistant. */
final class AssistantSecurityAuditService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Compare le registre applicatif à la table permissions sans modifier les rôles.
     * @return array{capability_count:int,missing_permissions:array<int,string>,invalid_definitions:array<int,string>}
     */
    public function auditCapabilityRegistry(): array
    {
        $registry = AssistantCapabilityRegistry::all();
        $rows = $this->db->fetchAll('SELECT code FROM permissions');
        $existing = array_fill_keys(array_map('strval', array_column($rows, 'code')), true);
        $missing = [];
        $invalid = [];
        foreach ($registry as $code => $definition) {
            $permission = (string) ($definition['permission'] ?? '');
            if ($permission === '' || !isset($existing[$permission])) {
                $missing[] = $permission !== '' ? $permission : '(permission vide pour ' . $code . ')';
            }
            if (trim((string) ($definition['intent'] ?? '')) === ''
                || trim((string) ($definition['description'] ?? '')) === ''
                || trim((string) ($definition['output'] ?? '')) === '') {
                $invalid[] = (string) $code;
            }
        }
        return [
            'capability_count' => count($registry),
            'missing_permissions' => array_values(array_unique($missing)),
            'invalid_definitions' => $invalid,
        ];
    }
}
