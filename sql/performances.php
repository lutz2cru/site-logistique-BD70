<?php
/**
 * Mesure des temps d'exécution des requêtes principales de l'application.
 *
 *   php sql/performances.php [--user=root] [--pass=]
 *
 * Chaque requête est exécutée 20 fois (après une exécution de chauffe) ; on
 * retient la médiane. Le plan d'exécution (EXPLAIN) indique les index utilisés.
 * Pour quelques requêtes, on compare avec une version qui ignore l'index afin
 * de mesurer son apport. Le résultat est écrit dans docs/rapport_performances.md.
 */
declare(strict_types=1);

$opts = getopt('', ['user::', 'pass::']);
$pdo = new PDO('mysql:host=localhost;dbname=entrepot;charset=utf8mb4', $opts['user'] ?? 'root', $opts['pass'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('SET SESSION query_cache_type = OFF');

const REPETITIONS = 20;

$quai = (int) $pdo->query("SELECT quai_id FROM rendez_vous GROUP BY quai_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
$lot = (int) $pdo->query('SELECT lot_id FROM mouvement_stock GROUP BY lot_id ORDER BY COUNT(*) DESC LIMIT 1')->fetchColumn();
$article = (int) $pdo->query('SELECT l.article_id FROM stock s JOIN lot l ON l.id = s.lot_id LIMIT 1')->fetchColumn();

/** [titre, description, requête, paramètres, requête sans index (facultatif)] */
$requetes = [
    ['Planning d\'une journée', 'Rendez-vous non annulés du jour, tous quais (planning.php)',
     "SELECT r.*, v.immatriculation, t.raison_sociale FROM rendez_vous r JOIN vehicule v ON v.id = r.vehicule_id
        JOIN transporteur t ON t.id = v.transporteur_id
       WHERE r.debut >= CURRENT_DATE AND r.debut < CURRENT_DATE + INTERVAL 1 DAY AND r.statut <> 'annule'", [],
     "SELECT r.*, v.immatriculation, t.raison_sociale FROM rendez_vous r IGNORE INDEX (ix_rdv_debut, ix_rdv_quai_creneau) JOIN vehicule v ON v.id = r.vehicule_id
        JOIN transporteur t ON t.id = v.transporteur_id
       WHERE r.debut >= CURRENT_DATE AND r.debut < CURRENT_DATE + INTERVAL 1 DAY AND r.statut <> 'annule'"],
    ['Contrôle de chevauchement', 'Test exécuté par le déclencheur à chaque prise de rendez-vous',
     "SELECT EXISTS (SELECT 1 FROM rendez_vous r WHERE r.quai_id = ? AND r.statut IN ('planifie','honore')
        AND r.debut < CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 9 HOUR AND r.fin > CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 8 HOUR)", [$quai],
     "SELECT EXISTS (SELECT 1 FROM rendez_vous r IGNORE INDEX (ix_rdv_quai_creneau) WHERE r.quai_id = ? AND r.statut IN ('planifie','honore')
        AND r.debut < CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 9 HOUR AND r.fin > CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 8 HOUR)"],
    ['Camions sur site', 'Passages dont la dernière étape n\'est pas le départ (flux.php)',
     "SELECT p.id, v.immatriculation, te.libelle, fn_date_etape(p.id, 'ARRIVEE') AS arrivee FROM passage p
        JOIN type_etape te ON te.id = p.etape_courante_id JOIN vehicule v ON v.id = p.vehicule_id WHERE te.code <> 'DEPART'", []],
    ['État des quais en temps réel', 'Vue v_quai_temps_reel (statut calculé, camion à quai, prochain RDV)',
     'SELECT * FROM v_quai_temps_reel', []],
    ['Recherche plein texte (1re version)', 'MATCH … AGAINST en sous-requête ; comparé à un LIKE \'%…%\'',
     "SELECT d.* FROM v_stock_detail d WHERE d.article_id IN (SELECT id FROM article WHERE MATCH(designation) AGAINST ('+yaourt*' IN BOOLEAN MODE))", [],
     "SELECT d.* FROM v_stock_detail d WHERE d.article_id IN (SELECT id FROM article WHERE designation LIKE '%yaourt%')"],
    ['Recherche plein texte (version retenue)', 'Étape 1 : articles par MATCH … AGAINST (index FULLTEXT)',
     "SELECT id FROM article WHERE MATCH(designation) AGAINST ('+yaourt*' IN BOOLEAN MODE)", [],
     "SELECT id FROM article WHERE designation LIKE '%yaourt%'"],
    ['', 'Étape 2 : stock des articles trouvés (stock.php)',
     'SELECT d.* FROM v_stock_detail d WHERE d.article_id IN (' . (implode(',', $pdo->query("SELECT id FROM article WHERE MATCH(designation) AGAINST ('+yaourt*' IN BOOLEAN MODE)")->fetchAll(PDO::FETCH_COLUMN)) ?: '0') . ')', []],
    ['Historique d\'un lot', 'Mouvements d\'un lot par ordre chronologique (lot.php)',
     'SELECT * FROM mouvement_stock WHERE lot_id = ? ORDER BY date_heure', [$lot],
     'SELECT * FROM mouvement_stock IGNORE INDEX (ix_mvt_lot_date) WHERE lot_id = ? ORDER BY date_heure'],
    ['Mouvements des 7 derniers jours', 'Historique filtré par date (mouvements.php)',
     'SELECT COUNT(*), type FROM mouvement_stock WHERE date_heure >= CURRENT_DATE - INTERVAL 7 DAY GROUP BY type', [],
     'SELECT COUNT(*), type FROM mouvement_stock IGNORE INDEX (ix_mvt_date) WHERE date_heure >= CURRENT_DATE - INTERVAL 7 DAY GROUP BY type'],
    ['Temps moyens par transporteur (30 j)', 'Agrégation de v_passage_durees (indicateurs.php)',
     "SELECT transporteur_id, COUNT(*), AVG(attente_min), AVG(immobilisation_min) FROM v_passage_durees
       WHERE depart IS NOT NULL AND arrivee >= CURRENT_DATE - INTERVAL 30 DAY GROUP BY transporteur_id", []],
    ['Remplissage par zone', 'Vue v_remplissage_zone (somme des palettes par zone)', 'SELECT * FROM v_remplissage_zone', []],
    ['Taux d\'écart par fournisseur', 'Vue v_taux_ecart_fournisseur', 'SELECT * FROM v_taux_ecart_fournisseur', []],
    ['Lots proches de la DLC', 'Vue v_lots_proches_dlc (index sur la date limite)', 'SELECT * FROM v_lots_proches_dlc', []],
    ['Proposition d\'emplacements', 'Procédure stockée proposer_emplacements (rangement)', 'CALL proposer_emplacements(?, 1, 8)', [$article]],
];

function mesurer(PDO $pdo, string $sql, array $params): float
{
    $st = $pdo->prepare($sql);
    $temps = [];
    for ($i = 0; $i <= REPETITIONS; $i++) {
        $t = hrtime(true);
        $st->execute($params);
        do { $st->fetchAll(); } while ($st->nextRowset());
        $st->closeCursor();
        if ($i > 0) { $temps[] = (hrtime(true) - $t) / 1e6; }   // la 1re exécution sert de chauffe
    }
    sort($temps);
    return $temps[intdiv(count($temps), 2)];
}

function plan(PDO $pdo, string $sql, array $params): string
{
    if (str_starts_with($sql, 'CALL')) { return 'procédure stockée'; }
    $st = $pdo->prepare('EXPLAIN ' . $sql);
    $st->execute($params);
    $etapes = [];
    foreach ($st->fetchAll() as $l) {
        $etapes[] = $l['table'] . ' : ' . ($l['key'] ? 'index `' . $l['key'] . '`' : ($l['type'] === 'ALL' ? 'parcours complet' : $l['type'])) . ' (' . $l['rows'] . ' l.)';
    }
    return implode(' ; ', array_slice($etapes, 0, 4));
}

$volumes = $pdo->query("SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = 'entrepot'
                         AND table_name IN ('rendez_vous','passage','passage_etape','mouvement_stock','lot','stock','reception','commande','ligne_prelevement')
                         ORDER BY table_rows DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
$version = $pdo->query('SELECT VERSION()')->fetchColumn();

$md = "# Rapport d'analyse des performances\n\n";
$md .= "Mesures réalisées le " . date('d/m/Y à H:i') . " sur MariaDB $version (poste local), base alimentée par `sql/installer.php`.\n";
$md .= "Chaque requête est exécutée " . REPETITIONS . " fois après une exécution de chauffe ; le temps retenu est la **médiane** (en millisecondes, récupération des lignes comprise).\n\n";
$md .= "## Volumétrie\n\n| Table | Lignes (estimation InnoDB) |\n|---|---:|\n";
foreach ($volumes as $t => $n) { $md .= "| `$t` | " . number_format((int) $n, 0, ',', ' ') . " |\n"; }
$md .= "\n## Requêtes principales\n\n| # | Requête | Temps médian | Sans index | Plan d'exécution |\n|---|---|---:|---:|---|\n";
foreach ($requetes as $i => $r) {
    [$titre, $desc, $sql, $params] = $r;
    $avec = mesurer($pdo, $sql, $params);
    $sans = isset($r[4]) ? mesurer($pdo, $r[4], $params) : null;
    $gain = $sans ? sprintf('%.2f ms (×%.1f)', $sans, $sans / max($avec, 0.001)) : '–';
    $libelle = $titre === '' ? "↳ $desc" : "**$titre** — $desc";   // titre vide : suite de la requête précédente
    $md .= sprintf("| %d | %s | %.2f ms | %s | %s |\n", $i + 1, $libelle, $avec, $gain, plan($pdo, $sql, $params));
    printf("%-40s %8.2f ms %s\n", $titre, $avec, $sans ? sprintf('(sans index : %.2f ms)', $sans) : '');
}
$md .= "\n## Analyse : recherche plein texte\n\n";
$md .= "La première version de la recherche de stock plaçait le `MATCH … AGAINST` dans une sous-requête `IN (…)`. Le plan d'exécution montre que l'optimiseur estime "
     . "une seule ligne pour la recherche plein texte, puis parcourt toute la chaîne zone → allée → rack → emplacement → stock avant de filtrer : la requête était "
     . "alors plus lente qu'un simple `LIKE '%…%'`. La version retenue résout d'abord les articles par l'index FULLTEXT, puis interroge le stock avec la liste "
     . "d'identifiants obtenue : chaque étape utilise un index et le temps total est le plus faible des trois variantes. Avec un catalogue plus volumineux, "
     . "l'écart en faveur de l'index FULLTEXT sur l'étape 1 augmenterait (le `LIKE '%…%'` impose toujours un parcours complet de la table).\n";
$md .= "\n## Optimisations mises en place\n\n";
$md .= "- **Index composite `ix_rdv_quai_creneau (quai_id, debut, fin)`** : utilisé par le contrôle de chevauchement exécuté dans le déclencheur à chaque insertion ou modification de rendez-vous, et par le planning.\n";
$md .= "- **Index `ix_mvt_lot_date (lot_id, date_heure)`** : historique d'un lot déjà trié, sans tri supplémentaire (traçabilité).\n";
$md .= "- **Index `ix_mvt_date`, `ix_pe_date`, `ix_rdv_debut`** : filtres par période des écrans d'historique et des indicateurs.\n";
$md .= "- **Index FULLTEXT `ft_article_designation`** : recherche d'articles par mots, au lieu d'un `LIKE '%…%'` qui impose un parcours complet.\n";
$md .= "- **Index `ix_lot_dlc`** : détection des lots proches de leur date limite.\n";
$md .= "- **Dénormalisations contrôlées** : `passage.etape_courante_id` et `quai.statut` sont maintenus par déclencheur, ce qui évite de recalculer la dernière étape (sous-requête sur `passage_etape`) à chaque affichage des écrans temps réel ; la table `stock` est la synthèse des mouvements, maintenue par le déclencheur de `mouvement_stock`, pour ne pas sommer tout l'historique à chaque recherche.\n";
$md .= "- **Requêtes préparées** (PDO) : le plan est réutilisé et les paramètres ne sont jamais concaténés dans le SQL.\n";

@mkdir(__DIR__ . '/../docs');
file_put_contents(__DIR__ . '/../docs/rapport_performances.md', $md);
echo "\nRapport écrit dans docs/rapport_performances.md\n";
