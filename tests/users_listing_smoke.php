<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/config/app.php';
require_once __DIR__ . '/../src/config/database.php';

$db = Database::getInstance();
$total = (int) $db->fetchColumn(
    'SELECT COUNT(*)
     FROM utilisateurs u
     LEFT JOIN roles r ON u.role_id = r.id'
);
$users = $db->fetchAll(
    "SELECT u.id, u.nom, u.prenom, COALESCE(r.nom, 'Rôle inconnu') AS role_nom
     FROM utilisateurs u
     LEFT JOIN roles r ON u.role_id = r.id
     ORDER BY u.nom ASC, u.id ASC
     LIMIT :limit OFFSET :offset",
    ['limit' => ITEMS_PER_PAGE, 'offset' => 0]
);

if (count($users) > ITEMS_PER_PAGE || count($users) > $total) {
    fwrite(STDERR, "Pagination des utilisateurs invalide.\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Requête utilisateurs valide : %d compte(s), %d sur la première page.\n",
    $total,
    count($users)
));
