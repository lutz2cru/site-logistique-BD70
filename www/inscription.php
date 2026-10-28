<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (utilisateur()) { rediriger('index.php'); }

// l'administrateur ne peut pas être demandé à l'inscription
$profils = paires("SELECT code, libelle FROM profil WHERE code <> 'admin' ORDER BY id");
$transporteurs = paires('SELECT id, raison_sociale FROM transporteur ORDER BY raison_sociale');

$v = ['nom' => '', 'prenom' => '', 'email' => '', 'telephone' => '', 'profil' => 'magasinier', 'transporteur_id' => ''];
$erreurs = [];
if (est_post()) {
    verifier_csrf();
    foreach ($v as $k => $_) { $v[$k] = trim($_POST[$k] ?? ''); }
    $mdp = $_POST['mot_de_passe'] ?? '';

    if ($v['nom'] === '' || mb_strlen($v['nom']) > 60) { $erreurs[] = 'Le nom est obligatoire (60 caractères max).'; }
    if ($v['prenom'] === '' || mb_strlen($v['prenom']) > 60) { $erreurs[] = 'Le prénom est obligatoire (60 caractères max).'; }
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) { $erreurs[] = 'L\'adresse e-mail n\'est pas valide.'; }
    if ($v['telephone'] !== '' && !preg_match('/^[0-9 +.]{10,20}$/', $v['telephone'])) { $erreurs[] = 'Le numéro de téléphone n\'est pas valide.'; }
    if (!isset($profils[$v['profil']])) { $erreurs[] = 'Profil inconnu.'; }
    if ($v['profil'] === 'transporteur' && !isset($transporteurs[$v['transporteur_id']])) { $erreurs[] = 'Choisissez votre société de transport.'; }
    if (strlen($mdp) < 8 || !preg_match('/\d/', $mdp) || !preg_match('/[a-z]/i', $mdp)) {
        $erreurs[] = 'Le mot de passe doit contenir au moins 8 caractères dont une lettre et un chiffre.';
    }
    if ($mdp !== ($_POST['confirmation'] ?? '')) { $erreurs[] = 'Les deux mots de passe ne correspondent pas.'; }
    if (!$erreurs && valeur('SELECT COUNT(*) FROM utilisateur WHERE email = ?', [$v['email']])) {
        $erreurs[] = 'Un compte existe déjà avec cette adresse e-mail.';
    }

    if (!$erreurs) {
        requete('INSERT INTO utilisateur (email, nom, prenom, telephone, mot_de_passe_hash, actif, profil_id, transporteur_id)
                 VALUES (?, ?, ?, ?, ?, FALSE, (SELECT id FROM profil WHERE code = ?), ?)', [
            $v['email'], $v['nom'], $v['prenom'], $v['telephone'] ?: null, password_hash($mdp, PASSWORD_DEFAULT),
            $v['profil'], $v['profil'] === 'transporteur' ? $v['transporteur_id'] : null,
        ]);
        flash('succes', 'Votre demande a été enregistrée. Un administrateur doit valider votre compte avant la première connexion.');
        rediriger('connexion.php');
    }
}

entete('Inscription');
?>
<div class="ecran-connexion">
    <div class="carte" style="max-width:520px">
        <h1>Demande d'accès</h1>
        <p class="discret">Votre compte sera activé par un administrateur.</p>
        <?php foreach ($erreurs as $err): ?><div class="alerte erreur"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post" class="formulaire" style="grid-template-columns:1fr 1fr">
            <?= champ_csrf() ?>
            <label><span class="requis">Prénom</span><input name="prenom" value="<?= e($v['prenom']) ?>" required maxlength="60"></label>
            <label><span class="requis">Nom</span><input name="nom" value="<?= e($v['nom']) ?>" required maxlength="60"></label>
            <label class="pleine"><span class="requis">Adresse e-mail</span><input type="email" name="email" value="<?= e($v['email']) ?>" required maxlength="120"></label>
            <label>Téléphone<input name="telephone" value="<?= e($v['telephone']) ?>" maxlength="20" pattern="[0-9 +.]{10,20}"></label>
            <label><span class="requis">Profil souhaité</span>
                <select name="profil" id="profil"><?= options($profils, $v['profil']) ?></select>
            </label>
            <label class="pleine" id="bloc-transporteur"><span class="requis">Société de transport</span>
                <select name="transporteur_id"><?= options($transporteurs, $v['transporteur_id'], '— Choisir —') ?></select>
            </label>
            <label><span class="requis">Mot de passe</span><input type="password" name="mot_de_passe" required minlength="8" autocomplete="new-password"></label>
            <label><span class="requis">Confirmation</span><input type="password" name="confirmation" required minlength="8" autocomplete="new-password"></label>
            <button type="submit" class="pleine">Envoyer la demande</button>
        </form>
        <p><a href="connexion.php">← Retour à la connexion</a></p>
    </div>
</div>
<script>
    // n'affiche le choix de la société que pour le profil « transporteur »
    const profil = document.getElementById('profil'), bloc = document.getElementById('bloc-transporteur');
    const maj = () => bloc.style.display = profil.value === 'transporteur' ? '' : 'none';
    profil.addEventListener('change', maj); maj();
</script>
<?php pied();
