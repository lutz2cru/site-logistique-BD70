<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_profil('client');

$f = ['du' => filtre('du') ?: date('Y-m-d', strtotime('-30 days')), 'au' => filtre('au') ?: date('Y-m-d', strtotime('+30 days')),
      'statut' => filtre('statut'), 'q' => filtre('q')];
$where = ['c.client_id = ?', 'c.expedition_souhaitee >= ?', 'c.expedition_souhaitee <= ?'];
$params = [$u['client_id'], $f['du'], $f['au']];
if ($f['statut'] !== '') { $where[] = 'c.statut = ?'; $params[] = $f['statut']; }
if ($f['q'] !== '') { $where[] = '(c.numero LIKE ? OR c.ville LIKE ?)'; array_push($params, "%{$f['q']}%", "%{$f['q']}%"); }
$from = 'FROM commande c WHERE ' . implode(' AND ', $where);
$total = (int) valeur("SELECT COUNT(*) $from", $params);
[$limite, $decalage, $page, $pages] = pagination($total);
$commandes = lignes("SELECT c.*, (SELECT COUNT(*) FROM ligne_commande lc WHERE lc.commande_id = c.id) AS nb_articles,
                            (SELECT SUM(quantite) FROM ligne_commande lc WHERE lc.commande_id = c.id) AS unites,
                            (SELECT MAX(ex.numero) FROM unite_expedition ue JOIN expedition ex ON ex.id = ue.expedition_id
                              WHERE ue.commande_id = c.id) AS expedition
                     $from ORDER BY c.expedition_souhaitee DESC, c.numero DESC LIMIT $limite OFFSET $decalage", $params);
$parStatut = paires('SELECT statut, COUNT(*) FROM commande WHERE client_id = ? AND statut NOT IN (\'expediee\', \'annulee\') GROUP BY statut', [$u['client_id']]);

entete('Mes commandes');
?>
<div class="entete-page">
    <span class="discret">
        <?php foreach (['recue', 'en_preparation', 'preparee'] as $s): ?><?= pastille($s) ?> <?= (int) ($parStatut[$s] ?? 0) ?> &nbsp; <?php endforeach; ?>
    </span>
    <a class="bouton" href="client_commande.php">+ Nouvelle commande</a>
</div>
<div class="carte">
    <h2>Commandes <span class="discret"><?= nombre($total) ?></span></h2>
    <form class="filtres" method="get">
        <label>Expédition du <input type="date" name="du" value="<?= e($f['du']) ?>"></label>
        <label>au <input type="date" name="au" value="<?= e($f['au']) ?>"></label>
        <label>Statut <select name="statut"><?= options(['recue' => 'Reçue', 'en_preparation' => 'En préparation', 'preparee' => 'Préparée',
                                                        'expediee' => 'Expédiée', 'annulee' => 'Annulée'], $f['statut'], 'Tous') ?></select></label>
        <label>N° / ville <input name="q" value="<?= e($f['q']) ?>"></label>
        <button type="submit">Filtrer</button>
        <a class="bouton discret" href="client_commandes.php">Réinitialiser</a>
    </form>
    <?php if (!$commandes): ?><p class="vide">Aucune commande sur cette période.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Numéro</th><th>Passée le</th><th>Expédition souhaitée</th><th>Livraison</th><th class="nombre">Articles</th><th class="nombre">Unités</th><th>Canal</th><th>Statut</th><th>Expédition</th></tr>
        <?php foreach ($commandes as $c): ?>
        <tr>
            <td><a href="client_commande.php?id=<?= $c['id'] ?>"><strong><?= e($c['numero']) ?></strong></a></td>
            <td><?= date_fr($c['date_commande']) ?></td><td><?= date_fr($c['expedition_souhaitee']) ?></td>
            <td><?= e($c['code_postal'] . ' ' . $c['ville']) ?></td>
            <td class="nombre"><?= $c['nb_articles'] ?></td><td class="nombre"><?= nombre($c['unites']) ?></td>
            <td><?= pastille($c['canal']) ?></td><td><?= pastille($c['statut']) ?></td>
            <td><?= e($c['expedition']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?= liens_pagination($page, $pages) ?>
    <?php endif; ?>
</div>
<?php pied();
