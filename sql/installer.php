<?php
/**
 * Script d'installation de la base « entrepot ».
 *
 *   php sql/installer.php [--host=localhost] [--user=root] [--pass=]
 *
 * 1. exécute 01_schema.sql puis 02_automatisation.sql ;
 * 2. insère les données de référence (profils, étapes, zones, quais…) ;
 * 3. simule environ deux mois d'activité de l'entrepôt (rendez-vous,
 *    passages de camions, réceptions, mouvements de stock, commandes) en
 *    passant par les déclencheurs et procédures de la base : les données
 *    factices respectent donc toutes les règles métier.
 *
 * Tous les comptes de démonstration ont le mot de passe « demo1234 »
 * (voir README.md).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Ce script s'exécute en ligne de commande : php sql/installer.php\n");
}

$opts = getopt('', ['host::', 'user::', 'pass::']);
$host = $opts['host'] ?? 'localhost';
$user = $opts['user'] ?? 'root';
$pass = $opts['pass'] ?? '';

const MOT_DE_PASSE_DEMO = 'demo1234';
const JOURS_HISTORIQUE  = 60;
const JOURS_FUTURS      = 7;

mt_srand(70);   // données reproductibles
$debutChrono = microtime(true);

$pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// ---------------------------------------------------------------------
//  1. Schéma et automatisation
// ---------------------------------------------------------------------

/** Exécute un fichier SQL en gérant la directive DELIMITER (comme le client mysql). */
function executerFichierSql(PDO $pdo, string $chemin): void
{
    $delimiteur = ';';
    $tampon = '';
    foreach (file($chemin) as $ligne) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)/i', $ligne, $m)) {
            $delimiteur = $m[1];
            continue;
        }
        $tampon .= $ligne;
        if (str_ends_with(rtrim($ligne), $delimiteur)) {
            $requete = trim(substr(rtrim($tampon), 0, -strlen($delimiteur)));
            if ($requete !== '' && !preg_match('/^(--[^\n]*\s*)+$/', $requete)) {
                $pdo->exec($requete);
            }
            $tampon = '';
        }
    }
}

echo "Création du schéma...\n";
executerFichierSql($pdo, __DIR__ . '/01_schema.sql');
executerFichierSql($pdo, __DIR__ . '/02_automatisation.sql');
$pdo->exec('USE entrepot');
try {
    $pdo->exec('SET GLOBAL event_scheduler = ON');
} catch (PDOException) {
    echo "  (impossible d'activer event_scheduler : droits insuffisants)\n";
}

// ---------------------------------------------------------------------
//  Utilitaires
// ---------------------------------------------------------------------
function alea(array $t): mixed { return $t[array_rand($t)]; }
function proba(float $p): bool { return mt_rand() / mt_getrandmax() < $p; }
function entre(int $a, int $b): int { return mt_rand($a, $b); }

function inserer(PDO $pdo, string $table, array $ligne): int
{
    static $cache = [];
    $cle = $table . ':' . implode(',', array_keys($ligne));
    if (!isset($cache[$cle])) {
        $cols = implode(', ', array_keys($ligne));
        $ph = implode(', ', array_fill(0, count($ligne), '?'));
        $cache[$cle] = $pdo->prepare("INSERT INTO $table ($cols) VALUES ($ph)");
    }
    $cache[$cle]->execute(array_values($ligne));
    return (int) $pdo->lastInsertId();
}

function siret(): string { return (string) entre(100, 999) . str_pad((string) entre(0, 99999999999), 11, '0', STR_PAD_LEFT); }
function telephone(): string { return '0' . entre(1, 7) . ' ' . implode(' ', array_map(fn() => sprintf('%02d', entre(0, 99)), range(1, 4))); }
function dt(DateTimeImmutable $d): string { return $d->format('Y-m-d H:i:s'); }
function plusMin(DateTimeImmutable $d, int $min): DateTimeImmutable { return $d->modify(($min >= 0 ? '+' : '') . $min . ' minutes'); }

$noms = ['Martin', 'Bernard', 'Thomas', 'Petit', 'Robert', 'Richard', 'Durand', 'Dubois', 'Moreau', 'Laurent',
    'Simon', 'Michel', 'Lefebvre', 'Leroy', 'Roux', 'David', 'Bertrand', 'Morel', 'Fournier', 'Girard', 'Bonnet',
    'Dupont', 'Lambert', 'Fontaine', 'Rousseau', 'Vincent', 'Muller', 'Lefevre', 'Faure', 'Andre', 'Mercier',
    'Blanc', 'Guerin', 'Boyer', 'Garnier', 'Chevalier', 'Francois', 'Legrand', 'Gauthier', 'Garcia', 'Perrin',
    'Robin', 'Clement', 'Morin', 'Nicolas', 'Henry', 'Roussel', 'Mathieu', 'Gautier', 'Masson', 'Marchand'];
$prenoms = ['Lucas', 'Hugo', 'Louis', 'Nathan', 'Léo', 'Jules', 'Emma', 'Léa', 'Chloé', 'Manon', 'Camille', 'Sarah',
    'Inès', 'Julie', 'Thomas', 'Paul', 'Antoine', 'Maxime', 'Nicolas', 'Julien', 'Karim', 'Yanis', 'Sofia', 'Nadia',
    'Mehdi', 'Pierre', 'Marie', 'Claire', 'Sophie', 'Laura', 'Kevin', 'Romain', 'Alexandre', 'Anaïs', 'Mathilde'];
$villes = [['Belfort', '90000'], ['Montbéliard', '25200'], ['Mulhouse', '68100'], ['Besançon', '25000'],
    ['Dijon', '21000'], ['Strasbourg', '67000'], ['Colmar', '68000'], ['Lyon', '69003'], ['Nancy', '54000'],
    ['Metz', '57000'], ['Vesoul', '70000'], ['Héricourt', '70400'], ['Audincourt', '25400'], ['Sochaux', '25600']];
$rues = ['rue de la Gare', 'avenue Jean Jaurès', 'rue des Entrepreneurs', 'ZI des Grands Bois', 'rue de l\'Industrie',
    'boulevard Kennedy', 'ZA de la Praille', 'rue du Port', 'chemin des Prés', 'route de Delle'];

function adresse(array $rues, array $villes): array
{
    [$ville, $cp] = alea($villes);
    return [entre(1, 180) . ' ' . alea($rues), $cp, $ville];
}

// ---------------------------------------------------------------------
//  2. Données de référence
// ---------------------------------------------------------------------
echo "Données de référence...\n";
$pdo->beginTransaction();

$profils = [
    'admin'        => ['Administrateur', 'Gestion des comptes, des droits et du référentiel'],
    'responsable'  => ['Responsable d\'exploitation', 'Planification des quais, pilotage de l\'activité'],
    'accueil'      => ['Agent d\'accueil', 'Poste de garde : arrivées et départs des camions'],
    'magasinier'   => ['Magasinier / Préparateur', 'Réception, stockage et préparation des commandes'],
    'transporteur' => ['Transporteur', 'Accès limité à la prise de rendez-vous'],
];
$profilId = [];
foreach ($profils as $code => [$lib, $desc]) {
    $profilId[$code] = inserer($pdo, 'profil', ['code' => $code, 'libelle' => $lib, 'description' => $desc]);
}

$etapes = [
    'ARRIVEE'    => 'Arrivée au poste de garde',
    'CONTROLE'   => 'Contrôle des documents',
    'PARKING'    => 'Mise en attente sur le parking',
    'APPEL_QUAI' => 'Appel à quai',
    'DEBUT_OP'   => 'Début du (dé)chargement',
    'FIN_OP'     => 'Fin du (dé)chargement',
    'DEPART'     => 'Départ du site',
];
$etapeId = [];
$ordre = 0;
foreach ($etapes as $code => $lib) {
    $etapeId[$code] = inserer($pdo, 'type_etape', ['code' => $code, 'libelle' => $lib, 'ordre' => ++$ordre]);
}

$typesVehicule = ['Semi-remorque', 'Porteur 19 t', 'Porteur 12 t', 'Fourgon'];
$typeVehId = [];
foreach ($typesVehicule as $t) {
    $typeVehId[$t] = inserer($pdo, 'type_vehicule', ['libelle' => $t]);
}

$equipements = ['Niveleur', 'Hayon élévateur', 'Quai frigorifique', 'Sas d\'étanchéité', 'Butoirs', 'Rampe mobile'];
$equipId = [];
foreach ($equipements as $e) {
    $equipId[$e] = inserer($pdo, 'equipement', ['libelle' => $e]);
}

$habilitations = [
    'ADR'      => 'Transport de matières dangereuses (ADR base)',
    'ADR-CIT'  => 'ADR citerne',
    'FIMO'     => 'Formation initiale minimale obligatoire',
    'FCO'      => 'Formation continue obligatoire',
    'FRIGO'    => 'Transport sous température dirigée',
    'CACES-R3' => 'CACES R489 cat. 3 (chariot élévateur)',
];
$habId = [];
foreach ($habilitations as $code => $lib) {
    $habId[$code] = inserer($pdo, 'habilitation', ['code' => $code, 'libelle' => $lib]);
}

$conditions = [
    'Ambiant'       => [15, 25],
    'Froid positif' => [2, 8],
    'Surgelé'       => [-25, -18],
    'Dangereux'     => [5, 30],
];
$condId = [];
foreach ($conditions as $lib => [$min, $max]) {
    $condId[$lib] = inserer($pdo, 'condition_stockage', ['libelle' => $lib, 'temperature_min' => $min, 'temperature_max' => $max]);
}

// Structure physique : zone → allées → racks → niveaux → emplacements
$zones = [
    'AMB' => ['Zone ambiante', ['Ambiant'], 9],
    'FRO' => ['Zone froid positif', ['Froid positif'], 3],
    'SUR' => ['Zone surgelés', ['Surgelé'], 2],
    'DGX' => ['Zone produits dangereux', ['Dangereux', 'Ambiant'], 1],
];
$emplacements = [];      // id => ['cap' => palettes, 'zone' => code, 'occ' => [lot_id => qte], 'bloque' => bool]
$zoneConditions = [];    // code zone => [condition_id]
foreach ($zones as $code => [$lib, $conds, $nbAllees]) {
    $zid = inserer($pdo, 'zone', ['code' => $code, 'libelle' => $lib]);
    foreach ($conds as $c) {
        inserer($pdo, 'zone_condition', ['zone_id' => $zid, 'condition_stockage_id' => $condId[$c]]);
        $zoneConditions[$code][] = $condId[$c];
    }
    for ($a = 1; $a <= $nbAllees; $a++) {
        $codeAllee = sprintf('%s-A%02d', $code, $a);
        $aid = inserer($pdo, 'allee', ['zone_id' => $zid, 'code' => $codeAllee]);
        for ($r = 1; $r <= 10; $r++) {
            $niveaux = $code === 'AMB' ? 5 : 4;
            $codeRack = sprintf('%s-R%02d', $codeAllee, $r);
            $rid = inserer($pdo, 'rack', ['allee_id' => $aid, 'code' => $codeRack, 'nb_niveaux' => $niveaux]);
            for ($n = 1; $n <= $niveaux; $n++) {
                for ($p = 1; $p <= 2; $p++) {
                    $cap = $n === 1 ? 2 : 1;   // les emplacements au sol accueillent 2 palettes
                    $eid = inserer($pdo, 'emplacement', [
                        'rack_id' => $rid, 'code' => sprintf('%s-N%d-P%d', $codeRack, $n, $p), 'niveau' => $n,
                        'capacite_palettes' => $cap, 'poids_max_kg' => $n === 1 ? 2000 : 1000, 'bloque' => 0,
                    ]);
                    $emplacements[$eid] = ['cap' => $cap, 'zone' => $code, 'occ' => [], 'niveau' => $n];
                }
            }
        }
    }
}

// Quais
$quais = [];   // id => ['type' => ..., 'types_veh' => [...]]
$defQuais = [];
for ($i = 1; $i <= 16; $i++) {
    $type = $i <= 6 ? 'reception' : ($i <= 12 ? 'expedition' : 'mixte');
    $frigo = $i >= 15;
    $accepte = $type === 'mixte' ? $typesVehicule : ['Semi-remorque', 'Porteur 19 t', 'Porteur 12 t'];
    $equip = ['Niveleur', 'Butoirs'];
    if ($frigo) { $equip[] = 'Quai frigorifique'; $equip[] = 'Sas d\'étanchéité'; }
    if ($type === 'mixte') { $equip[] = 'Rampe mobile'; }
    $qid = inserer($pdo, 'quai', ['numero' => sprintf('Q%02d', $i), 'type' => $type]);
    foreach ($equip as $e) { inserer($pdo, 'quai_equipement', ['quai_id' => $qid, 'equipement_id' => $equipId[$e]]); }
    foreach ($accepte as $t) { inserer($pdo, 'quai_type_vehicule', ['quai_id' => $qid, 'type_vehicule_id' => $typeVehId[$t]]); }
    $quais[$qid] = ['type' => $type, 'types_veh' => array_map(fn($t) => $typeVehId[$t], $accepte), 'numero' => sprintf('Q%02d', $i)];
}
$quaiHorsService = array_key_last($quais) - 4;   // Q12 : mis hors service à la fin de la simulation

// ---------------------------------------------------------------------
//  Transporteurs, chauffeurs, véhicules
// ---------------------------------------------------------------------
$nomsTransporteurs = ['Transports Girard', 'TransEst Logistique', 'Rhin Express', 'Groupe Mauffrey', 'Jura Fret',
    'Alsace Route Services', 'Franche-Comté Messagerie', 'Vosges Transports', 'Dachser Belfort', 'Trans Doubs',
    'Lorraine Fret', 'Messageries du Sundgau', 'Bourgogne Transports Frigorifiques', 'Express Montbéliard',
    'Kuehne Route Est', 'Saône Logistique', 'TLR Haguenau', 'Ferroux & Fils'];
$transporteurs = [];   // id => ['ponctualite' => facteur, 'chauffeurs' => [], 'vehicules' => [type_id => [ids]]]
$immatUtilisees = [];
foreach ($nomsTransporteurs as $idx => $nom) {
    [$adr, $cp, $ville] = adresse($rues, $villes);
    $slug = strtolower(preg_replace('/[^a-z]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', $nom)));
    $tid = inserer($pdo, 'transporteur', [
        'siret' => siret(), 'raison_sociale' => $nom, 'adresse' => $adr, 'code_postal' => $cp, 'ville' => $ville,
        'telephone' => telephone(), 'email' => "exploitation@$slug.test",
    ]);
    $transporteurs[$tid] = ['ponctualite' => [0.4, 0.8, 1.0, 1.0, 1.3, 1.8, 2.5][$idx % 7], 'chauffeurs' => [], 'vehicules' => [], 'nom' => $nom, 'slug' => $slug];

    for ($c = 0, $n = entre(1, 3); $c < $n; $c++) {
        $p = alea($prenoms); $nm = alea($noms);
        inserer($pdo, 'contact', [
            'transporteur_id' => $tid, 'nom' => $nm, 'prenom' => $p,
            'fonction' => alea(['Responsable exploitation', 'Affréteur', 'Commercial', 'Gestionnaire de flotte']),
            'telephone' => telephone(),
            'email' => strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', "$p.$nm")) . ".$c@$slug.test",
        ]);
    }
    for ($c = 0, $n = entre(1, 2); $c < $n; $c++) {
        $debut = (new DateTimeImmutable())->modify('-' . entre(100, 1000) . ' days');
        inserer($pdo, 'contrat', [
            'reference' => sprintf('CT-%03d-%d', $tid, $c + 1), 'transporteur_id' => $tid,
            'objet' => alea(['Contrat cadre de transport régional', 'Affrètement ponctuel', 'Navettes inter-sites', 'Distribution température dirigée']),
            'date_debut' => $debut->format('Y-m-d'),
            'date_fin' => proba(0.3) ? $debut->modify('+2 years')->format('Y-m-d') : null,
            'montant_annuel' => entre(20, 450) * 1000,
        ]);
    }
    for ($c = 0, $n = entre(4, 8); $c < $n; $c++) {
        $chid = inserer($pdo, 'chauffeur', [
            'transporteur_id' => $tid, 'nom' => alea($noms), 'prenom' => alea($prenoms), 'telephone' => telephone(),
            'numero_permis' => sprintf('%02d%02d%06d', entre(10, 99), entre(1, 12), entre(0, 999999)),
            'categorie_permis' => alea(['C', 'CE', 'CE', 'C1']),
            'validite_permis' => (new DateTimeImmutable())->modify('+' . entre(-20, 1500) . ' days')->format('Y-m-d'),
        ]);
        $transporteurs[$tid]['chauffeurs'][] = $chid;
        foreach ((array) array_rand($habId, entre(1, 3)) as $h) {
            $obt = (new DateTimeImmutable())->modify('-' . entre(30, 1500) . ' days');
            inserer($pdo, 'chauffeur_habilitation', [
                'chauffeur_id' => $chid, 'habilitation_id' => $habId[$h], 'date_obtention' => $obt->format('Y-m-d'),
                'date_expiration' => $obt->modify('+5 years')->format('Y-m-d'),
            ]);
        }
    }
    for ($c = 0, $n = entre(5, 9); $c < $n; $c++) {
        $type = alea(['Semi-remorque', 'Semi-remorque', 'Porteur 19 t', 'Porteur 12 t', 'Fourgon']);
        do {
            $immat = sprintf('%s-%03d-%s', chr(entre(65, 90)) . chr(entre(65, 90)), entre(1, 999), chr(entre(65, 90)) . chr(entre(65, 90)));
        } while (isset($immatUtilisees[$immat]));
        $immatUtilisees[$immat] = true;
        [$poids, $pal] = match ($type) {
            'Semi-remorque' => [25000, 33], 'Porteur 19 t' => [10000, 18], 'Porteur 12 t' => [6000, 14], 'Fourgon' => [1200, 4],
        };
        $vid = inserer($pdo, 'vehicule', [
            'immatriculation' => $immat, 'transporteur_id' => $tid, 'type_vehicule_id' => $typeVehId[$type],
            'poids_max_kg' => $poids, 'palettes_max' => $pal, 'frigorifique' => (int) proba(0.25),
            'hayon' => (int) ($type !== 'Semi-remorque' && proba(0.6)),
        ]);
        $transporteurs[$tid]['vehicules'][$typeVehId[$type]][] = $vid;
    }
}

// ---------------------------------------------------------------------
//  Utilisateurs (mot de passe commun : demo1234)
// ---------------------------------------------------------------------
$hash = password_hash(MOT_DE_PASSE_DEMO, PASSWORD_DEFAULT);
$utilisateurs = ['magasinier' => [], 'responsable' => [], 'accueil' => []];
$comptesDemo = [
    ['admin@entrepot.test', 'Admin', 'Système', 'admin'],
    ['responsable@entrepot.test', 'Lombard', 'Alexandre', 'responsable'],
    ['accueil@entrepot.test', 'Garnier', 'Inès', 'accueil'],
    ['magasinier@entrepot.test', 'Morel', 'Hugo', 'magasinier'],
];
foreach ($comptesDemo as [$email, $nom, $prenom, $profil]) {
    $id = inserer($pdo, 'utilisateur', ['email' => $email, 'nom' => $nom, 'prenom' => $prenom, 'telephone' => telephone(),
        'mot_de_passe_hash' => $hash, 'actif' => 1, 'profil_id' => $profilId[$profil],
        'date_creation' => dt((new DateTimeImmutable())->modify('-90 days'))]);
    if (isset($utilisateurs[$profil])) { $utilisateurs[$profil][] = $id; }
}
foreach (['responsable' => 2, 'accueil' => 3, 'magasinier' => 13] as $profil => $n) {
    for ($i = 1; $i <= $n; $i++) {
        $p = alea($prenoms); $nm = alea($noms);
        $id = inserer($pdo, 'utilisateur', [
            'email' => strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', "$p.$nm")) . ".$profil$i@entrepot.test",
            'nom' => $nm, 'prenom' => $p, 'telephone' => telephone(), 'mot_de_passe_hash' => $hash, 'actif' => 1,
            'profil_id' => $profilId[$profil], 'date_creation' => dt((new DateTimeImmutable())->modify('-' . entre(60, 400) . ' days')),
        ]);
        $utilisateurs[$profil][] = $id;
    }
}
$tidTransporteurDemo = array_key_first($transporteurs);
inserer($pdo, 'utilisateur', ['email' => 'transporteur@entrepot.test', 'nom' => 'Girard', 'prenom' => 'Paul',
    'telephone' => telephone(), 'mot_de_passe_hash' => $hash, 'actif' => 1, 'profil_id' => $profilId['transporteur'],
    'transporteur_id' => $tidTransporteurDemo]);
foreach (array_slice(array_keys($transporteurs), 1, 4) as $tid) {
    inserer($pdo, 'utilisateur', ['email' => "planning@{$transporteurs[$tid]['slug']}.test", 'nom' => alea($noms),
        'prenom' => alea($prenoms), 'mot_de_passe_hash' => $hash, 'actif' => 1,
        'profil_id' => $profilId['transporteur'], 'transporteur_id' => $tid]);
}
// inscriptions en attente de validation
foreach ([['Rousseau', 'Camille'], ['Faure', 'Mehdi']] as [$nm, $p]) {
    inserer($pdo, 'utilisateur', ['email' => strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', "$p.$nm")) . '@entrepot.test',
        'nom' => $nm, 'prenom' => $p, 'mot_de_passe_hash' => $hash, 'actif' => 0, 'profil_id' => $profilId['magasinier']]);
}

// ---------------------------------------------------------------------
//  Clients, fournisseurs, articles
// ---------------------------------------------------------------------
$nomsClients = ['Brasserie de l\'Est', 'Fromagerie Comtoise', 'Peugeot Pièces de Rechange', 'BricoVosges',
    'Laiterie du Sundgau', 'Pharma Rhin', 'Jouets Montbéliard', 'Chimie Belfortaine', 'Épicerie Fine d\'Alsace',
    'Surgelés du Jura', 'Électroménager Doubs', 'Textile Lorraine', 'Cosmétiques Vosgiens', 'Papeterie Franc-Comtoise'];
$clients = [];
foreach ($nomsClients as $nom) {
    [$adr, $cp, $ville] = adresse($rues, $villes);
    $clients[] = inserer($pdo, 'client', ['siret' => siret(), 'raison_sociale' => $nom, 'adresse' => $adr,
        'code_postal' => $cp, 'ville' => $ville, 'telephone' => telephone(),
        'email' => 'logistique@' . strtolower(preg_replace('/[^a-z]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', $nom))) . '.test']);
}
$fournisseurs = [];
for ($i = 1; $i <= 30; $i++) {
    [$adr, $cp, $ville] = adresse($rues, $villes);
    $nom = alea(['Ets', 'Société', 'Groupe', 'Manufacture', 'Laboratoires', 'Industries']) . ' ' . alea($noms) . ($i > 20 ? ' & Cie' : '');
    $fournisseurs[] = inserer($pdo, 'fournisseur', ['siret' => siret(), 'raison_sociale' => $nom, 'adresse' => $adr,
        'code_postal' => $cp, 'ville' => $ville, 'telephone' => telephone(), 'email' => "commandes$i@fournisseur.test"]);
}

$familles = [
    'Ambiant' => [['Pack eau minérale', 'Jus de pomme', 'Biscuits', 'Café moulu', 'Pâtes', 'Conserves de légumes',
        'Papier A4', 'Cartons de déménagement', 'Plaquettes de frein', 'Filtre à huile', 'Perceuse', 'Peinture',
        'Lessive', 'Shampoing', 'Jouet en bois', 'Grille-pain', 'T-shirt coton', 'Bière blonde', 'Farine', 'Huile d\'olive'],
        ['500 g', '1 L', '6 x 1,5 L', '250 g', 'carton de 12', 'lot de 5', '2 kg', 'modèle standard', 'grand modèle', 'premium']],
    'Froid positif' => [['Yaourt nature', 'Comté AOP', 'Beurre doux', 'Crème fraîche', 'Jambon blanc', 'Salade composée',
        'Lait frais', 'Fromage blanc', 'Vaccin', 'Insuline'], ['x 4', '200 g', '500 g', '1 kg', 'tranché', 'bio']],
    'Surgelé' => [['Pizza surgelée', 'Frites', 'Glace vanille', 'Légumes poêlés', 'Poisson pané', 'Croissants crus'],
        ['1 kg', '2,5 kg', 'x 10', 'familial', '750 g']],
    'Dangereux' => [['Solvant industriel', 'Aérosol peinture', 'Acide chlorhydrique', 'Batterie lithium', 'Bouteille de gaz', 'Diluant'],
        ['5 L', '20 L', '400 mL', 'bidon', 'carton de 6']],
];
$repartition = ['Ambiant' => 290, 'Froid positif' => 60, 'Surgelé' => 30, 'Dangereux' => 20];
$articles = [];   // id => ['cond' => libellé, 'par_palette' => n, 'upc' => unités/colis, 'poids' => kg]
$numRef = 0;
foreach ($repartition as $cond => $n) {
    for ($i = 0; $i < $n; $i++) {
        $upc = alea([1, 4, 6, 12, 24]);
        $cpp = alea([20, 30, 40, 48, 60]);
        $poids = round(entre(50, 5000) / 1000 * ($upc > 6 ? 0.5 : 1), 3);
        $aid = inserer($pdo, 'article', [
            'reference' => sprintf('ART-%05d', ++$numRef),
            'designation' => alea($familles[$cond][0]) . ' ' . alea($familles[$cond][1]),
            'client_id' => alea($clients), 'condition_stockage_id' => $condId[$cond],
            'longueur_cm' => entre(10, 60), 'largeur_cm' => entre(10, 40), 'hauteur_cm' => entre(5, 40),
            'poids_kg' => $poids, 'unites_par_colis' => $upc, 'colis_par_palette' => $cpp,
        ]);
        $articles[$aid] = ['cond' => $cond, 'cond_id' => $condId[$cond], 'upc' => $upc, 'par_palette' => $upc * $cpp, 'poids' => $poids];
    }
}
// chaque fournisseur livre une sélection d'articles
$articlesParFournisseur = [];
$idsArticles = array_keys($articles);
foreach ($fournisseurs as $f) {
    $articlesParFournisseur[$f] = array_map(fn($k) => $idsArticles[$k], (array) array_rand($idsArticles, 25));
}
$pdo->commit();

// ---------------------------------------------------------------------
//  3. Simulation de l'activité
// ---------------------------------------------------------------------
echo "Simulation de l'activité (" . (JOURS_HISTORIQUE + JOURS_FUTURS) . " jours)...\n";

$maintenant = new DateTimeImmutable();
$aujourdhui = $maintenant->setTime(0, 0);
$lotsEntree = [];   // lot_id => date d'entrée (pour les transferts)

/** Capacité libre (palettes) d'un emplacement d'après le suivi local du stock. */
function libres(array $e, array $articles, array $lotArticle): int
{
    $occ = 0;
    foreach ($e['occ'] as $lot => $q) { $occ += (int) ceil($q / $articles[$lotArticle[$lot]]['par_palette']); }
    return $e['cap'] - $occ;
}

/** Emplacement compatible avec assez de place, en privilégiant le bas des racks. */
function choisirEmplacement(array &$emplacements, array $zoneConditions, array $articles, array $lotArticle, int $articleId, int $palettes): ?int
{
    $cond = $articles[$articleId]['cond_id'];
    $candidats = [];
    foreach ($emplacements as $id => $e) {
        if (!empty($e['bloque']) || !in_array($cond, $zoneConditions[$e['zone']], true)) { continue; }
        // zone dangereuse réservée aux produits dangereux sauf débordement
        if ($e['zone'] === 'DGX' && $articles[$articleId]['cond'] !== 'Dangereux' && proba(0.9)) { continue; }
        if (libres($e, $articles, $lotArticle) >= $palettes) { $candidats[] = $id; }
        if (count($candidats) >= 30) { break; }
    }
    return $candidats ? alea($candidats) : null;
}

$lotArticle = [];      // lot_id => article_id
$stockParArticle = []; // article_id => total en stock (suivi local)
$mvt = $pdo->prepare('INSERT INTO mouvement_stock (type, lot_id, quantite, emplacement_source_id, emplacement_destination_id, utilisateur_id, date_heure, motif)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
function mouvement(PDOStatement $mvt, array &$emplacements, array &$stockParArticle, array $lotArticle, string $type, int $lot, int $q, ?int $src, ?int $dst, int $user, DateTimeImmutable $d, ?string $motif = null): void
{
    $mvt->execute([$type, $lot, $q, $src, $dst, $user, dt($d), $motif]);
    $art = $lotArticle[$lot];
    if ($src !== null) {
        $emplacements[$src]['occ'][$lot] -= $q;
        if ($emplacements[$src]['occ'][$lot] <= 0) { unset($emplacements[$src]['occ'][$lot]); }
        $stockParArticle[$art] -= $q;
    }
    if ($dst !== null) {
        $emplacements[$dst]['occ'][$lot] = ($emplacements[$dst]['occ'][$lot] ?? 0) + $q;
        $stockParArticle[$art] = ($stockParArticle[$art] ?? 0) + $q;
    }
}

$etape = $pdo->prepare('INSERT INTO passage_etape (passage_id, type_etape_id, date_heure, commentaire) VALUES (?, ?, ?, ?)');
$majQuaiPassage = $pdo->prepare('UPDATE passage SET quai_id = ? WHERE id = ?');
$capaciteTotale = array_sum(array_column($emplacements, 'cap'));
$compteurs = ['rdv' => 0, 'passages' => 0, 'receptions' => 0, 'commandes' => 0];

/**
 * Enregistre les étapes d'un passage jusqu'à $etapeMax (DEPART = passage complet),
 * sans dépasser l'heure actuelle. Retourne [horodatages, code de la dernière étape franchie].
 */
function deroulerPassage(PDOStatement $etape, PDOStatement $majQuai, array $etapeId, int $passageId, int $quaiId,
                         DateTimeImmutable $arrivee, DateTimeImmutable $creneau, bool $sansRdv, float $ponctualite,
                         string $etapeMax, DateTimeImmutable $maintenant): array
{
    $t = ['ARRIVEE' => $arrivee];
    $t['CONTROLE'] = plusMin($arrivee, entre(2, 8));
    $appel = plusMin($t['CONTROLE'], entre(2, 10));
    if ($appel < $creneau) { $appel = $creneau; }
    $appel = plusMin($appel, (int) (entre(0, $sansRdv ? 70 : 30) * $ponctualite));
    if (TIMESTAMPDIFF_MIN($t['CONTROLE'], $appel) > 12) { $t['PARKING'] = plusMin($t['CONTROLE'], entre(1, 4)); }
    $t['APPEL_QUAI'] = $appel;
    $t['DEBUT_OP'] = plusMin($appel, entre(3, 10));
    $t['FIN_OP'] = plusMin($t['DEBUT_OP'], entre(20, 55));
    $t['DEPART'] = plusMin($t['FIN_OP'], entre(5, 20));

    $derniere = null;
    foreach ($t as $code => $date) {
        if ($date > $maintenant) { break; }
        if ($code === 'APPEL_QUAI') {
            $majQuai->execute([$quaiId, $passageId]);
            try {
                $etape->execute([$passageId, $etapeId[$code], dt($date), null]);
            } catch (PDOException) {
                break;   // quai encore occupé par un autre camion : le chauffeur attend sur le parking
            }
        } else {
            $etape->execute([$passageId, $etapeId[$code], dt($date), null]);
        }
        $derniere = $code;
        if ($code === $etapeMax) { break; }
    }
    return [$t, $derniere];
}
function TIMESTAMPDIFF_MIN(DateTimeImmutable $a, DateTimeImmutable $b): int { return (int) (($b->getTimestamp() - $a->getTimestamp()) / 60); }

/** Réception complète d'un camion entrant (avis, lots, écarts, rangement). */
function simulerReception(PDO $pdo, array $ctx, int $passageId, array $t, bool $cloturer): void
{
    extract($ctx, EXTR_REFS);
    $fournisseur = alea($fournisseurs);
    $avisId = null;
    $lignes = [];
    $arts = (array) array_rand(array_flip($articlesParFournisseur[$fournisseur]), entre(1, 3));
    foreach ($arts as $a) {
        $colis = entre(5, (int) ($articles[$a]['par_palette'] / $articles[$a]['upc'] * 1.5));
        $lignes[$a] = $colis * $articles[$a]['upc'];
    }
    if (proba(0.92)) {   // 8 % de livraisons non annoncées
        $avisId = inserer($pdo, 'avis_expedition', ['fournisseur_id' => $fournisseur,
            'date_emission' => $t['ARRIVEE']->modify('-' . entre(1, 6) . ' days')->format('Y-m-d'),
            'livraison_prevue' => $t['ARRIVEE']->format('Y-m-d'), 'statut' => 'attendu']);
        foreach ($lignes as $a => $q) { inserer($pdo, 'ligne_avis', ['avis_id' => $avisId, 'article_id' => $a, 'quantite_attendue' => $q]); }
    }
    $magasinier = alea($utilisateurs['magasinier']);
    $recId = inserer($pdo, 'reception', ['passage_id' => $passageId, 'avis_id' => $avisId, 'receptionnaire_id' => $magasinier,
        'debut' => dt($t['DEBUT_OP']), 'statut' => 'en_cours']);

    foreach ($lignes as $a => $attendu) {
        $recu = $attendu;
        if (proba(0.10)) { $recu = max($articles[$a]['upc'], $attendu - $articles[$a]['upc'] * entre(1, 5)); }
        elseif (proba(0.04)) { $recu = $attendu + $articles[$a]['upc'] * entre(1, 3); }
        $nbLots = $recu > $articles[$a]['par_palette'] && proba(0.4) ? 2 : 1;
        $reste = $recu;
        for ($k = 0; $k < $nbLots; $k++) {
            $q = $k === $nbLots - 1 ? $reste : intdiv($recu, 2);
            $reste -= $q;
            $dlc = match ($articles[$a]['cond']) {
                'Froid positif' => $t['ARRIVEE']->modify('+' . entre(12, 40) . ' days'),
                'Surgelé'       => $t['ARRIVEE']->modify('+' . entre(150, 400) . ' days'),
                'Ambiant'       => proba(0.5) ? $t['ARRIVEE']->modify('+' . entre(60, 500) . ' days') : null,
                default         => null,
            };
            $lotId = inserer($pdo, 'lot', ['article_id' => $a, 'numero_lot_fournisseur' => 'F' . entre(100000, 999999),
                'date_fabrication' => $t['ARRIVEE']->modify('-' . entre(2, 30) . ' days')->format('Y-m-d'),
                'date_limite_conso' => $dlc?->format('Y-m-d')]);
            $lotArticle[$lotId] = $a;
            $etat = proba(0.04) ? 'endommage' : 'bon';
            inserer($pdo, 'reception_lot', ['reception_id' => $recId, 'lot_id' => $lotId, 'quantite_recue' => $q, 'etat_palette' => $etat]);

            if ($cloturer) {
                // rangement palette par palette
                $restant = $q;
                $heure = plusMin($t['FIN_OP'], entre(10, 40));
                while ($restant > 0) {
                    $qPal = min($restant, $articles[$a]['par_palette']);
                    $dest = choisirEmplacement($emplacements, $zoneConditions, $articles, $lotArticle, $a, 1);
                    if ($dest === null) { break; }   // entrepôt plein : la palette reste en zone de réception
                    mouvement($mvt, $emplacements, $stockParArticle, $lotArticle, 'entree', $lotId, $qPal, null, $dest, $magasinier, $heure, 'Rangement après réception');
                    $lotsEntree[$lotId] = $heure;
                    $restant -= $qPal;
                    $heure = plusMin($heure, entre(2, 6));
                }
            }
        }
    }
    if ($cloturer) {
        $pdo->prepare('UPDATE reception SET fin = ? WHERE id = ?')->execute([dt($t['FIN_OP']), $recId]);
        $pdo->prepare('CALL cloturer_reception(?)')->execute([$recId]);
        // réserve émise auprès du transporteur pour les écarts de casse et une partie des manquants
        $ecarts = $pdo->query("SELECT id, type FROM ecart WHERE reception_id = $recId")->fetchAll();
        $aReserver = array_filter($ecarts, fn($e) => $e['type'] === 'endommage' || ($e['type'] === 'manquant' && proba(0.5)));
        if ($aReserver) {
            $resId = inserer($pdo, 'reserve', ['date_emission' => dt($t['FIN_OP']),
                'motif' => count($aReserver) > 1 ? 'Colis manquants et/ou endommagés à la livraison' : 'Réserve sur l\'état de la marchandise',
                'transmise' => (int) proba(0.8)]);
            $pdo->exec("UPDATE ecart SET reserve_id = $resId WHERE id IN (" . implode(',', array_column($aReserver, 'id')) . ')');
        }
        $compteurs['receptions']++;
    }
}

/** Commandes chargées par un camion sortant : prélèvement FEFO/FIFO par la procédure stockée puis chargement. */
function simulerExpedition(PDO $pdo, array $ctx, int $passageId, array $t, bool $charger, bool $parti = true): void
{
    extract($ctx, EXTR_REFS);
    $enStock = array_keys(array_filter($stockParArticle, fn($q) => $q > 0));
    if (!$enStock) { return; }
    $taux = array_sum(array_map(fn($e) => $e['cap'] - libres($e, $articles, $lotArticle), $emplacements)) / $capaciteTotale;
    $commandes = [];
    for ($c = 0, $n = entre(1, 3); $c < $n; $c++) {
        $client = alea($clients);
        [$adr, $cp, $ville] = adresse($rues, $villes);
        $dateCmd = $t['ARRIVEE']->modify('-' . entre(1, 4) . ' days');
        $cmdId = inserer($pdo, 'commande', ['client_id' => $client, 'date_commande' => $dateCmd->format('Y-m-d'),
            'expedition_souhaitee' => $t['ARRIVEE']->format('Y-m-d'), 'adresse_livraison' => $adr,
            'code_postal' => $cp, 'ville' => $ville, 'statut' => 'recue']);
        $choix = (array) array_rand(array_flip($enStock), min(count($enStock), entre(1, 4)));
        foreach ($choix as $a) {
            // plus l'entrepôt est rempli, plus les commandes sont volumineuses (équilibre des flux)
            $max = max(1, (int) ($stockParArticle[$a] * min(0.9, 0.15 + $taux)));
            $q = max($articles[$a]['upc'], intdiv(entre(1, $max), $articles[$a]['upc']) * $articles[$a]['upc']);
            inserer($pdo, 'ligne_commande', ['commande_id' => $cmdId, 'article_id' => $a, 'quantite' => min($q, $stockParArticle[$a])]);
        }
        $commandes[] = $cmdId;
        $compteurs['commandes']++;
    }
    if (!$charger) { return; }

    $expId = inserer($pdo, 'expedition', ['passage_id' => $passageId, 'date_chargement' => dt($t['DEBUT_OP']), 'statut' => $parti ? 'partie' : 'chargee']);
    foreach ($commandes as $cmdId) {
        $regle = proba(0.6) ? 'FEFO' : 'FIFO';
        $pdo->prepare('CALL generer_liste_prelevement(?, ?, @liste)')->execute([$cmdId, $regle]);
        $listeId = (int) $pdo->query('SELECT @liste')->fetchColumn();
        $prep = alea($utilisateurs['magasinier']);
        $debut = plusMin($t['ARRIVEE'], -entre(60, 240));
        $heure = $debut;
        $lignes = $pdo->query("SELECT lot_id, emplacement_id, quantite_a_prelever FROM ligne_prelevement WHERE liste_id = $listeId ORDER BY ordre")->fetchAll();
        $contenu = [];
        foreach ($lignes as $l) {
            $heure = plusMin($heure, entre(2, 7));
            $q = (int) $l['quantite_a_prelever'];
            mouvement($mvt, $emplacements, $stockParArticle, $lotArticle, 'sortie', (int) $l['lot_id'], $q, (int) $l['emplacement_id'], null, $prep, $heure, 'Prélèvement ' . $listeId);
            $pdo->prepare('UPDATE ligne_prelevement SET quantite_prelevee = ? WHERE liste_id = ? AND lot_id = ? AND emplacement_id = ?')
                ->execute([$q, $listeId, $l['lot_id'], $l['emplacement_id']]);
            $contenu[(int) $l['lot_id']] = ($contenu[(int) $l['lot_id']] ?? 0) + $q;
        }
        $pdo->prepare("UPDATE liste_prelevement SET preparateur_id = ?, date_creation = ?, debut = ?, fin = ?, statut = 'terminee' WHERE id = ?")
            ->execute([$prep, dt(plusMin($debut, -30)), dt($debut), dt(plusMin($heure, 5)), $listeId]);
        // constitution des palettes / colis
        foreach (array_chunk($contenu, 2, true) as $groupe) {
            $poids = 0;
            foreach ($groupe as $lot => $q) { $poids += $q * $articles[$lotArticle[$lot]]['poids']; }
            $ueId = inserer($pdo, 'unite_expedition', ['sscc' => '3' . str_pad((string) entre(0, 99999999), 8, '0') . str_pad((string) ($compteurs['commandes'] * 10 + mt_rand(0, 9)), 9, '0', STR_PAD_LEFT),
                'commande_id' => $cmdId, 'expedition_id' => $expId, 'type' => $poids > 30 ? 'palette' : 'colis', 'poids_kg' => max(0.5, round($poids + ($poids > 30 ? 25 : 0.3), 2))]);
            foreach ($groupe as $lot => $q) { inserer($pdo, 'contenu_unite', ['unite_id' => $ueId, 'lot_id' => $lot, 'quantite' => $q]); }
        }
        $pdo->exec("UPDATE commande SET statut = 'expediee' WHERE id = $cmdId");
        inserer($pdo, 'document_transport', ['expedition_id' => $expId, 'type' => 'BL', 'date_emission' => dt($t['DEBUT_OP'])]);
    }
    inserer($pdo, 'document_transport', ['expedition_id' => $expId, 'type' => 'CMR', 'date_emission' => dt($t['FIN_OP'])]);
}

$ctx = [
    'articles' => &$articles, 'articlesParFournisseur' => $articlesParFournisseur, 'fournisseurs' => $fournisseurs,
    'utilisateurs' => $utilisateurs, 'emplacements' => &$emplacements, 'zoneConditions' => $zoneConditions,
    'lotArticle' => &$lotArticle, 'stockParArticle' => &$stockParArticle, 'mvt' => $mvt, 'lotsEntree' => &$lotsEntree,
    'compteurs' => &$compteurs, 'clients' => $clients, 'rues' => $rues, 'villes' => $villes, 'capaciteTotale' => $capaciteTotale,
];

$sansMs = fn(DateTimeImmutable $d) => $d->setTime((int) $d->format('H'), (int) $d->format('i'));
$occupesCreneau = [];   // "v<id>@<créneau>" : véhicule déjà attendu sur ce créneau
$surSite = [];          // véhicules / chauffeurs actuellement sur le site
for ($j = -JOURS_HISTORIQUE; $j <= JOURS_FUTURS; $j++) {
    $jour = $aujourdhui->modify(($j >= 0 ? '+' : '') . $j . ' days');
    if ($jour->format('N') == 7) { continue; }   // fermé le dimanche
    $pdo->beginTransaction();

    // créneaux de 1 h 30 de 6 h à 19 h 30, RDV d'une heure
    $evenements = [];
    foreach ($quais as $qid => $q) {
        if ($qid === $quaiHorsService && $j >= 0) { continue; }
        for ($h = 0; $h < 9; $h++) {
            $debut = $jour->setTime(6, 0)->modify('+' . ($h * 90) . ' minutes');
            $remplissage = $jour->format('N') == 6 ? 0.2 : 0.5;
            $ev = null;
            if (proba($remplissage)) {
                $ev = ['quai' => $qid, 'debut' => $debut, 'rdv' => true];
            } elseif ($j <= 0 && proba(0.08)) {
                $ev = ['quai' => $qid, 'debut' => plusMin($debut, entre(0, 30)), 'rdv' => false];
            }
            if ($ev) {
                $ev['sens'] = $q['type'] === 'mixte' ? alea(['reception', 'expedition']) : $q['type'];
                $ev['enCours'] = $ev['debut'] <= $maintenant && plusMin($ev['debut'], 150) >= $maintenant;
                $evenements[] = $ev;
            }
        }
    }
    // Les expéditions du jour sont préparées le matin sur le stock de la veille : on les traite
    // d'abord. Les camions encore sur site sont traités en dernier (ils occupent leur quai).
    usort($evenements, fn($a, $b) => [$a['enCours'], $a['sens'] !== 'expedition', $a['debut']] <=> [$b['enCours'], $b['sens'] !== 'expedition', $b['debut']]);

    foreach ($evenements as $ev) {
        $qid = $ev['quai'];
        $sens = $ev['sens'];
        // véhicule et chauffeur libres (pas déjà sur site ni attendus sur le même créneau)
        $cleCreneau = $ev['debut']->format('YmdHi');
        for ($essai = 0; $essai < 20; $essai++) {
            $typeVeh = alea($quais[$qid]['types_veh']);
            $candidatsT = array_keys(array_filter($transporteurs, fn($t) => !empty($t['vehicules'][$typeVeh])));
            $tid = alea($candidatsT);
            $vid = alea($transporteurs[$tid]['vehicules'][$typeVeh]);
            $chid = alea($transporteurs[$tid]['chauffeurs']);
            if (!isset($occupesCreneau["v$vid@$cleCreneau"]) && !isset($surSite["v$vid"]) && !isset($surSite["c$chid"])) { break; }
        }
        $occupesCreneau["v$vid@$cleCreneau"] = true;
        $ponct = $transporteurs[$tid]['ponctualite'];

        $enCours = $ev['enCours'];                                  // camion actuellement sur site
        $passe = !$enCours && $ev['debut'] < $maintenant;           // passage terminé
        $rdvId = null;
        if ($ev['rdv']) {
            $statut = 'planifie';
            if ($passe || $enCours) {
                $statut = proba(0.06) ? 'annule' : (proba(0.05) ? 'absent' : 'honore');
            }
            $rdvId = inserer($pdo, 'rendez_vous', ['quai_id' => $qid, 'vehicule_id' => $vid, 'debut' => dt($ev['debut']),
                'fin' => dt(plusMin($ev['debut'], 60)), 'sens' => $sens, 'statut' => $statut === 'honore' ? 'planifie' : $statut,
                'motif_annulation' => $statut === 'annule' ? alea(['Commande reportée par le client', 'Panne du véhicule', 'Grève', 'Erreur de planification']) : null,
                'commentaire' => proba(0.1) ? alea(['Livraison urgente', 'Prévoir transpalette électrique', 'Chargement en vrac', 'Hayon obligatoire']) : null]);
            $compteurs['rdv']++;
            if ($statut !== 'honore') { continue; }
        } elseif (!$passe) {
            continue;
        }

        $retard = (int) (entre(-25, 30) * $ponct + (proba(0.1 * $ponct) ? entre(15, 90) : 0));
        $arrivee = $sansMs(plusMin($ev['debut'], $ev['rdv'] ? $retard : 0));
        if ($enCours && $arrivee > $maintenant) { $arrivee = plusMin($maintenant, -entre(5, 30)); }
        $pid = inserer($pdo, 'passage', ['rendez_vous_id' => $rdvId, 'vehicule_id' => $vid, 'chauffeur_id' => $chid, 'sens' => $sens,
            'observations' => $ev['rdv'] ? null : 'Arrivée sans rendez-vous']);
        $compteurs['passages']++;

        // un camion en cours s'arrête à une étape compatible avec l'heure actuelle
        $etapeMax = 'DEPART';
        if ($enCours) {
            $etapeMax = alea(['CONTROLE', 'APPEL_QUAI', 'DEBUT_OP', 'DEBUT_OP', 'FIN_OP']);
        }
        [$t, $derniere] = deroulerPassage($etape, $majQuaiPassage, $etapeId, $pid, $qid, $arrivee, $ev['debut'], !$ev['rdv'], $ponct, $etapeMax, $maintenant);
        if ($derniere !== 'DEPART') { $surSite["v$vid"] = $surSite["c$chid"] = true; }

        $codes = array_keys($etapes);
        $atteint = $derniere !== null && array_search($derniere, $codes, true) >= array_search('DEBUT_OP', $codes, true);
        if ($sens === 'reception' && $atteint) {
            simulerReception($pdo, $ctx, $pid, $t, $derniere === 'DEPART');
        } elseif ($sens === 'expedition' && $atteint) {
            simulerExpedition($pdo, $ctx, $pid, $t, $derniere !== 'DEBUT_OP', $derniere === 'DEPART');
        }
    }

    // mouvements internes de l'après-midi : transferts et ajustements d'inventaire
    if ($j <= 0 && $jour->format('N') <= 5) {
        $limite = $j === 0 ? $maintenant : $jour->setTime(17, 0);
        $occupes = array_keys(array_filter($emplacements, fn($e) => !empty($e['occ'])));
        for ($k = 0, $n = entre(3, 8); $k < $n && $occupes; $k++) {
            $src = alea($occupes);
            if (empty($emplacements[$src]['occ'])) { continue; }   // vidé par un transfert précédent
            $lot = array_rand($emplacements[$src]['occ']);
            $heure = $sansMs($jour->setTime(13, 0)->modify('+' . entre(0, 240) . ' minutes'));
            if ($heure > $limite || ($lotsEntree[$lot] ?? $heure) >= $heure) { continue; }
            $q = $emplacements[$src]['occ'][$lot];
            if (proba(0.75)) {
                $dst = choisirEmplacement($emplacements, $zoneConditions, $articles, $lotArticle, $lotArticle[$lot], (int) ceil($q / $articles[$lotArticle[$lot]]['par_palette']));
                if ($dst !== null && $dst !== $src) {
                    mouvement($mvt, $emplacements, $stockParArticle, $lotArticle, 'transfert', $lot, $q, $src, $dst, alea($utilisateurs['magasinier']), $heure,
                        alea(['Réorganisation de la zone', 'Rapprochement picking', 'Libération emplacement sol']));
                }
            } else {
                $delta = $articles[$lotArticle[$lot]]['upc'] * entre(1, 2);
                if (proba(0.7) && $q > $delta) {
                    mouvement($mvt, $emplacements, $stockParArticle, $lotArticle, 'ajustement', $lot, $delta, $src, null, alea($utilisateurs['magasinier']), $heure, 'Inventaire tournant : écart négatif');
                } else {
                    mouvement($mvt, $emplacements, $stockParArticle, $lotArticle, 'ajustement', $lot, $delta, null, $src, alea($utilisateurs['magasinier']), $heure, 'Inventaire tournant : écart positif');
                }
            }
        }
    }
    $pdo->commit();
    if ($j % 10 === 0) {
        printf("  jour %+d : %d RDV, %d passages, %d réceptions\n", $j, $compteurs['rdv'], $compteurs['passages'], $compteurs['receptions']);
    }
}

// Commandes à préparer (aujourd'hui et demain) avec listes de prélèvement en attente
$pdo->beginTransaction();
for ($c = 0; $c < 12; $c++) {
    $t = ['ARRIVEE' => $aujourdhui->modify('+' . entre(0, 1) . ' days')->setTime(entre(8, 18), 0)];
    simulerExpedition($pdo, $ctx, 0, $t, false);
}
$enAttente = $pdo->query("SELECT id FROM commande WHERE statut = 'recue' ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_COLUMN);
foreach ($enAttente as $i => $cmdId) {
    $pdo->prepare('CALL generer_liste_prelevement(?, ?, @liste)')->execute([$cmdId, $i % 2 ? 'FIFO' : 'FEFO']);
    if ($i < 3) {
        $pdo->exec('UPDATE liste_prelevement SET preparateur_id = ' . alea($utilisateurs['magasinier']) . ' WHERE id = (SELECT @liste)');
    }
}

// Avis d'expédition attendus pour les réceptions planifiées des prochains jours
$rdvReception = $pdo->query("SELECT debut FROM rendez_vous WHERE statut = 'planifie' AND sens = 'reception'
                               AND debut < CURRENT_DATE + INTERVAL 3 DAY ORDER BY debut")->fetchAll(PDO::FETCH_COLUMN);
foreach ($rdvReception as $debut) {
    $fournisseur = alea($fournisseurs);
    $avisId = inserer($pdo, 'avis_expedition', ['fournisseur_id' => $fournisseur,
        'date_emission' => date('Y-m-d', strtotime("$debut -" . entre(1, 4) . ' days')),
        'livraison_prevue' => substr($debut, 0, 10), 'statut' => 'attendu']);
    foreach ((array) array_rand(array_flip($articlesParFournisseur[$fournisseur]), entre(1, 3)) as $a) {
        inserer($pdo, 'ligne_avis', ['avis_id' => $avisId, 'article_id' => $a,
            'quantite_attendue' => entre(5, (int) ($articles[$a]['par_palette'] / $articles[$a]['upc'] * 1.5)) * $articles[$a]['upc']]);
    }
}

// Quelques emplacements bloqués et le quai hors service
$pdo->exec('SET @utilisateur_id = 1');
$pdo->exec('UPDATE emplacement SET bloque = TRUE WHERE id IN (SELECT id FROM (SELECT e.id FROM emplacement e LEFT JOIN stock s ON s.emplacement_id = e.id WHERE s.lot_id IS NULL ORDER BY e.id DESC LIMIT 8) x)');
$pdo->exec("UPDATE quai SET statut = 'hors_service' WHERE id = $quaiHorsService");
$pdo->commit();

// ---------------------------------------------------------------------
//  Bilan
// ---------------------------------------------------------------------
echo "\nTerminé en " . round(microtime(true) - $debutChrono, 1) . " s.\n";
$tables = $pdo->query("SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = 'entrepot' AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll();
foreach ($tables as $t) {
    $n = $pdo->query("SELECT COUNT(*) FROM `{$t['table_name']}`")->fetchColumn();
    printf("  %-26s %8d\n", $t['table_name'], $n);
}
echo "\nComptes de démonstration (mot de passe : " . MOT_DE_PASSE_DEMO . ") : admin@, responsable@, accueil@, magasinier@, transporteur@entrepot.test\n";
