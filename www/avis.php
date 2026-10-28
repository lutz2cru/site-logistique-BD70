<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable', 'magasinier');

if (est_post()) {
    verifier_csrf();
    try {
        if (isset($_POST['supprimer'])) {
            $n = requete("DELETE FROM avis_expedition WHERE id = ? AND statut <> 'recu'
                            AND NOT EXISTS (SELECT 1 FROM reception WHERE avis_id = ?)", [$_POST['supprimer'], $_POST['supprimer']])->rowCount();
            flash($n ? 'succes' : 'erreur', $n ? 'Avis supprimé.' : 'Un avis déjà réceptionné ne peut pas être supprimé.');
        } elseif (isset($_POST['annuler'])) {
            requete("UPDATE avis_expedition SET statut = 'annule' WHERE id = ? AND statut = 'attendu'", [$_POST['annuler']]);
            flash('succes', 'Avis annulé.');
        }
    } catch (PDOException $e) {
        flash('erreur', message_erreur($e));
    }
    rediriger('avis.php?' . http_build_query($_GET));
}

$f = ['du' => filtre('du') ?: date('Y-m-d', strtotime('-3 days')), 'au' => filtre('au') ?: date('Y-m-d', strtotime('+7 days')),
      'statut' => filtre('statut'), 'fournisseur' => filtre('fournisseur'), 'q' => filtre('q')];
$where = ['a.livraison_prevue BETWEEN ? AND ?'];
$params = [$f['du'], $f['au']];
if ($f['statut'] !== '') { $where[] = 'a.statut = ?'; $params[] = $f['statut']; }
if ($f['fournisseur'] !== '') { $where[] = 'a.fournisseur_id = ?'; $params[] = $f['fournisseur']; }
if ($f['q'] !== '') { $where[] = 'a.numero LIKE ?'; $params[] = "%{$f['q']}%"; }
$from = 'FROM avis_expedition a JOIN fournisseur f ON f.id = a.fournisseur_id WHERE ' . implode(' AND ', $where);
$total = (int) valeur("SELECT COUNT(*) $from", $params);
[$limite, $decalage, $page, $pages] = pagination($total);
$avis = lignes("SELECT a.*, f.raison_sociale,
                       (SELECT COUNT(*) FROM ligne_avis la WHERE la.avis_id = a.id) AS nb_lignes,
                       (SELECT SUM(quantite_attendue) FROM ligne_avis la WHERE la.avis_id = a.id) AS unites,
                       (SELECT r.id FROM reception r WHERE r.avis_id = a.id LIMIT 1) AS reception_id
                $from ORDER BY a.livraison_prevue DESC, a.numero DESC LIMIT $limite OFFSET $decalage", $params);

$fournisseurs = paires('SELECT id, raison_sociale FROM fournisseur ORDER BY raison_sociale');
entete('Avis d\'expédition');
?>
<div class="entete-page">
    <span class="discret">Annonces de livraison envoyées par les fournisseurs (liste des articles et quantités attendues).</span>
    <a class="bouton" href="avis_form.php">+ Nouvel avis</a>
</div>
<div class="carte">
    <form class="filtres" method="get">
        <label>Livraison du <input type="date" name="du" value="<?= e($f['du']) ?>"></label>
        <label>au <input type="date" name="au" value="<?= e($f['au']) ?>"></label>
        <label>Statut <select name="statut"><?= options(['attendu' => 'Attendu', 'recu' => 'Reçu', 'annule' => 'Annulé'], $f['statut'], 'Tous') ?></select></label>
        <label>Fournisseur <select name="fournisseur"><?= options($fournisseurs, $f['fournisseur'], 'Tous') ?></select></label>
        <label>Numéro <input name="q" value="<?= e($f['q']) ?>"></label>
        <button type="submit">Filtrer</button>
        <a class="bouton discret" href="avis.php">Réinitialiser</a>
    </form>
    <?php if (!$avis): ?><p class="vide">Aucun avis sur cette période.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Numéro</th><th>Fournisseur</th><th>Émis le</th><th>Livraison prévue</th><th class="nombre">Lignes</th><th class="nombre">Unités</th><th>Statut</th><th></th></tr>
        <?php foreach ($avis as $a): ?>
        <tr>
            <td><?= e($a['numero']) ?></td><td><?= e($a['raison_sociale']) ?></td>
            <td><?= date_fr($a['date_emission']) ?></td><td><?= date_fr($a['livraison_prevue']) ?></td>
            <td class="nombre"><?= $a['nb_lignes'] ?></td><td class="nombre"><?= nombre($a['unites']) ?></td>
            <td><?= pastille($a['statut']) ?></td>
            <td class="actions-ligne">
                <?php if ($a['reception_id']): ?>
                    <a class="bouton petit discret" href="reception.php?id=<?= $a['reception_id'] ?>">Réception</a>
                <?php elseif ($a['statut'] === 'attendu'): ?>
                    <a class="bouton petit secondaire" href="avis_form.php?id=<?= $a['id'] ?>">Modifier</a>
                    <form method="post"><?= champ_csrf() ?><button class="petit discret" name="annuler" value="<?= $a['id'] ?>">Annuler</button></form>
                    <form method="post" onsubmit="return confirm('Supprimer cet avis ?')"><?= champ_csrf() ?><button class="petit danger" name="supprimer" value="<?= $a['id'] ?>">Supprimer</button></form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?= liens_pagination($page, $pages) ?>
    <?php endif; ?>
</div>
<?php pied();
