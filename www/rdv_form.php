<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_profil('accueil', 'transporteur');
$estTransporteur = $u['profil'] === 'transporteur';

// Rendez-vous existant (le transporteur ne voit que les siens)
$id = (int) ($_GET['id'] ?? 0);
$rdv = null;
if ($id) {
    $rdv = ligne('SELECT r.*, v.transporteur_id FROM rendez_vous r JOIN vehicule v ON v.id = r.vehicule_id WHERE r.id = ?', [$id]);
    if (!$rdv || ($estTransporteur && $rdv['transporteur_id'] !== $u['transporteur_id'])) {
        flash('erreur', 'Rendez-vous introuvable.');
        rediriger('rdv.php');
    }
}
$modifiable = !$rdv || $rdv['statut'] === 'planifie';

$vehicules = lignes('SELECT v.id, v.immatriculation, tv.libelle AS type, t.raison_sociale, v.type_vehicule_id
                       FROM vehicule v JOIN type_vehicule tv ON tv.id = v.type_vehicule_id JOIN transporteur t ON t.id = v.transporteur_id
                      WHERE (? IS NULL OR v.transporteur_id = ?) ORDER BY t.raison_sociale, v.immatriculation',
                    [$estTransporteur ? $u['transporteur_id'] : null, $estTransporteur ? $u['transporteur_id'] : null]);
$quais = lignes("SELECT id, numero, type, statut FROM quai ORDER BY numero");

// valeurs du formulaire
$v = [
    'vehicule_id' => $rdv['vehicule_id'] ?? '',
    'quai_id'     => $rdv['quai_id'] ?? '',
    'date'        => $rdv ? date('Y-m-d', strtotime($rdv['debut'])) : (filtre('date') ?: date('Y-m-d', strtotime('+1 day'))),
    'heure'       => $rdv ? date('H:i', strtotime($rdv['debut'])) : '08:00',
    'duree'       => $rdv ? (strtotime($rdv['fin']) - strtotime($rdv['debut'])) / 60 : 60,
    'sens'        => $rdv['sens'] ?? 'reception',
    'commentaire' => $rdv['commentaire'] ?? '',
];
$erreurs = [];
$disponibles = null;

if (est_post()) {
    verifier_csrf();
    foreach ($v as $k => $_) { $v[$k] = trim((string) ($_POST[$k] ?? $v[$k])); }
    $action = $_POST['action'] ?? 'enregistrer';

    if ($action === 'annuler' && $rdv && $modifiable) {
        $motif = trim($_POST['motif_annulation'] ?? '');
        if ($motif === '') {
            $erreurs[] = 'Indiquez le motif de l\'annulation.';
        } else {
            requete("UPDATE rendez_vous SET statut = 'annule', motif_annulation = ? WHERE id = ?", [$motif, $id]);
            flash('succes', "Rendez-vous {$rdv['numero']} annulé.");
            rediriger('rdv.php');
        }
    } elseif ($modifiable) {
        // contrôle des saisies
        $vehiculesIds = array_column($vehicules, 'id');
        if (!in_array((int) $v['vehicule_id'], $vehiculesIds, true)) { $erreurs[] = 'Choisissez un véhicule.'; }
        if (!in_array($v['sens'], ['reception', 'expedition'], true)) { $erreurs[] = 'Sens de flux invalide.'; }
        $debut = DateTime::createFromFormat('Y-m-d H:i', $v['date'] . ' ' . $v['heure']);
        if (!$debut) { $erreurs[] = 'Date ou heure invalide.'; }
        elseif ($debut < new DateTime() && !$rdv) { $erreurs[] = 'Le rendez-vous doit être dans le futur.'; }
        elseif ((int) $debut->format('H') < 6 || (int) $debut->format('H') >= 21) { $erreurs[] = 'L\'entrepôt reçoit de 6 h à 21 h.'; }
        $duree = (int) $v['duree'];
        if ($duree < 15 || $duree > 240) { $erreurs[] = 'La durée doit être comprise entre 15 min et 4 h.'; }
        if (mb_strlen($v['commentaire']) > 255) { $erreurs[] = 'Commentaire trop long (255 caractères max).'; }

        if (!$erreurs && $action === 'verifier') {
            // quais compatibles et libres sur ce créneau (mêmes règles que le déclencheur)
            $fin = (clone $debut)->modify("+$duree minutes");
            $disponibles = lignes("SELECT q.id, q.numero, q.type,
                                          GROUP_CONCAT(DISTINCT eq.libelle SEPARATOR ', ') AS equipements
                                     FROM quai q
                                     JOIN quai_type_vehicule qtv ON qtv.quai_id = q.id
                                     JOIN vehicule v ON v.id = ? AND v.type_vehicule_id = qtv.type_vehicule_id
                                     LEFT JOIN quai_equipement qe ON qe.quai_id = q.id
                                     LEFT JOIN equipement eq ON eq.id = qe.equipement_id
                                    WHERE q.statut <> 'hors_service' AND q.type IN (?, 'mixte')
                                      AND NOT EXISTS (SELECT 1 FROM rendez_vous r WHERE r.quai_id = q.id AND r.id <> ?
                                                       AND r.statut IN ('planifie','honore') AND r.debut < ? AND r.fin > ?)
                                    GROUP BY q.id ORDER BY q.type = 'mixte', q.numero",
                [$v['vehicule_id'], $v['sens'], $id, $fin->format('Y-m-d H:i'), $debut->format('Y-m-d H:i')]);
        } elseif (!$erreurs) {
            if (!in_array((int) $v['quai_id'], array_column($quais, 'id'), true)) {
                $erreurs[] = 'Choisissez un quai (bouton « Vérifier les disponibilités »).';
            } else {
                $fin = (clone $debut)->modify("+$duree minutes");
                $donnees = [$v['quai_id'], $v['vehicule_id'], $debut->format('Y-m-d H:i'), $fin->format('Y-m-d H:i'), $v['sens'], $v['commentaire'] ?: null];
                try {
                    // le déclencheur vérifie chevauchement et compatibilité véhicule / quai
                    if ($rdv) {
                        requete('UPDATE rendez_vous SET quai_id = ?, vehicule_id = ?, debut = ?, fin = ?, sens = ?, commentaire = ? WHERE id = ?', [...$donnees, $id]);
                        flash('succes', "Rendez-vous {$rdv['numero']} modifié.");
                    } else {
                        requete('INSERT INTO rendez_vous (quai_id, vehicule_id, debut, fin, sens, commentaire) VALUES (?, ?, ?, ?, ?, ?)', $donnees);
                        $num = valeur('SELECT numero FROM rendez_vous WHERE id = LAST_INSERT_ID()');
                        flash('succes', "Rendez-vous $num enregistré.");
                    }
                    rediriger('planning.php?date=' . $debut->format('Y-m-d'));
                } catch (PDOException $e) {
                    $erreurs[] = message_erreur($e);
                }
            }
        }
    }
}

$optVehicules = [];
foreach ($vehicules as $veh) {
    $optVehicules[$veh['raison_sociale']][$veh['id']] = $veh['immatriculation'] . ' — ' . $veh['type'];
}
entete($rdv ? "Rendez-vous {$rdv['numero']}" : 'Nouveau rendez-vous', 'rdv.php');
?>
<?php foreach ($erreurs as $err): ?><div class="alerte erreur"><?= e($err) ?></div><?php endforeach; ?>
<?php if (!$modifiable): ?>
    <div class="alerte info">Ce rendez-vous est <?= mb_strtolower(STATUTS[$rdv['statut']][0]) ?> et ne peut plus être modifié.</div>
<?php endif; ?>

<div class="carte">
    <form method="post" class="formulaire">
        <?= champ_csrf() ?>
        <fieldset <?= $modifiable ? '' : 'disabled' ?> style="display:contents">
        <label class="pleine"><span class="requis">Véhicule</span>
            <select name="vehicule_id" required>
                <option value="">— Choisir un véhicule —</option>
                <?php foreach ($optVehicules as $transp => $liste): ?>
                    <optgroup label="<?= e($transp) ?>"><?= options($liste, $v['vehicule_id']) ?></optgroup>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span class="requis">Sens</span>
            <select name="sens"><?= options(['reception' => 'Réception (déchargement)', 'expedition' => 'Expédition (chargement)'], $v['sens']) ?></select>
        </label>
        <label><span class="requis">Date</span><input type="date" name="date" value="<?= e($v['date']) ?>" required></label>
        <label><span class="requis">Heure d'arrivée</span><input type="time" name="heure" value="<?= e($v['heure']) ?>" min="06:00" max="20:45" step="900" required></label>
        <label><span class="requis">Durée prévue</span>
            <select name="duree"><?= options([30 => '30 min', 45 => '45 min', 60 => '1 h', 90 => '1 h 30', 120 => '2 h', 180 => '3 h'], $v['duree']) ?></select>
        </label>
        <label><span class="requis">Quai</span>
            <select name="quai_id" <?= $disponibles ? 'disabled title="Choisissez parmi les quais proposés ci-dessous"' : '' ?>>
                <option value="">— Vérifier les disponibilités —</option>
                <?php foreach ($quais as $q): ?>
                    <option value="<?= $q['id'] ?>" <?= (string) $q['id'] === (string) $v['quai_id'] ? 'selected' : '' ?> <?= $q['statut'] === 'hors_service' ? 'disabled' : '' ?>>
                        <?= e($q['numero'] . ' — ' . STATUTS[$q['type']][0] . ($q['statut'] === 'hors_service' ? ' (hors service)' : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="pleine">Commentaire<input name="commentaire" value="<?= e($v['commentaire']) ?>" maxlength="255" placeholder="ex. hayon obligatoire, livraison urgente…"></label>
        </fieldset>

        <?php if ($disponibles !== null): ?>
        <div class="pleine">
            <h3>Quais compatibles et libres sur ce créneau</h3>
            <?php if (!$disponibles): ?>
                <p class="alerte erreur">Aucun quai compatible n'est libre sur ce créneau. Essayez un autre horaire.</p>
            <?php else: ?>
                <div class="cases">
                <?php foreach ($disponibles as $i => $q): ?>
                    <label><input type="radio" name="quai_id" value="<?= $q['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
                        <strong><?= e($q['numero']) ?></strong> <?= pastille($q['type']) ?> <small><?= e($q['equipements']) ?></small></label>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($modifiable): ?>
        <div class="boutons pleine">
            <button type="submit" name="action" value="verifier" class="secondaire" formnovalidate>Vérifier les disponibilités</button>
            <button type="submit" name="action" value="enregistrer">Enregistrer</button>
            <a class="bouton discret" href="rdv.php">Retour</a>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if ($rdv && $modifiable): ?>
<div class="carte">
    <h2>Annuler ce rendez-vous</h2>
    <form method="post" class="filtres">
        <?= champ_csrf() ?>
        <label style="flex:1">Motif de l'annulation<input name="motif_annulation" required maxlength="255"></label>
        <button type="submit" name="action" value="annuler" class="danger">Annuler le rendez-vous</button>
    </form>
</div>
<?php endif; ?>
<?php pied();
