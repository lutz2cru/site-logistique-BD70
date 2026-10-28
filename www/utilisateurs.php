<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$moi = exiger_profil();   // administrateur uniquement

$profils = paires('SELECT id, libelle FROM profil ORDER BY id');
$transporteurs = paires('SELECT id, raison_sociale FROM transporteur ORDER BY raison_sociale');

if (est_post()) {
    verifier_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($id === $moi['id'] && (isset($_POST['actif']) || isset($_POST['supprimer']) || isset($_POST['profil_id']))) {
            throw new RuntimeException('Vous ne pouvez pas modifier ou supprimer votre propre compte administrateur.');
        }
        if (isset($_POST['enregistrer'])) {
            if (!isset($profils[$_POST['profil_id'] ?? ''])) { throw new RuntimeException('Profil invalide.'); }
            $transp = $_POST['transporteur_id'] ?? '';
            $estTransp = valeur("SELECT code FROM profil WHERE id = ?", [$_POST['profil_id']]) === 'transporteur';
            if ($estTransp && !isset($transporteurs[$transp])) { throw new RuntimeException('Un compte transporteur doit être rattaché à une société.'); }
            requete('UPDATE utilisateur SET profil_id = ?, transporteur_id = ?, actif = ? WHERE id = ?',
                    [$_POST['profil_id'], $estTransp ? $transp : null, isset($_POST['actif']) ? 1 : 0, $id]);
            flash('succes', 'Compte mis à jour.');
        } elseif (isset($_POST['reinitialiser'])) {
            $mdp = $_POST['mot_de_passe'] ?? '';
            if (strlen($mdp) < 8) { throw new RuntimeException('Le nouveau mot de passe doit contenir au moins 8 caractères.'); }
            requete('UPDATE utilisateur SET mot_de_passe_hash = ? WHERE id = ?', [password_hash($mdp, PASSWORD_DEFAULT), $id]);
            flash('succes', 'Mot de passe réinitialisé.');
        } elseif (isset($_POST['supprimer'])) {
            requete('DELETE FROM utilisateur WHERE id = ?', [$id]);
            flash('succes', 'Compte supprimé.');
        }
    } catch (RuntimeException $e) {
        flash('erreur', $e->getMessage());
    } catch (PDOException $e) {
        flash('erreur', ($e->errorInfo[1] ?? 0) === 1451
            ? 'Ce compte a un historique (réceptions, mouvements…) : désactivez-le plutôt que de le supprimer.'
            : message_erreur($e));
    }
    rediriger('utilisateurs.php?' . http_build_query($_GET));
}

$f = ['q' => filtre('q'), 'profil' => filtre('profil'), 'actif' => filtre('actif')];
$where = ['1 = 1'];
$params = [];
if ($f['q'] !== '') { $where[] = '(u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ?)'; array_push($params, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }
if ($f['profil'] !== '') { $where[] = 'u.profil_id = ?'; $params[] = $f['profil']; }
if ($f['actif'] !== '') { $where[] = 'u.actif = ?'; $params[] = $f['actif']; }
$utilisateurs = lignes('SELECT u.*, p.code AS profil FROM utilisateur u JOIN profil p ON p.id = u.profil_id
                         WHERE ' . implode(' AND ', $where) . ' ORDER BY u.actif, u.nom, u.prenom', $params);
$enAttente = (int) valeur('SELECT COUNT(*) FROM utilisateur WHERE actif = FALSE');

entete('Utilisateurs');
?>
<?php if ($enAttente): ?><div class="alerte info"><?= $enAttente ?> compte(s) en attente de validation (affichés en premier).</div><?php endif; ?>
<div class="carte">
    <form class="filtres" method="get">
        <label>Recherche<input name="q" value="<?= e($f['q']) ?>" placeholder="nom, e-mail"></label>
        <label>Profil <select name="profil"><?= options($profils, $f['profil'], 'Tous') ?></select></label>
        <label>État <select name="actif"><?= options(['1' => 'Actifs', '0' => 'En attente / désactivés'], $f['actif'], 'Tous') ?></select></label>
        <button type="submit">Filtrer</button>
        <a class="bouton discret" href="utilisateurs.php">Réinitialiser</a>
    </form>
    <div class="tableau"><table>
        <tr><th>Nom</th><th>E-mail</th><th>Créé le</th><th>Profil</th><th>Société (transporteur)</th><th>Actif</th><th></th></tr>
        <?php foreach ($utilisateurs as $x): $form = "f{$x['id']}"; ?>
        <tr>
            <td><strong><?= e($x['prenom'] . ' ' . $x['nom']) ?></strong><?= $x['id'] === $moi['id'] ? ' <small>(vous)</small>' : '' ?></td>
            <td><?= e($x['email']) ?></td>
            <td><?= date_fr($x['date_creation']) ?></td>
            <td><select name="profil_id" form="<?= $form ?>"><?= options($profils, $x['profil_id']) ?></select></td>
            <td><select name="transporteur_id" form="<?= $form ?>"><?= options($transporteurs, $x['transporteur_id'], '—') ?></select></td>
            <td><input type="checkbox" name="actif" value="1" form="<?= $form ?>" <?= $x['actif'] ? 'checked' : '' ?>></td>
            <td class="actions-ligne">
                <form method="post" id="<?= $form ?>">
                    <?= champ_csrf() ?><input type="hidden" name="id" value="<?= $x['id'] ?>">
                    <button class="petit <?= $x['actif'] ? 'secondaire' : '' ?>" name="enregistrer" value="1"><?= $x['actif'] ? 'Enregistrer' : 'Valider' ?></button>
                    <button class="petit danger" name="supprimer" value="1" onclick="return confirm('Supprimer ce compte ?')">Supprimer</button>
                </form>
                <details style="display:inline-block">
                    <summary class="bouton petit discret">Mot de passe</summary>
                    <form method="post" class="actions" style="margin-top:4px">
                        <?= champ_csrf() ?><input type="hidden" name="id" value="<?= $x['id'] ?>">
                        <input type="password" name="mot_de_passe" minlength="8" placeholder="nouveau mot de passe" autocomplete="new-password">
                        <button class="petit" name="reinitialiser" value="1">OK</button>
                    </form>
                </details>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php pied();
