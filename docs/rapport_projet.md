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

Le diagramme `uml/use_case_entrepot.png` décrit le système selon huit acteurs : administrateur, responsable d'exploitation, agent d'accueil, magasinier / préparateur, transporteur, client propriétaire, chauffeur et horloge. Il comprend aussi les acteurs génériques visiteur et utilisateur pour l'inscription et la connexion.

*[Insérer ici uml/use_case_entrepot.png]*

Nous avons ajouté trois acteurs au-delà du sujet :
- le **chauffeur**, sans compte, qui déclare son arrivée à une borne et suit l'écran d'appel du parking ;
- le **client propriétaire**, qui consulte son stock et passe ses commandes sur un portail ;
- l'**horloge**, qui représente les traitements planifiés de la base.

Quatre cas phares sont détaillés dans `fiches_cas_utilisation.md` : déclarer son arrivée à la borne, accueillir un camion sans rendez-vous, prélever les articles de sa liste et passer une commande.

## 3. Conception de la base de données

### 3.1 Modèle conceptuel

Le diagramme entité-association (`uml/diagramme_entite_association.png`) compte 34 entités et 50 associations.

*[Insérer ici uml/diagramme_entite_association.png]*

### 3.2 Modèle logique

Le modèle relationnel compte 46 tables : les 34 entités, les associations N:N devenues tables de jointure, et l'archive du journal. Le schéma `uml/schema_relationnel.png` le représente : clés primaires soulignées, clés étrangères préfixées par `#` et reliées par une flèche à la table référencée, valeurs uniques en italique. Le détail table par table figure dans `schema_relationnel.md`.

*[Insérer ici uml/schema_relationnel.png]*

Principes retenus :
- Chaque table a une clé primaire artificielle `id`, sauf les tables de jointure, dont la clé est composée des clés étrangères.
- Les identifiants métier (SIRET, immatriculation, numéro de rendez-vous…) sont déclarés `UNIQUE`. Ce sont eux qui sont affichés, jamais les `id`.
- Les contraintes `CHECK` protègent les valeurs : formats de SIRET et de code postal, dates cohérentes, quantités positives, cohérence source / destination d'un mouvement.
- Plusieurs associations N:N portent des attributs, par exemple `passage_etape` (horodatage) ou `ligne_commande` (quantité). `ligne_prelevement` est une association ternaire : liste × lot × emplacement.

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

`sql/installer.php` crée la base puis simule environ deux mois d'activité, soit environ 125 000 lignes, en passant par les déclencheurs et procédures. Les données respectent donc les règles métier : après installation, le stock correspond exactement à la somme des mouvements et aucun emplacement ne dépasse sa capacité.

## 4. Automatisation interne à la base

| Mécanisme | Nombre | Rôle |
|---|---:|---|
| Déclencheurs | 25 | Numérotation, contrôles métier, mise à jour du stock et des statuts, historisation |
| Procédures | 7 | Clôture de réception, génération et clôture de listes, proposition d'emplacements, archivage |
| Fonctions | 4 | Palettes occupées, palettes et poids libres, date d'une étape |
| Vues | 5 | Quais en temps réel, durées des passages, détail du stock, remplissage, dates limites |
| Évènements | 2 | Rendez-vous absents toutes les 15 min, archivage nocturne du journal |

**Historisation.** La table `mouvement_stock` est immuable : deux déclencheurs refusent toute modification ou suppression, et une correction passe par un ajustement. Le journal `journal_audit` conserve les modifications sensibles (rendez-vous, quais, articles, emplacements, comptes) avec leur auteur. Les entrées de plus de 180 jours sont déplacées dans `journal_audit_archive`.

## 5. Sécurité et confidentialité

- **Mots de passe** : hachés avec `password_hash` (bcrypt), jamais stockés en clair.
- **Injection SQL** : toutes les valeurs saisies passent par des requêtes préparées.
- **XSS** : chaque donnée affichée est échappée par la fonction `e()`.
- **CSRF** : tout formulaire porte un jeton vérifié à la réception.
- **Droits** : chaque page contrôle le profil côté serveur. Un transporteur ou un client ne voit que ses propres données, et un compte désactivé perd son accès à la page suivante.
- **Moindre privilège** : le site se connecte avec le compte `entrepot_app`, qui ne peut ni modifier la structure ni écrire dans `stock` ou le journal.
- **Erreurs** : les erreurs techniques sont écrites dans le journal PHP et jamais affichées.
- **Borne** : le message d'échec est volontairement générique et ne révèle pas quelle donnée est fausse.

Limites connues : le site n'impose pas HTTPS et ne bloque pas les comptes après plusieurs échecs de connexion (il ralentit seulement chaque tentative). Un déploiement réel devrait traiter ces deux points.

## 6. Performances

Les requêtes principales sont mesurées par `sql/performances.php` ; le détail figure dans `rapport_performances.md`. Toutes s'exécutent en quelques millisecondes sur le jeu de données fourni, à l'exception de la proposition d'emplacements (quelques dizaines de millisecondes), acceptable pour un écran de rangement.

Les index les plus utiles sont l'index composite `ix_rdv_quai_creneau`, qui rend le contrôle de chevauchement environ 12 fois plus rapide, et `ix_mvt_lot_date`, qui rend l'historique d'un lot environ 20 fois plus rapide.

## 7. Fonctionnalités réalisées

*Pour chaque fonctionnalité, ajouter une capture d'écran de la page indiquée.*

### 7.1 Authentification et profils

**Objectif.** Permettre au personnel de s'inscrire et de se connecter, avec des droits distincts.

**Travail réalisé.** Inscription avec contrôle des saisies, validation par l'administrateur, connexion, six profils et menu adapté à chacun. L'administrateur active, modifie, réinitialise et supprime les comptes (`inscription.php`, `connexion.php`, `utilisateurs.php`).

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

**Travail réalisé.** Tableau des camions sur site avec passage d'étape en un clic, arrivées avec ou sans rendez-vous, proposition d'un quai libre et compatible, historique avec durées, borne chauffeur et écran d'appel (`flux.php`, `passage_arrivee.php`, `passages.php`, `borne.php`, `ecran.php`).

**Conclusion.** L'ordre des étapes, la disponibilité du quai et sa compatibilité sont garantis par la base. La borne et l'écran d'appel dépassent le sujet.

### 7.5 Réception des marchandises

**Objectif.** Comparer ce qui est reçu à ce qui était annoncé et tracer les écarts.

**Travail réalisé.** Avis d'expédition, réception par lots avec dates limites et état des palettes, calcul automatique des écarts à la clôture, réserves au transporteur, rangement (`avis.php`, `receptions.php`, `reception.php`).

**Conclusion.** Le calcul des écarts est centralisé dans la procédure `cloturer_reception`. Une livraison non annoncée est gérée : ses articles ressortent en excédent.

### 7.6 Emplacements et stocks

**Objectif.** Savoir où est chaque lot et historiser chaque mouvement.

**Travail réalisé.** Recherche multicritère et plein texte, export CSV, carte des emplacements, proposition d'emplacements, transferts, ajustements d'inventaire, historique des mouvements (`stock.php`, `emplacements.php`, `mouvement_form.php`, `mouvements.php`).

**Conclusion.** Le stock n'évolue que par des mouvements, ce qui garantit sa cohérence. Compatibilité produit / zone, blocage et capacité sont contrôlés par la base. Par choix de traçabilité, un mouvement ne se supprime pas : il se corrige par un ajustement.

### 7.7 Préparation et expédition

**Objectif.** Sortir les marchandises selon une règle FIFO ou FEFO, jusqu'au chargement du camion.

**Travail réalisé.** Commandes clients, génération et affectation des listes, prélèvement avec signalement des manquants, colis et palettes avec numéro SSCC, chargement avec contrôle de la capacité du camion, bon de livraison et lettre de voiture imprimables (`commandes.php`, `preparation.php`, `liste.php`, `colisage.php`, `expedition.php`, `document.php`).

**Conclusion.** La chaîne est complète de la commande au départ du camion, qui fait passer la commande à « expédiée ». Cette partie n'était pas exigée dans l'interface.

### 7.8 Portail client

**Objectif.** Donner au client propriétaire un accès à ses seules données.

**Travail réalisé.** Consultation du stock et des dates limites, passage de commande limité au stock disponible, suivi et annulation (`client_stock.php`, `client_commandes.php`, `client_commande.php`).

**Conclusion.** Fonctionnalité supplémentaire. Chaque requête est filtrée par le client connecté.

### 7.9 Traçabilité et indicateurs

**Objectif.** Retrouver l'historique d'un lot et piloter l'activité.

**Travail réalisé.** `lot.php` retrace la réception, les emplacements, la commande et le camion. `indicateurs.php` présente sur une période les indicateurs du sujet, en chiffres, tableaux et graphiques, avec un rapport imprimable.

**Conclusion.** Tous les indicateurs demandés sont produits. Les graphiques fonctionnent sans connexion internet.

### 7.10 Choix de périmètre sur les suppressions

Les données d'historique ne sont pas supprimables depuis l'interface : mouvements de stock, passages, réceptions, expéditions et documents de transport. Les supprimer romprait la traçabilité exigée par le sujet. Ces objets se corrigent, s'annulent ou s'ajustent selon leur nature.

## 8. Conclusion

Le système couvre l'ensemble du sujet. Les fonctionnalités web minimales sont réalisées et étendues à la préparation, à l'expédition, au portail client et à la borne chauffeur.

Le choix le plus structurant a été de placer les règles métier dans la base : le stock, les quais et les étapes restent cohérents quel que soit le chemin d'écriture. Ce choix a un coût : la logique est répartie entre SQL et PHP, et le schéma dépend de MariaDB à cause des séquences.

Plusieurs améliorations resteraient possibles : la réaffectation automatique des rendez-vous d'un quai hors service, le blocage des comptes après plusieurs échecs de connexion, et un parcours de préparation sur terminal mobile.

*[À compléter par le groupe : répartition du travail et bilan personnel.]*
