<?php
/**
 * Borne d'accueil en libre-service, installée au poste de garde.
 * Le chauffeur déclare lui-même son arrivée sur rendez-vous ; l'agent d'accueil
 * n'a plus qu'à contrôler ses documents. Aucune connexion n'est demandée :
 * le chauffeur prouve son identité par le trio rendez-vous / immatriculation / permis.
 */
require __DIR__ . '/includes/bootstrap.php';

/** Immatriculation sans espaces ni tirets, en majuscules, pour la comparaison. */
function immat_normalisee(string $i): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $i)); }

$v = ['rdv' => '', 'immatriculation' => '', 'permis' => ''];
$erreur = null;
$confirmation = null;

if (est_post()) {
    verifier_csrf();
    foreach ($v as $k => $_) { $v[$k] = trim((string) ($_POST[$k] ?? '')); }
    $numero = strtoupper($v['rdv']);
    if (preg_match('/^\d+$/', $numero)) { $numero = 'RDV-' . str_pad($numero, 6, '0', STR_PAD_LEFT); }

    $rdv = ligne("SELECT r.*, v.immatriculation, v.transporteur_id, q.numero AS quai
                    FROM rendez_vous r JOIN vehicule v ON v.id = r.vehicule_id JOIN quai q ON q.id = r.quai_id
                   WHERE r.numero = ? AND r.statut = 'planifie' AND DATE(r.debut) = CURRENT_DATE
                     AND NOT EXISTS (SELECT 1 FROM passage p WHERE p.rendez_vous_id = r.id)", [$numero]);
    $chauffeur = ligne('SELECT * FROM chauffeur WHERE numero_permis = ?', [strtoupper($v['permis'])]);

    // message volontairement générique : la borne ne révèle pas quelle donnée est fausse
    if (!$rdv || immat_normalisee($rdv['immatriculation']) !== immat_normalisee($v['immatriculation'])
        || !$chauffeur || $chauffeur['transporteur_id'] !== $rdv['transporteur_id']) {
        usleep(500000);
        $erreur = 'Rendez-vous introuvable pour aujourd\'hui, ou informations incorrectes. Présentez-vous à l\'agent d\'accueil.';
    } elseif ($chauffeur['validite_permis'] < date('Y-m-d')) {
        $erreur = 'Votre permis de conduire est expiré : présentez-vous à l\'agent d\'accueil.';
    } elseif (strtotime($rdv['debut']) - time() > 2 * 3600) {
        $erreur = 'Votre rendez-vous est à ' . heure($rdv['debut']) . ' : la borne enregistre les arrivées au plus tôt 2 h avant le créneau.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            requete('INSERT INTO passage (rendez_vous_id, vehicule_id, chauffeur_id, sens, borne) VALUES (?, ?, ?, ?, TRUE)',
                    [$rdv['id'], $rdv['vehicule_id'], $chauffeur['id'], $rdv['sens']]);
            $pid = (int) $pdo->lastInsertId();
            requete("INSERT INTO passage_etape (passage_id, type_etape_id, date_heure, commentaire)
                     VALUES (?, (SELECT id FROM type_etape WHERE code = 'ARRIVEE'), NOW(), 'Déclarée à la borne')", [$pid]);
            $pdo->commit();
            $retard = (int) ((time() - strtotime($rdv['debut'])) / 60);
            $confirmation = ['passage' => valeur('SELECT numero FROM passage WHERE id = ?', [$pid]), 'quai' => $rdv['quai'],
                             'creneau' => heure($rdv['debut']), 'retard' => $retard > 15 ? $retard : 0,
                             'chauffeur' => $chauffeur['prenom'] . ' ' . $chauffeur['nom']];
            $v = array_map(fn() => '', $v);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $erreur = message_erreur($e);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Borne d'accueil · Entrepôt</title>
    <link rel="stylesheet" href="assets/style.css">
    <?php if ($confirmation): ?><meta http-equiv="refresh" content="30;url=borne.php"><?php endif; ?>
</head>
<body>
<div class="ecran-connexion borne">
    <div class="carte">
        <h1>▣ Bienvenue à l'entrepôt</h1>
        <?php if ($confirmation): ?>
            <div class="alerte succes">Arrivée enregistrée, <?= e($confirmation['chauffeur']) ?>.</div>
            <p class="gros">Passage <strong><?= e($confirmation['passage']) ?></strong> · rendez-vous de <?= e($confirmation['creneau']) ?> · quai prévu <strong><?= e($confirmation['quai']) ?></strong></p>
            <?php if ($confirmation['retard']): ?><p class="retard">Vous avez <?= duree($confirmation['retard']) ?> de retard : le quai pourra être réattribué.</p><?php endif; ?>
            <ol>
                <li>Présentez vos documents de transport au poste de garde.</li>
                <li>Garez-vous sur le parking et surveillez l'écran d'appel.</li>
                <li>Rendez-vous au quai affiché lorsque votre immatriculation apparaît.</li>
            </ol>
            <a class="bouton" href="borne.php">Terminer</a>
        <?php else: ?>
            <p class="discret">Déclarez votre arrivée sur rendez-vous. Sans rendez-vous, adressez-vous à l'agent d'accueil.</p>
            <?php if ($erreur): ?><div class="alerte erreur"><?= e($erreur) ?></div><?php endif; ?>
            <form method="post" class="formulaire" autocomplete="off">
                <?= champ_csrf() ?>
                <label>N° de rendez-vous<input name="rdv" value="<?= e($v['rdv']) ?>" required placeholder="RDV-001234" maxlength="12"></label>
                <label>Immatriculation du véhicule<input name="immatriculation" value="<?= e($v['immatriculation']) ?>" required placeholder="AB-123-CD" maxlength="12"></label>
                <label>N° de permis de conduire<input name="permis" value="<?= e($v['permis']) ?>" required maxlength="20"></label>
                <button type="submit">Déclarer mon arrivée</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
