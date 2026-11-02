# Cahier des charges — Système de gestion d'un entrepôt logistique

UV BD70 · RAZEK, TELLITEL, LUTZ

## 1. Contexte et objectif

Un entrepôt logistique reçoit des camions, stocke la marchandise de plusieurs clients propriétaires, puis prépare et expédie leurs commandes. Le système doit gérer ces opérations quotidiennes dans une base de données relationnelle, assurer la traçabilité complète des marchandises de leur arrivée à leur départ, et fournir aux responsables des indicateurs de pilotage.

## 2. Périmètre

| Domaine | Base de données | Interface web |
|---|---|---|
| Authentification et profils | Oui | Oui (requis) |
| Transporteurs, chauffeurs, véhicules | Oui | Oui |
| Quais et rendez-vous | Oui | Oui (requis) |
| Flux de camions | Oui | Oui (requis) |
| Réception des marchandises | Oui | Oui (requis) |
| Emplacements et stocks | Oui | Oui (requis) |
| Préparation et expédition | Oui | Oui |
| Traçabilité et indicateurs | Oui | Oui |

L'interface n'était exigée que pour les domaines marqués « requis » ; les autres ont été réalisés en supplément.

## 3. Acteurs

| Acteur | Rôle |
|---|---|
| Administrateur | Gère les comptes et les droits, consulte le journal. N'a aucun accès à l'exploitation |
| Responsable d'exploitation | Décrit l'entrepôt, gère les référentiels et les commandes, génère les listes de prélèvement, suit les indicateurs |
| Agent d'accueil | Tient le poste de garde : rendez-vous, arrivées, documents, appels à quai, départs |
| Magasinier / préparateur | Réceptionne, range, prélève, colise, charge les camions |
| Transporteur | Prend et suit ses propres rendez-vous |
| Client propriétaire | Consulte son stock, passe et suit ses commandes |
| Chauffeur | Sans compte : déclare son arrivée à la borne et lit l'écran d'appel |
| Horloge | Marque les rendez-vous non honorés, archive le journal |

## 4. Exigences fonctionnelles

**Comptes.** Le personnel s'inscrit ; l'administrateur valide le compte et son profil. Chaque profil n'accède qu'à ses écrans.

**Référentiels.** Ajouter, modifier, supprimer et rechercher les transporteurs, contacts, contrats, chauffeurs, habilitations, véhicules, clients, fournisseurs, articles et la structure de l'entrepôt (zones, allées, racks, emplacements).

**Quais et rendez-vous.**
- Un quai a un type (réception, expédition, mixte), des équipements et des types de véhicules acceptés.
- Deux rendez-vous ne se chevauchent jamais sur un même quai ni pour un même véhicule.
- Un rendez-vous respecte la compatibilité du véhicule et du sens de flux avec le quai.
- Le statut d'un quai (libre, réservé, occupé, hors service) est visible en temps réel.

**Flux de camions.**
- Chaque étape est horodatée : arrivée, contrôle des documents, parking, appel à quai, début et fin d'opération, départ.
- Les étapes suivent un ordre imposé.
- Les retards, temps d'attente et d'immobilisation sont calculés.
- Une arrivée sans rendez-vous reçoit un quai libre et compatible.

**Réception.**
- Les avis d'expédition annoncent les articles et quantités attendus.
- La réception enregistre les quantités, lots, dates limites et états des palettes.
- La clôture calcule les écarts (manquants, excédents, endommagés) ; des réserves peuvent être émises au transporteur.

**Stocks.**
- Le stock est tenu par article, lot et emplacement.
- Tout mouvement (entrée, transfert, sortie, ajustement) est historisé et ne peut être ni modifié ni supprimé.
- Un rangement respecte la compatibilité produit / zone, le blocage et la capacité de l'emplacement (palettes et poids).
- Le système propose des emplacements libres adaptés.

**Préparation et expédition.**
- Les listes de prélèvement appliquent la règle FIFO ou FEFO et sont affectées à un préparateur.
- Les colis et palettes sont constitués, puis chargés dans un camion sans dépasser sa capacité.
- Un bon de livraison et une lettre de voiture sont générés.

**Traçabilité et pilotage.**
- L'historique d'un lot retrace sa réception, ses emplacements, sa commande et son camion.
- Les indicateurs couvrent l'occupation des quais, les temps par transporteur, le remplissage par zone, le taux d'écart, la productivité des préparateurs et les dates limites proches.

## 5. Exigences générales et contraintes

| Exigence | Traduction dans le projet |
|---|---|
| SGBD imposé | MariaDB 10.4 |
| Normalisation | Base en 3NF, écarts justifiés dans le rapport |
| Données factices en quantité | Simulation de deux mois d'activité, environ 125 000 lignes |
| Sécurité et confidentialité | Mots de passe hachés, requêtes préparées, jetons CSRF, droits par profil, compte MySQL limité |
| Performances | Index ciblés, temps mesurés dans `rapport_performances.md` |
| Automatisation | Déclencheurs, procédures, évènements planifiés, journal d'audit |
| Interface conviviale | Menu par profil, formulaires contrôlés, messages d'erreur lisibles |

## 6. Livrables

Diagramme de cas d'utilisation, schéma entité-association, schéma relationnel, script d'installation avec données factices, pages web, rapport de projet, rapport de performances et soutenance.
