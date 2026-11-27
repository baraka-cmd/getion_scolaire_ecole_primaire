<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/AnneeScolaireRepository.php';

class AnneeScolaireService
{
    private AnneeScolaireRepository $repository;

    public function __construct(?AnneeScolaireRepository $repository = null)
    {
        $this->repository = $repository ?? new AnneeScolaireRepository();
    }

    public function getListForAjax(): array
    {
        return $this->repository->findListForAjax();
    }

    public function getDetailsForAjax(int $id): ?array
    {
        $year = $this->repository->findForAjax($id);
        if ($year === null) {
            return null;
        }

        return [
            'annee' => $year,
            'trimestres' => $this->repository->findTerms($id),
        ];
    }

    public function getTerms(int $yearId): array
    {
        return $this->repository->findTerms($yearId);
    }

    public function getAll(): array
    {
        return $this->repository->findAll();
    }

    public function getActive(): ?array
    {
        return $this->repository->findActive();
    }

    public function getById(int $id): ?array
    {
        return $this->repository->findById($id);
    }

    public function validateTermDates(array $input, string $dateStart, string $dateEnd): array
    {
        $errors = [];
        $dates = [];
        $labels = [1 => '1er Trimestre', 2 => '2ème Trimestre', 3 => '3ème Trimestre'];

        for ($number = 1; $number <= 3; $number++) {
            $term = $input[$number] ?? [];
            $start = is_array($term) && is_scalar($term['date_debut'] ?? null)
                ? trim((string) $term['date_debut'])
                : '';
            $end = is_array($term) && is_scalar($term['date_fin'] ?? null)
                ? trim((string) $term['date_fin'])
                : '';

            if (!isValidDate($start) || !isValidDate($end)) {
                $errors["trimestre_{$number}"] =
                    "Les dates du {$labels[$number]} sont obligatoires et doivent être valides.";
                continue;
            }
            if ($start > $end) {
                $errors["trimestre_{$number}"] =
                    "La fin du {$labels[$number]} doit être après sa date de début.";
                continue;
            }
            if ($start < $dateStart || $end > $dateEnd) {
                $errors["trimestre_{$number}"] =
                    "Les dates du {$labels[$number]} doivent rester dans les limites de l'année scolaire.";
                continue;
            }

            $dates[$number] = [
                'numero' => $number,
                'libelle' => $labels[$number],
                'date_debut' => $start,
                'date_fin' => $end,
            ];
        }

        if (count($dates) === 3) {
            for ($number = 2; $number <= 3; $number++) {
                if ($dates[$number]['date_debut'] <= $dates[$number - 1]['date_fin']) {
                    $errors["trimestre_{$number}"] =
                        'Les trimestres doivent suivre l’ordre chronologique et ne pas se chevaucher.';
                }
            }
        }

        return ['errors' => $errors, 'dates' => $dates];
    }

    public function distributeTerms(string $dateStart, string $dateEnd): array
    {
        $start = new DateTime($dateStart);
        $end = new DateTime($dateEnd);
        $termDuration = max(1, (int) floor($start->diff($end)->days / 3));
        $labels = [1 => '1er Trimestre', 2 => '2ème Trimestre', 3 => '3ème Trimestre'];
        $terms = [];

        for ($number = 1; $number <= 3; $number++) {
            $termStart = (clone $start)->modify('+' . (($number - 1) * $termDuration) . ' days');
            $termEnd = $number === 3
                ? clone $end
                : (clone $termStart)->modify('+' . ($termDuration - 1) . ' days');
            if ($termEnd > $end) {
                $termEnd = clone $end;
            }

            $terms[$number] = [
                'numero' => $number,
                'libelle' => $labels[$number],
                'date_debut' => $termStart->format('Y-m-d'),
                'date_fin' => $termEnd->format('Y-m-d'),
            ];
        }

        return $terms;
    }

    public function saveTerms(int $yearId, array $terms): void
    {
        $existingTerms = $this->repository->findTermStates($yearId);
        $byNumber = [];
        $hasActiveTerm = false;

        foreach ($existingTerms as $existingTerm) {
            $byNumber[(int) $existingTerm['numero']] = $existingTerm;
            $hasActiveTerm = $hasActiveTerm || (bool) $existingTerm['est_actif'];
        }

        foreach ($terms as $number => $term) {
            $active = ($number === 1 && !$hasActiveTerm)
                ? 1
                : (isset($byNumber[$number]) ? (int) $byNumber[$number]['est_actif'] : 0);
            $params = [
                'aid' => $yearId,
                'num' => $number,
                'lib' => $term['libelle'],
                'debut' => $term['date_debut'],
                'fin' => $term['date_fin'],
                'actif' => $active,
            ];

            if (isset($byNumber[$number])) {
                $this->repository->updateTerm($params);
                if ($active) {
                    $hasActiveTerm = true;
                }
            } else {
                $this->repository->createTerm($params);
                if ($active) {
                    $hasActiveTerm = true;
                }
            }
        }
    }
}
