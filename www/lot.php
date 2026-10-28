<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable', 'magasinier');

$code = strtoupper(filtre('code'));
$lot = $code !== '' ? ligne('SELECT l.*, a.reference, a.designation, c.raison_sociale AS client, cs.libelle AS condition_stockage
                               FROM lot l JOIN article a ON a.id = l.article_id JOIN client c ON c.id = a.client_id
                               JOIN condition_stockage cs ON cs.id = a.condition_stockage_id
                              WHERE l.code_lot = ? OR l.numero_lot_fournisseur = ? LIMIT 1', [$code, $code]) : null;

if ($lot) {
    $receptions = lignes("SELECT r.id, r.numero, r.debut, rl.quantite_recue, rl.etat_palette, a.numero AS avis, f.raison_sociale AS fournisseur,
                                 v.immatriculation, t.raison_sociale AS transporteur, p.id AS passage_id
                            FROM reception_lot rl JOIN reception r ON r.id = rl.reception_id
                            JOIN passage p ON p.id = r.passage_id JOIN vehicule v ON v.id = p.vehicule_id JOIN transporteur t ON t.id = v.transporteur_id
                            LEFT JOIN avis_expedition a ON a.id = r.avis_id LEFT JOIN fournisseur f ON f.id = a.fournisseur_id
                           WHERE rl.lot_id = ?", [$lot['id']]);
    $mouvements = lignes("SELECT m.*, es.code AS source, ed.code AS destination, CONCAT(u.prenom, ' ', u.nom) AS utilisateur
                            FROM mouvement_stock m LEFT JOIN emplacement es ON es.id = m.emplacement_source_id
                            LEFT JOIN emplacement ed ON ed.id = m.emplacement_destination_id JOIN utilisateur u ON u.id = m.utilisateur_id
                           WHERE m.lot_id = ? ORDER BY m.date_heure, m.id", [$lot['id']]);
    $stock = lignes('SELECT emplacement, emplacement_id, quantite, palettes FROM v_stock_detail WHERE lot_id = ?', [$lot['id']]);
    $sorties = lignes("SELECT li.numero AS liste, li.regle_sortie, lp.quantite_prelevee, c.numero AS commande, cl.raison_sociale AS client,
                              c.ville, CONCAT(u.prenom, ' ', u.nom) AS preparateur, li.fin
                         FROM ligne_prelevement lp JOIN liste_prelevement li ON li.id = lp.liste_id
                         JOIN commande c ON c.id = li.commande_id JOIN client cl ON cl.id = c.client_id
                         LEFT JOIN utilisateur u ON u.id = li.preparateur_id
                        WHERE lp.lot_id = ? ORDER BY li.date_creation", [$lot['id']]);
    $expeditions = lignes("SELECT ue.sscc, ue.type, cu.quantite, x.numero AS expedition, x.date_chargement, x.statut,
                                  v.immatriculation, t.raison_sociale AS transporteur, p.id AS passage_id, c.numero AS commande,
                                  (SELECT GROUP_CONCAT(d.numero SEPARATOR ', ') FROM document_transport d WHERE d.expedition_id = x.id) AS documents
                             FROM contenu_unite cu JOIN unite_expedition ue ON ue.id = cu.unite_id JOIN commande c ON c.id = ue.commande_id
                             LEFT JOIN expedition x ON x.id = ue.expedition_id LEFT JOIN passage p ON p.id = x.passage_id
                             LEFT JOIN vehicule v ON v.id = p.vehicule_id LEFT JOIN transporteur t ON t.id = v.transporteur_id
                            WHERE cu.lot_id = ? ORDER BY x.date_chargement", [$lot['id']]);
}

entete('Traçabilité d\'un lot');
?>
<div class="carte">
    <form class="filtres" method="get" style="margin:0">
        <label>Code lot ou n° de lot fournisseur<input name="code" value="<?= e($code) ?>" placeholder="ex. L00000123" required autofocus></label>
        <button type="submit">Tracer</button>
    </form>
</div>

<?php if ($code !== '' && !$lot): ?>
    <div class="alerte erreur">Aucun lot ne correspond à « <?= e($code) ?> ».</div>
<?php elseif ($lot): ?>
<div class="carte">
    <h2>Lot <?= e($lot['code_lot']) ?> — <?= e($lot['reference']) ?> <?= e($lot['designation']) ?></h2>
    <p class="discret">Propriétaire <?= e($lot['client']) ?> · <?= e($lot['condition_stockage']) ?> · lot fournisseur <?= e($lot['numero_lot_fournisseur']) ?>
       · fabriqué le <?= date_fr($lot['date_fabrication']) ?> · DLC <?= date_fr($lot['date_limite_conso']) ?: '–' ?>
       · en stock : <strong><?= nombre(array_sum(array_column($stock, 'quantite'))) ?></strong>
       <?= $stock ? '(' . e(implode(', ', array_column($stock, 'emplacement'))) . ')' : '' ?></p>
</div>

<div class="carte">
    <h2>1. Réception</h2>
    <?php foreach ($receptions as $r): ?>
        <p><a href="reception.php?id=<?= $r['id'] ?>"><?= e($r['numero']) ?></a> le <?= date_heure_fr($r['debut']) ?> ·
           <?= nombre($r['quantite_recue']) ?> unités <?= pastille($r['etat_palette']) ?> · avis <?= e($r['avis'] ?? 'non annoncé') ?>
           <?= $r['fournisseur'] ? '(' . e($r['fournisseur']) . ')' : '' ?> · camion
           <a href="passage.php?id=<?= $r['passage_id'] ?>"><?= e($r['immatriculation']) ?></a> (<?= e($r['transporteur']) ?>)</p>
    <?php endforeach; ?>
</div>

<div class="carte">
    <h2>2. Emplacements successifs et mouvements</h2>
    <?php if (!$mouvements): ?><p class="vide">Aucun mouvement.</p><?php else: ?>
    <ul class="chronologie">
        <?php foreach ($mouvements as $m): ?>
        <li><?= date_heure_fr($m['date_heure']) ?> · <?= pastille($m['type']) ?> <strong><?= nombre($m['quantite']) ?></strong>
            <?= $m['source'] ? 'de <strong>' . e($m['source']) . '</strong>' : '' ?> <?= $m['destination'] ? 'vers <strong>' . e($m['destination']) . '</strong>' : '' ?>
            <small>— <?= e($m['utilisateur']) ?><?= $m['motif'] ? ', ' . e($m['motif']) : '' ?></small></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<div class="grille">
    <div class="carte">
        <h2>3. Prélèvements pour commandes</h2>
        <?php if (!$sorties): ?><p class="vide">Pas encore prélevé.</p><?php else: ?>
        <div class="tableau"><table>
            <tr><th>Commande</th><th>Client</th><th>Liste</th><th class="nombre">Prélevé</th><th>Préparateur</th></tr>
            <?php foreach ($sorties as $s): ?>
            <tr><td><?= e($s['commande']) ?></td><td><?= e($s['client']) ?> <small><?= e($s['ville']) ?></small></td>
                <td><?= e($s['liste']) ?> <small><?= e($s['regle_sortie']) ?></small></td>
                <td class="nombre"><?= $s['quantite_prelevee'] !== null ? nombre($s['quantite_prelevee']) : '<small>à prélever</small>' ?></td>
                <td><?= e($s['preparateur']) ?></td></tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
    </div>
    <div class="carte">
        <h2>4. Expédition</h2>
        <?php if (!$expeditions): ?><p class="vide">Pas encore expédié.</p><?php else: ?>
        <div class="tableau"><table>
            <tr><th>Unité (SSCC)</th><th class="nombre">Qté</th><th>Expédition</th><th>Camion</th><th>Documents</th></tr>
            <?php foreach ($expeditions as $x): ?>
            <tr><td><small><?= e($x['sscc']) ?></small> <?= e($x['type']) ?></td><td class="nombre"><?= nombre($x['quantite']) ?></td>
                <td><?= e($x['expedition']) ?> <small><?= date_heure_fr($x['date_chargement']) ?></small></td>
                <td><?php if ($x['passage_id']): ?><a href="passage.php?id=<?= $x['passage_id'] ?>"><?= e($x['immatriculation']) ?></a> <small><?= e($x['transporteur']) ?></small><?php endif; ?></td>
                <td><small><?= e($x['documents']) ?></small></td></tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php pied();
