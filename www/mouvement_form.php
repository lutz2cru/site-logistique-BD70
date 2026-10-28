<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_profil('responsable', 'magasinier');

$type = $_GET['type'] ?? 'transfert';
if (!in_array($type, ['entree', 'transfert', 'ajustement'], true)) { $type = 'transfert'; }
$retour = $_GET['retour'] ?? 'stock.php';
if (!preg_match('/^[a-z_]+\.php(\?[\w=&%.-]*)?$/', $retour)) { $retour = 'stock.php'; }   // pas de redirection externe

$lot = ligne('SELECT l.*, a.id AS article_id, a.reference, a.designation, a.unites_par_colis,
                     a.unites_par_colis * a.colis_par_palette AS par_palette, c.libelle AS condition_stockage
                FROM lot l JOIN article a ON a.id = l.article_id JOIN condition_stockage c ON c.id = a.condition_stockage_id
               WHERE l.id = ?', [(int) ($_GET['lot'] ?? 0)]);
if (!$lot) { flash('erreur', 'Lot introuvable.'); rediriger($retour); }

$source = null;
if ($type !== 'entree') {
    $source = ligne('SELECT e.id, e.code, s.quantite FROM emplacement e JOIN stock s ON s.emplacement_id = e.id AND s.lot_id = ?
                      WHERE e.id = ?', [$lot['id'], (int) ($_GET['emplacement'] ?? 0)]);
    if (!$source) { flash('erreur', 'Ce lot n\'est pas présent sur cet emplacement.'); rediriger($retour); }
    $disponible = (int) $source['quantite'];
} else {
    // reste à ranger = reçu (hors palettes refusées) − déjà entré en stock
    $disponible = (int) valeur("SELECT COALESCE(SUM(quantite_recue), 0) FROM reception_lot WHERE lot_id = ? AND etat_palette <> 'refuse'", [$lot['id']])
                - (int) valeur("SELECT COALESCE(SUM(quantite), 0) FROM mouvement_stock WHERE lot_id = ? AND type = 'entree'", [$lot['id']]);
    if ($disponible <= 0) { flash('info', 'Ce lot est entièrement rangé.'); rediriger($retour); }
}

$quantite = (int) ($_POST['quantite'] ?? ($type === 'entree' ? min($disponible, $lot['par_palette']) : $disponible));
$erreurs = [];

if (est_post()) {
    verifier_csrf();
    $motif = trim($_POST['motif'] ?? '');
    try {
        if ($type === 'ajustement') {
            // saisie de la quantité comptée : on enregistre l'écart d'inventaire
            $compte = filter_var($_POST['compte'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($compte === false) { $erreurs[] = 'La quantité comptée doit être un entier positif ou nul.'; }
            elseif ($compte === $disponible) { $erreurs[] = 'La quantité comptée est identique au stock : aucun ajustement.'; }
            if ($motif === '') { $erreurs[] = 'Le motif de l\'ajustement est obligatoire.'; }
            if (!$erreurs) {
                $delta = $compte - $disponible;
                requete('INSERT INTO mouvement_stock (type, lot_id, quantite, emplacement_source_id, emplacement_destination_id, utilisateur_id, motif)
                         VALUES (\'ajustement\', ?, ?, ?, ?, ?, ?)',
                        [$lot['id'], abs($delta), $delta < 0 ? $source['id'] : null, $delta > 0 ? $source['id'] : null, $u['id'], $motif]);
                flash('succes', 'Ajustement d\'inventaire enregistré (' . ($delta > 0 ? '+' : '') . $delta . ').');
                rediriger($retour);
            }
        } else {
            if ($quantite < 1 || $quantite > $disponible) { $erreurs[] = "La quantité doit être comprise entre 1 et $disponible."; }
            // destination choisie dans les propositions ou saisie par son code
            $code = strtoupper(trim($_POST['code_destination'] ?? ''));
            $dest = $code !== '' ? valeur('SELECT id FROM emplacement WHERE code = ?', [$code]) : (int) ($_POST['destination'] ?? 0);
            if (!$dest) { $erreurs[] = $code !== '' ? "L'emplacement $code n'existe pas." : 'Choisissez un emplacement de destination.'; }
            if (!$erreurs) {
                // le déclencheur vérifie stock disponible, emplacement bloqué et compatibilité zone / produit
                requete('INSERT INTO mouvement_stock (type, lot_id, quantite, emplacement_source_id, emplacement_destination_id, utilisateur_id, motif)
                         VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$type, $lot['id'], $quantite, $source['id'] ?? null, $dest, $u['id'],
                         $motif ?: ($type === 'entree' ? 'Rangement après réception' : null)]);
                $codeDest = valeur('SELECT code FROM emplacement WHERE id = ?', [$dest]);
                flash('succes', nombre($quantite) . " unités du lot {$lot['code_lot']} " . ($type === 'entree' ? 'rangées' : 'transférées') . " en $codeDest.");
                rediriger($type === 'entree' && $quantite < $disponible ? $_SERVER['REQUEST_URI'] : $retour);
            }
        }
    } catch (PDOException $e) {
        $erreurs[] = message_erreur($e);
    }
}

// emplacements proposés par la procédure stockée (zone compatible, place suffisante)
$propositions = [];
if ($type !== 'ajustement') {
    $palettes = max(1, (int) ceil($quantite / $lot['par_palette']));
    $st = requete('CALL proposer_emplacements(?, ?, 8)', [$lot['article_id'], $palettes]);
    $propositions = array_filter($st->fetchAll(), fn($p) => !$source || (int) $p['id'] !== (int) $source['id']);
    $st->closeCursor();
}

$titres = ['entree' => 'Ranger un lot', 'transfert' => 'Transférer du stock', 'ajustement' => 'Ajuster l\'inventaire'];
entete($titres[$type], $type === 'entree' ? 'receptions.php' : 'stock.php');
?>
<?php foreach ($erreurs as $err): ?><div class="alerte erreur"><?= e($err) ?></div><?php endforeach; ?>
<div class="carte">
    <h2>Lot <?= e($lot['code_lot']) ?> — <?= e($lot['reference']) ?> <?= e($lot['designation']) ?></h2>
    <p class="discret">
        Conditions : <?= e($lot['condition_stockage']) ?> · <?= nombre($lot['par_palette']) ?> unités par palette
        <?= $lot['date_limite_conso'] ? ' · DLC ' . date_fr($lot['date_limite_conso']) : '' ?>
        <?= $source ? ' · emplacement source <strong>' . e($source['code']) . '</strong>' : '' ?>
        · <?= $type === 'entree' ? 'reste à ranger' : 'en stock' ?> : <strong><?= nombre($disponible) ?></strong>
    </p>

    <form method="post" class="formulaire">
        <?= champ_csrf() ?>
        <?php if ($type === 'ajustement'): ?>
            <label><span class="requis">Quantité comptée</span><input type="number" name="compte" min="0" required value="<?= e($_POST['compte'] ?? $disponible) ?>"></label>
            <label class="pleine"><span class="requis">Motif</span><input name="motif" maxlength="255" required placeholder="ex. inventaire tournant, casse constatée" value="<?= e($_POST['motif'] ?? '') ?>"></label>
        <?php else: ?>
            <label><span class="requis">Quantité (unités)</span>
                <input type="number" name="quantite" min="1" max="<?= $disponible ?>" value="<?= $quantite ?>" required>
                <small>soit <?= nombre($quantite / $lot['par_palette'], 2) ?> palette(s)</small>
            </label>
            <label>Ou code d'emplacement<input name="code_destination" placeholder="ex. AMB-A01-R01-N1-P1" value="<?= e($_POST['code_destination'] ?? '') ?>"></label>
            <label>Motif<input name="motif" maxlength="255" value="<?= e($_POST['motif'] ?? '') ?>"></label>
            <div class="pleine">
                <strong>Emplacements proposés</strong> <small>(zone compatible, non bloqués, place suffisante ; ceux contenant déjà l'article en premier)</small>
                <?php if (!$propositions): ?>
                    <p class="alerte erreur">Aucun emplacement compatible n'a assez de place : réduisez la quantité ou saisissez un code.</p>
                <?php else: ?>
                <div class="tableau"><table>
                    <tr><th></th><th>Emplacement</th><th>Zone</th><th class="nombre">Niveau</th><th class="nombre">Palettes libres</th><th></th></tr>
                    <?php foreach (array_values($propositions) as $i => $p): ?>
                    <tr>
                        <td><input type="radio" name="destination" value="<?= $p['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>></td>
                        <td><strong><?= e($p['code']) ?></strong></td><td><?= e($p['zone_libelle']) ?></td>
                        <td class="nombre"><?= $p['niveau'] ?></td><td class="nombre"><?= $p['palettes_libres'] ?> / <?= $p['capacite_palettes'] ?></td>
                        <td><?= $p['contient_article'] ? '<span class="pastille bleu">contient déjà l\'article</span>' : '' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="boutons pleine">
            <button type="submit">Valider</button>
            <a class="bouton discret" href="<?= e($retour) ?>">Retour</a>
        </div>
    </form>
</div>
<?php pied();
