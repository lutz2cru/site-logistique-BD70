<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_profil('client');

// Stock du client par article : quantité physique, réservée par les listes en cours, disponible
$f = ['q' => filtre('q'), 'condition' => filtre('condition'), 'dlc' => filtre('dlc')];
$where = ['ar.client_id = ?'];
$params = [$u['client_id']];
if ($f['q'] !== '') { $where[] = '(ar.reference LIKE ? OR ar.designation LIKE ?)'; array_push($params, "%{$f['q']}%", "%{$f['q']}%"); }
if ($f['condition'] !== '') { $where[] = 'ar.condition_stockage_id = ?'; $params[] = $f['condition']; }
$having = $f['dlc'] === '1' ? 'HAVING dlc_min <= CURRENT_DATE + INTERVAL 30 DAY' : '';
$articles = lignes("SELECT ar.id, ar.reference, ar.designation, cs.libelle AS condition_stockage,
                           COALESCE(SUM(s.quantite), 0) AS en_stock, COUNT(DISTINCT s.lot_id) AS nb_lots,
                           MIN(CASE WHEN s.lot_id IS NOT NULL THEN l.date_limite_conso END) AS dlc_min,
                           COALESCE((SELECT SUM(lp.quantite_a_prelever) FROM ligne_prelevement lp
                                       JOIN liste_prelevement li ON li.id = lp.liste_id JOIN lot l2 ON l2.id = lp.lot_id
                                      WHERE l2.article_id = ar.id AND li.statut <> 'terminee'), 0) AS reserve
                      FROM article ar
                      JOIN condition_stockage cs ON cs.id = ar.condition_stockage_id
                      LEFT JOIN lot l ON l.article_id = ar.id
                      LEFT JOIN stock s ON s.lot_id = l.id
                     WHERE " . implode(' AND ', $where) . "
                     GROUP BY ar.id $having ORDER BY ar.reference", $params);
$dlc = lignes('SELECT d.* FROM v_lots_proches_dlc d JOIN lot l ON l.id = d.lot_id JOIN article ar ON ar.id = l.article_id
                WHERE ar.client_id = ? ORDER BY d.date_limite_conso', [$u['client_id']]);
$conditions = paires('SELECT id, libelle FROM condition_stockage ORDER BY id');
$total = array_sum(array_column($articles, 'en_stock'));

entete('Mon stock');
?>
<p class="discret">Stock de vos marchandises dans l'entrepôt, mis à jour en temps réel. La quantité réservée correspond aux commandes en cours de préparation.</p>

<?php if ($dlc): ?>
<div class="carte">
    <h2>Lots proches de leur date limite <span class="discret">30 jours</span></h2>
    <div class="tableau"><table>
        <tr><th>Lot</th><th>Article</th><th>DLC</th><th class="nombre">Jours restants</th><th class="nombre">Quantité</th></tr>
        <?php foreach ($dlc as $d): ?>
        <tr>
            <td><?= e($d['code_lot']) ?></td><td><strong><?= e($d['reference']) ?></strong> <?= e($d['designation']) ?></td>
            <td><?= date_fr($d['date_limite_conso']) ?></td>
            <td class="nombre"><span class="pastille <?= $d['jours_restants'] <= 7 ? 'rouge' : 'orange' ?>"><?= $d['jours_restants'] ?> j</span></td>
            <td class="nombre"><?= nombre($d['quantite']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php endif; ?>

<div class="carte">
    <h2>Articles <span class="discret"><?= count($articles) ?> · <?= nombre($total) ?> unités en stock</span></h2>
    <form class="filtres" method="get">
        <label>Référence / désignation <input name="q" value="<?= e($f['q']) ?>"></label>
        <label>Conditions de stockage <select name="condition"><?= options($conditions, $f['condition'], 'Toutes') ?></select></label>
        <label>Date limite <select name="dlc"><?= options(['1' => 'Proche (30 jours)'], $f['dlc'], 'Toutes') ?></select></label>
        <button type="submit">Filtrer</button>
        <a class="bouton discret" href="client_stock.php">Réinitialiser</a>
    </form>
    <?php if (!$articles): ?><p class="vide">Aucun article.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Référence</th><th>Désignation</th><th>Conditions</th><th class="nombre">Lots</th><th class="nombre">En stock</th><th class="nombre">Réservé</th><th class="nombre">Disponible</th><th>DLC la plus proche</th></tr>
        <?php foreach ($articles as $a): $dispo = max(0, $a['en_stock'] - $a['reserve']); ?>
        <tr>
            <td><strong><?= e($a['reference']) ?></strong></td><td><?= e($a['designation']) ?></td>
            <td><?= e($a['condition_stockage']) ?></td>
            <td class="nombre"><?= $a['nb_lots'] ?></td>
            <td class="nombre"><?= nombre($a['en_stock']) ?></td>
            <td class="nombre"><?= $a['reserve'] ? nombre($a['reserve']) : '' ?></td>
            <td class="nombre"><strong><?= nombre($dispo) ?></strong></td>
            <td><?= date_fr($a['dlc_min']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>
<?php pied();
