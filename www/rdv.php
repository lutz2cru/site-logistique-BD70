<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_profil('responsable', 'accueil', 'transporteur');
$estTransporteur = $u['profil'] === 'transporteur';
$peutSaisir = a_profil('accueil', 'transporteur');   // le responsable consulte sans saisir

// Suppression (uniquement un rendez-vous planifié sans passage associé)
if (est_post() && isset($_POST['supprimer'])) {
    verifier_csrf();
    if (!a_profil('accueil')) { exit('Action non autorisée.'); }
    try {
        $n = requete("DELETE FROM rendez_vous WHERE id = ? AND statut = 'planifie'
                        AND NOT EXISTS (SELECT 1 FROM passage WHERE rendez_vous_id = ?)", [$_POST['supprimer'], $_POST['supprimer']])->rowCount();
        flash($n ? 'succes' : 'erreur', $n ? 'Rendez-vous supprimé.' : 'Seul un rendez-vous planifié et sans passage peut être supprimé (sinon, annulez-le).');
    } catch (PDOException $e) {
        flash('erreur', message_erreur($e));
    }
    rediriger('rdv.php?' . http_build_query($_GET));
}

// ---------------------------------------------------------------------
//  Recherche multicritère
// ---------------------------------------------------------------------
$f = [
    'du' => filtre('du') ?: date('Y-m-d'), 'au' => filtre('au') ?: date('Y-m-d', strtotime('+7 days')),
    'quai' => filtre('quai'), 'statut' => filtre('statut'), 'sens' => filtre('sens'),
    'transporteur' => filtre('transporteur'), 'q' => filtre('q'),
];
$where = ['r.debut >= ?', 'r.debut < ? + INTERVAL 1 DAY'];
$params = [$f['du'], $f['au']];
if ($f['quai'] !== '')   { $where[] = 'r.quai_id = ?'; $params[] = $f['quai']; }
if ($f['statut'] !== '') { $where[] = 'r.statut = ?'; $params[] = $f['statut']; }
if ($f['sens'] !== '')   { $where[] = 'r.sens = ?'; $params[] = $f['sens']; }
if ($estTransporteur) {
    $where[] = 'v.transporteur_id = ?'; $params[] = $u['transporteur_id'];
} elseif ($f['transporteur'] !== '') {
    $where[] = 'v.transporteur_id = ?'; $params[] = $f['transporteur'];
}
if ($f['q'] !== '') {
    $where[] = '(r.numero LIKE ? OR v.immatriculation LIKE ?)';
    array_push($params, "%{$f['q']}%", "%{$f['q']}%");
}
$from = 'FROM rendez_vous r JOIN quai q ON q.id = r.quai_id JOIN vehicule v ON v.id = r.vehicule_id
         JOIN transporteur t ON t.id = v.transporteur_id LEFT JOIN passage p ON p.rendez_vous_id = r.id
         WHERE ' . implode(' AND ', $where);

$total = (int) valeur("SELECT COUNT(*) $from", $params);
[$limite, $decalage, $page, $pages] = pagination($total);
$rdvs = lignes("SELECT r.*, q.numero AS quai, v.immatriculation, t.raison_sociale, p.id AS passage_id,
                       fn_date_etape(p.id, 'ARRIVEE') AS arrivee
                $from ORDER BY r.debut, q.numero LIMIT $limite OFFSET $decalage", $params);

// État imprimable / export CSV des rendez-vous filtrés
if (filtre('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rendez-vous.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Numéro', 'Début', 'Fin', 'Quai', 'Véhicule', 'Transporteur', 'Sens', 'Statut', 'Arrivée'], ';');
    foreach (lignes("SELECT r.*, q.numero AS quai, v.immatriculation, t.raison_sociale, fn_date_etape(p.id, 'ARRIVEE') AS arrivee $from ORDER BY r.debut", $params) as $r) {
        fputcsv($out, [$r['numero'], $r['debut'], $r['fin'], $r['quai'], $r['immatriculation'], $r['raison_sociale'], $r['sens'], $r['statut'], $r['arrivee']], ';');
    }
    exit;
}

$quais = paires('SELECT id, numero FROM quai ORDER BY numero');
$transporteurs = paires('SELECT id, raison_sociale FROM transporteur ORDER BY raison_sociale');
entete('Rendez-vous');
?>
<div class="entete-page">
    <span class="discret"><?= nombre($total) ?> rendez-vous</span>
    <div class="actions">
        <a class="bouton discret" href="?<?= e(http_build_query($_GET + ['export' => 'csv'])) ?>">Exporter (CSV)</a>
        <?php if ($peutSaisir): ?><a class="bouton" href="rdv_form.php">+ Nouveau rendez-vous</a><?php endif; ?>
    </div>
</div>
<div class="carte">
    <form class="filtres" method="get">
        <label>Du <input type="date" name="du" value="<?= e($f['du']) ?>"></label>
        <label>Au <input type="date" name="au" value="<?= e($f['au']) ?>"></label>
        <label>Quai <select name="quai"><?= options($quais, $f['quai'], 'Tous') ?></select></label>
        <label>Statut <select name="statut"><?= options(['planifie' => 'Planifié', 'honore' => 'Honoré', 'annule' => 'Annulé', 'absent' => 'Absent'], $f['statut'], 'Tous') ?></select></label>
        <label>Sens <select name="sens"><?= options(['reception' => 'Réception', 'expedition' => 'Expédition'], $f['sens'], 'Tous') ?></select></label>
        <?php if (!$estTransporteur): ?>
        <label>Transporteur <select name="transporteur"><?= options($transporteurs, $f['transporteur'], 'Tous') ?></select></label>
        <?php endif; ?>
        <label>N° ou immatriculation <input name="q" value="<?= e($f['q']) ?>" placeholder="RDV-… / AB-123-CD"></label>
        <button type="submit">Rechercher</button>
        <a class="bouton discret" href="rdv.php">Réinitialiser</a>
    </form>

    <?php if (!$rdvs): ?><p class="vide">Aucun rendez-vous ne correspond à ces critères.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Numéro</th><th>Date</th><th>Créneau</th><th>Quai</th><th>Véhicule</th><th>Transporteur</th><th>Sens</th><th>Statut</th><th>Arrivée</th><th></th></tr>
        <?php foreach ($rdvs as $r):
            $retard = $r['arrivee'] && strtotime($r['arrivee']) > strtotime($r['debut']) + 15 * 60; ?>
        <tr>
            <td><?= e($r['numero']) ?></td>
            <td><?= date_fr($r['debut']) ?></td>
            <td><?= heure($r['debut']) ?> – <?= heure($r['fin']) ?></td>
            <td><?= e($r['quai']) ?></td>
            <td><?= e($r['immatriculation']) ?></td>
            <td><?= e($r['raison_sociale']) ?></td>
            <td><?= pastille($r['sens']) ?></td>
            <td><?= pastille($r['statut']) ?></td>
            <td class="<?= $retard ? 'retard' : '' ?>">
                <?php if ($r['passage_id'] && !$estTransporteur): ?><a href="passage.php?id=<?= $r['passage_id'] ?>"><?= heure($r['arrivee']) ?></a><?php else: ?><?= heure($r['arrivee']) ?><?php endif; ?>
                <?= $retard ? ' (retard)' : '' ?>
            </td>
            <td class="actions-ligne">
                <?php if ($r['statut'] === 'planifie'): ?>
                    <?php if ($peutSaisir): ?><a class="bouton petit secondaire" href="rdv_form.php?id=<?= $r['id'] ?>">Modifier</a><?php endif; ?>
                    <?php if (a_profil('accueil') && !$r['passage_id']): ?>
                    <form method="post" onsubmit="return confirm('Supprimer définitivement ce rendez-vous ?')">
                        <?= champ_csrf() ?><button class="petit danger" name="supprimer" value="<?= $r['id'] ?>">Supprimer</button>
                    </form>
                    <?php endif; ?>
                <?php elseif ($r['statut'] === 'annule'): ?>
                    <small title="<?= e($r['motif_annulation']) ?>">motif : <?= e(mb_strimwidth($r['motif_annulation'] ?? '', 0, 30, '…')) ?></small>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?= liens_pagination($page, $pages) ?>
    <?php endif; ?>
</div>
<?php pied();
