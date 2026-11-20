<?php
$bulletinSummaryColumns = [
    ['trim' => 'T1', 'score' => 'p1'],
    ['trim' => 'T1', 'score' => 'p2'],
    ['trim' => 'T1', 'score' => 'ex'],
    ['trim' => 'T1', 'score' => 'trimestre'],
    ['trim' => 'T2', 'score' => 'p1'],
    ['trim' => 'T2', 'score' => 'p2'],
    ['trim' => 'T2', 'score' => 'ex'],
    ['trim' => 'T2', 'score' => 'trimestre'],
    ['trim' => 'T3', 'score' => 'p1'],
    ['trim' => 'T3', 'score' => 'p2'],
    ['trim' => 'T3', 'score' => 'ex'],
    ['trim' => 'T3', 'score' => 'trimestre'],
    ['trim' => 'annuel', 'score' => 'pct'],
];
$bulletinSignatureGroups = [
    ['label' => '1er TRIMESTRE', 'colspan' => 8],
    ['label' => '2e TRIMESTRE', 'colspan' => 8],
    ['label' => '3e TRIMESTRE + TOTAL ANNUEL', 'colspan' => 10],
];
?>
<tr class="place-row">
    <td class="col-branches"><strong>PLACE</strong></td>
    <?php foreach ($bulletinSummaryColumns as $column):
        $trim = $column['trim'];
        $score = $column['score'];
        $place = $bulletin['places'][$trim][$score] ?? null;
        $placeLabel = $place
            ? (($place['rang'] === 1 ? '1re' : $place['rang'] . 'e') . ' / ' . $place['effectif_classe'])
            : '—';
    ?>
        <td colspan="2" class="text-center bulletin-summary-value">
            <?= e($placeLabel) ?>
        </td>
    <?php endforeach; ?>
</tr>
<tr class="application-row">
    <td class="col-branches"><strong>APPLICATION</strong></td>
    <?php foreach ($bulletinSummaryColumns as $column):
        $trim = $column['trim'];
        $score = $column['score'];
        $application = $trim === 'annuel'
            ? ($bulletin['pourcentages']['annuel']['application'] ?? '')
            : ($score === 'trimestre'
                ? ($bulletin['pourcentages'][$trim]['application'] ?? '')
                : ($bulletin['pourcentages'][$trim][$score . '_application'] ?? ''));
    ?>
        <td colspan="2" class="text-center bulletin-summary-value">
            <?= e($application) ?>
        </td>
    <?php endforeach; ?>
</tr>
<tr class="conduite-row">
    <td class="col-branches"><strong>CONDUITE</strong></td>
    <?php foreach ($bulletinSummaryColumns as $_column): ?>
        <td colspan="2" class="text-center bulletin-summary-value conduite-value">&nbsp;</td>
    <?php endforeach; ?>
</tr>
<tr class="signature-row">
    <td class="col-branches"><strong>SIGNATURES</strong></td>
    <?php foreach ($bulletinSignatureGroups as $group): ?>
        <td colspan="<?= (int) $group['colspan'] ?>" class="signature-quarter-cell">
            <div class="signature-quarter-label"><?= e($group['label']) ?></div>
            <div class="signature-pair">
                <div class="signature-role">
                    <span>Enseignant</span>
                    <span class="signature-line"></span>
                </div>
                <div class="signature-role">
                    <span>Responsable</span>
                    <span class="signature-line"></span>
                </div>
            </div>
        </td>
    <?php endforeach; ?>
</tr>
