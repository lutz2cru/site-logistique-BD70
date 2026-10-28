-- =====================================================================
--  Script 2/3 : automatisation interne à la base
--   - fonctions et procédures stockées
--   - déclencheurs (numérotation, contrôles métier, mise à jour du stock,
--     statut des quais, historisation)
--   - vues d'indicateurs
--   - évènements planifiés
-- =====================================================================
USE entrepot;

DELIMITER $$

-- =====================================================================
--  FONCTIONS
-- =====================================================================

-- Nombre de palettes occupées par une quantité d'un article
CREATE FUNCTION fn_palettes(p_article_id INT UNSIGNED, p_quantite INT UNSIGNED)
RETURNS INT UNSIGNED
READS SQL DATA
BEGIN
    DECLARE v_par_palette INT UNSIGNED;
    SELECT unites_par_colis * colis_par_palette INTO v_par_palette
      FROM article WHERE id = p_article_id;
    RETURN CEIL(p_quantite / v_par_palette);
END$$

-- Horodatage d'une étape donnée d'un passage (NULL si non franchie)
CREATE FUNCTION fn_date_etape(p_passage_id INT UNSIGNED, p_code VARCHAR(20))
RETURNS DATETIME
READS SQL DATA
BEGIN
    DECLARE v_date DATETIME;
    SELECT pe.date_heure INTO v_date
      FROM passage_etape pe JOIN type_etape te ON te.id = pe.type_etape_id
     WHERE pe.passage_id = p_passage_id AND te.code = p_code;
    RETURN v_date;
END$$

-- Palettes libres sur un emplacement
CREATE FUNCTION fn_palettes_libres(p_emplacement_id INT UNSIGNED)
RETURNS INT
READS SQL DATA
BEGIN
    DECLARE v_capacite INT;
    DECLARE v_occupees INT;
    SELECT capacite_palettes INTO v_capacite FROM emplacement WHERE id = p_emplacement_id;
    SELECT COALESCE(SUM(fn_palettes(l.article_id, s.quantite)), 0) INTO v_occupees
      FROM stock s JOIN lot l ON l.id = s.lot_id
     WHERE s.emplacement_id = p_emplacement_id;
    RETURN v_capacite - v_occupees;
END$$

-- =====================================================================
--  PROCÉDURES : contrôles réutilisés par les déclencheurs
-- =====================================================================

-- Contrôle d'un rendez-vous : quai en service, sens compatible avec le
-- type de quai, véhicule compatible, absence de chevauchement.
CREATE PROCEDURE verifier_rendez_vous(
    IN p_id INT UNSIGNED, IN p_quai_id SMALLINT UNSIGNED, IN p_vehicule_id INT UNSIGNED,
    IN p_debut DATETIME, IN p_fin DATETIME, IN p_sens VARCHAR(12))
BEGIN
    DECLARE v_statut_quai VARCHAR(15);
    DECLARE v_type_quai   VARCHAR(12);

    SELECT statut, type INTO v_statut_quai, v_type_quai FROM quai WHERE id = p_quai_id;

    IF v_statut_quai = 'hors_service' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ce quai est hors service.';
    END IF;

    IF v_type_quai <> 'mixte' AND v_type_quai <> p_sens THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ce quai ne traite pas ce sens de flux (réception / expédition).';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM vehicule v
                     JOIN quai_type_vehicule qtv ON qtv.type_vehicule_id = v.type_vehicule_id
                    WHERE v.id = p_vehicule_id AND qtv.quai_id = p_quai_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ce type de véhicule n''est pas accepté sur ce quai.';
    END IF;

    IF EXISTS (SELECT 1 FROM rendez_vous r
                WHERE r.quai_id = p_quai_id
                  AND r.id <> COALESCE(p_id, 0)
                  AND r.statut IN ('planifie', 'honore')
                  AND r.debut < p_fin AND r.fin > p_debut) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ce créneau chevauche un autre rendez-vous sur ce quai.';
    END IF;

    IF EXISTS (SELECT 1 FROM rendez_vous r
                WHERE r.vehicule_id = p_vehicule_id
                  AND r.id <> COALESCE(p_id, 0)
                  AND r.statut IN ('planifie', 'honore')
                  AND r.debut < p_fin AND r.fin > p_debut) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ce véhicule a déjà un rendez-vous sur ce créneau.';
    END IF;
END$$

-- =====================================================================
--  DÉCLENCHEURS : numérotation automatique des documents
-- =====================================================================
CREATE TRIGGER tr_rdv_bi BEFORE INSERT ON rendez_vous FOR EACH ROW
BEGIN
    IF NEW.numero IS NULL OR NEW.numero = '' THEN
        SET NEW.numero = CONCAT('RDV-', LPAD(NEXT VALUE FOR seq_rdv, 6, '0'));
    END IF;
    IF NEW.statut IN ('planifie', 'honore') THEN
        CALL verifier_rendez_vous(NULL, NEW.quai_id, NEW.vehicule_id, NEW.debut, NEW.fin, NEW.sens);
    END IF;
END$$

CREATE TRIGGER tr_rdv_bu BEFORE UPDATE ON rendez_vous FOR EACH ROW
BEGIN
    -- on ne revalide que si le créneau, le quai ou le véhicule change
    IF NEW.statut = 'planifie' AND (NEW.quai_id <> OLD.quai_id OR NEW.vehicule_id <> OLD.vehicule_id
        OR NEW.debut <> OLD.debut OR NEW.fin <> OLD.fin OR NEW.sens <> OLD.sens OR OLD.statut <> 'planifie') THEN
        CALL verifier_rendez_vous(NEW.id, NEW.quai_id, NEW.vehicule_id, NEW.debut, NEW.fin, NEW.sens);
    END IF;
END$$

CREATE TRIGGER tr_passage_bi BEFORE INSERT ON passage FOR EACH ROW
BEGIN
    IF NEW.numero IS NULL OR NEW.numero = '' THEN
        SET NEW.numero = CONCAT('PAS-', LPAD(NEXT VALUE FOR seq_passage, 6, '0'));
    END IF;
    -- un véhicule ou un chauffeur ne peut pas être sur le site deux fois en même temps
    IF EXISTS (SELECT 1 FROM passage p LEFT JOIN type_etape te ON te.id = p.etape_courante_id
                WHERE (p.vehicule_id = NEW.vehicule_id OR p.chauffeur_id = NEW.chauffeur_id)
                  AND COALESCE(te.code, '') <> 'DEPART') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ce véhicule ou ce chauffeur est déjà enregistré sur le site.';
    END IF;
END$$

CREATE TRIGGER tr_avis_bi BEFORE INSERT ON avis_expedition FOR EACH ROW
BEGIN
    IF NEW.numero IS NULL OR NEW.numero = '' THEN
        SET NEW.numero = CONCAT('ASN-', LPAD(NEXT VALUE FOR seq_avis, 6, '0'));
    END IF;
END$$

CREATE TRIGGER tr_reception_bi BEFORE INSERT ON reception FOR EACH ROW
BEGIN
    IF NEW.numero IS NULL OR NEW.numero = '' THEN
        SET NEW.numero = CONCAT('REC-', LPAD(NEXT VALUE FOR seq_reception, 6, '0'));
    END IF;
END$$

CREATE TRIGGER tr_ecart_bi BEFORE INSERT ON ecart FOR EACH ROW
BEGIN
    IF NEW.numero IS NULL OR NEW.numero = '' THEN
        SET NEW.numero = CONCAT('ECA-', LPAD(NEXT VALUE FOR seq_ecart, 6, '0'));
    END IF;
END$$

CREATE TRIGGER tr_reserve_bi BEFORE INSERT ON reserve FOR EACH ROW
BEGIN
    IF NEW.numero IS NULL OR NEW.numero = '' THEN
        SET NEW.numero = CONCAT('RES-', LPAD(NEXT VALUE FOR seq_reserve, 6, '0'));
    END IF;
END$$

CREATE TRIGGER tr_lot_bi BEFORE INSERT ON lot FOR EACH ROW
BEGIN
    IF NEW.code_lot IS NULL OR NEW.code_lot = '' THEN
        SET NEW.code_lot = CONCAT('L', LPAD(NEXT VALUE FOR seq_lot, 8, '0'));
    END IF;
END$$

CREATE TRIGGER tr_commande_bi BEFORE INSERT ON commande FOR EACH ROW
BEGIN
    IF NEW.numero IS NULL OR NEW.numero = '' THEN
        SET NEW.numero = CONCAT('CMD-', LPAD(NEXT VALUE FOR seq_commande, 6, '0'));
    END IF;
END$$

CREATE TRIGGER tr_liste_bi BEFORE INSERT ON liste_prelevement FOR EACH ROW
BEGIN
    IF NEW.numero IS NULL OR NEW.numero = '' THEN
        SET NEW.numero = CONCAT('PRL-', LPAD(NEXT VALUE FOR seq_liste, 6, '0'));
    END IF;
END$$

CREATE TRIGGER tr_expedition_bi BEFORE INSERT ON expedition FOR EACH ROW
BEGIN
    IF NEW.numero IS NULL OR NEW.numero = '' THEN
        SET NEW.numero = CONCAT('EXP-', LPAD(NEXT VALUE FOR seq_expedition, 6, '0'));
    END IF;
END$$

CREATE TRIGGER tr_document_bi BEFORE INSERT ON document_transport FOR EACH ROW
BEGIN
    IF NEW.numero IS NULL OR NEW.numero = '' THEN
        SET NEW.numero = CONCAT(NEW.type, '-', LPAD(NEXT VALUE FOR seq_document, 6, '0'));
    END IF;
END$$

-- =====================================================================
--  DÉCLENCHEURS : suivi des flux de camions
-- =====================================================================

-- Contrôle de l'ordre chronologique des étapes et de la disponibilité du quai
CREATE TRIGGER tr_passage_etape_bi BEFORE INSERT ON passage_etape FOR EACH ROW
BEGIN
    DECLARE v_ordre_courant TINYINT UNSIGNED DEFAULT 0;
    DECLARE v_ordre_nouveau TINYINT UNSIGNED;
    DECLARE v_code          VARCHAR(20);
    DECLARE v_derniere      DATETIME;
    DECLARE v_quai_id       SMALLINT UNSIGNED;

    SELECT ordre, code INTO v_ordre_nouveau, v_code FROM type_etape WHERE id = NEW.type_etape_id;
    SELECT COALESCE(MAX(te.ordre), 0), MAX(pe.date_heure) INTO v_ordre_courant, v_derniere
      FROM passage_etape pe JOIN type_etape te ON te.id = pe.type_etape_id
     WHERE pe.passage_id = NEW.passage_id;

    IF v_ordre_nouveau <= v_ordre_courant THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cette étape a déjà été franchie ou précède la dernière étape enregistrée.';
    END IF;
    IF v_derniere IS NOT NULL AND NEW.date_heure < v_derniere THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L''horodatage doit être postérieur à celui de l''étape précédente.';
    END IF;

    IF v_code = 'APPEL_QUAI' THEN
        SELECT quai_id INTO v_quai_id FROM passage WHERE id = NEW.passage_id;
        IF v_quai_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Aucun quai n''est affecté à ce passage.';
        END IF;
        IF (SELECT statut FROM quai WHERE id = v_quai_id) <> 'libre' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Le quai affecté n''est pas libre.';
        END IF;
    END IF;
END$$

-- Mise à jour de l'étape courante, du statut du quai et du rendez-vous
CREATE TRIGGER tr_passage_etape_ai AFTER INSERT ON passage_etape FOR EACH ROW
BEGIN
    DECLARE v_code    VARCHAR(20);
    DECLARE v_quai_id SMALLINT UNSIGNED;
    DECLARE v_rdv_id  INT UNSIGNED;

    SELECT code INTO v_code FROM type_etape WHERE id = NEW.type_etape_id;
    SELECT quai_id, rendez_vous_id INTO v_quai_id, v_rdv_id FROM passage WHERE id = NEW.passage_id;

    UPDATE passage SET etape_courante_id = NEW.type_etape_id WHERE id = NEW.passage_id;

    IF v_code = 'ARRIVEE' AND v_rdv_id IS NOT NULL THEN
        UPDATE rendez_vous SET statut = 'honore' WHERE id = v_rdv_id AND statut IN ('planifie', 'absent');
    ELSEIF v_code = 'APPEL_QUAI' THEN
        UPDATE quai SET statut = 'occupe' WHERE id = v_quai_id;
    ELSEIF v_code = 'DEPART' AND v_quai_id IS NOT NULL THEN
        UPDATE quai SET statut = 'libre' WHERE id = v_quai_id AND statut = 'occupe';
    END IF;
END$$

-- =====================================================================
--  DÉCLENCHEURS : stocks (le stock n'est modifié QUE par les mouvements)
-- =====================================================================
CREATE TRIGGER tr_mouvement_bi BEFORE INSERT ON mouvement_stock FOR EACH ROW
BEGIN
    DECLARE v_dispo INT UNSIGNED DEFAULT 0;

    IF NEW.numero IS NULL OR NEW.numero = '' THEN
        SET NEW.numero = CONCAT('MVT-', LPAD(NEXT VALUE FOR seq_mouvement, 7, '0'));
    END IF;

    -- quantité suffisante sur l'emplacement source
    IF NEW.emplacement_source_id IS NOT NULL THEN
        SELECT COALESCE(MAX(quantite), 0) INTO v_dispo FROM stock
         WHERE emplacement_id = NEW.emplacement_source_id AND lot_id = NEW.lot_id;
        IF v_dispo < NEW.quantite THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quantité insuffisante sur l''emplacement source.';
        END IF;
    END IF;

    IF NEW.emplacement_destination_id IS NOT NULL THEN
        -- emplacement utilisable
        IF (SELECT bloque FROM emplacement WHERE id = NEW.emplacement_destination_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L''emplacement de destination est bloqué.';
        END IF;
        -- règle de stockage : la zone doit accepter la condition de l'article
        IF NOT EXISTS (
            SELECT 1 FROM emplacement e
              JOIN rack r            ON r.id = e.rack_id
              JOIN allee a           ON a.id = r.allee_id
              JOIN zone_condition zc ON zc.zone_id = a.zone_id
              JOIN lot l             ON l.id = NEW.lot_id
              JOIN article ar        ON ar.id = l.article_id AND ar.condition_stockage_id = zc.condition_stockage_id
             WHERE e.id = NEW.emplacement_destination_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La zone de destination n''est pas compatible avec les conditions de stockage de l''article.';
        END IF;
    END IF;
END$$

CREATE TRIGGER tr_mouvement_ai AFTER INSERT ON mouvement_stock FOR EACH ROW
BEGIN
    IF NEW.emplacement_source_id IS NOT NULL THEN
        UPDATE stock SET quantite = quantite - NEW.quantite
         WHERE emplacement_id = NEW.emplacement_source_id AND lot_id = NEW.lot_id;
        DELETE FROM stock
         WHERE emplacement_id = NEW.emplacement_source_id AND lot_id = NEW.lot_id AND quantite = 0;
    END IF;
    IF NEW.emplacement_destination_id IS NOT NULL THEN
        INSERT INTO stock (emplacement_id, lot_id, quantite)
        VALUES (NEW.emplacement_destination_id, NEW.lot_id, NEW.quantite)
        ON DUPLICATE KEY UPDATE quantite = quantite + NEW.quantite;
    END IF;
END$$

-- L'historique des mouvements est immuable
CREATE TRIGGER tr_mouvement_bu BEFORE UPDATE ON mouvement_stock FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un mouvement de stock ne peut pas être modifié : saisir un ajustement.';
END$$

CREATE TRIGGER tr_mouvement_bd BEFORE DELETE ON mouvement_stock FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un mouvement de stock ne peut pas être supprimé : saisir un ajustement.';
END$$

-- =====================================================================
--  DÉCLENCHEURS : réception
-- =====================================================================
CREATE TRIGGER tr_reception_au AFTER UPDATE ON reception FOR EACH ROW
BEGIN
    IF NEW.statut = 'cloturee' AND OLD.statut <> 'cloturee' AND NEW.avis_id IS NOT NULL THEN
        UPDATE avis_expedition SET statut = 'recu' WHERE id = NEW.avis_id;
    END IF;
END$$

-- =====================================================================
--  DÉCLENCHEURS : historisation (journal_audit)
--  L'application positionne @utilisateur_id à chaque connexion PDO.
-- =====================================================================
CREATE TRIGGER tr_rdv_au AFTER UPDATE ON rendez_vous FOR EACH ROW
BEGIN
    INSERT INTO journal_audit (table_concernee, enregistrement_id, action, utilisateur_id, details)
    VALUES ('rendez_vous', NEW.id, 'UPDATE', @utilisateur_id,
            JSON_OBJECT('numero', NEW.numero,
                        'avant', JSON_OBJECT('quai_id', OLD.quai_id, 'debut', OLD.debut, 'fin', OLD.fin, 'statut', OLD.statut),
                        'apres', JSON_OBJECT('quai_id', NEW.quai_id, 'debut', NEW.debut, 'fin', NEW.fin, 'statut', NEW.statut)));
END$$

CREATE TRIGGER tr_rdv_ad AFTER DELETE ON rendez_vous FOR EACH ROW
BEGIN
    INSERT INTO journal_audit (table_concernee, enregistrement_id, action, utilisateur_id, details)
    VALUES ('rendez_vous', OLD.id, 'DELETE', @utilisateur_id,
            JSON_OBJECT('numero', OLD.numero, 'quai_id', OLD.quai_id, 'debut', OLD.debut, 'fin', OLD.fin));
END$$

CREATE TRIGGER tr_quai_au AFTER UPDATE ON quai FOR EACH ROW
BEGIN
    IF NEW.statut <> OLD.statut OR NEW.type <> OLD.type OR NEW.numero <> OLD.numero THEN
        INSERT INTO journal_audit (table_concernee, enregistrement_id, action, utilisateur_id, details)
        VALUES ('quai', NEW.id, 'UPDATE', @utilisateur_id,
                JSON_OBJECT('numero', NEW.numero, 'statut_avant', OLD.statut, 'statut_apres', NEW.statut,
                            'type_avant', OLD.type, 'type_apres', NEW.type));
    END IF;
END$$

CREATE TRIGGER tr_article_au AFTER UPDATE ON article FOR EACH ROW
BEGIN
    INSERT INTO journal_audit (table_concernee, enregistrement_id, action, utilisateur_id, details)
    VALUES ('article', NEW.id, 'UPDATE', @utilisateur_id,
            JSON_OBJECT('reference', NEW.reference,
                        'designation_avant', OLD.designation, 'designation_apres', NEW.designation,
                        'condition_avant', OLD.condition_stockage_id, 'condition_apres', NEW.condition_stockage_id));
END$$

CREATE TRIGGER tr_emplacement_au AFTER UPDATE ON emplacement FOR EACH ROW
BEGIN
    IF NEW.bloque <> OLD.bloque OR NEW.capacite_palettes <> OLD.capacite_palettes THEN
        INSERT INTO journal_audit (table_concernee, enregistrement_id, action, utilisateur_id, details)
        VALUES ('emplacement', NEW.id, 'UPDATE', @utilisateur_id,
                JSON_OBJECT('code', NEW.code, 'bloque_avant', OLD.bloque, 'bloque_apres', NEW.bloque,
                            'capacite_avant', OLD.capacite_palettes, 'capacite_apres', NEW.capacite_palettes));
    END IF;
END$$

CREATE TRIGGER tr_utilisateur_au AFTER UPDATE ON utilisateur FOR EACH ROW
BEGIN
    IF NEW.profil_id <> OLD.profil_id OR NEW.actif <> OLD.actif THEN
        INSERT INTO journal_audit (table_concernee, enregistrement_id, action, utilisateur_id, details)
        VALUES ('utilisateur', NEW.id, 'UPDATE', @utilisateur_id,
                JSON_OBJECT('email', NEW.email, 'profil_avant', OLD.profil_id, 'profil_apres', NEW.profil_id,
                            'actif_avant', OLD.actif, 'actif_apres', NEW.actif));
    END IF;
END$$

-- =====================================================================
--  PROCÉDURES MÉTIER
-- =====================================================================

-- Clôture d'une réception : calcul automatique des écarts par rapport à
-- l'avis d'expédition (manquants, excédents, marchandises endommagées).
CREATE PROCEDURE cloturer_reception(IN p_reception_id INT UNSIGNED)
BEGIN
    DECLARE v_avis_id INT UNSIGNED;
    DECLARE v_statut  VARCHAR(10);

    SELECT avis_id, statut INTO v_avis_id, v_statut FROM reception WHERE id = p_reception_id;
    IF v_statut = 'cloturee' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cette réception est déjà clôturée.';
    END IF;

    -- quantités reçues par article pour cette réception
    CREATE TEMPORARY TABLE tmp_recu AS
        SELECT l.article_id,
               SUM(rl.quantite_recue) AS recu,
               SUM(CASE WHEN rl.etat_palette <> 'bon' THEN rl.quantite_recue ELSE 0 END) AS abime
          FROM reception_lot rl JOIN lot l ON l.id = rl.lot_id
         WHERE rl.reception_id = p_reception_id
         GROUP BY l.article_id;

    -- manquants (et articles annoncés non livrés)
    INSERT INTO ecart (reception_id, article_id, type, quantite, description)
    SELECT p_reception_id, la.article_id, 'manquant', la.quantite_attendue - COALESCE(t.recu, 0),
           'Écart calculé automatiquement à la clôture'
      FROM ligne_avis la LEFT JOIN tmp_recu t ON t.article_id = la.article_id
     WHERE la.avis_id = v_avis_id AND COALESCE(t.recu, 0) < la.quantite_attendue;

    -- excédents (y compris articles non annoncés)
    INSERT INTO ecart (reception_id, article_id, type, quantite, description)
    SELECT p_reception_id, t.article_id, 'excedent', t.recu - COALESCE(la.quantite_attendue, 0),
           IF(la.article_id IS NULL, 'Article non annoncé dans l''avis', 'Écart calculé automatiquement à la clôture')
      FROM tmp_recu t LEFT JOIN ligne_avis la ON la.avis_id = v_avis_id AND la.article_id = t.article_id
     WHERE t.recu > COALESCE(la.quantite_attendue, 0);

    -- marchandises endommagées / refusées
    INSERT INTO ecart (reception_id, article_id, type, quantite, description)
    SELECT p_reception_id, t.article_id, 'endommage', t.abime, 'Palettes déclarées endommagées ou refusées'
      FROM tmp_recu t WHERE t.abime > 0;

    DROP TEMPORARY TABLE tmp_recu;

    UPDATE reception SET statut = 'cloturee', fin = COALESCE(fin, NOW()) WHERE id = p_reception_id;
END$$

-- Proposition d'emplacements libres et compatibles pour un article :
-- zone autorisée, emplacement non bloqué, capacité suffisante ; on
-- privilégie les emplacements contenant déjà l'article, puis les zones
-- dédiées (les zones polyvalentes servent au débordement), puis le plus juste.
CREATE PROCEDURE proposer_emplacements(IN p_article_id INT UNSIGNED, IN p_palettes INT UNSIGNED, IN p_limite INT UNSIGNED)
BEGIN
    SELECT e.id, e.code, z.code AS zone, z.libelle AS zone_libelle, e.niveau,
           e.capacite_palettes, fn_palettes_libres(e.id) AS palettes_libres,
           EXISTS (SELECT 1 FROM stock s JOIN lot l ON l.id = s.lot_id
                    WHERE s.emplacement_id = e.id AND l.article_id = p_article_id) AS contient_article,
           (SELECT COUNT(*) FROM zone_condition zc2 WHERE zc2.zone_id = z.id) AS polyvalence
      FROM emplacement e
      JOIN rack r            ON r.id = e.rack_id
      JOIN allee a           ON a.id = r.allee_id
      JOIN zone z            ON z.id = a.zone_id
      JOIN zone_condition zc ON zc.zone_id = z.id
      JOIN article ar        ON ar.id = p_article_id AND ar.condition_stockage_id = zc.condition_stockage_id
     WHERE e.bloque = FALSE
    HAVING palettes_libres >= p_palettes
     ORDER BY contient_article DESC, polyvalence ASC, palettes_libres ASC, e.niveau ASC, e.code
     LIMIT p_limite;
END$$

-- Génération d'une liste de prélèvement pour une commande selon la règle
-- FIFO (premier entré – premier sorti) ou FEFO (premier périmé – premier sorti).
-- Les quantités déjà réservées par des listes non terminées sont déduites.
CREATE PROCEDURE generer_liste_prelevement(IN p_commande_id INT UNSIGNED, IN p_regle VARCHAR(4), OUT p_liste_id INT UNSIGNED)
BEGIN
    DECLARE v_fini       BOOLEAN DEFAULT FALSE;
    DECLARE v_article_id INT UNSIGNED;
    DECLARE v_besoin     INT;
    DECLARE v_ordre      SMALLINT UNSIGNED DEFAULT 0;

    DECLARE c_lignes CURSOR FOR
        SELECT article_id, quantite FROM ligne_commande WHERE commande_id = p_commande_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fini = TRUE;

    IF p_regle NOT IN ('FIFO', 'FEFO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Règle de sortie inconnue (FIFO ou FEFO).';
    END IF;

    INSERT INTO liste_prelevement (commande_id, regle_sortie) VALUES (p_commande_id, p_regle);
    SET p_liste_id = LAST_INSERT_ID();

    OPEN c_lignes;
    boucle_lignes: LOOP
        FETCH c_lignes INTO v_article_id, v_besoin;
        IF v_fini THEN LEAVE boucle_lignes; END IF;

        BEGIN
            DECLARE v_fini_stock BOOLEAN DEFAULT FALSE;
            DECLARE v_lot_id INT UNSIGNED;
            DECLARE v_empl_id INT UNSIGNED;
            DECLARE v_dispo INT;
            DECLARE c_stock CURSOR FOR
                SELECT s.lot_id, s.emplacement_id,
                       s.quantite - COALESCE((SELECT SUM(lp.quantite_a_prelever) FROM ligne_prelevement lp
                                                JOIN liste_prelevement li ON li.id = lp.liste_id
                                               WHERE lp.lot_id = s.lot_id AND lp.emplacement_id = s.emplacement_id
                                                 AND li.statut <> 'terminee'), 0) AS dispo
                  FROM stock s
                  JOIN lot l          ON l.id = s.lot_id
                  JOIN emplacement e  ON e.id = s.emplacement_id
                 WHERE l.article_id = v_article_id AND e.bloque = FALSE
                 ORDER BY
                    CASE WHEN p_regle = 'FEFO' THEN COALESCE(l.date_limite_conso, '9999-12-31') END,
                    (SELECT MIN(m.date_heure) FROM mouvement_stock m WHERE m.lot_id = l.id AND m.type = 'entree'),
                    l.id;
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fini_stock = TRUE;

            OPEN c_stock;
            boucle_stock: LOOP
                FETCH c_stock INTO v_lot_id, v_empl_id, v_dispo;
                IF v_fini_stock OR v_besoin <= 0 THEN LEAVE boucle_stock; END IF;
                IF v_dispo > 0 THEN
                    SET v_ordre = v_ordre + 1;
                    INSERT INTO ligne_prelevement (liste_id, lot_id, emplacement_id, ordre, quantite_a_prelever)
                    VALUES (p_liste_id, v_lot_id, v_empl_id, v_ordre, LEAST(v_dispo, v_besoin));
                    SET v_besoin = v_besoin - LEAST(v_dispo, v_besoin);
                END IF;
            END LOOP;
            CLOSE c_stock;
        END;
    END LOOP;
    CLOSE c_lignes;

    UPDATE commande SET statut = 'en_preparation' WHERE id = p_commande_id AND statut = 'recue';
END$$

-- Rendez-vous non honorés : passés à « absent » 2 h après la fin du créneau
CREATE PROCEDURE marquer_rdv_absents()
BEGIN
    UPDATE rendez_vous SET statut = 'absent'
     WHERE statut = 'planifie' AND fin < NOW() - INTERVAL 2 HOUR;
END$$

-- Archivage des entrées du journal plus anciennes que p_jours
CREATE PROCEDURE archiver_journal(IN p_jours INT)
BEGIN
    START TRANSACTION;
    INSERT INTO journal_audit_archive
        SELECT * FROM journal_audit WHERE date_heure < NOW() - INTERVAL p_jours DAY;
    DELETE FROM journal_audit WHERE date_heure < NOW() - INTERVAL p_jours DAY;
    COMMIT;
END$$

-- =====================================================================
--  ÉVÈNEMENTS PLANIFIÉS (nécessitent event_scheduler = ON)
-- =====================================================================
CREATE EVENT ev_rdv_absents ON SCHEDULE EVERY 15 MINUTE
DO CALL marquer_rdv_absents()$$

CREATE EVENT ev_archiver_journal ON SCHEDULE EVERY 1 DAY STARTS (CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 3 HOUR)
DO CALL archiver_journal(180)$$

DELIMITER ;

-- =====================================================================
--  VUES D'INDICATEURS ET DE CONSULTATION
-- =====================================================================

-- Statut temps réel des quais (« réservé » = RDV planifié dans l'heure)
CREATE VIEW v_quai_temps_reel AS
SELECT q.id, q.numero, q.type,
       CASE
           WHEN q.statut = 'hors_service' THEN 'hors_service'
           WHEN q.statut = 'occupe'       THEN 'occupe'
           WHEN EXISTS (SELECT 1 FROM rendez_vous r
                         WHERE r.quai_id = q.id AND r.statut = 'planifie'
                           AND r.debut <= NOW() + INTERVAL 1 HOUR AND r.fin >= NOW()) THEN 'reserve'
           ELSE 'libre'
       END AS statut,
       (SELECT v.immatriculation FROM passage p JOIN vehicule v ON v.id = p.vehicule_id
          JOIN type_etape te ON te.id = p.etape_courante_id
         WHERE p.quai_id = q.id AND te.code IN ('APPEL_QUAI', 'DEBUT_OP', 'FIN_OP')
         LIMIT 1) AS vehicule_a_quai,
       (SELECT MIN(r.debut) FROM rendez_vous r
         WHERE r.quai_id = q.id AND r.statut = 'planifie' AND r.debut >= NOW()) AS prochain_rdv
  FROM quai q;

-- Durées de chaque passage (en minutes)
CREATE VIEW v_passage_durees AS
SELECT p.id, p.numero, p.sens, p.rendez_vous_id, v.transporteur_id,
       arr.date_heure AS arrivee, appel.date_heure AS appel_quai, dep.date_heure AS depart,
       TIMESTAMPDIFF(MINUTE, arr.date_heure, appel.date_heure) AS attente_min,
       TIMESTAMPDIFF(MINUTE, arr.date_heure, dep.date_heure)   AS immobilisation_min,
       TIMESTAMPDIFF(MINUTE, appel.date_heure, COALESCE(fin.date_heure, dep.date_heure)) AS occupation_quai_min,
       CASE WHEN r.id IS NULL THEN NULL
            ELSE GREATEST(TIMESTAMPDIFF(MINUTE, r.debut, arr.date_heure), 0) END AS retard_min
  FROM passage p
  JOIN vehicule v ON v.id = p.vehicule_id
  LEFT JOIN rendez_vous r ON r.id = p.rendez_vous_id
  LEFT JOIN passage_etape arr   ON arr.passage_id = p.id   AND arr.type_etape_id   = (SELECT id FROM type_etape WHERE code = 'ARRIVEE')
  LEFT JOIN passage_etape appel ON appel.passage_id = p.id AND appel.type_etape_id = (SELECT id FROM type_etape WHERE code = 'APPEL_QUAI')
  LEFT JOIN passage_etape fin   ON fin.passage_id = p.id   AND fin.type_etape_id   = (SELECT id FROM type_etape WHERE code = 'FIN_OP')
  LEFT JOIN passage_etape dep   ON dep.passage_id = p.id   AND dep.type_etape_id   = (SELECT id FROM type_etape WHERE code = 'DEPART');

-- Temps moyens d'attente / d'immobilisation par transporteur
CREATE VIEW v_temps_par_transporteur AS
SELECT t.id AS transporteur_id, t.raison_sociale,
       COUNT(d.id)                       AS nb_passages,
       ROUND(AVG(d.attente_min), 1)        AS attente_moy_min,
       ROUND(AVG(d.immobilisation_min), 1) AS immobilisation_moy_min,
       SUM(d.retard_min > 15)             AS nb_retards
  FROM transporteur t
  JOIN v_passage_durees d ON d.transporteur_id = t.id
 WHERE d.depart IS NOT NULL
 GROUP BY t.id, t.raison_sociale;

-- Détail du stock (une ligne par lot et par emplacement)
CREATE VIEW v_stock_detail AS
SELECT s.emplacement_id, s.lot_id, e.code AS emplacement, z.id AS zone_id, z.code AS zone,
       ar.id AS article_id, ar.reference, ar.designation, c.raison_sociale AS client, c.id AS client_id,
       l.code_lot, l.date_limite_conso, s.quantite,
       fn_palettes(ar.id, s.quantite) AS palettes
  FROM stock s
  JOIN emplacement e ON e.id = s.emplacement_id
  JOIN rack r        ON r.id = e.rack_id
  JOIN allee a       ON a.id = r.allee_id
  JOIN zone z        ON z.id = a.zone_id
  JOIN lot l         ON l.id = s.lot_id
  JOIN article ar    ON ar.id = l.article_id
  JOIN client c      ON c.id = ar.client_id;

-- Taux de remplissage par zone (en palettes)
CREATE VIEW v_remplissage_zone AS
SELECT z.id, z.code, z.libelle,
       cap.capacite,
       COALESCE(occ.occupees, 0) AS occupees,
       ROUND(100 * COALESCE(occ.occupees, 0) / cap.capacite, 1) AS taux
  FROM zone z
  JOIN (SELECT a.zone_id, SUM(e.capacite_palettes) AS capacite
          FROM emplacement e JOIN rack r ON r.id = e.rack_id JOIN allee a ON a.id = r.allee_id
         GROUP BY a.zone_id) cap ON cap.zone_id = z.id
  LEFT JOIN (SELECT zone_id, SUM(palettes) AS occupees FROM v_stock_detail GROUP BY zone_id) occ
         ON occ.zone_id = z.id;

-- Taux d'écart en réception par fournisseur
CREATE VIEW v_taux_ecart_fournisseur AS
SELECT f.id AS fournisseur_id, f.raison_sociale,
       COUNT(DISTINCT rc.id) AS nb_receptions,
       COUNT(DISTINCT e.reception_id) AS nb_receptions_avec_ecart,
       ROUND(100 * COUNT(DISTINCT e.reception_id) / COUNT(DISTINCT rc.id), 1) AS taux_ecart
  FROM fournisseur f
  JOIN avis_expedition av ON av.fournisseur_id = f.id
  JOIN reception rc       ON rc.avis_id = av.id AND rc.statut = 'cloturee'
  LEFT JOIN ecart e       ON e.reception_id = rc.id
 GROUP BY f.id, f.raison_sociale;

-- Lots en stock dont la date limite approche (30 jours)
CREATE VIEW v_lots_proches_dlc AS
SELECT l.id AS lot_id, l.code_lot, ar.reference, ar.designation, l.date_limite_conso,
       DATEDIFF(l.date_limite_conso, CURRENT_DATE) AS jours_restants,
       SUM(s.quantite) AS quantite
  FROM lot l
  JOIN article ar ON ar.id = l.article_id
  JOIN stock s    ON s.lot_id = l.id
 WHERE l.date_limite_conso IS NOT NULL
   AND l.date_limite_conso <= CURRENT_DATE + INTERVAL 30 DAY
 GROUP BY l.id, l.code_lot, ar.reference, ar.designation, l.date_limite_conso;

-- Productivité des préparateurs
CREATE VIEW v_productivite_preparateur AS
SELECT u.id AS utilisateur_id, CONCAT(u.prenom, ' ', u.nom) AS preparateur,
       COUNT(*)            AS nb_listes,
       SUM(x.nb_lignes)    AS nb_lignes,
       SUM(x.unites)       AS unites_prelevees,
       ROUND(SUM(x.nb_lignes) / NULLIF(SUM(x.duree_min) / 60, 0), 1) AS lignes_par_heure
  FROM utilisateur u
  JOIN (SELECT li.id, li.preparateur_id, COUNT(*) AS nb_lignes,
               SUM(lp.quantite_prelevee) AS unites,
               TIMESTAMPDIFF(MINUTE, li.debut, li.fin) AS duree_min
          FROM liste_prelevement li JOIN ligne_prelevement lp ON lp.liste_id = li.id
         WHERE li.statut = 'terminee'
         GROUP BY li.id, li.preparateur_id, li.debut, li.fin) x ON x.preparateur_id = u.id
 GROUP BY u.id, u.prenom, u.nom;
