<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable');
const NB_LIGNES = 8;

$id = (int) ($_GET['id'] ?? 0);
$cmd = $id ? ligne('SELECT c.*, cl.raison_sociale AS client FROM commande c JOIN client cl ON cl.id = c.client_id WHERE c.id = ?', [$id]) : null;
if ($id && !$cmd) {
    flash('erreur', 'Commande introuvable.');
    rediriger('commandes.php');
}

$nbListes = $cmd ? (int) valeur('SELECT COUNT(*) FROM liste_prelevement WHERE commande_id = ?', [$id]) : 0;
$modifiable = !$cmd || ($cmd['statut'] === 'recue' && !$nbListes);
// une commande s'annule tant que rien n'a été sorti du stock ; les listes non commencées disparaissent avec elle
$annulable = $cmd && !in_array($cmd['statut'], ['expediee', 'annulee'], true)
    && !valeur("SELECT COUNT(*) FROM liste_prelevement li LEFT JOIN ligne_prelevement lp ON lp.liste_id = li.id
                 WHERE li.commande_id = ? AND (li.statut = 'en_cours' OR lp.quantite_prelevee > 0)", [$id])
    && !valeur('SELECT COUNT(*) FROM unite_expedition WHERE commande_id = ?', [$id]);
$supprimable = $cmd && !$nbListes && in_array($cmd['statut'], ['recue', 'annulee'], true);

// le client se choisit d'abord : il détermine les articles proposés
$clientId = (int) ($cmd['client_id'] ?? $_GET['client'] ?? 0);
$client = $clientId ? ligne('SELECT * FROM client WHERE id = ?', [$clientId]) : null;
$erreurs = [];

// ---------------------------------------------------------------------
//  Annulation et suppression
// ---------------------------------------------------------------------
if ($cmd && est_post() && (isset($_POST['annuler']) || isset($_POST['supprimer']))) {
    verifier_csrf();
    $pdo = db();
    try {
        if (isset($_POST['annuler']) && $annulable) {
            $pdo->beginTransaction();
            requete('DELETE FROM liste_prelevement WHERE commande_id = ?', [$id]);
            requete("UPDATE commande SET statut = 'annulee' WHERE id = ?", [$id]);
            $pdo->commit();
            flash('succes', "Commande {$cmd['numero']} annulée.");
            rediriger("commande_form.php?id=$id");
        }
        if (isset($_POST['supprimer']) && $supprimable) {
            requete('DELETE FROM commande WHERE id = ?', [$id]);
            flash('succes', "Commande {$cmd['numero']} supprimée.");
            rediriger('commandes.php');
        }
        flash('erreur', 'Cette commande ne peut plus être annulée ni supprimée.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash('erreur', message_erreur($e));
    }
    rediriger("commande_form.php?id=$id");
}

// ---------------------------------------------------------------------
//  Saisie
// ---------------------------------------------------------------------
$articles = $modifiable && $client ? articles_disponibles($clientId) : [];
$v = ['expedition_souhaitee' => $cmd['expedition_souhaitee'] ?? date('Y-m-d', strtotime('+2 days')),
      'adresse_livraison' => $cmd['adresse_livraison'] ?? $client['adresse'] ?? '',
      'code_postal' => $cmd['code_postal'] ?? $client['code_postal'] ?? '',
      'ville' => $cmd['ville'] ?? $client['ville'] ?? ''];
$lignesSaisies = array_fill(0, NB_LIGNES, ['article_id' => '', 'quantite' => '']);
if ($cmd) {
    foreach (lignes('SELECT article_id, quantite FROM ligne_commande WHERE commande_id = ? LIMIT ' . NB_LIGNES, [$id]) as $i => $l) {
        $lignesSaisies[$i] = ['article_id' => (string) $l['article_id'], 'quantite' => (string) $l['quantite']];
    }
}

if ($modifiable && $client && est_post()) {
    verifier_csrf();
    foreach ($v as $k => $_) { $v[$k] = trim((string) ($_POST[$k] ?? '')); }
    $lignesCmd = [];
    foreach (array_slice($_POST['lignes'] ?? [], 0, NB_LIGNES) as $i => $l) {
        $lignesSaisies[$i] = ['article_id' => (string) ($l['article_id'] ?? ''), 'quantite' => trim((string) ($l['quantite'] ?? ''))];
        if ($lignesSaisies[$i]['article_id'] === '' && $lignesSaisies[$i]['quantite'] === '') { continue; }
        $a = $articles[(int) $lignesSaisies[$i]['article_id']] ?? null;
        $q = filter_var($lignesSaisies[$i]['quantite'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$a) { $erreurs[] = 'Ligne ' . ($i + 1) . ' : choisissez un article en stock.'; continue; }
        if ($q === false) { $erreurs[] = 'Ligne ' . ($i + 1) . ' : la quantité doit être un entier positif.'; continue; }
        if (isset($lignesCmd[$a['id']])) { $erreurs[] = "L'article {$a['reference']} est saisi deux fois."; continue; }
        if ($q > $a['dispo']) { $erreurs[] = "Article {$a['reference']} : seulement " . nombre($a['dispo']) . ' unités disponibles.'; continue; }
        $lignesCmd[$a['id']] = $q;
    }
    if (!$lignesCmd && !$erreurs) { $erreurs[] = 'Ajoutez au moins un article à la commande.'; }
    $dateMin = $cmd['date_commande'] ?? date('Y-m-d');
    if (!DateTime::createFromFormat('Y-m-d', $v['expedition_souhaitee']) || $v['expedition_souhaitee'] < $dateMin) {
        $erreurs[] = 'La date d\'expédition doit être postérieure à la date de commande.';
    }
    if ($v['adresse_livraison'] === '' || mb_strlen($v['adresse_livraison']) > 150) { $erreurs[] = 'Indiquez l\'adresse de livraison (150 caractères max).'; }
    if (!preg_match('/^[0-9]{5}$/', $v['code_postal'])) { $erreurs[] = 'Le code postal doit comporter 5 chiffres.'; }
    if ($v['ville'] === '' || mb_strlen($v['ville']) > 80) { $erreurs[] = 'Indiquez la ville de livraison.'; }

    if (!$erreurs) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $donnees = [$v['expedition_souhaitee'], $v['adresse_livraison'], $v['code_postal'], $v['ville']];
            if ($cmd) {
                requete('UPDATE commande SET expedition_souhaitee = ?, adresse_livraison = ?, code_postal = ?, ville = ? WHERE id = ?', [...$donnees, $id]);
                requete('DELETE FROM ligne_commande WHERE commande_id = ?', [$id]);
                $nid = $id;
            } else {
                requete("INSERT INTO commande (client_id, date_commande, expedition_souhaitee, adresse_livraison, code_postal, ville)
                         VALUES (?, CURRENT_DATE, ?, ?, ?, ?)", [$clientId, ...$donnees]);
                $nid = (int) $pdo->lastInsertId();
            }
            foreach ($lignesCmd as $art => $q) {
                requete('INSERT INTO ligne_commande (commande_id, article_id, quantite) VALUES (?, ?, ?)', [$nid, $art, $q]);
            }
            $pdo->commit();
            flash('succes', 'Commande ' . valeur('SELECT numero FROM commande WHERE id = ?', [$nid]) . ' enregistrée.');
            rediriger("commande_form.php?id=$nid");
        } catch (PDOException $e) {
            $pdo->rollBack();
            $erreurs[] = message_erreur($e);
        }
    }
}

// ---------------------------------------------------------------------
//  Suivi d'une commande existante
// ---------------------------------------------------------------------
$lignesCmd = $cmd ? lignes('SELECT lc.quantite, ar.reference, ar.designation,
                                   (SELECT SUM(lp.quantite_prelevee) FROM ligne_prelevement lp JOIN liste_prelevement li ON li.id = lp.liste_id
                                      JOIN lot l ON l.id = lp.lot_id WHERE li.commande_id = lc.commande_id AND l.article_id = lc.article_id) AS prelevee
                              FROM ligne_commande lc JOIN article ar ON ar.id = lc.article_id
                             WHERE lc.commande_id = ? ORDER BY ar.reference', [$id]) : [];
$listes = $cmd ? lignes("SELECT li.id, li.numero, li.statut, li.regle_sortie, CONCAT(u.prenom, ' ', u.nom) AS preparateur
                           FROM liste_prelevement li LEFT JOIN utilisateur u ON u.id = li.preparateur_id
                          WHERE li.commande_id = ? ORDER BY li.id", [$id]) : [];
$expeditions = $cmd ? lignes('SELECT DISTINCT x.id, x.numero, x.statut FROM unite_expedition ue JOIN expedition x ON x.id = ue.expedition_id
                               WHERE ue.commande_id = ? ORDER BY x.id', [$id]) : [];

$clients = paires('SELECT id, raison_sociale FROM client ORDER BY raison_sociale');
entete($cmd ? "Commande {$cmd['numero']}" : 'Nouvelle commande', 'commandes.php');
?>
<?php foreach ($erreurs as $err): ?><div class="alerte erreur"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($cmd): ?>
<div class="carte">
    <h2>
        <span><?= pastille($cmd['statut']) ?> <?= e($cmd['client']) ?> · expédition souhaitée le <?= date_fr($cmd['expedition_souhaitee']) ?></span>
        <span class="actions">
            <?php if ($annulable): ?>
            <form method="post" onsubmit="return confirm('Annuler cette commande ?')"><?= champ_csrf() ?><button class="discret" name="annuler" value="1">Annuler la commande</button></form>
            <?php endif; ?>
            <?php if ($supprimable): ?>
            <form method="post" onsubmit="return confirm('Supprimer définitivement cette commande ?')"><?= champ_csrf() ?><button class="danger" name="supprimer" value="1">Supprimer</button></form>
            <?php endif; ?>
        </span>
    </h2>
    <p class="discret">Commandée le <?= date_fr($cmd['date_commande']) ?> ·
       livraison : <?= e("{$cmd['adresse_livraison']}, {$cmd['code_postal']} {$cmd['ville']}") ?></p>
</div>
<?php endif; ?>

<?php if (!$cmd && !$client): ?>
<div class="carte">
    <form method="get" class="filtres">
        <label>Client propriétaire <select name="client" required><?= options($clients, null, '— Choisir —') ?></select></label>
        <button type="submit">Continuer</button>
        <a class="bouton discret" href="commandes.php">Retour</a>
    </form>
</div>
<?php elseif ($modifiable): ?>
<div class="carte">
    <?php if (!$cmd): ?><h2>Commande pour <?= e($client['raison_sociale']) ?></h2><?php endif; ?>
    <form method="post" class="formulaire">
        <?= champ_csrf() ?>
        <label><span class="requis">Expédition souhaitée</span><input type="date" name="expedition_souhaitee" required value="<?= e($v['expedition_souhaitee']) ?>"></label>
        <label class="pleine"><span class="requis">Adresse de livraison</span><input name="adresse_livraison" required maxlength="150" value="<?= e($v['adresse_livraison']) ?>"></label>
        <label><span class="requis">Code postal</span><input name="code_postal" required pattern="[0-9]{5}" value="<?= e($v['code_postal']) ?>"></label>
        <label><span class="requis">Ville</span><input name="ville" required maxlength="80" value="<?= e($v['ville']) ?>"></label>
        <div class="pleine">
            <h3>Articles</h3>
            <?php if (!$articles): ?><p class="vide">Aucun article de ce client n'est disponible en stock.</p><?php endif; ?>
            <div class="tableau"><table>
                <tr><th>Article (disponible)</th><th class="nombre">Quantité</th></tr>
                <?php foreach ($lignesSaisies as $i => $l): ?>
                <tr>
                    <td><select name="lignes[<?= $i ?>][article_id]" style="width:100%">
                        <option value="">—</option>
                        <?php foreach ($articles as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= (string) $a['id'] === $l['article_id'] ? 'selected' : '' ?>><?= e("{$a['reference']} — {$a['designation']} (" . nombre($a['dispo']) . ')') ?></option>
                        <?php endforeach; ?>
                    </select></td>
                    <td class="nombre"><input type="number" name="lignes[<?= $i ?>][quantite]" min="1" style="width:110px" value="<?= e($l['quantite']) ?>"></td>
                </tr>
                <?php endforeach; ?>
            </table></div>
        </div>
        <div class="boutons pleine">
            <button type="submit">Enregistrer</button>
            <a class="bouton discret" href="commandes.php">Retour</a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="grille">
    <div class="carte">
        <h2>Articles commandés</h2>
        <div class="tableau"><table>
            <tr><th>Article</th><th class="nombre">Commandé</th><th class="nombre">Prélevé</th></tr>
            <?php foreach ($lignesCmd as $l): ?>
            <tr>
                <td><strong><?= e($l['reference']) ?></strong> <?= e($l['designation']) ?></td>
                <td class="nombre"><?= nombre($l['quantite']) ?></td>
                <td class="nombre"><?php if ($l['prelevee'] !== null): ?>
                    <span class="pastille <?= $l['prelevee'] < $l['quantite'] ? 'orange' : 'vert' ?>"><?= nombre($l['prelevee']) ?></span>
                <?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
    </div>
    <div class="carte">
        <h2>Préparation et expédition</h2>
        <?php if (!$listes && !$expeditions): ?><p class="vide">Aucune préparation.</p><?php endif; ?>
        <?php foreach ($listes as $l): ?>
        <p><a href="liste.php?id=<?= $l['id'] ?>"><?= e($l['numero']) ?></a> <?= pastille($l['statut']) ?> · <?= e($l['regle_sortie']) ?> · <?= e($l['preparateur'] ?? 'non affectée') ?></p>
        <?php endforeach; ?>
        <?php foreach ($expeditions as $x): ?>
        <p><a href="expedition.php?id=<?= $x['id'] ?>"><?= e($x['numero']) ?></a> <?= pastille($x['statut']) ?></p>
        <?php endforeach; ?>
        <?php if ($cmd['statut'] === 'recue' || $cmd['statut'] === 'en_preparation'): ?><p><a href="preparation.php">Gérer les listes de prélèvement</a></p>
        <?php elseif ($cmd['statut'] === 'preparee'): ?><p><a href="colisage.php?id=<?= $id ?>">Voir les colis et palettes</a></p><?php endif; ?>
    </div>
</div>
<p><a href="commandes.php">← Retour aux commandes</a></p>
<?php endif; ?>
<?php pied();
