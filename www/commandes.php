<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable');

// ---------------------------------------------------------------------
//  Recherche multicritère
// ---------------------------------------------------------------------
$f = ['du' => filtre('du') ?: date('Y-m-d', strtotime('-30 days')), 'au' => filtre('au') ?: date('Y-m-d', strtotime('+30 days')),
      'client' => filtre('client'), 'statut' => filtre('statut'), 'q' => filtre('q')];
$where = ['c.expedition_souhaitee BETWEEN ? AND ?'];
$params = [$f['du'], $f['au']];
if ($f['client'] !== '') { $where[] = 'c.client_id = ?'; $params[] = $f['client']; }
if ($f['statut'] !== '') { $where[] = 'c.statut = ?'; $params[] = $f['statut']; }
if ($f['q'] !== '') {
    $where[] = '(c.numero LIKE ? OR c.ville LIKE ?)';
    array_push($params, "%{$f['q']}%", "%{$f['q']}%");
}
$from = 'FROM commande c JOIN client cl ON cl.id = c.client_id WHERE ' . implode(' AND ', $where);

$total = (int) valeur("SELECT COUNT(*) $from", $params);
[$limite, $decalage, $page, $pages] = pagination($total);
$commandes = lignes("SELECT c.*, cl.raison_sociale AS client,
                            (SELECT COUNT(*) FROM ligne_commande lc WHERE lc.commande_id = c.id) AS nb_articles,
                            (SELECT SUM(lc.quantite) FROM ligne_commande lc WHERE lc.commande_id = c.id) AS unites
                     $from ORDER BY c.expedition_souhaitee DESC, c.numero DESC LIMIT $limite OFFSET $decalage", $params);

$clients = paires('SELECT id, raison_sociale FROM client ORDER BY raison_sociale');
entete('Commandes clients');
?>
<div class="entete-page">
    <span class="discret"><?= nombre($total) ?> commande(s)</span>
    <a class="bouton" href="commande_form.php">+ Nouvelle commande</a>
</div>
<div class="carte">
    <form class="filtres" method="get">
        <label>Expédition du <input type="date" name="du" value="<?= e($f['du']) ?>"></label>
        <label>au <input type="date" name="au" value="<?= e($f['au']) ?>"></label>
        <label>Client <select name="client"><?= options($clients, $f['client'], 'Tous') ?></select></label>
        <label>Statut <select name="statut"><?= options(['recue' => 'Reçue', 'en_preparation' => 'En préparation', 'preparee' => 'Préparée', 'expediee' => 'Expédiée', 'annulee' => 'Annulée'], $f['statut'], 'Tous') ?></select></label>
        <label>N° ou ville <input name="q" value="<?= e($f['q']) ?>"></label>
        <button type="submit">Rechercher</button>
        <a class="bouton discret" href="commandes.php">Réinitialiser</a>
    </form>
    <?php if (!$commandes): ?><p class="vide">Aucune commande ne correspond à ces critères.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Numéro</th><th>Client</th><th>Commandée le</th><th>Expédition souhaitée</th><th>Destination</th><th class="nombre">Articles</th><th class="nombre">Unités</th><th>Statut</th></tr>
        <?php foreach ($commandes as $c): ?>
        <tr>
            <td><a href="commande_form.php?id=<?= $c['id'] ?>"><strong><?= e($c['numero']) ?></strong></a></td>
            <td><?= e($c['client']) ?></td>
            <td><?= date_fr($c['date_commande']) ?></td>
            <td><?= date_fr($c['expedition_souhaitee']) ?></td>
            <td><?= e("{$c['code_postal']} {$c['ville']}") ?></td>
            <td class="nombre"><?= $c['nb_articles'] ?></td>
            <td class="nombre"><?= nombre($c['unites']) ?></td>
            <td><?= pastille($c['statut']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?= liens_pagination($page, $pages) ?>
    <?php endif; ?>
</div>
<?php pied();
