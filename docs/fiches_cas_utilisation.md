# Fiches textuelles des cas d'utilisation phares

Spécification textuelle de quatre cas d'utilisation du diagramme `uml/use_case_entrepot.drawio`,
selon le modèle vu en cours (identification, séquencement, préconditions, enchaînement, postconditions).
Les noms des cas, les `«include»` et les `«extend»` cités sont exactement ceux du diagramme.
Une table de correspondance entre les cas d'utilisation et le diagramme entité-association termine le document.

| | Cas d'utilisation | Acteur principal | Étape du sujet |
|---|---|---|---|
| 1 | Prendre un rendez-vous | Transporteur, Agent d'accueil | 1 · Planifier les rendez-vous de quai |
| 2 | Accueillir un camion sans rendez-vous | Agent d'accueil | 2 · Accueillir les camions sur le site |
| 3 | Enregistrer la réception | Magasinier / Préparateur | 3 · Réceptionner et contrôler la marchandise |
| 4 | Prélever les articles d'une liste | Magasinier / Préparateur | 5 · Préparer et expédier les commandes |

---

## 1. Prendre un rendez-vous

**Identification**

| | |
|---|---|
| Nom du cas | Prendre un rendez-vous |
| Objectif | Réserver un créneau sur un quai compatible avec le véhicule et le sens du flux, sans chevauchement |
| Acteurs | Transporteur (principal, pour ses propres véhicules) ; Agent d'accueil (principal, pour tout transporteur) |
| Date | 04/11/2026 |
| Responsables | RAZEK, TELLITEL, LUTZ |
| Version | 2.0 |

**Séquencement** : le cas commence quand l'acteur choisit « Nouveau rendez-vous ». Il inclut « Vérifier le créneau et la compatibilité véhicule / quai » (`«include»`).

**Préconditions**
- L'acteur est connecté. Un transporteur ne voit que les véhicules de son entreprise.
- Le véhicule est référencé, avec son type.

**Enchaînement nominal**
1. L'acteur choisit le véhicule, le sens (réception ou expédition), la date, l'heure d'arrivée et la durée prévue.
2. Le système contrôle la saisie : créneau dans le futur, entre 6 h et 21 h, durée de 15 min à 4 h.
3. L'acteur demande les disponibilités. Le système vérifie le créneau (cas inclus) et propose uniquement les quais qui ne sont pas hors service, qui traitent ce sens de flux, qui acceptent ce type de véhicule et qui sont libres sur tout le créneau.
4. L'acteur choisit un des quais proposés et enregistre.
5. Un déclencheur refait les mêmes contrôles en base, puis le rendez-vous est enregistré au statut « planifié » avec un numéro `RDV-…`.

**Enchaînements alternatifs**
- *A1 – Aucun quai disponible* (étape 3) : le système l'indique et invite à choisir un autre horaire.
- *A2 – Conflit au moment d'enregistrer* (étape 5) : un autre rendez-vous a été pris entre-temps. Le déclencheur refuse avec un message explicite (chevauchement, véhicule déjà attendu, quai incompatible) et le système propose de nouveau les quais libres.

**Postconditions**
- Le rendez-vous apparaît dans le planning des quais et dans la liste des rendez-vous du transporteur.
- Il peut être modifié ou annulé tant qu'il est planifié (« Modifier ou annuler un rendez-vous », qui inclut la même vérification).

---

## 2. Accueillir un camion sans rendez-vous

**Identification**

| | |
|---|---|
| Nom du cas | Accueillir un camion sans rendez-vous |
| Objectif | Accueillir un camion qui se présente sans rendez-vous et lui trouver un quai compatible et libre |
| Acteurs | Agent d'accueil |
| Date | 04/11/2026 |
| Responsables | RAZEK, TELLITEL, LUTZ |
| Version | 2.0 |

**Séquencement** : le cas commence quand un camion se présente au poste de garde sans rendez-vous. Il étend « Enregistrer l'arrivée au poste de garde » (`«extend»`) sous la condition [aucun rendez-vous]. Il inclut « Affecter un quai libre et compatible » (`«include»`).

**Préconditions**
- L'agent d'accueil est connecté.
- Le véhicule et le chauffeur sont référencés. Sinon, l'agent les crée d'abord avec « Gérer les véhicules » et « Gérer les chauffeurs et leurs habilitations ».

**Enchaînement nominal**
1. L'agent choisit « Arrivée sans rendez-vous ».
2. Il sélectionne le véhicule, le chauffeur et le sens du flux (réception ou expédition).
3. Le système vérifie que le chauffeur appartient au transporteur du véhicule et que son permis est valide.
4. Le système crée le passage, sans rendez-vous, et horodate l'étape « Arrivée au poste de garde ». Le camion attend sur le parking.
5. Après le contrôle des documents, l'agent affecte un quai (cas inclus). Le système ne propose que les quais *libres*, compatibles avec le type de véhicule et avec le sens du flux.
6. L'agent appelle le camion à quai. Le système horodate l'appel, et le statut du quai passe à « occupé ».

**Enchaînements alternatifs**
- *A1 – Chauffeur d'un autre transporteur ou permis expiré* (étape 3) : l'enregistrement est refusé avec un message explicite.
- *A2 – Camion ou chauffeur déjà sur le site* (étape 4) : un déclencheur refuse le second passage.
- *A3 – Aucun quai compatible libre* (étape 5) : le camion reste en attente sur le parking.

**Postconditions**
- Le passage est enregistré et rattaché à un quai dès l'appel à quai.
- Les temps d'attente et d'immobilisation sont mesurés à partir des étapes horodatées.

---

## 3. Enregistrer la réception

**Identification**

| | |
|---|---|
| Nom du cas | Enregistrer la réception (quantités, lots, DLC, état des palettes) |
| Objectif | Contrôler la marchandise déchargée par rapport à l'avis d'expédition et tracer chaque lot reçu |
| Acteurs | Magasinier / Préparateur |
| Date | 04/11/2026 |
| Responsables | RAZEK, TELLITEL, LUTZ |
| Version | 2.0 |

**Séquencement** : le cas commence quand un camion de réception est à quai. Il inclut « Détecter les écarts avec l'avis » (`«include»`). Il est étendu par « Émettre une réserve auprès du transporteur » (`«extend»`) sous la condition [écart constaté].

**Préconditions**
- Le camion a été appelé à quai (passage en sens réception).
- L'avis d'expédition du fournisseur a été enregistré, sauf pour une livraison non annoncée.

**Enchaînement nominal**
1. Le magasinier démarre la réception du camion et choisit l'avis d'expédition correspondant.
2. Le système affiche, par article, la quantité attendue selon l'avis et la quantité déjà reçue.
3. Pour chaque palette, le magasinier saisit l'article, la quantité, le numéro de lot fournisseur, les dates de fabrication et limite de consommation, et l'état (bon, endommagé, refusé). Le système crée le lot avec son code interne.
4. Le magasinier clôture la réception.
5. La procédure stockée `cloturer_reception` détecte les écarts (cas inclus) : manquants, excédents et marchandises endommagées. Chaque écart est un *constat* qui relie la réception, l'article et le type d'écart, avec la quantité concernée.
6. La réception passe au statut « clôturée » et l'avis à « reçu ».

**Enchaînements alternatifs**
- *A1 – DLC manquante* (étape 3) : pour un produit frais ou surgelé, la date limite est obligatoire.
- *A2 – DLC dépassée* (étape 3) : la saisie est refusée, et la marchandise doit être refusée.
- *A3 – Écart constaté* (étape 5) : extension « Émettre une réserve auprès du transporteur ». Le magasinier saisit le motif. La réserve est rattachée à la réception, et peut être marquée « transmise » au chauffeur.
- *A4 – Casse découverte après la clôture* : le magasinier déclare manuellement un constat d'écart. S'il existe déjà pour le même article et le même type, la quantité s'y ajoute.

**Postconditions**
- Les lots reçus sont prêts à être rangés (« Affecter la marchandise reçue à un emplacement »).
- Les constats alimentent l'indicateur « taux d'écart par fournisseur ».

---

## 4. Prélever les articles d'une liste

**Identification**

| | |
|---|---|
| Nom du cas | Prélever les articles d'une liste |
| Objectif | Exécuter une liste de prélèvement générée par le responsable d'exploitation, ligne par ligne, en tenant le stock à jour |
| Acteurs | Magasinier / Préparateur |
| Date | 04/11/2026 |
| Responsables | RAZEK, TELLITEL, LUTZ |
| Version | 2.0 |

**Séquencement** : le cas commence quand le préparateur ouvre une liste qui lui est affectée.

**Préconditions**
- Le responsable d'exploitation a généré la liste pour une commande (« Générer les listes de prélèvement », qui inclut « Appliquer la règle de sortie (FIFO / FEFO) »).
- La liste est affectée au préparateur (« Affecter une liste à un préparateur »).

**Enchaînement nominal**
1. Le préparateur ouvre une liste « à faire ».
2. Il commence le prélèvement. La liste passe « en cours » et l'heure de début est enregistrée.
3. Le système affiche les lignes dans l'ordre du parcours : emplacement, article, lot, date limite, quantité à prélever. Chaque ligne relie la liste, le lot et l'emplacement.
4. Pour chaque ligne, le préparateur saisit la quantité réellement prélevée et la valide.
5. Le système enregistre une sortie de stock sur l'emplacement et le lot concernés. Un déclencheur vérifie la quantité disponible et met le stock à jour.
6. Quand toutes les lignes sont traitées, le préparateur clôture la liste.
7. La procédure stockée de clôture passe la liste « terminée ». Elle passe la commande « préparée » si toutes ses listes sont terminées.

**Enchaînements alternatifs**
- *A1 – Quantité insuffisante sur l'emplacement* (étape 4) : le préparateur saisit la quantité trouvée, éventuellement 0. La ligne est enregistrée avec un manquant, signalé en orange.
- *A2 – Clôture prématurée* (étape 6) : si une ligne n'a pas été traitée, la procédure refuse la clôture.
- *A3 – Liste d'un autre préparateur* : la liste s'affiche en lecture seule.

**Postconditions**
- Le stock est diminué des quantités prélevées, et chaque sortie est historisée (mouvement daté, utilisateur, motif « Prélèvement PRL-… »).
- La commande peut passer à « Constituer les colis et palettes ».

---

## Correspondance entre cas d'utilisation et diagramme entité-association

Chaque cas d'utilisation s'appuie sur des entités et des associations du diagramme `uml/diagramme_entite_association.drawio`.
Réciproquement, chaque entité est créée ou mise à jour par au moins un cas d'utilisation.

| Cas d'utilisation | Entités | Associations |
|---|---|---|
| S'inscrire, Se connecter, Gérer les comptes et les profils | Utilisateur, Profil | Avoir, Représenter |
| Gérer les transporteurs (coordonnées, contrats, contacts) | Transporteur, Contact, Contrat | Travailler pour, Signer |
| Gérer les chauffeurs et leurs habilitations | Chauffeur, Habilitation, Transporteur | Employer, Détenir |
| Gérer les véhicules | Véhicule, Type de véhicule | Posséder, Être de type |
| Décrire les quais, Suivre le statut des quais, Mettre un quai hors service | Quai, Équipement, Type de véhicule | Équiper, Accepter |
| Prendre / Modifier ou annuler un rendez-vous, Consulter le planning, Vérifier le créneau | Rendez-vous, Quai, Véhicule | Réserver, Prévoir |
| Enregistrer l'arrivée, Accueillir sans rendez-vous, Contrôler les documents, Mettre en attente, Affecter un quai, Appeler à quai, Enregistrer le (dé)chargement et le départ, Suivre les camions sur site | Passage, Type d'étape, Chauffeur, Véhicule, Quai | Honorer, Utiliser, Conduire, Accoster, Franchir |
| Gérer les fournisseurs, Enregistrer un avis d'expédition | Fournisseur, Avis d'expédition, Article | Émettre, Annoncer |
| Enregistrer la réception, Détecter les écarts avec l'avis | Réception, Passage, Avis d'expédition, Lot, Article, Type d'écart | Donner lieu à, Correspondre à, Recevoir, Concerner, **Constater** (ternaire) |
| Émettre une réserve auprès du transporteur | Réserve, Réception | Porter sur |
| Gérer le catalogue d'articles, Gérer les clients propriétaires | Article, Client, Condition de stockage | Appartenir à, Nécessiter |
| Décrire la structure de l'entrepôt | Zone, Allée, Rack, Emplacement, Condition de stockage | Diviser, Desservir, Comporter, Autoriser |
| Affecter la marchandise à un emplacement, Proposer un emplacement, Transférer du stock, Ajuster l'inventaire | Mouvement de stock, Lot, Emplacement | Stocker, Déplacer, Provenir de, Aller vers, Effectuer |
| Consulter le stock, l'historique des mouvements, Retracer l'historique d'un lot | Lot, Emplacement, Mouvement de stock | Stocker, Déplacer |
| Gérer les commandes clients | Commande, Client, Article | Passer, Comprendre |
| Générer les listes, Appliquer la règle FIFO / FEFO, Affecter une liste, Prélever les articles | Liste de prélèvement, Lot, Emplacement, Utilisateur | Servir, Préparer, **Prélever** (ternaire) |
| Constituer les colis et palettes, Charger le camion sortant | Unité d'expédition, Commande, Lot, Expédition, Passage | Constituer, Contenir, Charger, Enlever |
| Générer les documents de transport | Document de transport, Expédition | Accompagner |
| Consulter les indicateurs de pilotage, Générer un rapport | lecture de Passage, Quai, Réception, Constater, Emplacement, Liste de prélèvement | — |

Les deux associations ternaires correspondent à des faits que le sujet décrit avec trois participants :
- un **constat d'écart** porte sur une réception, un article et un type d'écart (manquant, excédent, endommagé), avec la quantité concernée ;
- un **prélèvement** sort un lot donné d'un emplacement donné pour une liste donnée, avec la quantité à prélever et la quantité prélevée.
