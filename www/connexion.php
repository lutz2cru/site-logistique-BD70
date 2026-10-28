<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (utilisateur()) { rediriger('index.php'); }

$email = '';
$erreur = null;
if (est_post()) {
    verifier_csrf();
    $email = trim($_POST['email'] ?? '');
    $u = ligne('SELECT u.*, p.code AS profil, p.libelle AS profil_libelle
                  FROM utilisateur u JOIN profil p ON p.id = u.profil_id WHERE u.email = ?', [$email]);

    if (!$u || !password_verify($_POST['mot_de_passe'] ?? '', $u['mot_de_passe_hash'])) {
        usleep(500000);   // ralentit les tentatives par force brute
        $erreur = 'Adresse e-mail ou mot de passe incorrect.';
    } elseif (!$u['actif']) {
        $erreur = 'Votre compte n\'a pas encore été validé par un administrateur.';
    } else {
        session_regenerate_id(true);
        $_SESSION['utilisateur'] = [
            'id' => (int) $u['id'], 'nom' => $u['nom'], 'prenom' => $u['prenom'], 'email' => $u['email'],
            'profil' => $u['profil'], 'profil_libelle' => $u['profil_libelle'],
            'transporteur_id' => $u['transporteur_id'] !== null ? (int) $u['transporteur_id'] : null,
        ];
        flash('succes', 'Bienvenue ' . $u['prenom'] . ' !');
        rediriger('index.php');
    }
}

entete('Connexion');
?>
<div class="ecran-connexion">
    <div class="carte">
        <h1>▣ Entrepôt logistique</h1>
        <p class="discret">Connectez-vous pour accéder à votre espace.</p>
        <?php messages_flash(); ?>
        <?php if ($erreur): ?><div class="alerte erreur"><?= e($erreur) ?></div><?php endif; ?>
        <form method="post" class="formulaire">
            <?= champ_csrf() ?>
            <label>Adresse e-mail
                <input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username">
            </label>
            <label>Mot de passe
                <input type="password" name="mot_de_passe" required autocomplete="current-password">
            </label>
            <button type="submit">Se connecter</button>
        </form>
        <p>Pas encore de compte ? <a href="inscription.php">Demander un accès</a></p>
    </div>
</div>
<?php pied();
