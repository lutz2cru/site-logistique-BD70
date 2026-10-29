<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_profil('responsable', 'accueil', 'transporteur');
$estTransporteur = $u['profil'] === 'transporteur';
$peutSaisir = a_profil('accueil', 'transporteur');   // le responsable consulte sans saisir

$jour = filtre('date') ?: date('Y-m-d');
if (!DateTime::createFromFormat('Y-m-d', $jour)) { $jour = date('Y-m-d'); }
const HEURE_DEBUT = 6;
const HEURE_FIN = 21;

$quais = lignes('SELECT q.id, q.numero, q.type, q.statut,
                        GROUP_CONCAT(tv.libelle ORDER BY tv.id SEPARATOR ", ") AS vehicules
                   FROM quai q
                   LEFT JOIN quai_type_vehicule qtv ON qtv.quai_id = q.id
                   LEFT JOIN type_vehicule tv ON tv.id = qtv.type_vehicule_id
                  GROUP BY q.id ORDER BY q.numero');
$rdvs = lignes("SELECT r.id, r.numero, r.quai_id, r.debut, r.fin, r.sens, r.statut, v.immatriculation,
                       v.transporteur_id, t.raison_sociale
                  FROM rendez_vous r
                  JOIN vehicule v ON v.id = r.vehicule_id JOIN transporteur t ON t.id = v.transporteur_id
                 WHERE r.debut >= ? AND r.debut < ? + INTERVAL 1 DAY AND r.statut <> 'annule'
                 ORDER BY r.debut", [$jour, $jour]);
$parQuai = [];
foreach ($rdvs as $r) { $parQuai[$r['quai_id']][] = $r; }

/** Position horizontale (en %) d'une heure sur la piste du planning. */
function position(string $dt): float
{
    $min = (int) date('H', strtotime($dt)) * 60 + (int) date('i', strtotime($dt)) - HEURE_DEBUT * 60;
    return max(0, min(100, 100 * $min / ((HEURE_FIN - HEURE_DEBUT) * 60)));
}

$precedent = date('Y-m-d', strtotime("$jour -1 day"));
$suivant = date('Y-m-d', strtotime("$jour +1 day"));
entete('Planning des quais');
?>
<div class="entete-page">
    <form class="filtres" method="get" style="margin:0">
        <a class="bouton discret" href="?date=<?= $precedent ?>">←</a>
        <input type="date" name="date" value="<?= e($jour) ?>" onchange="this.form.submit()">
        <a class="bouton discret" href="?date=<?= $suivant ?>">→</a>
        <a class="bouton discret" href="?date=<?= date('Y-m-d') ?>">Aujourd'hui</a>
    </form>
    <div class="actions">
        <span class="legende">
            <span class="pastille bleu">Planifié</span><span class="pastille vert">Honoré</span><span class="pastille rouge">Absent</span>
            <span>hachuré = expédition</span>
        </span>
        <?php if ($peutSaisir): ?><a class="bouton" href="rdv_form.php?date=<?= e($jour) ?>">+ Nouveau rendez-vous</a><?php endif; ?>
    </div>
</div>

<div class="carte planning">
    <div class="graduations">
        <?php for ($h = HEURE_DEBUT; $h < HEURE_FIN; $h++): ?><span><?= $h ?> h</span><?php endfor; ?>
    </div>
    <?php foreach ($quais as $q): ?>
    <div class="ligne <?= $q['statut'] === 'hors_service' ? 'hs' : '' ?>">
        <div class="nom-quai" title="<?= e($q['vehicules']) ?>">
            <?= e($q['numero']) ?><br><small><?= e(STATUTS[$q['type']][0]) ?></small>
        </div>
        <div class="piste">
            <?php if ($jour === date('Y-m-d')): ?>
                <div class="maintenant" style="left:<?= position(date('Y-m-d H:i:s')) ?>%"></div>
            <?php endif; ?>
            <?php foreach ($parQuai[$q['id']] ?? [] as $r):
                $gauche = position($r['debut']);
                $largeur = max(1.5, position($r['fin']) - $gauche);
                $visible = !$estTransporteur || $r['transporteur_id'] === $u['transporteur_id'];
                $classe = "creneau {$r['statut']} {$r['sens']}";
                $style = "left:$gauche%;width:$largeur%" . ($visible ? '' : ';background:#98a2b3');
                $info = $visible
                    ? heure($r['debut']) . '–' . heure($r['fin']) . ' · ' . $r['immatriculation'] . ' · ' . $r['raison_sociale']
                    : heure($r['debut']) . '–' . heure($r['fin']) . ' · créneau réservé'; ?>
                <?php if ($visible && $peutSaisir): ?>
                    <a class="<?= $classe ?>" style="<?= $style ?>" href="rdv_form.php?id=<?= $r['id'] ?>" title="<?= e($r['numero'] . ' · ' . $info) ?>"><?= e($info) ?></a>
                <?php else: ?>
                    <span class="<?= $classe ?>" style="<?= $style ?>" title="<?= e($info) ?>"><?= $visible ? e($info) : 'Réservé' ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<p class="discret">Survolez un créneau pour voir le détail, cliquez pour le modifier. Les quais hachurés sont hors service.</p>
<?php pied();
