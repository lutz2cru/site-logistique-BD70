<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_profil('magasinier');

// Prise en charge d'une liste non affectée
if (est_post() && isset($_POST['prendre'])) {
    verifier_csrf();
    $n = requete("UPDATE liste_prelevement SET preparateur_id = ? WHERE id = ? AND preparateur_id IS NULL AND statut = 'a_faire'",
                 [$u['id'], $_POST['prendre']])->rowCount();
    flash($n ? 'succes' : 'erreur', $n ? 'Liste prise en charge.' : 'Cette liste a déjà été affectée.');
    rediriger($n ? 'liste.php?id=' . (int) $_POST['prendre'] : 'listes.php');
}

$sql = "SELECT li.*, c.numero AS commande, c.expedition_souhaitee, cl.raison_sociale AS client,
               COUNT(lp.lot_id) AS nb_lignes, SUM(lp.quantite_prelevee IS NOT NULL) AS nb_faites, SUM(lp.quantite_a_prelever) AS unites
          FROM liste_prelevement li JOIN commande c ON c.id = li.commande_id JOIN client cl ON cl.id = c.client_id
          LEFT JOIN ligne_prelevement lp ON lp.liste_id = li.id
         WHERE %s GROUP BY li.id ORDER BY li.statut = 'a_faire', c.expedition_souhaitee, li.numero";
$mesListes = lignes(sprintf($sql, "li.preparateur_id = ? AND li.statut <> 'terminee'"), [$u['id']]);
$libres = lignes(sprintf($sql, "li.preparateur_id IS NULL AND li.statut = 'a_faire'"));
$terminees = lignes(sprintf($sql, "li.preparateur_id = ? AND li.statut = 'terminee' AND li.fin >= CURRENT_DATE"), [$u['id']]);

/** Tableau de listes ; $prendre ajoute le bouton de prise en charge. */
function tableau_listes(array $listes, bool $prendre = false): void
{
    ?>
    <div class="tableau"><table>
        <tr><th>Liste</th><th>Commande</th><th>Client</th><th>Expédition</th><th>Règle</th><th class="nombre">Lignes</th><th class="nombre">Unités</th><th>Statut</th><th></th></tr>
        <?php foreach ($listes as $l): ?>
        <tr>
            <td><a href="liste.php?id=<?= $l['id'] ?>"><strong><?= e($l['numero']) ?></strong></a></td>
            <td><?= e($l['commande']) ?></td><td><?= e($l['client']) ?></td>
            <td class="<?= $l['statut'] !== 'terminee' && $l['expedition_souhaitee'] < date('Y-m-d') ? 'retard' : '' ?>"><?= date_fr($l['expedition_souhaitee']) ?></td>
            <td><?= e($l['regle_sortie']) ?></td>
            <td class="nombre"><?= (int) $l['nb_faites'] ?> / <?= $l['nb_lignes'] ?></td>
            <td class="nombre"><?= nombre($l['unites']) ?></td>
            <td><?= pastille($l['statut']) ?></td>
            <td class="actions-ligne">
                <?php if ($prendre): ?>
                <form method="post"><?= champ_csrf() ?><button class="petit" name="prendre" value="<?= $l['id'] ?>">Prendre en charge</button></form>
                <?php elseif ($l['statut'] !== 'terminee'): ?>
                <a class="bouton petit" href="liste.php?id=<?= $l['id'] ?>"><?= $l['statut'] === 'a_faire' ? 'Commencer' : 'Continuer' ?></a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php
}

entete('Mes listes de prélèvement');
?>
<div class="carte">
    <h2>Mes listes à préparer <span class="discret"><?= count($mesListes) ?></span></h2>
    <?php if (!$mesListes): ?><p class="vide">Aucune liste ne vous est affectée pour le moment.</p><?php else: tableau_listes($mesListes); endif; ?>
</div>

<?php if ($libres): ?>
<div class="carte">
    <h2>Listes non affectées <span class="discret"><?= count($libres) ?></span></h2>
    <?php tableau_listes($libres, true); ?>
</div>
<?php endif; ?>

<div class="carte">
    <h2>Terminées aujourd'hui</h2>
    <?php if (!$terminees): ?><p class="vide">Aucune liste terminée aujourd'hui.</p><?php else: tableau_listes($terminees); endif; ?>
</div>
<?php pied();
