<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable', 'magasinier');

// blocage / déblocage d'un emplacement (historisé par déclencheur)
if (est_post() && isset($_POST['bloquer'])) {
    verifier_csrf();
    requete('UPDATE emplacement SET bloque = ? WHERE id = ?', [$_POST['bloquer'] === '1' ? 1 : 0, $_POST['emplacement_id']]);
    flash('succes', $_POST['bloquer'] === '1' ? 'Emplacement bloqué.' : 'Emplacement débloqué.');
    rediriger('emplacements.php?' . http_build_query($_GET));
}

$zones = lignes('SELECT v.*, (SELECT GROUP_CONCAT(c.libelle SEPARATOR ", ") FROM zone_condition zc
                     JOIN condition_stockage c ON c.id = zc.condition_stockage_id WHERE zc.zone_id = v.id) AS conditions
                   FROM v_remplissage_zone v ORDER BY v.code');
$allees = lignes('SELECT a.id, a.code, a.zone_id, COUNT(e.id) AS nb, SUM(e.capacite_palettes) AS capacite
                    FROM allee a JOIN rack r ON r.allee_id = a.id JOIN emplacement e ON e.rack_id = r.id
                   GROUP BY a.id ORDER BY a.code');
$alleeId = (int) (filtre('allee') ?: $allees[0]['id']);

$cases = lignes("SELECT e.id, e.code, e.niveau, e.capacite_palettes, e.bloque, r.code AS rack, r.id AS rack_id,
                        COALESCE(SUM(fn_palettes(l.article_id, s.quantite)), 0) AS occupees,
                        GROUP_CONCAT(CONCAT(a.reference, ' × ', s.quantite) SEPARATOR ' | ') AS contenu
                   FROM emplacement e JOIN rack r ON r.id = e.rack_id
                   LEFT JOIN stock s ON s.emplacement_id = e.id LEFT JOIN lot l ON l.id = s.lot_id LEFT JOIN article a ON a.id = l.article_id
                  WHERE r.allee_id = ?
                  GROUP BY e.id ORDER BY r.code, e.niveau DESC, e.code", [$alleeId]);
$racks = [];
foreach ($cases as $c) { $racks[$c['rack']][] = $c; }

$detail = null;
if ($empId = (int) filtre('emplacement')) {
    $detail = ligne('SELECT e.*, r.code AS rack, fn_palettes_libres(e.id) AS libres FROM emplacement e JOIN rack r ON r.id = e.rack_id WHERE e.id = ?', [$empId]);
    $contenu = lignes('SELECT * FROM v_stock_detail WHERE emplacement_id = ? ORDER BY code_lot', [$empId]);
}

entete('Emplacements et remplissage');
?>
<div class="grille" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin-bottom:18px">
    <?php foreach ($zones as $z): $classe = $z['taux'] >= 90 ? 'plein' : ($z['taux'] >= 70 ? 'moyen' : ''); ?>
    <div class="carte">
        <h2><?= e($z['libelle']) ?> <span class="discret"><?= nombre($z['taux'], 1) ?> %</span></h2>
        <div class="jauge <?= $classe ?>"><span style="width:<?= min(100, (float) $z['taux']) ?>%"></span></div>
        <p class="discret" style="margin:8px 0 0"><?= nombre($z['occupees']) ?> / <?= nombre($z['capacite']) ?> palettes · <?= e($z['conditions']) ?></p>
    </div>
    <?php endforeach; ?>
</div>

<div class="carte">
    <h2>
        <form method="get" class="actions">
            Allée <select name="allee" onchange="this.form.submit()">
                <?php foreach ($zones as $z): ?>
                <optgroup label="<?= e($z['libelle']) ?>">
                    <?= options(array_column(array_filter($allees, fn($a) => $a['zone_id'] === $z['id']), 'code', 'id'), $alleeId) ?>
                </optgroup>
                <?php endforeach; ?>
            </select>
        </form>
        <span class="legende">
            <span><span class="case-empl"></span>vide</span><span><span class="case-empl partiel"></span>partiel</span>
            <span><span class="case-empl plein"></span>plein</span><span><span class="case-empl bloque"></span>bloqué</span>
        </span>
    </h2>
    <div class="racks">
        <?php foreach ($racks as $rack => $liste): ?>
        <div class="rack">
            <div class="titre"><?= e(substr($rack, strrpos($rack, '-') + 1)) ?></div>
            <div class="cases-empl">
            <?php foreach ($liste as $c):
                $etat = $c['bloque'] ? 'bloque' : ($c['occupees'] >= $c['capacite_palettes'] ? 'plein' : ($c['occupees'] > 0 ? 'partiel' : '')); ?>
                <a class="case-empl <?= $etat ?>" href="?allee=<?= $alleeId ?>&emplacement=<?= $c['id'] ?>"
                   title="<?= e($c['code'] . ' · ' . $c['occupees'] . '/' . $c['capacite_palettes'] . ' pal.' . ($c['contenu'] ? ' · ' . $c['contenu'] : '')) ?>"></a>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="discret">Chaque rack est représenté du niveau le plus haut (en haut) au sol (en bas), deux emplacements par niveau. Cliquez sur un emplacement pour voir son contenu.</p>
</div>

<?php if ($detail): ?>
<div class="carte" id="detail">
    <h2>
        <span>Emplacement <?= e($detail['code']) ?> <?= $detail['bloque'] ? pastille('hors_service') : '' ?></span>
        <form method="post">
            <?= champ_csrf() ?><input type="hidden" name="emplacement_id" value="<?= $detail['id'] ?>">
            <button class="petit <?= $detail['bloque'] ? 'secondaire' : 'danger' ?>" name="bloquer" value="<?= $detail['bloque'] ? 0 : 1 ?>"><?= $detail['bloque'] ? 'Débloquer' : 'Bloquer' ?></button>
        </form>
    </h2>
    <p class="discret">Niveau <?= $detail['niveau'] ?> · capacité <?= $detail['capacite_palettes'] ?> palette(s), <?= nombre($detail['poids_max_kg']) ?> kg · <?= $detail['libres'] ?> palette(s) libre(s)</p>
    <?php if (!$contenu): ?><p class="vide">Emplacement vide.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Lot</th><th>Article</th><th>Client</th><th>DLC</th><th class="nombre">Quantité</th><th class="nombre">Palettes</th><th></th></tr>
        <?php foreach ($contenu as $s): ?>
        <tr>
            <td><a href="lot.php?code=<?= e($s['code_lot']) ?>"><?= e($s['code_lot']) ?></a></td>
            <td><?= e($s['reference']) ?> <small><?= e($s['designation']) ?></small></td>
            <td><?= e($s['client']) ?></td><td><?= date_fr($s['date_limite_conso']) ?></td>
            <td class="nombre"><?= nombre($s['quantite']) ?></td><td class="nombre"><?= $s['palettes'] ?></td>
            <td class="actions-ligne">
                <a class="bouton petit secondaire" href="mouvement_form.php?type=transfert&lot=<?= $s['lot_id'] ?>&emplacement=<?= $detail['id'] ?>&retour=<?= urlencode("emplacements.php?allee=$alleeId&emplacement={$detail['id']}") ?>">Transférer</a>
                <a class="bouton petit discret" href="mouvement_form.php?type=ajustement&lot=<?= $s['lot_id'] ?>&emplacement=<?= $detail['id'] ?>&retour=<?= urlencode("emplacements.php?allee=$alleeId&emplacement={$detail['id']}") ?>">Ajuster</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php pied();
