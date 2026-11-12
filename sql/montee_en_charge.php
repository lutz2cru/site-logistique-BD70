<?php
/**
 * Montée en charge : ajoute un historique ancien à la base remplie par installer.php,
 * pour mesurer les requêtes sur plusieurs millions de lignes.
 *
 *   php sql/montee_en_charge.php --mouvements=1000000 --rdv=200000 [--host=] [--user=] [--pass=]
 *
 * --mouvements et --rdv sont les volumes d'archive visés (le script complète ce qui manque :
 * on peut le relancer avec des valeurs plus grandes pour passer au palier suivant).
 *
 * L'archive est datée AVANT les jours simulés par installer.php : les écrans du jour
 * (planning, stock, camions sur site) ne changent pas, seules les tables d'historique grossissent.
 * Les lignes passent par les déclencheurs, comme dans l'installateur : elles respectent les règles
 * métier (pas de chevauchement de rendez-vous, capacité et zone des emplacements, stock suffisant).
 *  - mouvement_stock : pour chaque lot d'archive, 5 couples entrée / sortie de même quantité,
 *    sur un emplacement compatible : le stock actuel est inchangé.
 *  - rendez_vous : créneaux de 30 min de 6 h à 20 h sur chaque quai, un véhicule différent par
 *    quai sur un même créneau ; 5 % sont annulés.
 * Les lignes d'archive sont marquées (motif / commentaire 'Archive', lot fournisseur 'ARCHIVE').
 * Pour revenir à la base de démonstration : relancer installer.php.
 */
$opts = getopt('', ['host::', 'user::', 'pass::', 'mouvements::', 'rdv::']);
$pdo = new PDO('mysql:host=' . ($opts['host'] ?? 'localhost') . ';dbname=entrepot;charset=utf8mb4', $opts['user'] ?? 'root', $opts['pass'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
// planificateur suspendu pendant le chargement : ev_quais_maintenance pourrait passer un quai
// hors service en cours de route, et les rendez-vous d'archive sur ce quai seraient refusés
try { $pdo->exec('SET GLOBAL event_scheduler = OFF'); } catch (PDOException $e) {}
register_shutdown_function(function () use ($pdo) {
    try { $pdo->exec('SET GLOBAL event_scheduler = ON'); } catch (PDOException $e) {}
});

const LOT_SQL = 50000;          // lignes insérées par requête INSERT … SELECT
const COUPLES_PAR_LOT = 5;      // couples entrée / sortie par lot d'archive
const CRENEAUX_PAR_JOUR = 28;   // 6 h → 20 h, par pas de 30 min

$cibleMvt = (int) ($opts['mouvements'] ?? 1000000);
$cibleRdv = (int) ($opts['rdv'] ?? 200000);
$cibleMvt -= $cibleMvt % (2 * COUPLES_PAR_LOT);   // un lot = 10 mouvements

// Fin de l'archive : la veille du premier jour simulé par installer.php (stable d'un palier à l'autre)
$fin = $pdo->query("SELECT DATE(LEAST(
        (SELECT MIN(debut) FROM rendez_vous WHERE commentaire IS NULL OR commentaire <> 'Archive'),
        (SELECT MIN(date_heure) FROM mouvement_stock WHERE motif IS NULL OR motif <> 'Archive')))")->fetchColumn();
echo "Archive datée avant le $fin\n";

function chrono(float $t0, int $n): string
{
    $s = microtime(true) - $t0;
    return sprintf('%6.1f s, %6.0f lignes/s', $s, $n / max($s, 0.001));
}

// ---------------------------------------------------------------------------
//  Rendez-vous
// ---------------------------------------------------------------------------
$deja = (int) $pdo->query("SELECT COUNT(*) FROM rendez_vous WHERE commentaire = 'Archive'")->fetchColumn();
if ($deja < $cibleRdv) {
    // quais en service, numérotés 0..n-1
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS t_quai');
    $pdo->exec("CREATE TEMPORARY TABLE t_quai (idx INT PRIMARY KEY, id SMALLINT UNSIGNED, type VARCHAR(12))
                SELECT ROW_NUMBER() OVER (ORDER BY id) - 1 AS idx, id, type FROM quai WHERE statut <> 'hors_service'");
    $nbQuais = (int) $pdo->query('SELECT COUNT(*) FROM t_quai')->fetchColumn();
    // véhicules acceptés sur tous ces quais
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS t_vehicule');
    $pdo->exec("CREATE TEMPORARY TABLE t_vehicule (idx INT PRIMARY KEY, id INT UNSIGNED)
                SELECT ROW_NUMBER() OVER (ORDER BY v.id) - 1 AS idx, v.id FROM vehicule v
                 WHERE (SELECT COUNT(*) FROM quai_type_vehicule q JOIN t_quai t ON t.id = q.quai_id
                         WHERE q.type_vehicule_id = v.type_vehicule_id) = $nbQuais");
    $nbVehicules = (int) $pdo->query('SELECT COUNT(*) FROM t_vehicule')->fetchColumn();
    if ($nbVehicules < $nbQuais) { exit("Pas assez de véhicules compatibles avec tous les quais.\n"); }

    printf("Rendez-vous : %s → %s (%d quais, %d véhicules)\n", number_format($deja, 0, ',', ' '), number_format($cibleRdv, 0, ',', ' '), $nbQuais, $nbVehicules);
    $parJour = $nbQuais * CRENEAUX_PAR_JOUR;
    for ($n0 = $deja; $n0 < $cibleRdv; $n0 += LOT_SQL) {
        $n1 = min($n0 + LOT_SQL, $cibleRdv) - 1;
        $t0 = microtime(true);
        // n = numéro global du rendez-vous d'archive : quai = n mod nbQuais, créneau, puis jour (en remontant le temps)
        $creneau = "((s.seq DIV $nbQuais) % " . CRENEAUX_PAR_JOUR . ')';
        $debut = "(TIMESTAMP('$fin') - INTERVAL (s.seq DIV $parJour + 1) DAY + INTERVAL (360 + 30 * $creneau) MINUTE)";
        $pdo->exec("INSERT INTO rendez_vous (quai_id, vehicule_id, debut, fin, sens, statut, commentaire)
            SELECT q.id, v.id, $debut, $debut + INTERVAL 30 MINUTE,
                   IF(q.type = 'mixte', IF(s.seq % 2 = 0, 'reception', 'expedition'), q.type),
                   IF(s.seq % 20 = 7, 'annule', 'honore'), 'Archive'
              FROM seq_{$n0}_to_{$n1} s
              JOIN t_quai q ON q.idx = s.seq % $nbQuais
              JOIN t_vehicule v ON v.idx = (s.seq % $nbQuais + $nbQuais * $creneau + 7 * (s.seq DIV $parJour)) % $nbVehicules
             ORDER BY s.seq");
        printf("  %9s lignes  %s\n", number_format($n1 + 1, 0, ',', ' '), chrono($t0, $n1 - $n0 + 1));
    }
}

// ---------------------------------------------------------------------------
//  Lots et mouvements de stock
// ---------------------------------------------------------------------------
$deja = (int) $pdo->query("SELECT COUNT(*) FROM mouvement_stock WHERE motif = 'Archive'")->fetchColumn();
if ($deja < $cibleMvt) {
    // emplacements pouvant recevoir une palette de 500 kg, numérotés par condition de stockage
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS t_emplacement');
    $pdo->exec("CREATE TEMPORARY TABLE t_emplacement (cond TINYINT UNSIGNED, idx INT, id INT UNSIGNED, PRIMARY KEY (cond, idx))
                SELECT zc.condition_stockage_id AS cond,
                       ROW_NUMBER() OVER (PARTITION BY zc.condition_stockage_id ORDER BY e.id) - 1 AS idx, e.id
                  FROM emplacement e JOIN rack r ON r.id = e.rack_id JOIN allee a ON a.id = r.allee_id
                  JOIN zone_condition zc ON zc.zone_id = a.zone_id
                 WHERE e.bloque = 0 AND fn_palettes_libres(e.id) >= 1 AND fn_poids_libre(e.id) >= 500");
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS t_nb_emplacement');
    $pdo->exec('CREATE TEMPORARY TABLE t_nb_emplacement (cond TINYINT UNSIGNED PRIMARY KEY, n INT)
                SELECT cond, COUNT(*) AS n FROM t_emplacement GROUP BY cond');
    // articles qu'on peut ranger quelque part
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS t_article');
    $pdo->exec('CREATE TEMPORARY TABLE t_article (idx INT PRIMARY KEY, id INT UNSIGNED)
                SELECT ROW_NUMBER() OVER (ORDER BY ar.id) - 1 AS idx, ar.id FROM article ar
                  JOIN t_nb_emplacement n ON n.cond = ar.condition_stockage_id');
    $nbArticles = (int) $pdo->query('SELECT COUNT(*) FROM t_article')->fetchColumn();
    // magasiniers actifs
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS t_magasinier');
    $pdo->exec("CREATE TEMPORARY TABLE t_magasinier (idx INT PRIMARY KEY, id INT UNSIGNED)
                SELECT ROW_NUMBER() OVER (ORDER BY u.id) - 1 AS idx, u.id FROM utilisateur u
                  JOIN profil p ON p.id = u.profil_id WHERE p.code = 'magasinier' AND u.actif = 1");
    $nbMagasiniers = (int) $pdo->query('SELECT COUNT(*) FROM t_magasinier')->fetchColumn();

    printf("Mouvements de stock : %s → %s (%d articles, %d magasiniers)\n", number_format($deja, 0, ',', ' '), number_format($cibleMvt, 0, ',', ' '), $nbArticles, $nbMagasiniers);
    $couplesParRequete = LOT_SQL / 2;
    for ($k0 = intdiv($deja, 2); $k0 < $cibleMvt / 2; $k0 += $couplesParRequete) {
        $k1 = min($k0 + $couplesParRequete, intdiv($cibleMvt, 2));   // couples [k0, k1)
        $j0 = intdiv($k0, COUPLES_PAR_LOT);
        $j1 = intdiv($k1, COUPLES_PAR_LOT) - 1;                      // lots [j0, j1]
        $t0 = microtime(true);

        // couple k daté de : fin - 2 jours - k minutes (entrée), sortie le lendemain
        $pdo->exec("INSERT INTO lot (article_id, numero_lot_fournisseur, date_fabrication, date_limite_conso)
            SELECT a.id, 'ARCHIVE', DATE(TIMESTAMP('$fin') - INTERVAL 30 DAY - INTERVAL (s.seq * " . COUPLES_PAR_LOT . ") MINUTE),
                   DATE(TIMESTAMP('$fin') + INTERVAL 90 DAY - INTERVAL (s.seq * " . COUPLES_PAR_LOT . ") MINUTE)
              FROM seq_{$j0}_to_{$j1} s
              JOIN t_article a ON a.idx = s.seq % $nbArticles
             ORDER BY s.seq");
        $premierLot = (int) $pdo->lastInsertId();
        $dernierLot = (int) $pdo->query('SELECT MAX(id) FROM lot')->fetchColumn();
        if ($dernierLot - $premierLot !== $j1 - $j0) { exit("Identifiants de lots non consécutifs : arrêt.\n"); }

        $m0 = 2 * $k0;
        $m1 = 2 * $k1 - 1;
        $pdo->exec("INSERT INTO mouvement_stock (type, lot_id, quantite, emplacement_source_id, emplacement_destination_id, utilisateur_id, date_heure, motif)
            SELECT IF(s.seq % 2 = 0, 'entree', 'sortie'), l.id,
                   LEAST(ar.unites_par_colis * ar.colis_par_palette, 100),
                   IF(s.seq % 2 = 0, NULL, e.id), IF(s.seq % 2 = 0, e.id, NULL),
                   u.id,
                   TIMESTAMP('$fin') - INTERVAL 2 DAY - INTERVAL (s.seq DIV 2) MINUTE + INTERVAL (s.seq % 2) DAY,
                   'Archive'
              FROM seq_{$m0}_to_{$m1} s
              JOIN lot l ON l.id = $premierLot + (s.seq DIV 2) DIV " . COUPLES_PAR_LOT . " - $j0
              JOIN article ar ON ar.id = l.article_id
              JOIN t_nb_emplacement n ON n.cond = ar.condition_stockage_id
              JOIN t_emplacement e ON e.cond = n.cond AND e.idx = (s.seq DIV 2) % n.n
              JOIN t_magasinier u ON u.idx = (s.seq DIV 2) % $nbMagasiniers
             ORDER BY s.seq");
        printf("  %9s lignes  %s\n", number_format($m1 + 1, 0, ',', ' '), chrono($t0, $m1 - $m0 + 1));
    }
}

$pdo->query('ANALYZE TABLE rendez_vous, mouvement_stock, lot')->fetchAll();   // statistiques à jour pour l'optimiseur
foreach (['rendez_vous', 'mouvement_stock', 'lot', 'stock'] as $t) {
    printf("%-16s %12s lignes\n", $t, number_format((int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(), 0, ',', ' '));
}
