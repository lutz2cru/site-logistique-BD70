<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$u = exiger_profil('responsable', 'accueil', 'transporteur');
$estTransporteur = $u['profil'] === 'transporteur';
$peutSaisir = a_profil('accueil', 'transporteur');   // le responsable consulte sans saisir
$peutPlanifier = a_profil('responsable');            // le responsable inscrit les évènements (maintenance, inventaire…)

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

// Évènements qui touchent la journée (un évènement peut durer plusieurs jours)
$evenements = lignes("SELECT e.id, e.type, e.libelle, e.quai_id, e.debut, e.fin, e.bloquant, e.commentaire, q.numero AS quai
                        FROM evenement e LEFT JOIN quai q ON q.id = e.quai_id
                       WHERE e.debut < ? + INTERVAL 1 DAY AND e.fin > ?
                       ORDER BY e.debut", [$jour, $jour]);
$evParQuai = [];   // clé '' = tout le site
foreach ($evenements as $ev) { $evParQuai[$ev['quai_id'] ?? ''][] = $ev; }
$bloquantsSite = array_filter($evParQuai[''] ?? [], fn($ev) => $ev['bloquant']);

/** Position horizontale (en %) d'une date sur la piste du jour affiché (bornée à la journée). */
function position(string $dt): float
{
    global $jour;
    $t = strtotime($dt);
    if ($t <= strtotime($jour)) { return 0; }
    if ($t >= strtotime("$jour +1 day")) { return 100; }
    $min = (int) date('H', $t) * 60 + (int) date('i', $t) - HEURE_DEBUT * 60;
    return max(0, min(100, 100 * $min / ((HEURE_FIN - HEURE_DEBUT) * 60)));
}

/** Horaire lisible d'un évènement, avec les dates s'il déborde de la journée. */
function horaire_evenement(array $ev): string
{
    $surLeJour = substr($ev['debut'], 0, 10) === substr($ev['fin'], 0, 10);
    return $surLeJour ? heure($ev['debut']) . '–' . heure($ev['fin'])
                      : 'du ' . date_heure_fr($ev['debut']) . ' au ' . date_heure_fr($ev['fin']);
}

/** Bloc d'un évènement sur une piste (lien vers sa fiche pour le responsable). */
function bloc_evenement(array $ev, bool $lien): string
{
    $gauche = position($ev['debut']);
    $largeur = max(1.5, position($ev['fin']) - $gauche);
    $classe = 'evenement ' . TYPES_EVENEMENT[$ev['type']][1] . ($ev['bloquant'] ? '' : ' info');
    $texte = $ev['libelle'] . ' · ' . horaire_evenement($ev);
    $titre = TYPES_EVENEMENT[$ev['type']][0] . ($ev['bloquant'] ? ' (créneau bloqué)' : ' (information)') . ' · ' . $texte
           . ($ev['commentaire'] ? ' · ' . $ev['commentaire'] : '');
    $attr = 'class="' . $classe . '" style="left:' . $gauche . '%;width:' . $largeur . '%" title="' . e($titre) . '"';
    return $lien ? '<a ' . $attr . ' href="evenement_form.php?id=' . $ev['id'] . '">' . e($texte) . '</a>'
                 : '<span ' . $attr . '>' . e($texte) . '</span>';
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
        <?php if ($peutPlanifier): ?><a class="bouton discret" href="evenement_form.php?date=<?= e($jour) ?>">+ Nouvel évènement</a><?php endif; ?>
        <?php if ($peutSaisir): ?><a class="bouton" href="rdv_form.php?date=<?= e($jour) ?>">+ Nouveau rendez-vous</a><?php endif; ?>
    </div>
</div>

<div class="carte planning">
    <div class="graduations">
        <?php for ($h = HEURE_DEBUT; $h < HEURE_FIN; $h++): ?><span><?= $h ?> h</span><?php endfor; ?>
    </div>
    <?php if (!empty($evParQuai[''])): ?>
    <div class="ligne site">
        <div class="nom-quai">Site<br><small>tous les quais</small></div>
        <div class="piste">
            <?php foreach ($evParQuai[''] as $ev) { echo bloc_evenement($ev, $peutPlanifier); } ?>
        </div>
    </div>
    <?php endif; ?>
    <?php foreach ($quais as $q): ?>
    <div class="ligne <?= $q['statut'] === 'hors_service' ? 'hs' : '' ?>">
        <div class="nom-quai" title="<?= e($q['vehicules']) ?>">
            <?= e($q['numero']) ?><br><small><?= e(STATUTS[$q['type']][0]) ?></small>
        </div>
        <div class="piste">
            <?php foreach ($bloquantsSite as $ev):   // fermeture ou inventaire : créneaux indisponibles sur chaque quai
                $gauche = position($ev['debut']); ?>
                <div class="indisponible" style="left:<?= $gauche ?>%;width:<?= position($ev['fin']) - $gauche ?>%" title="<?= e($ev['libelle']) ?>"></div>
            <?php endforeach; ?>
            <?php foreach ($evParQuai[$q['id']] ?? [] as $ev) { echo bloc_evenement($ev, $peutPlanifier); } ?>
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
<p class="discret">Survolez un créneau pour voir le détail, cliquez pour le modifier. Les quais hachurés sont hors service.
Les évènements à bordure pleine bloquent la prise de rendez-vous ; en pointillés, ils sont donnés pour information.</p>

<?php if ($evenements): ?>
<div class="carte tableau">
    <h2>Évènements du jour</h2>
    <table>
        <thead><tr><th>Type</th><th>Évènement</th><th>Quai</th><th>Horaire</th><th>Effet</th></tr></thead>
        <tbody>
        <?php foreach ($evenements as $ev): ?>
            <tr>
                <td><span class="pastille <?= TYPES_EVENEMENT[$ev['type']][1] ?>"><?= e(TYPES_EVENEMENT[$ev['type']][0]) ?></span></td>
                <td><?php if ($peutPlanifier): ?><a href="evenement_form.php?id=<?= $ev['id'] ?>"><?= e($ev['libelle']) ?></a><?php else: ?><?= e($ev['libelle']) ?><?php endif; ?>
                    <?php if ($ev['commentaire'] && !$estTransporteur): ?><br><small class="discret"><?= e($ev['commentaire']) ?></small><?php endif; ?></td>
                <td><?= e($ev['quai'] ?? 'Tout le site') ?></td>
                <td><?= e(horaire_evenement($ev)) ?></td>
                <td><?= $ev['bloquant'] ? 'Aucun rendez-vous possible' : 'Information' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php pied();
