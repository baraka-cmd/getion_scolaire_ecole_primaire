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
}
