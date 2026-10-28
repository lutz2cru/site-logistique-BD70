<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_profil('responsable', 'magasinier');

$id = (int) ($_GET['id'] ?? 0);
$rec = ligne("SELECT r.*, a.numero AS avis, a.livraison_prevue, f.raison_sociale AS fournisseur, p.numero AS passage, p.id AS passage_id,
                     v.immatriculation, t.raison_sociale AS transporteur, q.numero AS quai, CONCAT(us.prenom, ' ', us.nom) AS receptionnaire
                FROM reception r
                JOIN passage p ON p.id = r.passage_id JOIN vehicule v ON v.id = p.vehicule_id
                JOIN transporteur t ON t.id = v.transporteur_id LEFT JOIN quai q ON q.id = p.quai_id
                LEFT JOIN avis_expedition a ON a.id = r.avis_id LEFT JOIN fournisseur f ON f.id = a.fournisseur_id
                JOIN utilisateur us ON us.id = r.receptionnaire_id
               WHERE r.id = ?", [$id]);
if (!$rec) { rediriger('receptions.php'); }
$enCours = $rec['statut'] === 'en_cours';
$erreurs = [];

// ---------------------------------------------------------------------
//  Actions
// ---------------------------------------------------------------------
if (est_post()) {
    verifier_csrf();
    $action = $_POST['action'] ?? '';
    $pdo = db();
    try {
        if ($action === 'ajouter_lot' && $enCours) {
            $art = ligne('SELECT a.id, a.unites_par_colis, c.libelle AS condition_stockage FROM article a
                            JOIN condition_stockage c ON c.id = a.condition_stockage_id WHERE a.id = ?', [$_POST['article_id'] ?? 0]);
            $qte = filter_var($_POST['quantite'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
            $dlc = trim($_POST['date_limite_conso'] ?? '');
            $fab = trim($_POST['date_fabrication'] ?? '');
            $etat = $_POST['etat_palette'] ?? 'bon';
            if (!$art) { $erreurs[] = 'Choisissez l\'article reçu.'; }
            if ($qte === false) { $erreurs[] = 'La quantité doit être un entier positif.'; }
            if ($art && in_array($art['condition_stockage'], ['Froid positif', 'Surgelé'], true) && $dlc === '') {
                $erreurs[] = 'La date limite de consommation est obligatoire pour un produit frais ou surgelé.';
            }
            if ($dlc !== '' && $dlc < date('Y-m-d')) { $erreurs[] = 'La date limite est déjà dépassée : refusez la marchandise.'; }
            if ($fab !== '' && $dlc !== '' && $fab > $dlc) { $erreurs[] = 'La date de fabrication doit précéder la date limite.'; }
            if (!in_array($etat, ['bon', 'endommage', 'refuse'], true)) { $erreurs[] = 'État de palette invalide.'; }
            if (!$erreurs) {
                $pdo->beginTransaction();
                requete('INSERT INTO lot (article_id, numero_lot_fournisseur, date_fabrication, date_limite_conso) VALUES (?, ?, ?, ?)',
                        [$art['id'], trim($_POST['numero_lot_fournisseur'] ?? '') ?: null, $fab ?: null, $dlc ?: null]);
                $lotId = (int) $pdo->lastInsertId();
                requete('INSERT INTO reception_lot (reception_id, lot_id, quantite_recue, etat_palette) VALUES (?, ?, ?, ?)', [$id, $lotId, $qte, $etat]);
                $pdo->commit();
                flash('succes', 'Lot ' . valeur('SELECT code_lot FROM lot WHERE id = ?', [$lotId]) . ' enregistré.');
            }
        } elseif ($action === 'supprimer_lot' && $enCours) {
            $pdo->beginTransaction();
            requete('DELETE FROM reception_lot WHERE reception_id = ? AND lot_id = ?', [$id, $_POST['lot_id']]);
            requete('DELETE FROM lot WHERE id = ? AND NOT EXISTS (SELECT 1 FROM mouvement_stock WHERE lot_id = ?)', [$_POST['lot_id'], $_POST['lot_id']]);
            $pdo->commit();
            flash('succes', 'Lot retiré de la réception.');
        } elseif ($action === 'cloturer' && $enCours) {
            if (!valeur('SELECT COUNT(*) FROM reception_lot WHERE reception_id = ?', [$id]) && !$rec['avis_id']) {
                $erreurs[] = 'Aucun lot n\'a été saisi.';
            } else {
                requete('CALL cloturer_reception(?)', [$id]);   // calcule automatiquement les écarts
                $n = (int) valeur('SELECT COUNT(*) FROM ecart WHERE reception_id = ?', [$id]);
                flash($n ? 'info' : 'succes', $n ? "Réception clôturée : $n écart(s) détecté(s) par rapport à l'avis." : 'Réception clôturée sans écart.');
            }
        } elseif ($action === 'declarer_ecart') {
            $qte = filter_var($_POST['quantite'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($qte === false || !in_array($_POST['type'] ?? '', ['manquant', 'excedent', 'endommage'], true) || empty($_POST['article_id'])) {
                $erreurs[] = 'Renseignez l\'article, le type et une quantité positive.';
            } else {
                requete('INSERT INTO ecart (reception_id, article_id, type, quantite, description) VALUES (?, ?, ?, ?, ?)',
                        [$id, $_POST['article_id'], $_POST['type'], $qte, trim($_POST['description'] ?? '') ?: null]);
                flash('succes', 'Écart enregistré.');
            }
        } elseif ($action === 'reserve') {
            $ids = array_map('intval', $_POST['ecarts'] ?? []);
            $motif = trim($_POST['motif'] ?? '');
            if (!$ids || $motif === '') {
                $erreurs[] = 'Sélectionnez au moins un écart et indiquez le motif de la réserve.';
            } else {
                $pdo->beginTransaction();
                requete('INSERT INTO reserve (date_emission, motif, transmise) VALUES (NOW(), ?, ?)', [$motif, isset($_POST['transmise']) ? 1 : 0]);
                $resId = (int) $pdo->lastInsertId();
                $ph = implode(',', array_fill(0, count($ids), '?'));
                requete("UPDATE ecart SET reserve_id = ? WHERE reception_id = ? AND reserve_id IS NULL AND id IN ($ph)", [$resId, $id, ...$ids]);
                $pdo->commit();
                flash('succes', 'Réserve ' . valeur('SELECT numero FROM reserve WHERE id = ?', [$resId]) . ' émise auprès de ' . $rec['transporteur'] . '.');
            }
        } elseif ($action === 'transmettre') {
            requete('UPDATE reserve SET transmise = TRUE WHERE id = ?', [$_POST['reserve_id']]);
            flash('succes', 'Réserve marquée comme transmise.');
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $erreurs[] = message_erreur($e);
    }
    if (!$erreurs) { rediriger("reception.php?id=$id"); }
}

// ---------------------------------------------------------------------
//  Données
// ---------------------------------------------------------------------
// comparaison attendu / reçu par article
$comparaison = lignes("SELECT ar.id, ar.reference, ar.designation, ar.unites_par_colis,
                              COALESCE(la.quantite_attendue, 0) AS attendu, COALESCE(rc.recu, 0) AS recu
                         FROM article ar
                         LEFT JOIN ligne_avis la ON la.article_id = ar.id AND la.avis_id = ?
                         LEFT JOIN (SELECT l.article_id, SUM(rl.quantite_recue) AS recu FROM reception_lot rl
                                      JOIN lot l ON l.id = rl.lot_id WHERE rl.reception_id = ? GROUP BY l.article_id) rc ON rc.article_id = ar.id
                        WHERE la.article_id IS NOT NULL OR rc.article_id IS NOT NULL
                        ORDER BY ar.reference", [$rec['avis_id'], $id]);
$lots = lignes("SELECT l.*, rl.quantite_recue, rl.etat_palette, ar.reference, ar.designation,
                       COALESCE((SELECT SUM(m.quantite) FROM mouvement_stock m WHERE m.lot_id = l.id AND m.type = 'entree'), 0) AS range_qte,
                       (SELECT GROUP_CONCAT(DISTINCT e.code SEPARATOR ', ') FROM stock s JOIN emplacement e ON e.id = s.emplacement_id WHERE s.lot_id = l.id) AS emplacements
                  FROM reception_lot rl JOIN lot l ON l.id = rl.lot_id JOIN article ar ON ar.id = l.article_id
                 WHERE rl.reception_id = ? ORDER BY l.id", [$id]);
$ecarts = lignes('SELECT e.*, ar.reference, ar.designation, rs.numero AS reserve, rs.transmise, rs.id AS reserve_id
                    FROM ecart e JOIN article ar ON ar.id = e.article_id LEFT JOIN reserve rs ON rs.id = e.reserve_id
                   WHERE e.reception_id = ? ORDER BY e.id', [$id]);

$annonces = paires("SELECT ar.id, CONCAT(ar.reference, ' — ', ar.designation) FROM ligne_avis la JOIN article ar ON ar.id = la.article_id WHERE la.avis_id = ? ORDER BY ar.reference", [$rec['avis_id']]);
$autres = paires("SELECT id, CONCAT(reference, ' — ', designation) FROM article ORDER BY reference");
$autres = array_diff_key($autres, $annonces);

entete("Réception {$rec['numero']}", 'receptions.php');
?>
<?php foreach ($erreurs as $err): ?><div class="alerte erreur"><?= e($err) ?></div><?php endforeach; ?>

<div class="carte">
    <h2>
        <span><?= pastille($rec['statut']) ?> Avis <?= e($rec['avis'] ?? '— livraison non annoncée') ?> <?= $rec['fournisseur'] ? '· ' . e($rec['fournisseur']) : '' ?></span>
        <?php if ($enCours): ?>
        <form method="post" onsubmit="return confirm('Clôturer la réception ? Les écarts seront calculés automatiquement.')">
            <?= champ_csrf() ?><button name="action" value="cloturer">Clôturer la réception</button>
        </form>
        <?php endif; ?>
    </h2>
    <p class="discret">
        Camion <a href="passage.php?id=<?= $rec['passage_id'] ?>"><?= e($rec['immatriculation']) ?></a> (<?= e($rec['transporteur']) ?>) · quai <?= e($rec['quai']) ?> ·
        début <?= date_heure_fr($rec['debut']) ?><?= $rec['fin'] ? ' · fin ' . date_heure_fr($rec['fin']) : '' ?> · réceptionnaire <?= e($rec['receptionnaire']) ?>
    </p>
</div>

<div class="grille">
    <div class="carte">
        <h2>Attendu / reçu</h2>
        <?php if (!$comparaison): ?><p class="vide">Rien d'annoncé ni de reçu pour l'instant.</p><?php else: ?>
        <div class="tableau"><table>
            <tr><th>Article</th><th class="nombre">Attendu</th><th class="nombre">Reçu</th><th class="nombre">Écart</th></tr>
            <?php foreach ($comparaison as $c): $d = $c['recu'] - $c['attendu']; ?>
            <tr>
                <td><strong><?= e($c['reference']) ?></strong> <?= e($c['designation']) ?></td>
                <td class="nombre"><?= nombre($c['attendu']) ?></td>
                <td class="nombre"><?= nombre($c['recu']) ?></td>
                <td class="nombre"><?= $d === 0 ? '<span class="pastille vert">OK</span>' : '<span class="pastille ' . ($d < 0 ? 'rouge' : 'orange') . '">' . ($d > 0 ? '+' : '') . nombre($d) . '</span>' ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
    </div>

    <?php if ($enCours): ?>
    <div class="carte">
        <h2>Enregistrer un lot reçu</h2>
        <form method="post" class="formulaire">
            <?= champ_csrf() ?><input type="hidden" name="action" value="ajouter_lot">
            <label class="pleine"><span class="requis">Article</span>
                <select name="article_id" required>
                    <option value="">— Choisir —</option>
                    <?php if ($annonces): ?><optgroup label="Annoncés dans l'avis"><?= options($annonces, $_POST['article_id'] ?? null) ?></optgroup><?php endif; ?>
                    <optgroup label="Autres articles"><?= options($autres, $_POST['article_id'] ?? null) ?></optgroup>
                </select>
            </label>
            <label><span class="requis">Quantité reçue (unités)</span><input type="number" name="quantite" min="1" required value="<?= e($_POST['quantite'] ?? '') ?>"></label>
            <label>N° de lot fournisseur<input name="numero_lot_fournisseur" maxlength="30" value="<?= e($_POST['numero_lot_fournisseur'] ?? '') ?>"></label>
            <label>Date de fabrication<input type="date" name="date_fabrication" value="<?= e($_POST['date_fabrication'] ?? '') ?>"></label>
            <label>Date limite de consommation<input type="date" name="date_limite_conso" value="<?= e($_POST['date_limite_conso'] ?? '') ?>"></label>
            <label>État de la palette<select name="etat_palette"><?= options(['bon' => 'Bon état', 'endommage' => 'Endommagée', 'refuse' => 'Refusée'], $_POST['etat_palette'] ?? 'bon') ?></select></label>
            <div class="boutons pleine"><button type="submit">Ajouter le lot</button></div>
        </form>
    </div>
    <?php endif; ?>
</div>

<div class="carte">
    <h2>Lots reçus</h2>
    <?php if (!$lots): ?><p class="vide">Aucun lot saisi.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Lot</th><th>Article</th><th>Lot fournisseur</th><th>DLC</th><th class="nombre">Reçu</th><th>État</th><th class="nombre">Rangé</th><th>Emplacement(s)</th><th></th></tr>
        <?php foreach ($lots as $l): $reste = $l['quantite_recue'] - $l['range_qte']; ?>
        <tr>
            <td><a href="lot.php?code=<?= e($l['code_lot']) ?>"><?= e($l['code_lot']) ?></a></td>
            <td><strong><?= e($l['reference']) ?></strong> <?= e($l['designation']) ?></td>
            <td><?= e($l['numero_lot_fournisseur']) ?></td>
            <td><?= date_fr($l['date_limite_conso']) ?></td>
            <td class="nombre"><?= nombre($l['quantite_recue']) ?></td>
            <td><?= pastille($l['etat_palette']) ?></td>
            <td class="nombre"><?= nombre($l['range_qte']) ?></td>
            <td><small><?= e($l['emplacements']) ?></small></td>
            <td class="actions-ligne">
                <?php if ($reste > 0 && $l['etat_palette'] !== 'refuse'): ?>
                    <a class="bouton petit" href="mouvement_form.php?type=entree&lot=<?= $l['id'] ?>&retour=<?= urlencode("reception.php?id=$id") ?>">Ranger (<?= nombre($reste) ?>)</a>
                <?php endif; ?>
                <?php if ($enCours && !$l['range_qte']): ?>
                <form method="post" onsubmit="return confirm('Retirer ce lot ?')">
                    <?= champ_csrf() ?><input type="hidden" name="lot_id" value="<?= $l['id'] ?>">
                    <button class="petit danger" name="action" value="supprimer_lot">Retirer</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>

<div class="carte">
    <h2>Écarts et réserves</h2>
    <?php if (!$ecarts): ?><p class="vide"><?= $enCours ? 'Les écarts seront calculés à la clôture.' : 'Aucun écart sur cette réception.' ?></p><?php else: ?>
    <form method="post">
        <?= champ_csrf() ?>
        <div class="tableau"><table>
            <tr><th></th><th>Numéro</th><th>Article</th><th>Type</th><th class="nombre">Quantité</th><th>Description</th><th>Réserve</th></tr>
            <?php foreach ($ecarts as $ec): ?>
            <tr>
                <td><?php if (!$ec['reserve_id']): ?><input type="checkbox" name="ecarts[]" value="<?= $ec['id'] ?>"><?php endif; ?></td>
                <td><?= e($ec['numero']) ?></td>
                <td><strong><?= e($ec['reference']) ?></strong> <?= e($ec['designation']) ?></td>
                <td><?= pastille($ec['type']) ?></td>
                <td class="nombre"><?= nombre($ec['quantite']) ?></td>
                <td><small><?= e($ec['description']) ?></small></td>
                <td><?php if ($ec['reserve_id']): ?>
                    <?= e($ec['reserve']) ?> <?= $ec['transmise'] ? pastille('recu') : '<button class="petit discret" name="action" value="transmettre" onclick="this.form.reserve_id.value=' . $ec['reserve_id'] . '">Marquer transmise</button>' ?>
                <?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <input type="hidden" name="reserve_id" value="">
        <h3>Émettre une réserve auprès du transporteur pour les écarts cochés</h3>
        <div class="filtres">
            <label style="flex:1">Motif<input name="motif" maxlength="255" placeholder="ex. 3 colis écrasés, film déchiré"></label>
            <label class="case" style="flex-direction:row;align-items:center;gap:6px"><input type="checkbox" name="transmise" checked> transmise au chauffeur</label>
            <button name="action" value="reserve" class="secondaire">Émettre la réserve</button>
        </div>
    </form>
    <?php endif; ?>

    <h3>Déclarer un écart manuellement</h3>
    <form method="post" class="filtres">
        <?= champ_csrf() ?><input type="hidden" name="action" value="declarer_ecart">
        <label>Article<select name="article_id" required><option value="">—</option><?= options($annonces + array_intersect_key($autres, array_flip(array_column($lots, 'article_id')))) ?></select></label>
        <label>Type<select name="type"><?= options(['manquant' => 'Manquant', 'excedent' => 'Excédent', 'endommage' => 'Endommagé']) ?></select></label>
        <label>Quantité<input type="number" name="quantite" min="1" required style="width:100px"></label>
        <label style="flex:1">Description<input name="description" maxlength="255"></label>
        <button class="secondaire">Déclarer</button>
    </form>
</div>
<?php pied();
