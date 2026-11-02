<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable', 'magasinier');
$peutSaisir = a_profil('magasinier');   // le responsable consulte, le magasinier charge le camion

// Une expédition par camion : on y accède par son numéro ou depuis le passage
$id = (int) ($_GET['id'] ?? 0);
$passageId = $id ? (int) valeur('SELECT passage_id FROM expedition WHERE id = ?', [$id]) : (int) ($_GET['passage'] ?? 0);
$p = ligne("SELECT p.id, p.numero, p.sens, v.immatriculation, v.palettes_max, v.poids_max_kg, t.raison_sociale AS transporteur,
                   CONCAT(c.prenom, ' ', c.nom) AS chauffeur, q.numero AS quai, te.code AS etape, te.libelle AS etape_libelle
              FROM passage p JOIN vehicule v ON v.id = p.vehicule_id JOIN transporteur t ON t.id = v.transporteur_id
              JOIN chauffeur c ON c.id = p.chauffeur_id JOIN type_etape te ON te.id = p.etape_courante_id
              LEFT JOIN quai q ON q.id = p.quai_id
             WHERE p.id = ? AND p.sens = 'expedition'", [$passageId]);
if (!$p) { rediriger('expeditions.php'); }
$exp = ligne('SELECT * FROM expedition WHERE passage_id = ?', [$passageId]);
$id = (int) ($exp['id'] ?? 0);
$aQuai = in_array($p['etape'], ['APPEL_QUAI', 'DEBUT_OP'], true);
$enChargement = $exp && $exp['statut'] === 'en_chargement' && $aQuai;
$erreurs = [];

// ---------------------------------------------------------------------
//  Actions du magasinier
// ---------------------------------------------------------------------
if (est_post()) {
    verifier_csrf();
    if (!$peutSaisir) { exit('Action non autorisée.'); }
    $action = $_POST['action'] ?? '';
    $pdo = db();
    try {
        if ($action === 'demarrer' && !$exp && $aQuai) {
            requete('INSERT INTO expedition (passage_id, date_chargement) VALUES (?, NOW())', [$passageId]);
            flash('succes', 'Chargement commencé.');
        } elseif ($action === 'charger' && $enChargement) {
            $ids = array_map('intval', $_POST['unites'] ?? []);
            if (!$ids) {
                $erreurs[] = 'Cochez au moins une unité à charger.';
            } else {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $charge = ligne("SELECT COALESCE(SUM(type = 'palette'), 0) AS palettes, COALESCE(SUM(poids_kg), 0) AS poids FROM unite_expedition
                                  WHERE expedition_id = ? OR (expedition_id IS NULL AND id IN ($ph))", [$id, ...$ids]);
                if ($charge['palettes'] > $p['palettes_max']) { $erreurs[] = "Le camion accepte {$p['palettes_max']} palettes au maximum."; }
                if ($charge['poids'] > $p['poids_max_kg']) { $erreurs[] = 'Le camion accepte ' . nombre($p['poids_max_kg']) . ' kg au maximum.'; }
                if (!$erreurs) {
                    requete("UPDATE unite_expedition ue JOIN commande c ON c.id = ue.commande_id SET ue.expedition_id = ?
                              WHERE ue.expedition_id IS NULL AND c.statut = 'preparee' AND ue.id IN ($ph)", [$id, ...$ids]);
                    flash('succes', count($ids) . ' unité(s) chargée(s).');
                }
            }
        } elseif ($action === 'retirer' && $enChargement) {
            requete('UPDATE unite_expedition SET expedition_id = NULL WHERE id = ? AND expedition_id = ?', [$_POST['unite_id'] ?? 0, $id]);
            flash('succes', 'Unité retirée du camion.');
        } elseif ($action === 'terminer' && $enChargement) {
            if (!valeur('SELECT COUNT(*) FROM unite_expedition WHERE expedition_id = ?', [$id])) {
                $erreurs[] = 'Chargez au moins une unité avant de terminer.';
            } else {
                // les documents de transport accompagnent le camion dès la fin du chargement
                $pdo->beginTransaction();
                requete("UPDATE expedition SET statut = 'chargee' WHERE id = ?", [$id]);
                foreach (['BL', 'CMR'] as $type) {
                    requete('INSERT INTO document_transport (expedition_id, type, date_emission) VALUES (?, ?, NOW())', [$id, $type]);
                }
                $pdo->commit();
                flash('succes', 'Chargement terminé : bon de livraison et lettre de voiture édités.');
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $erreurs[] = message_erreur($e);
    }
    if (!$erreurs) { rediriger("expedition.php?passage=$passageId"); }
}

// ---------------------------------------------------------------------
//  Données
// ---------------------------------------------------------------------
$colonnes = "ue.id, ue.sscc, ue.type, ue.poids_kg, c.id AS commande_id, c.numero AS commande, c.ville, cl.raison_sociale AS client";
$jointures = "FROM unite_expedition ue JOIN commande c ON c.id = ue.commande_id JOIN client cl ON cl.id = c.client_id";
$chargees = $exp ? lignes("SELECT $colonnes $jointures WHERE ue.expedition_id = ? ORDER BY c.numero, ue.id", [$id]) : [];
$disponibles = $enChargement ? lignes("SELECT $colonnes $jointures WHERE ue.expedition_id IS NULL AND c.statut = 'preparee'
                                       ORDER BY c.expedition_souhaitee, c.numero, ue.id") : [];
$documents = $exp ? lignes('SELECT id, numero, type, date_emission FROM document_transport WHERE expedition_id = ? ORDER BY type, id', [$id]) : [];
$palettes = count(array_filter($chargees, fn($u) => $u['type'] === 'palette'));
$poids = array_sum(array_column($chargees, 'poids_kg'));

entete($exp ? "Expédition {$exp['numero']}" : "Chargement — {$p['immatriculation']}", 'expeditions.php');
?>
<?php foreach ($erreurs as $err): ?><div class="alerte erreur"><?= e($err) ?></div><?php endforeach; ?>

<div class="carte">
    <h2>
        <span><?= $exp ? pastille($exp['statut']) : '' ?> Camion <?= e($p['immatriculation']) ?> · <?= e($p['transporteur']) ?></span>
        <?php if ($peutSaisir && !$exp && $aQuai): ?>
        <form method="post"><?= champ_csrf() ?><button name="action" value="demarrer">Commencer le chargement</button></form>
        <?php elseif ($peutSaisir && $enChargement): ?>
        <form method="post" onsubmit="return confirm('Terminer le chargement et éditer les documents de transport ?')"><?= champ_csrf() ?><button name="action" value="terminer">Terminer le chargement</button></form>
        <?php endif; ?>
    </h2>
    <p class="discret">
        Quai <?= e($p['quai'] ?? '–') ?> · chauffeur <?= e($p['chauffeur']) ?> · étape : <?= e($p['etape_libelle']) ?> ·
        <a href="passage.php?id=<?= $p['id'] ?>"><?= e($p['numero']) ?></a><?= $exp ? ' · chargement du ' . date_heure_fr($exp['date_chargement']) : '' ?>
    </p>
    <?php if (!$exp && !$aQuai): ?><p class="vide">Ce camion n'est pas à quai.</p><?php endif; ?>
    <?php if ($exp): ?>
    <div class="kpis">
        <div class="kpi"><div class="valeur"><?= $palettes ?> / <?= $p['palettes_max'] ?></div><div class="libelle">palettes chargées</div></div>
        <div class="kpi"><div class="valeur"><?= nombre($poids) ?> / <?= nombre($p['poids_max_kg']) ?></div><div class="libelle">kg chargés</div></div>
        <div class="kpi"><div class="valeur"><?= count(array_unique(array_column($chargees, 'commande_id'))) ?></div><div class="libelle">commande(s)</div></div>
    </div>
    <?php endif; ?>
    <?php if ($documents): ?>
    <p><strong>Documents de transport :</strong>
        <?php foreach ($documents as $d): ?>
        <a class="bouton petit secondaire" href="document.php?id=<?= $d['id'] ?>"><?= e(($d['type'] === 'BL' ? 'Bon de livraison ' : 'Lettre de voiture ') . $d['numero']) ?></a>
        <?php endforeach; ?>
    </p>
    <?php endif; ?>
</div>

<?php if ($disponibles && $peutSaisir): ?>
<div class="carte">
    <h2>Unités prêtes à charger <span class="discret"><?= count($disponibles) ?></span></h2>
    <form method="post">
        <?= champ_csrf() ?>
        <div class="tableau"><table>
            <tr><th></th><th>SSCC</th><th>Type</th><th>Commande</th><th>Client</th><th>Destination</th><th class="nombre">Poids (kg)</th></tr>
            <?php foreach ($disponibles as $un): ?>
            <tr>
                <td><input type="checkbox" name="unites[]" value="<?= $un['id'] ?>"></td>
                <td><?= e($un['sscc']) ?></td><td><?= pastille($un['type']) ?></td>
                <td><a href="colisage.php?id=<?= $un['commande_id'] ?>"><?= e($un['commande']) ?></a></td>
                <td><?= e($un['client']) ?></td><td><?= e($un['ville']) ?></td>
                <td class="nombre"><?= nombre($un['poids_kg'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <div class="boutons"><button name="action" value="charger">Charger les unités cochées</button></div>
    </form>
</div>
<?php elseif ($enChargement && $peutSaisir): ?>
<div class="alerte info">Aucune unité prête : constituez d'abord les colis et palettes des commandes préparées.</div>
<?php endif; ?>

<?php if ($exp): ?>
<div class="carte">
    <h2>Unités chargées <span class="discret"><?= count($chargees) ?></span></h2>
    <?php if (!$chargees): ?><p class="vide">Rien n'a encore été chargé.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>SSCC</th><th>Type</th><th>Commande</th><th>Client</th><th>Destination</th><th class="nombre">Poids (kg)</th><th></th></tr>
        <?php foreach ($chargees as $un): ?>
        <tr>
            <td><?= e($un['sscc']) ?></td><td><?= pastille($un['type']) ?></td>
            <td><a href="colisage.php?id=<?= $un['commande_id'] ?>"><?= e($un['commande']) ?></a></td>
            <td><?= e($un['client']) ?></td><td><?= e($un['ville']) ?></td>
            <td class="nombre"><?= nombre($un['poids_kg'], 2) ?></td>
            <td class="actions-ligne">
                <?php if ($peutSaisir && $enChargement): ?>
                <form method="post"><?= champ_csrf() ?><input type="hidden" name="unite_id" value="<?= $un['id'] ?>"><button class="petit danger" name="action" value="retirer">Retirer</button></form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>
<p><a href="expeditions.php">← Retour aux expéditions</a></p>
<?php pied();
