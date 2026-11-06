<?php
/**
 * Gabarit commun : en-tête, menu selon le profil, messages flash, pied de page.
 */

/** Entrées du menu : [section => [[libellé, url, profils autorisés]]]. */
function menu(): array
{
    $exploitation = ['responsable', 'accueil', 'magasinier'];
    return [
        '' => [
            ['Tableau de bord', 'index.php', ['responsable', 'accueil', 'magasinier', 'transporteur', 'admin']],
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
        'Préparation' => [
            ['Commandes clients', 'commandes.php', ['responsable']],
            ['Listes de prélèvement', 'preparation.php', ['responsable']],
            ['Mes listes de prélèvement', 'listes.php', ['magasinier']],
            ['Colis et expéditions', 'expeditions.php', ['responsable', 'magasinier']],
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
            ['Utilisateurs', 'utilisateurs.php', ['admin']],
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
        $visibles = [];                          // entrées du menu autorisées pour ce profil
        foreach ($entrees as $m) {
            if (profil_parmi($m[2])) { $visibles[] = $m; }
        }
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

/**
 * Carte « Historique des statuts » d'un rendez-vous, d'une commande ou d'un quai,
 * lue dans le journal tenu par les déclencheurs de la base (table historique_statut).
 */
function carte_historique_statuts(string $objet, int $id, bool $avecAuteur = true, int $limite = 20): void
{
    $lignes = lignes("SELECT h.date_heure, h.ancien_statut, h.nouveau_statut, CONCAT(u.prenom, ' ', u.nom) AS auteur
                        FROM historique_statut h LEFT JOIN utilisateur u ON u.id = h.utilisateur_id
                       WHERE h.objet = ? AND h.objet_id = ?
                       ORDER BY h.date_heure DESC, h.id DESC LIMIT " . (int) $limite, [$objet, $id]);
    ?>
<div class="carte">
    <h2>Historique des statuts <span class="discret">enregistré automatiquement par la base</span></h2>
    <?php if (!$lignes): ?><p class="vide">Aucun changement de statut depuis la création.</p><?php else: ?>
    <div class="tableau"><table>
        <tr><th>Date</th><th>Changement</th><?php if ($avecAuteur): ?><th>Par</th><?php endif; ?></tr>
        <?php foreach ($lignes as $h): ?>
        <tr>
            <td><?= date_heure_fr($h['date_heure']) ?></td>
            <td><?= pastille($h['ancien_statut']) ?> → <?= pastille($h['nouveau_statut']) ?></td>
            <?php if ($avecAuteur): ?><td><?= e($h['auteur'] ?? 'Automatique') ?></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php if (count($lignes) === $limite): ?><p class="discret">Les <?= $limite ?> derniers changements sont affichés.</p><?php endif; ?>
    <?php endif; ?>
</div>
<?php
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
