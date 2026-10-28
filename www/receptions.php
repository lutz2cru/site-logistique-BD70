<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_profil('responsable', 'magasinier');

// Démarrage d'une réception pour un camion à quai
if (est_post() && isset($_POST['passage_id'])) {
    verifier_csrf();
    $avis = (int) ($_POST['avis_id'] ?? 0) ?: null;
    try {
        $ok = valeur("SELECT COUNT(*) FROM passage p JOIN type_etape te ON te.id = p.etape_courante_id
                       WHERE p.id = ? AND p.sens = 'reception' AND te.code IN ('APPEL_QUAI','DEBUT_OP','FIN_OP')
                         AND NOT EXISTS (SELECT 1 FROM reception r WHERE r.passage_id = p.id)", [$_POST['passage_id']]);
        if (!$ok) { throw new RuntimeException('Ce camion n\'est pas à quai ou sa réception a déjà été démarrée.'); }
        if ($avis && !valeur("SELECT COUNT(*) FROM avis_expedition WHERE id = ? AND statut = 'attendu'", [$avis])) {
            throw new RuntimeException('Cet avis d\'expédition n\'est plus attendu.');
        }
        requete('INSERT INTO reception (passage_id, avis_id, receptionnaire_id, debut) VALUES (?, ?, ?, NOW())',
                [$_POST['passage_id'], $avis, $u['id']]);
        rediriger('reception.php?id=' . db()->lastInsertId());
    } catch (RuntimeException $e) {
        flash('erreur', $e->getMessage());
    } catch (PDOException $e) {
        flash('erreur', message_erreur($e));
    }
    rediriger('receptions.php');
}

// Camions à quai dont la réception n'est pas commencée
$aQuai = lignes("SELECT p.id, p.numero, v.immatriculation, t.raison_sociale, q.numero AS quai, te.libelle AS etape
                   FROM passage p JOIN type_etape te ON te.id = p.etape_courante_id
                   JOIN vehicule v ON v.id = p.vehicule_id JOIN transporteur t ON t.id = v.transporteur_id
                   LEFT JOIN quai q ON q.id = p.quai_id
                  WHERE p.sens = 'reception' AND te.code IN ('APPEL_QUAI','DEBUT_OP','FIN_OP')
                    AND NOT EXISTS (SELECT 1 FROM reception r WHERE r.passage_id = p.id)
                  ORDER BY q.numero");
$avisAttendus = paires("SELECT a.id, CONCAT(a.numero, ' — ', f.raison_sociale, ' (prévu le ', DATE_FORMAT(a.livraison_prevue, '%d/%m'), ')')
                          FROM avis_expedition a JOIN fournisseur f ON f.id = a.fournisseur_id
                         WHERE a.statut = 'attendu' AND NOT EXISTS (SELECT 1 FROM reception r WHERE r.avis_id = a.id)
                         ORDER BY ABS(DATEDIFF(a.livraison_prevue, CURRENT_DATE)), a.numero LIMIT 100");

// Recherche des réceptions
$f = ['du' => filtre('du') ?: date('Y-m-d', strtotime('-7 days')), 'au' => filtre('au') ?: date('Y-m-d'),
      'statut' => filtre('statut'), 'fournisseur' => filtre('fournisseur'), 'ecart' => filtre('ecart'), 'q' => filtre('q')];
$where = ['r.debut >= ?', 'r.debut < ? + INTERVAL 1 DAY'];
$params = [$f['du'], $f['au']];
if ($f['statut'] !== '') { $where[] = 'r.statut = ?'; $params[] = $f['statut']; }
if ($f['fournisseur'] !== '') { $where[] = 'a.fournisseur_id = ?'; $params[] = $f['fournisseur']; }
if ($f['ecart'] === '1') { $where[] = 'EXISTS (SELECT 1 FROM ecart e WHERE e.reception_id = r.id)'; }
if ($f['q'] !== '') { $where[] = '(r.numero LIKE ? OR a.numero LIKE ?)'; array_push($params, "%{$f['q']}%", "%{$f['q']}%"); }
$from = 'FROM reception r LEFT JOIN avis_expedition a ON a.id = r.avis_id LEFT JOIN fournisseur f ON f.id = a.fournisseur_id
         JOIN utilisateur u ON u.id = r.receptionnaire_id WHERE ' . implode(' AND ', $where);
$total = (int) valeur("SELECT COUNT(*) $from", $params);
[$limite, $decalage, $page, $pages] = pagination($total);
$receptions = lignes("SELECT r.*, a.numero AS avis, f.raison_sociale AS fournisseur, CONCAT(u.prenom, ' ', u.nom) AS receptionnaire,
                             (SELECT COUNT(*) FROM reception_lot rl WHERE rl.reception_id = r.id) AS nb_lots,
                             (SELECT COUNT(*) FROM ecart e WHERE e.reception_id = r.id) AS nb_ecarts
                      $from ORDER BY r.debut DESC LIMIT $limite OFFSET $decalage", $params);

$fournisseurs = paires('SELECT id, raison_sociale FROM fournisseur ORDER BY raison_sociale');
entete('Réceptions');
?>
<div class="carte">
    <h2>Camions à quai à réceptionner</h2>
    <?php if (!$aQuai): ?><p class="vide">Aucun camion en attente de réception.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Quai</th><th>Véhicule</th><th>Transporteur</th><th>Étape</th><th>Avis d'expédition correspondant</th></tr>
        <?php foreach ($aQuai as $p): ?>
        <tr>
            <td><strong><?= e($p['quai']) ?></strong></td><td><?= e($p['immatriculation']) ?></td>
            <td><?= e($p['raison_sociale']) ?></td><td><?= e($p['etape']) ?></td>
            <td>
                <form method="post" class="actions">
                    <?= champ_csrf() ?><input type="hidden" name="passage_id" value="<?= $p['id'] ?>">
                    <select name="avis_id" style="max-width:380px"><?= options($avisAttendus, null, 'Livraison non annoncée (sans avis)') ?></select>
                    <button class="petit">Démarrer la réception</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>

<div class="carte">
    <h2>Réceptions <span class="discret"><?= nombre($total) ?></span></h2>
    <form class="filtres" method="get">
        <label>Du <input type="date" name="du" value="<?= e($f['du']) ?>"></label>
        <label>Au <input type="date" name="au" value="<?= e($f['au']) ?>"></label>
        <label>Statut <select name="statut"><?= options(['en_cours' => 'En cours', 'cloturee' => 'Clôturée'], $f['statut'], 'Tous') ?></select></label>
        <label>Fournisseur <select name="fournisseur"><?= options($fournisseurs, $f['fournisseur'], 'Tous') ?></select></label>
        <label>Écarts <select name="ecart"><?= options(['1' => 'Avec écart uniquement'], $f['ecart'], 'Toutes') ?></select></label>
        <label>N° réception / avis <input name="q" value="<?= e($f['q']) ?>"></label>
        <button type="submit">Filtrer</button>
        <a class="bouton discret" href="receptions.php">Réinitialiser</a>
    </form>
    <?php if (!$receptions): ?><p class="vide">Aucune réception sur cette période.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Numéro</th><th>Début</th><th>Avis</th><th>Fournisseur</th><th>Réceptionnaire</th><th class="nombre">Lots</th><th class="nombre">Écarts</th><th>Statut</th></tr>
        <?php foreach ($receptions as $r): ?>
        <tr>
            <td><a href="reception.php?id=<?= $r['id'] ?>"><?= e($r['numero']) ?></a></td>
            <td><?= date_heure_fr($r['debut']) ?></td>
            <td><?= e($r['avis'] ?? 'non annoncée') ?></td>
            <td><?= e($r['fournisseur']) ?></td>
            <td><?= e($r['receptionnaire']) ?></td>
            <td class="nombre"><?= $r['nb_lots'] ?></td>
            <td class="nombre"><?= $r['nb_ecarts'] ? '<span class="pastille rouge">' . $r['nb_ecarts'] . '</span>' : '0' ?></td>
            <td><?= pastille($r['statut']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?= liens_pagination($page, $pages) ?>
    <?php endif; ?>
</div>
<?php pied();
