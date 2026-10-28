<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable', 'accueil');

// Arrivée sur rendez-vous ou sans rendez-vous
$rdvId = (int) ($_GET['rdv'] ?? 0);
$rdv = $rdvId ? ligne("SELECT r.*, v.immatriculation, v.transporteur_id, t.raison_sociale, q.numero AS quai, tv.libelle AS type_vehicule
                         FROM rendez_vous r JOIN vehicule v ON v.id = r.vehicule_id JOIN transporteur t ON t.id = v.transporteur_id
                         JOIN type_vehicule tv ON tv.id = v.type_vehicule_id JOIN quai q ON q.id = r.quai_id
                        WHERE r.id = ? AND r.statut = 'planifie'", [$rdvId]) : null;
if ($rdvId && !$rdv) {
    flash('erreur', 'Ce rendez-vous n\'est plus attendu.');
    rediriger('flux.php');
}

$vehicules = lignes('SELECT v.id, v.immatriculation, v.transporteur_id, tv.libelle AS type, t.raison_sociale
                       FROM vehicule v JOIN type_vehicule tv ON tv.id = v.type_vehicule_id JOIN transporteur t ON t.id = v.transporteur_id
                      ORDER BY t.raison_sociale, v.immatriculation');
$chauffeurs = lignes("SELECT c.id, c.transporteur_id, CONCAT(c.nom, ' ', c.prenom) AS nom, c.validite_permis, t.raison_sociale
                        FROM chauffeur c JOIN transporteur t ON t.id = c.transporteur_id
                       WHERE (? IS NULL OR c.transporteur_id = ?) ORDER BY t.raison_sociale, c.nom",
                     [$rdv['transporteur_id'] ?? null, $rdv['transporteur_id'] ?? null]);

$v = ['vehicule_id' => $rdv['vehicule_id'] ?? '', 'chauffeur_id' => '', 'sens' => $rdv['sens'] ?? 'reception', 'observations' => ''];
$erreurs = [];

if (est_post()) {
    verifier_csrf();
    foreach ($v as $k => $_) { $v[$k] = trim((string) ($_POST[$k] ?? '')); }
    if ($rdv) { $v['vehicule_id'] = $rdv['vehicule_id']; $v['sens'] = $rdv['sens']; }

    $veh = array_values(array_filter($vehicules, fn($x) => (string) $x['id'] === $v['vehicule_id']))[0] ?? null;
    $ch = array_values(array_filter($chauffeurs, fn($x) => (string) $x['id'] === $v['chauffeur_id']))[0] ?? null;
    if (!$veh) { $erreurs[] = 'Choisissez le véhicule.'; }
    if (!$ch) { $erreurs[] = 'Choisissez le chauffeur.'; }
    if ($veh && $ch && $veh['transporteur_id'] !== $ch['transporteur_id']) { $erreurs[] = 'Le chauffeur n\'appartient pas au transporteur du véhicule.'; }
    if ($ch && $ch['validite_permis'] < date('Y-m-d')) { $erreurs[] = 'Le permis de ce chauffeur est expiré : accès refusé.'; }
    if (!in_array($v['sens'], ['reception', 'expedition'], true)) { $erreurs[] = 'Sens invalide.'; }
    if (mb_strlen($v['observations']) > 255) { $erreurs[] = 'Observations trop longues.'; }

    if (!$erreurs) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            requete('INSERT INTO passage (rendez_vous_id, vehicule_id, chauffeur_id, sens, observations) VALUES (?, ?, ?, ?, ?)',
                    [$rdv['id'] ?? null, $v['vehicule_id'], $v['chauffeur_id'], $v['sens'],
                     $v['observations'] ?: ($rdv ? null : 'Arrivée sans rendez-vous')]);
            $pid = (int) $pdo->lastInsertId();
            requete("INSERT INTO passage_etape (passage_id, type_etape_id, date_heure)
                     VALUES (?, (SELECT id FROM type_etape WHERE code = 'ARRIVEE'), NOW())", [$pid]);
            $pdo->commit();

            $msg = 'Arrivée enregistrée (' . valeur('SELECT numero FROM passage WHERE id = ?', [$pid]) . ').';
            if ($rdv) {
                $ecart = (int) ((time() - strtotime($rdv['debut'])) / 60);
                if ($ecart > 15) { $msg .= " Retard de " . duree($ecart) . " sur le rendez-vous."; }
                elseif ($ecart < -60) { $msg .= " Camion en avance de " . duree(-$ecart) . '.'; }
            }
            flash('succes', $msg);
            rediriger('flux.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $erreurs[] = message_erreur($e);
        }
    }
}

$optVeh = [];
foreach ($vehicules as $x) { $optVeh[$x['raison_sociale']][$x['id']] = "{$x['immatriculation']} — {$x['type']}"; }
$optCh = [];
foreach ($chauffeurs as $x) {
    $optCh[$x['raison_sociale']][$x['id']] = $x['nom'] . ($x['validite_permis'] < date('Y-m-d') ? ' (permis expiré)' : '');
}

entete($rdv ? "Arrivée — rendez-vous {$rdv['numero']}" : 'Arrivée sans rendez-vous', 'flux.php');
?>
<?php foreach ($erreurs as $err): ?><div class="alerte erreur"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($rdv):
    $ecart = (int) ((time() - strtotime($rdv['debut'])) / 60); ?>
<div class="carte">
    <h2>Rendez-vous prévu</h2>
    <p><strong><?= e($rdv['immatriculation']) ?></strong> (<?= e($rdv['type_vehicule']) ?>) · <?= e($rdv['raison_sociale']) ?> ·
       quai <strong><?= e($rdv['quai']) ?></strong> · <?= pastille($rdv['sens']) ?> ·
       créneau <?= heure($rdv['debut']) ?> – <?= heure($rdv['fin']) ?>
       <?php if ($ecart > 15): ?><span class="pastille rouge">Retard de <?= duree($ecart) ?></span>
       <?php elseif ($ecart >= -15): ?><span class="pastille vert">À l'heure</span>
       <?php else: ?><span class="pastille bleu">En avance de <?= duree(-$ecart) ?></span><?php endif; ?>
    </p>
</div>
<?php endif; ?>

<div class="carte">
    <form method="post" class="formulaire">
        <?= champ_csrf() ?>
        <?php if (!$rdv): ?>
        <label><span class="requis">Véhicule</span>
            <select name="vehicule_id" required>
                <option value="">— Immatriculation —</option>
                <?php foreach ($optVeh as $t => $liste): ?><optgroup label="<?= e($t) ?>"><?= options($liste, $v['vehicule_id']) ?></optgroup><?php endforeach; ?>
            </select>
        </label>
        <label><span class="requis">Sens</span>
            <select name="sens"><?= options(['reception' => 'Réception (déchargement)', 'expedition' => 'Expédition (chargement)'], $v['sens']) ?></select>
        </label>
        <?php endif; ?>
        <label><span class="requis">Chauffeur</span>
            <select name="chauffeur_id" required>
                <option value="">— Choisir —</option>
                <?php foreach ($optCh as $t => $liste): ?><optgroup label="<?= e($t) ?>"><?= options($liste, $v['chauffeur_id']) ?></optgroup><?php endforeach; ?>
            </select>
        </label>
        <label class="pleine">Observations<input name="observations" value="<?= e($v['observations']) ?>" maxlength="255" placeholder="ex. scellé n°…, palettes filmées…"></label>
        <div class="boutons pleine">
            <button type="submit">Enregistrer l'arrivée</button>
            <a class="bouton discret" href="flux.php">Annuler</a>
        </div>
    </form>
</div>
<?php if (!$rdv): ?>
<p class="discret">Un quai compatible et libre sera proposé au moment de l'appel à quai (après contrôle des documents).</p>
<?php endif; ?>
<?php pied();
