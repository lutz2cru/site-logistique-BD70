<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable', 'accueil');

$f = [
    'du' => filtre('du') ?: date('Y-m-d', strtotime('-7 days')), 'au' => filtre('au') ?: date('Y-m-d'),
    'transporteur' => filtre('transporteur'), 'sens' => filtre('sens'), 'rdv' => filtre('rdv'),
    'retard' => filtre('retard'), 'q' => filtre('q'),
];
$where = ['d.arrivee >= ?', 'd.arrivee < ? + INTERVAL 1 DAY'];
$params = [$f['du'], $f['au']];
if ($f['transporteur'] !== '') { $where[] = 'd.transporteur_id = ?'; $params[] = $f['transporteur']; }
if ($f['sens'] !== '') { $where[] = 'd.sens = ?'; $params[] = $f['sens']; }
if ($f['rdv'] === 'avec') { $where[] = 'd.rendez_vous_id IS NOT NULL'; }
if ($f['rdv'] === 'sans') { $where[] = 'd.rendez_vous_id IS NULL'; }
if ($f['retard'] === '1') { $where[] = 'd.retard_min > 15'; }
if ($f['q'] !== '') { $where[] = '(v.immatriculation LIKE ? OR d.numero LIKE ?)'; array_push($params, "%{$f['q']}%", "%{$f['q']}%"); }

$from = 'FROM v_passage_durees d JOIN passage p ON p.id = d.id JOIN vehicule v ON v.id = p.vehicule_id
         JOIN transporteur t ON t.id = d.transporteur_id JOIN type_etape te ON te.id = p.etape_courante_id
         LEFT JOIN quai q ON q.id = p.quai_id WHERE ' . implode(' AND ', $where);
$stats = ligne("SELECT COUNT(*) AS n, ROUND(AVG(d.attente_min)) AS attente, ROUND(AVG(d.immobilisation_min)) AS immo,
                       SUM(d.retard_min > 15) AS retards, SUM(d.rendez_vous_id IS NULL) AS sans_rdv $from", $params);
[$limite, $decalage, $page, $pages] = pagination((int) $stats['n']);
$passages = lignes("SELECT d.*, v.immatriculation, t.raison_sociale, te.libelle AS etape, q.numero AS quai
                    $from ORDER BY d.arrivee DESC LIMIT $limite OFFSET $decalage", $params);

$transporteurs = paires('SELECT id, raison_sociale FROM transporteur ORDER BY raison_sociale');
entete('Historique des passages');
?>
<div class="kpis">
    <div class="kpi"><div class="valeur"><?= nombre($stats['n']) ?></div><div class="libelle">passages sur la période</div></div>
    <div class="kpi"><div class="valeur"><?= duree($stats['attente'] !== null ? (int) $stats['attente'] : null) ?></div><div class="libelle">attente moyenne</div></div>
    <div class="kpi"><div class="valeur"><?= duree($stats['immo'] !== null ? (int) $stats['immo'] : null) ?></div><div class="libelle">immobilisation moyenne</div></div>
    <div class="kpi"><div class="valeur"><?= nombre($stats['retards']) ?></div><div class="libelle">arrivées en retard (&gt; 15 min)</div></div>
    <div class="kpi"><div class="valeur"><?= nombre($stats['sans_rdv']) ?></div><div class="libelle">arrivées sans rendez-vous</div></div>
</div>
<div class="carte">
    <form class="filtres" method="get">
        <label>Arrivée du <input type="date" name="du" value="<?= e($f['du']) ?>"></label>
        <label>au <input type="date" name="au" value="<?= e($f['au']) ?>"></label>
        <label>Transporteur <select name="transporteur"><?= options($transporteurs, $f['transporteur'], 'Tous') ?></select></label>
        <label>Sens <select name="sens"><?= options(['reception' => 'Réception', 'expedition' => 'Expédition'], $f['sens'], 'Tous') ?></select></label>
        <label>Rendez-vous <select name="rdv"><?= options(['avec' => 'Avec RDV', 'sans' => 'Sans RDV'], $f['rdv'], 'Tous') ?></select></label>
        <label>Retard <select name="retard"><?= options(['1' => 'En retard uniquement'], $f['retard'], 'Tous') ?></select></label>
        <label>Immatriculation / n° <input name="q" value="<?= e($f['q']) ?>"></label>
        <button type="submit">Filtrer</button>
        <a class="bouton discret" href="passages.php">Réinitialiser</a>
    </form>
    <?php if (!$passages): ?><p class="vide">Aucun passage sur cette période.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Numéro</th><th>Arrivée</th><th>Véhicule</th><th>Transporteur</th><th>Sens</th><th>Quai</th><th>Étape</th>
            <th class="nombre">Attente</th><th class="nombre">Immobilisation</th><th class="nombre">Retard</th></tr>
        <?php foreach ($passages as $p): ?>
        <tr>
            <td><a href="passage.php?id=<?= $p['id'] ?>"><?= e($p['numero']) ?></a></td>
            <td><?= date_heure_fr($p['arrivee']) ?></td>
            <td><?= e($p['immatriculation']) ?></td>
            <td><?= e($p['raison_sociale']) ?></td>
            <td><?= pastille($p['sens']) ?></td>
            <td><?= e($p['quai']) ?></td>
            <td><?= e($p['etape']) ?></td>
            <td class="nombre"><?= duree($p['attente_min'] !== null ? (int) $p['attente_min'] : null) ?></td>
            <td class="nombre"><?= duree($p['immobilisation_min'] !== null ? (int) $p['immobilisation_min'] : null) ?></td>
            <td class="nombre <?= $p['retard_min'] > 15 ? 'retard' : '' ?>"><?= $p['rendez_vous_id'] ? duree((int) $p['retard_min']) : 'sans RDV' ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?= liens_pagination($page, $pages) ?>
    <?php endif; ?>
</div>
<?php pied();
