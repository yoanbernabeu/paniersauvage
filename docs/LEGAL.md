# Textes légaux : état et points à valider

**Les textes publiés (`templates/legal/pages/`) n'ont été relus par aucun juriste.** Ils sont
rédigés de bonne foi à partir du Code de la consommation, de la LCEN, du RGPD et des
recommandations de la CNIL, pour un service qui n'encaisse rien et ne vend rien. Ils doivent être
validés par un avocat ou un juriste (droit de la consommation et données personnelles) **avant le
premier producteur réel**. Ce fichier n'est pas affiché sur le site.

| Page | Route | Gabarit | Version |
| --- | --- | --- | --- |
| Mentions légales | `legal_notice` (`/mentions-legales`) | `notice.html.twig` | `LegalDocuments::NOTICE_VERSION` |
| CGU (producteurs, clients, annexe 1 sous-traitance, annexe 2 rétractation) | `legal_terms` (`/cgu`) | `terms.html.twig` | `LegalDocuments::TERMS_VERSION` |
| Confidentialité | `legal_privacy` (`/confidentialite`) | `privacy.html.twig` | `LegalDocuments::PRIVACY_VERSION` |
| Engagements publics | `legal_commitments` (`/engagements`) | `commitments.html.twig` | `LegalDocuments::COMMITMENTS_VERSION` |

## Modifier un texte

1. Modifier le gabarit, puis la version **et** la date dans `src/Legal/LegalDocuments.php`.
2. Pour les CGU, changer `TERMS_VERSION` fait réaccepter tous les producteurs (page
   `/pro/conditions` avant tout `/pro`) et redemande la case aux clients à leur commande suivante.
   Une simple correction de forme peut garder la version.
3. Annoncer par e-mail aux producteurs tout changement important (prix : 30 jours avant ;
   sous-traitants ultérieurs : 30 jours avant, comme le prévoit l'annexe 1). Cet envoi n'est pas
   automatisé.

## Points à faire trancher

- **Rétractation et denrées** : l'exclusion de l'article L221-28 4° vise les biens « susceptibles de
  se détériorer ou de se périmer rapidement ». Vin, miel, huile, conserves, farines, tisanes ne le
  sont pas : la case « Denrée périssable », cochée par défaut, repose sur la déclaration du
  producteur. À valider : le cas des produits descellés (L221-28 5°), des colis de viande, des paniers
  composés, et des produits confectionnés sur demande (L221-28 3°).
- **Tournées à domicile** : l'article L221-2 4° exclut du régime de la vente à distance la
  fourniture de denrées « livrées physiquement par un professionnel lors de tournées fréquentes et
  régulières » au domicile ou au travail du consommateur. Les arrêts de tournée et la livraison à
  domicile d'un producteur pourraient en relever (pas de droit de rétractation, information allégée).
  Le site applique par prudence le régime complet partout.
- **Précommande payée au retrait** : la vente est conclue à distance mais exécutée et payée en
  présence des parties. Confirmer que le régime de la vente à distance s'applique bien (information
  précontractuelle, confirmation sur support durable) et le point de départ du délai de 14 jours
  (le retrait).
- **Identité du vendeur** : le site affiche le nom de la boutique, le nom du producteur, le SIRET
  s'il est renseigné, l'adresse, le téléphone et l'e-mail du compte producteur. Faut-il rendre le
  SIRET et l'adresse obligatoires pour ouvrir une vente ? Faut-il un champ « médiateur de la
  consommation » par boutique (obligation L612-1 du vendeur) et une mention de la garantie légale de
  conformité sur la page de vente ?
- **Médiation pour la plateforme** : l'accès client est gratuit et Panier Sauvage ne vend rien aux
  consommateurs. Vérifier qu'aucun médiateur n'est requis pour la plateforme elle-même.
- **Alcool** : la case « J'ai 18 ans » et le message sanitaire sont des mesures minimales (guide UGVC
  2021). Le contrôle réel est fait au retrait par le producteur. À valider : licence de vente à
  distance ou statut de récoltant exigé du producteur, message sanitaire (art. L3323-4 CSP) sur toute
  publicité, y compris l'annonce par e-mail d'une vente qui contient de l'alcool (non ajouté).
  La certification n'est pas conservée en base (seulement validée) : faut-il la tracer par commande ?
- **Rôles RGPD** : producteur responsable de traitement des données de ses clients, Panier Sauvage
  sous-traitant pour ces traitements et responsable pour les comptes de la plateforme. Le compte
  client unique (plusieurs producteurs) et l'annuaire rapprochent la plateforme d'un responsable
  conjoint : à confirmer, ainsi que la base légale de chaque traitement et les durées.
- **Durées de conservation** : aucune purge automatique n'existe. La CNIL recommande de supprimer
  les comptes clients inactifs depuis 3 ans et de limiter les journaux techniques à 6-12 mois. La
  politique annonce « tant que le compte existe » et « au plus 12 mois » pour les journaux : à mettre
  en œuvre (commande planifiée) ou à corriger.
- **Acceptation par un invité** : un invité qui commande avec l'adresse d'un compte existant coche
  les CGU, mais l'acceptation n'est enregistrée que sur un compte créé par cette commande (on ne peut
  pas engager le titulaire d'une adresse). Faut-il tracer l'acceptation sur la commande ?
- **Fonds de carte OpenStreetMap** : le navigateur transmet l'IP du visiteur à la fondation OSM
  (Royaume-Uni). Mentionné dans la politique ; un fournisseur de tuiles européen ou auto-hébergé
  supprimerait ce transfert.
- **SIRET non diffusibles** : un entrepreneur individuel peut s'opposer à la diffusion de ses données
  Sirene. L'API renvoie alors un nom masqué : la vérification passe, mais le nom stocké est peu
  utile. Afficher le SIRET d'un tel producteur sur sa page doit rester son choix (il le saisit).
- **DSA** : Panier Sauvage héberge des contenus de tiers (boutiques). Le signalement et le retrait
  prompt sont en place ; l'exemption des micro et petites entreprises (art. 19 et 29) est à confirmer,
  ainsi que l'obligation de motiver les décisions envers le producteur concerné (art. 17).
- **Prix TTC de l'abonnement** : les CGU renvoient au prix affiché ; préciser TTC ou « TVA non
  applicable, art. 293 B du CGI » selon le régime de l'éditeur.
