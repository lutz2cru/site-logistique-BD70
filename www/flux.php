<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_profil('responsable', 'accueil', 'magasinier');
$peutAgir = a_profil('responsable', 'accueil');   // le magasinier ne fait que consulter (sauf (dé)chargement)

$etapes = lignes('SELECT id, code, libelle, ordre FROM type_etape ORDER BY ordre');
$parCode = array_column($etapes, null, 'code');

// ---------------------------------------------------------------------
//  Enregistrement d'une étape (horodatage automatique)
// ---------------------------------------------------------------------
if (est_post()) {
    verifier_csrf();
    $pid = (int) ($_POST['passage_id'] ?? 0);
    $code = $_POST['etape'] ?? '';
    $autorise = $peutAgir || in_array($code, ['DEBUT_OP', 'FIN_OP'], true);
    if (!isset($parCode[$code]) || !$autorise) { exit('Action non autorisée.'); }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        if ($code === 'APPEL_QUAI') {
            requete('UPDATE passage SET quai_id = ? WHERE id = ?', [(int) ($_POST['quai_id'] ?? 0) ?: null, $pid]);
        }
        // le déclencheur contrôle l'ordre des étapes et la disponibilité du quai,
        // puis met à jour le statut du quai et du rendez-vous
        requete('INSERT INTO passage_etape (passage_id, type_etape_id, date_heure, commentaire) VALUES (?, ?, NOW(), ?)',
                [$pid, $parCode[$code]['id'], trim($_POST['commentaire'] ?? '') ?: null]);
        $pdo->commit();
        $numero = valeur('SELECT numero FROM passage WHERE id = ?', [$pid]);
        flash('succes', "$numero : « {$parCode[$code]['libelle']} » enregistré à " . date('H:i') . '.');
    } catch (PDOException $e) {
        $pdo->rollBack();
        flash('erreur', message_erreur($e));
    }
    rediriger('flux.php');
}

// ---------------------------------------------------------------------
//  Données affichées
// ---------------------------------------------------------------------
$surSite = lignes("SELECT p.id, p.numero, p.sens, p.quai_id, p.rendez_vous_id, p.vehicule_id, v.immatriculation, v.type_vehicule_id,
                          tv.libelle AS type_vehicule, t.raison_sociale, CONCAT(c.prenom, ' ', c.nom) AS chauffeur,
                          te.code AS etape, te.libelle AS etape_libelle, te.ordre, q.numero AS quai,
                          r.debut AS rdv_debut, rq.id AS rdv_quai_id, rq.numero AS rdv_quai,
                          fn_date_etape(p.id, 'ARRIVEE') AS arrivee,
                          (SELECT MAX(date_heure) FROM passage_etape WHERE passage_id = p.id) AS derniere,
                          (SELECT r2.id FROM reception r2 WHERE r2.passage_id = p.id LIMIT 1) AS reception_id
                     FROM passage p
                     JOIN type_etape te ON te.id = p.etape_courante_id
                     JOIN vehicule v ON v.id = p.vehicule_id
                     JOIN type_vehicule tv ON tv.id = v.type_vehicule_id
                     JOIN transporteur t ON t.id = v.transporteur_id
                     JOIN chauffeur c ON c.id = p.chauffeur_id
                     LEFT JOIN quai q ON q.id = p.quai_id
                     LEFT JOIN rendez_vous r ON r.id = p.rendez_vous_id
                     LEFT JOIN quai rq ON rq.id = r.quai_id
                    WHERE te.code <> 'DEPART'
                    ORDER BY te.ordre DESC, arrivee");

// quais libres (statut stocké) avec types de véhicules acceptés, pour l'appel à quai
$quaisLibres = lignes("SELECT q.id, q.numero, q.type, GROUP_CONCAT(qtv.type_vehicule_id) AS types
                         FROM quai q JOIN quai_type_vehicule qtv ON qtv.quai_id = q.id
                        WHERE q.statut = 'libre' GROUP BY q.id ORDER BY q.numero");

$attendus = lignes("SELECT r.id, r.numero, r.debut, r.sens, q.numero AS quai, v.immatriculation, t.raison_sociale,
                           TIMESTAMPDIFF(MINUTE, r.debut, NOW()) AS ecart
                      FROM rendez_vous r JOIN quai q ON q.id = r.quai_id
                      JOIN vehicule v ON v.id = r.vehicule_id JOIN transporteur t ON t.id = v.transporteur_id
                     WHERE r.statut = 'planifie' AND DATE(r.debut) = CURRENT_DATE
                       AND NOT EXISTS (SELECT 1 FROM passage p WHERE p.rendez_vous_id = r.id)
                     ORDER BY r.debut");

/** Quais proposés pour un camion : libres, compatibles avec son véhicule et son sens ; celui du RDV en premier. */
function quais_proposes(array $p, array $quaisLibres): array
{
    $ok = array_filter($quaisLibres, fn($q) => in_array((string) $p['type_vehicule_id'], explode(',', $q['types']), true)
                                            && in_array($q['type'], [$p['sens'], 'mixte'], true));
    usort($ok, fn($a, $b) => [$b['id'] === $p['rdv_quai_id'], $a['type'] === 'mixte', $a['numero']]
                         <=> [$a['id'] === $p['rdv_quai_id'], $b['type'] === 'mixte', $b['numero']]);
    return $ok;
}

entete('Camions sur site');
?>
<div class="entete-page">
    <span class="discret"><?= count($surSite) ?> camion(s) sur site · <?= count($attendus) ?> rendez-vous encore attendus aujourd'hui</span>
    <?php if ($peutAgir): ?><a class="bouton" href="passage_arrivee.php">+ Arrivée sans rendez-vous</a><?php endif; ?>
</div>

<div class="carte">
    <h2>Camions sur site</h2>
    <?php if (!$surSite): ?><p class="vide">Aucun camion sur le site.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Véhicule</th><th>Transporteur / chauffeur</th><th>Sens</th><th>RDV</th><th>Arrivée</th><th>Étape actuelle</th><th>Quai</th><th>Action suivante</th></tr>
        <?php foreach ($surSite as $p):
            $retard = $p['rdv_debut'] ? (int) ((strtotime($p['arrivee']) - strtotime($p['rdv_debut'])) / 60) : null;
            $depuis = (int) ((time() - strtotime($p['derniere'])) / 60); ?>
        <tr>
            <td><a href="passage.php?id=<?= $p['id'] ?>"><strong><?= e($p['immatriculation']) ?></strong></a><br><small><?= e($p['type_vehicule']) ?></small></td>
            <td><?= e($p['raison_sociale']) ?><br><small><?= e($p['chauffeur']) ?></small></td>
            <td><?= pastille($p['sens']) ?></td>
            <td><?php if ($p['rdv_debut']): ?><?= heure($p['rdv_debut']) ?> · <?= e($p['rdv_quai']) ?><?php else: ?><span class="pastille orange">Sans RDV</span><?php endif; ?></td>
            <td><?= heure($p['arrivee']) ?>
                <?php if ($retard !== null && $retard > 15): ?><br><small class="retard">retard <?= duree($retard) ?></small><?php endif; ?></td>
            <td><?= e($p['etape_libelle']) ?><br><small class="<?= $depuis > 45 ? 'retard' : '' ?>">depuis <?= duree($depuis) ?></small></td>
            <td><?= e($p['quai']) ?></td>
            <td>
                <?php
                $form =fn(string $code, string $lib, string $classe = '', string $extra = '') =>
                    '<form method="post" style="display:inline-flex;gap:4px;align-items:center">' . champ_csrf()
                    . '<input type="hidden" name="passage_id" value="' . $p['id'] . '"><input type="hidden" name="etape" value="' . $code . '">'
                    . $extra . '<button class="petit ' . $classe . '">' . e($lib) . '</button></form> ';

                if (in_array($p['etape'], ['ARRIVEE', 'CONTROLE', 'PARKING'], true)) {
                    if (!$peutAgir) { echo '<small>en attente du poste de garde</small>'; }
                    else {
                        if ($p['etape'] === 'ARRIVEE') { echo $form('CONTROLE', 'Documents contrôlés'); }
                        if ($p['etape'] === 'CONTROLE') { echo $form('PARKING', 'Mettre en attente', 'discret'); }
                        $proposes = quais_proposes($p, $quaisLibres);
                        if ($p['etape'] === 'ARRIVEE') {
                            // les documents doivent être contrôlés avant l'appel à quai
                        } elseif ($proposes) {
                            $select = '<select name="quai_id" style="padding:2px 4px">' . options(array_column($proposes, 'numero', 'id')) . '</select>';
                            echo $form('APPEL_QUAI', 'Appeler à quai', 'secondaire', $select);
                        } else {
                            echo '<small class="retard">aucun quai compatible libre</small>';
                        }
                    }
                } elseif ($p['etape'] === 'APPEL_QUAI') {
                    echo $form('DEBUT_OP', 'Début ' . ($p['sens'] === 'reception' ? 'déchargement' : 'chargement'));
                } elseif ($p['etape'] === 'DEBUT_OP') {
                    if ($p['sens'] === 'reception') {
                        echo $p['reception_id']
                            ? '<a class="bouton petit secondaire" href="reception.php?id=' . $p['reception_id'] . '">Réception</a> '
                            : '<a class="bouton petit secondaire" href="receptions.php">Démarrer la réception</a> ';
                    }
                    echo $form('FIN_OP', 'Fin ' . ($p['sens'] === 'reception' ? 'déchargement' : 'chargement'));
                } elseif ($p['etape'] === 'FIN_OP' && $peutAgir) {
                    echo $form('DEPART', 'Départ du site', '', '');
                }
                ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>

<div class="carte">
    <h2>Rendez-vous attendus aujourd'hui</h2>
    <?php if (!$attendus): ?><p class="vide">Tous les rendez-vous du jour sont arrivés.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Heure</th><th>Numéro</th><th>Quai</th><th>Véhicule</th><th>Transporteur</th><th>Sens</th><th></th></tr>
        <?php foreach ($attendus as $r): ?>
        <tr>
            <td class="<?= $r['ecart'] > 15 ? 'retard' : '' ?>"><?= heure($r['debut']) ?><?= $r['ecart'] > 15 ? ' · retard ' . duree((int) $r['ecart']) : '' ?></td>
            <td><?= e($r['numero']) ?></td><td><?= e($r['quai']) ?></td>
            <td><?= e($r['immatriculation']) ?></td><td><?= e($r['raison_sociale']) ?></td><td><?= pastille($r['sens']) ?></td>
            <td class="actions-ligne"><?php if ($peutAgir): ?><a class="bouton petit" href="passage_arrivee.php?rdv=<?= $r['id'] ?>">Enregistrer l'arrivée</a><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>
<?php pied('<script>setTimeout(() => location.reload(), 120000)</script>');
