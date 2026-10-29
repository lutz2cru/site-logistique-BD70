<?php
/**
 * Chargé en tête de chaque page : session, connexion PDO, fonctions utilitaires.
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Paris');
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
        // utilisé par les déclencheurs d'historisation (journal_audit)
        $pdo->prepare('SET @utilisateur_id = ?')->execute([$_SESSION['utilisateur']['id'] ?? null]);
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
function valeur(string $sql, array $params = []): mixed { return requete($sql, $params)->fetchColumn(); }

/** Message lisible à partir d'une erreur SQL (contrôles des déclencheurs, contraintes). */
function message_erreur(PDOException $e): string
{
    $info = $e->errorInfo ?? [];
    $code = (int) ($info[1] ?? 0);
    return match (true) {
        ($info[0] ?? '') === '45000' => $info[2],                                 // SIGNAL d'un déclencheur
        $code === 1062 => 'Cette valeur existe déjà (doublon sur un identifiant unique).',
        $code === 1451 => 'Suppression impossible : cet élément est utilisé par d\'autres données.',
        $code === 1452 => 'Référence invalide vers un autre élément.',
        $code === 4025 => 'Une contrainte de cohérence n\'est pas respectée (' . ($info[2] ?? '') . ').',
        default => 'Erreur de base de données : ' . ($info[2] ?? $e->getMessage()),
    };
}

// ---------------------------------------------------------------------
//  Authentification et droits
// ---------------------------------------------------------------------
function utilisateur(): ?array { return $_SESSION['utilisateur'] ?? null; }

function a_profil(string ...$profils): bool
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
    return utilisateur();
}

function exiger_profil(string ...$profils): array
{
    $u = exiger_connexion();
    if (!a_profil(...$profils)) {
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
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function champ_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . jeton_csrf() . '">';
}

function verifier_csrf(): void
{
    if (!hash_equals(jeton_csrf(), $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Jeton de sécurité invalide. Rechargez la page.');
    }
}

function est_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }

function flash(string $type, string $message): void { $_SESSION['flash'][] = [$type, $message]; }

function rediriger(string $url): never
{
    header('Location: ' . $url);
    exit;
}

// ---------------------------------------------------------------------
//  Affichage
// ---------------------------------------------------------------------
function e(mixed $v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function date_fr(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : ''; }
function date_heure_fr(?string $d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : ''; }
function heure(?string $d): string { return $d ? date('H:i', strtotime($d)) : ''; }
function nombre(mixed $n, int $dec = 0): string { return $n === null ? '–' : number_format((float) $n, $dec, ',', ' '); }

function duree(?int $minutes): string
{
    if ($minutes === null) { return '–'; }
    return $minutes >= 60 ? intdiv($minutes, 60) . ' h ' . sprintf('%02d', $minutes % 60) : $minutes . ' min';
}

/** Libellés et couleurs des statuts affichés sous forme de pastilles. */
const STATUTS = [
    'libre' => ['Libre', 'vert'], 'reserve' => ['Réservé', 'bleu'], 'occupe' => ['Occupé', 'orange'], 'hors_service' => ['Hors service', 'gris'],
    'planifie' => ['Planifié', 'bleu'], 'honore' => ['Honoré', 'vert'], 'annule' => ['Annulé', 'gris'], 'absent' => ['Absent', 'rouge'],
    'en_cours' => ['En cours', 'orange'], 'cloturee' => ['Clôturée', 'vert'],
    'attendu' => ['Attendu', 'bleu'], 'recu' => ['Reçu', 'vert'],
    'manquant' => ['Manquant', 'rouge'], 'excedent' => ['Excédent', 'orange'], 'endommage' => ['Endommagé', 'rouge'],
    'bon' => ['Bon', 'vert'], 'refuse' => ['Refusé', 'rouge'],
    'entree' => ['Entrée', 'vert'], 'sortie' => ['Sortie', 'rouge'], 'transfert' => ['Transfert', 'bleu'], 'ajustement' => ['Ajustement', 'orange'],
    'reception' => ['Réception', 'bleu'], 'expedition' => ['Expédition', 'violet'], 'mixte' => ['Mixte', 'gris'],
    'a_faire' => ['À faire', 'bleu'], 'terminee' => ['Terminée', 'vert'], 'recue' => ['Reçue', 'bleu'],
    'en_preparation' => ['En préparation', 'orange'], 'preparee' => ['Préparée', 'bleu'], 'expediee' => ['Expédiée', 'vert'], 'annulee' => ['Annulée', 'gris'],
];

function pastille(?string $statut): string
{
    [$lib, $couleur] = STATUTS[$statut] ?? [$statut, 'gris'];
    return '<span class="pastille ' . $couleur . '">' . e($lib) . '</span>';
}

/** Liste déroulante à partir de paires valeur => libellé. */
function options(array $paires, mixed $selection = null, ?string $vide = null): string
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

/** Valeur d'un paramètre GET de filtre (chaîne vide si absent). */
function filtre(string $nom): string { return trim((string) ($_GET[$nom] ?? '')); }
