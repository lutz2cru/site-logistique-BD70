<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable', 'magasinier');
$peutSaisir = a_profil('magasinier');   // le responsable consulte, le magasinier constitue les colis

const TARE_KG = ['colis' => 0.3, 'palette' => 25];

/** Numéro SSCC à 18 chiffres : 17 chiffres puis la clé de contrôle GS1. */
function nouveau_sscc(): string
{
    $base = '4' . str_pad((string) random_int(0, 9999999999999999), 16, '0', STR_PAD_LEFT);
    $somme = 0;
    foreach (str_split($base) as $i => $chiffre) { $somme += (int) $chiffre * ($i % 2 === 0 ? 3 : 1); }
    return $base . (10 - $somme % 10) % 10;
}

$id = (int) ($_GET['id'] ?? 0);
$cmd = ligne('SELECT c.*, cl.raison_sociale AS client FROM commande c JOIN client cl ON cl.id = c.client_id WHERE c.id = ?', [$id]);
if (!$cmd) { rediriger('expeditions.php'); }
$ouverte = $cmd['statut'] === 'preparee';
$erreurs = [];

// lots prélevés pour cette commande, moins ce qui est déjà dans un colis ou une palette
$restes = lignes('SELECT l.id, l.code_lot, l.date_limite_conso, ar.reference, ar.designation, ar.poids_kg,
                         ar.unites_par_colis, ar.unites_par_colis * ar.colis_par_palette AS par_palette,
                         x.prelevee - COALESCE(u.conditionnee, 0) AS reste
                    FROM (SELECT lp.lot_id, SUM(lp.quantite_prelevee) AS prelevee
                            FROM ligne_prelevement lp JOIN liste_prelevement li ON li.id = lp.liste_id
                           WHERE li.commande_id = ? GROUP BY lp.lot_id) x
                    JOIN lot l ON l.id = x.lot_id JOIN article ar ON ar.id = l.article_id
                    LEFT JOIN (SELECT cu.lot_id, SUM(cu.quantite) AS conditionnee
                                 FROM contenu_unite cu JOIN unite_expedition ue ON ue.id = cu.unite_id
                                WHERE ue.commande_id = ? GROUP BY cu.lot_id) u ON u.lot_id = x.lot_id
                   HAVING reste > 0 ORDER BY ar.reference, l.code_lot', [$id, $id]);

// ---------------------------------------------------------------------
//  Actions du magasinier
// ---------------------------------------------------------------------
if (est_post()) {
    verifier_csrf();
    if (!$peutSaisir || !$ouverte) { exit('Action non autorisée.'); }
    $pdo = db();
    try {
        if (isset($_POST['supprimer'])) {
            $n = requete('DELETE FROM unite_expedition WHERE id = ? AND commande_id = ? AND expedition_id IS NULL', [$_POST['supprimer'], $id])->rowCount();
            flash($n ? 'succes' : 'erreur', $n ? 'Unité supprimée : sa marchandise est de nouveau à conditionner.' : 'Une unité chargée ne peut pas être supprimée.');
        } else {
            $type = $_POST['type'] ?? '';
            if (!isset(TARE_KG[$type])) { $erreurs[] = 'Choisissez un colis ou une palette.'; }
            $contenu = [];
            $poids = TARE_KG[$type] ?? 0;
            foreach ($restes as $r) {
                $saisi = trim((string) ($_POST['quantites'][$r['id']] ?? ''));
                if ($saisi === '' || $saisi === '0') { continue; }
                $q = filter_var($saisi, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $r['reste']]]);
                if ($q === false) { $erreurs[] = "Lot {$r['code_lot']} : la quantité doit être comprise entre 1 et {$r['reste']}."; continue; }
                $contenu[$r['id']] = $q;
                $poids += $q * $r['poids_kg'];
            }
            if (!$contenu && !$erreurs) { $erreurs[] = 'Indiquez la quantité de chaque lot à mettre dans cette unité.'; }
            if (!$erreurs) {
                $pdo->beginTransaction();
                requete('INSERT INTO unite_expedition (sscc, commande_id, type, poids_kg) VALUES (?, ?, ?, ?)', [nouveau_sscc(), $id, $type, round($poids, 2)]);
                $uniteId = (int) $pdo->lastInsertId();
                foreach ($contenu as $lot => $q) {
                    requete('INSERT INTO contenu_unite (unite_id, lot_id, quantite) VALUES (?, ?, ?)', [$uniteId, $lot, $q]);
                }
                $pdo->commit();
                flash('succes', ($type === 'colis' ? 'Colis constitué' : 'Palette constituée') . ' (' . nombre($poids, 1) . ' kg).');
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $erreurs[] = message_erreur($e);
    }
    if (!$erreurs) { rediriger("colisage.php?id=$id"); }
}

$unites = lignes("SELECT ue.*, x.numero AS expedition, x.id AS exp_id,
                         (SELECT GROUP_CONCAT(CONCAT(cu.quantite, ' × ', l.code_lot) ORDER BY l.code_lot SEPARATOR ', ')
                            FROM contenu_unite cu JOIN lot l ON l.id = cu.lot_id WHERE cu.unite_id = ue.id) AS contenu
                    FROM unite_expedition ue LEFT JOIN expedition x ON x.id = ue.expedition_id
                   WHERE ue.commande_id = ? ORDER BY ue.id", [$id]);

entete("Colis — commande {$cmd['numero']}", 'expeditions.php');
?>
<?php foreach ($erreurs as $err): ?><div class="alerte erreur"><?= e($err) ?></div><?php endforeach; ?>

<div class="carte">
    <h2><span><?= pastille($cmd['statut']) ?> <?= e($cmd['client']) ?></span></h2>
    <p class="discret">Expédition souhaitée le <?= date_fr($cmd['expedition_souhaitee']) ?> · livraison : <?= e("{$cmd['adresse_livraison']}, {$cmd['code_postal']} {$cmd['ville']}") ?></p>
</div>

<?php if ($ouverte && $peutSaisir): ?>
<div class="carte">
    <h2>Constituer une unité d'expédition</h2>
    <?php if (!$restes): ?><p class="vide">Toute la marchandise prélevée est conditionnée : la commande peut être chargée.</p><?php else: ?>
    <form method="post">
        <?= champ_csrf() ?>
        <div class="tableau"><table>
            <tr><th>Article</th><th>Lot</th><th>DLC</th><th class="nombre">U./colis</th><th class="nombre">U./palette</th><th class="nombre">Reste</th><th class="nombre">À mettre dans l'unité</th></tr>
            <?php foreach ($restes as $r): ?>
            <tr>
                <td><strong><?= e($r['reference']) ?></strong> <?= e($r['designation']) ?></td>
                <td><?= e($r['code_lot']) ?></td><td><?= date_fr($r['date_limite_conso']) ?></td>
                <td class="nombre"><?= nombre($r['unites_par_colis']) ?></td><td class="nombre"><?= nombre($r['par_palette']) ?></td>
                <td class="nombre"><?= nombre($r['reste']) ?></td>
                <td class="nombre"><input type="number" name="quantites[<?= $r['id'] ?>]" min="0" max="<?= $r['reste'] ?>" style="width:110px" value="<?= e($_POST['quantites'][$r['id']] ?? '') ?>"></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <div class="boutons">
            <button name="type" value="palette">Constituer une palette</button>
            <button name="type" value="colis" class="secondaire">Constituer un colis</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="carte">
    <h2>Colis et palettes <span class="discret"><?= count($unites) ?></span></h2>
    <?php if (!$unites): ?><p class="vide">Aucune unité constituée.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>SSCC</th><th>Type</th><th>Contenu</th><th class="nombre">Poids (kg)</th><th>Expédition</th><th></th></tr>
        <?php foreach ($unites as $un): ?>
        <tr>
            <td><?= e($un['sscc']) ?></td>
            <td><?= pastille($un['type']) ?></td>
            <td><small><?= e($un['contenu']) ?></small></td>
            <td class="nombre"><?= nombre($un['poids_kg'], 2) ?></td>
            <td><?php if ($un['exp_id']): ?><a href="expedition.php?id=<?= $un['exp_id'] ?>"><?= e($un['expedition']) ?></a><?php else: ?>à charger<?php endif; ?></td>
            <td class="actions-ligne">
                <?php if ($peutSaisir && $ouverte && !$un['exp_id']): ?>
                <form method="post" onsubmit="return confirm('Défaire cette unité ?')"><?= champ_csrf() ?><button class="petit danger" name="supprimer" value="<?= $un['id'] ?>">Défaire</button></form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>
<p><a href="expeditions.php">← Retour aux expéditions</a></p>
<?php pied();
