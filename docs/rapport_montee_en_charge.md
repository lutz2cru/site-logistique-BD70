# Montée en charge

Mesures de `sql/performances.php` (médiane de 20 exécutions) sur la base de démonstration, puis après ajout d'un historique ancien par `sql/montee_en_charge.php`.

## Volumes

| Palier | `mouvement_stock` | `rendez_vous` | `lot` | Toute la base (estimation) | Mesuré le |
|---|---:|---:|---:|---:|---|
| base | 16 844 | 3 759 | 4 077 | 117 745 | 06/11/2026 09:46 |
| 1M avant | 1 016 844 | 203 759 | 104 077 | 1 415 756 | 06/11/2026 09:53 |
| 5M avant | 5 016 844 | 903 759 | 504 077 | 6 494 183 | 06/11/2026 10:23 |
| 5M après | 5 016 844 | 903 759 | 504 077 | 6 494 183 | 06/11/2026 10:25 |

## Temps médian avec index (et sans index, entre parenthèses)

| Requête | base | 1M avant | 5M avant | 5M après |
|---|---:|---:|---:|---:|
| Planning d'une journée | 0,57 ms (1,50 ms) | 0,26 ms (1 895,26 ms) | 0,35 ms (5 065,19 ms) | 0,35 ms (198,43 ms) |
| Contrôle de chevauchement | 0,09 ms (1,29 ms) | 3,19 ms (343,28 ms) | 6,09 ms (958,85 ms) | 0,22 ms (0,11 ms) |
| Contrôle du véhicule | 0,11 ms | 23,48 ms | 59,00 ms | 0,10 ms (0,11 ms) |
| Prise d'un rendez-vous | 0,48 ms | 34,63 ms | 101,69 ms | 0,41 ms |
| Camions sur site | 0,31 ms | 0,55 ms | 0,46 ms | 0,41 ms |
| État des quais en temps réel | 2,81 ms | 19,99 ms | 20,94 ms | 6,54 ms |
| Recherche plein texte (1re version) | 0,47 ms (2,44 ms) | 12,04 ms (6,88 ms) | 10,80 ms (3,45 ms) | 10,02 ms (3,26 ms) |
| Recherche plein texte (version retenue) | 0,06 ms (0,09 ms) | 0,15 ms (0,24 ms) | 0,12 ms (0,15 ms) | 0,10 ms (0,15 ms) |
| Étape 2 : stock des articles trouvés (stock.php) | 0,31 ms | 2,37 ms | 2,77 ms | 2,58 ms |
| Historique d'un lot | 0,10 ms (1,67 ms) | 0,13 ms (229,07 ms) | 0,16 ms (830,92 ms) | 0,15 ms (833,96 ms) |
| Mouvements des 7 derniers jours | 0,88 ms (1,65 ms) | 1,04 ms (219,32 ms) | 1,35 ms (759,63 ms) | 0,88 ms (666,87 ms) |
| Temps moyens par transporteur (30 j) | 3,42 ms | 8,94 ms | 5,85 ms | 3,77 ms |
| Remplissage par zone | 5,61 ms | 9,80 ms | 6,08 ms | 6,06 ms |
| Taux d'écart par fournisseur | 2,31 ms | 3,26 ms | 2,64 ms | 2,50 ms |
| Lots proches de la DLC | 0,71 ms | 0,80 ms | 0,74 ms | 0,70 ms |
| Proposition d'emplacements | 32,44 ms | 64,28 ms | 34,91 ms | 32,52 ms |
| Stock disponible d'un client | 1,20 ms | 22,97 ms | 63,08 ms | 50,29 ms |
| Stock disponible (version retenue) | – | – | 0,94 ms | 0,90 ms |
| Contenu d'une expédition | 0,11 ms | 0,20 ms | 0,12 ms | 0,09 ms |

« avant » : schéma d'origine ; « après » : corrections décrites ci-dessous. Entre parenthèses, la même
requête avec `IGNORE INDEX` sur l'index qu'elle doit utiliser.

## Méthode

`sql/montee_en_charge.php` ajoute un historique ancien, daté avant les jours simulés par l'installateur :
plusieurs années de rendez-vous (créneaux de 30 min sur chaque quai) et de mouvements de stock (lots
d'archive entrés puis sortis). Les écrans du jour sont inchangés : seules les tables d'historique
grossissent, comme dans un entrepôt en service depuis quelques années. Les lignes sont produites en SQL
(`INSERT … SELECT` sur les tables de nombres `seq_1_to_N` de MariaDB, par paquets de 50 000) et passent
par les déclencheurs : elles respectent toutes les règles métier. Débit observé : environ 4 000 mouvements
par seconde (≈ 20 min pour 5 millions).

## Ce que le test a montré

1. **Les index bien choisis ne dépendent pas du volume.** Historique d'un lot ou mouvements des 7 derniers
   jours restent autour de 0,1 à 1 ms de 17 000 à 5 millions de mouvements, alors que la même requête sans
   index passe à environ 800 ms : l'index lit quelques lignes, le parcours complet lit toute la table.
2. **Un index composite ne suffit pas pour un intervalle.** Le contrôle de chevauchement
   (`r.debut < fin AND r.fin > debut`) utilisait bien `ix_rdv_quai_creneau`, mais la condition `debut < fin`
   ne borne l'index que d'un côté : MariaDB lisait tout l'historique du quai (6 ms à 5 millions de lignes,
   contre 0,09 ms au départ). Correction : un rendez-vous dure au plus 4 h (nouvelle contrainte
   `ck_rdv_creneau`, la règle existait déjà dans le formulaire), on ajoute donc `r.debut > debut - 4 h` ;
   la lecture de l'index est bornée des deux côtés. Même correction dans la vue `v_quai_temps_reel` et dans
   la recherche des quais libres (`rdv_form.php`).
3. **Un index manquait.** Le second contrôle du déclencheur (le véhicule n'a pas déjà un rendez-vous) ne
   disposait que de l'index de la clé étrangère `vehicule_id` : il lisait tous les rendez-vous du véhicule.
   Ajout de `ix_rdv_vehicule_creneau (vehicule_id, debut, fin)`. Avec les deux corrections, la prise d'un
   rendez-vous passe de 102 ms à 0,4 ms à 5 millions de lignes, et le chargement des rendez-vous d'archive
   de 3 700 à 12 700 lignes par seconde.
4. **Une sous-requête par ligne se dégrade avec l'historique.** Le stock disponible d'un client (saisie de
   commande) sommait, pour chaque article, le stock de tous ses lots, y compris les lots sortis depuis
   longtemps : 1,2 ms au départ, 50 ms à 500 000 lots. Version retenue : on regroupe d'abord la table
   `stock` (quelques centaines de lignes) et les prélèvements en cours par article, puis on joint : 0,9 ms
   quel que soit l'historique, pour un résultat identique.
5. **Statistiques de l'optimiseur.** Juste après l'ajout de l'index, sans `ANALYZE TABLE`, l'optimiseur
   estimait un seul rendez-vous par véhicule et choisissait ce nouvel index pour le planning (40 ms au lieu
   de 0,3 ms). Après `ANALYZE TABLE`, il revient au bon plan : le script de montée en charge l'exécute à la fin.

## Limite repérée

Les numéros de rendez-vous sont produits par `LPAD(NEXT VALUE FOR seq_rdv, 6, '0')` : au-delà de 999 999
rendez-vous, `LPAD` tronque le numéro et la contrainte d'unicité refuse l'insertion. Le test reste sous ce
seuil (≈ 6 ans d'activité au rythme simulé) ; passer à 7 chiffres suffirait (`numero` fait 12 caractères).
