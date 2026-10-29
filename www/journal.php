<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

exiger_profil('admin');

$f = ['table' => filtre('table'), 'action' => filtre('action'), 'du' => filtre('du') ?: date('Y-m-d', strtotime('-7 days')), 'au' => filtre('au') ?: date('Y-m-d')];
$where = ['j.date_heure >= ?', 'j.date_heure < ? + INTERVAL 1 DAY'];
$params = [$f['du'], $f['au']];
if ($f['table'] !== '') { $where[] = 'j.table_concernee = ?'; $params[] = $f['table']; }
if ($f['action'] !== '') { $where[] = 'j.action = ?'; $params[] = $f['action']; }
$from = 'FROM journal_audit j LEFT JOIN utilisateur u ON u.id = j.utilisateur_id WHERE ' . implode(' AND ', $where);
$total = (int) valeur("SELECT COUNT(*) $from", $params);
[$limite, $decalage, $page, $pages] = pagination($total, 40);
$entrees = lignes("SELECT j.*, CONCAT(u.prenom, ' ', u.nom) AS auteur $from ORDER BY j.id DESC LIMIT $limite OFFSET $decalage", $params);
$tables = paires('SELECT DISTINCT table_concernee, table_concernee FROM journal_audit ORDER BY 1');

entete('Journal des modifications');
?>
<p class="discret">Alimenté automatiquement par les déclencheurs de la base (rendez-vous, quais, articles, emplacements, comptes). Les entrées de plus de 180 jours sont archivées chaque nuit par un évènement planifié.</p>
<div class="carte">
    <form class="filtres" method="get">
        <label>Du <input type="date" name="du" value="<?= e($f['du']) ?>"></label>
        <label>Au <input type="date" name="au" value="<?= e($f['au']) ?>"></label>
        <label>Table <select name="table"><?= options($tables, $f['table'], 'Toutes') ?></select></label>
        <label>Action <select name="action"><?= options(['UPDATE' => 'Modification', 'DELETE' => 'Suppression'], $f['action'], 'Toutes') ?></select></label>
        <button type="submit">Filtrer</button>
    </form>
    <?php if (!$entrees): ?><p class="vide">Aucune entrée.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Date</th><th>Table</th><th>Action</th><th>Auteur</th><th>Détails</th></tr>
        <?php foreach ($entrees as $j):
            $d = json_decode($j['details'] ?? '', true);
            $texte = $d ? implode(' · ', array_map(fn($k, $v) => "$k : " . (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v), array_keys($d), $d)) : $j['details']; ?>
        <tr>
            <td><?= date_heure_fr($j['date_heure']) ?></td><td><?= e($j['table_concernee']) ?></td>
            <td><?= $j['action'] === 'DELETE' ? '<span class="pastille rouge">Suppression</span>' : '<span class="pastille bleu">Modification</span>' ?></td>
            <td><?= e($j['auteur'] ?? 'système') ?></td>
            <td><small><?= e($texte) ?></small></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?= liens_pagination($page, $pages) ?>
    <?php endif; ?>
</div>
<?php pied();
