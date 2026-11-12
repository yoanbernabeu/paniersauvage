# Panier Sauvage

SaaS de précommandes pour producteurs. **Lis `docs/SPEC.md` avant toute tâche** : règles produit,
modèle de données, noms de routes, événements de domaine et direction artistique y sont figés.
Les conventions Symfony générales sont en fin de fichier ; en cas de conflit, les règles du projet
qui suivent l'emportent (ex. : `doctrine:schema:update` pour la base de test, migration initiale unique).

## Commandes

```bash
docker compose up -d --wait                       # PostgreSQL (port 54329) + Mailpit (http://localhost:8025)
symfony serve -d                                  # serveur local
php bin/console doctrine:migrations:migrate -n    # dev : appliquer les migrations
php bin/console doctrine:schema:update --force --env=test
php bin/console doctrine:fixtures:load -n         # jeu de démo (Fournil du Pont + 3 voisins + 15 producteurs autour de Valence, déjà géocodés)
php bin/console app:delivery-points:geocode       # géocode les points de retrait sans coordonnées (API Adresse)
php bin/console tailwind:build                    # CSS (ajoute --watch pendant le développement)
vendor/bin/phpunit                                # tests (ou un dossier : vendor/bin/phpunit tests/Catalog)
vendor/bin/phpstan analyse                        # niveau max
vendor/bin/php-cs-fixer fix                       # style @Symfony
composer ci                                       # toute la CI en local (sauf mutation et image Docker)
composer mutation:diff                            # mutation des lignes modifiées depuis origin/main
```

## Qualité (CI)

`.github/workflows/ci.yml` lance en parallèle : linters Symfony, **Symfony LSP** (`symfony lsp:check`,
Symfony CLI ≥ 5.20), PHPStan max, PHP-CS-Fixer, **Rector** (dry-run : tout changement proposé fait
échouer), migrations rejouées sur une base vide puis `schema:validate`, chargement des fixtures,
PHPUnit avec couverture, `composer audit` + `importmap:audit`, construction de l'image Docker et,
sur chaque pull request, **Infection** sur les lignes modifiées (MSI couvert ≥ 90 %).
`mutation.yml` mute tout le code chaque nuit (seuils dans `infection.json5` : 88 %, pour 89 % mesurés
le 10 novembre 2026 ; on les monte, on ne les baisse jamais). Les formulaires (`OrderFormType`,
`SaleScheduleType`, `ShopSettingsType`), `OrderManager`, `SaleEditor` et le webhook Stripe
concentrent le plus de mutants survivants : à renforcer en priorité.

- Infection a besoin de PCOV ou Xdebug et d'une base de test par thread (`TEST_TOKEN`, suffixe déjà
  prévu dans `doctrine.yaml`) : `composer mutation:databases` les crée.
- Un mutant qui survit = une ligne qu'aucun test ne verrouille : ajoute l'assertion qui le tue
  plutôt que d'exclure le mutateur. Exclusion seulement pour un vrai faux positif, commentée dans
  `infection.json5`.
- Rector : `vendor/bin/rector process` puis relecture du diff et des tests avant de committer.

## Conventions

- Code, identifiants et commentaires en anglais ; **interface et e-mails en français, tutoiement**.
- Un dossier par domaine sous `src/` (`Shop`, `Catalog`, `Sale`, `Delivery`, `Ordering`, `Customer`,
  `Billing`, `Notification`, `Accounting`, `Legal`, `Recurring`, `Shared`) ; templates dans `templates/<domaine>/`,
  tests dans `tests/<Domaine>/`.
- `declare(strict_types=1);`, classes `final` par défaut, attributs PHP (routes, ORM, validation).
- Montants en centimes (`int`) ; affichage via le filtre Twig `|money`. TVA en points de base.
- **Quantités en millièmes d'unité** (`int`, `App\Shared\Service\Quantity`) : 2 pains = `2000`,
  1,5 kg = `1500` ; seules les ventes au kilo acceptent des fractions. Prix d'une quantité :
  `Quantity::priceCents()` ; affichage `|quantity(unit)`, `|line_quantity(unit)`, `|quantity_input` ;
  saisie `App\Shared\Form\QuantityType` (SPEC §13).
- Dates en `DateTimeImmutable` ; fuseau `Europe/Paris` pour l'affichage ; injecter `ClockInterface`
  au lieu d'appeler `new \DateTimeImmutable()` dans les services.
- Côté `/pro`, toujours partir de la boutique courante (`App\Shop\Service\CurrentShop`) et renvoyer
  404 si une ressource appartient à une autre boutique. Chaque route `/pro` a un test d'isolation.
- **Jamais de notion de paiement enregistré** (voir SPEC §1).
- Les e-mails partent via des abonnés aux événements de domaine (SPEC §6), pas depuis les contrôleurs.
- Tests : `WebTestCase` + factories Foundry (`tests/Factory/`), base réinitialisée par
  `dama/doctrine-test-bundle`.

## Socle commun (fondations)

**Entités** : `App\<Domaine>\Entity`, classes `final`, getters/setters classiques. Les constructeurs
prennent le parent (`new Product($shop)`, `new SaleOffer($sale, $product)`, `new OrderLine($order, $offer, 2)`,
`new SaleDelivery($sale, $point, $start, $end)`) ; offres, livraisons, lignes et événements
s'attachent eux-mêmes à leur parent (cascade persist). Les quantités sont en millièmes :
`new OrderLine($order, $offer, 2000)` pour deux pièces (une fraction de pièce lève une exception). Enums avec `label()` en français.
Tables : `producer_user` (User), `customer_order` (Order).

**Dates** : fuseau PHP forcé à `Europe/Paris` (`App\Kernel::boot()`), colonnes `datetime_immutable`
stockées en heure de Paris. Ne crée jamais de date dans un autre fuseau avant de la persister.

**Services partagés**
- `App\Shop\Service\CurrentShop` : `get()` (lève `NoCurrentShopException` = 403 / redirection login),
  `find()`, `has()`, `getUser()`, `owns($shop)`, `denyUnlessOwns($shop)` (404).
- `App\Sale\Service\SaleCodeGenerator::generate()`, `App\Ordering\Service\OrderReferenceGenerator::generate()`.
- `App\Shared\Service\FrenchFormatter` (PHP) et filtres Twig : `|money`, `|vat_rate`, `|date_long`,
  `|date_compact`, `|date_short`, `|time_short`, `|datetime_long`, `|weekday_name`, `time_range(a, b)`.
- Routes pas encore créées : `safe_path('route', '/chemin/de/secours')`, `route_exists('route')`
  (`App\Shared\Navigation\SafeRouter` côté PHP). La navigation producteur (`pro_nav()`,
  `App\Shared\Navigation\ProNavigation`) n'affiche que les routes qui existent : rien à modifier
  quand tu ajoutes `pro_sale_index`, etc.
- `SaleOfferRepository::findForUpdate($id)` : `SELECT … FOR UPDATE` pour `ReservationService`.

**Instance territoriale** (SPEC §16, `docs/INSTANCE.md`) : global Twig `instance`
(`App\Instance\InstanceSettings`) ; dans les gabarits, écris `{{ instance.name }}` plutôt que
« Panier Sauvage » pour nommer le service. Abonnement : passe par `App\Instance\SubscriptionPolicy`
(mode sponsorisé), pas directement par `ShopSubscription::allowsNewSales()`. Tests sur une autre
instance : `App\Tests\Instance\InstanceTrait::useInstance([...])` avant `createClient()`.

**Sécurité** : pare-feu `producer` (`^/pro`, `User`, form_login) et `main` (`Customer`, login_link,
`check_route: customer_login_check`). En test : `$client->loginUser($user, 'producer')` ou
`$client->loginUser($customer, 'main')`. Rôles : `ROLE_PRODUCER`, `ROLE_CUSTOMER`, et
`ROLE_SHOP_OWNER` (`User::ROLE_SHOP_OWNER`) pour le propriétaire d'une boutique : une boutique peut avoir
plusieurs comptes (SPEC §22.5), seul le propriétaire gère l'abonnement et l'équipe. « Le » producteur
d'une boutique est `Shop::getOwner()` (jamais `users|first`) ; l'e-mail à montrer aux clients est
`Shop::publicEmail()`. `UserFactory` crée un propriétaire ; un membre :
`UserFactory::createOne(['shop' => $shop, 'shopRole' => ShopRole::Member])`.

**Gabarits**
- `layout/customer.html.twig` : blocs `title`, `meta_description`, `header`, `header_nav`, `flashes`,
  `main`, `content`, `footer` ; variable `content_width` (défaut `max-w-3xl`).
- `layout/pro.html.twig` : blocs `title`, `page_title`, `page_subtitle`, `page_actions`,
  `page_header`, `flashes`, `content` ; variable `content_width` (défaut `max-w-6xl`).
- `layout/auth.html.twig` (carte centrée, connexion/inscription) : blocs `auth_title`, `auth_intro`,
  `content`, `auth_footer`.
- Messages flash : `success`, `error`, `warning`, `info`, déjà affichés par les trois gabarits.
- Formulaires Symfony : thème global `templates/form/theme.html.twig` (`form_row(form.champ)` suffit).

**Composants Twig** (`templates/components/`, kit shadcn rethémé) : `Button` (variants `default` = jaune
blé, `secondary` = contour sauge, `sage`, `outline`, `ghost`, `destructive`, `link` ; tailles `xs`, `sm`,
`default`, `lg`, `icon` ; `as="a"`), `Card` (+ `Card:Header|Title|Description|Content|Footer|Action`),
`Input`, `Label`, `Textarea`, `NativeSelect` (+ `:Option`, `:OptGroup`), `Select` (liste déroulante
Stimulus), `Checkbox`, `Badge` (`default`, `secondary`, `sage`, `muted`, `destructive`, `outline`),
`Alert` (`default`, `success`, `info`, `warning`, `destructive` ; + `Alert:Title|Description|Action`),
`Table` (+ `Table:Header|Body|Row|Head|Cell|Footer|Caption`), `Separator`, `Empty` (états vides),
`Field`, `Logo` (`tone="light"`, `size`, `:wordmark="false"`), `Illustration` (`name` = `basket`,
`wheat`, `herbs`). Icônes Lucide : `<twig:ux:icon name="lucide:calendar" class="size-5" />` (SVG
dans `assets/icons/lucide/` ; une icône absente est téléchargée et enregistrée en dev : commite-la).
Un nom de variable Twig `style` est réservé à l'intérieur des composants du kit : évite-le.

**CSS** : jetons dans `assets/styles/app.css` (`bg-sage`, `text-sage-dark`, `text-sage-deep`,
`bg-sage-soft`, `bg-sage-mist`, `bg-wheat`, `bg-wheat-soft`, `text-wheat-ink`, `bg-cream`, `text-ink`,
`text-ink-soft`, `border-line`, `border-line-soft`, `text-danger`, `bg-danger-soft`, `font-display`,
`shadow-card`, classes `.amount` et `.link`). Après un `git clone` : `php bin/console importmap:install`
avant `tailwind:build` (le CSS importe `assets/vendor/`).

**Factories** (`tests/Factory/`, mot de passe producteur : `password`) : `UserFactory::createOne()`
crée le producteur et sa boutique ; `SaleFactory::createOne(['shop' => $shop])` (états `scheduled()`,
`closed()`, `done()`, `cancelled()`) ; `SaleOfferFactory`, `SaleDeliveryFactory`, `OrderFactory`,
`OrderLineFactory` complètent seuls les objets manquants dans la boutique de la vente. Les factories
ne réservent pas de stock (`reservedQty` à passer explicitement). Quantités en millièmes (`'quantity' => 2000`,
`'maxQty' => 5000`) ; `ProductFactory::new()->limitedTo(5)` (pièces), `->byWeight(250, 500)` (au kilo) ;
`CustomerFactory::new()->withoutEmail($shop)` (client sans e-mail). Ajoute `use Factories;` au test.
Une boutique n'est dans l'annuaire que sur inscription : `ShopFactory::new()->listed()` (avec une
catégorie). Les factories écrivent les propriétés sans passer par les setters
(`always_force_properties`) : `ShopFactory` convertit les listes d'enums, `DeliveryPointFactory` lit
la commune dans l'adresse.

**PHPStan** lit `var/cache/dev/App_KernelDevDebugContainer.xml` : lance une commande `bin/console`
en dev au moins une fois avant `vendor/bin/phpstan analyse`.

## Migrations

**Tant que le code n'a jamais été poussé sur un dépôt distant ni déployé, il n'y a qu'une seule
migration : la migration initiale.** Toute évolution du modèle la régénère au lieu d'en ajouter une
nouvelle (pas de migration de reprise de données : aucune base à faire évoluer).

```bash
rm migrations/Version*.php
export DATABASE_URL='postgresql://app:!ChangeMe!@127.0.0.1:54329/app_squash?serverVersion=18&charset=utf8'
php bin/console doctrine:database:create && php bin/console doctrine:migrations:diff -n
php bin/console doctrine:migrations:migrate -n && php bin/console doctrine:schema:validate
php bin/console doctrine:database:drop --force && unset DATABASE_URL
```

Relis la migration produite (description `Initial schema`, docblock explicatif, pas de
`CREATE SCHEMA public` dans `down()`). Puis base de dev : `doctrine:database:drop --force`,
`doctrine:database:create`, `doctrine:migrations:migrate -n`, `doctrine:fixtures:load -n` (ou, pour
garder les données : `doctrine:schema:update --force`, vider `doctrine_migration_versions` et
`doctrine:migrations:version 'DoctrineMigrations\VersionXXX' --add -n`). Dans un worktree de lot,
régénère aussi la migration initiale : à la fusion, on la régénère une dernière fois depuis le code
fusionné. La base de test est synchronisée avec `doctrine:schema:update --force --env=test`.

Une fois le code poussé ou déployé, cette règle tombe : retour à une migration par évolution
(`doctrine:migrations:diff`, relue, puis `doctrine:migrations:migrate -n`).

## Pièges connus

- **Slugs réservés** : `/{slug}` attrape tout. Une nouvelle route publique ajoute son premier
  segment à `Shop::RESERVED_SLUGS` (sinon `ReservedSlugsTest` échoue).
- **CGU et tests** : `UserFactory` et `CustomerFactory` créent des comptes qui ont accepté la version
  courante (`LegalDocuments::TERMS_VERSION`) ; sans elle, toute page `/pro` redirige vers
  `/pro/conditions`. Un invité coche `order_form[acceptTerms]` à chaque commande, la création de
  compte client coche `accept_terms` (`MagicLinkTrait::confirmLink()` le fait).
- **Vérification des SIRET en test** : aucun appel réseau, `company_registry.client` est servi par
  `App\Tests\Legal\Support\FakeCompanyRegistry` (`use FakeCompanyRegistryTrait;`, puis `knows()`,
  `down()`, `timeout()`). Une boutique n'apparaît dans l'annuaire que vérifiée :
  `ShopFactory::new()->verified()` ; les fixtures créent leurs `ShopVerification` directement.
- **API Adresse en test** : aucun appel réseau, `ban.client` est servi par
  `App\Tests\Delivery\Support\FakeBan` (`use FakeBanTrait;`, puis `FakeBan::knows()`, `down()`,
  `timeout()`). Un point créé par factory avec une adresse est géocodé (sans résultat par défaut) :
  `DeliveryPointFactory::new()->located($lat, $lng)` pour des coordonnées connues.

- **Twig et les méthodes d'action des entités** : `sale.open` appelle `Sale::open()` et change le
  statut en mémoire. Écris toujours `sale.isOpen()`. Même risque pour `sale.close`, `sale.cancel`,
  `order.confirm`, `order.cancel`, et `customer.withoutEmail` (fabrique statique : écris
  `customer.isWithoutEmail()`), `sale.openLastMinute` et `sale.endLastMinute` (écris `sale.isLastMinute()`).
  `customer.isWithoutEmail()`). Pour un abonnement (`App\Recurring`, SPEC §23) : `recurring_order.suspend`,
  `.pause`, `.end` agissent ; écris `recurring_order.isSuspended()`, `.isEnded()`.
- **PropertyAccessor** lit `hasX()` avant une propriété publique `$x` : n'ajoute pas de `hasEmail()` à un
  DTO de formulaire qui a un champ `email`.
- **Client sans e-mail** : `Customer::getEmail()` est nullable ; ne construis jamais d'`Address` sans
  tester `null` (SPEC §14).
- **PHPStan** : à froid, lance-le avec `--memory-limit=1G`.
- **Annulation d'une vente** : `SaleLifecycle::cancel()` annule la vente et, dans la même transaction
  (`SaleCancelling`), `SaleOrderCanceller` annule les commandes et libère le stock ; `SaleCancelled`
  (e-mails) part après le commit. Filet : `ReconcileCancelledSalesTask` (toutes les 15 min).
- **Palier solidaire** : un abonnement `active` n'autorise pas toujours les ventes. Le palier gratuit
  (plan `solidarity` sans `stripeSubscriptionId`, `isFreeSolidarity()`) s'arrête à `currentPeriodEnd`.
  Toute nouvelle règle sur l'abonnement passe par `allowsNewSales()` **et** `allowsNewSalesSql()`
  (`ShopSubscriptionSqlTest`). En test, `FakeBillingGateway` enregistre aussi `amountChanges` et
  `cancellations` ; il se réinitialise à chaque requête (`$client->disableReboot()` pour le régler).
- **Exports** : les identifiants du JSON-LD Data Food Consortium sont des URL absolues (hôte de la
  requête, `DEFAULT_URI` hors requête). Le fichier au format FEC n'est pas le FEC fiscal (SPEC §18).

## Travail en parallèle (un worktree git par lot)

Chaque lot est développé dans son propre worktree git, sur sa propre branche, avec sa propre base.

Mise en route d'un worktree :

```bash
composer install
printf 'DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:54329/app_<lot>?serverVersion=18&charset=utf8"\n' > .env.local
cp .env.local .env.test.local   # .env.local est ignoré en test : sans ce fichier, tous les worktrees partagent app_test
echo "APP_SECRET=$(openssl rand -hex 32)" >> .env.local   # APP_SECRET n'est plus versionné (dev uniquement, pas en test)
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate -n
php bin/console doctrine:database:create --if-not-exists --env=test
php bin/console doctrine:schema:update --force --env=test
php bin/console importmap:install && php bin/console tailwind:build
```

Règles :

- Ne touche que les fichiers de ton lot. Fichiers partagés (`composer.json`, `config/packages/*`,
  `config/services.yaml`, `templates/base.html.twig`, `templates/layout/*`, `templates/components/*`,
  entités et factories d'un autre domaine, `docs/SPEC.md`, `AGENTS.md`) : modification minimale et
  additive uniquement si indispensable, signalée dans ton rapport (elle devra être fusionnée).
- Ton lot doit compiler et passer ses tests **seul**, sans le code des autres lots : pour un lien vers
  une route d'un autre lot, utilise `safe_path('route', '/chemin/de/secours')`.
- N'installe pas de paquet Composer sans nécessité absolue ; signale-le.
- Committe ton travail sur la branche de ton worktree (messages en anglais, impératif, préfixe
  `feat:`/`fix:`/`test:`/`chore:`/`docs:`/`refactor:`, 72 caractères max, sans mention d'outil d'IA).
  Jamais de `git push`, ni de changement de branche.

---

# Conventions Symfony générales

This is a Symfony project. Check `composer.json` for the exact Symfony/PHP version
in use, and read `symfony.lock` to see which recipes ran. Don't assume Doctrine,
Twig, API Platform, Messenger, or Lock are installed unless one of those says so.

## Ask before generating

If the task doesn't specify, ask rather than guess:

- Persistence: Doctrine ORM, Doctrine ODM, or none?
- Interface: server-rendered (Twig), API (Serializer, maybe API Platform), or both?
- Auth: SecurityBundle, and which authenticator?

If you can't ask (no interactive channel), state the assumption you're making and
pick the smallest option (e.g. no persistence layer) rather than scaffolding a
full stack nobody asked for.

## Adding features: Flex, not hand-wiring

Install new capabilities with `composer require <package>` (e.g. `symfony/lock`,
`symfony/messenger`, `orm-pack`) and let the Flex recipe register the bundle and
generate its config. Don't hand-edit `config/bundles.php` or hand-write a bundle's
base config; that's what the recipe is for. Don't skip a good-fit component just
because it isn't installed yet; installing it is one command.

## Conventions

Follow https://symfony.com/doc/current/best_practices.html to write idiomatic
Symfony:

- Use PHP attributes for framework metadata, and not only on controllers:
  `#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]` on actions, `#[Assert\...]`
  on properties, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`, and
  `#[AsAlias]` / `#[AsTaggedItem]` / `#[Autoconfigure]` on services. No YAML or
  XML routing.
- Rely on autowiring and autoconfiguration. Type-hint constructor arguments and
  let the container resolve them. Where a type-hint can't express it, stay in the
  class with `#[Autowire]` (parameters, env vars, expressions) or `#[Target]` (one
  of several implementations of an interface). A YAML service definition is the
  last resort, not the first.
- Controllers extend `AbstractController`, stay thin, and delegate to services.
- Use the framework for what it already does: Form for server-rendered forms,
  Validator for validation, Serializer for JSON, Messenger for async work,
  Security (voters, authenticators) for access control, Twig `path()`/`url()`
  instead of hardcoded URLs.
- Before hand-writing infrastructure (locks, queues, caches, HTTP clients,
  mailers, schedulers) or reaching for a third-party library, check whether a
  Symfony component covers it. It usually does.

Three specifics worth spelling out, because they are easy to get wrong:

- Bind request data with `#[MapRequestPayload]` / `#[MapQueryString]` on action
  arguments, which wires up Serializer and Validator for you, instead of calling
  `json_decode()` or `SerializerInterface` by hand. If neither package is
  installed yet, `composer require` them rather than falling back to manual
  parsing.
- Use constructor property promotion, and `readonly` for DTOs and value objects.
- Use `symfony/lock` (`LockFactory`) for mutual exclusion. A hand-built flag or
  lock file looks fine in review and is usually wrong under concurrency.

## Everyday workflow

- Run the app with `symfony serve -d`, and commands with `symfony console ...`
  (or `bin/console` when the Symfony CLI isn't available).
- When something fails, read `var/log/dev.log` and the web profiler
  (`/_profiler`) before changing code.
- If `maker-bundle` is installed, prefer `bin/console make:*` with every argument
  passed up front and `--no-interaction` where supported: makers prompt on a
  terminal by default, which hangs a non-interactive shell. If a maker still
  needs interactive input, hand-write the code instead.
- If Doctrine ORM is installed, schema changes go through migrations
  (`bin/console make:migration`, then `doctrine:migrations:migrate`), never
  `doctrine:schema:update` or hand-written SQL.
- `.env` is committed and holds defaults only. Real secrets belong in `.env.local`
  (git-ignored) or the secrets vault (`bin/console secrets:set`), read via
  `%env(...)%`.

## Testing

Install `symfony/test-pack` if it isn't already. Functional/HTTP tests extend
`WebTestCase`; service-level tests extend `KernelTestCase`. Run
`php bin/phpunit` (falls back to `vendor/bin/phpunit`). A feature isn't done
until it has a test that exercises it the way a caller would, an HTTP request for
a controller or a service call for a service, not just "it didn't throw."

## Code style

Symfony's coding standard, the `@Symfony` php-cs-fixer ruleset (a PSR-12-derived
superset). Run `vendor/bin/php-cs-fixer fix` if `friendsofphp/php-cs-fixer` is
installed; it isn't part of the skeleton by default.

## Discover, don't guess

Framework APIs change between versions and your training data may be stale. Look
things up in the project instead of relying on memory:

- `bin/console about`: versions, environment, paths.
- `bin/console debug:router`, `debug:container`, `debug:autowiring <name>`,
  `debug:config <bundle>`, `config:dump-reference <bundle>`: what exists and how
  it is configured.
- `bin/console lint:container`, plus `lint:twig templates/` and
  `lint:yaml config/` where those packages are installed: validate before running.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).
