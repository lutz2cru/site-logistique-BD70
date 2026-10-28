<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable', 'magasinier');

$id = (int) ($_GET['id'] ?? 0);
$avis = $id ? ligne("SELECT * FROM avis_expedition WHERE id = ? AND statut = 'attendu'", [$id]) : null;
if ($id && !$avis) { flash('erreur', 'Seul un avis attendu peut être modifié.'); rediriger('avis.php'); }

$v = [
    'fournisseur_id'   => $avis['fournisseur_id'] ?? '',
    'date_emission'    => $avis['date_emission'] ?? date('Y-m-d'),
    'livraison_prevue' => $avis['livraison_prevue'] ?? date('Y-m-d', strtotime('+2 days')),
];
$lignesAvis = $id ? lignes('SELECT article_id, quantite_attendue FROM ligne_avis WHERE avis_id = ?', [$id]) : [];
$erreurs = [];

if (est_post()) {
    verifier_csrf();
    foreach ($v as $k => $_) { $v[$k] = trim($_POST[$k] ?? ''); }
    // lignes : on ignore les lignes vides, on regroupe les doublons d'article
    $lignesAvis = [];
    foreach ($_POST['article'] ?? [] as $i => $art) {
        $q = (int) ($_POST['quantite'][$i] ?? 0);
        if ($art === '' && $q === 0) { continue; }
        if ($art === '' || $q <= 0) { $erreurs[] = 'Chaque ligne doit avoir un article et une quantité positive.'; break; }
        $lignesAvis[$art] = ['article_id' => (int) $art, 'quantite_attendue' => ($lignesAvis[$art]['quantite_attendue'] ?? 0) + $q];
    }
    $lignesAvis = array_values($lignesAvis);
    if (!valeur('SELECT COUNT(*) FROM fournisseur WHERE id = ?', [$v['fournisseur_id']])) { $erreurs[] = 'Choisissez le fournisseur.'; }
    if (!strtotime($v['date_emission']) || !strtotime($v['livraison_prevue'])) { $erreurs[] = 'Dates invalides.'; }
    elseif ($v['livraison_prevue'] < $v['date_emission']) { $erreurs[] = 'La livraison ne peut pas précéder l\'émission de l\'avis.'; }
    if (!$lignesAvis) { $erreurs[] = 'Ajoutez au moins une ligne.'; }

    if (!$erreurs) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            if ($avis) {
                requete('UPDATE avis_expedition SET fournisseur_id = ?, date_emission = ?, livraison_prevue = ? WHERE id = ?', [...array_values($v), $id]);
                requete('DELETE FROM ligne_avis WHERE avis_id = ?', [$id]);
            } else {
                requete('INSERT INTO avis_expedition (fournisseur_id, date_emission, livraison_prevue) VALUES (?, ?, ?)', array_values($v));
                $id = (int) $pdo->lastInsertId();
            }
            foreach ($lignesAvis as $l) {
                requete('INSERT INTO ligne_avis (avis_id, article_id, quantite_attendue) VALUES (?, ?, ?)', [$id, $l['article_id'], $l['quantite_attendue']]);
            }
            $pdo->commit();
            flash('succes', 'Avis ' . valeur('SELECT numero FROM avis_expedition WHERE id = ?', [$id]) . ' enregistré.');
            rediriger('avis.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $erreurs[] = message_erreur($e);
        }
    }
}

$fournisseurs = paires('SELECT id, raison_sociale FROM fournisseur ORDER BY raison_sociale');
$articles = paires("SELECT id, CONCAT(reference, ' — ', designation) FROM article ORDER BY reference");
$lignesAvis = array_pad($lignesAvis, count($lignesAvis) + 3, ['article_id' => '', 'quantite_attendue' => '']);

entete($avis ? "Avis {$avis['numero']}" : 'Nouvel avis d\'expédition', 'avis.php');
?>
<?php foreach (array_unique($erreurs) as $err): ?><div class="alerte erreur"><?= e($err) ?></div><?php endforeach; ?>
<div class="carte">
    <form method="post">
        <?= champ_csrf() ?>
        <div class="formulaire">
            <label><span class="requis">Fournisseur</span><select name="fournisseur_id" required><?= options($fournisseurs, $v['fournisseur_id'], '— Choisir —') ?></select></label>
            <label><span class="requis">Date d'émission</span><input type="date" name="date_emission" value="<?= e($v['date_emission']) ?>" required></label>
            <label><span class="requis">Livraison prévue</span><input type="date" name="livraison_prevue" value="<?= e($v['livraison_prevue']) ?>" required></label>
        </div>
        <h3>Articles annoncés</h3>
        <div class="tableau"><table id="lignes">
            <tr><th>Article</th><th class="nombre">Quantité attendue (unités)</th></tr>
            <?php foreach ($lignesAvis as $l): ?>
            <tr>
                <td><select name="article[]" style="width:100%"><?= options($articles, $l['article_id'], '—') ?></select></td>
                <td class="nombre"><input type="number" name="quantite[]" min="1" value="<?= e($l['quantite_attendue']) ?>" style="width:140px"></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <div class="boutons">
            <button type="button" class="discret" onclick="ajouterLigne()">+ Ligne</button>
            <button type="submit">Enregistrer</button>
            <a class="bouton discret" href="avis.php">Annuler</a>
        </div>
    </form>
</div>
<script>
function ajouterLigne() {
    const t = document.getElementById('lignes'), modele = t.rows[t.rows.length - 1].cloneNode(true);
    modele.querySelectorAll('select, input').forEach(c => c.value = '');
    t.tBodies[0].appendChild(modele);
}
</script>
<?php pied();
