#!/bin/sh
# Entrypoint of the production image:
# - checks the mandatory settings,
# - waits for PostgreSQL,
# - runs the Doctrine migrations when the container is the web server (not the worker).
set -eu

if [ "${1#-}" != "$1" ]; then
    set -- frankenphp run "$@"
fi

needs_app=0
case "$1" in
    frankenphp | php | bin/console) needs_app=1 ;;
esac

# Refuses to start with a missing secret or a value copied from an example: the committed .env files
# are compiled into the image (composer dump-env prod) and must never be what runs in production.
config_errors=0

config_error() {
    echo "entrypoint: $1 (see .env.prod.example and docs/DEPLOY.md)." >&2
    config_errors=$((config_errors + 1))
}

# require NAME [glob that the value must match] : the variable is set, non-empty, and no placeholder.
require() {
    name="$1"
    pattern="${2:-*}"
    eval "value=\${$name:-}"

    if [ -z "$value" ]; then
        config_error "$name is not set"
        return
    fi

    case "$value" in
        $pattern) ;;
        *) config_error "$name does not look like a real value"; return ;;
    esac

    lowered=$(printf '%s' "$value" | tr '[:upper:]' '[:lower:]')
    case "$lowered" in
        *changeme* | *change_me* | *example* | *dummy* | *brevo_api_key* | *username:password* | *'$ecretf0rt3st'* | 2fdee91914a386dd95b0326a8eb201f3 | *renseigner* | */owner/* | *.test | *.test'>' | *.invalid)
            config_error "$name still holds an example value"
            ;;
    esac
}

# optional NAME [glob] : may be empty; otherwise checked like a required value.
optional() {
    eval "optional_value=\${$1:-}"
    if [ -n "$optional_value" ]; then
        require "$@"
    fi
}

# Publisher and host of the platform (legal notice, LCEN art. 6 III): shown on public pages.
check_legal() {
    require LEGAL_PUBLISHER_NAME
    require LEGAL_PUBLISHER_FORM
    require LEGAL_PUBLISHER_ADDRESS
    optional LEGAL_PUBLISHER_SIRET '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]'
    case "${LEGAL_PUBLISHER_SIRET:-}" in
        00000000000000 | 12345678900012) config_error "LEGAL_PUBLISHER_SIRET still holds an example value" ;;
    esac
    require LEGAL_PUBLISHER_PHONE
    require LEGAL_PUBLICATION_DIRECTOR
    require LEGAL_CONTACT_EMAIL '?*@?*.?*'
    require LEGAL_HOST_NAME
    require LEGAL_HOST_ADDRESS
    optional LEGAL_HOST_PHONE
    require LEGAL_SOURCE_CODE_URL 'https://?*'
}

# Settings of a territorial instance (INSTANCE_*, docs/INSTANCE.md): checked by the application itself.
check_instance() {
    if ! instance_output=$(php bin/console app:instance:check --no-ansi 2>&1); then
        printf '%s\n' "$instance_output" >&2
        config_error "an INSTANCE_* setting is not usable"
    fi
}

check_config() {
    require APP_SECRET
    if [ -n "${APP_SECRET:-}" ] && [ "${#APP_SECRET}" -lt 32 ]; then
        config_error "APP_SECRET is too short (openssl rand -hex 32)"
    fi
    require DATABASE_URL
    require MAILER_DSN
    case "${MAILER_DSN:-}" in
        null://* | *127.0.0.1:1025* | *localhost:1025* | *mailpit*) config_error "MAILER_DSN points to a development mailer" ;;
    esac
    require MAILER_FROM
    # A sponsored instance (the carrier pays for every producer, docs/INSTANCE.md) never calls Stripe.
    if [ "${INSTANCE_PRICING:-standard}" != "sponsored" ]; then
        require STRIPE_SECRET_KEY '[sr]k_*'
        require STRIPE_WEBHOOK_SECRET 'whsec_????????????????*'
        require STRIPE_PRICE_MONTHLY 'price_*'
        require STRIPE_PRICE_YEARLY 'price_*'
        require STRIPE_PRODUCT_SOLIDARITY 'prod_*'
    fi
    check_legal
    check_instance

    if [ "$config_errors" -gt 0 ]; then
        echo "entrypoint: $config_errors setting(s) to fix, not starting." >&2
        exit 1
    fi
}

if [ "$needs_app" = 1 ]; then
    check_config

    attempts="${DATABASE_WAIT_ATTEMPTS:-60}"
    echo "entrypoint: waiting for the database..."
    until php bin/console dbal:run-sql --quiet "SELECT 1" >/dev/null 2>&1; do
        attempts=$((attempts - 1))
        if [ "$attempts" -le 0 ]; then
            echo "entrypoint: the database is still unreachable, last error:" >&2
            php bin/console dbal:run-sql "SELECT 1" >&2 || true
            exit 1
        fi
        sleep 1
    done
    echo "entrypoint: database is ready."

    if [ "$1" = "frankenphp" ] && [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
        php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    fi
fi

exec "$@"
