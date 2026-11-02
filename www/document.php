<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable', 'magasinier');

$doc = ligne("SELECT d.*, x.id AS exp_id, x.numero AS expedition, x.date_chargement, p.numero AS passage, v.immatriculation,
                     tv.libelle AS type_vehicule, t.raison_sociale AS transporteur, t.adresse, t.code_postal, t.ville,
                     CONCAT(c.prenom, ' ', c.nom) AS chauffeur, c.numero_permis
                FROM document_transport d JOIN expedition x ON x.id = d.expedition_id
                JOIN passage p ON p.id = x.passage_id JOIN vehicule v ON v.id = p.vehicule_id
                JOIN type_vehicule tv ON tv.id = v.type_vehicule_id JOIN transporteur t ON t.id = v.transporteur_id
                JOIN chauffeur c ON c.id = p.chauffeur_id
               WHERE d.id = ?", [(int) ($_GET['id'] ?? 0)]);
if (!$doc) { rediriger('expeditions.php'); }
$estBl = $doc['type'] === 'BL';

// une section par commande chargée dans le camion
$commandes = lignes("SELECT c.id, c.numero, c.adresse_livraison, c.code_postal, c.ville, cl.raison_sociale AS client,
                            COUNT(*) AS unites, SUM(ue.type = 'palette') AS palettes, SUM(ue.poids_kg) AS poids
                       FROM unite_expedition ue JOIN commande c ON c.id = ue.commande_id JOIN client cl ON cl.id = c.client_id
                      WHERE ue.expedition_id = ? GROUP BY c.id ORDER BY c.numero", [$doc['exp_id']]);
$detail = [];
foreach (lignes('SELECT ue.commande_id, ar.reference, ar.designation, l.code_lot, l.date_limite_conso, SUM(cu.quantite) AS quantite
                   FROM unite_expedition ue JOIN contenu_unite cu ON cu.unite_id = ue.id JOIN lot l ON l.id = cu.lot_id JOIN article ar ON ar.id = l.article_id
                  WHERE ue.expedition_id = ? GROUP BY ue.commande_id, l.id ORDER BY ar.reference, l.code_lot', [$doc['exp_id']]) as $l) {
    $detail[$l['commande_id']][] = $l;
}

entete(($estBl ? 'Bon de livraison ' : 'Lettre de voiture ') . $doc['numero'], 'expeditions.php');
?>
<div class="entete-page no-print">
    <a href="expedition.php?id=<?= $doc['exp_id'] ?>">← Retour à l'expédition</a>
    <button onclick="window.print()">Imprimer</button>
</div>
<div class="carte">
    <h2><?= $estBl ? 'Bon de livraison' : 'Lettre de voiture' ?> n° <?= e($doc['numero']) ?></h2>
    <p class="discret">Émis le <?= date_heure_fr($doc['date_emission']) ?> · expédition <?= e($doc['expedition']) ?><?= $doc['date_chargement'] ? ' · chargée le ' . date_heure_fr($doc['date_chargement']) : '' ?></p>
    <div class="grille">
        <div>
            <h3>Transporteur</h3>
            <p><?= e($doc['transporteur']) ?><br><?= e("{$doc['adresse']}, {$doc['code_postal']} {$doc['ville']}") ?></p>
        </div>
        <div>
            <h3>Véhicule et chauffeur</h3>
            <p><?= e($doc['immatriculation']) ?> (<?= e($doc['type_vehicule']) ?>)<br><?= e($doc['chauffeur']) ?> · permis <?= e($doc['numero_permis']) ?></p>
        </div>
    </div>
</div>

<?php if ($estBl): ?>
    <?php foreach ($commandes as $c): ?>
    <div class="carte">
        <h2>Commande <?= e($c['numero']) ?> · <?= e($c['client']) ?></h2>
        <p>Livraison : <?= e("{$c['adresse_livraison']}, {$c['code_postal']} {$c['ville']}") ?> ·
           <?= $c['unites'] ?> unité(s) dont <?= (int) $c['palettes'] ?> palette(s) · <?= nombre($c['poids'], 1) ?> kg</p>
        <div class="tableau"><table>
            <tr><th>Article</th><th>Lot</th><th>DLC</th><th class="nombre">Quantité</th></tr>
            <?php foreach ($detail[$c['id']] ?? [] as $l): ?>
            <tr><td><strong><?= e($l['reference']) ?></strong> <?= e($l['designation']) ?></td><td><?= e($l['code_lot']) ?></td>
                <td><?= date_fr($l['date_limite_conso']) ?></td><td class="nombre"><?= nombre($l['quantite']) ?></td></tr>
            <?php endforeach; ?>
        </table></div>
    </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="carte">
        <h2>Marchandises transportées</h2>
        <div class="tableau"><table>
            <tr><th>Expéditeur</th><th>Destination</th><th>Commande</th><th class="nombre">Unités</th><th class="nombre">Palettes</th><th class="nombre">Poids (kg)</th></tr>
            <?php foreach ($commandes as $c): ?>
            <tr><td><?= e($c['client']) ?></td><td><?= e("{$c['adresse_livraison']}, {$c['code_postal']} {$c['ville']}") ?></td><td><?= e($c['numero']) ?></td>
                <td class="nombre"><?= $c['unites'] ?></td><td class="nombre"><?= (int) $c['palettes'] ?></td><td class="nombre"><?= nombre($c['poids'], 1) ?></td></tr>
            <?php endforeach; ?>
        </table></div>
    </div>
<?php endif; ?>
<div class="grille">
    <div class="carte"><h3>Signature de l'expéditeur</h3><br><br></div>
    <div class="carte"><h3>Signature du <?= $estBl ? 'destinataire' : 'transporteur' ?></h3><br><br></div>
</div>
<?php pied();
