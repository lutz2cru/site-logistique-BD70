<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_profil('client');
const NB_LIGNES = 6;

// le client ne voit que ses propres commandes
$id = (int) ($_GET['id'] ?? 0);
$cmd = $id ? ligne('SELECT * FROM commande WHERE id = ? AND client_id = ?', [$id, $u['client_id']]) : null;
if ($id && !$cmd) {
    flash('erreur', 'Commande introuvable.');
    rediriger('client_commandes.php');
}
$erreurs = [];

// ---------------------------------------------------------------------
//  Annulation (tant que la préparation n'a pas commencé)
// ---------------------------------------------------------------------
if ($cmd && est_post() && isset($_POST['annuler'])) {
    verifier_csrf();
    $n = requete("UPDATE commande SET statut = 'annulee' WHERE id = ? AND statut = 'recue'
                    AND NOT EXISTS (SELECT 1 FROM liste_prelevement WHERE commande_id = ?)", [$id, $id])->rowCount();
    flash($n ? 'succes' : 'erreur', $n ? "Commande {$cmd['numero']} annulée." : 'La préparation a commencé : contactez l\'entrepôt pour annuler.');
    rediriger("client_commande.php?id=$id");
}

// ---------------------------------------------------------------------
//  Nouvelle commande : articles du client avec leur quantité disponible
// ---------------------------------------------------------------------
if (!$cmd) {
    $client = ligne('SELECT * FROM client WHERE id = ?', [$u['client_id']]);
    $articles = articles_disponibles($u['client_id']);
    $v = ['expedition_souhaitee' => date('Y-m-d', strtotime('+2 days')), 'adresse_livraison' => $client['adresse'],
          'code_postal' => $client['code_postal'], 'ville' => $client['ville']];
    $lignesSaisies = array_fill(0, NB_LIGNES, ['article_id' => '', 'quantite' => '']);

    if (est_post()) {
        verifier_csrf();
        foreach ($v as $k => $_) { $v[$k] = trim((string) ($_POST[$k] ?? '')); }
        $lignesCmd = [];
        foreach (array_slice($_POST['lignes'] ?? [], 0, NB_LIGNES) as $i => $l) {
            $lignesSaisies[$i] = ['article_id' => (string) ($l['article_id'] ?? ''), 'quantite' => trim((string) ($l['quantite'] ?? ''))];
            if ($lignesSaisies[$i]['article_id'] === '' && $lignesSaisies[$i]['quantite'] === '') { continue; }
            $a = $articles[(int) $lignesSaisies[$i]['article_id']] ?? null;
            $q = filter_var($lignesSaisies[$i]['quantite'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$a) { $erreurs[] = 'Ligne ' . ($i + 1) . ' : choisissez un article en stock.'; continue; }
            if ($q === false) { $erreurs[] = "Ligne " . ($i + 1) . ' : la quantité doit être un entier positif.'; continue; }
            if (isset($lignesCmd[$a['id']])) { $erreurs[] = "L'article {$a['reference']} est saisi deux fois."; continue; }
            if ($q > $a['dispo']) { $erreurs[] = "Article {$a['reference']} : seulement " . nombre($a['dispo']) . ' unités disponibles.'; continue; }
            $lignesCmd[$a['id']] = $q;
        }
        if (!$lignesCmd && !$erreurs) { $erreurs[] = 'Ajoutez au moins un article à la commande.'; }
        if ($v['expedition_souhaitee'] < date('Y-m-d', strtotime('+1 day'))) { $erreurs[] = 'L\'expédition doit être demandée au plus tôt pour demain.'; }
        if ($v['adresse_livraison'] === '' || mb_strlen($v['adresse_livraison']) > 150) { $erreurs[] = 'Indiquez l\'adresse de livraison (150 caractères max).'; }
        if (!preg_match('/^[0-9]{5}$/', $v['code_postal'])) { $erreurs[] = 'Le code postal doit comporter 5 chiffres.'; }
        if ($v['ville'] === '' || mb_strlen($v['ville']) > 80) { $erreurs[] = 'Indiquez la ville de livraison.'; }

        if (!$erreurs) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                requete("INSERT INTO commande (client_id, date_commande, expedition_souhaitee, adresse_livraison, code_postal, ville, canal)
                         VALUES (?, CURRENT_DATE, ?, ?, ?, ?, 'portail')",
                        [$u['client_id'], $v['expedition_souhaitee'], $v['adresse_livraison'], $v['code_postal'], $v['ville']]);
                $nid = (int) $pdo->lastInsertId();
                foreach ($lignesCmd as $art => $q) {
                    requete('INSERT INTO ligne_commande (commande_id, article_id, quantite) VALUES (?, ?, ?)', [$nid, $art, $q]);
                }
                $pdo->commit();
                flash('succes', 'Commande ' . valeur('SELECT numero FROM commande WHERE id = ?', [$nid]) . ' transmise à l\'entrepôt.');
                rediriger("client_commande.php?id=$nid");
            } catch (PDOException $e) {
                $pdo->rollBack();
                $erreurs[] = message_erreur($e);
            }
        }
    }

    entete('Nouvelle commande', 'client_commandes.php');
    ?>
    <?php foreach ($erreurs as $err): ?><div class="alerte erreur"><?= e($err) ?></div><?php endforeach; ?>
    <div class="carte">
        <form method="post" class="formulaire">
            <?= champ_csrf() ?>
            <label><span class="requis">Expédition souhaitée</span><input type="date" name="expedition_souhaitee" required
                   min="<?= date('Y-m-d', strtotime('+1 day')) ?>" value="<?= e($v['expedition_souhaitee']) ?>"></label>
            <label class="pleine"><span class="requis">Adresse de livraison</span><input name="adresse_livraison" required maxlength="150" value="<?= e($v['adresse_livraison']) ?>"></label>
            <label><span class="requis">Code postal</span><input name="code_postal" required pattern="[0-9]{5}" value="<?= e($v['code_postal']) ?>"></label>
            <label><span class="requis">Ville</span><input name="ville" required maxlength="80" value="<?= e($v['ville']) ?>"></label>
            <div class="pleine">
                <h3>Articles</h3>
                <?php if (!$articles): ?><p class="vide">Aucun de vos articles n'est disponible en stock.</p><?php endif; ?>
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
                <button type="submit">Transmettre la commande</button>
                <a class="bouton discret" href="client_commandes.php">Annuler</a>
            </div>
        </form>
    </div>
    <?php
    pied();
    exit;
}

// ---------------------------------------------------------------------
//  Suivi d'une commande : préparation puis expédition
// ---------------------------------------------------------------------
$lignesCmd = lignes('SELECT lc.quantite, ar.reference, ar.designation,
                            (SELECT SUM(lp.quantite_prelevee) FROM ligne_prelevement lp JOIN liste_prelevement li ON li.id = lp.liste_id
                               JOIN lot l ON l.id = lp.lot_id WHERE li.commande_id = lc.commande_id AND l.article_id = lc.article_id) AS prelevee
                       FROM ligne_commande lc JOIN article ar ON ar.id = lc.article_id
                      WHERE lc.commande_id = ? ORDER BY ar.reference', [$id]);
$listes = lignes('SELECT numero, statut, debut, fin FROM liste_prelevement WHERE commande_id = ? ORDER BY id', [$id]);
$expeditions = lignes("SELECT ex.id, ex.numero, ex.date_chargement, ex.statut, v.immatriculation, t.raison_sociale AS transporteur,
                              fn_date_etape(p.id, 'DEPART') AS depart,
                              COUNT(ue.id) AS nb_unites, SUM(ue.type = 'palette') AS palettes, SUM(ue.poids_kg) AS poids,
                              (SELECT GROUP_CONCAT(d.numero ORDER BY d.type SEPARATOR ', ') FROM document_transport d WHERE d.expedition_id = ex.id) AS documents
                         FROM unite_expedition ue JOIN expedition ex ON ex.id = ue.expedition_id
                         JOIN passage p ON p.id = ex.passage_id JOIN vehicule v ON v.id = p.vehicule_id
                         JOIN transporteur t ON t.id = v.transporteur_id
                        WHERE ue.commande_id = ? GROUP BY ex.id ORDER BY ex.id", [$id]);

// étapes du suivi affichées comme une frise
$debutPrep = min(array_filter(array_column($listes, 'debut')) ?: [null]);
$finPrep = count($listes) && !array_filter($listes, fn($l) => $l['statut'] !== 'terminee') ? max(array_column($listes, 'fin')) : null;
$depart = $expeditions ? max(array_column($expeditions, 'depart')) : null;
$etapes = [
    ['Commande reçue', $cmd['date_commande']],
    ['Préparation commencée', $debutPrep],
    ['Commande préparée', $finPrep],
    ['Chargée dans le camion', $expeditions ? max(array_column($expeditions, 'date_chargement')) : null],
    ['Partie de l\'entrepôt', $depart],
];

entete("Commande {$cmd['numero']}", 'client_commandes.php');
?>
<div class="carte">
    <h2>
        <span><?= pastille($cmd['statut']) ?> Expédition souhaitée le <?= date_fr($cmd['expedition_souhaitee']) ?></span>
        <?php if ($cmd['statut'] === 'recue' && !$listes): ?>
        <form method="post" onsubmit="return confirm('Annuler cette commande ?')"><?= champ_csrf() ?><button class="danger" name="annuler" value="1">Annuler la commande</button></form>
        <?php endif; ?>
    </h2>
    <p class="discret">Passée le <?= date_fr($cmd['date_commande']) ?> (<?= $cmd['canal'] === 'portail' ? 'portail client' : 'échange EDI' ?>) ·
       livraison : <?= e("{$cmd['adresse_livraison']}, {$cmd['code_postal']} {$cmd['ville']}") ?></p>
    <?php if ($cmd['statut'] !== 'annulee'): ?>
    <div class="tableau"><table>
        <tr><?php foreach ($etapes as [$lib, $d]): ?><th><?= $d ? '✔' : '○' ?> <?= e($lib) ?></th><?php endforeach; ?></tr>
        <tr><?php foreach ($etapes as [$lib, $d]): ?><td><?= $d ? (strlen($d) > 10 ? date_heure_fr($d) : date_fr($d)) : '' ?></td><?php endforeach; ?></tr>
    </table></div>
    <?php endif; ?>
</div>

<div class="grille">
    <div class="carte">
        <h2>Articles commandés</h2>
        <div class="tableau"><table>
            <tr><th>Article</th><th class="nombre">Commandé</th><th class="nombre">Préparé</th></tr>
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
        <h2>Expédition</h2>
        <?php if (!$expeditions): ?><p class="vide">Pas encore chargée.</p><?php else: ?>
        <?php foreach ($expeditions as $x): ?>
        <p><strong><?= e($x['numero']) ?></strong> <?= pastille($x['statut']) ?><br>
           Camion <?= e($x['immatriculation']) ?> · <?= e($x['transporteur']) ?><br>
           <?= $x['nb_unites'] ?> unité(s) dont <?= (int) $x['palettes'] ?> palette(s) · <?= nombre($x['poids'], 1) ?> kg<br>
           <?= $x['depart'] ? 'Départ le ' . date_heure_fr($x['depart']) : 'En attente de départ' ?>
           <?= $x['documents'] ? '<br><small>Documents : ' . e($x['documents']) . '</small>' : '' ?></p>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php pied();
