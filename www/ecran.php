<?php
/**
 * Écran d'appel affiché sur le parking poids lourds : les chauffeurs voient
 * quand leur camion est appelé et à quel quai. Rafraîchi automatiquement.
 */
require __DIR__ . '/includes/bootstrap.php';

$appeles = lignes("SELECT v.immatriculation, q.numero AS quai, fn_date_etape(p.id, 'APPEL_QUAI') AS appel
                     FROM passage p JOIN type_etape te ON te.id = p.etape_courante_id
                     JOIN vehicule v ON v.id = p.vehicule_id LEFT JOIN quai q ON q.id = p.quai_id
                    WHERE te.code = 'APPEL_QUAI' ORDER BY appel DESC");
$attente = lignes("SELECT v.immatriculation, te.code AS etape, fn_date_etape(p.id, 'ARRIVEE') AS arrivee
                     FROM passage p JOIN type_etape te ON te.id = p.etape_courante_id JOIN vehicule v ON v.id = p.vehicule_id
                    WHERE te.code IN ('ARRIVEE', 'CONTROLE', 'PARKING') ORDER BY arrivee");
$libelles = ['ARRIVEE' => 'Documents à présenter au poste de garde', 'CONTROLE' => 'Documents contrôlés', 'PARKING' => 'En attente d\'un quai'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="20">
    <title>Écran d'appel · Entrepôt</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="ecran-appel">
    <header><h1>▣ Appel à quai</h1><span><?= date('H:i') ?></span></header>
    <section>
        <h2>Présentez-vous au quai</h2>
        <?php if (!$appeles): ?><p class="vide">Aucun camion appelé pour le moment.</p><?php else: ?>
        <table>
            <?php foreach ($appeles as $a): ?>
            <tr><td class="immat"><?= e($a['immatriculation']) ?></td><td>→</td><td class="quai">Quai <?= e($a['quai']) ?></td><td class="discret">appelé à <?= heure($a['appel']) ?></td></tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </section>
    <section>
        <h2>En attente</h2>
        <?php if (!$attente): ?><p class="vide">Aucun camion en attente.</p><?php else: ?>
        <table>
            <?php foreach ($attente as $a): ?>
            <tr><td class="immat"><?= e($a['immatriculation']) ?></td><td><?= e($libelles[$a['etape']]) ?></td><td class="discret">arrivé à <?= heure($a['arrivee']) ?></td></tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </section>
</body>
</html>
