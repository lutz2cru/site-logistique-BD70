# Système de gestion d'un entrepôt logistique — BD70

Application web (PHP 8 + PDO, MariaDB 10.4+) de gestion des opérations quotidiennes d'un entrepôt :
quais et rendez-vous, flux de camions, réception des marchandises, emplacements et stocks,
préparation et expédition des commandes, traçabilité et indicateurs de pilotage.

Le schéma utilise `CREATE SEQUENCE`, propre à MariaDB : il ne s'installe pas sous MySQL.

## Installation (XAMPP)

1. Démarrer **MySQL** depuis le panneau XAMPP.
2. Créer la base et la remplir de données factices (≈ 2 mois d'activité simulée, ~120 000 lignes, ~1 min 30) :

   ```bash
   C:\xampp\php\php.exe sql/installer.php
   ```

   Options : `--host=`, `--user=`, `--pass=` (par défaut `localhost` / `root` / vide).
   L'installateur exécute `sql/01_schema.sql` puis `sql/02_automatisation.sql`, puis simule l'activité **en passant par les déclencheurs et
   procédures** : les données respectent donc toutes les règles métier. Il crée aussi le compte
   `entrepot_app`, utilisé par le site.
3. Lancer le site, au choix :
   - `C:\xampp\php\php.exe -S localhost:8080 -t www` puis ouvrir <http://localhost:8080> ;
   - ou copier le dossier `www` dans `C:\xampp\htdocs\entrepot` et ouvrir <http://localhost/entrepot>.

Les paramètres de connexion sont dans `www/config.php`. Le site n'utilise pas `root` : `entrepot_app`
peut lire la base et écrire dans les tables métier, mais ni dans `stock` (tenu par les
déclencheurs) ni dans la structure. Son mot de passe, de démonstration, est à changer pour un vrai
déploiement (dans `sql/installer.php` et `www/config.php`).

## Comptes de démonstration

Mot de passe commun : **`demo1234`**

| Compte | Profil |
|---|---|
| `admin@entrepot.test` | Administrateur du système d'information : comptes et droits (aucun accès à l'exploitation) |
| `responsable@entrepot.test` | Responsable d'exploitation : quais, structure, catalogue, clients, fournisseurs, transporteurs, commandes clients, listes de prélèvement, indicateurs et productivité des équipes ; ajuste les rendez-vous existants, consulte le planning, les flux, les réceptions et les avis d'expédition |
| `accueil@entrepot.test` | Agent d'accueil (poste de garde) : rendez-vous, arrivées et départs des camions, chauffeurs et véhicules, statut des quais |
| `magasinier@entrepot.test` | Magasinier / préparateur : avis d'expédition, réception, rangement, stocks, exécution de ses listes de prélèvement, colis et palettes, chargement des camions sortants |
| `transporteur@entrepot.test` | Transporteur (ses rendez-vous uniquement) |

Deux inscriptions sont en attente de validation (menu *Administration › Utilisateurs*).

## Structure

```
sql/
  01_schema.sql            modèle relationnel : 45 tables, clés, contraintes CHECK, index
  02_automatisation.sql    fonctions, procédures, déclencheurs, vues
  installer.php            installation + génération des données factices
  performances.php         mesure des requêtes principales (écrit les mesures dans docs/rapport_performances.md)
  schema_relationnel.php   liste les tables de la base installée (écrit docs/schema_relationnel.md)
www/                       site web (une page PHP par écran, gabarit commun dans includes/)
docs/                      documents Word : rapport de projet, cahier des charges, fiches de cas
                           d'utilisation, schéma relationnel, rapport de performances
uml/                       diagramme de cas d'utilisation, diagramme entité-association,
                           schéma relationnel (fichiers SVG)
sujet/                     énoncé du projet (A26) et grille d'évaluation SI70
```

## Fonctionnalités web

| Module | Écrans |
|---|---|
| Authentification | connexion, inscription (validée par l'administrateur), 5 profils aux droits distincts, gestion des comptes |
| Quais et rendez-vous | planning journalier des quais, prise / modification / annulation de RDV avec recherche des quais compatibles et libres, gestion des quais (équipements, véhicules acceptés, hors service) |
| Flux de camions | arrivées avec ou sans RDV (détection des retards), tableau des camions sur site avec horodatage de chaque étape en un clic, proposition d'un quai libre compatible, historique et durées (attente, immobilisation) |
| Réception | avis d'expédition, réception par lots (DLC, état des palettes), calcul automatique des écarts à la clôture, réserves au transporteur, rangement |
| Emplacements et stocks | recherche multicritère (plein texte, client, zone, DLC…), export CSV, carte des emplacements, transferts, ajustements d'inventaire, historique des mouvements, traçabilité complète d'un lot |
| Commandes et préparation | commandes clients (ajout, modification, annulation, suppression, recherche), génération FIFO/FEFO et affectation des listes, prélèvement avec signalement des manquants |
| Expédition | constitution des colis et palettes (SSCC), chargement du camion avec contrôle de sa capacité, bon de livraison et lettre de voiture imprimables, passage à « expédiée » au départ du camion |
| Référentiels | ajout / modification / suppression / recherche pour transporteurs, contacts, contrats, chauffeurs, véhicules, articles, clients, fournisseurs, zones, allées, racks, emplacements… |
| Pilotage | indicateurs sur période (occupation des quais, attentes par transporteur, remplissage par zone, taux d'écart fournisseurs, productivité des préparateurs, DLC proches), graphiques, rapport imprimable |

Le périmètre est celui du sujet : les clients propriétaires, fournisseurs et chauffeurs sont des
données gérées par le personnel et n'ont pas de compte. Le départ du camion passe l'expédition
à « partie » et ses commandes entièrement chargées à « expédiée ». Une expédition reçoit un bon
de livraison et une lettre de voiture, qui regroupent toutes ses commandes.

## Automatisation dans la base

- **Numérotation** des documents (RDV-, PAS-, REC-, MVT-…) par séquences dans les déclencheurs.
- **Contrôles métier** : chevauchement de RDV sur un quai ou pour un véhicule, compatibilité véhicule / quai / sens
  (au rendez-vous et à l'appel à quai), ordre chronologique des étapes, quai libre à l'appel, véhicule déjà sur site,
  stock suffisant, emplacement bloqué, capacité de l'emplacement (palettes et poids), compatibilité produit / zone.
- **Mises à jour automatiques** : statut des quais et des RDV selon les étapes, stock recalculé à chaque mouvement,
  avis réceptionné et écarts calculés à la clôture de la réception (constats par article et type d'écart).
- **Historisation** : `mouvement_stock` immuable (daté, avec son auteur et son motif), étapes des passages horodatées.
