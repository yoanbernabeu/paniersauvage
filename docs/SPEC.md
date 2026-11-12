# Panier Sauvage — spécification V1

SaaS (10 € / mois) qui permet à un producteur professionnel (ex. un boulanger) de prendre des
précommandes en ligne au lieu de WhatsApp, puis de les livrer. Code open source (AGPL-3.0).
L'accueil (`/`) présente l'outil aux producteurs (tarifs, engagements, inscription). Un petit
annuaire secondaire, sur inscription volontaire des producteurs, aide les clients à en trouver près
de chez eux (`/trouver-un-producteur`, §11). Panier Sauvage est un outil du producteur, **jamais une
place de marché** : pas de panier multi-producteurs, pas de commission, pas de paiement centralisé,
pas de mise en avant payée, pas d'avis.

Exemple fil rouge : un boulanger prend les commandes jusqu'au vendredi 20 h, cuit le dimanche,
livre le lundi de 18 h à 19 h sur une place de village. 5 à 10 clients par livraison.

## 1. Règles produit non négociables

1. **Aucun enregistrement de paiement.** Pas de champ « payé », pas de mode de règlement, pas de
   table de paiement, pas de libellé « payée ». L'outil affiche le montant à régler et les moyens
   de paiement *acceptés* par le producteur (liste `PaymentMethod` + texte libre, ex. « Wero au
   06… », résumés par `Shop::paymentSummary()`). Raison :
   rester hors du champ des logiciels de caisse (BOI-TVA-DECLA-30-10-30). Statuts de commande :
   `confirmed`, `delivered`, `not_delivered`, `cancelled`.
2. **Pas de survente.** Toute réservation de stock passe par `App\Ordering\Service\ReservationService`
   (transaction + `SELECT … FOR UPDATE` sur `SaleOffer`) ; contrainte SQL `reserved_qty <= max_qty`.
3. **Isolation des producteurs.** Un producteur ne voit que les données de sa boutique (`Shop`).
   Toute requête côté `/pro` est filtrée par la boutique courante ; chaque route `/pro` a un test
   qui vérifie qu'un autre producteur reçoit 404 ou 403.
4. **Montants en centimes** (`int`), jamais de flottants. TVA en points de base (`550` = 5,5 %).
   **Quantités en millièmes d'unité** (`int`) partout (§13) : 2 pains = `2000`, 1,5 kg = `1500`.
5. **E-mail uniquement** en V1 (pas de SMS, pas de WhatsApp automatique). Un bouton « Partager sur
   WhatsApp » ouvre un lien `https://wa.me/?text=…` prérempli.
6. **Tutoiement chaleureux partout** (client et producteur), phrases courtes, français uniquement.
   Emojis rares (🌾, 🧺) dans les confirmations et e-mails seulement. Pas de facture envoyée au client.
7. Thème clair uniquement. Mobile d'abord (360 px). Accessibilité : vrais `<button>`, `<a>`,
   `<label>`, contrastes AA, cibles tactiles ≥ 44 px.

## 2. Acteurs et authentification

| Acteur | Entité | Connexion | Zone |
| --- | --- | --- | --- |
| Producteur | `App\Shop\Entity\User` | e-mail + mot de passe (form_login) | `/pro/**` (pare-feu `producer`) |
| Client | `App\Customer\Entity\Customer` | sans mot de passe : lien magique par e-mail (`login_link`) | reste du site (pare-feu `main`) |

Un client a **un compte unique** sur la plateforme (e-mail unique) et peut commander chez plusieurs
producteurs. Un producteur ne voit que ses propres clients (ceux qui ont commandé chez lui ou le suivent).
Exception : le **client sans e-mail** (§14), créé par un producteur, n'appartient qu'à sa boutique et ne
se connecte jamais.

## 3. Modèle de données

Un dossier par domaine sous `src/` : `Shop`, `Catalog`, `Sale`, `Delivery`, `Ordering`, `Customer`,
`Billing`, `Notification`, `Accounting`, `Legal`, `Shared`. Paniers récurrents : `Recurring` (§23, lot H). Chaque domaine contient `Entity/`, `Repository/`,
`Controller/`, `Form/`, `Service/`, `Enum/`, `Event/` selon ses besoins.

| Entité | Champs principaux |
| --- | --- |
| `Shop\Entity\User` | email (unique), password, firstName, lastName, roles, shop (ManyToOne), shopRole (`owner`/`member`, lot D §22.5), termsVersion, termsAcceptedAt (CGU acceptées, §12) |
| `Shop\Entity\Shop` | slug (unique), name, legalName (raison sociale, nullable), description, siret, address, phone, contactEmail (nullable : e-mail du compte sinon), logoPath, paymentMethods (jsonb, liste de `PaymentMethod` : `cash`, `cheque`, `instant_transfer` (Wero), `bank_transfer`, `card_on_site`, `local_currency`, `food_voucher`), localCurrencyName, foodVoucherDetails, paymentInfo (texte libre affiché au client), categories (jsonb, liste de `ShopCategory`), defaultEditCutoffHours (int, nullable = jusqu'à la date limite), allowCustomerEdit (bool), listedInSearch (bool, **faux par défaut** : « Apparaître dans l'annuaire des producteurs », exige au moins une catégorie), vatRegime (`normal`/`franchise`, §15), firstOrderCapCents et newCustomerDeposit (nullables, protection des nouveaux clients, lot D §22.3), crossDiscoveryEnabled (vrai par défaut, lot D §22.6) |
| `Shop\Entity\ShopInvitation` | shop, email, tokenHash (SHA-256, unique), invitedBy (User nullable), createdAt, expiresAt (7 jours), acceptedAt (lot D §22.5) |
| `Billing\Entity\ShopSubscription` | shop (OneToOne), plan (`monthly`/`yearly`/`solidarity`), status (`trialing`, `active`, `past_due`, `canceled`), trialEndsAt, currentPeriodEnd, stripeCustomerId, stripeSubscriptionId, amountCents (palier solidaire), solidarityCriteria (jsonb), solidarityDeclaredAt, solidarityReminderSentAt (lot E, §17) |
| `Catalog\Entity\Product` | shop, name, description, photoPath, priceCents (prix au kilo pour `kg`), vatRateBp, unit (`piece`/`kg`/`bunch`), allergens, labels, kind (`permanent`/`seasonal`), availableFrom, availableUntil, maxQtyDefault (nullable, millièmes), quantityStep et minQuantity (millièmes, vente au poids seulement, §13), archived, position, perishable (vrai par défaut : exclu du droit de rétractation), alcoholic (§12) |
| `Delivery\Entity\DeliveryPoint` | shop, type (`meeting_point`/`tour_stop`/`home`), name, address, instructions, archived, latitude, longitude (float, nullable, WGS 84 ; centre de la zone pour `home`), geocodedAt, city, postcode, communeSlug (`valence-26`, pages par commune), zoneTown et zoneRadiusKm (1 à 50 km, `home` seulement : zone de livraison) |
| `Sale\Entity\SaleSchedule` | shop, name, active, weekday de livraison (1-7), deliveryStartTime, deliveryEndTime, deadlineDaysBefore, deadlineTime, opensDaysBefore, editCutoffHours (nullable), deliveryPoints (ManyToMany), products (ManyToMany) |
| `Sale\Entity\Sale` | shop, schedule (nullable), code (court, unique), title, status (`scheduled`, `open`, `closed`, `done`, `cancelled`), opensAt, deadlineAt, editCutoffAt (nullable), allowCustomerEdit, deliveryDate, lastMinuteUntil et lastMinuteOpenedAt (nullable, vente de dernière minute, §17) |
| `Sale\Entity\SaleDelivery` | sale, deliveryPoint, startsAt, endsAt, position |
| `Sale\Entity\SaleOffer` | sale, product, priceCents, vatRateBp, maxQty (nullable = illimité), reservedQty (millièmes d'unité), lastMinute (remise en vente de dernière minute, §17) ; unique (sale, product) |
| `Customer\Entity\Customer` | email (unique, nullable pour un client sans e-mail), firstName, lastName, phone, ownerShop (nullable : boutique du client sans e-mail, §14), termsVersion, termsAcceptedAt (§12) |
| `Customer\Entity\ShopFollower` | customer, shop, emailConsent, createdAt ; unique (customer, shop) |
| `Ordering\Entity\Order` (table `customer_order`) | reference (unique), sale, customer, saleDelivery, status, totalCents, vatExempt (figé, §15), note, deliveryAddress (si domicile), source (`OrderSource` nullable : `directory`, `cross_discovery`, `shared_link`, `subscription` (commande générée, lot H, jamais lue depuis l'URL)), createdAt, updatedAt |
| `Ordering\Entity\OrderLine` | order, saleOffer, quantity (millièmes), unitPriceCents, vatRateBp, label, unit (figés), actualQuantity (poids réel, nullable, §13) |
| `Ordering\Entity\OrderEvent` | order, type, actor (`customer`/`producer`/`system`), detail (json), createdAt |
| `Ordering\Entity\DeliverySheetAction` (table `delivery_sheet_action`) | id (UUID choisi par le navigateur, clé primaire), order, type (`mark`/`weigh`), outcome (`applied`/`unchanged`/`conflict`), message, processedAt : actions déjà traitées de la feuille hors ligne (§20) |
| `Notification\Entity\NotificationLog` | customer (nullable), type, channel, status, relatedType, relatedId, sentAt |
| `Legal\Entity\ShopVerification` | shop (OneToOne), siret (le SIRET vérifié), status (`pending`, `verified`, `to_review`, `not_found`, `closed`, `rejected`), companyName, nafCode, checkedAt, verifiedAt, reviewNote |
| `Accounting\Entity\AccountingSettings` | shop (OneToOne), salesAccount (`706`), vatAccount (`44571`), counterpart (`customer`/`cash`), customerAccount (`411`), cashAccount (`530`), journalCode (`VE`) — lot E, §18 |
| `Catalog\Entity\CatalogPublication` | shop (OneToOne), dfcPublic (faux par défaut), changedAt — lot E, §19 |
| `Recurring\Entity\RecurringPlan` (lot H, §23) | schedule (OneToOne `SaleSchedule`), shop, enabled (inscriptions ouvertes), title, description, mode (`basket`/`choice`), maxShares, capacity (nullable), term (`open_ended`/`season`), seasonStartsOn, seasonEndsOn, noticeDays, maxPauses (nullable), conditions, items (`RecurringPlanItem` : product, quantity en millièmes) |
| `Recurring\Entity\RecurringOrder` (table `recurring_order`, lot H) | plan, customer (avec e-mail), deliveryPoint, deliveryAddress, status (`active`, `suspended`, `ended`), shares, lines (`RecurringOrderLine` : product, quantity), pausedDates (jsonb, `Y-m-d`), startsOn, endsOn, endReason (`customer`, `producer`, `contract_end`), adultCertifiedAt ; un seul abonnement en cours par (plan, client), index unique partiel |
| `Recurring\Entity\RecurringOccurrence` (lot H) | recurringOrder, sale, deliveryDate, outcome (`placed`, `partial`, `paused`, `out_of_stock`, `unavailable`, `already_ordered`), order (nullable), missing (jsonb) ; **unique (recurring_order, sale)** |
| `Shop\Entity\SearchStat` | day, department (`26`, `974`, vide si inconnu), outcome (`results`/`empty`/`not_found`), searches ; unique (day, department, outcome). Agrégat journalier, ni IP ni cookie |

Règles : gamme **permanente** proposée par défaut dans chaque vente ; produits **du moment**
(`seasonal`) proposés quand la date de livraison tombe dans leur période ou ajoutés à la main.
Prix, TVA et libellé figés dans `OrderLine`. Un produit archivé disparaît des nouvelles ventes.

## 4. Cycle d'une vente

- **Récurrence automatique** : un `SaleSchedule` actif génère les ventes des 4 prochaines semaines.
- **Vente manuelle** : le producteur crée une vente ponctuelle.
- Statuts : `scheduled` → `open` (à `opensAt`) → `closed` (à `deadlineAt`) → `done` (lendemain de la
  livraison), ou `cancelled`. Ouverture et clôture automatiques (Scheduler).
- Livraison : une vente a 1..n `SaleDelivery` (point de rendez-vous, arrêt de tournée, domicile).
  Domicile : le client saisit son adresse, aucune règle automatique.
- Le client peut modifier/annuler jusqu'à `editCutoffAt` (défaut : `deadlineAt`) si
  `allowCustomerEdit`. Après la date limite, **seul le producteur** peut ajouter, modifier ou retirer
  une commande ; le client est prévenu par e-mail à chaque changement.
- Limite de quantité **par produit** uniquement (pas de capacité globale en V1).
- **Vente de dernière minute** (§17) : une vente `closed` peut reprendre des commandes sur ses invendus
  pendant une courte fenêtre, **sans changer de statut** (ni retour à `open`, ni nouveau `SaleClosed`).

## 5. Routes (noms figés — utilisez-les tels quels)

Côté producteur (`/pro`, pare-feu `producer`, rôle `ROLE_PRODUCER`) :

| Route | Chemin | Lot |
| --- | --- | --- |
| `pro_register` | `/pro/inscription` | A1 |
| `pro_login` / `pro_logout` | `/pro/connexion`, `/pro/deconnexion` | Fondations |
| `pro_onboarding` | `/pro/bienvenue` | A1 |
| `pro_dashboard` | `/pro` | A2 |
| `pro_shop_settings` | `/pro/boutique` | A1 |
| `pro_product_index` / `_new` / `_edit` / `_archive` | `/pro/produits…` | A1 |
| `pro_delivery_point_index` / `_new` / `_edit` / `_archive` | `/pro/points-de-livraison…` | A1 |
| `pro_schedule_index` / `_new` / `_edit` / `_toggle` | `/pro/recurrences…` | A2 |
| `pro_sale_index` / `_new` / `_show` / `_edit` / `_cancel` | `/pro/ventes…` | A2 |
| `pro_sale_order_new` / `pro_order_edit` / `pro_order_cancel` | `/pro/ventes/{id}/commandes/…` | A2 |
| `pro_sale_delivery_sheet` | `/pro/ventes/{id}/livraison` (écran mobile, cases « livrée ») | A2 |
| `pro_order_mark` | `POST /pro/commandes/{id}/statut` | A2 |
| `pro_sale_production_pdf` | `/pro/ventes/{id}/production.pdf` | D |
| `pro_sale_delivery_pdf` | `/pro/ventes/{id}/livraison.pdf` | D |
| `pro_sale_orders_csv` | `/pro/ventes/{id}/commandes.csv` | D |
| `pro_accounting` / `pro_accounting_csv` / `pro_accounting_pdf` | `/pro/comptabilite…` | D |
| `pro_billing` / `pro_billing_checkout` / `pro_billing_portal` | `/pro/abonnement…` | D |
| `stripe_webhook` | `POST /webhooks/stripe` | D |
| `pro_terms_accept` | `/pro/conditions` (nouvelle version des CGU à accepter avant tout `/pro`) | Juridique |
| `pro_billing_solidarity` | `POST /pro/abonnement/solidaire` (déclaration du palier solidaire) | E (vague 2) |
| `pro_accounting_entries_csv` / `pro_accounting_fec` | `/pro/comptabilite/ecritures.csv`, `/pro/comptabilite/ecritures-fec.txt` | E (vague 2) |
| `pro_accounting_settings` | `/pro/comptabilite/comptes` | E (vague 2) |
| `pro_catalog_dfc` / `_download` / `_publish` | `/pro/produits/data-food-consortium…` | E (vague 2) |

Ajouts (lot C, vague 1) : `pro_order_weigh` (`POST /pro/commandes/{id}/poids`, poids réels depuis la
feuille de livraison), `pro_offline_customer_edit` (`/pro/clients-sans-email/{id}`) et
`pro_offline_customer_erase` (`POST /pro/clients-sans-email/{id}/effacer`).

Ajouts (lot F, vague 2) : `pro_order_sheet_action` (`POST /pro/commandes/{id}/action`, JSON, actions
rejouées de la feuille de livraison hors ligne, §20), `pro_delivery_sheet_worker`
(`/pro/feuille-hors-ligne.js`, service worker de la feuille), `pro_sale_last_minute`
(`/pro/ventes/{id}/derniere-minute`) et `pro_sale_last_minute_end`
(`POST /pro/ventes/{id}/derniere-minute/arreter`), §21.

Ajouts (lot D, vague 2) : `pro_customer_index` (`/pro/clients`, `?q=` et `?tri=`), `pro_customer_csv`
(`/pro/clients.csv`), `pro_customer_show` (`/pro/clients/{id}`), `pro_stats` (`/pro/statistiques`),
`pro_team` (`/pro/equipe`), `pro_team_invite` (`POST /pro/equipe/inviter`), `pro_team_invitation_cancel`
(`POST /pro/equipe/invitations/{id}/annuler`), `pro_team_member_remove` (`POST /pro/equipe/membres/{id}/retirer`),
`pro_team_invitation_token` (`/pro/invitation/{token}`, public) et `pro_team_invitation_accept`
(`/pro/invitation`, public).

Ajouts (lot H, §23) : `pro_recurring_index` (`/pro/paniers`), `pro_recurring_plan_edit`
(`/pro/recurrences/{id}/panier`), `pro_recurring_order_show` (`/pro/paniers/abonnes/{id}`),
`pro_recurring_order_suspend` / `_resume` / `_end` (`POST …/suspendre`, `…/reprendre`, `…/terminer`).

Côté client (pare-feu `main`) :

| Route | Chemin | Lot |
| --- | --- | --- |
| `home` | `/` (présentation aux producteurs : promesse, fonctionnement, tarifs, engagements, FAQ, inscription) | A (vague 1) |
| `producer_directory` | `/trouver-un-producteur` (annuaire : `?ou=…&rayon=…` ou `?lat=…&lng=…&rayon=…`, filtres `categorie`, `bio`, `cheques-alimentaires`, `monnaie-locale`) | A (vague 1) |
| `producer_directory_commune` | `/trouver-un-producteur/{commune}` (page indexable d'une commune, ex. `valence-26`) | A (vague 1) |
| `sitemap` | `/sitemap.xml` | A (vague 1) |
| `customer_login` / `customer_login_check` / `customer_logout` | `/connexion`, `/connexion/verification`, `/deconnexion` | B |
| `customer_account` | `/mon-compte` (mes commandes, mes producteurs suivis, profil) | B |
| `customer_order_show` / `_edit` / `_cancel` / `_ics` | `/mes-commandes/{reference}…` | B |
| `shop_show` | `/{slug}` (page publique : ventes ouvertes, catalogue, points de retrait et carte, paiement, identité du vendeur, producteurs voisins) | B |
| `shop_follow` / `shop_unfollow` | `POST /{slug}/suivre`, `/{slug}/ne-plus-suivre` | B |
| `sale_show` | `/{slug}/vente/{code}` (commande) | B |
| `shop_report` | `/{slug}/signaler` (signalement d'une boutique) | Juridique |
| `shop_dfc_catalog` | `/{slug}/catalogue.jsonld` (catalogue Data Food Consortium, si le producteur l'a publié, 404 sinon) | E (vague 2) |
| `legal_notice` / `legal_terms` / `legal_privacy` / `legal_commitments` | `/mentions-legales`, `/cgu`, `/confidentialite`, `/engagements` | Juridique |
| `recurring_plan_show` / `recurring_plan_login` | `/{slug}/panier/{id}` (page d'un panier récurrent, abonnement), `…/connexion` | H |
| `customer_recurring_show` / `_pause` / `_unpause` / `_cancel` | `/mon-compte/paniers/{id}` (+ `POST …/pause`, `…/reprise`, `…/resilier`) | H |

`/{slug}` est déclaré en dernier (priorité basse). Slugs réservés : liste unique
`Shop::RESERVED_SLUGS` (`pro`, `producteurs`, `trouver-un-producteur`, `connexion`, `deconnexion`, `mon-compte`,
`mes-commandes`, `e-mails`, `webhooks`, `uploads`, `assets`, `_components`, etc.), appliquée par la
validation de `Shop` et par `ShopSlugGenerator`. `ReservedSlugsTest` vérifie que le premier segment
de chaque route y figure : **toute nouvelle route publique ajoute son segment à cette liste**.

## 6. Événements de domaine (découplage entre lots)

Les contrôleurs et services **dispatchent** ces événements (`EventDispatcherInterface`) ; le lot C
(notifications) s'y abonne et envoie les e-mails. Aucun lot n'appelle directement le mailer d'un autre.

| Événement (`App\…\Event`) | Dispatché quand | E-mail |
| --- | --- | --- |
| `Ordering\Event\OrderPlaced(Order, actor)` | commande créée | confirmation au client (+ .ics) |
| `Ordering\Event\OrderUpdated(Order, actor)` | commande modifiée | récapitulatif au client |
| `Ordering\Event\OrderCancelled(Order, actor)` | commande annulée | avis au client |
| `Sale\Event\SaleOpened(Sale)` | vente passe à `open` | notification aux abonnés consentants |
| `Sale\Event\SaleClosed(Sale)` | vente passe à `closed` | récapitulatif au producteur |
| `Sale\Event\SaleCancelled(Sale)` | vente annulée | avis aux clients ayant commandé |
| `Sale\Event\LastMinuteSaleOpened(Sale)` | vente de dernière minute ouverte (§21) | une fois, aux abonnés consentants sans commande dans la vente |
| `Recurring\Event\RecurringOrderStarted(RecurringOrder)` | abonnement pris | confirmation à l'abonné (lot H) |
| `Recurring\Event\RecurringOrderGenerated(RecurringOccurrence)` | commande générée, ou aucune (rupture, point) | « ta commande est prête » avec pause / modification (lot H) |
| `Recurring\Event\RecurringShortagesDetected(Sale, occurrences)` | abonnés servis incomplètement | récapitulatif au producteur (lot H) |
| `Recurring\Event\RecurringOrderSuspended(RecurringOrder)` | suspendu par le producteur | avis à l'abonné (lot H) |
| `Recurring\Event\RecurringOrderEnded(RecurringOrder)` | fin de contrat, arrêt par le producteur, résiliation | avis à l'abonné, sauf s'il a résilié lui-même (lot H) |

`actor` est un `App\Shared\Enum\Actor` (`customer`, `producer`, `system`).

Aucun e-mail n'est envoyé à un client sans e-mail (§14). La saisie du poids réel (§13) n'envoie pas
d'e-mail : elle est tracée dans l'historique de la commande (`OrderEvent` de type `weighed`).

## 7. Abonnement SaaS

10 € / mois ou 100 € / an (Stripe Billing, Checkout + portail client + webhooks), ou le **palier
solidaire** à prix libre de 0 à 10 € / mois (§16). Essai gratuit
de 30 jours à l'inscription, sans carte. Abonnement inactif (`past_due` dépassé ou `canceled`,
essai expiré) : les ventes en cours vont à leur terme, **aucune nouvelle vente ne s'ouvre ni ne se
crée**, les données restent consultables et exportables. Méthode de référence :
`ShopSubscription::allowsNewSales()`.

## 8. Direction artistique

Ambiance « nature sauvage moderne ». Deux tons sur la même identité :
- **Client** : chaleureux, aéré, grandes vignettes, Fraunces visible, un seul bouton jaune blé par écran.
- **Producteur** : dense et sobre, tableaux et listes, Inter partout sauf titres de page.

| Jeton | Valeur | Usage |
| --- | --- | --- |
| `sage` | `#5B7B6A` | aplats, bordures actives |
| `sage-dark` | `#46604F` | textes, liens, en-tête producteur (contraste AA) |
| `sage-deep` | `#2F4337` | texte sur fond sauge clair |
| `sage-soft` | `#F1F5F2` / `#E3EBE5` | fonds doux |
| `wheat` | `#E8B931` | bouton d'action principal, toujours avec texte foncé |
| `wheat-soft` | `#FBF1D3` (texte `#5A4300`) | badges |
| `cream` | `#FAF8F3` | fond général côté client |
| `ink` | `#1F2A24` | texte ; secondaire `#56635B` ; bordures `#E6E1D6` / `#ECE8DE` |

Titres : **Fraunces** (500/600). Texte : **Inter** (400/500/600), chiffres tabulaires pour les montants.
Polices auto-hébergées dans `assets/fonts/` (aucun appel à Google Fonts). Icônes : Lucide (SVG inline,
trait 1.8). Illustrations au trait (panier, épi, herbes) uniquement : accueil, états vides, e-mails, 404.
Rayons : cartes 16 px, champs 12 px, boutons pilule. Composants : kit shadcn du Symfony UX Toolkit
(`templates/components/`), rethémés avec ces jetons.

## 9. Stack

PHP ≥ 8.4 (8.5 en production), Symfony 8.1, Doctrine ORM + PostgreSQL 18, Twig + Symfony UX (Turbo,
Stimulus, Live Components), AssetMapper, Tailwind (symfonycasts/tailwind-bundle), Messenger
(transport Doctrine) + Scheduler, Mailer (Brevo en production, Mailpit en local), Stripe Billing,
dompdf (gabarits HTML/CSS simples : tableaux, pas de flexbox), FrankenPHP + Docker Compose sur un VPS,
GitHub Actions. Qualité : PHPUnit, PHPStan niveau max, PHP-CS-Fixer (@Symfony), Rector.

## 10. Hors V1

Prépaiement en ligne, SMS/WhatsApp automatiques, connexion par téléphone, capacité globale de
fournée, frais de livraison à domicile et contrôle de l'adresse du client dans la zone, factures
client, mode sombre, Mercure (quantités en temps réel). Jamais (décision du propriétaire) : panier
multi-producteurs, commission, paiement centralisé, mise en avant payée, avis clients, messagerie
interne, points de retrait mutualisés.

## 11. Annuaire des producteurs (page secondaire, sur inscription)

L'annuaire complète l'outil : un producteur prend ses commandes par son propre lien, l'annuaire
aide seulement les gens du coin à le découvrir. Il dit sur chaque page : « Producteurs qui utilisent
Panier Sauvage et ont choisi d'apparaître ici, classés uniquement par distance. Aucune mise en
avant payée. »

- **Visibilité** : une boutique apparaît si elle l'a choisi (`listedInSearch`, faux par défaut,
  réglage expliqué dans `pro_shop_settings` et étape facultative des premiers pas), avec au moins une
  catégorie (`ShopCategory`), si son SIRET est vérifié (§12, `ShopVerificationRepository::verifiedSql()`), si son abonnement autorise les ventes (`ShopSubscription::allowsNewSales()`,
  traduit en SQL par `ShopSubscriptionRepository::allowsNewSalesSql()`, testé à l'identique) et si
  au moins un de ses points actifs `meeting_point`/`tour_stop` a des coordonnées, ou si le visiteur
  est dans une de ses **zones de livraison à domicile** (point `home` avec commune `zoneTown`
  géocodée et rayon `zoneRadiusKm`) : distance 0, « livre chez toi ».
- **Géocodage** : API Adresse de l'État (BAN, `https://api-adresse.data.gouv.fr/search/`, gratuite,
  sans clé), client HTTP `ban.client` (timeout court, User-Agent générique), score minimal 0,5
  (`App\Delivery\Geocoding\BanGeocoder`). Seules des données publiques partent : l'adresse d'un
  point de retrait, la commune d'une zone ou le lieu tapé par le visiteur. Un point est géocodé à
  l'enregistrement quand son adresse, sa commune de zone ou son type change
  (`DeliveryPointGeocodingListener`, `preFlush`) ; la commune et le code postal renvoyés par la BAN
  remplacent ceux lus dans l'adresse (`city`, `postcode`, `communeSlug`). Une panne ne bloque jamais
  l'enregistrement (coordonnées nulles, log). `app:delivery-points:geocode` géocode les points et
  zones sans coordonnées (reprise, `--all` pour tout refaire).
- **Recherche** : ville ou code postal géocodés côté serveur (cache `geocoding.cache` : 30 jours si
  trouvé, 1 jour sinon, jamais en cas de panne), ou « Autour de moi » (géolocalisation du navigateur,
  contrôleur Stimulus `geolocate`, position arrondie à 3 décimales, jamais stockée). Limite
  `producer_search_ip` : 30 recherches / 5 min par IP (429 au-delà). Pages de résultats en `noindex`.
- **Filtres** (`App\Shop\Search\SearchFilters`) : catégorie, « produits bio » (un label d'un produit
  actif commence par Bio, AB ou Agriculture biologique), « chèques ou caisse alimentaire », « monnaie
  locale ». Ils réduisent la liste, ne la réordonnent jamais.
- **Distance** : formule de Haversine en SQL natif (pas de PostGIS), pré-filtre par boîte englobante
  sur l'index (latitude, longitude). Rayon 10 / 30 / 50 km (30 par défaut), 50 résultats au plus,
  triés par distance au point le plus proche (0 dans une zone de livraison). Trois requêtes en tout :
  boutiques + distance, prochaine vente (ouverte, sinon programmée), labels des produits ; plus
  l'incrément du compteur.
- **Résultats** : nom, distance et point, catégories, labels dédupliqués, prochaine vente ; liens
  `shop_show?via=recherche`.
- **Pages par commune** : `/trouver-un-producteur/{commune}` pour chaque `communeSlug` d'un point fixe
  localisé d'une boutique de l'annuaire (404 sinon), producteurs à moins de 10 km du centre des
  points de la commune, `canonical`, titre et description propres, indexables. La page d'accueil de
  l'annuaire les liste. `sitemap.xml` : accueil, annuaire, pages légales existantes, communes, pages
  des boutiques de l'annuaire (jamais celles qui ne s'y sont pas inscrites).
- **Carte** : complément de la liste (qui reste l'information principale), Leaflet via l'importmap,
  contrôleur Stimulus `producer-map` nourri par des valeurs JSON rendues côté serveur
  (`App\Shop\Search\SearchMap`, aucune requête de plus) : cercle du rayon, point cherché, point de
  retrait le plus proche de chaque boutique (pas de marqueur pour une zone de livraison). Masquée
  sans JavaScript ou sans résultat localisé. Tuiles `MAP_TILE_URL` / `MAP_TILE_ATTRIBUTION` : **Plan
  IGN de la Géoplateforme** par défaut (WMTS `GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2`, `PM_0_19`, gratuit,
  sans clé ni quota, Licence Ouverte Etalab), fournisseur interchangeable (`App\Shared\Map\MapTiles`) ;
  la CSP n'autorise dans `img-src` que l'hôte de ces tuiles (`https://data.geopf.fr`). La page boutique
  a sa petite carte des points de retrait (contrôleur `points-map`, `App\Shop\Search\PointsMap`).
  Au clavier, les marqueurs forment un seul arrêt de tabulation (flèches, Début, Fin pour passer
  d'un producteur à l'autre) ; au doigt, un doigt fait défiler la page et deux doigts déplacent la
  carte (un message le rappelle). Ouvrir un marqueur fait défiler la liste jusqu'à la boutique
  seulement quand la carte est à côté de la liste (≥ `lg`).
- **Découverte croisée** (`App\Shop\Search\CrossDiscovery`) : « D'autres producteurs près de ce point
  de retrait », au plus 3 boutiques de l'annuaire à moins de 10 km du point (hors boutique courante),
  par distance, avec leur prochaine vente ; sur la page boutique (premier point fixe localisé), la
  confirmation de commande et l'e-mail `order_placed` (commande passée par le client seulement).
  Liens `?via=croise`. Rien pour une livraison à domicile.
- **Provenance** (`App\Ordering\Enum\OrderSource`) : `?via=recherche|croise|lien` (lien partagé par le
  producteur : copie et WhatsApp de `ShopLink`, message de `SaleShareLink`) passe de la page boutique
  aux liens de commande, puis dans un champ caché du formulaire ; stockée dans `Order::source`. Aucun
  cookie.
- **Instrumentation sobre** : `SearchCounter` compte chaque recherche aboutie par jour, département
  (code postal du lieu trouvé, ou tapé) et issue (`results`, `empty`, `not_found`) dans `search_stat`,
  sans IP, cookie ni texte cherché. `app:stats:search [--days=30] [--limit=10]` liste les départements
  avec le plus de recherches sans résultat (où recruter des producteurs).

## 12. Cadre juridique et confiance

Textes rédigés sans validation juridique : à faire relire par un juriste avant le premier producteur
réel (`docs/LEGAL.md`). Domaine `App\Legal`.

- **Éditeur et hébergeur** : variables `LEGAL_*` (`App\Legal\Service\PublisherIdentity`), valeurs
  factices « à renseigner » dans `.env` ; l'image de production refuse de démarrer avec une valeur
  vide ou factice (`docker/entrypoint.sh`, voir `docs/DEPLOY.md`).
- **Pages** (gabarit client, version et date en tête, `App\Legal\LegalDocuments`) : mentions légales
  (LCEN), CGU (dispositions communes, partie producteurs, partie clients, annexe 1 accord de
  sous-traitance art. 28 RGPD, annexe 2 formulaire type de rétractation), confidentialité, engagements
  publics (zéro commission à vie, prix public unique, AGPL, export, annuaire classé par distance sans
  mise en avant payée, aucune donnée revendue, sobriété). Liens dans le pied des trois gabarits et de
  tous les e-mails.
- **Rôles RGPD** : le producteur est responsable du traitement des données de ses clients pour ses
  commandes, Panier Sauvage son sous-traitant ; Panier Sauvage est responsable pour les comptes
  (clients et producteurs), l'annuaire et la sécurité.
- **Acceptation des CGU** (date + version) : case obligatoire à l'inscription producteur, à la
  création d'un compte client et à la commande (invité, ou client qui n'a pas la version courante).
  La case d'un invité n'est enregistrée que sur un compte qu'elle crée. Changer
  `LegalDocuments::TERMS_VERSION` envoie chaque producteur sur `pro_terms_accept` avant toute page
  `/pro` (`TermsAcceptanceSubscriber`) et redemande la case aux clients à leur commande suivante.
- **Information précontractuelle** (page de vente, e-mail de confirmation) : identité du vendeur
  (nom, producteur, SIRET, adresse, téléphone, e-mail), prix TTC, retrait, paiement direct au
  producteur. **Rétractation** : `Product::perishable` (vrai par défaut) exclut du droit de
  rétractation ; un produit non périssable est marqué sur la page et l'e-mail rappelle le délai de
  14 jours et le formulaire type.
- **Alcool** (`Product::alcoholic`) : message sanitaire sur le produit, la page et l'e-mail ; case
  « Je certifie avoir 18 ans ou plus » validée côté serveur dès qu'une boisson alcoolisée est au panier
  (commande et modification client, pas côté producteur) ; « Vérifie l'âge au retrait » sur la feuille
  de livraison (écran et PDF).
- **Vérification des producteurs** : quand le SIRET d'une boutique change, `ShopVerificationListener`
  (`preFlush`) le contrôle (somme de Luhn, puis API Recherche d'entreprises
  `https://recherche-entreprises.api.gouv.fr/search?q=<siret>`, client `company_registry.client`,
  timeout court, User-Agent générique, cache `company_registry.cache` d'un jour, seul le SIRET part).
  Établissement actif et NAF agricole ou alimentaire (`NafClassifier` : divisions 01-03, 10-11,
  47.21Z à 47.25Z, 47.29Z, 47.81Z) : `verified`. Autre activité : `to_review`, e-mail à
  `LEGAL_CONTACT_EMAIL` (`ShopFlaggedForReview`), décision par `app:shops:review --approve|--reject`.
  Panne : `pending`, jamais bloquant, nouvelle tentative par `app:shops:verify` (toutes les 6 h,
  `--all` pour tout revérifier). Seules les boutiques vérifiées pour leur SIRET actuel figurent dans
  l'annuaire. Partiel `shop/_verified_badge.html.twig` (« SIRET vérifié ») pour la page boutique.
- **Signalement** : `shop_report` (motif, message, e-mail facultatif ; CSRF, pot de miel, limite
  `shop_report_ip` de 5 par heure), e-mail à `LEGAL_CONTACT_EMAIL` (`ShopReported`, réponse à
  l'auteur si e-mail). Partiel `shop/_report_link.html.twig` pour la page boutique.

## 13. Vente au poids

- **Quantités entières et exactes** : toute quantité (ligne de commande, stock réservé et maximum d'une
  offre, maximum par défaut d'un produit) est un `int` en **millièmes d'unité**
  (`App\Shared\Service\Quantity`, `Quantity::UNIT = 1000`). Les unités `piece` et `bunch` n'acceptent
  que des multiples de 1000 (`ProductUnit::accepts()`, vérifié par `OrderLine`, `SaleOffer::reserve()`,
  `OrderManager` et les formulaires) ; `kg` accepte le gramme. La contrainte SQL `reserved_qty <= max_qty`
  et `ReservationService` (verrou `FOR UPDATE`) sont inchangés.
- **Produit au kilo** : `priceCents` est le prix du kilo ; `quantityStep` (100, 250, 500 g ou 1 kg) et
  `minQuantity` (multiple du pas) encadrent la commande du client (`Product::acceptsOrderQuantity()`).
  Le producteur, lui, peut saisir n'importe quel poids (commande prise au téléphone).
- **Saisie** : champ décimal (« 1,5 » ou « 1.5 », trois décimales au plus, `QuantityType`), stepper par
  pas côté client, jamais de flottant côté serveur. Affichage via `FrenchFormatter::quantity()` et les
  filtres Twig `|quantity(unit)` (« 1,5 kg », « 2 »), `|line_quantity(unit)` (« 1,5 kg » ou « 2 × ») et
  `|quantity_input` (« 1.5 », valeur d'un `<input type="number">`).
- **Arrondi** : total de ligne = prix unitaire × quantité / 1000, arrondi au centime le plus proche, demis
  vers le haut (`Quantity::priceCents()`, repris à l'identique par le contrôleur Stimulus `order-form`).
- **Articles** : une ligne au poids compte pour un article, les autres pour leur nombre d'unités.
- **Poids réel** : avant pesée, le montant d'une ligne au poids est une estimation (« environ … »,
  `Order::isEstimated()`). Le producteur saisit le poids réel sur la feuille de livraison (un formulaire
  par commande, gros champs) ou dans l'édition de la commande (`App\Ordering\Service\OrderWeighing`,
  verrou sur la commande, événement `weighed`). La ligne est alors facturée sur le poids réel : montant
  à régler, exports CSV/PDF, comptabilité et statistiques suivent. Le stock reste réservé sur la quantité
  commandée ; la fiche de production montre la quantité commandée. Aucun paiement n'est enregistré.

## 14. Client sans e-mail

- Côté producteur uniquement (`pro_sale_order_new`) : une commande avec nom et téléphone, sans e-mail
  (`OrderFormType` option `email_optional`). Le client est créé par `Customer::withoutEmail($shop)` via
  `App\Customer\Service\OfflineCustomerManager` (retrouvé ensuite par téléphone, chiffres seuls, et nom).
- Il appartient à **une seule boutique** (`ownerShop`) : jamais visible d'une autre (404), jamais de
  connexion, **aucun e-mail** (confirmation, modification, annulation, rappel, annulation de vente).
- Le producteur le gère sur `pro_offline_customer_edit` : coordonnées (reportées sur ses commandes),
  effacement (RGPD, `CustomerEraser`, refusé tant qu'une livraison est en attente). S'il ajoute une
  adresse : inconnue, le client devient un compte ordinaire (il pourra se connecter et recevra les
  prochains e-mails, aucun envoi immédiat) ; déjà utilisée, ses commandes passent sur ce compte (qui
  garde son profil) et le client sans e-mail est supprimé.

## 15. Régime de TVA

- Réglage de boutique `vatRegime` : `normal` (taux de chaque produit) ou `franchise` (franchise en base,
  art. 293 B du CGI). Le régime est **figé sur la commande** à sa création (`Order::vatExempt`) : ses
  lignes sont à 0 %, même si le producteur change de régime ensuite (seules les nouvelles commandes
  suivent).
- Mention « TVA non applicable, art. 293 B du CGI » (`VatRegime::FRANCHISE_MENTION`) sur le récapitulatif
  de commande et ses e-mails, les PDF de la vente (production, livraison), le journal (écran, PDF) et
  les exports CSV (colonne « Mention TVA »).
- Journal comptable : les ventes en franchise ont leurs propres lignes (par taux, par produit, détail),
  HT = TTC et TVA = 0, même quand le régime change en cours de mois.

## 16. Lot I — Instance territoriale

Une collectivité ou un PAT peut déployer sa propre instance du logiciel libre : **déploiement
séparé** du même code (AGPL), configuré par variables d'environnement, **sans multi-tenant** dans
la base, sans gouvernance partagée. Guide : `docs/INSTANCE.md`. Domaine `App\Instance`.

- **Réglages** (`App\Instance\InstanceSettings`, variables `INSTANCE_*` documentées dans `.env`,
  `.env.prod.example`, `docs/DEPLOY.md`) : nom, logo (fichier de `public/`), couleur d'accent
  (`AccentColor` : `sauge`, `riviere`, `terre`, `prune`, `ardoise`), territoire, texte d'accueil,
  organisme porteur (nom, lien), mode de tarification (`PricingMode`). Les défauts donnent
  exactement Panier Sauvage. Toute valeur est validée (`InvalidInstanceSettingException`) ;
  `app:instance:check`, lancé par `docker/entrypoint.sh`, refuse le démarrage sinon. Global Twig
  `instance`.
- **Nom** : `<title>`, description par défaut, en-tête (`Logo`), pied de page, `aria-label`,
  e-mails (expéditeur « Boutique via {nom} », objet du lien de connexion, cadre), textes de
  l'annuaire. Une instance renommée (`isRenamed()`) affiche **toujours** « Propulsé par Panier
  Sauvage, logiciel libre (AGPL) » avec le lien `LEGAL_SOURCE_CODE_URL` (`instance/_powered_by`,
  gabarits client, producteur, connexion et e-mails) et `<meta name="application-name|generator">`.
- **Couleur** : `<html data-accent="…">` remplace les cinq jetons sauge (`app.css`), rien d'autre ;
  palette calculée pour les contrastes AA (`AccentPaletteTest` : formule WCAG, valeurs de
  `app.css` = `AccentColor::tokens()`). E-mails et `theme-color` suivent ; les PDF restent en sauge.
- **Territoire** (`App\Instance\Territory`, `INSTANCE_TERRITORY` : départements et/ou codes INSEE) :
  `DeliveryPoint::inseeCode` (`citycode` de la BAN, effacé quand l'adresse change). Un point est
  dedans si son code INSEE est listé ou appartient à un département listé ; sans code INSEE, par le
  département de son code postal (jamais pour une liste de communes). `contains()` et `pointSql()`
  testés à l'identique. Appliqué aux points candidats de `ShopRepository::findNearby()` (recherche,
  pages communes, découverte croisée), aux communes de l'annuaire et à la visibilité (sitemap).
  `INSTANCE_TERRITORY_CENTER` / `_RADIUS_KM` : l'annuaire s'ouvre sur la liste et la carte du
  territoire (non compté dans `search_stat`).
- **Tarification** (`App\Instance\SubscriptionPolicy`) : `standard` = §7 inchangé ; `sponsored` =
  abonnement offert par l'organisme porteur (obligatoire) : `allowsNewSales()` vrai pour toute
  boutique et `shopAllowsNewSalesSql()` = `TRUE` (cohérence testée dans les deux modes) ; utilisée
  par `SubscriptionGate` et la visibilité de l'annuaire. Pas d'essai ni de bandeau, page
  `pro_billing` « Abonnement offert par … », `pro_billing_checkout` / `_portal` redirigent sans
  appeler Stripe ; l'entrypoint n'exige plus les `STRIPE_*`.
- **Accueil** : texte territorial et organisme porteur (`instance/_home_welcome`), variante
  sponsorisée (promesse « Offert. », tarifs, FAQ, inscription). **Pages légales** : encadré
  « {nom} est une instance du logiciel libre Panier Sauvage, éditée par {LEGAL_PUBLISHER_NAME} et
  portée par {organisme} » (`instance/_legal_notice`), organisme porteur dans les mentions légales.

## 17. Lot E — palier solidaire

Décision du fondateur : **prix libre de 0 à 10 € par mois, 5 € suggérés**, sur **déclaration sur
l'honneur**, mêmes fonctions que l'offre normale.

- **Déclaration** (`pro_billing_solidarity`, formulaire `SolidarityDeclarationType` sur
  `pro_billing`) : au moins une situation cochée (`SolidarityCriterion` : installation depuis moins
  de 3 ans, moins de 300 € de précommandes retirées par mois, première saison, aide alimentaire),
  montant en euros entiers de 0 à 10 (Stripe ne prélève pas moins de 0,50 €), case « Je déclare sur
  l'honneur… ». Aucun justificatif, aucune vérification (seul le critère 2 pourrait être contrôlé
  après coup, par l'outil). Les critères et la date sont enregistrés (`solidarityCriteria`,
  `solidarityDeclaredAt`).
- **Plus de 0 €** : plan `solidarity`, montant en centimes (`amountCents`).
  `App\Billing\Service\SolidarityPlanManager` : sans abonnement Stripe en cours, Checkout en mode
  abonnement avec un tarif mensuel créé à la volée (`price_data` : `eur`, produit
  `STRIPE_PRODUCT_SOLIDARITY`, `unit_amount`, `recurring.interval = month`), métadonnées `shop_id`,
  `plan`, `amount_cents` ; avec un abonnement en cours (`active`/`past_due`), changement du tarif
  de son article dans Stripe (`proration_behavior: none`, à partir de la prochaine échéance). Le
  portail Stripe garde moyen de paiement, factures et résiliation (il ne sait pas changer un tarif
  créé à la volée). Le formulaire standard (`pro_billing_checkout`) refuse `plan=solidarity`.
- **0 €** : aucun Stripe. Un abonnement Stripe en cours est résilié tout de suite ;
  `ShopSubscription::activateFreeSolidarity()` : statut `active`, `amountCents = 0`, pas de
  `stripeSubscriptionId` (le client Stripe est gardé pour les anciennes factures),
  `currentPeriodEnd` = aujourd'hui + 12 mois. Une nouvelle déclaration repart pour 12 mois à partir
  du jour. `isFreeSolidarity()` = plan `solidarity` sans abonnement Stripe.
- **Règle** `allowsNewSales()` : `active` reste vrai, sauf palier gratuit dont `currentPeriodEnd` est
  passé (ou absent). Traduction SQL `ShopSubscriptionRepository::allowsNewSalesSql()` (plan ≠
  solidarity, ou abonnement Stripe, ou fin future), testée à l'identique (`ShopSubscriptionSqlTest`).
- **Webhooks** (idempotents comme les autres : `StripeEvent`, événements tardifs ignorés) : le plan
  vient du tarif (mensuel, annuel), puis de la métadonnée `plan` ; le montant d'un abonnement
  solidaire vient de `items.data[0].price.unit_amount`, sinon de la métadonnée `amount_cents`
  (Checkout : métadonnée). Retour à un tarif fixe : `amountCents` effacé. La suppression d'un ancien
  abonnement Stripe ne met pas fin au palier gratuit.
- **Rappel** : `SolidarityReminderTask` (tous les jours à 9 h 30, `App\Schedule`) →
  `SolidarityRenewalReminder` : palier gratuit qui finit dans moins de 30 jours, une seule fois par
  période (`solidarityReminderSentAt`, remis à zéro par une déclaration) → événement
  `Billing\Event\SolidarityPlanEnding` → e-mail `solidarity_renewal` aux producteurs de la boutique
  (`Notification\EventListener\BillingNotificationListener`). Bandeau : avertissement dans les 30
  jours, alerte une fois échu (« Renouveler ma déclaration »).
- **Affichage** : `pro_billing` (statut, montant, date de fin du palier gratuit, formulaire,
  dernière déclaration) et la ligne « Solidaire, prix libre » de l'ardoise des prix de l'accueil
  (`home/index.html.twig`, `[data-plan="solidarity"]` : prix, critères publics).

## 18. Lot E — export pour le comptable

En plus du journal (§15, `pro_accounting_csv` / `_pdf`), les **écritures comptables** du mois
(`App\Accounting\Service\AccountingEntriesBuilder`), même périmètre : commandes `delivered` des ventes
livrées dans le mois, montants réels (poids réel une fois saisi).

- **Une écriture par commande**, journal `VE` (réglable), datée du jour de livraison, pièce = référence
  de la commande : débit du compte de contrepartie (TTC), crédit du compte de ventes (HT, une ligne
  par taux), crédit du compte de TVA collectée (une ligne par taux non nul). Ventilation ligne à ligne
  par `VatCalculator` : chaque écriture est équilibrée au centime. Libellés en ASCII simple (espaces
  ordinaires) : « Client <nom> - cde <réf.> », « Ventes TVA 5,5 % - cde … », « TVA collectée … ».
- **Aucun paiement enregistré** (§1) : la contrepartie est un compte client générique (`411`) que le
  comptable lettre avec les encaissements, ou la caisse (`530`/`531`) au choix du producteur
  (`AccountingCounterpart`). L'écran le dit (`[data-no-payment]`).
- **Franchise en base** (`Order::isVatExempt()`) : aucune ligne `44571`, ventes au TTC, libellé
  « Ventes franchise TVA art. 293 B CGI ».
- **Comptes** par boutique (`AccountingSettings`, `pro_accounting_settings`) : défauts `706`, `44571`,
  `411`, `530`, `VE` ; 3 à 20 chiffres ou majuscules commençant par un chiffre, espaces retirés ; un
  champ vide reprend le défaut.
- **CSV** (`pro_accounting_entries_csv`) : `Date;Journal;Compte;Libellé;Débit;Crédit;Pièce`, `;`,
  UTF-8 avec BOM, virgule décimale, dates `JJ/MM/AAAA`, cellule vide quand le montant est nul,
  protection contre l'injection de formules (`CsvWriter`).
- **Format FEC** (`pro_accounting_fec`) : les 18 colonnes de l'article A47 A-1 du LPF (`JournalCode`…
  `Idevise`), noms en première ligne, tabulation, dates `AAAAMMJJ`, virgule décimale, UTF-8 (admis par
  le texte), fin de ligne CRLF, `EcritureNum` = `VE` + `AAAAMM` + rang. **Ce n'est pas le FEC fiscal** :
  celui-ci couvre tous les journaux de l'exercice et sort de la comptabilité tenue par le producteur
  ou son comptable, après validation. Ici, des écritures en brouillard à importer (`ValidDate`,
  `EcritureLet`, `DateLet`, devise vides ; pas de compte auxiliaire) ; nom de fichier sans le motif
  `SirenFECAAAAMMJJ` pour éviter la confusion. Source : article A47 A-1 du LPF (Légifrance).

## 19. Lot E — export Data Food Consortium

Catalogue de la boutique au format du standard **Data Food Consortium** (DFC), JSON-LD
(`App\Catalog\Dfc\DfcCatalogExporter`).

- **Version** : ontologie et contexte **1.16.0** (`https://w3id.org/dfc/ontology/context/context_1.16.0.json`,
  préfixes `dfc-b:`, `dfc-m:`, `dfc-f:`). La version 2.0.0 de l'ontologie (février 2026) change
  l'espace de noms (`w3id.org/dfc/ontology/v2.0.0/…`) et renomme `Enterprise` en `Organization` ;
  les connecteurs officiels (Ruby, utilisé par Open Food Network, et TypeScript, mis à jour en juin
  2026) émettent toujours le contexte 1.16.0 : c'est lui qui garantit l'interopérabilité aujourd'hui.
  Passer à 2.0 = changer `DfcVocabulary::CONTEXT` et les types quand les connecteurs l'auront fait.
- **Graphe** (`@graph`, identifiants = fragments de l'URL publique stable) : `dfc-b:Enterprise`
  (nom, présentation, page boutique, logo, adresse ; `maintains` le catalogue, `manages` les articles,
  `supplies` les produits), `dfc-b:Catalog`, pour chaque produit actif un `dfc-b:SuppliedProduct`
  (nom, description, photo, `hasQuantity` = 1 `dfc-m:Piece`/`Kilogram`/`Bunch`, `hasCertification`),
  un `dfc-b:CatalogItem` (`sku` = id) et un `dfc-b:Offer` au prix catalogue (`dfc-b:Price` : TTC en
  euros, `VATrate` en %, 0 en franchise, `dfc-m:Euro`) ; chaque vente ouverte ou programmée en
  `dfc-b:SaleSession` (`beginDate` = ouverture, `endDate` = date limite) qui `lists` ses propres
  offres (prix de la vente, `stockLimitation` = quantité restante si limitée) ; chaque point de
  retrait actif (rendez-vous, arrêt de tournée) en `dfc-b:PhysicalPlace` + `dfc-b:Address` (rue,
  code postal, ville, pays `…/authority/country/FRA`, coordonnées) + `dfc-b:PickupOption`. Montants
  en chaînes décimales calculées sur des entiers (jamais de flottant).
- **Labels** : correspondance vers la taxonomie des facettes (`DfcVocabulary::certification()`) : AB /
  Agriculture biologique → `Organic-AB`, Bio / Eurofeuille → `Organic-EU`, Bio Cohérence, Demeter,
  Nature & Progrès, Label Rouge (`RedLabel_FR`), AOP (`AOP_EU`), AOC (`AOC_FR`), IGP (`IGP_EU`). Les
  autres labels et les allergènes restent lisibles dans la description (le DFC structure les
  allergènes par dimension, que l'outil ne connaît pas).
- **Exclus** : zones de livraison à domicile, produits archivés, ventes closes ou passées, et tout ce
  qui touche aux clients et aux commandes.
- **Accès** : téléchargement depuis `/pro` (`pro_catalog_dfc_download`, page `pro_catalog_dfc`, lien
  « Partager mon catalogue » sur `pro_product_index`) ; **URL publique** `shop_dfc_catalog`
  (`/{slug}/catalogue.jsonld`) seulement si le producteur l'active (`CatalogPublication::dfcPublic`,
  `pro_catalog_dfc_publish`, CSRF), 404 sinon. `application/ld+json`, `Access-Control-Allow-Origin: *`,
  cache public 5 min.
- **Sources** : dépôt de l'ontologie `github.com/datafoodconsortium/ontology` (versions 1.16.0 et
  2.0.0, dossier `context/`), taxonomies `github.com/datafoodconsortium/taxonomies` (`measures`,
  `facets`), connecteurs `github.com/datafoodconsortium/connector-ruby` et `connector-typescript`
  (propriétés et contexte émis), `datafoodconsortium.org`.

## 20. Lot F — Feuille de livraison hors ligne

- **Sans JavaScript**, rien ne change : chaque case est un formulaire (`pro_order_mark`, `pro_order_weigh`).
- **Avec JavaScript** (`assets/controllers/delivery_sheet_controller.js`) : « livrée », « non livrée »,
  « remettre à livrer » et la pesée s'appliquent **tout de suite à l'écran** (la carte change d'onglet,
  compteurs, progression, reste du camion et montant suivent), puis partent dans une **file** gardée par
  le navigateur (`localStorage`, clé `panier-sauvage:feuille-de-livraison:<id producteur>`, mémoire si le
  stockage est bloqué), envoyée **dans l'ordre**, une action à la fois, à `pro_order_sheet_action`, dès que
  le réseau est là (au chargement, à l'évènement `online`, au retour sur l'onglet, puis toutes les 15 s
  après un échec). Un seul onglet envoie à la fois (`navigator.locks`).
- **Indicateur** (`role="status"`) dans l'en-tête : « Tout est enregistré », « Enregistrement… N actions en
  attente », « Hors ligne : N actions en attente », « Session expirée … » avec un lien pour se reconnecter
  (la file attend, rien n'est perdu).
- **Idempotence** : chaque action porte un UUID et l'état que le producteur voyait (`expected`,
  `expectedWeights`). `App\Ordering\Service\DeliverySheetSync` verrouille la commande (`FOR UPDATE`) :
  un UUID déjà traité (`DeliverySheetAction`) renvoie son issue enregistrée (`replayed: true`) sans rien
  changer, donc un rejeu ne crée jamais de second `OrderEvent`. Un UUID déjà servi pour une autre
  commande ou un autre type d'action est refusé (422).
- **Conflits** : action appliquée seulement si la commande est encore dans l'état vu (ou déjà dans l'état
  demandé : `unchanged`). Commande ou vente annulée, statut ou poids changé ailleurs, vente pas encore
  close : `conflict`, rien n'est enregistré, le message s'affiche dans un encadré « À vérifier » et la carte
  reprend l'état du serveur. Une action impossible (produit hors commande, poids hors bornes) : 422, elle
  est retirée de la file, signalée, et la page est rechargée. Les changements passent par
  `OrderDeliveryMarker` et `OrderWeighing`, comme depuis les formulaires.
- **CSRF** : même jeton sans état que les formulaires (`order-mark`), envoyé en double (en-tête
  `X-Csrf-Token` + en-tête et cookie du contrôleur `csrf-protection`), plus l'origine.
- **Hors ligne complet** : service worker `pro_delivery_sheet_worker` (portée `/pro/`, servi par Symfony :
  compatible AssetMapper et CSP `script-src 'self'`). Il ne répond que pour les feuilles
  (`/pro/ventes/<id>/livraison`, réseau d'abord, dernière copie si le réseau échoue ou dépasse 4 s) et les
  assets qu'elles chargent (`/assets/…`, versionnés) ; tout le reste va au réseau sans lui. Les copies
  contiennent des noms et téléphones : 10 feuilles au plus, 7 jours au plus, **effacées à la
  déconnexion** (le worker voit le `POST /pro/deconnexion`). Rouverte hors ligne, la feuille réapplique
  les actions en attente.
- **Roue de secours** : le PDF (`pro_sale_delivery_pdf`) reste proposé en bas de la feuille.

## 21. Lot F — Invendus et vente de dernière minute

- **Invendus** : sur `pro_sale_show` d'une vente `closed`, une carte liste le reste des offres limitées
  (« 6 sur 20 »).
- **Choix : réouverture contrôlée de la même vente**, pas une nouvelle vente liée. Raisons : une seule
  feuille de livraison, une seule fiche de production et les mêmes compteurs de stock pour la tournée ;
  pas de second code de vente à partager ; aucune survente entre deux ventes qui se partageraient le même
  pain. Le cycle de vie reste monotone : le statut reste `closed` (`Sale::isLastMinute()`,
  `isLastMinuteOpen(now)`), `SaleClosed` n'est pas renvoyé, `done` arrive le lendemain comme d'habitude.
- **Ouverture** (`pro_sale_last_minute`, `App\Sale\Service\LastMinuteSale`) : une seule fois par vente, si
  l'abonnement autorise les ventes (§7), au moins 30 min de commande, fin au plus tard **1 h avant la
  première livraison** (proposée : 2 h avant), mêmes points et créneaux. Le producteur indique pour chaque
  offre la quantité remise en vente (prérempli : le reste des offres limitées ; une offre sans limite peut
  recevoir un rab). Sous verrou (`FOR UPDATE` sur la vente et chaque offre), `maxQty = reservedQty +
  quantité` et `SaleOffer::lastMinute = true` ; ensuite chaque commande passe par `ReservationService`
  comme d'habitude (contrainte `reserved_qty <= max_qty` inchangée). « Arrêter maintenant »
  (`pro_sale_last_minute_end`) termine la fenêtre ; elle ne se rouvre pas.
- **Client** : la vente apparaît sur la page boutique et sur `sale_show` (« Dernière minute 🧺 », « commande
  jusqu'au … », `Sale::getOrderDeadline()`), avec **seulement les offres remises en vente**
  (`Sale::getOrderableOffers()`, vérifié aussi par `OrderManager`). Il commande comme d'habitude ; sa
  commande n'est pas modifiable en ligne (date limite passée). Un client qui a déjà une commande dans la
  vente est renvoyé vers elle (une commande par client et par vente) : il ne reçoit donc pas l'e-mail.
  Le producteur, lui, peut toujours ajouter n'importe quel produit (§4).
- **E-mail** (`LastMinuteSaleOpened`, type `last_minute_sale`) : abonnés `emailConsent` de la boutique,
  sauf ceux qui ont une commande active dans la vente, **une seule fois** (journal de notifications),
  désabonnement en un clic (`List-Unsubscribe`). Jamais aux clients sans e-mail.

## 22. Lot D — outils du producteur

### 22.1 Mes clients

- `pro_customer_index` liste **les clients de la boutique** : ceux qui y ont commandé, ses abonnés
  (`ShopFollower`) et ses clients sans e-mail (§14), personne d'autre (`App\Customer\Service\ShopCustomers`,
  une requête SQL). Par client : nom et téléphone de sa dernière commande ici (sinon son profil), e-mail
  (jamais celui d'un compte effacé, affiché « Client effacé »), abonnement et consentement aux annonces,
  date d'abonnement, commandes non annulées, date de livraison de la dernière, total retiré (`delivered`,
  au poids réel), absences (`not_delivered`). Tous les chiffres ne portent que sur les commandes de cette
  boutique : un producteur ne voit jamais ce qu'un client commande ailleurs.
- Recherche (`?q=` : nom dans les deux ordres, e-mail, ou au moins 3 chiffres du téléphone) et tri
  (`?tri=` : `nom`, `derniere-commande`, `commandes`, `total`, `absences`, `abonnement`, `App\Customer\Enum\CustomerSort`).
- `pro_customer_show` : coordonnées, abonnement, chiffres et historique des commandes **de cette boutique** ;
  pour un client sans e-mail, les actions existantes (`pro_offline_customer_edit`, effacement). Un client qui
  n'est pas celui de la boutique : 404.
- `pro_customer_csv` : même liste (`CustomerCsvExporter`, `CsvWriter` : cellules protégées contre l'injection
  de formules), téléchargement privé sans cache.

### 22.2 Historique des absences

Badge discret « 2 absences » à côté du nom du client sur la page de vente, la feuille de livraison et
l'édition d'une commande (partiel `customer/pro/_absence_badge.html.twig`, fonction Twig
`recent_absences(order)`, `App\Customer\Service\AbsenceHistory`) : commandes `not_delivered` **de cette
boutique** dont la livraison date de moins de 180 jours, la vente affichée exclue. Une requête par boutique
et par page. Rien n'est montré au client.

### 22.3 Protection des nouveaux clients

Deux réglages de boutique, désactivés par défaut (section « Nouveaux clients » de `pro_shop_settings`).
Un client est **nouveau** tant qu'aucune de ses commandes chez ce producteur n'a été retirée
(`App\Ordering\Service\NewCustomerPolicy`).

- **Plafond d'une première commande** (`Shop::firstOrderCapCents`, 5 à 1 000 €) : `OrderManager` refuse
  (`FirstOrderCapExceededException`) la commande d'un client (acteur `customer`, création ou hausse d'une
  commande ; baisser reste toujours possible) au-delà du plafond. Message poli, panier conservé, téléphone du
  producteur (sinon son e-mail). Un invité est toujours traité comme nouveau (taper une adresse ne prouve rien,
  et la réponse ne révèle pas si cette adresse est déjà cliente) : se connecter lève le plafond d'un habitué.
  Les commandes saisies par le producteur ne sont jamais plafonnées. Le plafond est annoncé sur la page de vente.
- **Acompte hors plateforme** (`Shop::newCustomerDeposit`, texte libre, ex. « 5 € par Wero à la commande ») :
  affiché aux nouveaux clients sur la page de vente, la confirmation, le récapitulatif de la commande et
  l'e-mail `order_placed` (commande passée par le client). **Rien n'est enregistré** (§1) : c'est un texte.

### 22.4 Statistiques

`pro_stats` (`App\Shop\Statistics\ShopStatistics`, SQL sur les tables de la boutique, rendu serveur) :
commandes retirées par semaine (indicateur principal, 12 semaines, par date de livraison), chiffre
d'affaires retiré par mois (12 mois, montant des commandes `delivered` au poids réel), et sur les
182 derniers jours de livraison : commandes médianes par vente terminée, remplissage des offres limitées
(quantité réservée / maximum, ventes closes ou terminées, par produit), clients réguliers (au moins
2 commandes non annulées livrées dans les 60 jours), taux d'absence (`not_delivered` / retirées + non
retirées) et d'annulation des ventes terminées, provenance (`Order::source`, « saisies par toi » quand
l'événement `placed` vient du producteur, sinon inconnue). Pourcentages entiers arrondis sans flottant
(`Rate`). Graphiques en SVG inline (attributs seulement, ni script ni style), doublés d'un tableau des
chiffres.

### 22.5 Plusieurs utilisateurs par boutique

- Un compte producteur sert **une seule boutique** ; une boutique a un **propriétaire** (`ShopRole::Owner`,
  celui qui l'a créée) et des **membres** (`ShopRole::Member`). `User::getRoles()` ajoute
  `ROLE_SHOP_OWNER` au propriétaire. Seul le propriétaire gère l'abonnement (`BillingController` :
  `#[IsGranted(User::ROLE_SHOP_OWNER)]`, entrée « Abonnement » masquée aux membres, bandeaux sans lien) et
  l'équipe (inviter, annuler une invitation, retirer un membre) ; la suppression d'un compte producteur
  n'existe pas encore et lui sera réservée. Les membres font tout le reste.
- `pro_team` liste les comptes (propriétaire d'abord). Invitation (`App\Shop\Team\TeamManager`) : jeton
  aléatoire dont seul le hash est stocké, valable 7 jours, à usage unique, une nouvelle invitation à la même
  adresse remplace l'ancienne, 10 invitations en attente au plus ; e-mail direct (comme la réinitialisation du
  mot de passe) sur le gabarit de notification, réponse au propriétaire. Le lien passe le jeton en session puis
  `pro_team_invitation_accept` : l'invité choisit nom, prénom et mot de passe et accepte les CGU, il est
  connecté. Si l'adresse a déjà un compte producteur : page qui explique « un compte = une boutique » (409).
  Retirer un membre supprime son compte (sa session tombe) ; le propriétaire ne peut pas être retiré.
- « Le » producteur d'une boutique est son propriétaire (`Shop::getOwner()`, sinon le compte le plus ancien) :
  identité du vendeur, e-mail public (`Shop::publicEmail()` = e-mail de contact, sinon celui du propriétaire),
  réponse aux e-mails clients, contact de la page de commande. L'e-mail de clôture de vente part à chaque compte.
  Paiement Stripe : e-mail du propriétaire (seul à pouvoir souscrire).

### 22.6 Découverte croisée désactivable

`Shop::crossDiscoveryEnabled` (vrai par défaut, case de la section « Annuaire » de `pro_shop_settings`) :
faux, `CrossDiscovery::around()` ne renvoie rien pour les points de cette boutique (page boutique,
confirmation, e-mail `order_placed`). La boutique reste suggérée chez les autres si elle est dans l'annuaire.

## 23. Lot H — Paniers récurrents (commande récurrente, contrat de saison)

Un client s'abonne à une **commande récurrente chez un seul producteur**, rattachée à une récurrence de
vente (`SaleSchedule`). Jamais de panier multi-producteurs, **jamais de paiement enregistré** (§1) :
chaque livraison se règle au retrait ; le total d'un contrat de saison n'est affiché qu'à titre indicatif.
Domaine `App\Recurring`.

- **Offre du producteur** (`pro_recurring_plan_edit`, une par récurrence) : `basket` (panier composé :
  quantité de chaque produit dans un panier, l'abonné choisit 1 à `maxShares` paniers) ou `choice`
  (produits au choix : quantité maximale par livraison, l'abonné choisit sa quantité dans le pas de vente,
  §13). Produits de la récurrence (toute la gamme si elle n'en coche aucun). `capacity` = places (les
  abonnements suspendus gardent la leur). Conditions affichées : engagement (`open_ended` avec
  `noticeDays` de préavis, ou `season` du `seasonStartsOn` au `seasonEndsOn`), absences (`maxPauses`,
  vide = illimité), texte libre. Fermer les inscriptions (`enabled`) n'arrête pas les abonnements en cours.
  Inscriptions possibles si la récurrence est active, la saison pas finie et l'abonnement SaaS autorise
  les ventes (`SubscriptionGate`, §7), via `RecurringPlanCatalog`.
- **Côté client** : page boutique (`recurring/_shop_plans.html.twig`) et page de vente
  (`recurring/_sale_plan.html.twig`) invitent à `recurring_plan_show` ; un visiteur passe par le lien
  magique (`recurring_plan_login`, protégé, qui revient sur la page). Formulaire : point de retrait (ceux de
  la récurrence), adresse si domicile, paniers ou quantités, case « J'ai lu les conditions » (« je
  m'engage pour toute la durée » pour un contrat), case 18 ans si un produit est alcoolisé (§12, date
  gardée dans `adultCertifiedAt`), CGU si la version courante n'est pas acceptée. Verrou `FOR UPDATE`
  sur le plan (dernière place), index unique partiel (un abonnement en cours par client et plan).
  « Mon compte » liste les abonnements (`recurring/_account_section.html.twig`) ; `customer_recurring_show`
  montre les 6 prochaines livraisons (`RecurringCalendar`), les changements pour les suivantes, l'historique.
- **Pause** d'une livraison : jusqu'à la date limite de modification de sa vente (`editCutoffAt`, sinon
  `deadlineAt`, calculée depuis la récurrence tant que la vente n'existe pas), dans la limite de
  `maxPauses`. Déjà générée, sa commande est annulée par `OrderManager` (acteur client : la boutique doit
  autoriser la modification client). Une livraison en pause se reprend tant qu'elle n'a pas été générée ;
  après, le client commande sur la page de la vente. Les changements de quantités valent pour les
  commandes générées ensuite ; une commande déjà créée se modifie sur sa page.
- **Résiliation** : sans engagement, immédiate (`noticeDays` = 0, plus rien n'est généré) ou avec préavis
  (`endsOn` = aujourd'hui + préavis : les livraisons jusque-là restent dues). Un contrat de saison engage
  jusqu'à son terme (résiliation en ligne refusée : « parles-en au producteur »). Le producteur suspend,
  reprend ou termine un abonnement (`pro_recurring_order_*`) ; les commandes déjà générées restent dans ses
  ventes. `RecurringOrderManager::endDue()` termine les abonnements dont le dernier jour est passé.
- **Génération** (`RecurringOrderGenerator`) : quand une vente de la récurrence **ouvre**
  (`GenerateOnSaleOpenedListener`, priorité 10 : avant l'annonce aux abonnés de la boutique, les abonnés
  sont servis en premier), dès l'abonnement pour les ventes déjà ouvertes, et en rattrapage toutes les
  15 minutes (`RecurringOrderTask`, `app:recurring-orders:generate`). Pour chaque abonnement actif couvrant
  la date de livraison : une transaction, verrou `FOR UPDATE` sur l'abonnement, puis une
  `RecurringOccurrence` (contrainte unique (abonnement, vente) : **idempotent**, même en parallèle).
  Commande passée par `OrderManager::placeOrder()` (acteur `system`, provenance `subscription`), stock par
  `ReservationService` : jamais de survente. **Règle de stock** : un produit qui manque est réduit à ce
  qu'il reste (dans son pas et son minimum, `StockShortage::orderable()`), sinon retiré ; un produit que la
  vente ne propose pas est retiré ; commande créée avec le reste (`partial`) ou aucune si rien ne reste
  (`out_of_stock`). Point de retrait non desservi : `unavailable`. Livraison en pause : `paused`. Client
  ayant déjà commandé à la main dans la vente : sa commande est gardée (`already_ordered`). L'abonné reçoit
  l'e-mail `recurring_order_generated` (jamais `order_placed`), le producteur un récapitulatif
  `recurring_shortages`. **Robuste** : une erreur sur un abonné est journalisée, rien n'est enregistré
  pour lui (le rattrapage réessaie), les suivants sont servis ; le gestionnaire d'entités n'est jamais vidé
  (la génération tourne aussi pendant l'ouverture d'une vente).
- **Client sans e-mail : pas d'abonnement** (décision lot H). L'abonnement est un engagement du client
  lui-même (CGU, majorité, conditions) qu'il gère seul (pause, quantités, résiliation) et chaque commande
  générée, surtout incomplète, doit lui être annoncée : sans e-mail ni compte, rien de cela n'est possible
  et le producteur découvrirait les ruptures au retrait. Le producteur continue de prendre ses commandes
  vente par vente (`pro_sale_order_new`, §14). Un producteur n'abonne pas non plus un client à sa place.
- **RGPD** : l'effacement d'un compte (`CustomerErased`) supprime ses abonnements (adresse, historique).

