<?php

defined('ACCESS') or die('Accès interdit');

/**
 * Index et recherche documentaire locale pour l'assistant.
 * Les documents restent dans le module Documents; cette classe ne duplique
 * ni leur stockage ni leurs règles de visibilité.
 */
final class AssistantDocumentKnowledgeService
{
    private Database $db;
    private const MAX_DOCUMENT_BYTES = 2000000;
    private const CHUNK_CHARS = 1400;
    private const CHUNK_OVERLAP = 180;
    private const SEARCH_LIMIT = 8;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /** @return array{document_id:int,chunks:int,sha256:string} */
    public function indexDocument(int $documentId): array
    {
        if ($documentId <= 0) {
            throw new InvalidArgumentException('Identifiant de document invalide.');
        }
        $document = $this->db->fetchOne(
            'SELECT id, titre, description, type_document, chemin_fichier, extension, est_public, created_at
             FROM documents WHERE id = :id LIMIT 1',
            ['id' => $documentId]
        );
        if (!$document) {
            throw new RuntimeException('Document scolaire introuvable.');
        }

        $extension = strtolower((string) ($document['extension'] ?? pathinfo((string) $document['chemin_fichier'], PATHINFO_EXTENSION)));
        if (!in_array($extension, ['txt', 'md', 'csv'], true)) {
            throw new DomainException('Format non indexable dans cette livraison. Les PDF et fichiers Office nécessitent un extracteur dédié.');
        }

        // DocumentController stocke les chemins relatifs sous la forme documents/<nom opaque>.
        // Ne jamais concaténer un chemin DB arbitraire au chemin racine.
        $storedPath = str_replace('\\\\', '/', (string) $document['chemin_fichier']);
        if (!str_starts_with($storedPath, 'documents/') || basename($storedPath) !== substr($storedPath, strlen('documents/'))) {
            throw new RuntimeException('Chemin documentaire non conforme au stockage privé attendu.');
        }
        $base = realpath(DOCUMENTS_STORAGE_PATH);
        $path = $base === false ? false : realpath($base . DIRECTORY_SEPARATOR . basename($storedPath));
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new RuntimeException('Le chemin du document est absent ou hors du stockage documentaire privé.');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_DOCUMENT_BYTES) {
            throw new DomainException('Le document doit contenir entre 1 octet et 2 Mo pour l’indexation.');
        }
        $content = file_get_contents($path);
        if (!is_string($content) || str_contains($content, "\0")) {
            throw new DomainException('Le document est illisible ou contient des données binaires.');
        }
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        $content = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/u', ' ', $content) ?? '';
        $content = trim($content);
        if ($content === '') {
            throw new DomainException('Le document ne contient aucun texte exploitable.');
        }

        $text = trim((string) $document['titre']) . "\n"
            . trim((string) ($document['description'] ?? '')) . "\n"
            . 'Catégorie : ' . trim((string) ($document['type_document'] ?? '')) . "\n\n"
            . $content;
        $chunks = $this->splitText($text);
        $hash = hash('sha256', $text);

        $this->db->delete('DELETE FROM assistant_knowledge_chunks WHERE document_id = :id', ['id' => $documentId]);
        foreach ($chunks as $index => $chunk) {
            $this->db->insert(
                'INSERT INTO assistant_knowledge_chunks
                    (document_id, chunk_index, contenu, contenu_hash, source_updated_at, indexed_at)
                 VALUES (:document_id, :chunk_index, :content, :hash, :source_updated, NOW())',
                [
                    'document_id' => $documentId,
                    'chunk_index' => $index,
                    'content' => $chunk,
                    'hash' => hash('sha256', $chunk),
                    'source_updated' => $document['created_at'] ?? null,
                ]
            );
        }
        return ['document_id' => $documentId, 'chunks' => count($chunks), 'sha256' => $hash];
    }

    /**
     * @return array<int,array{document_id:int,title:string,category:string,excerpt:string,chunk_index:int,source:string}>
     */
    public function searchForUser(int $userId, string $query, bool $isAdmin = false, int $limit = self::SEARCH_LIMIT): array
    {
        if ($userId <= 0 || trim($query) === '') {
            throw new InvalidArgumentException('La question documentaire est vide ou invalide.');
        }
        $query = mb_substr(trim(strip_tags($query)), 0, 1000, 'UTF-8');
        $terms = $this->terms($query);
        if ($terms === []) {
            return [];
        }
        $visibility = $isAdmin ? '' : 'AND d.est_public = 1';
        $rows = $this->db->fetchAll(
            "SELECT k.document_id, k.chunk_index, k.contenu, d.titre, d.type_document, d.nom_fichier
             FROM assistant_knowledge_chunks k
             INNER JOIN documents d ON d.id = k.document_id
             WHERE 1=1 {$visibility}
             ORDER BY k.indexed_at DESC LIMIT 400"
        );

        $ranked = [];
        foreach ($rows as $row) {
            $normalized = mb_strtolower((string) $row['contenu'], 'UTF-8');
            $score = 0;
            foreach ($terms as $term) {
                $score += substr_count($normalized, $term);
            }
            if ($score < 1) {
                continue;
            }
            $ranked[] = [
                'score' => $score,
                'document_id' => (int) $row['document_id'],
                'title' => (string) $row['titre'],
                'category' => (string) ($row['type_document'] ?? ''),
                'excerpt' => mb_substr((string) $row['contenu'], 0, 1200, 'UTF-8'),
                'chunk_index' => (int) $row['chunk_index'],
                'source' => (string) $row['nom_fichier'],
            ];
        }
        usort($ranked, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $limit = max(1, min($limit, self::SEARCH_LIMIT));
        return array_map(static function (array $row): array {
            unset($row['score']);
            return $row;
        }, array_slice($ranked, 0, $limit));
    }

    /** @return list<string> */
    private function terms(string $query): array
    {
        $parts = preg_split('/[^\\p{L}\\p{N}]+/u', mb_strtolower($query, 'UTF-8')) ?: [];
        $stop = ['les','des','une','dans','pour','avec','est','que','qui','quoi','comment','notre','votre','sur','par','aux','du','de','la','le','et'];
        return array_values(array_unique(array_filter($parts, static fn(string $term): bool =>
            mb_strlen($term, 'UTF-8') >= 3 && !in_array($term, $stop, true)
        )));
    }

    /** @return list<string> */
    private function splitText(string $text): array
    {
        $chunks = [];
        $length = mb_strlen($text, 'UTF-8');
        $step = self::CHUNK_CHARS - self::CHUNK_OVERLAP;
        for ($offset = 0; $offset < $length && count($chunks) < 500; $offset += $step) {
            $chunk = trim(mb_substr($text, $offset, self::CHUNK_CHARS, 'UTF-8'));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
        }
        return $chunks;
    }
}
