<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class DocumentRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function paginateVisible(array $filters, int $page, int $perPage, bool $admin): array
    {
        $where = [];
        $params = [];
        if (!$admin) {
            $where[] = 'd.est_public = 1';
        }

        $category = (string) ($filters['categorie'] ?? '');
        if ($category !== '') {
            $where[] = $category === 'Autre'
                ? "(d.type_document = :cat OR d.type_document = '')"
                : 'd.type_document = :cat';
            $params['cat'] = $category;
        }

        $visibility = $filters['visibilite'] ?? '';
        if ($visibility !== '' && $admin) {
            $where[] = 'd.est_public = :vis';
            $params['vis'] = (int) $visibility;
        }

        $search = (string) ($filters['recherche'] ?? '');
        if ($search !== '') {
            $where[] = '(d.titre LIKE :r1 OR d.description LIKE :r2 OR d.nom_fichier LIKE :r3 OR d.extension LIKE :r4)';
            $params['r1'] = "%{$search}%";
            $params['r2'] = "%{$search}%";
            $params['r3'] = "%{$search}%";
            $params['r4'] = "%{$search}%";
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM documents d {$whereClause}",
            $params
        );
        $offset = ($page - 1) * $perPage;
        $documents = $this->db->fetchAll(
            "SELECT d.*,
                    DATE_FORMAT(d.created_at, '%d/%m/%Y') AS date_format,
                    TIME_FORMAT(d.created_at, '%H:%i') AS heure_format,
                    CONCAT(u.prenom, ' ', u.nom) AS upload_par_nom,
                    ROUND(d.taille_fichier / 1024, 1) AS taille_ko,
                    CASE
                        WHEN d.taille_fichier > 1048576 THEN CONCAT(ROUND(d.taille_fichier / 1048576, 1), ' Mo')
                        WHEN d.taille_fichier > 1024 THEN CONCAT(ROUND(d.taille_fichier / 1024, 1), ' Ko')
                        ELSE CONCAT(d.taille_fichier, ' o')
                    END AS taille_formatee,
                    d.extension AS type_fichier,
                    d.nom_fichier AS nom_original,
                    COALESCE(NULLIF(d.type_document, ''), 'Autre') AS categorie,
                    0 AS nb_telechargements
             FROM documents d
             LEFT JOIN utilisateurs u ON d.utilisateur_id = u.id
             {$whereClause}
             ORDER BY d.created_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        $statsWhere = $admin ? '' : 'WHERE est_public = 1';
        $totalFiles = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM documents {$statsWhere}");
        $totalSize = (int) $this->db->fetchColumn("SELECT COALESCE(SUM(taille_fichier), 0) FROM documents {$statsWhere}");
        $byCategory = $this->db->fetchAll(
            "SELECT COALESCE(NULLIF(type_document, ''), 'Autre') AS categorie, COUNT(*) AS nb
             FROM documents {$statsWhere}
             GROUP BY COALESCE(NULLIF(type_document, ''), 'Autre')
             ORDER BY nb DESC"
        );

        return [
            'documents' => $documents,
            'total' => $total,
            'total_fichiers' => $totalFiles,
            'taille_totale_octets' => $totalSize,
            'par_categorie' => $byCategory,
        ];
    }

    public function findMetadata(int $documentId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, nom_fichier, chemin_fichier, est_public FROM documents WHERE id = :id',
            ['id' => $documentId]
        );
    }

    public function create(array $data): int
    {
        return (int) $this->db->insert(
            'INSERT INTO documents
                (titre, description, type_document, nom_fichier, chemin_fichier,
                 taille_fichier, extension, est_public, utilisateur_id, created_at)
             VALUES (:titre, :description, :type_document, :nom_fichier, :chemin_fichier,
                     :taille_fichier, :extension, :est_public, :utilisateur_id, NOW())',
            [
                'titre' => $data['titre'],
                'description' => $data['description'],
                'type_document' => $data['type_document'],
                'nom_fichier' => $data['nom_fichier'],
                'chemin_fichier' => $data['chemin_fichier'],
                'taille_fichier' => $data['taille_fichier'],
                'extension' => $data['extension'],
                'est_public' => $data['est_public'],
                'utilisateur_id' => $data['utilisateur_id'],
            ]
        );
    }

    public function delete(int $documentId): void
    {
        $this->db->delete('DELETE FROM documents WHERE id = :id', ['id' => $documentId]);
    }

    public function setVisibility(int $documentId, int $visibility): void
    {
        $this->db->update(
            'UPDATE documents SET est_public = :visibility WHERE id = :id',
            ['visibility' => $visibility, 'id' => $documentId]
        );
    }

    public function findDetails(int $documentId): ?array
    {
        return $this->db->fetchOne(
            "SELECT d.*, d.nom_fichier AS nom_original, d.extension AS type_fichier,
                    COALESCE(NULLIF(d.type_document, ''), 'Autre') AS categorie,
                    DATE_FORMAT(d.created_at, '%d/%m/%Y à %H:%i') AS date_format,
                    CONCAT(u.prenom, ' ', u.nom) AS upload_par_nom,
                    CASE
                        WHEN d.taille_fichier > 1048576 THEN CONCAT(ROUND(d.taille_fichier / 1048576, 1), ' Mo')
                        WHEN d.taille_fichier > 1024 THEN CONCAT(ROUND(d.taille_fichier / 1024, 1), ' Ko')
                        ELSE CONCAT(d.taille_fichier, ' o')
                    END AS taille_formatee,
                    0 AS nb_telechargements
             FROM documents d
             LEFT JOIN utilisateurs u ON d.utilisateur_id = u.id
             WHERE d.id = :id",
            ['id' => $documentId]
        );
    }

    public function findRecent(bool $admin, int $limit): array
    {
        $where = $admin ? '' : 'WHERE d.est_public = 1';
        return $this->db->fetchAll(
            "SELECT d.*, d.nom_fichier AS nom_original, d.extension AS type_fichier,
                    DATE_FORMAT(d.created_at, '%d/%m/%Y') AS date_format,
                    CONCAT(u.prenom, ' ', u.nom) AS upload_par_nom
             FROM documents d
             LEFT JOIN utilisateurs u ON d.utilisateur_id = u.id
             {$where}
             ORDER BY d.created_at DESC
             LIMIT :limit",
            ['limit' => $limit]
        );
    }

    public function statistics(bool $admin): array
    {
        $visibility = $admin ? '' : 'WHERE est_public = 1';
        $monthVisibility = $admin ? 'WHERE ' : 'WHERE est_public = 1 AND ';
        return [
            'total' => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM documents {$visibility}"),
            'total_size' => (int) $this->db->fetchColumn("SELECT COALESCE(SUM(taille_fichier), 0) FROM documents {$visibility}"),
            'public_count' => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM documents WHERE est_public = 1"),
            'current_month_count' => (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM documents {$monthVisibility}MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())"
            ),
        ];
    }

}
