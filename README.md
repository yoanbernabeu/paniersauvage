# Panier Sauvage

Panier Sauvage est un SaaS qui permet à un producteur (boulanger, maraîcher, fromager…) de prendre
ses précommandes en ligne plutôt que sur WhatsApp, puis de les livrer. Le producteur ouvre une
vente avec une date limite, ses clients commandent sans créer de mot de passe, et il retrouve la
liste de ce qu'il doit préparer et distribuer. L'outil n'enregistre aucun paiement : il affiche
seulement le montant à régler et les moyens de paiement acceptés.

La spécification complète est dans [`docs/SPEC.md`](docs/SPEC.md), la mise en production dans
[`docs/DEPLOY.md`](docs/DEPLOY.md), le positionnement et les engagements publics dans [`docs/produit/`](docs/produit/README.md).
Une collectivité ou un projet alimentaire territorial peut déployer sa propre instance, à ses
couleurs et sur son territoire : [`docs/INSTANCE.md`](docs/INSTANCE.md).

## Prérequis

- PHP 8.4 ou plus récent, avec les extensions `ctype`, `iconv`, `intl`, `pdo_pgsql`, `gd`, `zip`
- [Composer 2](https://getcomposer.org/)
- [Symfony CLI](https://symfony.com/download) (serveur local)
- Docker et Docker Compose (PostgreSQL 18 et Mailpit)

Node.js n'est pas nécessaire : les fichiers JavaScript et CSS passent par AssetMapper et le binaire
autonome de Tailwind.

## Installation locale

```bash
# 1. Récupérer le code et les dépendances PHP
git clone https://github.com/yoanbernabeu/paniersauvage.git
cd paniersauvage
composer install
# APP_SECRET n'est pas versionné : génère le tien une fois, dans .env.local
grep -q '^APP_SECRET=.' .env.local 2>/dev/null || echo "APP_SECRET=$(openssl rand -hex 32)" >> .env.local

# 2. Démarrer PostgreSQL (port 54329) et Mailpit (http://localhost:8025)
docker compose up -d --wait

# 3. Créer le schéma de la base de développement
php bin/console doctrine:migrations:migrate -n

# 4. Créer la base de test
php bin/console doctrine:database:create --if-not-exists --env=test
php bin/console doctrine:schema:update --force --env=test

# 5. Installer les dépendances JavaScript/CSS, PUIS compiler Tailwind
#    (l'ordre compte : la feuille de style importe des fichiers de assets/vendor/)
php bin/console importmap:install
php bin/console tailwind:build

# 6. Charger le jeu de démonstration (boutique « Fournil du Pont »)
php bin/console doctrine:fixtures:load -n

# 7. Lancer le serveur
symfony serve -d
```

L'application répond sur l'adresse affichée par `symfony serve` (en général
<https://127.0.0.1:8000>).

| Élément | Valeur |
| --- | --- |
| Espace producteur | `/pro/connexion` |
| Compte de démonstration | `boulanger@example.test` / `password` |
| E-mails envoyés en local | <http://localhost:8025> (Mailpit) |

Pendant le développement, garde Tailwind en surveillance dans un second terminal :

```bash
php bin/console tailwind:build --watch
```

Chaque évolution du modèle ajoute une migration Doctrine (procédure dans `AGENTS.md`, section
« Migrations »). La base de test, elle, est simplement synchronisée avec
`doctrine:schema:update --force --env=test`.

Pour personnaliser un réglage en local (autre port de base, clés Stripe de test…), crée un fichier
`.env.local` : il n'est pas versionné et a priorité sur `.env`.

## Commandes de qualité

```bash
vendor/bin/phpunit                                  # tests (ou un dossier : vendor/bin/phpunit tests/Catalog)
php bin/console about                               # à lancer une fois avant PHPStan (il lit le conteneur de dev)
vendor/bin/phpstan analyse                          # analyse statique, niveau max
vendor/bin/php-cs-fixer fix                         # style de code @Symfony
vendor/bin/rector process --dry-run                 # propositions de refactoring, sans rien modifier
php bin/console lint:container
php bin/console lint:twig templates
php bin/console lint:yaml config
php bin/console doctrine:schema:validate --skip-sync
```

L'intégration continue (`.github/workflows/ci.yml`) lance ces mêmes contrôles à chaque push et à
chaque pull request, avec PHP 8.4 et PostgreSQL 18.

## Structure du projet

```text
assets/            JavaScript (Stimulus), styles Tailwind, polices, icônes, images
config/            configuration Symfony
docker/            Caddyfile, php.ini, script d'entrée et script de sauvegarde (production)
docs/              SPEC.md (spécification), DEPLOY.md (mise en production), INSTANCE.md (instance territoriale)
migrations/        migrations Doctrine
public/            racine web (index.php, fichiers compilés, uploads)
src/               code applicatif, un dossier par domaine :
  Shop/            boutique du producteur, compte, boutique courante
  Catalog/         produits
  Sale/            ventes, offres, récurrence
  Delivery/        points et créneaux de livraison
  Ordering/        commandes, réservation de stock
  Customer/        clients, connexion par lien magique
  Billing/         abonnement Stripe
  Notification/    e-mails
  Accounting/      exports comptables
  Shared/          formatage, navigation, extensions Twig
templates/         gabarits Twig (un dossier par domaine, components/ pour le kit d'interface)
tests/             tests PHPUnit (un dossier par domaine) et factories Foundry
translations/      traductions
Dockerfile         image de production (FrankenPHP, PHP 8.5, mode worker)
compose.yaml       services de développement (PostgreSQL, Mailpit via compose.override.yaml)
compose.prod.yaml  pile de production pour un VPS
```

## Stack

PHP 8.4+ (8.5 en production), Symfony 8.1, Doctrine ORM et PostgreSQL 18, Twig et Symfony UX
(Turbo, Stimulus, Live Components), AssetMapper, Tailwind CSS, Messenger et Scheduler, Mailer
(Brevo en production), Stripe Billing, dompdf, FrankenPHP et Docker Compose.

## Mise en production

Tout est décrit pas à pas dans [`docs/DEPLOY.md`](docs/DEPLOY.md) : VPS, DNS, `.env.prod`, premier
déploiement, mises à jour automatiques par GitHub Actions, sauvegardes et restauration.

## Contribuer

Toute contribution commence par une issue : pas de pull request sans issue acceptée au préalable.
Le fonctionnement est décrit dans [`CONTRIBUTING.md`](CONTRIBUTING.md), et la participation suit le
[code de conduite](CODE_OF_CONDUCT.md). Une faille de sécurité se signale en privé : voir
[`SECURITY.md`](SECURITY.md).

## Licence

Panier Sauvage est un logiciel libre distribué sous licence
[GNU Affero General Public License v3.0 ou ultérieure](LICENSE) (AGPL-3.0-or-later). Si tu
modifies le logiciel et que tu le proposes comme service en ligne, tu dois mettre le code source
de ta version à disposition de ses utilisateurs.
