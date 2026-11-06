<?php
/**
 * Chargé en tête de chaque page : session, connexion PDO, fonctions utilitaires.
 */
date_default_timezone_set('Europe/Paris');

// aucune erreur technique (chemin, requête) n'est montrée à l'utilisateur : elle part dans le journal PHP
ini_set('display_errors', '0');
function erreur_non_prevue($e)
{
    error_log((string) $e);
    if (!headers_sent()) { http_response_code(500); }
    echo '<p>Une erreur est survenue. Revenez à la <a href="index.php">page d\'accueil</a> et réessayez.</p>';
}
set_exception_handler('erreur_non_prevue');

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

// ---------------------------------------------------------------------
//  Base de données
// ---------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = require __DIR__ . '/../config.php';
        $pdo = new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // auteur des changements de statut, lu par les déclencheurs du journal (historique_statut)
        if (isset($_SESSION['utilisateur']['id'])) {
            $pdo->prepare('SET @utilisateur_id = ?')->execute([(int) $_SESSION['utilisateur']['id']]);
        }
    }
    return $pdo;
}

/** Exécute une requête préparée et retourne le PDOStatement. */
function requete(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function lignes(string $sql, array $params = []): array { return requete($sql, $params)->fetchAll(); }
function ligne(string $sql, array $params = []): ?array { return requete($sql, $params)->fetch() ?: null; }
function valeur(string $sql, array $params = []) { return requete($sql, $params)->fetchColumn(); }

/** Message lisible à partir d'une erreur SQL (contrôles des déclencheurs, contraintes). */
function message_erreur(PDOException $e): string
{
    $info = $e->errorInfo ?? [];
    $etat = $info[0] ?? '';
    $code = (int) ($info[1] ?? 0);
    $texte = $info[2] ?? $e->getMessage();
    if ($etat === '45000') {                 // erreur levée par SIGNAL dans un déclencheur : message déjà rédigé
        return $texte;
    }
    if ($code === 1062) { return 'Cette valeur existe déjà (doublon sur un identifiant unique).'; }
    if ($code === 1451) { return 'Suppression impossible : cet élément est utilisé par d\'autres données.'; }
    if ($code === 1452) { return 'Référence invalide vers un autre élément.'; }
    if ($code === 4025) { return 'Une contrainte de cohérence n\'est pas respectée (' . $texte . ').'; }
    return 'Erreur de base de données : ' . $texte;
}

// ---------------------------------------------------------------------
//  Authentification et droits
// ---------------------------------------------------------------------
function utilisateur(): ?array { return $_SESSION['utilisateur'] ?? null; }

function a_profil(string ...$profils): bool
{
    return profil_parmi($profils);
}

/** Vrai si l'utilisateur connecté a l'un des profils de la liste. */
function profil_parmi(array $profils): bool
{
    $u = utilisateur();
    if ($u === null) { return false; }
    return in_array($u['profil'], $profils, true);
}

function exiger_connexion(): array
{
    if (utilisateur() === null) {
        flash('info', 'Veuillez vous connecter.');
        rediriger('connexion.php');
    }
    // relu à chaque page : un compte désactivé ou modifié par l'administrateur prend effet aussitôt
    $compte = ligne('SELECT u.actif, u.transporteur_id, p.code, p.libelle
                       FROM utilisateur u JOIN profil p ON p.id = u.profil_id WHERE u.id = ?', [$_SESSION['utilisateur']['id']]);
    if (!$compte || !$compte['actif']) {
        unset($_SESSION['utilisateur']);
        flash('erreur', 'Votre compte a été désactivé.');
        rediriger('connexion.php');
    }
    $_SESSION['utilisateur'] = array_merge($_SESSION['utilisateur'], [
        'profil' => $compte['code'], 'profil_libelle' => $compte['libelle'],
        'transporteur_id' => $compte['transporteur_id'] !== null ? (int) $compte['transporteur_id'] : null,
    ]);
    return utilisateur();
}

function exiger_profil(string ...$profils): array
{
    return exiger_profil_parmi($profils);
}

/** Refuse l'accès à la page si l'utilisateur n'a aucun des profils de la liste. */
function exiger_profil_parmi(array $profils): array
{
    $u = exiger_connexion();
    if (!profil_parmi($profils)) {
        http_response_code(403);
        flash('erreur', 'Vous n\'avez pas accès à cette page.');
        rediriger('index.php');
    }
    return $u;
}

// ---------------------------------------------------------------------
//  Formulaires : CSRF, messages flash, redirections
// ---------------------------------------------------------------------
function jeton_csrf(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));   // jeton aléatoire, créé une fois par session
    }
    return $_SESSION['csrf'];
}

function champ_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . jeton_csrf() . '">';
}

function verifier_csrf(): void
{
    $jeton = $_POST['csrf'] ?? '';
    if (!is_string($jeton) || !hash_equals(jeton_csrf(), $jeton)) {
        http_response_code(400);
        exit('Jeton de sécurité invalide. Rechargez la page.');
    }
}

function est_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }

function flash(string $type, string $message): void { $_SESSION['flash'][] = [$type, $message]; }

function rediriger(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ---------------------------------------------------------------------
//  Affichage
// ---------------------------------------------------------------------
function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function date_fr(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : ''; }
function date_heure_fr(?string $d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : ''; }
function heure(?string $d): string { return $d ? date('H:i', strtotime($d)) : ''; }
function nombre($n, int $dec = 0): string { return $n === null ? '–' : number_format((float) $n, $dec, ',', ' '); }

function duree(?int $minutes): string
{
    if ($minutes === null) { return '–'; }
    return $minutes >= 60 ? intdiv($minutes, 60) . ' h ' . sprintf('%02d', $minutes % 60) : $minutes . ' min';
}

/** Libellés et couleurs des statuts affichés sous forme de pastilles. */
const STATUTS = [
    'libre' => ['Libre', 'vert'], 'reserve' => ['Réservé', 'bleu'], 'occupe' => ['Occupé', 'orange'], 'hors_service' => ['Hors service', 'gris'],
    'planifie' => ['Planifié', 'bleu'], 'honore' => ['Honoré', 'vert'], 'annule' => ['Annulé', 'gris'],
    'en_cours' => ['En cours', 'orange'], 'cloturee' => ['Clôturée', 'vert'],
    'attendu' => ['Attendu', 'bleu'], 'recu' => ['Reçu', 'vert'],
    'manquant' => ['Manquant', 'rouge'], 'excedent' => ['Excédent', 'orange'], 'endommage' => ['Endommagé', 'rouge'],
    'bon' => ['Bon', 'vert'], 'refuse' => ['Refusé', 'rouge'],
    'entree' => ['Entrée', 'vert'], 'sortie' => ['Sortie', 'rouge'], 'transfert' => ['Transfert', 'bleu'], 'ajustement' => ['Ajustement', 'orange'],
    'reception' => ['Réception', 'bleu'], 'expedition' => ['Expédition', 'violet'], 'mixte' => ['Mixte', 'gris'],
    'a_faire' => ['À faire', 'bleu'], 'terminee' => ['Terminée', 'vert'], 'recue' => ['Reçue', 'bleu'],
    'en_chargement' => ['En chargement', 'orange'], 'chargee' => ['Chargée', 'bleu'], 'partie' => ['Partie', 'vert'],
    'en_preparation' => ['En préparation', 'orange'], 'preparee' => ['Préparée', 'bleu'], 'expediee' => ['Expédiée', 'vert'], 'annulee' => ['Annulée', 'gris'],
    'colis' => ['Colis', 'bleu'], 'palette' => ['Palette', 'violet'],
];

function pastille(?string $statut): string
{
    [$lib, $couleur] = STATUTS[$statut] ?? [$statut, 'gris'];
    return '<span class="pastille ' . $couleur . '">' . e($lib) . '</span>';
}

/** Liste déroulante à partir de paires valeur => libellé. */
function options(array $paires, $selection = null, ?string $vide = null): string
{
    $html = $vide !== null ? '<option value="">' . e($vide) . '</option>' : '';
    foreach ($paires as $v => $lib) {
        $sel = (string) $v === (string) $selection ? ' selected' : '';
        $html .= '<option value="' . e($v) . '"' . $sel . '>' . e($lib) . '</option>';
    }
    return $html;
}

/** Paires id => libellé depuis une requête « SELECT id, libelle ... ». */
function paires(string $sql, array $params = []): array
{
    return requete($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Pagination : retourne [limite, décalage, page]. */
function pagination(int $total, int $parPage = 25): array
{
    $pages = max(1, (int) ceil($total / $parPage));
    $page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
    return [$parPage, ($page - 1) * $parPage, $page, $pages];
}

function liens_pagination(int $page, int $pages): string
{
    if ($pages <= 1) { return ''; }
    $q = $_GET;
    $html = '<nav class="pagination">';
    foreach ([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages] as $p) {
        if ($p < 1 || $p > $pages || isset($vus[$p])) { continue; }
        $vus[$p] = true;
        $q['page'] = $p;
        $html .= $p === $page ? "<span class=\"actif\">$p</span>" : '<a href="?' . e(http_build_query($q)) . "\">$p</a>";
    }
    return $html . '</nav>';
}

/** Articles d'un client ayant du stock disponible (stock physique moins lignes réservées non prélevées), indexés par id. */
function articles_disponibles(int $clientId): array
{
    $articles = [];
    // On part du stock (quelques centaines de lignes) et des prélèvements en cours, regroupés par
    // article, plutôt que de parcourir tous les lots de chaque article : l'historique des lots
    // sortis ne ralentit plus la requête (voir docs/rapport_montee_en_charge.md).
    foreach (lignes("SELECT ar.id, ar.reference, ar.designation, COALESCE(st.quantite, 0) - COALESCE(re.quantite, 0) AS dispo
                       FROM article ar
                       LEFT JOIN (SELECT l.article_id, SUM(s.quantite) AS quantite FROM stock s JOIN lot l ON l.id = s.lot_id
                                   GROUP BY l.article_id) st ON st.article_id = ar.id
                       LEFT JOIN (SELECT l.article_id, SUM(lp.quantite_a_prelever) AS quantite FROM ligne_prelevement lp
                                    JOIN liste_prelevement li ON li.id = lp.liste_id JOIN lot l ON l.id = lp.lot_id
                                   WHERE li.statut <> 'terminee' AND lp.quantite_prelevee IS NULL GROUP BY l.article_id) re ON re.article_id = ar.id
                      WHERE ar.client_id = ? ORDER BY ar.reference", [$clientId]) as $a) {
        if ($a['dispo'] > 0) { $articles[$a['id']] = $a; }
    }
    return $articles;
}

/** Valeur d'un paramètre GET de filtre (chaîne vide si absent). */
function filtre(string $nom): string
{
    $v = $_GET[$nom] ?? '';
    return is_string($v) ? trim($v) : '';
}

/** Date AAAA-MM-JJ d'un filtre GET ; la valeur par défaut si elle est absente ou invalide. */
function filtre_date(string $nom, string $defaut): string
{
    $d = DateTime::createFromFormat('Y-m-d', filtre($nom));
    return $d && $d->format('Y-m-d') === filtre($nom) ? filtre($nom) : $defaut;
}
