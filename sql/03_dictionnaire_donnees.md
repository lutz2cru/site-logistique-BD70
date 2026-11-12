# Dictionnaire de données — Entrepôt logistique

Ce document décrit les 47 tables du modèle relationnel ainsi que les quatre
fonctions SQL définies dans les scripts de la base `entrepot`.

- [01_schema.sql](./01_schema.sql) crée les tables, les clés, les contraintes et les index.
- [02_automatisation.sql](./02_automatisation.sql) définit les fonctions, procédures,
  déclencheurs et vues qui automatisent les opérations.

## Conventions de lecture

- Les liens indiqués correspondent aux clés étrangères déclarées dans le schéma.
- « Plusieurs » signifie qu'un enregistrement parent peut être référencé par
  plusieurs lignes enfants.
- Les tables d'association matérialisent les relations plusieurs-à-plusieurs
  (N:N) ou ternaires et contiennent, le cas échéant, les attributs de la relation.
- `ON DELETE CASCADE` supprime les lignes dépendantes ; `ON DELETE SET NULL`
  conserve la ligne dépendante en retirant son rattachement.

## 1. Comptes, transporteurs et véhicules

| Table | Rôle | Liens et cardinalités |
|---|---|---|
| <a id="profil"></a>`profil` | Référentiel des rôles et niveaux de droits attribués aux utilisateurs. | Un profil peut être affecté à plusieurs [`utilisateur`](#utilisateur). |
| <a id="transporteur"></a>`transporteur` | Entreprise de transport intervenant sur le site, identifiée notamment par son SIRET. | Peut être liée à plusieurs [`utilisateur`](#utilisateur), [`contact`](#contact), [`contrat`](#contrat), [`chauffeur`](#chauffeur) et [`vehicule`](#vehicule). |
| <a id="utilisateur"></a>`utilisateur` | Compte applicatif : identité, adresse de connexion, mot de passe haché, activation et rattachements éventuels. | Référence un [`profil`](#profil) et, facultativement, un [`transporteur`](#transporteur). Peut être référencé par [`mouvement_stock`](#mouvement_stock), [`liste_prelevement`](#liste_prelevement) et [`historique_statut`](#historique_statut). |
| <a id="contact"></a>`contact` | Coordonnées d'un interlocuteur opérationnel d'un transporteur. | Chaque contact référence un [`transporteur`](#transporteur). La suppression du transporteur supprime ses contacts. |
| <a id="contrat"></a>`contrat` | Contrat commercial ou opérationnel conclu avec un transporteur, avec dates et montant éventuel. | Chaque contrat référence un [`transporteur`](#transporteur). La suppression du transporteur supprime ses contrats. |
| <a id="chauffeur"></a>`chauffeur` | Chauffeur d'un transporteur, avec informations de permis et coordonnées. | Référence un [`transporteur`](#transporteur). Peut intervenir dans plusieurs [`passage`](#passage) et détenir plusieurs [`habilitation`](#habilitation) via `chauffeur_habilitation`. |
| <a id="habilitation"></a>`habilitation` | Référentiel des qualifications ou autorisations que peut détenir un chauffeur. | Associée à plusieurs [`chauffeur`](#chauffeur) au moyen de `chauffeur_habilitation`. |
| <a id="chauffeur_habilitation"></a>`chauffeur_habilitation` | Association N:N entre chauffeurs et habilitations ; conserve les dates d'obtention et d'expiration. | Référence un [`chauffeur`](#chauffeur) et une [`habilitation`](#habilitation). Sa clé primaire est la paire de ces identifiants. |
| <a id="type_vehicule"></a>`type_vehicule` | Référentiel des catégories de véhicules. | Un type peut caractériser plusieurs [`vehicule`](#vehicule) et être autorisé sur plusieurs [`quai`](#quai) via `quai_type_vehicule`. |
| <a id="vehicule"></a>`vehicule` | Véhicule d'un transporteur, avec type, capacité de poids, capacité en palettes et équipements. | Référence un [`transporteur`](#transporteur) et un [`type_vehicule`](#type_vehicule). Peut être lié à plusieurs [`rendez_vous`](#rendez_vous) et [`passage`](#passage). |

## 2. Quais, rendez-vous et passages

| Table | Rôle | Liens et cardinalités |
|---|---|---|
| <a id="quai"></a>`quai` | Quai physique du site ; définit le flux accepté et l'état opérationnel. L'état réservé est calculé dans une vue, il n'est pas stocké ici. | Peut être référencé par plusieurs [`rendez_vous`](#rendez_vous) et [`passage`](#passage). Lié à plusieurs équipements via `quai_equipement` et à plusieurs types de véhicules via `quai_type_vehicule`. |
| <a id="equipement"></a>`equipement` | Référentiel des équipements disponibles sur les quais. | Associé à plusieurs [`quai`](#quai) via `quai_equipement`. |
| <a id="quai_equipement"></a>`quai_equipement` | Association N:N indiquant quels équipements sont installés ou disponibles sur quels quais. | Référence un [`quai`](#quai) et un [`equipement`](#equipement). La suppression de l'un supprime l'association correspondante. |
| <a id="quai_type_vehicule"></a>`quai_type_vehicule` | Association N:N définissant les types de véhicules acceptés sur chaque quai. | Référence un [`quai`](#quai) et un [`type_vehicule`](#type_vehicule). Les déclencheurs s'appuient sur cette association pour valider les rendez-vous et les appels à quai. |
| <a id="rendez_vous"></a>`rendez_vous` | Créneau planifié pour un véhicule sur un quai, dans le sens réception ou expédition. | Référence un [`quai`](#quai) et un [`vehicule`](#vehicule). Peut être associé à un [`passage`](#passage) ; un passage ne peut référencer qu'un seul rendez-vous. |
| <a id="evenement"></a>`evenement` | Évènement inscrit au planning en dehors des rendez-vous : maintenance ou nettoyage d'un quai, inventaire, audit, formation, fermeture du site. Un évènement bloquant interdit la prise de rendez-vous sur son créneau ; les autres sont donnés pour information. | Référence facultativement un [`quai`](#quai) (absent = tout le site) et l'[`utilisateur`](#utilisateur) qui l'a inscrit. La suppression du quai supprime ses évènements. Les déclencheurs refusent un rendez-vous sous un évènement bloquant, et un évènement bloquant posé sur des rendez-vous planifiés. |
| <a id="type_etape"></a>`type_etape` | Référentiel des étapes du parcours d'un camion, avec code, libellé et ordre chronologique. | Peut être référencé comme étape courante par plusieurs [`passage`](#passage) et apparaître dans plusieurs [`passage_etape`](#passage_etape). |
| <a id="passage"></a>`passage` | Visite effective d'un véhicule sur le site ; conserve le chauffeur, le sens du flux, le quai et la dernière étape. | Peut référencer un [`rendez_vous`](#rendez_vous), un [`vehicule`](#vehicule), un [`chauffeur`](#chauffeur), un [`quai`](#quai) et un [`type_etape`](#type_etape) courant. Ses étapes détaillées sont stockées dans `passage_etape`. Peut être lié à une [`reception`](#reception) ou une [`expedition`](#expedition). |
| <a id="passage_etape"></a>`passage_etape` | Historique des étapes franchies par un passage, horodatées et éventuellement commentées. | Association entre un [`passage`](#passage) et un [`type_etape`](#type_etape). Une paire passage/étape n'apparaît qu'une fois ; les déclencheurs imposent l'ordre et empêchent la modification ou la suppression des étapes enregistrées. |

## 3. Entrepôt, articles et mouvements de stock

| Table | Rôle | Liens et cardinalités |
|---|---|---|
| <a id="client"></a>`client` | Entreprise propriétaire des articles entreposés ou préparés. | Peut posséder plusieurs [`article`](#article) et passer plusieurs [`commande`](#commande). |
| <a id="condition_stockage"></a>`condition_stockage` | Référentiel des conditions de conservation, avec plage de température facultative. | Associée aux zones autorisées via `zone_condition` ; peut être exigée par plusieurs [`article`](#article). |
| <a id="zone"></a>`zone` | Secteur de l'entrepôt identifié par un code et un libellé. | Contient plusieurs [`allee`](#allee) et peut autoriser plusieurs conditions via `zone_condition`. |
| <a id="zone_condition"></a>`zone_condition` | Association N:N des conditions de stockage admises dans chaque zone. | Référence une [`zone`](#zone) et une [`condition_stockage`](#condition_stockage). Elle sert au contrôle de compatibilité lors du rangement. |
| <a id="allee"></a>`allee` | Allée appartenant à une zone, qui regroupe les racks. | Référence une [`zone`](#zone) et peut contenir plusieurs [`rack`](#rack). |
| <a id="rack"></a>`rack` | Structure de rangement d'une allée, identifiée par un code et un nombre de niveaux. | Référence une [`allee`](#allee) et peut contenir plusieurs [`emplacement`](#emplacement). |
| <a id="emplacement"></a>`emplacement` | Position physique de stockage avec capacité en palettes, limite de poids et indicateur de blocage. | Référence un [`rack`](#rack). Peut contenir plusieurs [`lot`](#lot) au travers de `stock`, et être référencé par des [`mouvement_stock`](#mouvement_stock) et [`ligne_prelevement`](#ligne_prelevement). |
| <a id="article"></a>`article` | Fiche produit appartenant à un client ; décrit dimensions, poids, condition de stockage et conditionnement par palette. | Référence un [`client`](#client) et une [`condition_stockage`](#condition_stockage). Peut être décliné en plusieurs [`lot`](#lot), figurer dans des [`ligne_avis`](#ligne_avis), [`constat_ecart`](#constat_ecart) et [`ligne_commande`](#ligne_commande). |
| <a id="lot"></a>`lot` | Lot physique d'un article, avec références fournisseur et dates de fabrication ou de consommation. | Référence un [`article`](#article). Peut apparaître dans `stock`, `mouvement_stock`, `reception_lot`, `ligne_prelevement` et `contenu_unite`. |
| <a id="stock"></a>`stock` | Quantité courante d'un lot présente sur un emplacement ; une ligne correspond à une paire emplacement/lot. | Association N:N entre un [`emplacement`](#emplacement) et un [`lot`](#lot). Cette table est tenue à jour exclusivement par les déclencheurs de `mouvement_stock` ; l'application ne l'écrit pas directement. |
| <a id="mouvement_stock"></a>`mouvement_stock` | Journal des entrées, sorties, transferts et ajustements qui expliquent l'évolution du stock. | Référence un [`lot`](#lot), un [`utilisateur`](#utilisateur) et, selon le type de mouvement, un [`emplacement`](#emplacement) source et/ou destination. Les déclencheurs mettent à jour `stock` et rendent le journal immuable. |

## 4. Fournisseurs et réceptions

| Table | Rôle | Liens et cardinalités |
|---|---|---|
| <a id="fournisseur"></a>`fournisseur` | Entreprise fournissant les marchandises reçues sur le site. | Peut émettre plusieurs [`avis_expedition`](#avis_expedition). |
| <a id="avis_expedition"></a>`avis_expedition` | Annonce de livraison émise par un fournisseur, avec dates et statut de suivi. | Référence un [`fournisseur`](#fournisseur). Contient plusieurs articles via `ligne_avis` et peut être associé à plusieurs [`reception`](#reception). |
| <a id="ligne_avis"></a>`ligne_avis` | Association entre un avis d'expédition et un article, avec la quantité attendue. | Référence un [`avis_expedition`](#avis_expedition) et un [`article`](#article) ; chaque article ne figure qu'une fois par avis. |
| <a id="reception"></a>`reception` | Opération de réception associée à un passage et éventuellement à un avis annoncé. | Référence un [`passage`](#passage) et, facultativement, un [`avis_expedition`](#avis_expedition). Regroupe les lots reçus dans `reception_lot`, les réserves dans `reserve` et les écarts dans `constat_ecart`. |
| <a id="reception_lot"></a>`reception_lot` | Association entre une réception et les lots réellement livrés ; conserve quantité et état des palettes. | Référence une [`reception`](#reception) et un [`lot`](#lot). La clé primaire empêche de dupliquer le même lot dans une réception. |
| <a id="reserve"></a>`reserve` | Réserve formalisée pour une réception, avec motif, date et état de transmission au transporteur. | Référence une [`reception`](#reception). Les réserves de la réception sont supprimées avec celle-ci. |
| <a id="type_ecart"></a>`type_ecart` | Référentiel des catégories d'écarts calculés ou constatés (manquant, excédent, endommagé). | Peut qualifier plusieurs lignes de `constat_ecart`. |
| <a id="constat_ecart"></a>`constat_ecart` | Écart associé à une réception, un article et un type, avec quantité et description. | Association ternaire entre [`reception`](#reception), [`article`](#article) et [`type_ecart`](#type_ecart). La clé primaire identifie une combinaison unique des trois. |

## 5. Commandes, préparation et expéditions

| Table | Rôle | Liens et cardinalités |
|---|---|---|
| <a id="commande"></a>`commande` | Commande d'un client, avec dates, adresse de livraison et statut de traitement. | Référence un [`client`](#client). Contient plusieurs articles via `ligne_commande` et peut être liée à plusieurs [`liste_prelevement`](#liste_prelevement) et [`unite_expedition`](#unite_expedition). |
| <a id="ligne_commande"></a>`ligne_commande` | Association entre une commande et un article, avec la quantité demandée. | Référence une [`commande`](#commande) et un [`article`](#article) ; un article ne peut apparaître qu'une fois par commande. |
| <a id="liste_prelevement"></a>`liste_prelevement` | Liste de travail de préparation d'une commande, avec règle FIFO/FEFO, préparateur et état d'avancement. | Référence une [`commande`](#commande) et éventuellement un [`utilisateur`](#utilisateur) préparateur. Ses lignes de prélèvement sont détaillées dans `ligne_prelevement`. |
| <a id="ligne_prelevement"></a>`ligne_prelevement` | Quantité à prendre ou prise pour une ligne de préparation, à partir d'un lot et d'un emplacement précis. | Association ternaire entre [`liste_prelevement`](#liste_prelevement), [`lot`](#lot) et [`emplacement`](#emplacement). Elle mémorise l'ordre de prélèvement et les quantités prévue et réalisée. |
| <a id="expedition"></a>`expedition` | Opération de chargement des marchandises sur un camion, avec date et statut d'avancement. | Référence un [`passage`](#passage). Peut regrouper plusieurs [`unite_expedition`](#unite_expedition) et [`document_transport`](#document_transport). |
| <a id="unite_expedition"></a>`unite_expedition` | Colis ou palette identifié par son SSCC, affecté à une commande et éventuellement chargé sur une expédition. | Référence une [`commande`](#commande) et, facultativement, une [`expedition`](#expedition). Son contenu en lots est détaillé dans `contenu_unite`. |
| <a id="contenu_unite"></a>`contenu_unite` | Association indiquant les lots et quantités contenus dans chaque unité d'expédition. | Association N:N entre une [`unite_expedition`](#unite_expedition) et un [`lot`](#lot). |
| <a id="document_transport"></a>`document_transport` | Document émis pour une expédition : bon de livraison (BL) ou lettre de voiture (CMR). | Référence une [`expedition`](#expedition). Les documents sont supprimés avec l'expédition. |

## 6. Historique

| Table | Rôle | Liens et cardinalités |
|---|---|---|
| <a id="historique_statut"></a>`historique_statut` | Journal des changements de statut de rendez-vous, commande et quai, avec ancien/nouveau statut, horodatage et auteur éventuel. | Référence éventuellement un [`utilisateur`](#utilisateur). `objet` et `objet_id` désignent l'objet historisé sans clé étrangère : l'historique survit ainsi à la suppression de cet objet. Les déclencheurs l'alimentent et interdisent sa modification ou sa suppression. |

## 7. Fonctions SQL

Les fonctions ci-dessous sont définies dans [02_automatisation.sql](./02_automatisation.sql).
Elles lisent les données de la base et peuvent être utilisées dans des requêtes,
procédures et déclencheurs.

### `fn_palettes(p_article_id, p_quantite)`

Calcule le nombre de palettes nécessaires pour stocker une quantité d'un article.

- **Paramètres :** `p_article_id` est l'identifiant de l'article ; `p_quantite`
  est la quantité d'unités.
- **Calcul :** récupère `unites_par_colis × colis_par_palette` pour l'article,
  puis divise la quantité par ce nombre d'unités par palette.
- **Résultat :** entier non signé arrondi à la palette supérieure (`CEIL`).
  Toute palette partiellement remplie est donc comptée comme une palette occupée.
- **Utilisation :** calcul des capacités en palettes, notamment pour le stock
  d'un emplacement et les propositions de rangement.

### `fn_date_etape(p_passage_id, p_code)`

Recherche l'horodatage auquel un passage a franchi une étape donnée.

- **Paramètres :** `p_passage_id` identifie le passage ; `p_code` est le code
  de l'étape dans `type_etape` (par exemple `ARRIVEE` ou `DEPART`).
- **Calcul :** recherche la ligne correspondante dans `passage_etape` en
  reliant l'identifiant d'étape à son code dans `type_etape`.
- **Résultat :** date et heure (`DATETIME`) de l'étape, ou `NULL` si elle n'a
  pas été enregistrée pour ce passage.

### `fn_palettes_libres(p_emplacement_id)`

Calcule le nombre de places-palettes encore disponibles sur un emplacement.

- **Paramètre :** `p_emplacement_id` identifie l'emplacement à évaluer.
- **Calcul :** lit la capacité totale (`emplacement.capacite_palettes`), calcule
  pour chaque lot présent le nombre de palettes qu'il occupe avec `fn_palettes`,
  additionne ces occupations, puis soustrait le total à la capacité.
- **Résultat :** nombre entier de palettes libres. La fonction renvoie zéro
  comme occupation pour un emplacement vide ; si l'occupation dépasse la
  capacité déclarée, le résultat peut être négatif.
- **Utilisation :** contrôles de capacité et recherche d'emplacements de stockage.

### `fn_poids_libre(p_emplacement_id)`

Calcule la marge de poids encore admissible sur un emplacement, en kilogrammes.

- **Paramètre :** `p_emplacement_id` identifie l'emplacement à évaluer.
- **Calcul :** lit le poids maximal (`emplacement.poids_max_kg`), additionne
  pour tout le stock de l'emplacement `quantite × poids unitaire de l'article`,
  puis soustrait le poids présent au maximum autorisé.
- **Résultat :** poids restant sous forme décimale (`DECIMAL(12,3)`), ou le poids
  maximal pour un emplacement vide. Un résultat négatif indique un dépassement
  de la capacité déclarée.
- **Utilisation :** vérification du poids disponible lors du rangement et de
  la proposition d'emplacements.

## 8. Objets complémentaires

Le schéma contient également des séquences pour numéroter les documents métier.
Elles ne sont pas des tables : les déclencheurs de [02_automatisation.sql](./02_automatisation.sql)
les utilisent pour générer les numéros de rendez-vous, passages, avis,
réceptions, réserves, lots, mouvements, commandes, listes, expéditions et
documents de transport.

## Évènements programmés

Définis à la fin de [02_automatisation.sql](./02_automatisation.sql), ils sont exécutés
par le planificateur du serveur (`event_scheduler = ON`), sans action d'un utilisateur.

| Évènement | Fréquence | Rôle |
|---|---|---|
| `ev_quais_maintenance` | toutes les 15 minutes | Passe hors service un quai libre pendant une maintenance ou un nettoyage bloquant de la table [`evenement`](#evenement), puis le remet libre quand l'intervention est terminée. Chaque changement est journalisé dans [`historique_statut`](#historique_statut) sans auteur. |
| `ev_purge_evenements` | chaque mois, à 3 h | Supprime les évènements du planning terminés depuis plus d'un an. |
