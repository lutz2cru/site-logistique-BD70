<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_profil('responsable', 'accueil');
$gestion = a_profil('responsable');
$suiviStatut = a_profil('accueil');   // le responsable gère les quais, l'accueil suit leur statut

if (est_post()) {
    verifier_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if (isset($_POST['statut']) && $suiviStatut) {
            // suivi / modification du statut (libre, hors service)
            $statut = $_POST['statut'] === 'hors_service' ? 'hors_service' : 'libre';
            if ($statut === 'libre' && valeur("SELECT COUNT(*) FROM passage p JOIN type_etape te ON te.id = p.etape_courante_id
                                                WHERE p.quai_id = ? AND te.code IN ('APPEL_QUAI','DEBUT_OP','FIN_OP')", [$id])) {
                $statut = 'occupe';   // un camion est encore à quai
            }
            requete('UPDATE quai SET statut = ? WHERE id = ?', [$statut, $id]);
            flash('succes', 'Statut du quai mis à jour.');
        } elseif (isset($_POST['supprimer']) && $gestion) {
            requete('DELETE FROM quai WHERE id = ?', [$id]);
            flash('succes', 'Quai supprimé.');
        }
    } catch (PDOException $e) {
        flash('erreur', message_erreur($e));
    }
    rediriger('quais.php');
}

$quais = lignes("SELECT v.*, q.statut AS statut_stocke,
                        (SELECT GROUP_CONCAT(e.libelle ORDER BY e.libelle SEPARATOR ', ') FROM quai_equipement qe
                           JOIN equipement e ON e.id = qe.equipement_id WHERE qe.quai_id = v.id) AS equipements,
                        (SELECT GROUP_CONCAT(t.libelle ORDER BY t.id SEPARATOR ', ') FROM quai_type_vehicule qt
                           JOIN type_vehicule t ON t.id = qt.type_vehicule_id WHERE qt.quai_id = v.id) AS vehicules,
                        (SELECT COUNT(*) FROM rendez_vous r WHERE r.quai_id = v.id AND DATE(r.debut) = CURRENT_DATE AND r.statut <> 'annule') AS rdv_jour
                   FROM v_quai_temps_reel v JOIN quai q ON q.id = v.id ORDER BY v.numero");

entete('Quais');
?>
<div class="entete-page">
    <span class="discret">Le statut est mis à jour automatiquement à chaque appel à quai et départ de camion.</span>
    <?php if ($gestion): ?><a class="bouton" href="quai_form.php">+ Nouveau quai</a><?php endif; ?>
</div>
<div class="carte tableau">
    <table>
        <tr><th>Quai</th><th>Type</th><th>Statut</th><th>À quai</th><th>Équipements</th><th>Véhicules acceptés</th><th class="nombre">RDV du jour</th><th></th></tr>
        <?php foreach ($quais as $q): ?>
        <tr>
            <td><strong><?= e($q['numero']) ?></strong></td>
            <td><?= pastille($q['type']) ?></td>
            <td><?= pastille($q['statut']) ?></td>
            <td><?= e($q['vehicule_a_quai']) ?></td>
            <td><small><?= e($q['equipements']) ?></small></td>
            <td><small><?= e($q['vehicules']) ?></small></td>
            <td class="nombre"><a href="planning.php"><?= $q['rdv_jour'] ?></a></td>
            <td class="actions-ligne">
                <form method="post">
                    <?= champ_csrf() ?><input type="hidden" name="id" value="<?= $q['id'] ?>">
                    <?php if (!$suiviStatut): ?>
                    <?php elseif ($q['statut_stocke'] === 'hors_service'): ?>
                        <button class="petit secondaire" name="statut" value="libre">Remettre en service</button>
                    <?php else: ?>
                        <button class="petit discret" name="statut" value="hors_service" onclick="return confirm('Passer ce quai hors service ?')">Hors service</button>
                    <?php endif; ?>
                    <?php if ($gestion): ?>
                        <a class="bouton petit secondaire" href="quai_form.php?id=<?= $q['id'] ?>">Modifier</a>
                        <button class="petit danger" name="supprimer" value="1" onclick="return confirm('Supprimer ce quai ?')">Supprimer</button>
                    <?php endif; ?>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php pied();
