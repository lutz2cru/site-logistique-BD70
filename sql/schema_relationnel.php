<?php
/**
 * Génère docs/schema_relationnel.md (modèle logique) à partir de la base installée :
 * clé primaire soulignée, clés étrangères préfixées par #, avec la table référencée.
 *
 *   php sql/schema_relationnel.php
 */
declare(strict_types=1);

$pdo = new PDO('mysql:host=localhost;dbname=information_schema;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$base = 'entrepot';

$tables = $pdo->query("SELECT table_name FROM tables WHERE table_schema = '$base' AND table_type = 'BASE TABLE' AND table_name NOT LIKE 'seq\_%' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$colonnes = $pdo->query("SELECT table_name, column_name, column_type, is_nullable, column_key FROM columns
                          WHERE table_schema = '$base' ORDER BY table_name, ordinal_position")->fetchAll(PDO::FETCH_GROUP);
$pk = [];
foreach ($pdo->query("SELECT table_name, column_name FROM key_column_usage WHERE table_schema = '$base' AND constraint_name = 'PRIMARY'") as $r) {
    $pk[$r['table_name']][$r['column_name']] = true;
}
$fk = [];
foreach ($pdo->query("SELECT table_name, column_name, referenced_table_name FROM key_column_usage
                       WHERE table_schema = '$base' AND referenced_table_name IS NOT NULL") as $r) {
    $fk[$r['table_name']][$r['column_name']] = $r['referenced_table_name'];
}
$uniques = [];
foreach ($pdo->query("SELECT table_name, column_name FROM statistics WHERE table_schema = '$base' AND non_unique = 0 AND index_name <> 'PRIMARY'") as $r) {
    $uniques[$r['table_name']][$r['column_name']] = true;
}

$md = "# Modèle logique — schéma relationnel\n\n";
$md .= "Généré automatiquement depuis la base `$base` (" . count($tables) . " tables). Notation : <u>clé primaire</u>, #clé étrangère (→ table référencée), *unique*.\n\n";
$jonctions = [];
foreach ($tables as $t) {
    if ($t === 'journal_audit_archive') { continue; }
    $attrs = [];
    foreach ($colonnes[$t] as $c) {
        $n = $c['column_name'];
        $txt = isset($fk[$t][$n]) ? "#$n" : $n;
        if (isset($pk[$t][$n])) { $txt = "<u>$txt</u>"; }
        elseif (isset($uniques[$t][$n])) { $txt = "*$txt*"; }
        $attrs[] = $txt;
    }
    $md .= "- **$t** (" . implode(', ', $attrs) . ")\n";
    $refs = array_map(fn($col, $ref) => "`$col` → $ref", array_keys($fk[$t] ?? []), $fk[$t] ?? []);
    if ($refs) { $md .= "  - références : " . implode(', ', $refs) . "\n"; }
    if (count($pk[$t] ?? []) > 1) { $jonctions[] = $t; }
}
$md .= "\n`journal_audit_archive` a la même structure que `journal_audit`.\n";
$md .= "\n## Tables de jointure (associations N:N)\n\n";
$md .= "Clé primaire composée des clés étrangères : " . implode(', ', array_map(fn($t) => "`$t`", $jonctions)) . ".\n";
$md .= "\n## Remarques de normalisation (3FN)\n\n";
$md .= "- Chaque attribut non clé dépend de toute la clé et uniquement d'elle ; les libellés répétitifs (profils, types de véhicule, étapes, conditions de stockage, équipements, habilitations) sont isolés dans des tables de référence.\n";
$md .= "- Les coordonnées (adresse, code postal, ville) sont stockées telles qu'imprimées sur les documents ; la ville n'est pas déduite du code postal (plusieurs communes partagent un même code).\n";
$md .= "- Trois dénormalisations sont assumées et maintenues **uniquement par déclencheur** : `passage.etape_courante_id` (dernière ligne de `passage_etape`), `quai.statut` (occupé / libre) et la table `stock` (synthèse de `mouvement_stock`). Elles évitent des agrégations coûteuses dans les écrans temps réel (voir `rapport_performances.md`).\n";
$md .= "- `passage.sens` peut paraître redondant avec `rendez_vous.sens`, mais il est indispensable pour les arrivées sans rendez-vous (`rendez_vous_id` NULL).\n";

@mkdir(__DIR__ . '/../docs');
file_put_contents(__DIR__ . '/../docs/schema_relationnel.md', $md);
echo "docs/schema_relationnel.md écrit (" . count($tables) . " tables).\n";
