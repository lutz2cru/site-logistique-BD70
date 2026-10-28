<?php
/**
 * Écran générique de gestion des référentiels (liste, recherche, filtres,
 * ajout, modification, suppression), piloté par includes/referentiels.php.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/referentiels.php';

$refs = referentiels();
$cle = $_GET['t'] ?? '';
if (!isset($refs[$cle])) { rediriger('index.php'); }
$ref = $refs[$cle];
exiger_profil(...$ref['lecture']);
$ecriture = a_profil(...$ref['ecriture']);
$table = $ref['table'];
$url = 'referentiel.php?t=' . $cle;

/** Options d'un champ de type liste ou multi. */
function options_champ(array $def): array
{
    return $def['valeurs'] ?? paires($def['sql']);
}

/** Contrôle et normalisation d'une saisie selon le type du champ ; retourne [valeur, erreur]. */
function valider(array $def, mixed $brut): array
{
    [$lib, $type] = $def;
    if ($type === 'booleen') { return [$brut ? 1 : 0, null]; }
    $val = trim((string) $brut);
    if ($val === '') { return [null, !empty($def['requis']) ? "« $lib » est obligatoire." : null]; }
    if (isset($def['max']) && mb_strlen($val) > $def['max']) { return [null, "« $lib » : {$def['max']} caractères maximum."]; }
    $err = "« $lib » n'est pas valide.";
    switch ($type) {
        case 'email':  return filter_var($val, FILTER_VALIDATE_EMAIL) ? [$val, null] : [null, $err];
        case 'tel':    return preg_match('/^[0-9 +.]{10,20}$/', $val) ? [$val, null] : [null, "$err (10 chiffres)"];
        case 'siret':  $val = str_replace(' ', '', $val); return preg_match('/^\d{14}$/', $val) ? [$val, null] : [null, "$err (14 chiffres)"];
        case 'cp':     return preg_match('/^\d{5}$/', $val) ? [$val, null] : [null, "$err (5 chiffres)"];
        case 'immat':  $val = strtoupper($val); return preg_match('/^[A-Z]{2}-\d{3}-[A-Z]{2}$/', $val) ? [$val, null] : [null, "$err (format AB-123-CD)"];
        case 'date':   return DateTime::createFromFormat('Y-m-d', $val) ? [$val, null] : [null, $err];
        case 'entier':
        case 'decimal':
            $val = str_replace(',', '.', $val);
            $ok = $type === 'entier' ? filter_var($val, FILTER_VALIDATE_INT) !== false : is_numeric($val);
            if (!$ok) { return [null, $err]; }
            if (isset($def['min']) && $val < $def['min']) { return [null, "« $lib » doit être supérieur ou égal à {$def['min']}."]; }
            if (isset($def['maxv']) && $val > $def['maxv']) { return [null, "« $lib » doit être inférieur ou égal à {$def['maxv']}."]; }
            return [$val, null];
        case 'liste':  return array_key_exists($val, options_champ($def)) ? [$val, null] : [null, $err];
        default:
            if (isset($def['motif']) && !preg_match('/^' . $def['motif'] . '$/u', $val)) { return [null, $err]; }
            return [$val, null];
    }
}

$champs = $ref['champs'];
$simples = array_filter($champs, fn($d) => $d[1] !== 'multi');
$multis = array_filter($champs, fn($d) => $d[1] === 'multi');
$erreurs = [];
$edition = isset($_GET['modifier']) || isset($_GET['nouveau']);
$id = (int) ($_GET['modifier'] ?? 0);

// ---------------------------------------------------------------------
//  Enregistrement / suppression
// ---------------------------------------------------------------------
if (est_post() && $ecriture) {
    verifier_csrf();
    if (isset($_POST['supprimer'])) {
        try {
            requete("DELETE FROM `$table` WHERE id = ?", [$_POST['supprimer']]);
            flash('succes', 'Élément supprimé.');
        } catch (PDOException $e) {
            flash('erreur', message_erreur($e));
        }
        rediriger($url . '&' . http_build_query(array_diff_key($_GET, ['t' => 1])));
    }

    $valeurs = [];
    foreach ($simples as $nom => $def) {
        [$valeurs[$nom], $err] = valider($def, $_POST[$nom] ?? null);
        if ($err) { $erreurs[] = $err; }
    }
    if (!$erreurs) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $cols = array_keys($valeurs);
            if ($id) {
                $set = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
                requete("UPDATE `$table` SET $set WHERE id = ?", [...array_values($valeurs), $id]);
            } else {
                $ph = implode(', ', array_fill(0, count($cols), '?'));
                requete("INSERT INTO `$table` (`" . implode('`, `', $cols) . "`) VALUES ($ph)", array_values($valeurs));
                $id = (int) $pdo->lastInsertId();
            }
            // associations N:N : on retire les décochées, on ajoute les nouvelles (les existantes gardent leurs attributs)
            foreach ($multis as $nom => $def) {
                $coches = array_intersect(array_map('intval', $_POST[$nom] ?? []), array_keys(options_champ($def)));
                if ($coches) {
                    $ph = implode(',', array_fill(0, count($coches), '?'));
                    requete("DELETE FROM `{$def['jonction']}` WHERE `{$def['cle']}` = ? AND `{$def['autre']}` NOT IN ($ph)", [$id, ...$coches]);
                } else {
                    requete("DELETE FROM `{$def['jonction']}` WHERE `{$def['cle']}` = ?", [$id]);
                }
                $extraCols = implode('', array_map(fn($c) => ", `$c`", array_keys($def['defauts'] ?? [])));
                $extraVals = implode('', array_map(fn($v) => ", $v", $def['defauts'] ?? []));
                foreach ($coches as $o) {
                    requete("INSERT IGNORE INTO `{$def['jonction']}` (`{$def['cle']}`, `{$def['autre']}`$extraCols) VALUES (?, ?$extraVals)", [$id, $o]);
                }
            }
            $pdo->commit();
            flash('succes', 'Enregistrement effectué.');
            rediriger($url);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $erreurs[] = message_erreur($e);
        }
    }
    $edition = true;
}

// ---------------------------------------------------------------------
//  Formulaire
// ---------------------------------------------------------------------
if ($edition && $ecriture) {
    $courant = $id ? ligne("SELECT * FROM `$table` WHERE id = ?", [$id]) : [];
    if ($id && !$courant) { rediriger($url); }
    foreach ($multis as $nom => $def) {
        $courant[$nom] = $id ? array_map('intval', requete("SELECT `{$def['autre']}` FROM `{$def['jonction']}` WHERE `{$def['cle']}` = ?", [$id])->fetchAll(PDO::FETCH_COLUMN)) : [];
    }
    if (est_post()) { $courant = array_merge($courant, $_POST); }

    entete(($id ? 'Modifier — ' : 'Ajouter — ') . $ref['titre'], 'referentiel.php?t=' . $cle);
    foreach ($erreurs as $err) { echo '<div class="alerte erreur">' . e($err) . '</div>'; }
    ?>
    <div class="carte">
        <form method="post" class="formulaire">
            <?= champ_csrf() ?>
            <?php foreach ($champs as $nom => $def):
                [$lib, $type] = $def;
                $val = $courant[$nom] ?? '';
                $req = !empty($def['requis']) ? 'required' : '';
                $classeLib = $req ? 'requis' : ''; ?>
                <?php if ($type === 'booleen'): ?>
                    <label class="case"><input type="checkbox" name="<?= $nom ?>" value="1" <?= $val ? 'checked' : '' ?>> <?= e($lib) ?></label>
                <?php elseif ($type === 'multi'): ?>
                    <div class="pleine"><strong><?= e($lib) ?></strong><div class="cases">
                    <?php foreach (options_champ($def) as $o => $olib): ?>
                        <label><input type="checkbox" name="<?= $nom ?>[]" value="<?= $o ?>" <?= in_array((int) $o, array_map('intval', (array) $val), true) ? 'checked' : '' ?>> <?= e($olib) ?></label>
                    <?php endforeach; ?></div></div>
                <?php elseif ($type === 'liste'): ?>
                    <label><span class="<?= $classeLib ?>"><?= e($lib) ?></span><select name="<?= $nom ?>" <?= $req ?>><?= options(options_champ($def), $val, '— Choisir —') ?></select></label>
                <?php else:
                    $html = match ($type) { 'email' => 'email', 'date' => 'date', 'entier', 'decimal' => 'number', 'tel' => 'tel', default => 'text' };
                    $attrs = $type === 'decimal' ? 'step="any"' : '';
                    if (isset($def['min'])) { $attrs .= ' min="' . $def['min'] . '"'; }
                    if (isset($def['maxv'])) { $attrs .= ' max="' . $def['maxv'] . '"'; }
                    if (isset($def['max'])) { $attrs .= ' maxlength="' . $def['max'] . '"'; }
                    $motif = $def['motif'] ?? match ($type) { 'siret' => '[0-9 ]{14,17}', 'cp' => '[0-9]{5}', 'immat' => '[A-Za-z]{2}-[0-9]{3}-[A-Za-z]{2}', default => null };
                    if ($motif) { $attrs .= ' pattern="' . e($motif) . '"'; } ?>
                    <label><span class="<?= $classeLib ?>"><?= e($lib) ?></span><input type="<?= $html ?>" name="<?= $nom ?>" value="<?= e($val) ?>" <?= $req ?> <?= $attrs ?>></label>
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="boutons pleine">
                <button type="submit">Enregistrer</button>
                <a class="bouton discret" href="<?= e($url) ?>">Annuler</a>
            </div>
        </form>
    </div>
    <?php
    pied();
    exit;
}

// ---------------------------------------------------------------------
//  Liste : recherche, filtres, pagination
// ---------------------------------------------------------------------
$where = ['1 = 1'];
$params = [];
if (($q = filtre('q')) !== '') {
    $where[] = '(' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $ref['recherche'])) . ')';
    foreach ($ref['recherche'] as $_) { $params[] = "%$q%"; }
}
foreach ($ref['filtres'] ?? [] as $nom) {
    if (($v = filtre($nom)) !== '') { $where[] = "t.`$nom` = ?"; $params[] = $v; }
}
$sql = $ref['liste']['sql'] . ' WHERE ' . implode(' AND ', $where);
$total = (int) valeur("SELECT COUNT(*) FROM ($sql) x", $params);
[$limite, $decalage, $page, $pages] = pagination($total);
$elements = lignes("$sql ORDER BY {$ref['liste']['ordre']} LIMIT $limite OFFSET $decalage", $params);
$colonnes = $ref['liste']['colonnes'];

/** Mise en forme d'une valeur de cellule selon le type du champ d'origine. */
function cellule(array $champs, string $col, mixed $val): string
{
    $type = $champs[$col][1] ?? '';
    if ($type === 'booleen') { return $val ? '<span class="pastille bleu">Oui</span>' : '<span class="discret">Non</span>'; }
    if ($type === 'date') { return e(date_fr($val)); }
    if ($type === 'decimal' && $val !== null) { return e(nombre($val, 2)); }
    if (is_int($val) || (is_numeric($val) && in_array($col, ['en_stock'], true))) { return e(nombre($val)); }
    return e($val);
}

$onglets = array_filter($refs, fn($r) => $r['groupe'] === $ref['groupe'] && a_profil(...$r['lecture']));
entete($ref['titre'], 'referentiel.php?t=' . $cle);
?>
<?php if (count($onglets) > 1): ?>
<div class="actions" style="margin-bottom:14px">
    <?php foreach ($onglets as $k => $o): ?>
        <a class="bouton <?= $k === $cle ? '' : 'discret' ?>" href="referentiel.php?t=<?= $k ?>"><?= e($o['titre']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="carte">
    <h2>
        <span><?= nombre($total) ?> élément(s)</span>
        <?php if ($ecriture): ?><a class="bouton" href="<?= e($url) ?>&nouveau=1">+ Ajouter</a><?php endif; ?>
    </h2>
    <form class="filtres" method="get">
        <input type="hidden" name="t" value="<?= e($cle) ?>">
        <label>Recherche<input name="q" value="<?= e(filtre('q')) ?>"></label>
        <?php foreach ($ref['filtres'] ?? [] as $nom): ?>
            <label><?= e($champs[$nom][0]) ?><select name="<?= $nom ?>"><?= options(options_champ($champs[$nom]), filtre($nom), 'Tous') ?></select></label>
        <?php endforeach; ?>
        <button type="submit">Rechercher</button>
        <a class="bouton discret" href="<?= e($url) ?>">Réinitialiser</a>
    </form>
    <?php if (!$elements): ?><p class="vide">Aucun résultat.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><?php foreach ($colonnes as $lib): ?><th><?= e($lib) ?></th><?php endforeach; ?><?php if ($ecriture): ?><th></th><?php endif; ?></tr>
        <?php foreach ($elements as $el): ?>
        <tr>
            <?php foreach ($colonnes as $col => $_): ?><td><?= cellule($champs, $col, $el[$col]) ?></td><?php endforeach; ?>
            <?php if ($ecriture): ?>
            <td class="actions-ligne">
                <a class="bouton petit secondaire" href="<?= e($url) ?>&modifier=<?= $el['id'] ?>">Modifier</a>
                <form method="post" onsubmit="return confirm('Supprimer cet élément ?')">
                    <?= champ_csrf() ?><button class="petit danger" name="supprimer" value="<?= $el['id'] ?>">Supprimer</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?= liens_pagination($page, $pages) ?>
    <?php endif; ?>
</div>
<?php pied();
