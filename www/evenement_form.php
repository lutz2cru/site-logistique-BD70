<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

// Évènements du planning (maintenance, nettoyage, inventaire, audit, formation, fermeture) :
// inscrits par le responsable d'exploitation. Les règles (pas de rendez-vous planifié sous un
// évènement bloquant, et inversement) sont contrôlées par les déclencheurs de la base.
$u = exiger_profil('responsable');

$id = (int) ($_GET['id'] ?? 0);
$evenement = $id ? ligne('SELECT * FROM evenement WHERE id = ?', [$id]) : null;
if ($id && !$evenement) { rediriger('planning.php'); }

$jour = filtre('date') ?: date('Y-m-d');
if (!DateTime::createFromFormat('Y-m-d', $jour)) { $jour = date('Y-m-d'); }
$quais = paires('SELECT id, numero FROM quai ORDER BY numero');
$types = array_map(fn($t) => $t[0], TYPES_EVENEMENT);

$v = [
    'type'        => $evenement['type'] ?? 'maintenance',
    'libelle'     => $evenement['libelle'] ?? '',
    'quai_id'     => (string) ($evenement['quai_id'] ?? ''),
    'debut'       => $evenement ? date('Y-m-d\TH:i', strtotime($evenement['debut'])) : "{$jour}T13:30",
    'fin'         => $evenement ? date('Y-m-d\TH:i', strtotime($evenement['fin'])) : "{$jour}T16:30",
    'bloquant'    => $evenement ? (bool) $evenement['bloquant'] : true,
    'commentaire' => $evenement['commentaire'] ?? '',
];
$erreurs = [];
$conflits = [];   // rendez-vous planifiés qui empêchent de bloquer le créneau

if (est_post()) {
    verifier_csrf();
    $action = $_POST['action'] ?? 'enregistrer';

    if ($action === 'supprimer' && $evenement) {
        requete('DELETE FROM evenement WHERE id = ?', [$id]);
        flash('succes', "Évènement « {$evenement['libelle']} » supprimé.");
        rediriger('planning.php?date=' . substr($evenement['debut'], 0, 10));
    }

    foreach (['type', 'libelle', 'quai_id', 'debut', 'fin', 'commentaire'] as $k) { $v[$k] = trim((string) ($_POST[$k] ?? '')); }
    $v['bloquant'] = !empty($_POST['bloquant']);

    if (!isset(TYPES_EVENEMENT[$v['type']])) { $erreurs[] = 'Type d\'évènement invalide.'; }
    if ($v['libelle'] === '' || mb_strlen($v['libelle']) > 100) { $erreurs[] = 'Le libellé est obligatoire (100 caractères max).'; }
    if ($v['quai_id'] !== '' && !isset($quais[(int) $v['quai_id']])) { $erreurs[] = 'Quai invalide.'; }
    $debut = DateTime::createFromFormat('Y-m-d\TH:i', $v['debut']);
    $fin = DateTime::createFromFormat('Y-m-d\TH:i', $v['fin']);
    if (!$debut || !$fin) { $erreurs[] = 'Dates de début et de fin invalides.'; }
    elseif ($fin <= $debut) { $erreurs[] = 'La fin doit être postérieure au début.'; }
    if (mb_strlen($v['commentaire']) > 255) { $erreurs[] = 'Commentaire trop long (255 caractères max).'; }

    if (!$erreurs) {
        $quai = $v['quai_id'] === '' ? null : (int) $v['quai_id'];
        $donnees = [$v['type'], $v['libelle'], $quai, $debut->format('Y-m-d H:i'), $fin->format('Y-m-d H:i'),
                    (int) $v['bloquant'], $v['commentaire'] ?: null];
        try {
            // le déclencheur refuse un évènement bloquant posé sur des rendez-vous encore planifiés
            if ($evenement) {
                requete('UPDATE evenement SET type = ?, libelle = ?, quai_id = ?, debut = ?, fin = ?, bloquant = ?, commentaire = ? WHERE id = ?',
                    array_merge($donnees, [$id]));
            } else {
                requete('INSERT INTO evenement (type, libelle, quai_id, debut, fin, bloquant, commentaire, auteur_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    array_merge($donnees, [$u['id']]));
            }
            flash('succes', "Évènement « {$v['libelle']} » enregistré au planning.");
            rediriger('planning.php?date=' . $debut->format('Y-m-d'));
        } catch (PDOException $e) {
            $erreurs[] = message_erreur($e);
            $conflits = lignes("SELECT r.id, r.numero, r.debut, r.fin, q.numero AS quai, t.raison_sociale
                                  FROM rendez_vous r JOIN quai q ON q.id = r.quai_id
                                  JOIN vehicule v ON v.id = r.vehicule_id JOIN transporteur t ON t.id = v.transporteur_id
                                 WHERE r.statut = 'planifie' AND (? IS NULL OR r.quai_id = ?)
                                   AND r.debut > ? - INTERVAL 4 HOUR AND r.debut < ? AND r.fin > ?
                                 ORDER BY r.debut",
                [$quai, $quai, $donnees[3], $donnees[4], $donnees[3]]);
        }
    }
}

$auteur = $evenement && $evenement['auteur_id']
    ? valeur("SELECT CONCAT(prenom, ' ', nom) FROM utilisateur WHERE id = ?", [$evenement['auteur_id']]) : null;
entete($evenement ? 'Évènement du planning' : 'Nouvel évènement', 'planning.php');
?>
<?php foreach ($erreurs as $err): ?><div class="alerte erreur"><?= e($err) ?></div><?php endforeach; ?>
<?php if ($conflits): ?>
<div class="carte tableau">
    <h2>Rendez-vous à déplacer ou annuler</h2>
    <table>
        <tr><th>Rendez-vous</th><th>Quai</th><th>Créneau</th><th>Transporteur</th></tr>
        <?php foreach ($conflits as $r): ?>
        <tr>
            <td><a href="rdv_form.php?id=<?= $r['id'] ?>"><?= e($r['numero']) ?></a></td>
            <td><?= e($r['quai']) ?></td>
            <td><?= date_fr($r['debut']) ?> <?= heure($r['debut']) ?>–<?= heure($r['fin']) ?></td>
            <td><?= e($r['raison_sociale']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<div class="carte">
    <form method="post" class="formulaire">
        <?= champ_csrf() ?>
        <label><span class="requis">Type</span>
            <select name="type"><?= options($types, $v['type']) ?></select>
        </label>
        <label><span class="requis">Libellé</span><input name="libelle" value="<?= e($v['libelle']) ?>" required maxlength="100"></label>
        <label><span>Quai concerné</span>
            <select name="quai_id"><?= options($quais, $v['quai_id'], 'Tout le site') ?></select>
        </label>
        <label><span class="requis">Début</span><input type="datetime-local" name="debut" value="<?= e($v['debut']) ?>" step="900" required></label>
        <label><span class="requis">Fin</span><input type="datetime-local" name="fin" value="<?= e($v['fin']) ?>" step="900" required></label>
        <label class="case pleine"><input type="checkbox" name="bloquant" value="1" <?= $v['bloquant'] ? 'checked' : '' ?>>
            &nbsp;Bloque la prise de rendez-vous sur ce créneau (sinon simple information au planning)</label>
        <label class="pleine"><span>Commentaire</span><textarea name="commentaire" rows="2" maxlength="255"><?= e($v['commentaire']) ?></textarea></label>
        <?php if ($auteur): ?><p class="discret pleine">Inscrit au planning par <?= e($auteur) ?>.</p><?php endif; ?>
        <div class="boutons pleine">
            <button type="submit">Enregistrer</button>
            <?php if ($evenement): ?>
                <button type="submit" name="action" value="supprimer" class="danger" formnovalidate onclick="return confirm('Supprimer cet évènement du planning ?')">Supprimer</button>
            <?php endif; ?>
            <a class="bouton discret" href="planning.php?date=<?= e($evenement ? substr($evenement['debut'], 0, 10) : $jour) ?>">Annuler</a>
        </div>
    </form>
</div>
<?php pied();
