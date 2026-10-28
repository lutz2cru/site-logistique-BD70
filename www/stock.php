<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable', 'magasinier');

// ---------------------------------------------------------------------
//  Recherche multicritère
// ---------------------------------------------------------------------
$f = ['q' => filtre('q'), 'client' => filtre('client'), 'zone' => filtre('zone'), 'condition' => filtre('condition'),
      'lot' => filtre('lot'), 'dlc' => filtre('dlc'), 'emplacement' => filtre('emplacement'), 'vue' => filtre('vue') ?: 'detail'];
$where = ['1 = 1'];
$params = [];
if ($f['q'] !== '') {
    // Index FULLTEXT sur la désignation + préfixe de référence. Les articles sont résolus
    // d'abord : en sous-requête, l'optimiseur sous-estime le MATCH et parcourt tous les
    // emplacements (19 ms contre 4 ms, voir docs/rapport_performances.md).
    $mots = implode(' ', array_map(fn($m) => '+' . preg_replace('/[^\p{L}\p{N}]/u', '', $m) . '*', preg_split('/\s+/', $f['q'])));
    $ids = requete('SELECT id FROM article WHERE MATCH(designation) AGAINST (? IN BOOLEAN MODE) OR reference LIKE ?', [$mots, $f['q'] . '%'])
        ->fetchAll(PDO::FETCH_COLUMN);
    $where[] = $ids ? 'd.article_id IN (' . implode(',', array_map('intval', $ids)) . ')' : '0 = 1';
}
if ($f['client'] !== '') { $where[] = 'd.client_id = ?'; $params[] = $f['client']; }
if ($f['zone'] !== '') { $where[] = 'd.zone_id = ?'; $params[] = $f['zone']; }
if ($f['condition'] !== '') { $where[] = 'd.article_id IN (SELECT id FROM article WHERE condition_stockage_id = ?)'; $params[] = $f['condition']; }
if ($f['lot'] !== '') { $where[] = 'd.code_lot LIKE ?'; $params[] = $f['lot'] . '%'; }
if ($f['dlc'] !== '') { $where[] = 'd.date_limite_conso <= ?'; $params[] = $f['dlc']; }
if ($f['emplacement'] !== '') { $where[] = 'd.emplacement LIKE ?'; $params[] = strtoupper($f['emplacement']) . '%'; }
$w = implode(' AND ', $where);

if ($f['vue'] === 'article') {
    $sql = "SELECT d.article_id, d.reference, d.designation, d.client, COUNT(DISTINCT d.lot_id) AS lots,
                   COUNT(DISTINCT d.emplacement_id) AS emplacements, SUM(d.quantite) AS quantite, SUM(d.palettes) AS palettes,
                   MIN(d.date_limite_conso) AS dlc
              FROM v_stock_detail d WHERE $w GROUP BY d.article_id, d.reference, d.designation, d.client";
    $total = (int) valeur("SELECT COUNT(*) FROM ($sql) x", $params);
    $ordre = 'ORDER BY reference';
} else {
    $sql = "SELECT d.* FROM v_stock_detail d WHERE $w";
    $total = (int) valeur("SELECT COUNT(*) FROM v_stock_detail d WHERE $w", $params);
    $ordre = 'ORDER BY d.emplacement, d.code_lot';
}
$totaux = ligne("SELECT COALESCE(SUM(quantite), 0) AS unites, COALESCE(SUM(palettes), 0) AS palettes FROM v_stock_detail d WHERE $w", $params);

if (filtre('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="etat-stock-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $rows = lignes("$sql $ordre", $params);
    fputcsv($out, array_keys($rows[0] ?? ['aucun résultat' => '']), ';');
    foreach ($rows as $r) { fputcsv($out, $r, ';'); }
    exit;
}

[$limite, $decalage, $page, $pages] = pagination($total, 30);
$resultats = lignes("$sql $ordre LIMIT $limite OFFSET $decalage", $params);

$clients = paires('SELECT id, raison_sociale FROM client ORDER BY raison_sociale');
$zones = paires('SELECT id, libelle FROM zone ORDER BY code');
$conditions = paires('SELECT id, libelle FROM condition_stockage ORDER BY id');
entete('Recherche de stock');
?>
<div class="kpis">
    <div class="kpi"><div class="valeur"><?= nombre($total) ?></div><div class="libelle"><?= $f['vue'] === 'article' ? 'articles' : 'lignes de stock' ?></div></div>
    <div class="kpi"><div class="valeur"><?= nombre($totaux['unites']) ?></div><div class="libelle">unités en stock</div></div>
    <div class="kpi"><div class="valeur"><?= nombre($totaux['palettes']) ?></div><div class="libelle">palettes occupées</div></div>
</div>
<div class="carte">
    <form class="filtres" method="get">
        <label>Article (réf. ou désignation)<input name="q" value="<?= e($f['q']) ?>" placeholder="ex. yaourt, ART-001"></label>
        <label>Client <select name="client"><?= options($clients, $f['client'], 'Tous') ?></select></label>
        <label>Zone <select name="zone"><?= options($zones, $f['zone'], 'Toutes') ?></select></label>
        <label>Conditions <select name="condition"><?= options($conditions, $f['condition'], 'Toutes') ?></select></label>
        <label>Lot<input name="lot" value="<?= e($f['lot']) ?>" size="10"></label>
        <label>DLC avant le<input type="date" name="dlc" value="<?= e($f['dlc']) ?>"></label>
        <label>Emplacement<input name="emplacement" value="<?= e($f['emplacement']) ?>" placeholder="ex. FRO-A02" size="12"></label>
        <label>Affichage <select name="vue"><?= options(['detail' => 'Par emplacement et lot', 'article' => 'Totaux par article'], $f['vue']) ?></select></label>
        <button type="submit">Rechercher</button>
        <a class="bouton discret" href="stock.php">Réinitialiser</a>
        <a class="bouton discret" href="?<?= e(http_build_query($_GET + ['export' => 'csv'])) ?>">Exporter l'état (CSV)</a>
    </form>

    <?php if (!$resultats): ?><p class="vide">Aucun stock ne correspond à ces critères.</p>
    <?php elseif ($f['vue'] === 'article'): ?>
    <div class="tableau"><table>
        <tr><th>Référence</th><th>Désignation</th><th>Client</th><th class="nombre">Lots</th><th class="nombre">Emplacements</th><th class="nombre">Quantité</th><th class="nombre">Palettes</th><th>DLC la plus proche</th></tr>
        <?php foreach ($resultats as $r): ?>
        <tr>
            <td><a href="?<?= e(http_build_query(['q' => $r['reference']])) ?>"><?= e($r['reference']) ?></a></td>
            <td><?= e($r['designation']) ?></td><td><?= e($r['client']) ?></td>
            <td class="nombre"><?= $r['lots'] ?></td><td class="nombre"><?= $r['emplacements'] ?></td>
            <td class="nombre"><?= nombre($r['quantite']) ?></td><td class="nombre"><?= nombre($r['palettes']) ?></td>
            <td><?= date_fr($r['dlc']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php else: ?>
    <div class="tableau"><table>
        <tr><th>Emplacement</th><th>Zone</th><th>Article</th><th>Client</th><th>Lot</th><th>DLC</th><th class="nombre">Quantité</th><th class="nombre">Palettes</th><th></th></tr>
        <?php foreach ($resultats as $r):
            $jours = $r['date_limite_conso'] ? (strtotime($r['date_limite_conso']) - strtotime('today')) / 86400 : null; ?>
        <tr>
            <td><strong><?= e($r['emplacement']) ?></strong></td><td><?= e($r['zone']) ?></td>
            <td><?= e($r['reference']) ?> <small><?= e($r['designation']) ?></small></td>
            <td><small><?= e($r['client']) ?></small></td>
            <td><a href="lot.php?code=<?= e($r['code_lot']) ?>"><?= e($r['code_lot']) ?></a></td>
            <td class="<?= $jours !== null && $jours <= 7 ? 'retard' : '' ?>"><?= date_fr($r['date_limite_conso']) ?></td>
            <td class="nombre"><?= nombre($r['quantite']) ?></td><td class="nombre"><?= $r['palettes'] ?></td>
            <td class="actions-ligne">
                <a class="bouton petit secondaire" href="mouvement_form.php?type=transfert&lot=<?= $r['lot_id'] ?>&emplacement=<?= $r['emplacement_id'] ?>">Transférer</a>
                <a class="bouton petit discret" href="mouvement_form.php?type=ajustement&lot=<?= $r['lot_id'] ?>&emplacement=<?= $r['emplacement_id'] ?>">Ajuster</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
    <?= liens_pagination($page, $pages) ?>
</div>
<?php pied();
