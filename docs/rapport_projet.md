# Système de gestion d'un entrepôt logistique

**Rapport de projet — UV BD70, Conception et administration des bases de données**

Auteurs : RAZEK, TELLITEL, LUTZ *(prénoms à compléter)*
Enseignant : Alexandre Lombard — UTBM

## Table des matières

1. Introduction
2. Analyse : cas d'utilisation
3. Conception de la base de données
4. Automatisation interne à la base
5. Sécurité et confidentialité
6. Performances
7. Fonctionnalités réalisées
8. Conclusion

Annexes : `cahier_des_charges.md`, `fiches_cas_utilisation.md`, `schema_relationnel.md`, `rapport_performances.md`.

---

## 1. Introduction

Ce projet consiste à concevoir et développer un système de base de données relationnelle pour les opérations quotidiennes d'un entrepôt logistique : flux de camions, quais, transporteurs, réception, stockage et expédition. Le système doit assurer une traçabilité complète des marchandises et fournir des indicateurs de pilotage.

Nous avons réalisé une application web en PHP 8 avec PDO, sur une base MariaDB 10.4. La logique métier critique est placée dans la base : les règles s'appliquent ainsi quel que soit le chemin d'écriture, y compris lors de la génération des données factices.

La démarche a suivi les étapes vues en cours : analyse par les cas d'utilisation, modèle conceptuel, modèle relationnel, implémentation, puis mesure des performances. Le détail des besoins figure dans le cahier des charges en annexe.

## 2. Analyse : cas d'utilisation

Le diagramme `uml/use_case_entrepot.drawio` décrit le système selon les cinq acteurs du sujet : administrateur, responsable d'exploitation, agent d'accueil, magasinier / préparateur et transporteur. Ils héritent tous de l'acteur générique « Utilisateur », qui se connecte ; le « Visiteur » peut seulement s'inscrire. Le diagramme compte 47 cas d'utilisation, regroupés selon les étapes du sujet, avec leurs relations `«include»` et `«extend»` (les extensions portent leur condition).

*[Insérer ici le diagramme de cas d'utilisation]*

Nous nous sommes limités au périmètre du sujet : les clients propriétaires, fournisseurs et chauffeurs sont des données du système, pas des acteurs, et il n'y a ni portail client, ni borne chauffeur, ni journal d'audit.

Quatre cas phares sont détaillés dans `fiches_cas_utilisation.md` : prendre un rendez-vous, accueillir un camion sans rendez-vous, enregistrer la réception et prélever les articles d'une liste. Le même document donne la correspondance entre chaque cas d'utilisation et les entités et associations du modèle conceptuel, ce qui garantit l'alignement des deux diagrammes.

## 3. Conception de la base de données

### 3.1 Modèle conceptuel

Le diagramme entité-association (`uml/diagramme_entite_association.drawio`) compte 33 entités et 46 associations, dont deux ternaires.

*[Insérer ici le diagramme entité-association]*

**Associations ternaires.** Deux faits du sujet mettent en jeu trois entités à la fois :
- **Constater** (Réception, Article, Type d'écart) : un écart de réception est un constat portant sur un article d'une réception, pour un type d'écart (manquant, excédent, endommagé), avec la quantité et une description. Aucune des trois entités ne suffit seule à l'identifier : la même réception a plusieurs articles en écart, et un même article peut être à la fois manquant et endommagé.
- **Prélever** (Liste de prélèvement, Lot, Emplacement) : une ligne de prélèvement sort un lot précis d'un emplacement précis pour une liste, avec la quantité à prélever et la quantité prélevée. Un même lot peut être réparti sur plusieurs emplacements, et une liste peut puiser plusieurs lots du même article.

À l'inverse, **Stocker** reste binaire (Emplacement, Lot) : l'article se déduit du lot (dépendance fonctionnelle Lot → Article), et l'ajouter à l'association violerait la 3NF.

La **réserve** émise auprès du transporteur est rattachée à la réception (association « Porter sur ») : elle concerne la livraison dans son ensemble, et son motif décrit les écarts constatés.

### 3.2 Modèle logique

Le modèle relationnel compte 45 tables : les 33 entités et les associations N:N ou ternaires devenues tables de jointure. Le schéma `uml/schema_relationnel.drawio` le représente : clés primaires soulignées, clés étrangères préfixées par `#` et reliées par une flèche à la table référencée, valeurs uniques en italique. Le détail table par table figure dans `schema_relationnel.md`.

*[Insérer ici le schéma relationnel]*

Principes retenus :
- Chaque table a une clé primaire artificielle `id`, sauf les tables de jointure, dont la clé est composée des clés étrangères.
- Les identifiants métier (SIRET, immatriculation, numéro de rendez-vous…) sont déclarés `UNIQUE`. Ce sont eux qui sont affichés, jamais les `id`.
- Les contraintes `CHECK` protègent les valeurs : formats de SIRET et de code postal, dates cohérentes, quantités positives, cohérence source / destination d'un mouvement.
- Plusieurs associations N:N portent des attributs, par exemple `passage_etape` (horodatage) ou `ligne_commande` (quantité). Les deux ternaires deviennent `constat_ecart` (clé : réception, article, type d'écart) et `ligne_prelevement` (liste, lot, emplacement).

### 3.3 Normalisation

La base respecte la 1NF : toutes les valeurs sont atomiques et les listes (équipements d'un quai, habilitations d'un chauffeur) sont des tables de jointure. Elle respecte la 2NF : dans les tables à clé composée, les attributs dépendent de toute la clé. Elle respecte la 3NF, sous réserve des choix ci-dessous, tous délibérés.

| Donnée | Pourquoi elle pourrait sembler redondante | Justification |
|---|---|---|
| `stock.quantite` | Se déduit de la somme des mouvements | Synthèse maintenue exclusivement par déclencheur, pour éviter de sommer tout l'historique. L'application ne peut pas y écrire |
| `passage.etape_courante_id` | Se déduit de `passage_etape` | Évite une sous-requête dans chaque écran temps réel. Maintenue par déclencheur |
| `quai.statut` | « occupé » se déduit des passages | Maintenu par déclencheur. « Hors service » est une saisie, et « réservé », dépendant de l'heure, reste calculé par la vue `v_quai_temps_reel` |
| `passage.vehicule_id` | Présent aussi sur le rendez-vous | Le passage décrit le véhicule réel, le rendez-vous le véhicule prévu. Un passage peut aussi exister sans rendez-vous |
| `passage.sens` | Présent aussi sur le rendez-vous | Même raison : une arrivée sans rendez-vous doit porter son sens |
| `commande.adresse_livraison` | Ressemble à l'adresse du client | L'adresse de livraison est propre à la commande et peut différer du siège |
| `liste_prelevement.statut` | Lié à `debut` et `fin` | Statut explicite du traitement, lisible et filtrable ; la contrainte `CHECK` assure la cohérence des dates |
| `reception.statut` | Lié à `fin` | Une date de fin peut être saisie avant la clôture, qui reste une action distincte déclenchant le calcul des écarts |
| `code_postal` et `ville` | Le code postal semble déterminer la ville | Ce n'est pas une dépendance fonctionnelle : un code postal peut couvrir plusieurs communes |

### 3.4 Données factices

`sql/installer.php` crée la base puis simule environ deux mois d'activité, soit environ 120 000 lignes, en passant par les déclencheurs et procédures. Les données respectent donc les règles métier : après installation, le stock correspond exactement à la somme des mouvements et aucun emplacement ne dépasse sa capacité.

## 4. Automatisation interne à la base

| Mécanisme | Nombre | Rôle |
|---|---:|---|
| Déclencheurs | 18 | Numérotation, contrôles métier, mise à jour du stock et des statuts, immuabilité des mouvements |
| Procédures | 5 | Contrôle d'un rendez-vous, clôture de réception, génération et clôture de listes, proposition d'emplacements |
| Fonctions | 4 | Palettes occupées, palettes et poids libres, date d'une étape |
| Vues | 5 | Quais en temps réel, durées des passages, détail du stock, remplissage, dates limites |

**Historisation.** La table `mouvement_stock` est immuable : deux déclencheurs refusent toute modification ou suppression, et une correction passe par un ajustement. Chaque mouvement garde sa date, son utilisateur et son motif, et chaque passage garde l'horodatage de toutes ses étapes : l'historique d'un lot se reconstitue de sa réception jusqu'au camion qui l'a emporté.

## 5. Sécurité et confidentialité

- **Mots de passe** : hachés avec `password_hash` (bcrypt), jamais stockés en clair.
- **Injection SQL** : toutes les valeurs saisies passent par des requêtes préparées.
- **XSS** : chaque donnée affichée est échappée par la fonction `e()`.
- **CSRF** : tout formulaire porte un jeton vérifié à la réception.
- **Droits** : chaque page contrôle le profil côté serveur. Un transporteur ne voit que ses propres rendez-vous, et un compte désactivé perd son accès à la page suivante.
- **Moindre privilège** : le site se connecte avec le compte `entrepot_app`, qui ne peut ni modifier la structure ni écrire dans `stock`, tenu uniquement par les déclencheurs.
- **Erreurs** : les erreurs techniques sont écrites dans le journal d'erreurs PHP et jamais affichées.

Limites connues : le site n'impose pas HTTPS et ne bloque pas les comptes après plusieurs échecs de connexion (il ralentit seulement chaque tentative). Un déploiement réel devrait traiter ces deux points.

## 6. Performances

Les requêtes principales sont mesurées par `sql/performances.php` ; le détail figure dans `rapport_performances.md`. Toutes s'exécutent en quelques millisecondes sur le jeu de données fourni, à l'exception de la proposition d'emplacements (quelques dizaines de millisecondes), acceptable pour un écran de rangement.

Les index les plus utiles sont l'index composite `ix_rdv_quai_creneau`, qui rend le contrôle de chevauchement environ 12 fois plus rapide, et `ix_mvt_lot_date`, qui rend l'historique d'un lot environ 20 fois plus rapide.

## 7. Fonctionnalités réalisées

*Pour chaque fonctionnalité, ajouter une capture d'écran de la page indiquée.*

### 7.1 Authentification et profils

**Objectif.** Permettre au personnel de s'inscrire et de se connecter, avec des droits distincts.

**Travail réalisé.** Inscription avec contrôle des saisies, validation par l'administrateur, connexion, cinq profils et menu adapté à chacun. L'administrateur active, modifie, réinitialise et supprime les comptes (`inscription.php`, `connexion.php`, `utilisateurs.php`).

**Conclusion.** L'usage multi-utilisateurs est complet. L'administrateur est volontairement exclu de l'exploitation.

### 7.2 Référentiels

**Objectif.** Maintenir les transporteurs, chauffeurs, véhicules, clients, fournisseurs, articles et la structure de l'entrepôt.

**Travail réalisé.** Un écran générique `referentiel.php`, piloté par `includes/referentiels.php`, offre liste, recherche, filtres, ajout, modification et suppression, avec contrôle des saisies.

**Conclusion.** Cet écran unique assure un comportement homogène. Un élément encore utilisé ne peut pas être supprimé, et l'utilisateur en est informé.

### 7.3 Quais et rendez-vous

**Objectif.** Planifier les quais sans chevauchement et suivre leur statut.

**Travail réalisé.** Planning journalier, prise et modification de rendez-vous avec recherche des quais compatibles et libres, recherche multicritère, export CSV, gestion des quais et de leur statut (`planning.php`, `rdv.php`, `rdv_form.php`, `quais.php`).

**Conclusion.** Les règles sont vérifiées par déclencheur : le chevauchement est impossible, même hors de l'interface. La mise hors service d'un quai signale les rendez-vous à déplacer, sans les déplacer automatiquement.

### 7.4 Flux de camions

**Objectif.** Horodater chaque étape du passage d'un camion et mesurer les temps.

**Travail réalisé.** Tableau des camions sur site avec passage d'étape en un clic, arrivées avec ou sans rendez-vous, proposition d'un quai libre et compatible, historique avec durées (`flux.php`, `passage_arrivee.php`, `passage.php`, `passages.php`).

**Conclusion.** L'ordre des étapes, la disponibilité du quai et sa compatibilité sont garantis par la base : un camion ne peut pas commencer son (dé)chargement sans avoir été appelé à quai.

### 7.5 Réception des marchandises

**Objectif.** Comparer ce qui est reçu à ce qui était annoncé et tracer les écarts.

**Travail réalisé.** Avis d'expédition, réception par lots avec dates limites et état des palettes, calcul automatique des écarts à la clôture (constats par article et type d'écart), déclaration manuelle d'un écart, réserves au transporteur, rangement (`avis.php`, `receptions.php`, `reception.php`).

**Conclusion.** Le calcul des écarts est centralisé dans la procédure `cloturer_reception`. Une livraison non annoncée est gérée : ses articles ressortent en excédent.

### 7.6 Emplacements et stocks

**Objectif.** Savoir où est chaque lot et historiser chaque mouvement.

**Travail réalisé.** Recherche multicritère et plein texte, export CSV, carte des emplacements, proposition d'emplacements, transferts, ajustements d'inventaire, historique des mouvements (`stock.php`, `emplacements.php`, `mouvement_form.php`, `mouvements.php`).

**Conclusion.** Le stock n'évolue que par des mouvements, ce qui garantit sa cohérence. Compatibilité produit / zone, blocage et capacité sont contrôlés par la base. Par choix de traçabilité, un mouvement ne se supprime pas : il se corrige par un ajustement.

### 7.7 Préparation et expédition

**Objectif.** Sortir les marchandises selon une règle FIFO ou FEFO, jusqu'au chargement du camion.

**Travail réalisé.** Commandes clients, génération et affectation des listes, prélèvement avec signalement des manquants, colis et palettes avec numéro SSCC, chargement avec contrôle de la capacité du camion, bon de livraison et lettre de voiture imprimables (`commandes.php`, `preparation.php`, `liste.php`, `colisage.php`, `expedition.php`, `document.php`).

**Conclusion.** La chaîne est complète de la commande au départ du camion, qui fait passer la commande à « expédiée ». Cette partie n'était pas exigée dans l'interface.

### 7.8 Traçabilité et indicateurs

**Objectif.** Retrouver l'historique d'un lot et piloter l'activité.

**Travail réalisé.** `lot.php` retrace la réception, les emplacements, la commande et le camion. `indicateurs.php` présente sur une période les indicateurs du sujet, en chiffres, tableaux et graphiques, avec un rapport imprimable.

**Conclusion.** Tous les indicateurs demandés sont produits. Les graphiques fonctionnent sans connexion internet.

### 7.9 Choix de périmètre sur les suppressions

Les données d'historique ne sont pas supprimables depuis l'interface : mouvements de stock, passages, réceptions, expéditions et documents de transport. Les supprimer romprait la traçabilité exigée par le sujet. Ces objets se corrigent, s'annulent ou s'ajustent selon leur nature.

## 8. Conclusion

Le système couvre l'ensemble du sujet. Les fonctionnalités web minimales sont réalisées, ainsi que la préparation et l'expédition, qui font partie du sujet. Nous n'avons rien ajouté au-delà : le diagramme de cas d'utilisation, le modèle conceptuel, la base et le site décrivent le même périmètre.

Le choix le plus structurant a été de placer les règles métier dans la base : le stock, les quais et les étapes restent cohérents quel que soit le chemin d'écriture. Ce choix a un coût : la logique est répartie entre SQL et PHP, et le schéma dépend de MariaDB à cause des séquences.

Plusieurs améliorations resteraient possibles : la réaffectation automatique des rendez-vous d'un quai hors service, le blocage des comptes après plusieurs échecs de connexion, et un parcours de préparation sur terminal mobile.

*[À compléter par le groupe : répartition du travail et bilan personnel.]*
