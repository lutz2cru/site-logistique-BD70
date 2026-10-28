<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable', 'accueil', 'magasinier');

$id = (int) ($_GET['id'] ?? 0);
$p = ligne("SELECT p.*, v.immatriculation, tv.libelle AS type_vehicule, t.raison_sociale, CONCAT(c.prenom, ' ', c.nom) AS chauffeur,
                   c.telephone AS tel_chauffeur, q.numero AS quai, r.numero AS rdv_numero, r.debut AS rdv_debut, r.fin AS rdv_fin,
                   rq.numero AS rdv_quai, d.attente_min, d.immobilisation_min, d.occupation_quai_min, d.retard_min
              FROM passage p
              JOIN vehicule v ON v.id = p.vehicule_id JOIN type_vehicule tv ON tv.id = v.type_vehicule_id
              JOIN transporteur t ON t.id = v.transporteur_id JOIN chauffeur c ON c.id = p.chauffeur_id
              LEFT JOIN quai q ON q.id = p.quai_id
              LEFT JOIN rendez_vous r ON r.id = p.rendez_vous_id LEFT JOIN quai rq ON rq.id = r.quai_id
              JOIN v_passage_durees d ON d.id = p.id
             WHERE p.id = ?", [$id]);
if (!$p) { rediriger('passages.php'); }

$etapes = lignes('SELECT te.code, te.libelle, pe.date_heure, pe.commentaire
                    FROM type_etape te LEFT JOIN passage_etape pe ON pe.type_etape_id = te.id AND pe.passage_id = ?
                   ORDER BY te.ordre', [$id]);
$receptions = lignes('SELECT id, numero, statut FROM reception WHERE passage_id = ?', [$id]);
$expeditions = lignes('SELECT e.id, e.numero, e.statut, COUNT(u.id) AS unites, COUNT(DISTINCT u.commande_id) AS commandes
                         FROM expedition e LEFT JOIN unite_expedition u ON u.expedition_id = e.id WHERE e.passage_id = ? GROUP BY e.id', [$id]);

entete("Passage {$p['numero']}", 'passages.php');
?>
<div class="kpis">
    <div class="kpi"><div class="valeur"><?= duree($p['attente_min'] !== null ? (int) $p['attente_min'] : null) ?></div><div class="libelle">attente avant appel à quai</div></div>
    <div class="kpi"><div class="valeur"><?= duree($p['occupation_quai_min'] !== null ? (int) $p['occupation_quai_min'] : null) ?></div><div class="libelle">occupation du quai</div></div>
    <div class="kpi"><div class="valeur"><?= duree($p['immobilisation_min'] !== null ? (int) $p['immobilisation_min'] : null) ?></div><div class="libelle">immobilisation totale</div></div>
    <div class="kpi"><div class="valeur <?= $p['retard_min'] > 15 ? 'retard' : '' ?>"><?= $p['rdv_numero'] ? duree((int) $p['retard_min']) : 'Sans RDV' ?></div><div class="libelle">retard sur rendez-vous</div></div>
</div>
<div class="grille">
    <div class="carte">
        <h2>Informations</h2>
        <table>
            <tr><th>Véhicule</th><td><?= e($p['immatriculation']) ?> (<?= e($p['type_vehicule']) ?>)</td></tr>
            <tr><th>Transporteur</th><td><?= e($p['raison_sociale']) ?></td></tr>
            <tr><th>Chauffeur</th><td><?= e($p['chauffeur']) ?> · <?= e($p['tel_chauffeur']) ?></td></tr>
            <tr><th>Sens</th><td><?= pastille($p['sens']) ?></td></tr>
            <tr><th>Rendez-vous</th><td><?= $p['rdv_numero'] ? e($p['rdv_numero']) . ' · ' . date_heure_fr($p['rdv_debut']) . ' – ' . heure($p['rdv_fin']) . ' · quai ' . e($p['rdv_quai']) : 'Arrivée sans rendez-vous' ?></td></tr>
            <tr><th>Quai affecté</th><td><?= e($p['quai'] ?? '–') ?></td></tr>
            <tr><th>Observations</th><td><?= e($p['observations']) ?></td></tr>
            <?php foreach ($receptions as $r): ?>
                <tr><th>Réception</th><td><a href="reception.php?id=<?= $r['id'] ?>"><?= e($r['numero']) ?></a> <?= pastille($r['statut']) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($expeditions as $x): ?>
                <tr><th>Expédition</th><td><?= e($x['numero']) ?> · <?= $x['commandes'] ?> commande(s), <?= $x['unites'] ?> unité(s) chargée(s)</td></tr>
            <?php endforeach; ?>
        </table>
    </div>
    <div class="carte">
        <h2>Chronologie</h2>
        <ul class="chronologie">
        <?php $precedente = null;
        foreach ($etapes as $et): ?>
            <li class="<?= $et['date_heure'] ? '' : 'a-venir' ?>">
                <strong><?= e($et['libelle']) ?></strong>
                <?php if ($et['date_heure']): ?>
                    · <?= date_heure_fr($et['date_heure']) ?>
                    <?php if ($precedente): ?><small>(+<?= duree((int) ((strtotime($et['date_heure']) - strtotime($precedente)) / 60)) ?>)</small><?php endif; ?>
                    <?php $precedente = $et['date_heure']; ?>
                    <?php if ($et['commentaire']): ?><br><small><?= e($et['commentaire']) ?></small><?php endif; ?>
                <?php elseif (in_array($et['code'], ['PARKING'], true)): ?><small>— non nécessaire / non franchie</small>
                <?php else: ?><small>— à venir</small><?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
</div>
<p><a href="javascript:history.back()">← Retour</a></p>
<?php pied();
