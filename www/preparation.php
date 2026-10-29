<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable');

$preparateurs = paires("SELECT u.id, CONCAT(u.prenom, ' ', u.nom) FROM utilisateur u JOIN profil p ON p.id = u.profil_id
                         WHERE p.code = 'magasinier' AND u.actif ORDER BY u.nom, u.prenom");

// ---------------------------------------------------------------------
//  Actions : génération et affectation des listes de prélèvement
// ---------------------------------------------------------------------
if (est_post()) {
    verifier_csrf();
    try {
        if (isset($_POST['generer'])) {
            $regle = $_POST['regle'] ?? '';
            if (!valeur("SELECT COUNT(*) FROM commande WHERE id = ? AND statut = 'recue'", [$_POST['generer']])) {
                throw new RuntimeException('Cette commande n\'est plus à préparer.');
            }
            // la procédure applique la règle FIFO / FEFO et réserve les quantités
            requete('CALL generer_liste_prelevement(?, ?, @liste)', [$_POST['generer'], $regle]);
            $liste = ligne('SELECT id, numero, (SELECT COUNT(*) FROM ligne_prelevement WHERE liste_id = @liste) AS nb FROM liste_prelevement WHERE id = @liste');
            $prep = (int) ($_POST['preparateur_id'] ?? 0);
            if ($prep && isset($preparateurs[$prep])) {
                requete('UPDATE liste_prelevement SET preparateur_id = ? WHERE id = ?', [$prep, $liste['id']]);
            }
            flash($liste['nb'] ? 'succes' : 'info', $liste['nb'] ? "Liste {$liste['numero']} générée ({$liste['nb']} ligne(s))."
                                                              : "Liste {$liste['numero']} générée, mais aucun stock disponible pour cette commande.");
        } elseif (isset($_POST['affecter'])) {
            $prep = (int) ($_POST['preparateur_id'] ?? 0);
            if (!isset($preparateurs[$prep])) { throw new RuntimeException('Choisissez un préparateur.'); }
            $n = requete("UPDATE liste_prelevement SET preparateur_id = ? WHERE id = ? AND statut = 'a_faire'", [$prep, $_POST['affecter']])->rowCount();
            flash($n ? 'succes' : 'erreur', $n ? "Liste affectée à {$preparateurs[$prep]}." : 'Seule une liste non commencée peut être réaffectée.');
        }
    } catch (RuntimeException $e) {
        flash('erreur', $e->getMessage());
    } catch (PDOException $e) {
        flash('erreur', message_erreur($e));
    }
    rediriger('preparation.php');
}

// ---------------------------------------------------------------------
//  Données
// ---------------------------------------------------------------------
$aPreparer = lignes("SELECT c.id, c.numero, c.date_commande, c.expedition_souhaitee, cl.raison_sociale AS client,
                            COUNT(lc.article_id) AS nb_articles, SUM(lc.quantite) AS unites
                       FROM commande c JOIN client cl ON cl.id = c.client_id JOIN ligne_commande lc ON lc.commande_id = c.id
                      WHERE c.statut = 'recue'
                      GROUP BY c.id ORDER BY c.expedition_souhaitee, c.numero");
$listes = lignes("SELECT li.*, c.numero AS commande, c.expedition_souhaitee, cl.raison_sociale AS client,
                         CONCAT(u.prenom, ' ', u.nom) AS preparateur,
                         COUNT(lp.lot_id) AS nb_lignes, SUM(lp.quantite_prelevee IS NOT NULL) AS nb_faites
                    FROM liste_prelevement li JOIN commande c ON c.id = li.commande_id JOIN client cl ON cl.id = c.client_id
                    LEFT JOIN utilisateur u ON u.id = li.preparateur_id
                    LEFT JOIN ligne_prelevement lp ON lp.liste_id = li.id
                   WHERE li.statut <> 'terminee' OR li.fin >= CURRENT_DATE
                   GROUP BY li.id
                   ORDER BY li.statut = 'terminee', c.expedition_souhaitee, li.numero");

entete('Préparation des commandes');
?>
<p class="discret">Le responsable d'exploitation génère les listes de prélèvement (règle FIFO ou FEFO) et les affecte aux préparateurs, qui les exécutent depuis « Mes listes de prélèvement ».</p>

<div class="carte">
    <h2>Commandes à préparer <span class="discret"><?= count($aPreparer) ?></span></h2>
    <?php if (!$aPreparer): ?><p class="vide">Aucune commande en attente de préparation.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Commande</th><th>Client</th><th>Reçue le</th><th>Expédition souhaitée</th><th class="nombre">Articles</th><th class="nombre">Unités</th><th>Générer la liste de prélèvement</th></tr>
        <?php foreach ($aPreparer as $c): ?>
        <tr>
            <td><strong><?= e($c['numero']) ?></strong></td><td><?= e($c['client']) ?></td>
            <td><?= date_fr($c['date_commande']) ?></td>
            <td class="<?= $c['expedition_souhaitee'] < date('Y-m-d') ? 'retard' : '' ?>"><?= date_fr($c['expedition_souhaitee']) ?></td>
            <td class="nombre"><?= $c['nb_articles'] ?></td><td class="nombre"><?= nombre($c['unites']) ?></td>
            <td>
                <form method="post" class="actions">
                    <?= champ_csrf() ?>
                    <select name="regle" title="Règle de sortie"><?= options(['FEFO' => 'FEFO (date limite)', 'FIFO' => 'FIFO (date d\'entrée)'], 'FEFO') ?></select>
                    <select name="preparateur_id"><?= options($preparateurs, null, 'Préparateur (plus tard)') ?></select>
                    <button class="petit" name="generer" value="<?= $c['id'] ?>">Générer</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>

<div class="carte">
    <h2>Listes de prélèvement <span class="discret">à faire, en cours et terminées aujourd'hui</span></h2>
    <?php if (!$listes): ?><p class="vide">Aucune liste de prélèvement.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Liste</th><th>Commande</th><th>Client</th><th>Expédition</th><th>Règle</th><th class="nombre">Lignes</th><th>Statut</th><th>Préparateur</th></tr>
        <?php foreach ($listes as $l): ?>
        <tr>
            <td><a href="liste.php?id=<?= $l['id'] ?>"><?= e($l['numero']) ?></a></td>
            <td><?= e($l['commande']) ?></td><td><?= e($l['client']) ?></td>
            <td><?= date_fr($l['expedition_souhaitee']) ?></td><td><?= e($l['regle_sortie']) ?></td>
            <td class="nombre"><?= (int) $l['nb_faites'] ?> / <?= $l['nb_lignes'] ?></td>
            <td><?= pastille($l['statut']) ?></td>
            <td>
                <?php if ($l['statut'] === 'a_faire'): ?>
                <form method="post" class="actions">
                    <?= champ_csrf() ?>
                    <select name="preparateur_id"><?= options($preparateurs, $l['preparateur_id'], '— Non affectée —') ?></select>
                    <button class="petit secondaire" name="affecter" value="<?= $l['id'] ?>"><?= $l['preparateur_id'] ? 'Réaffecter' : 'Affecter' ?></button>
                </form>
                <?php else: ?><?= e($l['preparateur']) ?><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>
<?php pied();
