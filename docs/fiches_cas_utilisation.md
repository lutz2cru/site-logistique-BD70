# Fiches textuelles des cas d'utilisation phares

Spécification textuelle de quatre cas d'utilisation du diagramme `uml/use_case_entrepot.drawio`,
selon le modèle vu en cours (identification, séquencement, préconditions, enchaînement, postconditions).
Ils ont été choisis parce qu'ils portent les règles métier les plus riches et les choix propres au projet.

| | Cas d'utilisation | Acteur principal | Étape du parcours |
|---|---|---|---|
| 1 | Déclarer son arrivée à la borne | Chauffeur | Étape 2 · Accueillir les camions sur le site |
| 2 | Gérer une arrivée sans rendez-vous | Agent d'accueil | Étape 2 · Accueillir les camions sur le site |
| 3 | Prélever les articles de sa liste | Magasinier / Préparateur | Étape 5 · Préparer et expédier les commandes |
| 4 | Passer une commande | Client propriétaire | Support · Portail du client propriétaire |

---

## 1. Déclarer son arrivée à la borne

**Identification**

| | |
|---|---|
| Nom du cas | Déclarer son arrivée à la borne |
| Objectif | Permettre au chauffeur d'enregistrer lui-même son arrivée sur rendez-vous, sans attendre au guichet du poste de garde |
| Acteurs | Chauffeur (principal) ; Agent d'accueil (secondaire, contrôle ensuite les documents) |
| Date | 29/10/2026 |
| Responsables | RAZEK, TELLITEL, LUTZ |
| Version | 1.0 |

**Séquencement** : le cas commence quand le chauffeur se présente devant la borne du poste de garde.

**Préconditions**
- Un rendez-vous *planifié* existe pour aujourd'hui, au nom du transporteur du chauffeur.
- Aucun passage n'est encore enregistré pour ce rendez-vous.
- Le chauffeur est référencé dans la base (numéro de permis connu) et rattaché à ce transporteur.

**Enchaînement nominal**
1. Le chauffeur saisit le numéro du rendez-vous, l'immatriculation du véhicule et son numéro de permis.
2. Le système retrouve le rendez-vous du jour (cas inclus « Vérifier le rendez-vous / détecter un retard »).
3. Le système vérifie que l'immatriculation est celle du véhicule prévu et que le chauffeur appartient au même transporteur.
4. Le système vérifie que le permis est en cours de validité.
5. Le système crée le passage, marqué « déclaré à la borne », et horodate l'étape « Arrivée au poste de garde ».
6. La borne affiche le numéro de passage, le quai prévu et les consignes : présenter les documents, attendre sur le parking, surveiller l'écran d'appel.

**Enchaînements alternatifs**
- *A1 – Informations incorrectes* (étape 2 ou 3) : la borne affiche un message volontairement générique, sans préciser quelle donnée est fausse, et invite le chauffeur à se présenter à l'agent d'accueil. Fin du cas.
- *A2 – Permis expiré* (étape 4) : l'accès est refusé et le chauffeur est renvoyé vers l'agent d'accueil. Fin du cas.
- *A3 – Arrivée trop tôt* (plus de 2 h avant le créneau) : la borne indique l'heure du rendez-vous et n'enregistre rien. Fin du cas.
- *A4 – Retard* (plus de 15 min après le début du créneau) : l'arrivée est enregistrée, mais la borne prévient que le quai pourra être réattribué.

**Postconditions**
- Le passage existe, à l'étape « Arrivée au poste de garde ». Il apparaît dans la liste des camions sur site de l'agent d'accueil, signalé « borne ».
- Le temps d'attente du camion est mesuré à partir de cet horodatage.

---

## 2. Gérer une arrivée sans rendez-vous

**Identification**

| | |
|---|---|
| Nom du cas | Gérer une arrivée sans rendez-vous |
| Objectif | Accueillir un camion qui se présente sans rendez-vous et lui trouver un quai compatible et libre |
| Acteurs | Agent d'accueil |
| Date | 29/10/2026 |
| Responsables | RAZEK, TELLITEL, LUTZ |
| Version | 1.0 |

**Séquencement** : le cas commence quand un camion se présente au poste de garde sans rendez-vous. Il étend le cas « Enregistrer l'arrivée d'un camion » (`«extend»`).

**Préconditions**
- L'agent d'accueil est connecté.
- Le véhicule et le chauffeur sont référencés. Sinon, l'agent les crée d'abord avec « Gérer les véhicules » et « Gérer les chauffeurs ».

**Enchaînement nominal**
1. L'agent choisit « Arrivée sans rendez-vous ».
2. Il sélectionne le véhicule, le chauffeur et le sens du flux (réception ou expédition).
3. Le système vérifie que le chauffeur appartient au transporteur du véhicule et que son permis est valide.
4. Le système crée le passage, sans rendez-vous, et horodate l'arrivée.
5. Après le contrôle des documents, l'agent appelle le camion à quai (cas inclus « Affecter un quai disponible »).
6. Le système ne propose que les quais *libres*, compatibles avec le type de véhicule et avec le sens du flux.
7. L'agent choisit un quai. Le système enregistre l'appel à quai, et le statut du quai passe à « occupé ».

**Enchaînements alternatifs**
- *A1 – Chauffeur d'un autre transporteur ou permis expiré* (étape 3) : l'enregistrement est refusé avec un message explicite.
- *A2 – Aucun quai compatible libre* (étape 6) : le camion reste en attente sur le parking, et le système affiche « aucun quai compatible libre ».

**Postconditions**
- Le passage est enregistré et rattaché à un quai dès l'appel à quai.
- Le camion apparaît sur l'écran d'appel du parking, avec le numéro de son quai.

---

## 3. Prélever les articles de sa liste

**Identification**

| | |
|---|---|
| Nom du cas | Prélever les articles de sa liste |
| Objectif | Exécuter une liste de prélèvement générée par le responsable d'exploitation, ligne par ligne, en tenant le stock à jour |
| Acteurs | Magasinier / Préparateur |
| Date | 29/10/2026 |
| Responsables | RAZEK, TELLITEL, LUTZ |
| Version | 1.0 |

**Séquencement** : le cas commence quand le préparateur ouvre une liste qui lui est affectée. Il inclut « Consulter ses listes de prélèvement » (`«include»`).

**Préconditions**
- Le responsable d'exploitation a généré la liste pour une commande, en appliquant la règle FIFO ou FEFO.
- La liste est affectée au préparateur, par le responsable ou par une prise en charge d'une liste non affectée.

**Enchaînement nominal**
1. Le préparateur consulte ses listes de prélèvement et ouvre une liste « à faire ».
2. Il commence le prélèvement. La liste passe « en cours » et l'heure de début est enregistrée.
3. Le système affiche les lignes dans l'ordre du parcours : emplacement, article, lot, date limite, quantité à prélever.
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
- Les heures de début et de fin alimentent l'indicateur « productivité des préparateurs ».

---

## 4. Passer une commande

**Identification**

| | |
|---|---|
| Nom du cas | Passer une commande |
| Objectif | Permettre au client propriétaire de commander l'expédition de ses propres marchandises depuis le portail |
| Acteurs | Client propriétaire |
| Date | 29/10/2026 |
| Responsables | RAZEK, TELLITEL, LUTZ |
| Version | 1.0 |

**Séquencement** : le cas commence quand le client, connecté au portail, choisit « Nouvelle commande ». Il inclut « Vérifier le stock disponible » (`«include»`).

**Préconditions**
- Le compte du client est validé par l'administrateur et rattaché à son entreprise (association « Agir pour »).
- Le client possède au moins un article en stock dans l'entrepôt.

**Enchaînement nominal**
1. Le système propose uniquement les articles du client. Pour chacun, il affiche la quantité disponible, c'est-à-dire le stock physique moins les quantités déjà réservées par les listes en cours.
2. Le client saisit la date d'expédition souhaitée et l'adresse de livraison, préremplie avec l'adresse de son entreprise.
3. Il ajoute les articles et les quantités voulus.
4. Le système vérifie chaque ligne : article du client, quantité positive, quantité inférieure ou égale au disponible, pas de doublon.
5. Le système enregistre la commande, au statut « reçue », avec le canal « portail », puis ses lignes.
6. Le client est redirigé vers le suivi de la commande.

**Enchaînements alternatifs**
- *A1 – Quantité supérieure au disponible* (étape 4) : la ligne est refusée, et le message indique la quantité disponible.
- *A2 – Date d'expédition trop proche* : l'expédition doit être demandée au plus tôt pour le lendemain.
- *A3 – Annulation* : tant qu'aucune liste de prélèvement n'existe, le client peut annuler la commande (cas « Annuler une commande », qui étend « Suivre ses commandes »).

**Postconditions**
- La commande apparaît dans « Commandes à préparer » chez le responsable d'exploitation, qui pourra générer sa liste de prélèvement.
- Le client suit ensuite chaque étape dans « Suivre ses commandes jusqu'à l'expédition » : préparation, chargement, départ du camion, documents de transport.
