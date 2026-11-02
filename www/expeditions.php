<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable', 'magasinier');

// Commandes préparées : marchandise à conditionner ou unités à charger
$aTraiter = lignes("SELECT c.id, c.numero, c.expedition_souhaitee, cl.raison_sociale AS client,
                           (SELECT COALESCE(SUM(lp.quantite_prelevee), 0) FROM ligne_prelevement lp JOIN liste_prelevement li ON li.id = lp.liste_id
                             WHERE li.commande_id = c.id) AS prelevee,
                           (SELECT COALESCE(SUM(cu.quantite), 0) FROM contenu_unite cu JOIN unite_expedition ue ON ue.id = cu.unite_id
                             WHERE ue.commande_id = c.id) AS conditionnee,
                           (SELECT COUNT(*) FROM unite_expedition ue WHERE ue.commande_id = c.id AND ue.expedition_id IS NULL) AS a_charger
                      FROM commande c JOIN client cl ON cl.id = c.client_id
                     WHERE c.statut = 'preparee'
                     ORDER BY c.expedition_souhaitee, c.numero");

// Camions d'expédition présents à quai
$camions = lignes("SELECT p.id, v.immatriculation, t.raison_sociale, q.numero AS quai, te.libelle AS etape, x.id AS expedition_id, x.numero AS expedition, x.statut
                     FROM passage p JOIN type_etape te ON te.id = p.etape_courante_id
                     JOIN vehicule v ON v.id = p.vehicule_id JOIN transporteur t ON t.id = v.transporteur_id
                     LEFT JOIN quai q ON q.id = p.quai_id
                     LEFT JOIN expedition x ON x.passage_id = p.id
                    WHERE p.sens = 'expedition' AND te.code IN ('APPEL_QUAI', 'DEBUT_OP', 'FIN_OP')
                    ORDER BY q.numero");

// Recherche des expéditions
$f = ['du' => filtre('du') ?: date('Y-m-d', strtotime('-7 days')), 'au' => filtre('au') ?: date('Y-m-d'),
      'statut' => filtre('statut'), 'q' => filtre('q')];
$where = ['x.date_chargement >= ?', 'x.date_chargement < ? + INTERVAL 1 DAY'];
$params = [$f['du'], $f['au']];
if ($f['statut'] !== '') { $where[] = 'x.statut = ?'; $params[] = $f['statut']; }
if ($f['q'] !== '') { $where[] = '(x.numero LIKE ? OR v.immatriculation LIKE ?)'; array_push($params, "%{$f['q']}%", "%{$f['q']}%"); }
$from = 'FROM expedition x JOIN passage p ON p.id = x.passage_id JOIN vehicule v ON v.id = p.vehicule_id
         JOIN transporteur t ON t.id = v.transporteur_id WHERE ' . implode(' AND ', $where);
$total = (int) valeur("SELECT COUNT(*) $from", $params);
[$limite, $decalage, $page, $pages] = pagination($total);
$expeditions = lignes("SELECT x.*, v.immatriculation, t.raison_sociale,
                              (SELECT COUNT(*) FROM unite_expedition ue WHERE ue.expedition_id = x.id) AS unites,
                              (SELECT COUNT(*) FROM document_transport d WHERE d.expedition_id = x.id) AS documents
                       $from ORDER BY x.date_chargement DESC LIMIT $limite OFFSET $decalage", $params);

entete('Colis et expéditions');
?>
<div class="carte">
    <h2>Commandes préparées <span class="discret"><?= count($aTraiter) ?></span></h2>
    <?php if (!$aTraiter): ?><p class="vide">Aucune commande préparée en attente d'expédition.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Commande</th><th>Client</th><th>Expédition</th><th class="nombre">Prélevé</th><th class="nombre">Conditionné</th><th class="nombre">Unités à charger</th><th></th></tr>
        <?php foreach ($aTraiter as $c): ?>
        <tr>
            <td><strong><?= e($c['numero']) ?></strong></td><td><?= e($c['client']) ?></td>
            <td class="<?= $c['expedition_souhaitee'] < date('Y-m-d') ? 'retard' : '' ?>"><?= date_fr($c['expedition_souhaitee']) ?></td>
            <td class="nombre"><?= nombre($c['prelevee']) ?></td>
            <td class="nombre"><?= nombre($c['conditionnee']) ?></td>
            <td class="nombre"><?= $c['a_charger'] ?></td>
            <td class="actions-ligne"><a class="bouton petit" href="colisage.php?id=<?= $c['id'] ?>"><?= a_profil('magasinier') ? 'Constituer les colis' : 'Consulter' ?></a></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>

<div class="carte">
    <h2>Camions à quai pour un chargement <span class="discret"><?= count($camions) ?></span></h2>
    <?php if (!$camions): ?><p class="vide">Aucun camion sortant à quai.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Quai</th><th>Véhicule</th><th>Transporteur</th><th>Étape</th><th>Expédition</th><th></th></tr>
        <?php foreach ($camions as $c): ?>
        <tr>
            <td><strong><?= e($c['quai']) ?></strong></td><td><?= e($c['immatriculation']) ?></td>
            <td><?= e($c['raison_sociale']) ?></td><td><?= e($c['etape']) ?></td>
            <td><?= $c['expedition'] ? e($c['expedition']) . ' ' . pastille($c['statut']) : '–' ?></td>
            <td class="actions-ligne"><a class="bouton petit" href="expedition.php?passage=<?= $c['id'] ?>"><?= a_profil('magasinier') ? 'Charger le camion' : 'Consulter' ?></a></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>

<div class="carte">
    <h2>Expéditions <span class="discret"><?= nombre($total) ?></span></h2>
    <form class="filtres" method="get">
        <label>Du <input type="date" name="du" value="<?= e($f['du']) ?>"></label>
        <label>Au <input type="date" name="au" value="<?= e($f['au']) ?>"></label>
        <label>Statut <select name="statut"><?= options(['en_chargement' => 'En chargement', 'chargee' => 'Chargée', 'partie' => 'Partie'], $f['statut'], 'Tous') ?></select></label>
        <label>N° ou immatriculation <input name="q" value="<?= e($f['q']) ?>"></label>
        <button type="submit">Filtrer</button>
        <a class="bouton discret" href="expeditions.php">Réinitialiser</a>
    </form>
    <?php if (!$expeditions): ?><p class="vide">Aucune expédition sur cette période.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Numéro</th><th>Chargement</th><th>Véhicule</th><th>Transporteur</th><th class="nombre">Unités</th><th class="nombre">Documents</th><th>Statut</th></tr>
        <?php foreach ($expeditions as $x): ?>
        <tr>
            <td><a href="expedition.php?id=<?= $x['id'] ?>"><strong><?= e($x['numero']) ?></strong></a></td>
            <td><?= date_heure_fr($x['date_chargement']) ?></td>
            <td><?= e($x['immatriculation']) ?></td><td><?= e($x['raison_sociale']) ?></td>
            <td class="nombre"><?= $x['unites'] ?></td><td class="nombre"><?= $x['documents'] ?></td>
            <td><?= pastille($x['statut']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?= liens_pagination($page, $pages) ?>
    <?php endif; ?>
</div>
<?php pied();
