<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable');

$id = (int) ($_GET['id'] ?? 0);
$quai = $id ? ligne('SELECT * FROM quai WHERE id = ?', [$id]) : null;
if ($id && !$quai) { rediriger('quais.php'); }

$equipements = paires('SELECT id, libelle FROM equipement ORDER BY libelle');
$types = paires('SELECT id, libelle FROM type_vehicule ORDER BY id');
$v = [
    'numero' => $quai['numero'] ?? '',
    'type'   => $quai['type'] ?? 'reception',
    'equipements' => $id ? array_map('intval', array_column(lignes('SELECT equipement_id FROM quai_equipement WHERE quai_id = ?', [$id]), 'equipement_id')) : [],
    'types'  => $id ? array_map('intval', array_column(lignes('SELECT type_vehicule_id FROM quai_type_vehicule WHERE quai_id = ?', [$id]), 'type_vehicule_id')) : [],
];
$erreurs = [];

if (est_post()) {
    verifier_csrf();
    $v['numero'] = strtoupper(trim($_POST['numero'] ?? ''));
    $v['type'] = $_POST['type'] ?? '';
    $v['equipements'] = array_values(array_intersect(array_map('intval', $_POST['equipements'] ?? []), array_keys($equipements)));
    $v['types'] = array_values(array_intersect(array_map('intval', $_POST['types'] ?? []), array_keys($types)));

    if (!preg_match('/^[A-Z0-9-]{1,10}$/', $v['numero'])) { $erreurs[] = 'Numéro invalide (10 caractères : lettres, chiffres, tiret).'; }
    if (!in_array($v['type'], ['reception', 'expedition', 'mixte'], true)) { $erreurs[] = 'Type de quai invalide.'; }
    if (!$v['types']) { $erreurs[] = 'Cochez au moins un type de véhicule accepté.'; }

    if (!$erreurs) {
        $pdo = db();
        try {
            $pdo->beginTransaction();   // le quai et ses associations sont enregistrés ensemble
            if ($quai) {
                requete('UPDATE quai SET numero = ?, type = ? WHERE id = ?', [$v['numero'], $v['type'], $id]);
                requete('DELETE FROM quai_equipement WHERE quai_id = ?', [$id]);
                requete('DELETE FROM quai_type_vehicule WHERE quai_id = ?', [$id]);
            } else {
                requete('INSERT INTO quai (numero, type) VALUES (?, ?)', [$v['numero'], $v['type']]);
                $id = (int) $pdo->lastInsertId();
            }
            foreach ($v['equipements'] as $eq) { requete('INSERT INTO quai_equipement VALUES (?, ?)', [$id, $eq]); }
            foreach ($v['types'] as $t) { requete('INSERT INTO quai_type_vehicule VALUES (?, ?)', [$id, $t]); }
            $pdo->commit();
            flash('succes', "Quai {$v['numero']} enregistré.");
            rediriger('quais.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $erreurs[] = message_erreur($e);
        }
    }
}

entete($quai ? "Quai {$quai['numero']}" : 'Nouveau quai', 'quais.php');
?>
<?php foreach ($erreurs as $err): ?><div class="alerte erreur"><?= e($err) ?></div><?php endforeach; ?>
<div class="carte">
    <form method="post" class="formulaire">
        <?= champ_csrf() ?>
        <label><span class="requis">Numéro</span><input name="numero" value="<?= e($v['numero']) ?>" required maxlength="10" pattern="[A-Za-z0-9-]{1,10}"></label>
        <label><span class="requis">Type</span>
            <select name="type"><?= options(['reception' => 'Réception', 'expedition' => 'Expédition', 'mixte' => 'Mixte'], $v['type']) ?></select>
        </label>
        <div class="pleine"><strong>Équipements</strong>
            <div class="cases">
            <?php foreach ($equipements as $eid => $lib): ?>
                <label><input type="checkbox" name="equipements[]" value="<?= $eid ?>" <?= in_array($eid, $v['equipements'], true) ? 'checked' : '' ?>> <?= e($lib) ?></label>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="pleine"><strong class="requis">Types de véhicules acceptés</strong>
            <div class="cases">
            <?php foreach ($types as $tid => $lib): ?>
                <label><input type="checkbox" name="types[]" value="<?= $tid ?>" <?= in_array($tid, $v['types'], true) ? 'checked' : '' ?>> <?= e($lib) ?></label>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="boutons pleine">
            <button type="submit">Enregistrer</button>
            <a class="bouton discret" href="quais.php">Annuler</a>
        </div>
    </form>
</div>
<?php pied();
