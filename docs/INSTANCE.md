# Déployer une instance territoriale

Panier Sauvage est un logiciel libre (AGPL-3.0). Une collectivité, un projet alimentaire
territorial (PAT) ou un réseau peut faire tourner **sa propre instance** : le même code, déployé à
part, avec son nom (par exemple « Panier Toqué du local »), son logo, sa couleur, son territoire, et
s'il le souhaite l'abonnement de ses producteurs pris en charge.

Ce qu'une instance territoriale **n'est pas** :

- ni un espace dans la base de Panier Sauvage : c'est un **déploiement séparé** (son serveur, sa
  base, ses e-mails, ses comptes). Rien n'est partagé avec panier-sauvage, ni données, ni comptes ;
- ni une place de marché : les règles du produit restent les mêmes (pas de panier multi-producteurs,
  pas de commission, pas de paiement en ligne des commandes, aucun paiement enregistré, annuaire
  classé par distance seulement) ;
- ni une gouvernance partagée du projet : le fondateur maintient le logiciel seul. L'instance
  appartient à qui la déploie, qui en est l'éditeur et le responsable.

## 1. Prérequis

- Un VPS (2 Go de RAM suffisent pour un territoire), un nom de domaine, Docker : tout est décrit
  dans [`DEPLOY.md`](DEPLOY.md), qui s'applique à l'identique.
- Un **éditeur** identifié (la collectivité, une association, une entreprise prestataire) : il
  apparaît dans les mentions légales (`LEGAL_*`) et devient responsable du traitement des comptes,
  sous-traitant des producteurs pour leurs clients (voir `/confidentialite`).
- Un compte d'envoi d'e-mails (Brevo ou autre SMTP) sur un domaine authentifié.
- Un **dépôt public** du code que l'instance fait tourner (voir § 6) : `LEGAL_SOURCE_CODE_URL`.
- Stripe seulement si les producteurs paient eux-mêmes leur abonnement (mode `standard`).
- Les textes légaux sont des modèles : à faire relire par le juriste de l'éditeur
  ([`LEGAL.md`](LEGAL.md)).

## 2. Les variables

Toutes sont facultatives. Sans elles, l'instance est Panier Sauvage à l'identique. Elles se placent
dans `.env.prod` (modèle commenté dans `.env.prod.example`).

| Variable | Exemple | Effet |
| --- | --- | --- |
| `INSTANCE_NAME` | `Panier Toqué du local` | Nom affiché partout : en-tête, `<title>`, métadonnées, pied de page, e-mails (expéditeur « Boutique via … », objet du lien de connexion). 60 caractères au plus. |
| `INSTANCE_LOGO` | `images/instance/logo.svg` | Logo à la place du panier, fichier `.svg`, `.png` ou `.webp` **dans `public/`** (ajouté à l'image ou monté en volume). Hauteur affichée 28 à 44 px. |
| `INSTANCE_ACCENT` | `riviere` | Couleur d'accent : `sauge` (défaut), `riviere` (bleu), `terre` (brique), `prune` (violet), `ardoise` (gris bleu). |
| `INSTANCE_TERRITORY` | `26` ou `26362 26281` | Départements et/ou codes INSEE de communes (virgules, points-virgules ou espaces). |
| `INSTANCE_TERRITORY_NAME` | `Valence Romans Agglo` | Nom du territoire dans les pages. |
| `INSTANCE_TERRITORY_CENTER` | `44.9334,4.8924` | Centre de la carte de l'annuaire (latitude, longitude). |
| `INSTANCE_TERRITORY_RADIUS_KM` | `30` | Rayon autour du centre : 10, 30 ou 50 km. |
| `INSTANCE_WELCOME_TEXT` | `Les producteurs de l'agglo…` | Texte d'accueil du territoire sur la page d'accueil (texte brut, retours à la ligne gardés). |
| `INSTANCE_CARRIER_NAME` | `Valence Romans Agglo` | Organisme porteur : accueil, pied de page, pages légales, e-mails. |
| `INSTANCE_CARRIER_URL` | `https://www.valenceromansagglo.fr` | Lien vers l'organisme porteur. |
| `INSTANCE_PRICING` | `standard` ou `sponsored` | Qui paie l'abonnement des producteurs (§ 4). |

Une valeur inutilisable (couleur libre, code inconnu, logo absent, rayon hors liste…) **empêche le
démarrage** : l'entrypoint lance `php bin/console app:instance:check`, qui nomme la variable fautive.
La même commande affiche la configuration en cours :

```bash
dc exec app php bin/console app:instance:check
```

### Le nom et la mention « propulsé par »

Dès que `INSTANCE_NAME` n'est plus « Panier Sauvage », chaque page (pied des gabarits client,
producteur et connexion) et chaque e-mail portent **« Propulsé par Panier Sauvage, logiciel libre
(AGPL) »**, avec un lien vers `LEGAL_SOURCE_CODE_URL`. Cette mention n'est pas réglable : elle
indique aux utilisateurs où trouver le code qu'ils utilisent (AGPL, article 13). Les pages légales
commencent par un encadré : « {nom} est une instance du logiciel libre Panier Sauvage, éditée par
{éditeur} et portée par {organisme} ; dans ces textes, « Panier Sauvage » désigne ce service ».

### La couleur

La couleur d'accent remplace les cinq tons sauge de la charte (`sage`, `sage-dark`, `sage-deep`,
`sage-soft`, `sage-mist`) par l'attribut `data-accent` de `<html>` : pas de CSS en ligne, pas de
couleur libre. Le jaune blé du bouton principal, le crème, l'encre et les bordures ne changent pas.
Chaque teinte a été calculée pour garder les contrastes AA (texte 4,5:1 sur blanc, crème et fonds
doux ; texte blanc sur l'en-tête producteur ; blé sur les aplats foncés ; bordures et anneau de
focus 3:1) : `tests/Instance/AccentPaletteTest.php` le vérifie. Les PDF (comptabilité, production)
restent en sauge.

## 3. Le territoire

`INSTANCE_TERRITORY` restreint **l'annuaire** (recherche, pages par commune, découverte croisée
« d'autres producteurs près de ce point », `sitemap.xml`) aux points de retrait et zones de
livraison situés sur le territoire. Une boutique qui a des points dedans et dehors apparaît par ses
points du territoire seulement. Sa page reste accessible par son lien dans tous les cas : le
territoire ne limite pas qui peut s'inscrire, seulement ce que l'annuaire montre.

- **Département** : `26`, `07`, `2A`, `2B`, `974`… Un point est dans le département de son code
  INSEE, ou à défaut de son code postal.
- **Commune** : son **code INSEE** (pas le code postal) : `26362` pour Valence, `26281` pour
  Romans-sur-Isère. La liste des communes d'une intercommunalité est publiée par l'INSEE (« Composition
  communale des EPCI ») ou sur la page de l'EPCI de `geo.api.gouv.fr` :
  `https://geo.api.gouv.fr/epcis/<siren>/communes?fields=code`.
- Le code INSEE d'un point vient du géocodage (API Adresse, champ `citycode`), fait à chaque
  enregistrement. Un point pas encore géocodé n'a que son code postal : il compte pour un
  département, jamais pour une liste de communes. Pour (re)géocoder tous les points :
  `dc exec app php bin/console app:delivery-points:geocode --all`.

Avec `INSTANCE_TERRITORY_CENTER`, la page `/trouver-un-producteur` s'ouvre directement sur la liste
et la carte des producteurs autour de ce point (rayon `INSTANCE_TERRITORY_RADIUS_KM`), avant toute
recherche ; cette ouverture n'est pas comptée dans les statistiques de recherche.

Exemple, Valence Romans Agglo (extrait, à compléter avec les 54 communes) :

```dotenv
INSTANCE_TERRITORY="26362 26281 26057 26058 26252 26037 26124 26064 26197 26004 26313 26084"
INSTANCE_TERRITORY_NAME="Valence Romans Agglo"
INSTANCE_TERRITORY_CENTER="44.9334,4.8924"
INSTANCE_TERRITORY_RADIUS_KM=30
```

Le jeu de démonstration (`doctrine:fixtures:load`) porte ces codes INSEE : de quoi essayer en local
(Montélimar, Livron, Tain et Nyons sortent de l'annuaire).

## 4. Prise en charge de l'abonnement

- **`INSTANCE_PRICING=standard`** (défaut) : comme sur Panier Sauvage, 30 jours d'essai puis
  abonnement payé par chaque producteur via Stripe (à configurer, voir `DEPLOY.md`). Les prix
  affichés sont ceux de Panier Sauvage (10 € par mois, 100 € par an) ; une instance qui veut
  d'autres prix modifie le code (et le publie, § 6).
- **`INSTANCE_PRICING=sponsored`** : l'organisme porteur paie pour tous les producteurs de
  l'instance. `INSTANCE_CARRIER_NAME` est alors obligatoire. Il n'y a ni essai, ni formule, ni
  Stripe (les variables `STRIPE_*` peuvent rester vides) ; la page Abonnement, l'accueil,
  l'inscription et les pages légales disent « Abonnement offert par {organisme} ». Toute boutique
  peut créer et ouvrir des ventes, et apparaître dans l'annuaire si elle l'a choisi (règle
  `App\Instance\SubscriptionPolicy`, la même en PHP et en SQL).

Rien ne change pour les clients : ils ne paient jamais l'outil et règlent leurs commandes
directement au producteur.

## 5. Mettre à jour le code

L'instance suit les versions de Panier Sauvage comme n'importe quel déploiement (`DEPLOY.md`, § 8) :

1. garder un fork du dépôt, avec ses éventuelles modifications sur une branche ;
2. récupérer les nouveautés : `git fetch upstream && git merge upstream/main` ;
3. relancer les tests (`composer ci`), construire l'image, déployer : les migrations passent au
   démarrage ;
4. relire `.env.prod.example` : une nouvelle variable y est toujours documentée.

Les réglages d'instance passent par des variables d'environnement justement pour que le fork reste
sans modification : c'est le plus simple à maintenir.

## 6. Obligations de l'AGPL

La licence [AGPL-3.0](../LICENSE) permet d'utiliser, modifier et redistribuer le logiciel, à une
condition qui compte pour un service en ligne :

- **publier le code source exact que l'instance fait tourner**, modifications comprises, sous la
  même licence, et l'offrir à ses utilisateurs : c'est le rôle de `LEGAL_SOURCE_CODE_URL` (dépôt
  public : forge de la collectivité, GitLab, GitHub…) et de la mention « Propulsé par Panier
  Sauvage » qui y mène ;
- garder les mentions de licence et de droit d'auteur du code ;
- ne pas retirer la mention « Propulsé par Panier Sauvage » ni le lien vers le code.

Le nom et le logo de l'instance appartiennent à celui qui la déploie. Le nom « Panier Sauvage »
désigne le logiciel : une instance ne se présente pas comme le service panier-sauvage lui-même.

## 7. Ce que le fondateur peut proposer

Le fondateur reste seul et ne crée pas de structure commune avec les instances. Il peut proposer,
sur devis et sans exclusivité :

- l'**installation** de l'instance (serveur, domaine, e-mails, variables, territoire) ;
- la **maintenance** : mises à jour de sécurité et de version, sauvegardes vérifiées, supervision ;
- de l'**accompagnement** des producteurs du territoire au démarrage ;
- le développement d'une fonctionnalité utile à tous, versée dans le code commun (et donc
  disponible pour toutes les instances).

Il ne propose pas d'héberger plusieurs territoires dans une même base, ni de modifications fermées.
Contact : l'adresse des mentions légales de panier-sauvage.
