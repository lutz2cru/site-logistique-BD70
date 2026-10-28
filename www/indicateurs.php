<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('responsable');

$du = filtre('du') ?: date('Y-m-d', strtotime('-30 days'));
$au = filtre('au') ?: date('Y-m-d');
$periode = [$du, $au];
const AMPLITUDE_MIN = 15 * 60;   // ouverture des quais : 6 h – 21 h

// ---------------------------------------------------------------------
//  Indicateurs de synthèse
// ---------------------------------------------------------------------
$flux = ligne("SELECT COUNT(*) AS passages, ROUND(AVG(attente_min)) AS attente, ROUND(AVG(immobilisation_min)) AS immo,
                      ROUND(100 * SUM(retard_min > 15) / NULLIF(SUM(rendez_vous_id IS NOT NULL), 0), 1) AS taux_retard,
                      SUM(occupation_quai_min) AS occupation, COUNT(DISTINCT DATE(arrivee)) AS jours
                 FROM v_passage_durees WHERE depart IS NOT NULL AND arrivee >= ? AND arrivee < ? + INTERVAL 1 DAY", $periode);
$nbQuais = (int) valeur("SELECT COUNT(*) FROM quai WHERE statut <> 'hors_service'");
$tauxQuais = $flux['jours'] ? round(100 * $flux['occupation'] / ($nbQuais * $flux['jours'] * AMPLITUDE_MIN), 1) : null;
$rdv = ligne("SELECT COUNT(*) AS total, SUM(statut = 'absent') AS absents, SUM(statut = 'annule') AS annules
                FROM rendez_vous WHERE debut >= ? AND debut < ? + INTERVAL 1 DAY AND debut < NOW()", $periode);
$recep = ligne("SELECT COUNT(*) AS n, ROUND(100 * SUM(EXISTS (SELECT 1 FROM ecart e WHERE e.reception_id = r.id)) / NULLIF(COUNT(*), 0), 1) AS taux
                  FROM reception r WHERE r.statut = 'cloturee' AND r.debut >= ? AND r.debut < ? + INTERVAL 1 DAY", $periode);
$remplissage = valeur('SELECT ROUND(100 * SUM(occupees) / SUM(capacite), 1) FROM v_remplissage_zone');

// ---------------------------------------------------------------------
//  Séries pour les graphiques et tableaux
// ---------------------------------------------------------------------
$parJour = lignes("SELECT DATE(arrivee) AS jour, SUM(sens = 'reception') AS receptions, SUM(sens = 'expedition') AS expeditions
                     FROM v_passage_durees WHERE arrivee >= ? AND arrivee < ? + INTERVAL 1 DAY GROUP BY DATE(arrivee) ORDER BY jour", $periode);
$parQuai = lignes("SELECT q.numero, ROUND(100 * COALESCE(SUM(d.occupation_quai_min), 0) / (? * ?), 1) AS taux
                     FROM quai q LEFT JOIN passage p ON p.quai_id = q.id
                     LEFT JOIN v_passage_durees d ON d.id = p.id AND d.arrivee >= ? AND d.arrivee < ? + INTERVAL 1 DAY AND d.depart IS NOT NULL
                    GROUP BY q.id ORDER BY q.numero", [max(1, (int) $flux['jours']), AMPLITUDE_MIN, $du, $au]);
$parTransporteur = lignes("SELECT t.raison_sociale, COUNT(*) AS passages, ROUND(AVG(d.attente_min)) AS attente,
                                  ROUND(AVG(d.immobilisation_min)) AS immo, SUM(d.retard_min > 15) AS retards
                             FROM v_passage_durees d JOIN transporteur t ON t.id = d.transporteur_id
                            WHERE d.depart IS NOT NULL AND d.arrivee >= ? AND d.arrivee < ? + INTERVAL 1 DAY
                            GROUP BY t.id ORDER BY attente DESC", $periode);
$zones = lignes('SELECT libelle, occupees, capacite, taux FROM v_remplissage_zone ORDER BY code');
$fournisseurs = lignes("SELECT f.raison_sociale, COUNT(DISTINCT r.id) AS receptions, COUNT(DISTINCT e.reception_id) AS avec_ecart,
                               ROUND(100 * COUNT(DISTINCT e.reception_id) / COUNT(DISTINCT r.id), 1) AS taux,
                               SUM(e.type = 'manquant') AS manquants, SUM(e.type = 'endommage') AS casses
                          FROM reception r JOIN avis_expedition a ON a.id = r.avis_id JOIN fournisseur f ON f.id = a.fournisseur_id
                          LEFT JOIN ecart e ON e.reception_id = r.id
                         WHERE r.statut = 'cloturee' AND r.debut >= ? AND r.debut < ? + INTERVAL 1 DAY
                         GROUP BY f.id ORDER BY taux DESC LIMIT 10", $periode);
$preparateurs = lignes("SELECT CONCAT(u.prenom, ' ', u.nom) AS nom, COUNT(*) AS listes, SUM(x.lignes) AS lignes, SUM(x.unites) AS unites,
                               ROUND(SUM(x.lignes) / NULLIF(SUM(x.minutes) / 60, 0), 1) AS lignes_heure
                          FROM (SELECT li.id, li.preparateur_id, COUNT(*) AS lignes, SUM(lp.quantite_prelevee) AS unites,
                                       TIMESTAMPDIFF(MINUTE, li.debut, li.fin) AS minutes
                                  FROM liste_prelevement li JOIN ligne_prelevement lp ON lp.liste_id = li.id
                                 WHERE li.statut = 'terminee' AND li.fin >= ? AND li.fin < ? + INTERVAL 1 DAY
                                 GROUP BY li.id) x
                          JOIN utilisateur u ON u.id = x.preparateur_id GROUP BY u.id ORDER BY lignes_heure DESC", $periode);
$dlc = lignes('SELECT * FROM v_lots_proches_dlc ORDER BY date_limite_conso LIMIT 15');

$json = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
entete('Indicateurs de pilotage');
?>
<div class="entete-page">
    <form class="filtres" method="get" style="margin:0">
        <label>Du <input type="date" name="du" value="<?= e($du) ?>"></label>
        <label>Au <input type="date" name="au" value="<?= e($au) ?>"></label>
        <button type="submit">Actualiser</button>
    </form>
    <div class="actions"><button class="secondaire" onclick="window.print()">Imprimer le rapport</button></div>
</div>
<p class="discret">Rapport d'activité du <?= date_fr($du) ?> au <?= date_fr($au) ?> — généré le <?= date('d/m/Y à H:i') ?></p>

<div class="kpis">
    <div class="kpi"><div class="valeur"><?= nombre($flux['passages']) ?></div><div class="libelle">passages de camions</div></div>
    <div class="kpi"><div class="valeur"><?= nombre($tauxQuais, 1) ?> %</div><div class="libelle">taux d'occupation des quais</div></div>
    <div class="kpi"><div class="valeur"><?= duree($flux['attente'] !== null ? (int) $flux['attente'] : null) ?></div><div class="libelle">attente moyenne avant quai</div></div>
    <div class="kpi"><div class="valeur"><?= duree($flux['immo'] !== null ? (int) $flux['immo'] : null) ?></div><div class="libelle">immobilisation moyenne</div></div>
    <div class="kpi"><div class="valeur"><?= nombre($flux['taux_retard'], 1) ?> %</div><div class="libelle">arrivées en retard</div></div>
    <div class="kpi"><div class="valeur"><?= nombre($rdv['absents']) ?> / <?= nombre($rdv['total']) ?></div><div class="libelle">RDV non honorés</div></div>
    <div class="kpi"><div class="valeur"><?= nombre($recep['taux'], 1) ?> %</div><div class="libelle">réceptions avec écart (<?= nombre($recep['n']) ?>)</div></div>
    <div class="kpi"><div class="valeur"><?= nombre($remplissage, 1) ?> %</div><div class="libelle">remplissage actuel</div></div>
</div>

<div class="grille">
    <div class="carte"><h2>Passages par jour</h2><canvas id="g-jours" height="200"></canvas></div>
    <div class="carte"><h2>Taux d'occupation par quai</h2><canvas id="g-quais" height="200"></canvas></div>
    <div class="carte"><h2>Remplissage par zone</h2><canvas id="g-zones" height="200"></canvas></div>
    <div class="carte"><h2>Attente moyenne par transporteur (min)</h2><canvas id="g-transp" height="200"></canvas></div>
</div>

<div class="carte" style="margin-top:18px">
    <h2>Temps d'attente et d'immobilisation par transporteur</h2>
    <div class="tableau"><table>
        <tr><th>Transporteur</th><th class="nombre">Passages</th><th class="nombre">Attente moy.</th><th class="nombre">Immobilisation moy.</th><th class="nombre">Retards</th></tr>
        <?php foreach ($parTransporteur as $t): ?>
        <tr><td><?= e($t['raison_sociale']) ?></td><td class="nombre"><?= $t['passages'] ?></td>
            <td class="nombre"><?= duree((int) $t['attente']) ?></td><td class="nombre"><?= duree((int) $t['immo']) ?></td>
            <td class="nombre"><?= $t['retards'] ?> (<?= nombre(100 * $t['retards'] / $t['passages'], 0) ?> %)</td></tr>
        <?php endforeach; ?>
    </table></div>
</div>

<div class="grille">
    <div class="carte">
        <h2>Taux d'écart en réception — 10 fournisseurs les moins fiables</h2>
        <div class="tableau"><table>
            <tr><th>Fournisseur</th><th class="nombre">Réceptions</th><th class="nombre">Avec écart</th><th class="nombre">Manquants</th><th class="nombre">Casses</th><th class="nombre">Taux</th></tr>
            <?php foreach ($fournisseurs as $f): ?>
            <tr><td><?= e($f['raison_sociale']) ?></td><td class="nombre"><?= $f['receptions'] ?></td><td class="nombre"><?= $f['avec_ecart'] ?></td>
                <td class="nombre"><?= (int) $f['manquants'] ?></td><td class="nombre"><?= (int) $f['casses'] ?></td><td class="nombre"><strong><?= nombre($f['taux'], 1) ?> %</strong></td></tr>
            <?php endforeach; ?>
        </table></div>
    </div>
    <div class="carte">
        <h2>Productivité des préparateurs</h2>
        <div class="tableau"><table>
            <tr><th>Préparateur</th><th class="nombre">Listes</th><th class="nombre">Lignes</th><th class="nombre">Unités</th><th class="nombre">Lignes / h</th></tr>
            <?php foreach ($preparateurs as $p): ?>
            <tr><td><?= e($p['nom']) ?></td><td class="nombre"><?= $p['listes'] ?></td><td class="nombre"><?= nombre($p['lignes']) ?></td>
                <td class="nombre"><?= nombre($p['unites']) ?></td><td class="nombre"><strong><?= nombre($p['lignes_heure'], 1) ?></strong></td></tr>
            <?php endforeach; ?>
        </table></div>
    </div>
</div>

<div class="carte" style="margin-top:18px">
    <h2>Articles proches de leur date limite (30 jours)</h2>
    <?php if (!$dlc): ?><p class="vide">Aucun lot concerné.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Lot</th><th>Article</th><th>DLC</th><th class="nombre">Jours restants</th><th class="nombre">Quantité en stock</th></tr>
        <?php foreach ($dlc as $l): ?>
        <tr><td><a href="lot.php?code=<?= e($l['code_lot']) ?>"><?= e($l['code_lot']) ?></a></td><td><?= e($l['reference']) ?> <small><?= e($l['designation']) ?></small></td>
            <td><?= date_fr($l['date_limite_conso']) ?></td>
            <td class="nombre <?= $l['jours_restants'] <= 7 ? 'retard' : '' ?>"><?= $l['jours_restants'] ?></td><td class="nombre"><?= nombre($l['quantite']) ?></td></tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>

<?php ob_start(); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const bleu = '#1f5fbf', violet = '#6941c6', vert = '#1a7f4b', orange = '#e69b23', rouge = '#b42318';
const jours = <?= $json($parJour) ?>, quais = <?= $json($parQuai) ?>, zones = <?= $json($zones) ?>, transp = <?= $json($parTransporteur) ?>;
const opts = { responsive: true, plugins: { legend: { position: 'bottom' } } };
new Chart(document.getElementById('g-jours'), { type: 'bar', options: { ...opts, scales: { x: { stacked: true }, y: { stacked: true } } }, data: {
    labels: jours.map(j => new Date(j.jour).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })),
    datasets: [{ label: 'Réceptions', data: jours.map(j => j.receptions), backgroundColor: bleu },
               { label: 'Expéditions', data: jours.map(j => j.expeditions), backgroundColor: violet }] } });
new Chart(document.getElementById('g-quais'), { type: 'bar', options: { ...opts, plugins: { legend: { display: false } }, scales: { y: { max: 100, ticks: { callback: v => v + ' %' } } } }, data: {
    labels: quais.map(q => q.numero), datasets: [{ data: quais.map(q => q.taux), backgroundColor: quais.map(q => q.taux > 70 ? rouge : q.taux > 40 ? orange : vert) }] } });
new Chart(document.getElementById('g-zones'), { type: 'bar', options: { ...opts, indexAxis: 'y', scales: { x: { stacked: true } , y: { stacked: true } } }, data: {
    labels: zones.map(z => z.libelle),
    datasets: [{ label: 'Palettes occupées', data: zones.map(z => z.occupees), backgroundColor: bleu },
               { label: 'Palettes libres', data: zones.map(z => z.capacite - z.occupees), backgroundColor: '#dde2ea' }] } });
new Chart(document.getElementById('g-transp'), { type: 'bar', options: { ...opts, indexAxis: 'y' }, data: {
    labels: transp.map(t => t.raison_sociale),
    datasets: [{ label: 'Attente', data: transp.map(t => t.attente), backgroundColor: orange },
               { label: 'Immobilisation', data: transp.map(t => t.immo), backgroundColor: bleu }] } });
</script>
<?php pied(ob_get_clean());
