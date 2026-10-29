# Modèle logique — schéma relationnel

Généré automatiquement depuis la base `entrepot` (46 tables). Notation : <u>clé primaire</u>, #clé étrangère (→ table référencée), *unique*.

- **allee** (<u>id</u>, #zone_id, *code*)
  - références : `zone_id` → zone
- **article** (<u>id</u>, *reference*, designation, #client_id, #condition_stockage_id, longueur_cm, largeur_cm, hauteur_cm, poids_kg, unites_par_colis, colis_par_palette)
  - références : `client_id` → client, `condition_stockage_id` → condition_stockage
- **avis_expedition** (<u>id</u>, *numero*, #fournisseur_id, date_emission, livraison_prevue, statut)
  - références : `fournisseur_id` → fournisseur
- **chauffeur** (<u>id</u>, #transporteur_id, nom, prenom, telephone, *numero_permis*, categorie_permis, validite_permis)
  - références : `transporteur_id` → transporteur
- **chauffeur_habilitation** (<u>#chauffeur_id</u>, <u>#habilitation_id</u>, date_obtention, date_expiration)
  - références : `chauffeur_id` → chauffeur, `habilitation_id` → habilitation
- **client** (<u>id</u>, *siret*, raison_sociale, adresse, code_postal, ville, telephone, email)
- **commande** (<u>id</u>, *numero*, #client_id, date_commande, expedition_souhaitee, adresse_livraison, code_postal, ville, statut, canal)
  - références : `client_id` → client
- **condition_stockage** (<u>id</u>, *libelle*, temperature_min, temperature_max)
- **contact** (<u>id</u>, #transporteur_id, nom, prenom, fonction, telephone, *email*)
  - références : `transporteur_id` → transporteur
- **contenu_unite** (<u>#unite_id</u>, <u>#lot_id</u>, quantite)
  - références : `lot_id` → lot, `unite_id` → unite_expedition
- **contrat** (<u>id</u>, *reference*, #transporteur_id, objet, date_debut, date_fin, montant_annuel)
  - références : `transporteur_id` → transporteur
- **document_transport** (<u>id</u>, *numero*, #expedition_id, type, date_emission)
  - références : `expedition_id` → expedition
- **ecart** (<u>id</u>, *numero*, #reception_id, #article_id, type, quantite, description, #reserve_id)
  - références : `article_id` → article, `reception_id` → reception, `reserve_id` → reserve
- **emplacement** (<u>id</u>, #rack_id, *code*, niveau, capacite_palettes, poids_max_kg, bloque)
  - références : `rack_id` → rack
- **equipement** (<u>id</u>, *libelle*)
- **expedition** (<u>id</u>, *numero*, #passage_id, date_chargement, statut)
  - références : `passage_id` → passage
- **fournisseur** (<u>id</u>, *siret*, raison_sociale, adresse, code_postal, ville, telephone, email)
- **habilitation** (<u>id</u>, *code*, libelle)
- **journal_audit** (<u>id</u>, table_concernee, enregistrement_id, action, utilisateur_id, date_heure, details)
- **ligne_avis** (<u>#avis_id</u>, <u>#article_id</u>, quantite_attendue)
  - références : `article_id` → article, `avis_id` → avis_expedition
- **ligne_commande** (<u>#commande_id</u>, <u>#article_id</u>, quantite)
  - références : `article_id` → article, `commande_id` → commande
- **ligne_prelevement** (<u>#liste_id</u>, <u>#lot_id</u>, <u>#emplacement_id</u>, ordre, quantite_a_prelever, quantite_prelevee)
  - références : `emplacement_id` → emplacement, `liste_id` → liste_prelevement, `lot_id` → lot
- **liste_prelevement** (<u>id</u>, *numero*, #commande_id, #preparateur_id, regle_sortie, date_creation, debut, fin, statut)
  - références : `commande_id` → commande, `preparateur_id` → utilisateur
- **lot** (<u>id</u>, *code_lot*, #article_id, numero_lot_fournisseur, date_fabrication, date_limite_conso)
  - références : `article_id` → article
- **mouvement_stock** (<u>id</u>, *numero*, type, #lot_id, quantite, #emplacement_source_id, #emplacement_destination_id, #utilisateur_id, date_heure, motif)
  - références : `emplacement_destination_id` → emplacement, `lot_id` → lot, `emplacement_source_id` → emplacement, `utilisateur_id` → utilisateur
- **passage** (<u>id</u>, *numero*, *#rendez_vous_id*, #vehicule_id, #chauffeur_id, #quai_id, sens, #etape_courante_id, observations, borne)
  - références : `chauffeur_id` → chauffeur, `etape_courante_id` → type_etape, `quai_id` → quai, `rendez_vous_id` → rendez_vous, `vehicule_id` → vehicule
- **passage_etape** (<u>#passage_id</u>, <u>#type_etape_id</u>, date_heure, commentaire)
  - références : `type_etape_id` → type_etape, `passage_id` → passage
- **profil** (<u>id</u>, *code*, *libelle*, description)
- **quai** (<u>id</u>, *numero*, type, statut)
- **quai_equipement** (<u>#quai_id</u>, <u>#equipement_id</u>)
  - références : `equipement_id` → equipement, `quai_id` → quai
- **quai_type_vehicule** (<u>#quai_id</u>, <u>#type_vehicule_id</u>)
  - références : `quai_id` → quai, `type_vehicule_id` → type_vehicule
- **rack** (<u>id</u>, #allee_id, *code*, nb_niveaux)
  - références : `allee_id` → allee
- **reception** (<u>id</u>, *numero*, #passage_id, #avis_id, #receptionnaire_id, debut, fin, statut)
  - références : `avis_id` → avis_expedition, `passage_id` → passage, `receptionnaire_id` → utilisateur
- **reception_lot** (<u>#reception_id</u>, <u>#lot_id</u>, quantite_recue, etat_palette)
  - références : `lot_id` → lot, `reception_id` → reception
- **rendez_vous** (<u>id</u>, *numero*, #quai_id, #vehicule_id, debut, fin, sens, statut, commentaire, motif_annulation)
  - références : `quai_id` → quai, `vehicule_id` → vehicule
- **reserve** (<u>id</u>, *numero*, date_emission, motif, transmise)
- **stock** (<u>#emplacement_id</u>, <u>#lot_id</u>, quantite)
  - références : `emplacement_id` → emplacement, `lot_id` → lot
- **transporteur** (<u>id</u>, *siret*, raison_sociale, adresse, code_postal, ville, telephone, email)
- **type_etape** (<u>id</u>, *code*, *libelle*, *ordre*)
- **type_vehicule** (<u>id</u>, *libelle*)
- **unite_expedition** (<u>id</u>, *sscc*, #commande_id, #expedition_id, type, poids_kg)
  - références : `commande_id` → commande, `expedition_id` → expedition
- **utilisateur** (<u>id</u>, *email*, nom, prenom, telephone, mot_de_passe_hash, date_creation, actif, #profil_id, #transporteur_id, #client_id)
  - références : `client_id` → client, `profil_id` → profil, `transporteur_id` → transporteur
- **vehicule** (<u>id</u>, *immatriculation*, #transporteur_id, #type_vehicule_id, poids_max_kg, palettes_max, frigorifique, hayon)
  - références : `transporteur_id` → transporteur, `type_vehicule_id` → type_vehicule
- **zone** (<u>id</u>, *code*, libelle)
- **zone_condition** (<u>#zone_id</u>, <u>#condition_stockage_id</u>)
  - références : `condition_stockage_id` → condition_stockage, `zone_id` → zone

`journal_audit_archive` a la même structure que `journal_audit`.

## Tables de jointure (associations N:N)

Clé primaire composée des clés étrangères : `chauffeur_habilitation`, `contenu_unite`, `ligne_avis`, `ligne_commande`, `ligne_prelevement`, `passage_etape`, `quai_equipement`, `quai_type_vehicule`, `reception_lot`, `stock`, `zone_condition`.

## Remarques de normalisation (3FN)

- Chaque attribut non clé dépend de toute la clé et uniquement d'elle ; les libellés répétitifs (profils, types de véhicule, étapes, conditions de stockage, équipements, habilitations) sont isolés dans des tables de référence.
- Les coordonnées (adresse, code postal, ville) sont stockées telles qu'imprimées sur les documents ; la ville n'est pas déduite du code postal (plusieurs communes partagent un même code).
- Trois dénormalisations sont assumées et maintenues **uniquement par déclencheur** : `passage.etape_courante_id` (dernière ligne de `passage_etape`), `quai.statut` (occupé / libre) et la table `stock` (synthèse de `mouvement_stock`). Elles évitent des agrégations coûteuses dans les écrans temps réel (voir `rapport_performances.md`).
- `passage.sens` peut paraître redondant avec `rendez_vous.sens`, mais il est indispensable pour les arrivées sans rendez-vous (`rendez_vous_id` NULL).
