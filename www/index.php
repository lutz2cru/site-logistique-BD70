<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_connexion();

// Le transporteur n'a accès qu'à ses propres rendez-vous
if ($u['profil'] === 'transporteur') {
    $rdvs = lignes("SELECT r.id, r.numero, r.debut, r.fin, r.sens, r.statut, q.numero AS quai, v.immatriculation
                      FROM rendez_vous r JOIN quai q ON q.id = r.quai_id JOIN vehicule v ON v.id = r.vehicule_id
                     WHERE v.transporteur_id = ? AND r.debut >= CURRENT_DATE AND r.statut = 'planifie'
                     ORDER BY r.debut LIMIT 20", [$u['transporteur_id']]);
    entete('Tableau de bord');
    ?>
    <div class="entete-page">
        <p>Bienvenue dans votre espace transporteur. Vous pouvez y planifier vos passages sur les quais de l'entrepôt.</p>
        <a class="bouton" href="rdv_form.php">+ Prendre un rendez-vous</a>
    </div>
    <div class="carte">
        <h2>Vos prochains rendez-vous</h2>
        <?php if (!$rdvs): ?><p class="vide">Aucun rendez-vous à venir.</p><?php else: ?>
        <div class="tableau"><table>
            <tr><th>Numéro</th><th>Date</th><th>Créneau</th><th>Quai</th><th>Véhicule</th><th>Sens</th><th></th></tr>
            <?php foreach ($rdvs as $r): ?>
            <tr>
                <td><?= e($r['numero']) ?></td><td><?= date_fr($r['debut']) ?></td>
                <td><?= heure($r['debut']) ?> – <?= heure($r['fin']) ?></td><td><?= e($r['quai']) ?></td>
                <td><?= e($r['immatriculation']) ?></td><td><?= pastille($r['sens']) ?></td>
                <td class="actions-ligne"><a class="bouton petit secondaire" href="rdv_form.php?id=<?= $r['id'] ?>">Modifier</a></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
    </div>
    <?php
    pied();
    exit;
}

// L'administrateur gère le système d'information, pas l'exploitation
if ($u['profil'] === 'admin') {
    $c = ligne("SELECT SUM(actif = FALSE) AS en_attente, SUM(actif = TRUE) AS actifs FROM utilisateur");
    $parProfil = lignes("SELECT p.libelle, COUNT(u.id) AS nb FROM profil p LEFT JOIN utilisateur u ON u.profil_id = p.id AND u.actif
                          GROUP BY p.id ORDER BY p.id");
    $journal = (int) valeur("SELECT COUNT(*) FROM journal_audit WHERE date_heure >= CURRENT_DATE - INTERVAL 7 DAY");
    entete('Tableau de bord');
    ?>
    <div class="kpis">
        <div class="kpi"><div class="valeur"><?= (int) $c['en_attente'] ?></div><div class="libelle">inscriptions à valider</div></div>
        <div class="kpi"><div class="valeur"><?= (int) $c['actifs'] ?></div><div class="libelle">comptes actifs</div></div>
        <div class="kpi"><div class="valeur"><?= nombre($journal) ?></div><div class="libelle">modifications journalisées (7 jours)</div></div>
    </div>
    <div class="grille">
        <div class="carte">
            <h2>Comptes actifs par profil <a class="bouton petit secondaire" href="utilisateurs.php?actif=0">Inscriptions en attente</a></h2>
            <div class="tableau"><table>
                <tr><th>Profil</th><th class="nombre">Comptes</th></tr>
                <?php foreach ($parProfil as $p): ?>
                <tr><td><?= e($p['libelle']) ?></td><td class="nombre"><?= $p['nb'] ?></td></tr>
                <?php endforeach; ?>
            </table></div>
        </div>
        <div class="carte">
            <h2>Journal des modifications <a class="bouton petit secondaire" href="journal.php">Consulter</a></h2>
            <p class="discret">Traçabilité des modifications sensibles (rendez-vous, quais, articles, emplacements, comptes), alimentée par les déclencheurs de la base.</p>
        </div>
    </div>
    <?php
    pied();
    exit;
}

// ---------------------------------------------------------------------
//  Personnel de l'entrepôt
// ---------------------------------------------------------------------
$k = ligne("SELECT
    (SELECT COUNT(*) FROM passage p JOIN type_etape te ON te.id = p.etape_courante_id WHERE te.code <> 'DEPART') AS sur_site,
    (SELECT COUNT(*) FROM passage p JOIN type_etape te ON te.id = p.etape_courante_id WHERE te.code IN ('ARRIVEE','CONTROLE','PARKING')) AS en_attente,
    (SELECT COUNT(*) FROM quai WHERE statut = 'occupe') AS quais_occupes,
    (SELECT COUNT(*) FROM quai WHERE statut <> 'hors_service') AS quais_service,
    (SELECT COUNT(*) FROM rendez_vous WHERE DATE(debut) = CURRENT_DATE AND statut <> 'annule') AS rdv_jour,
    (SELECT COUNT(*) FROM rendez_vous WHERE DATE(debut) = CURRENT_DATE AND statut = 'honore') AS rdv_honores,
    (SELECT COUNT(*) FROM reception WHERE statut = 'en_cours') AS receptions_en_cours,
    (SELECT ROUND(100 * SUM(occupees) / SUM(capacite), 1) FROM v_remplissage_zone) AS remplissage,
    (SELECT COUNT(*) FROM v_lots_proches_dlc WHERE jours_restants <= 7) AS dlc_7j,
    (SELECT COUNT(*) FROM liste_prelevement WHERE statut <> 'terminee') AS listes_ouvertes");

$quais = lignes('SELECT * FROM v_quai_temps_reel ORDER BY numero');
$attente = lignes("SELECT p.id, p.numero, v.immatriculation, t.raison_sociale, te.libelle AS etape, p.sens,
                          fn_date_etape(p.id, 'ARRIVEE') AS arrivee,
                          TIMESTAMPDIFF(MINUTE, fn_date_etape(p.id, 'ARRIVEE'), NOW()) AS depuis
                     FROM passage p
                     JOIN type_etape te ON te.id = p.etape_courante_id
                     JOIN vehicule v ON v.id = p.vehicule_id JOIN transporteur t ON t.id = v.transporteur_id
                    WHERE te.code <> 'DEPART'
                    ORDER BY arrivee LIMIT 10");
$prochains = lignes("SELECT r.id, r.numero, r.debut, r.sens, q.numero AS quai, v.immatriculation, t.raison_sociale
                       FROM rendez_vous r JOIN quai q ON q.id = r.quai_id
                       JOIN vehicule v ON v.id = r.vehicule_id JOIN transporteur t ON t.id = v.transporteur_id
                      WHERE r.statut = 'planifie' AND r.debut BETWEEN NOW() - INTERVAL 1 HOUR AND NOW() + INTERVAL 3 HOUR
                      ORDER BY r.debut LIMIT 10");

entete('Tableau de bord');
?>
<div class="kpis">
    <div class="kpi"><div class="valeur"><?= $k['sur_site'] ?></div><div class="libelle">camions sur site (<?= $k['en_attente'] ?> en attente)</div></div>
    <div class="kpi"><div class="valeur"><?= $k['quais_occupes'] ?> / <?= $k['quais_service'] ?></div><div class="libelle">quais occupés</div></div>
    <div class="kpi"><div class="valeur"><?= $k['rdv_honores'] ?> / <?= $k['rdv_jour'] ?></div><div class="libelle">rendez-vous honorés aujourd'hui</div></div>
    <div class="kpi"><div class="valeur"><?= $k['receptions_en_cours'] ?></div><div class="libelle">réceptions en cours</div></div>
    <div class="kpi"><div class="valeur"><?= nombre($k['remplissage'], 1) ?> %</div><div class="libelle">remplissage de l'entrepôt</div></div>
    <div class="kpi"><div class="valeur"><?= $k['dlc_7j'] ?></div><div class="libelle">lots périmant sous 7 jours</div></div>
</div>

<div class="grille">
    <div class="carte">
        <h2>État des quais <a class="bouton petit secondaire" href="planning.php">Planning</a></h2>
        <div class="tableau"><table>
            <tr><th>Quai</th><th>Type</th><th>Statut</th><th>À quai</th><th>Prochain RDV</th></tr>
            <?php foreach ($quais as $q): ?>
            <tr>
                <td><strong><?= e($q['numero']) ?></strong></td><td><?= pastille($q['type']) ?></td>
                <td><?= pastille($q['statut']) ?></td><td><?= e($q['vehicule_a_quai']) ?></td>
                <td><?= $q['prochain_rdv'] ? date_heure_fr($q['prochain_rdv']) : '' ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
    </div>
    <div>
        <div class="carte">
            <h2>Camions sur site <a class="bouton petit secondaire" href="flux.php">Gérer</a></h2>
            <?php if (!$attente): ?><p class="vide">Aucun camion sur site.</p><?php else: ?>
            <div class="tableau"><table>
                <tr><th>Véhicule</th><th>Transporteur</th><th>Étape</th><th class="nombre">Depuis</th></tr>
                <?php foreach ($attente as $a): ?>
                <tr>
                    <td><a href="passage.php?id=<?= $a['id'] ?>"><?= e($a['immatriculation']) ?></a></td>
                    <td><?= e($a['raison_sociale']) ?></td><td><?= e($a['etape']) ?></td>
                    <td class="nombre <?= $a['depuis'] > 60 ? 'retard' : '' ?>"><?= duree((int) $a['depuis']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table></div>
            <?php endif; ?>
        </div>
        <div class="carte">
            <h2>Arrivées attendues (3 h)</h2>
            <?php if (!$prochains): ?><p class="vide">Aucun rendez-vous dans les 3 prochaines heures.</p><?php else: ?>
            <div class="tableau"><table>
                <tr><th>Heure</th><th>Quai</th><th>Véhicule</th><th>Transporteur</th><th>Sens</th></tr>
                <?php foreach ($prochains as $r): ?>
                <tr>
                    <td class="<?= strtotime($r['debut']) < time() ? 'retard' : '' ?>"><?= heure($r['debut']) ?></td>
                    <td><?= e($r['quai']) ?></td><td><?= e($r['immatriculation']) ?></td>
                    <td><?= e($r['raison_sociale']) ?></td><td><?= pastille($r['sens']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php pied();
