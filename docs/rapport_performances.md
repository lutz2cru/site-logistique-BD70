# Rapport d'analyse des performances

Mesures réalisées le 28/10/2026 à 17:15 sur MariaDB 10.4.32-MariaDB (poste local), base alimentée par `sql/installer.php`.
Chaque requête est exécutée 20 fois après une exécution de chauffe ; le temps retenu est la **médiane** (en millisecondes, récupération des lignes comprise).

## Volumétrie

| Table | Lignes (estimation InnoDB) |
|---|---:|
| `passage_etape` | 22 092 |
| `mouvement_stock` | 16 290 |
| `ligne_prelevement` | 11 546 |
| `lot` | 3 695 |
| `rendez_vous` | 3 650 |
| `commande` | 3 333 |
| `passage` | 3 270 |
| `reception` | 1 613 |
| `stock` | 859 |

## Requêtes principales

| # | Requête | Temps médian | Sans index | Plan d'exécution |
|---|---|---:|---:|---|
| 1 | **Planning d'une journée** — Rendez-vous non annulés du jour, tous quais (planning.php) | 1.41 ms | 3.24 ms (×2.3) | t : index `ix_transporteur_raison` (18 l.) ; v : index `fk_vehicule_transporteur` (1 l.) ; r : index `fk_rdv_vehicule|ix_rdv_debut` (1 (2%) l.) |
| 2 | **Contrôle de chevauchement** — Test exécuté par le déclencheur à chaque prise de rendez-vous | 0.22 ms | 2.46 ms (×11.4) |  :  ( l.) ; r : index `ix_rdv_quai_creneau` (227 l.) |
| 3 | **Camions sur site** — Passages dont la dernière étape n'est pas le départ (flux.php) | 2.76 ms | – | v : index `immatriculation` (123 l.) ; p : index `fk_passage_vehicule` (13 l.) ; te : index `PRIMARY` (1 l.) |
| 4 | **État des quais en temps réel** — Vue v_quai_temps_reel (statut calculé, camion à quai, prochain RDV) | 5.16 ms | – | q : parcours complet (16 l.) ; r : index `ix_rdv_quai_creneau` (1 l.) ; p : index `fk_passage_quai` (102 l.) ; v : index `PRIMARY` (1 l.) |
| 5 | **Recherche plein texte (1re version)** — MATCH … AGAINST en sous-requête ; comparé à un LIKE '%…%' | 19.37 ms | 4.87 ms (×0.3) | article : index `ft_article_designation` (1 l.) ; ar : index `PRIMARY` (1 l.) ; c : index `PRIMARY` (1 l.) ; z : index `code` (4 l.) |
| 6 | **Recherche plein texte (version retenue)** — Étape 1 : articles par MATCH … AGAINST (index FULLTEXT) | 0.14 ms | 0.23 ms (×1.7) | article : index `ft_article_designation` (1 l.) |
| 7 | ↳ Étape 2 : stock des articles trouvés (stock.php) | 3.86 ms | – | z : index `code` (4 l.) ; a : index `fk_allee_zone` (1 l.) ; r : index `fk_rack_allee` (1 l.) ; e : index `fk_emplacement_rack` (1 l.) |
| 8 | **Historique d'un lot** — Mouvements d'un lot par ordre chronologique (lot.php) | 0.26 ms | 3.45 ms (×13.4) | mouvement_stock : index `ix_mvt_lot_date` (27 l.) |
| 9 | **Mouvements des 7 derniers jours** — Historique filtré par date (mouvements.php) | 2.10 ms | 3.26 ms (×1.6) | mouvement_stock : index `ix_mvt_date` (2204 l.) |
| 10 | **Temps moyens par transporteur (30 j)** — Agrégation de v_passage_durees (indicateurs.php) | 6.95 ms | – | v : index `fk_vehicule_transporteur` (123 l.) ; p : index `fk_passage_vehicule` (13 l.) ; arr : index `PRIMARY` (1 l.) ; appel : index `PRIMARY` (1 l.) |
| 11 | **Remplissage par zone** — Vue v_remplissage_zone (somme des palettes par zone) | 12.54 ms | – | z : parcours complet (4 l.) ; <derived4> : index `key0` (2 l.) ; <derived3> : index `key0` (2 l.) ; z : index `PRIMARY` (1 l.) |
| 12 | **Taux d'écart par fournisseur** — Vue v_taux_ecart_fournisseur | 5.57 ms | – | <derived2> : parcours complet (30 l.) ; f : parcours complet (30 l.) ; av : index `fk_avis_fournisseur` (1 l.) ; rc : index `fk_reception_avis` (1 l.) |
| 13 | **Lots proches de la DLC** — Vue v_lots_proches_dlc (index sur la date limite) | 1.23 ms | – | <derived2> : parcours complet (859 l.) ; s : parcours complet (859 l.) ; l : index `PRIMARY` (1 l.) ; ar : index `PRIMARY` (1 l.) |
| 14 | **Proposition d'emplacements** — Procédure stockée proposer_emplacements (rangement) | 2.86 ms | – | procédure stockée |

## Analyse : recherche plein texte

La première version de la recherche de stock plaçait le `MATCH … AGAINST` dans une sous-requête `IN (…)`. Le plan d'exécution montre que l'optimiseur estime une seule ligne pour la recherche plein texte, puis parcourt toute la chaîne zone → allée → rack → emplacement → stock avant de filtrer : la requête était alors plus lente qu'un simple `LIKE '%…%'`. La version retenue résout d'abord les articles par l'index FULLTEXT, puis interroge le stock avec la liste d'identifiants obtenue : chaque étape utilise un index et le temps total est le plus faible des trois variantes. Avec un catalogue plus volumineux, l'écart en faveur de l'index FULLTEXT sur l'étape 1 augmenterait (le `LIKE '%…%'` impose toujours un parcours complet de la table).

## Optimisations mises en place

- **Index composite `ix_rdv_quai_creneau (quai_id, debut, fin)`** : utilisé par le contrôle de chevauchement exécuté dans le déclencheur à chaque insertion ou modification de rendez-vous, et par le planning.
- **Index `ix_mvt_lot_date (lot_id, date_heure)`** : historique d'un lot déjà trié, sans tri supplémentaire (traçabilité).
- **Index `ix_mvt_date`, `ix_pe_date`, `ix_rdv_debut`** : filtres par période des écrans d'historique et des indicateurs.
- **Index FULLTEXT `ft_article_designation`** : recherche d'articles par mots, au lieu d'un `LIKE '%…%'` qui impose un parcours complet.
- **Index `ix_lot_dlc`** : détection des lots proches de leur date limite.
- **Dénormalisations contrôlées** : `passage.etape_courante_id` et `quai.statut` sont maintenus par déclencheur, ce qui évite de recalculer la dernière étape (sous-requête sur `passage_etape`) à chaque affichage des écrans temps réel ; la table `stock` est la synthèse des mouvements, maintenue par le déclencheur de `mouvement_stock`, pour ne pas sommer tout l'historique à chaque recherche.
- **Requêtes préparées** (PDO) : le plan est réutilisé et les paramètres ne sont jamais concaténés dans le SQL.
