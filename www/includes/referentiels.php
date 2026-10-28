<?php
/**
 * Description des référentiels gérés par l'écran générique referentiel.php.
 *
 * Pour chaque entité :
 *  - table, titre, groupe (onglets), lecture / ecriture : profils autorisés ;
 *  - champs : nom => [libellé, type, options...] ; types : texte, email, tel, siret, cp,
 *    entier, decimal, date, booleen, liste (sql), immat, multi (association N:N) ;
 *  - liste : requête de la liste (alias « t » pour la table) et colonnes affichées ;
 *  - recherche : colonnes interrogées par la recherche plein texte ;
 *  - filtres : champs de type liste proposés en filtre.
 */

const COORDONNEES = [
    'siret'          => ['SIRET', 'siret', 'requis' => true],
    'raison_sociale' => ['Raison sociale', 'texte', 'requis' => true, 'max' => 100],
    'adresse'        => ['Adresse', 'texte', 'requis' => true, 'max' => 150],
    'code_postal'    => ['Code postal', 'cp', 'requis' => true],
    'ville'          => ['Ville', 'texte', 'requis' => true, 'max' => 80],
    'telephone'      => ['Téléphone', 'tel'],
    'email'          => ['E-mail', 'email', 'max' => 120],
];

function referentiels(): array
{
    $gestion = ['responsable'];
    $transport = ['responsable', 'accueil'];
    return [
        // ------------------------------------------------------------- transport
        'transporteurs' => [
            'table' => 'transporteur', 'titre' => 'Transporteurs', 'groupe' => 'transport', 'lecture' => $transport, 'ecriture' => $gestion,
            'champs' => COORDONNEES,
            'liste' => ['sql' => 'SELECT t.*, (SELECT COUNT(*) FROM chauffeur c WHERE c.transporteur_id = t.id) AS nb_chauffeurs,
                                   (SELECT COUNT(*) FROM vehicule v WHERE v.transporteur_id = t.id) AS nb_vehicules FROM transporteur t',
                        'colonnes' => ['raison_sociale' => 'Raison sociale', 'siret' => 'SIRET', 'ville' => 'Ville', 'telephone' => 'Téléphone',
                                       'email' => 'E-mail', 'nb_chauffeurs' => 'Chauffeurs', 'nb_vehicules' => 'Véhicules'],
                        'ordre' => 't.raison_sociale'],
            'recherche' => ['t.raison_sociale', 't.siret', 't.ville'],
        ],
        'contacts' => [
            'table' => 'contact', 'titre' => 'Contacts', 'groupe' => 'transport', 'lecture' => $transport, 'ecriture' => $gestion,
            'champs' => [
                'transporteur_id' => ['Transporteur', 'liste', 'requis' => true, 'sql' => 'SELECT id, raison_sociale FROM transporteur ORDER BY raison_sociale'],
                'prenom' => ['Prénom', 'texte', 'requis' => true, 'max' => 60], 'nom' => ['Nom', 'texte', 'requis' => true, 'max' => 60],
                'fonction' => ['Fonction', 'texte', 'max' => 60], 'telephone' => ['Téléphone', 'tel'], 'email' => ['E-mail', 'email', 'requis' => true, 'max' => 120],
            ],
            'liste' => ['sql' => 'SELECT t.*, tr.raison_sociale FROM contact t JOIN transporteur tr ON tr.id = t.transporteur_id',
                        'colonnes' => ['raison_sociale' => 'Transporteur', 'prenom' => 'Prénom', 'nom' => 'Nom', 'fonction' => 'Fonction', 'telephone' => 'Téléphone', 'email' => 'E-mail'],
                        'ordre' => 'tr.raison_sociale, t.nom'],
            'recherche' => ['t.nom', 't.prenom', 't.email'], 'filtres' => ['transporteur_id'],
        ],
        'contrats' => [
            'table' => 'contrat', 'titre' => 'Contrats', 'groupe' => 'transport', 'lecture' => $gestion, 'ecriture' => $gestion,
            'champs' => [
                'reference' => ['Référence', 'texte', 'requis' => true, 'max' => 30],
                'transporteur_id' => ['Transporteur', 'liste', 'requis' => true, 'sql' => 'SELECT id, raison_sociale FROM transporteur ORDER BY raison_sociale'],
                'objet' => ['Objet', 'texte', 'requis' => true, 'max' => 255],
                'date_debut' => ['Début', 'date', 'requis' => true], 'date_fin' => ['Fin', 'date'],
                'montant_annuel' => ['Montant annuel (€)', 'decimal', 'min' => 0],
            ],
            'liste' => ['sql' => 'SELECT t.*, tr.raison_sociale FROM contrat t JOIN transporteur tr ON tr.id = t.transporteur_id',
                        'colonnes' => ['reference' => 'Référence', 'raison_sociale' => 'Transporteur', 'objet' => 'Objet', 'date_debut' => 'Début', 'date_fin' => 'Fin', 'montant_annuel' => 'Montant annuel'],
                        'ordre' => 't.date_debut DESC'],
            'recherche' => ['t.reference', 't.objet'], 'filtres' => ['transporteur_id'],
        ],
        'chauffeurs' => [
            'table' => 'chauffeur', 'titre' => 'Chauffeurs', 'groupe' => 'transport', 'lecture' => $transport, 'ecriture' => $transport,
            'champs' => [
                'transporteur_id' => ['Transporteur', 'liste', 'requis' => true, 'sql' => 'SELECT id, raison_sociale FROM transporteur ORDER BY raison_sociale'],
                'prenom' => ['Prénom', 'texte', 'requis' => true, 'max' => 60], 'nom' => ['Nom', 'texte', 'requis' => true, 'max' => 60],
                'telephone' => ['Téléphone', 'tel'],
                'numero_permis' => ['N° de permis', 'texte', 'requis' => true, 'max' => 20, 'motif' => '[A-Za-z0-9]{6,20}'],
                'categorie_permis' => ['Catégorie de permis', 'liste', 'requis' => true, 'valeurs' => ['C1' => 'C1', 'C' => 'C', 'C1E' => 'C1E', 'CE' => 'CE']],
                'validite_permis' => ['Validité du permis', 'date', 'requis' => true],
                'habilitations' => ['Habilitations', 'multi', 'jonction' => 'chauffeur_habilitation', 'cle' => 'chauffeur_id', 'autre' => 'habilitation_id',
                                    'sql' => 'SELECT id, libelle FROM habilitation ORDER BY code', 'defauts' => ['date_obtention' => 'CURRENT_DATE']],
            ],
            'liste' => ['sql' => 'SELECT t.*, tr.raison_sociale, (SELECT GROUP_CONCAT(h.code SEPARATOR ", ") FROM chauffeur_habilitation ch
                                   JOIN habilitation h ON h.id = ch.habilitation_id WHERE ch.chauffeur_id = t.id) AS habilitations_liste
                                   FROM chauffeur t JOIN transporteur tr ON tr.id = t.transporteur_id',
                        'colonnes' => ['nom' => 'Nom', 'prenom' => 'Prénom', 'raison_sociale' => 'Transporteur', 'telephone' => 'Téléphone',
                                       'categorie_permis' => 'Permis', 'validite_permis' => 'Validité', 'habilitations_liste' => 'Habilitations'],
                        'ordre' => 't.nom, t.prenom'],
            'recherche' => ['t.nom', 't.prenom', 't.numero_permis'], 'filtres' => ['transporteur_id', 'categorie_permis'],
        ],
        'vehicules' => [
            'table' => 'vehicule', 'titre' => 'Véhicules', 'groupe' => 'transport', 'lecture' => $transport, 'ecriture' => $transport,
            'champs' => [
                'immatriculation' => ['Immatriculation', 'immat', 'requis' => true],
                'transporteur_id' => ['Transporteur', 'liste', 'requis' => true, 'sql' => 'SELECT id, raison_sociale FROM transporteur ORDER BY raison_sociale'],
                'type_vehicule_id' => ['Type', 'liste', 'requis' => true, 'sql' => 'SELECT id, libelle FROM type_vehicule ORDER BY id'],
                'poids_max_kg' => ['Charge utile (kg)', 'entier', 'requis' => true, 'min' => 1, 'maxv' => 44000],
                'palettes_max' => ['Capacité (palettes)', 'entier', 'requis' => true, 'min' => 1, 'maxv' => 66],
                'frigorifique' => ['Frigorifique', 'booleen'], 'hayon' => ['Hayon', 'booleen'],
            ],
            'liste' => ['sql' => 'SELECT t.*, tr.raison_sociale, tv.libelle AS type FROM vehicule t JOIN transporteur tr ON tr.id = t.transporteur_id
                                   JOIN type_vehicule tv ON tv.id = t.type_vehicule_id',
                        'colonnes' => ['immatriculation' => 'Immatriculation', 'raison_sociale' => 'Transporteur', 'type' => 'Type', 'poids_max_kg' => 'Charge (kg)',
                                       'palettes_max' => 'Palettes', 'frigorifique' => 'Frigo', 'hayon' => 'Hayon'],
                        'ordre' => 't.immatriculation'],
            'recherche' => ['t.immatriculation'], 'filtres' => ['transporteur_id', 'type_vehicule_id'],
        ],
        'habilitations' => [
            'table' => 'habilitation', 'titre' => 'Habilitations', 'groupe' => 'transport', 'lecture' => $gestion, 'ecriture' => $gestion,
            'champs' => ['code' => ['Code', 'texte', 'requis' => true, 'max' => 20], 'libelle' => ['Libellé', 'texte', 'requis' => true, 'max' => 100]],
            'liste' => ['sql' => 'SELECT t.* FROM habilitation t', 'colonnes' => ['code' => 'Code', 'libelle' => 'Libellé'], 'ordre' => 't.code'],
            'recherche' => ['t.code', 't.libelle'],
        ],
        'types_vehicule' => [
            'table' => 'type_vehicule', 'titre' => 'Types de véhicules', 'groupe' => 'transport', 'lecture' => $gestion, 'ecriture' => $gestion,
            'champs' => ['libelle' => ['Libellé', 'texte', 'requis' => true, 'max' => 50]],
            'liste' => ['sql' => 'SELECT t.* FROM type_vehicule t', 'colonnes' => ['libelle' => 'Libellé'], 'ordre' => 't.id'],
            'recherche' => ['t.libelle'],
        ],

        // ------------------------------------------------------------- catalogue
        'articles' => [
            'table' => 'article', 'titre' => 'Articles', 'groupe' => 'catalogue', 'lecture' => ['responsable', 'magasinier'], 'ecriture' => $gestion,
            'champs' => [
                'reference' => ['Référence', 'texte', 'requis' => true, 'max' => 20],
                'designation' => ['Désignation', 'texte', 'requis' => true, 'max' => 150],
                'client_id' => ['Client propriétaire', 'liste', 'requis' => true, 'sql' => 'SELECT id, raison_sociale FROM client ORDER BY raison_sociale'],
                'condition_stockage_id' => ['Conditions de stockage', 'liste', 'requis' => true, 'sql' => 'SELECT id, libelle FROM condition_stockage ORDER BY id'],
                'longueur_cm' => ['Longueur (cm)', 'decimal', 'requis' => true, 'min' => 0.1], 'largeur_cm' => ['Largeur (cm)', 'decimal', 'requis' => true, 'min' => 0.1],
                'hauteur_cm' => ['Hauteur (cm)', 'decimal', 'requis' => true, 'min' => 0.1], 'poids_kg' => ['Poids unitaire (kg)', 'decimal', 'requis' => true, 'min' => 0.001],
                'unites_par_colis' => ['Unités par colis', 'entier', 'requis' => true, 'min' => 1],
                'colis_par_palette' => ['Colis par palette', 'entier', 'requis' => true, 'min' => 1],
            ],
            'liste' => ['sql' => 'SELECT t.*, c.raison_sociale, cs.libelle AS conditions,
                                   (SELECT COALESCE(SUM(s.quantite), 0) FROM stock s JOIN lot l ON l.id = s.lot_id WHERE l.article_id = t.id) AS en_stock
                                   FROM article t JOIN client c ON c.id = t.client_id JOIN condition_stockage cs ON cs.id = t.condition_stockage_id',
                        'colonnes' => ['reference' => 'Référence', 'designation' => 'Désignation', 'raison_sociale' => 'Client', 'conditions' => 'Conditions',
                                       'unites_par_colis' => 'U./colis', 'colis_par_palette' => 'Colis/pal.', 'en_stock' => 'En stock'],
                        'ordre' => 't.reference'],
            'recherche' => ['t.reference', 't.designation'], 'filtres' => ['client_id', 'condition_stockage_id'],
        ],
        'clients' => [
            'table' => 'client', 'titre' => 'Clients propriétaires', 'groupe' => 'catalogue', 'lecture' => $gestion, 'ecriture' => $gestion,
            'champs' => COORDONNEES,
            'liste' => ['sql' => 'SELECT t.*, (SELECT COUNT(*) FROM article a WHERE a.client_id = t.id) AS nb_articles FROM client t',
                        'colonnes' => ['raison_sociale' => 'Raison sociale', 'siret' => 'SIRET', 'ville' => 'Ville', 'telephone' => 'Téléphone', 'email' => 'E-mail', 'nb_articles' => 'Articles'],
                        'ordre' => 't.raison_sociale'],
            'recherche' => ['t.raison_sociale', 't.siret', 't.ville'],
        ],
        'fournisseurs' => [
            'table' => 'fournisseur', 'titre' => 'Fournisseurs', 'groupe' => 'catalogue', 'lecture' => ['responsable', 'magasinier'], 'ecriture' => $gestion,
            'champs' => COORDONNEES,
            'liste' => ['sql' => 'SELECT t.*, (SELECT COUNT(*) FROM avis_expedition a WHERE a.fournisseur_id = t.id) AS nb_avis FROM fournisseur t',
                        'colonnes' => ['raison_sociale' => 'Raison sociale', 'siret' => 'SIRET', 'ville' => 'Ville', 'telephone' => 'Téléphone', 'email' => 'E-mail', 'nb_avis' => 'Avis reçus'],
                        'ordre' => 't.raison_sociale'],
            'recherche' => ['t.raison_sociale', 't.siret', 't.ville'],
        ],

        // ------------------------------------------------------------- structure de l'entrepôt
        'zones' => [
            'table' => 'zone', 'titre' => 'Zones', 'groupe' => 'structure', 'lecture' => $gestion, 'ecriture' => $gestion,
            'champs' => [
                'code' => ['Code', 'texte', 'requis' => true, 'max' => 10], 'libelle' => ['Libellé', 'texte', 'requis' => true, 'max' => 60],
                'conditions' => ['Conditions de stockage autorisées', 'multi', 'jonction' => 'zone_condition', 'cle' => 'zone_id', 'autre' => 'condition_stockage_id',
                                 'sql' => 'SELECT id, libelle FROM condition_stockage ORDER BY id'],
            ],
            'liste' => ['sql' => 'SELECT t.*, (SELECT GROUP_CONCAT(c.libelle SEPARATOR ", ") FROM zone_condition zc JOIN condition_stockage c ON c.id = zc.condition_stockage_id WHERE zc.zone_id = t.id) AS cond,
                                   (SELECT COUNT(*) FROM allee a WHERE a.zone_id = t.id) AS nb_allees FROM zone t',
                        'colonnes' => ['code' => 'Code', 'libelle' => 'Libellé', 'cond' => 'Conditions autorisées', 'nb_allees' => 'Allées'], 'ordre' => 't.code'],
            'recherche' => ['t.code', 't.libelle'],
        ],
        'allees' => [
            'table' => 'allee', 'titre' => 'Allées', 'groupe' => 'structure', 'lecture' => $gestion, 'ecriture' => $gestion,
            'champs' => [
                'zone_id' => ['Zone', 'liste', 'requis' => true, 'sql' => 'SELECT id, libelle FROM zone ORDER BY code'],
                'code' => ['Code', 'texte', 'requis' => true, 'max' => 10],
            ],
            'liste' => ['sql' => 'SELECT t.*, z.libelle AS zone, (SELECT COUNT(*) FROM rack r WHERE r.allee_id = t.id) AS nb_racks FROM allee t JOIN zone z ON z.id = t.zone_id',
                        'colonnes' => ['code' => 'Code', 'zone' => 'Zone', 'nb_racks' => 'Racks'], 'ordre' => 't.code'],
            'recherche' => ['t.code'], 'filtres' => ['zone_id'],
        ],
        'racks' => [
            'table' => 'rack', 'titre' => 'Racks', 'groupe' => 'structure', 'lecture' => $gestion, 'ecriture' => $gestion,
            'champs' => [
                'allee_id' => ['Allée', 'liste', 'requis' => true, 'sql' => 'SELECT id, code FROM allee ORDER BY code'],
                'code' => ['Code', 'texte', 'requis' => true, 'max' => 15],
                'nb_niveaux' => ['Nombre de niveaux', 'entier', 'requis' => true, 'min' => 1, 'maxv' => 12],
            ],
            'liste' => ['sql' => 'SELECT t.*, a.code AS allee, (SELECT COUNT(*) FROM emplacement e WHERE e.rack_id = t.id) AS nb_emplacements FROM rack t JOIN allee a ON a.id = t.allee_id',
                        'colonnes' => ['code' => 'Code', 'allee' => 'Allée', 'nb_niveaux' => 'Niveaux', 'nb_emplacements' => 'Emplacements'], 'ordre' => 't.code'],
            'recherche' => ['t.code'], 'filtres' => ['allee_id'],
        ],
        'emplacements' => [
            'table' => 'emplacement', 'titre' => 'Emplacements', 'groupe' => 'structure', 'lecture' => $gestion, 'ecriture' => $gestion,
            'champs' => [
                'rack_id' => ['Rack', 'liste', 'requis' => true, 'sql' => 'SELECT id, code FROM rack ORDER BY code'],
                'code' => ['Code', 'texte', 'requis' => true, 'max' => 20],
                'niveau' => ['Niveau', 'entier', 'requis' => true, 'min' => 1, 'maxv' => 12],
                'capacite_palettes' => ['Capacité (palettes)', 'entier', 'requis' => true, 'min' => 1, 'maxv' => 10],
                'poids_max_kg' => ['Poids max (kg)', 'entier', 'requis' => true, 'min' => 1, 'maxv' => 5000],
                'bloque' => ['Bloqué', 'booleen'],
            ],
            'liste' => ['sql' => 'SELECT t.*, r.code AS rack, fn_palettes_libres(t.id) AS libres FROM emplacement t JOIN rack r ON r.id = t.rack_id',
                        'colonnes' => ['code' => 'Code', 'rack' => 'Rack', 'niveau' => 'Niveau', 'capacite_palettes' => 'Capacité', 'libres' => 'Libres', 'poids_max_kg' => 'Poids max', 'bloque' => 'Bloqué'],
                        'ordre' => 't.code'],
            'recherche' => ['t.code'], 'filtres' => ['rack_id'],
        ],
        'conditions' => [
            'table' => 'condition_stockage', 'titre' => 'Conditions de stockage', 'groupe' => 'structure', 'lecture' => $gestion, 'ecriture' => $gestion,
            'champs' => [
                'libelle' => ['Libellé', 'texte', 'requis' => true, 'max' => 50],
                'temperature_min' => ['Température min (°C)', 'decimal', 'min' => -40], 'temperature_max' => ['Température max (°C)', 'decimal', 'min' => -40],
            ],
            'liste' => ['sql' => 'SELECT t.* FROM condition_stockage t', 'colonnes' => ['libelle' => 'Libellé', 'temperature_min' => 'T° min', 'temperature_max' => 'T° max'], 'ordre' => 't.id'],
            'recherche' => ['t.libelle'],
        ],
        'equipements' => [
            'table' => 'equipement', 'titre' => 'Équipements de quai', 'groupe' => 'structure', 'lecture' => $gestion, 'ecriture' => $gestion,
            'champs' => ['libelle' => ['Libellé', 'texte', 'requis' => true, 'max' => 60]],
            'liste' => ['sql' => 'SELECT t.* FROM equipement t', 'colonnes' => ['libelle' => 'Libellé'], 'ordre' => 't.libelle'],
            'recherche' => ['t.libelle'],
        ],
    ];
}
