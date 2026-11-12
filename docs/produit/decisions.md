# Décisions produit

> Registre des décisions de positionnement prises par le fondateur le **10 novembre 2026**, après la
> revue produit et ESS de novembre 2026. Chaque décision a sa raison et ce qui pourrait la faire
> réviser. Toute révision ajoute une ligne datée dans le journal en bas de page : on ne réécrit pas
> l'histoire.

## 1. Positionnement

**Panier Sauvage est l'outil du producteur.** Il remplace le groupe WhatsApp et le tableur d'un
producteur qui vend en direct (5 à 10 clients par livraison, 30 à 100 clients en tout) : il ouvre
une vente, ses clients précommandent, il sait quoi préparer et à qui livrer.

- **Ce n'est pas une place de marché et ça ne le deviendra pas.** Pas de panier multi-producteurs,
  pas de commission, pas de paiement centralisé, pas de mise en avant payée.
- **Le producteur paie un abonnement fixe** (10 € / mois ou 100 € / an). Le mangeur ne paie rien,
  à vie. 100 % du prix des produits va au producteur.
- **L'annuaire est un annuaire de voisinage**, pas une vitrine commerciale : une page secondaire où
  figurent les producteurs qui l'ont choisi, classés par distance seulement.
- **Le vrai concurrent est WhatsApp + un tableur, à 0 €.** Chaque fonctionnalité se juge à cette
  aune : est-ce que ça fait gagner du temps ou des clients au producteur, sans le compliquer ?

Promesse : « Tes précommandes sans WhatsApp. 10 € par mois, zéro commission, tes clients restent les
tiens, logiciel libre. »

## 2. Décisions « oui »

| # | Décision | Raison | Ce qui pourrait la faire réviser |
| --- | --- | --- | --- |
| O1 | `/` est l'**accueil producteur** (présentation, tarifs, inscription), refait pour être beau. | Le client de Panier Sauvage est le producteur ; c'est lui qui amène ses mangeurs par son propre lien. Un accueil « cherche un producteur » promet une densité qu'on n'a pas. | Une densité réelle atteinte sur plusieurs bassins **et** une part significative de commandes venues de l'annuaire. Même alors, l'annuaire resterait une entrée secondaire. |
| O2 | **Annuaire de producteurs** sur une page secondaire, **sur inscription volontaire** (case décochée par défaut). | Respect du choix du producteur (certains ne veulent pas de clients inconnus) ; pas de vitrine vide ; RGPD et DSA plus simples. | Retours de producteurs qui oublient de cocher et le regrettent : on peut le proposer à l'onboarding, jamais le cocher pour eux. |
| O3 | Annuaire **classé par distance uniquement**, mention « aucune mise en avant payée ». | Neutralité, transparence du classement (droit de la consommation), cohérence avec l'absence de commission. | Rien de commercial. Un filtre (catégorie, moyen de paiement) reste possible : il filtre, il ne classe pas. |
| O4 | **Aucun enregistrement de paiement** (SPEC §1), affichage des moyens acceptés en texte libre ou structuré. | Rester hors du champ des logiciels de caisse ; laisser tous les moyens possibles (espèces, Wero, chèques, monnaie locale, chèques alimentaires). | Une évolution légale qui imposerait autre chose, ou un rescrit défavorable. |
| O5 | **Socle de confiance avant le premier producteur réel** : textes légaux, CGU acceptées, identité du vendeur, vente au poids, contrôle de majorité pour l'alcool, vérification légère du SIRET, signalement. | Un producteur qui met ses clients sur l'outil engage sa réputation ; un manquement légal le pénalise lui d'abord. | Aucune : c'est un prérequis. |
| O6 | **Vente au poids**, **commande sans e-mail** (saisie par le producteur), **franchise en base de TVA**. | Bloquants observés : maraîchers, fromagers, bouchers vendent au kg ; certains clients âgés n'ont pas d'e-mail ; beaucoup de petits producteurs sont en franchise. | — |
| O7 | **Découverte croisée** discrète (« d'autres producteurs livrent près de chez toi ») sur la confirmation de commande et dans les e-mails, limitée aux producteurs inscrits à l'annuaire. | C'est le seul canal d'acquisition de mangeurs qui ne coûte rien et ne transforme pas l'outil en place de marché : chaque producteur garde sa boutique, son lien, ses clients. | Plaintes de producteurs qui y voient un détournement de leurs clients : on la rend désactivable par boutique. |
| O8 | **Palier solidaire / prix libre** pour les producteurs en installation ou à petit volume. | Le tarif ne doit pas exclure ceux qui en ont le plus besoin. | Abus massif ou soutenabilité menacée : on resserre les critères, on ne supprime pas le palier. |
| O9 | **Engagements publics** d'un projet porté par une seule personne (voir `engagements.md`). | Le fondateur reste seul ; des engagements écrits et vérifiables (code libre, export, préavis de prix) rassurent sans gouvernance partagée. | Aucune pour les engagements « à vie » ; les autres ne peuvent qu'être renforcés. |
| O10 | **Lancement sur un seul bassin** (Valence-Romans), en complément de CoopCircuits. | La densité locale fait la valeur de l'annuaire et du bouche-à-oreille ; un bassin à la fois. | Une demande entrante forte ailleurs, portée par un réseau partenaire. |
| O11 | **Instrumentation sobre** : mesures calculées sur les tables existantes, compteurs serveur agrégés, aucun outil tiers, aucun cookie de suivi. | Cohérence avec la sobriété et le RGPD ; on n'a pas besoin de plus pour piloter. | — |
| O12 | **Logiciel libre AGPL**, export des données producteur à tout moment. | « Tes clients restent les tiens » doit être vrai techniquement ; une collectivité peut héberger sa propre instance. | — |

## 3. Décisions « non »

| # | Exclu | Raison | Ce qui pourrait la faire réviser |
| --- | --- | --- | --- |
| N1 | **Panier multi-producteurs** | C'est la mécanique d'une place de marché : elle impose un paiement centralisé, une logistique commune et une commission. Les collectifs ont déjà CoopCircuits et Cagette. | **Jamais.** Décision de positionnement, pas de calendrier. |
| N2 | **Commission sur les ventes** | 100 % du prix au producteur ; le coût doit rester prévisible et indépendant du succès du producteur. | **Jamais.** Inscrit dans les engagements publics. |
| N3 | **Paiement en ligne / paiement centralisé** | Ferait de Panier Sauvage un intermédiaire de paiement (agrément, KYC, litiges, logiciel de caisse) et exclurait espèces, chèques alimentaires, monnaies locales. | **Jamais** pour le paiement centralisé. Un lien de paiement externe du producteur (affiché en texte) reste possible et ne change rien. |
| N4 | **Points de retrait mutualisés** entre producteurs | Mécanique de place de marché (logistique commune, responsabilité partagée). Chaque producteur peut déjà déclarer un point à la même adresse qu'un autre. | Seulement si des producteurs le demandent **sans** paiement ni panier commun, et sans que l'outil ne coordonne la logistique. |
| N5 | **SMS** | Coût par message, dépendance à un fournisseur, consentement plus lourd ; l'e-mail et le lien WhatsApp suffisent. Les clients sans e-mail sont saisis par le producteur. | Une demande récurrente de producteurs **et** un coût couvert explicitement (option payante au prix coûtant). |
| N6 | **Application native** | Le site est mobile d'abord, rendu serveur, utilisable en 4G faible ; une appli coûte deux plateformes et des magasins d'applications. | Un besoin impossible en web (ex. hors-ligne profond) — la feuille de livraison hors-ligne est prévue en web. |
| N7 | **Avis clients** | Logique de place de marché, modération lourde, obligations sur les avis en ligne, et inutile : le mangeur connaît son producteur. | Non prévu. |
| N8 | **Messagerie interne** | Le producteur a déjà son téléphone et ses e-mails ; les e-mails partent avec la réponse au producteur. Une messagerie impose modération et stockage de données sensibles. | Non prévu. |
| N9 | **Restauration collective (EGalim)** | Autre métier (marchés publics, volumes, facturation) ; pas dans cette vague. | **À revoir en 2027** si une collectivité partenaire le finance et si le cœur producteur est solide. C'est un « pas maintenant », pas un « jamais ». |
| N10 | **SCIC, coopérative ou gouvernance partagée** (collèges, agrément ESUS) | Le fondateur veut rester seul, avec un modèle simple. La confiance repose sur des engagements publics, le code libre et l'export des données (`engagements.md`). | Décision du fondateur uniquement. |

## 4. Ce qui fait réviser une décision

On rouvre une décision quand **au moins une** de ces conditions est réunie, et on note la révision
ci-dessous :

1. **Un indicateur contredit l'hypothèse** de la décision pendant deux mois de suite.
2. **Au moins 5 producteurs payants** formulent la même demande indépendamment.
3. **Une évolution légale ou fiscale** change le cadre (logiciel de caisse, facturation électronique,
   DSA).
4. **Le fondateur** constate qu'elle l'empêche de garder un modèle simple et soutenable seul.

Les décisions N1, N2 et N3 (« jamais ») ne se révisent pas par ce mécanisme : elles sont des
engagements publics, et les changer reviendrait à changer de projet.

## Journal

| Date | Décision | Changement |
| --- | --- | --- |
| 10 novembre 2026 | Toutes | Création du registre. |
