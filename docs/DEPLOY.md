# Mise en production sur un VPS

Ce guide installe Panier Sauvage sur un seul VPS hébergé en France, avec Docker Compose :

| Service | Rôle |
| --- | --- |
| `app` | FrankenPHP (Caddy + PHP 8.5, mode worker), HTTPS automatique, ports 80 et 443 |
| `worker` | `messenger:consume async scheduler_default` : e-mails et tâches planifiées |
| `database` | PostgreSQL 18 |

Fichiers concernés : `Dockerfile`, `docker/` (Caddyfile, `php.ini`, script d'entrée, sauvegarde),
`compose.prod.yaml`, `.env.prod.example`, `.github/workflows/deploy.yml`.

Dans tout le guide, remplace `boutique.example.fr` par ton domaine, `OWNER/panier-sauvage` par ton
dépôt GitHub (en minuscules) et `203.0.113.10` par l'adresse IP du VPS.

## 1. Le VPS

Un petit VPS suffit pour démarrer (2 vCPU, 4 Go de RAM, 40 Go de disque), chez un hébergeur
français (OVHcloud, Scaleway…) avec un centre de données en France. Système conseillé : Debian 13 ou Ubuntu 24.04.

Sécurisation minimale, en `root` :

```bash
apt update && apt upgrade -y
adduser deploy                                    # utilisateur dédié au déploiement
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
# colle ta clé publique dans /home/deploy/.ssh/authorized_keys (chmod 600, propriétaire deploy)

# pare-feu : SSH, HTTP, HTTPS (TCP) et HTTP/3 (UDP 443)
apt install -y ufw
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp && ufw allow 443/udp
ufw enable
```

Désactive ensuite la connexion SSH par mot de passe (`PasswordAuthentication no` dans
`/etc/ssh/sshd_config`, puis `systemctl reload ssh`).

## 2. DNS

Chez ton registrar, crée :

| Type | Nom | Valeur |
| --- | --- | --- |
| `A` | `boutique.example.fr` | `203.0.113.10` |
| `AAAA` | `boutique.example.fr` | adresse IPv6 du VPS (si elle existe) |

Attends que `dig +short boutique.example.fr` renvoie la bonne adresse **avant** le premier
démarrage : Caddy demande le certificat Let's Encrypt dès le lancement, et les ports 80 et 443
doivent être joignables depuis Internet.

## 3. Docker

```bash
curl -fsSL https://get.docker.com | sh            # Docker Engine + plugin Compose
usermod -aG docker deploy                         # deploy peut lancer docker
install -d -o deploy -g deploy /opt/panier-sauvage
```

Reconnecte-toi avec l'utilisateur `deploy` ; la suite se passe dans `/opt/panier-sauvage`.

## 4. L'image

L'image de production est construite et publiée sur GHCR par GitHub Actions (section 7). Pour un
tout premier essai sans attendre la CI, tu peux aussi la construire sur ta machine :

```bash
docker build --platform linux/amd64 -t ghcr.io/OWNER/panier-sauvage:latest .
docker push ghcr.io/OWNER/panier-sauvage:latest   # après docker login ghcr.io
```

Si le paquet GHCR est privé, le VPS doit s'authentifier une fois pour tirer l'image à la main :
crée un jeton GitHub (classic) avec la seule permission `read:packages`, puis
`docker login ghcr.io -u <ton-identifiant>`. Le déploiement automatique, lui, utilise un jeton
temporaire et n'a pas besoin de cette étape.

## 5. Le fichier `.env.prod`

Copie sur le VPS `compose.prod.yaml`, `docker/backup-database.sh` et `.env.prod.example` (le
déploiement automatique recopie les deux premiers à chaque mise à jour) :

```bash
scp compose.prod.yaml .env.prod.example deploy@203.0.113.10:/opt/panier-sauvage/
ssh deploy@203.0.113.10 'mkdir -p /opt/panier-sauvage/docker'
scp docker/backup-database.sh deploy@203.0.113.10:/opt/panier-sauvage/docker/
```

Puis, sur le VPS :

```bash
cd /opt/panier-sauvage
cp .env.prod.example .env.prod
chmod 600 .env.prod
nano .env.prod
```

`.env.prod` contient tous les secrets : il ne quitte jamais le serveur et n'est jamais versionné
(il est dans `.gitignore` et `.dockerignore`).

| Variable | Rôle | Comment l'obtenir |
| --- | --- | --- |
| `APP_IMAGE` | image à lancer | `ghcr.io/OWNER/panier-sauvage:latest` |
| `SERVER_NAME` | domaine public, pilote le HTTPS automatique | `boutique.example.fr` |
| `CADDY_GLOBAL_OPTIONS` | optionnel, e-mail de contact Let's Encrypt | `"email admin@example.fr"` |
| `POSTGRES_DB`, `POSTGRES_USER` | base et utilisateur PostgreSQL | `app` / `app` |
| `POSTGRES_PASSWORD` | mot de passe PostgreSQL (lettres et chiffres uniquement) | `openssl rand -hex 24` |
| `APP_SECRET` | secret Symfony (jetons CSRF, liens signés) | `openssl rand -hex 32` |
| `DEFAULT_URI` | URL publique, utilisée dans les e-mails | `https://boutique.example.fr` |
| `MESSENGER_TRANSPORT_DSN` | file de messages | `doctrine://default?auto_setup=0` |
| `MAILER_DSN` | envoi des e-mails par Brevo | `brevo+api://CLE_API@default` |
| `MAILER_FROM` | expéditeur par défaut | `"Panier Sauvage <bonjour@example.fr>"` |
| `STRIPE_SECRET_KEY` | clé secrète Stripe (mode réel) | `sk_live_…` |
| `STRIPE_WEBHOOK_SECRET` | secret de signature du webhook | `whsec_…` |
| `STRIPE_PRICE_MONTHLY` | identifiant du tarif mensuel | `price_…` |
| `STRIPE_PRICE_YEARLY` | identifiant du tarif annuel | `price_…` |
| `STRIPE_PRODUCT_SOLIDARITY` | produit du palier solidaire (prix libre) : ses tarifs mensuels sont créés à la volée au montant choisi | `prod_…` |
| `MAP_TILE_URL` | optionnel, serveur de tuiles des cartes de l'annuaire et des pages boutique (HTTPS, `{z}/{x}/{y}`, `{s}` accepté) ; vide : pas de carte | défaut : Plan IGN de la Géoplateforme, `https://data.geopf.fr/wmts?…&LAYER=GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2&…&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}&FORMAT=image/png` (voir `.env`) |
| `MAP_TILE_ATTRIBUTION`, `MAP_TILE_ATTRIBUTION_URL` | optionnels, mention du fournisseur affichée sur la carte, et son lien | défaut : `© IGN – Plan IGN`, `https://www.ign.fr` |
| `LEGAL_PUBLISHER_NAME`, `LEGAL_PUBLISHER_FORM`, `LEGAL_PUBLISHER_ADDRESS`, `LEGAL_PUBLISHER_PHONE` | éditeur du site (mentions légales, CGU, confidentialité, pied des e-mails) : nom ou dénomination, forme juridique (et capital, RCS, TVA si besoin), adresse, téléphone | ta situation (voir « Mentions légales ») |
| `LEGAL_PUBLISHER_SIRET` | SIRET de l'éditeur (optionnel tant que tu n'en as pas) | `annuaire-entreprises.data.gouv.fr` |
| `LEGAL_PUBLICATION_DIRECTOR` | directeur ou directrice de la publication | ton nom |
| `LEGAL_CONTACT_EMAIL` | contact des pages légales ; reçoit les signalements et les boutiques à vérifier | `contact@ton-domaine.fr` |
| `LEGAL_HOST_NAME`, `LEGAL_HOST_ADDRESS`, `LEGAL_HOST_PHONE` | hébergeur du VPS (le téléphone est optionnel) | mentions légales de l'hébergeur |
| `LEGAL_SOURCE_CODE_URL` | dépôt public du code qui tourne (AGPL) | `https://github.com/OWNER/panier-sauvage` |
| `TRUSTED_PROXIES` | optionnel, uniquement avec `SERVER_NAME=:80` derrière un autre reverse proxy : ses adresses IP ou plages, séparées par des virgules, ou `PRIVATE_SUBNETS` | `172.18.0.0/16` |

Au démarrage, le conteneur refuse de se lancer si `APP_SECRET`, `MAILER_DSN`, `MAILER_FROM`, une
variable `STRIPE_*` ou une variable `LEGAL_*` obligatoire est vide ou contient encore une valeur
d'exemple (`changeme`, `example.fr`, `BREVO_API_KEY`, `à renseigner`, une adresse en `.test`, `OWNER`
dans l'URL du code…) : les journaux (`docker compose logs app`) listent les variables à corriger. Les fichiers `.env` versionnés ne contiennent aucun secret.

Laisse `TRUSTED_PROXIES` vide quand Caddy est directement exposé (cas par défaut) : les en-têtes
`X-Forwarded-*` sont alors ignorés, sinon n'importe qui pourrait falsifier son adresse IP et
contourner les limites de tentatives.

`DATABASE_URL` n'est pas à renseigner : `compose.prod.yaml` la construit à partir des variables
`POSTGRES_*`. Ne change plus `POSTGRES_PASSWORD` après le premier démarrage (il est fixé à la
création du volume) et ne change jamais `APP_SECRET` sans raison : cela invalide les liens de
connexion déjà envoyés.

### Tuiles de la carte

L'annuaire des producteurs et les pages boutique affichent une carte (Leaflet) dont les fonds
viennent par défaut du **Plan IGN de la Géoplateforme** (WMTS `GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2`,
jeu de tuiles `PM_0_19`, zooms 0 à 19) : gratuit, sans clé ni quota de requêtes, sous Licence
Ouverte Etalab ([CGU](https://cartes.gouv.fr/cgu/)). Rien à configurer.

Le fournisseur reste interchangeable : les tuiles d'OpenStreetMap (`https://tile.openstreetmap.org/{z}/{x}/{y}.png`,
attribution `© contributeurs OpenStreetMap`) sont une option pour un usage léger seulement (leur
[politique d'usage](https://operations.osmfoundation.org/policies/tiles/) interdit un usage
intensif), un fournisseur commercial (MapTiler, Stadia Maps, Thunderforest) ou ton propre serveur
de tuiles en sont d'autres. Renseigne alors `MAP_TILE_URL` (avec la clé du fournisseur dans l'URL si besoin),
`MAP_TILE_ATTRIBUTION` et `MAP_TILE_ATTRIBUTION_URL` comme il le demande ; l'attribution reste
toujours visible sur la carte. La Content-Security-Policy n'autorise les images que de l'hôte de
`MAP_TILE_URL` (`https://*.domaine` pour une URL en `{s}.domaine`) : rien d'autre à configurer.
Une URL vide, non HTTPS ou mal formée désactive la carte ; la liste des résultats reste.

### Brevo

1. Crée un compte [Brevo](https://www.brevo.com/fr/) (société française, données en Europe).
2. *Expéditeurs, domaines et IP dédiées* : ajoute ton domaine et crée les enregistrements DNS
   demandés (DKIM, code Brevo, DMARC). Sans domaine authentifié, les e-mails arrivent en spam.
3. *SMTP et API > Clés API* : génère une clé et place-la dans `MAILER_DSN=brevo+api://CLE@default`.
4. `MAILER_FROM` doit utiliser une adresse du domaine authentifié.

### Mentions légales

Les pages `/mentions-legales`, `/cgu`, `/confidentialite` et `/engagements` affichent les
variables `LEGAL_*`. La loi (LCEN, art. 6 III) impose l'identité de l'éditeur (nom ou dénomination,
adresse, téléphone, numéro d'immatriculation et capital pour une société), le directeur de la
publication et l'hébergeur (nom, adresse, téléphone). Les textes sont des modèles à faire relire par
un juriste avant le premier producteur réel : voir `docs/LEGAL.md`.

`LEGAL_CONTACT_EMAIL` reçoit les signalements de boutiques et les boutiques dont le code d'activité
n'est ni agricole ni alimentaire. Il n'y a pas d'interface d'administration, tout passe par la console :

```bash
dc exec app php bin/console app:shops:review                       # boutiques à vérifier
dc exec app php bin/console app:shops:review --approve=<slug>      # l'afficher dans l'annuaire
dc exec app php bin/console app:shops:review --reject=<slug> --note="Revente"
dc exec app php bin/console app:shops:verify                       # relancer les SIRET en attente (fait toutes les 6 h)
dc exec app php bin/console app:shops:verify --all                 # tout revérifier (établissements fermés)
```

La vérification interroge l'API publique Recherche d'entreprises
(`recherche-entreprises.api.gouv.fr`, gratuite, sans clé) : le serveur doit pouvoir la joindre en HTTPS.

### Stripe

1. Dans le tableau de bord Stripe, en mode réel, crée un produit « Panier Sauvage » avec deux
   tarifs récurrents (mensuel : 10 €, et annuel). Recopie leurs identifiants `price_…` dans
   `STRIPE_PRICE_MONTHLY` et `STRIPE_PRICE_YEARLY`.
   Crée aussi un produit « Panier Sauvage – palier solidaire » **sans tarif** et recopie son
   identifiant `prod_…` dans `STRIPE_PRODUCT_SOLIDARITY` : chaque abonnement solidaire y crée son
   tarif mensuel (`price_data`, 1 à 10 € en euros entiers). À 0 €, rien ne passe par Stripe.
2. *Développeurs > Clés API* : recopie la clé secrète dans `STRIPE_SECRET_KEY` (une clé
   restreinte limitée à Checkout, Billing et au portail client est préférable ; elle doit pouvoir
   lire, modifier et résilier les abonnements, pour les changements de montant du palier solidaire).
3. *Paramètres > Billing > Portail client* : active le portail (mise à jour du moyen de paiement,
   résiliation).
4. *Développeurs > Webhooks > Ajouter un endpoint* :
   - URL : `https://boutique.example.fr/webhooks/stripe`
   - événements : `checkout.session.completed`, `customer.subscription.created`,
     `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`,
     `invoice.payment_failed` (ajuste la liste à ce que traite le contrôleur du webhook) ;
   - recopie le *secret de signature* (`whsec_…`) dans `STRIPE_WEBHOOK_SECRET`.

Une instance sponsorisée (`INSTANCE_PRICING=sponsored`) n'utilise pas Stripe : les variables
`STRIPE_*` peuvent rester vides.

### Instance territoriale

Les variables `INSTANCE_*` (nom, logo, couleur d'accent, territoire, organisme porteur, prise en
charge de l'abonnement) sont facultatives : sans elles, c'est Panier Sauvage. Une collectivité qui
déploie sa propre instance suit [`docs/INSTANCE.md`](INSTANCE.md). Au démarrage, l'entrypoint lance
`php bin/console app:instance:check` et refuse de démarrer sur une valeur inutilisable ; la même
commande affiche la configuration :

```bash
dc exec app php bin/console app:instance:check
```

Après avoir modifié `.env.prod`, relance la pile (section 8) pour que les conteneurs relisent
leurs variables.

## 6. Premier déploiement

```bash
cd /opt/panier-sauvage
docker compose --env-file .env.prod -f compose.prod.yaml pull
docker compose --env-file .env.prod -f compose.prod.yaml up -d --wait
docker compose --env-file .env.prod -f compose.prod.yaml ps
```

Ce qui se passe au démarrage :

1. `database` démarre et devient « healthy » ;
2. `app` attend la base, lance `doctrine:migrations:migrate --no-interaction --allow-no-migration`,
   puis démarre FrankenPHP ; Caddy obtient le certificat TLS (quelques secondes) ;
3. `worker` démarre une fois `app` en bonne santé, donc après les migrations.

Vérifie :

```bash
curl -I https://boutique.example.fr                                         # HTTP/2 200
docker compose --env-file .env.prod -f compose.prod.yaml logs -f app worker
```

Pour éviter de retaper les options, ajoute un alias dans `~/.bashrc` de l'utilisateur `deploy` :

```bash
alias dc='docker compose --env-file /opt/panier-sauvage/.env.prod -f /opt/panier-sauvage/compose.prod.yaml'
```

La suite du guide utilise `dc`.

## 7. Déploiement automatique (GitHub Actions)

`.github/workflows/deploy.yml` se déclenche quand la CI réussit sur `main` : il construit l'image,
la pousse sur GHCR (`:latest` et `:<sha du commit>`), recopie `compose.prod.yaml` et
`docker/backup-database.sh` sur le VPS, puis exécute `docker compose pull` et `up -d --wait`.

**Tant que les secrets `SSH_HOST`, `SSH_USER` et `SSH_KEY` ne sont pas tous définis, rien ne se
construit et rien ne se déploie** (le workflow s'arrête avec un simple avis).

Secrets à créer dans *Settings > Secrets and variables > Actions* du dépôt :

| Secret | Obligatoire | Valeur |
| --- | --- | --- |
| `SSH_HOST` | oui | `203.0.113.10` ou le nom d'hôte du VPS |
| `SSH_USER` | oui | `deploy` |
| `SSH_KEY` | oui | clé privée dédiée au déploiement (format OpenSSH, sans phrase de passe) |
| `SSH_PORT` | non | port SSH, `22` par défaut |
| `SSH_KNOWN_HOSTS` | recommandé | résultat de `ssh-keyscan 203.0.113.10` (sinon la clé d'hôte est acceptée à la première connexion) |
| `DEPLOY_PATH` | non | dossier du VPS, `/opt/panier-sauvage` par défaut |

Crée une paire de clés réservée à cet usage :

```bash
ssh-keygen -t ed25519 -f deploy_key -N '' -C 'github-actions-deploy'
ssh-copy-id -i deploy_key.pub deploy@203.0.113.10
# contenu de deploy_key  -> secret SSH_KEY ; supprime ensuite le fichier local
```

Le job de déploiement utilise l'environnement GitHub `production` : tu peux y exiger une
validation manuelle (*Settings > Environments*). `GITHUB_TOKEN` suffit pour publier et tirer
l'image, aucun jeton supplémentaire n'est nécessaire.

## 8. Mises à jour

Automatique : fusionne sur `main`, la CI passe, le déploiement suit.

À la main :

```bash
dc pull
dc up -d --wait        # recrée app et worker, lance les nouvelles migrations
docker image prune -f  # supprime les anciennes images
```

Revenir à une version précédente (chaque image est aussi étiquetée par le SHA du commit) :

```bash
APP_IMAGE=ghcr.io/OWNER/panier-sauvage:<sha> dc up -d --wait
```

Attention : cela ne défait pas les migrations déjà appliquées. Si la version précédente n'est pas
compatible avec le nouveau schéma, restaure la sauvegarde d'avant déploiement (section 10).

Après une modification de `.env.prod` : `dc up -d --wait` (Compose recrée les conteneurs dont
l'environnement a changé).

Les sessions (volume `sessions`), les fichiers envoyés (volume `uploads`) et les certificats
(volume `caddy_data`) survivent aux mises à jour.

## 9. Sauvegardes quotidiennes

`docker/backup-database.sh` exécute `pg_dump` (format personnalisé, compressé) dans le conteneur
`database`, écrit le fichier dans `backups/` et supprime les sauvegardes de plus de 14 jours.

```bash
cd /opt/panier-sauvage && ./docker/backup-database.sh
# 2026-01-05 03:15:02 backup written to ./backups/panier-sauvage-20260105-031502.dump (1.2M)
```

Planifie-le chaque nuit avec `crontab -e` (utilisateur `deploy`) :

```cron
15 3 * * * cd /opt/panier-sauvage && ./docker/backup-database.sh >> backups/backup.log 2>&1
```

Réglages possibles par variables d'environnement : `BACKUP_DIR` (défaut `./backups`),
`BACKUP_RETENTION_DAYS` (défaut `14`).

Une sauvegarde qui reste sur le même serveur ne protège pas d'une panne du serveur. Copie le
dossier chaque nuit vers un autre lieu, par exemple un stockage objet français avec
[rclone](https://rclone.org/) :

```cron
45 3 * * * rclone sync /opt/panier-sauvage/backups remote:panier-sauvage-backups
```

Sauvegarde aussi les fichiers envoyés par les producteurs (volume `uploads`) :

```bash
docker run --rm -v panier-sauvage-prod_uploads:/data:ro -v /opt/panier-sauvage/backups:/backup \
  alpine tar czf /backup/uploads-$(date +%Y%m%d).tar.gz -C /data .
```

Teste une restauration au moins une fois avant d'en avoir besoin.

## 10. Restauration

```bash
cd /opt/panier-sauvage

# 1. Arrêter ce qui écrit dans la base
dc stop app worker

# 2. Recharger la sauvegarde (--clean supprime les objets existants avant de les recréer)
dc exec -T database sh -c 'pg_restore --clean --if-exists --no-owner --username="$POSTGRES_USER" --dbname="$POSTGRES_DB"' \
  < backups/panier-sauvage-20260105-031502.dump

# 3. Redémarrer (les migrations manquantes sont rejouées au démarrage de app)
dc up -d --wait
```

Sur un serveur neuf : refais les sections 1 à 5, démarre uniquement la base
(`dc up -d --wait database`), lance l'étape 2 ci-dessus, puis `dc up -d --wait`.

Restaurer les fichiers envoyés :

```bash
docker run --rm -v panier-sauvage-prod_uploads:/data -v /opt/panier-sauvage/backups:/backup \
  alpine tar xzf /backup/uploads-20260105.tar.gz -C /data
```

## 11. Le worker

Le service `worker` exécute :

```text
php bin/console messenger:consume async scheduler_default --time-limit=3600 --memory-limit=256M
```

- `async` : file des e-mails (table `messenger_messages`) ;
- `scheduler_default` : tâches planifiées déclarées avec `#[AsPeriodicTask]` / `#[AsCronTask]`
  (planning `default` de `src/Schedule.php`) ;
- le processus s'arrête de lui-même au bout d'une heure ou au-delà de 256 Mo, et Docker le relance
  (`restart: unless-stopped`). C'est voulu : la mémoire reste stable.

Commandes utiles :

```bash
dc logs -f worker                                             # suivre l'activité
dc exec app php bin/console messenger:stats                   # messages en attente
dc exec app php bin/console messenger:failed:show             # messages en échec (3 essais)
dc exec app php bin/console messenger:failed:retry            # les rejouer
dc exec app php bin/console debug:scheduler                   # tâches planifiées et prochaine exécution
dc restart worker                                             # redémarrer le worker
```

Un seul conteneur `worker` doit consommer `scheduler_default` : ne le multiplie pas avec
`--scale`, les tâches planifiées seraient exécutées en double.

## 12. Exploitation courante

```bash
dc ps                                             # état des services
dc logs -f app                                    # journaux web (JSON) et erreurs Symfony
dc exec app php bin/console about                 # version, environnement
dc exec database sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"'   # console SQL
df -h && docker system df                         # espace disque
```

Dépannage :

| Symptôme | Piste |
| --- | --- |
| `app` redémarre avec « APP_SECRET is not set » | variable vide dans `.env.prod` |
| `app` bloqué sur « waiting for the database » | `POSTGRES_PASSWORD` modifié après la création du volume, ou caractères spéciaux dans le mot de passe |
| pas de certificat HTTPS | DNS pas encore propagé, ports 80/443 fermés ; voir `dc logs app` |
| e-mails non reçus | `dc logs worker`, `messenger:failed:show`, domaine non authentifié chez Brevo |
| webhook Stripe en erreur 400 | `STRIPE_WEBHOOK_SECRET` ne correspond pas à l'endpoint déclaré |
| `app` refuse de démarrer, « LEGAL_… still holds an example value » | identité de l'éditeur ou de l'hébergeur non renseignée dans `.env.prod` |
| un producteur reste « Vérification en attente » | API Recherche d'entreprises injoignable ; `app:shops:verify` |

Pense aussi à garder le système à jour (`apt upgrade`, ou `unattended-upgrades`) et à surveiller
la disponibilité du site avec un outil externe.
