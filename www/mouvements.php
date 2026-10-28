<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable', 'magasinier');

$f = ['du' => filtre('du') ?: date('Y-m-d', strtotime('-7 days')), 'au' => filtre('au') ?: date('Y-m-d'),
      'type' => filtre('type'), 'q' => filtre('q'), 'lot' => filtre('lot'), 'emplacement' => filtre('emplacement'), 'utilisateur' => filtre('utilisateur')];
$where = ['m.date_heure >= ?', 'm.date_heure < ? + INTERVAL 1 DAY'];
$params = [$f['du'], $f['au']];
if ($f['type'] !== '') { $where[] = 'm.type = ?'; $params[] = $f['type']; }
if ($f['q'] !== '') { $where[] = '(a.reference LIKE ? OR a.designation LIKE ?)'; array_push($params, "{$f['q']}%", "%{$f['q']}%"); }
if ($f['lot'] !== '') { $where[] = 'l.code_lot = ?'; $params[] = $f['lot']; }
if ($f['emplacement'] !== '') { $where[] = '(es.code LIKE ? OR ed.code LIKE ?)'; array_push($params, strtoupper($f['emplacement']) . '%', strtoupper($f['emplacement']) . '%'); }
if ($f['utilisateur'] !== '') { $where[] = 'm.utilisateur_id = ?'; $params[] = $f['utilisateur']; }

$from = 'FROM mouvement_stock m JOIN lot l ON l.id = m.lot_id JOIN article a ON a.id = l.article_id
         LEFT JOIN emplacement es ON es.id = m.emplacement_source_id LEFT JOIN emplacement ed ON ed.id = m.emplacement_destination_id
         JOIN utilisateur u ON u.id = m.utilisateur_id WHERE ' . implode(' AND ', $where);
$parType = paires("SELECT m.type, COUNT(*) $from GROUP BY m.type", $params);
$total = array_sum($parType);
[$limite, $decalage, $page, $pages] = pagination($total, 30);
$mvts = lignes("SELECT m.*, l.code_lot, a.reference, a.designation, es.code AS source, ed.code AS destination,
                       CONCAT(u.prenom, ' ', u.nom) AS utilisateur
                $from ORDER BY m.date_heure DESC, m.id DESC LIMIT $limite OFFSET $decalage", $params);

$utilisateurs = paires("SELECT u.id, CONCAT(u.prenom, ' ', u.nom) FROM utilisateur u JOIN profil p ON p.id = u.profil_id
                         WHERE p.code IN ('magasinier','responsable','admin') ORDER BY u.nom");
entete('Historique des mouvements de stock');
?>
<div class="kpis">
    <?php foreach (['entree', 'sortie', 'transfert', 'ajustement'] as $t): ?>
    <div class="kpi"><div class="valeur"><?= nombre($parType[$t] ?? 0) ?></div><div class="libelle"><?= pastille($t) ?> sur la période</div></div>
    <?php endforeach; ?>
</div>
<div class="carte">
    <form class="filtres" method="get">
        <label>Du <input type="date" name="du" value="<?= e($f['du']) ?>"></label>
        <label>Au <input type="date" name="au" value="<?= e($f['au']) ?>"></label>
        <label>Type <select name="type"><?= options(['entree' => 'Entrée', 'sortie' => 'Sortie', 'transfert' => 'Transfert', 'ajustement' => 'Ajustement'], $f['type'], 'Tous') ?></select></label>
        <label>Article<input name="q" value="<?= e($f['q']) ?>"></label>
        <label>Lot<input name="lot" value="<?= e($f['lot']) ?>" size="10"></label>
        <label>Emplacement<input name="emplacement" value="<?= e($f['emplacement']) ?>" size="12"></label>
        <label>Opérateur <select name="utilisateur"><?= options($utilisateurs, $f['utilisateur'], 'Tous') ?></select></label>
        <button type="submit">Filtrer</button>
        <a class="bouton discret" href="mouvements.php">Réinitialiser</a>
    </form>
    <?php if (!$mvts): ?><p class="vide">Aucun mouvement sur cette période.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Date</th><th>Numéro</th><th>Type</th><th>Article</th><th>Lot</th><th class="nombre">Quantité</th><th>De</th><th>Vers</th><th>Opérateur</th><th>Motif</th></tr>
        <?php foreach ($mvts as $m): ?>
        <tr>
            <td><?= date_heure_fr($m['date_heure']) ?></td>
            <td><small><?= e($m['numero']) ?></small></td>
            <td><?= pastille($m['type']) ?></td>
            <td><?= e($m['reference']) ?> <small><?= e($m['designation']) ?></small></td>
            <td><a href="lot.php?code=<?= e($m['code_lot']) ?>"><?= e($m['code_lot']) ?></a></td>
            <td class="nombre"><?= ($m['type'] === 'sortie' || ($m['type'] === 'ajustement' && $m['source'])) ? '−' : '' ?><?= nombre($m['quantite']) ?></td>
            <td><?= e($m['source']) ?></td><td><?= e($m['destination']) ?></td>
            <td><?= e($m['utilisateur']) ?></td><td><small><?= e($m['motif']) ?></small></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?= liens_pagination($page, $pages) ?>
    <?php endif; ?>
</div>
<?php pied();
