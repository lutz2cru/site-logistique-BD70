<?php
/**
 * Gabarit commun : en-tête, menu selon le profil, messages flash, pied de page.
 */

/** Entrées du menu : [section => [[libellé, url, profils autorisés]]] (l'admin voit tout). */
function menu(): array
{
    $exploitation = ['responsable', 'accueil', 'magasinier'];
    return [
        '' => [
            ['Tableau de bord', 'index.php', ['responsable', 'accueil', 'magasinier', 'transporteur']],
        ],
        'Quais et rendez-vous' => [
            ['Planning des quais', 'planning.php', ['responsable', 'accueil', 'transporteur']],
            ['Rendez-vous', 'rdv.php', ['responsable', 'accueil', 'transporteur']],
            ['Quais', 'quais.php', ['responsable', 'accueil']],
        ],
        'Flux de camions' => [
            ['Camions sur site', 'flux.php', $exploitation],
            ['Historique des passages', 'passages.php', ['responsable', 'accueil']],
        ],
        'Réception' => [
            ['Réceptions', 'receptions.php', ['responsable', 'magasinier']],
            ['Avis d\'expédition', 'avis.php', ['responsable', 'magasinier']],
        ],
        'Stocks' => [
            ['Recherche de stock', 'stock.php', ['responsable', 'magasinier']],
            ['Mouvements', 'mouvements.php', ['responsable', 'magasinier']],
            ['Emplacements', 'emplacements.php', ['responsable', 'magasinier']],
            ['Traçabilité d\'un lot', 'lot.php', ['responsable', 'magasinier']],
            ['Articles', 'referentiel.php?t=articles', ['responsable', 'magasinier']],
            ['Clients propriétaires', 'referentiel.php?t=clients', ['responsable']],
        ],
        'Référentiels' => [
            ['Transporteurs', 'referentiel.php?t=transporteurs', ['responsable']],
            ['Chauffeurs', 'referentiel.php?t=chauffeurs', ['responsable', 'accueil']],
            ['Véhicules', 'referentiel.php?t=vehicules', ['responsable', 'accueil']],
            ['Fournisseurs', 'referentiel.php?t=fournisseurs', ['responsable']],
            ['Structure de l\'entrepôt', 'referentiel.php?t=zones', ['responsable']],
        ],
        'Pilotage' => [
            ['Indicateurs', 'indicateurs.php', ['responsable']],
        ],
        'Administration' => [
            ['Utilisateurs', 'utilisateurs.php', []],
            ['Journal des modifications', 'journal.php', []],
        ],
    ];
}

function entete(string $titre, string $actif = ''): void
{
    $u = utilisateur();
    $actif = $actif ?: basename($_SERVER['SCRIPT_NAME']) . (isset($_GET['t']) ? '?t=' . $_GET['t'] : '');
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titre) ?> · Entrepôt</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php if ($u): ?>
<input type="checkbox" id="menu-ouvert" hidden>
<aside class="menu">
    <a class="logo" href="index.php">▣ Entrepôt<br><small>Gestion logistique</small></a>
    <nav>
    <?php foreach (menu() as $section => $entrees):
        $visibles = array_filter($entrees, fn($m) => a_profil(...$m[2]));
        if (!$visibles) { continue; } ?>
        <?php if ($section): ?><div class="section"><?= e($section) ?></div><?php endif; ?>
        <?php foreach ($visibles as [$lib, $url]): ?>
            <a href="<?= e($url) ?>" class="<?= $url === $actif ? 'actif' : '' ?>"><?= e($lib) ?></a>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </nav>
</aside>
<?php endif; ?>
<div class="page<?= $u ? '' : ' sans-menu' ?>">
<?php if ($u): ?>
    <header class="barre">
        <label for="menu-ouvert" class="burger" title="Menu">☰</label>
        <h1><?= e($titre) ?></h1>
        <div class="compte">
            <span><?= e($u['prenom'] . ' ' . $u['nom']) ?> · <em><?= e($u['profil_libelle']) ?></em></span>
            <a class="bouton discret" href="deconnexion.php">Déconnexion</a>
        </div>
    </header>
<?php endif; ?>
    <main>
<?php
    if ($u) { messages_flash(); }
}

/** Affiche puis efface les messages flash de la session. */
function messages_flash(): void
{
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<div class="alerte ' . e($type) . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
}

function pied(string $scripts = ''): void
{
    ?>
    </main>
</div>
<?= $scripts ?>
</body>
</html>
<?php
}
