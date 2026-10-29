<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_profil('responsable', 'magasinier');

$id = (int) ($_GET['id'] ?? 0);
$liste = ligne("SELECT li.*, c.numero AS commande, c.expedition_souhaitee, c.statut AS statut_commande, cl.raison_sociale AS client,
                       CONCAT(us.prenom, ' ', us.nom) AS preparateur
                  FROM liste_prelevement li JOIN commande c ON c.id = li.commande_id JOIN client cl ON cl.id = c.client_id
                  LEFT JOIN utilisateur us ON us.id = li.preparateur_id
                 WHERE li.id = ?", [$id]);
if (!$liste) { rediriger(a_profil('magasinier') ? 'listes.php' : 'preparation.php'); }
// seul le préparateur affecté exécute la liste ; le responsable la consulte
$estPreparateur = $u['profil'] === 'magasinier' && (int) $liste['preparateur_id'] === (int) $u['id'];
$erreurs = [];

// ---------------------------------------------------------------------
//  Actions du préparateur
// ---------------------------------------------------------------------
if (est_post() && $estPreparateur) {
    verifier_csrf();
    $action = $_POST['action'] ?? '';
    $pdo = db();
    try {
        if ($action === 'demarrer' && $liste['statut'] === 'a_faire') {
            requete("UPDATE liste_prelevement SET statut = 'en_cours', debut = NOW() WHERE id = ? AND statut = 'a_faire'", [$id]);
            flash('succes', 'Prélèvement commencé : suivez l\'ordre des lignes.');
        } elseif ($action === 'prelever' && $liste['statut'] === 'en_cours') {
            $l = ligne('SELECT * FROM ligne_prelevement WHERE liste_id = ? AND lot_id = ? AND emplacement_id = ? AND quantite_prelevee IS NULL',
                       [$id, $_POST['lot_id'] ?? 0, $_POST['emplacement_id'] ?? 0]);
            $qte = filter_var($_POST['quantite'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $l['quantite_a_prelever'] ?? 0]]);
            if (!$l) { $erreurs[] = 'Cette ligne a déjà été prélevée.'; }
            elseif ($qte === false) { $erreurs[] = "La quantité prélevée doit être comprise entre 0 et {$l['quantite_a_prelever']}."; }
            else {
                $pdo->beginTransaction();
                if ($qte > 0) {
                    // sortie de stock : le déclencheur vérifie la quantité présente sur l'emplacement
                    requete("INSERT INTO mouvement_stock (type, lot_id, quantite, emplacement_source_id, emplacement_destination_id, utilisateur_id, motif)
                             VALUES ('sortie', ?, ?, ?, NULL, ?, ?)", [$l['lot_id'], $qte, $l['emplacement_id'], $u['id'], 'Prélèvement ' . $liste['numero']]);
                }
                requete('UPDATE ligne_prelevement SET quantite_prelevee = ? WHERE liste_id = ? AND lot_id = ? AND emplacement_id = ?',
                        [$qte, $id, $l['lot_id'], $l['emplacement_id']]);
                $pdo->commit();
                flash($qte < $l['quantite_a_prelever'] ? 'info' : 'succes',
                      $qte < $l['quantite_a_prelever'] ? 'Ligne enregistrée avec un manquant de ' . nombre($l['quantite_a_prelever'] - $qte) . ' unité(s).' : 'Ligne prélevée.');
            }
        } elseif ($action === 'cloturer') {
            requete('CALL cloturer_liste_prelevement(?)', [$id]);   // contrôle les lignes et met à jour la commande
            flash('succes', "Liste {$liste['numero']} terminée.");
            rediriger('listes.php');
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $erreurs[] = message_erreur($e);
    }
    if (!$erreurs) { rediriger("liste.php?id=$id"); }
}

// ---------------------------------------------------------------------
//  Données : lignes dans l'ordre du parcours
// ---------------------------------------------------------------------
$lignes = lignes('SELECT lp.*, e.code AS emplacement, l.code_lot, l.date_limite_conso, ar.reference, ar.designation,
                         COALESCE(s.quantite, 0) AS en_stock
                    FROM ligne_prelevement lp
                    JOIN emplacement e ON e.id = lp.emplacement_id JOIN lot l ON l.id = lp.lot_id JOIN article ar ON ar.id = l.article_id
                    LEFT JOIN stock s ON s.lot_id = lp.lot_id AND s.emplacement_id = lp.emplacement_id
                   WHERE lp.liste_id = ? ORDER BY lp.ordre', [$id]);
$restantes = count(array_filter($lignes, fn($l) => $l['quantite_prelevee'] === null));
$suivante = $restantes ? array_values(array_filter($lignes, fn($l) => $l['quantite_prelevee'] === null))[0]['ordre'] : null;

entete("Liste {$liste['numero']}", a_profil('magasinier') ? 'listes.php' : 'preparation.php');
?>
<?php foreach ($erreurs as $err): ?><div class="alerte erreur"><?= e($err) ?></div><?php endforeach; ?>

<div class="carte">
    <h2>
        <span><?= pastille($liste['statut']) ?> Commande <?= e($liste['commande']) ?> · <?= e($liste['client']) ?></span>
        <?php if ($estPreparateur && $liste['statut'] === 'a_faire'): ?>
        <form method="post"><?= champ_csrf() ?><button name="action" value="demarrer">Commencer le prélèvement</button></form>
        <?php elseif ($estPreparateur && $liste['statut'] === 'en_cours' && !$restantes): ?>
        <form method="post" onsubmit="return confirm('Clôturer la liste ? Les quantités saisies ne pourront plus être modifiées.')">
            <?= champ_csrf() ?><button name="action" value="cloturer">Clôturer la liste</button>
        </form>
        <?php endif; ?>
    </h2>
    <p class="discret">
        Règle de sortie <strong><?= e($liste['regle_sortie']) ?></strong> · expédition souhaitée le <?= date_fr($liste['expedition_souhaitee']) ?> ·
        préparateur <?= e($liste['preparateur'] ?? 'non affecté') ?>
        <?= $liste['debut'] ? ' · début ' . date_heure_fr($liste['debut']) : '' ?><?= $liste['fin'] ? ' · fin ' . date_heure_fr($liste['fin']) : '' ?>
        · commande <?= pastille($liste['statut_commande']) ?>
    </p>
    <?php if (!$estPreparateur && $liste['statut'] !== 'terminee'): ?>
    <p class="discret">Lecture seule : seul le préparateur affecté exécute cette liste.</p>
    <?php endif; ?>
</div>

<div class="carte">
    <h2>Lignes à prélever <span class="discret"><?= count($lignes) - $restantes ?> / <?= count($lignes) ?> traitée(s)</span></h2>
    <?php if (!$lignes): ?><p class="vide">Aucun stock n'était disponible à la génération de cette liste.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>#</th><th>Emplacement</th><th>Article</th><th>Lot</th><th>DLC</th><th class="nombre">À prélever</th><th class="nombre">En stock</th><th>Prélevé</th></tr>
        <?php foreach ($lignes as $l): ?>
        <tr<?= $l['ordre'] === $suivante && $liste['statut'] === 'en_cours' ? ' style="background:#eef4fb"' : '' ?>>
            <td><?= $l['ordre'] ?></td>
            <td><strong><?= e($l['emplacement']) ?></strong></td>
            <td><strong><?= e($l['reference']) ?></strong> <?= e($l['designation']) ?></td>
            <td><a href="lot.php?code=<?= e($l['code_lot']) ?>"><?= e($l['code_lot']) ?></a></td>
            <td><?= date_fr($l['date_limite_conso']) ?></td>
            <td class="nombre"><?= nombre($l['quantite_a_prelever']) ?></td>
            <td class="nombre"><?= $l['quantite_prelevee'] === null ? nombre($l['en_stock']) : '' ?></td>
            <td>
                <?php if ($l['quantite_prelevee'] !== null): ?>
                    <?= $l['quantite_prelevee'] < $l['quantite_a_prelever']
                        ? '<span class="pastille orange">' . nombre($l['quantite_prelevee']) . ' (manque ' . nombre($l['quantite_a_prelever'] - $l['quantite_prelevee']) . ')</span>'
                        : '<span class="pastille vert">' . nombre($l['quantite_prelevee']) . '</span>' ?>
                <?php elseif ($estPreparateur && $liste['statut'] === 'en_cours'): ?>
                <form method="post" class="actions">
                    <?= champ_csrf() ?><input type="hidden" name="action" value="prelever">
                    <input type="hidden" name="lot_id" value="<?= $l['lot_id'] ?>"><input type="hidden" name="emplacement_id" value="<?= $l['emplacement_id'] ?>">
                    <input type="number" name="quantite" min="0" max="<?= $l['quantite_a_prelever'] ?>" value="<?= min($l['quantite_a_prelever'], $l['en_stock']) ?>" style="width:90px" required>
                    <button class="petit">Valider</button>
                </form>
                <?php else: ?>–<?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>
<?php pied();
