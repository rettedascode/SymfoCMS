#!/bin/sh
# Prepares the development container on every start: dependencies, database
# schema and, on the very first start, the demo content.
set -e

composer install --no-interaction --no-progress --prefer-dist

php bin/console doctrine:migrations:migrate --no-interaction

# Load the demo content only into an empty database, never over existing data.
if php bin/console doctrine:query:dql 'SELECT COUNT(u.id) FROM App\Entity\User u' | grep -q 'int(0)'; then
    echo "Empty database: loading demo content ..."
    php bin/console doctrine:fixtures:load --no-interaction --append
fi

echo ""
echo "SymfoCMS is running at http://localhost:${APP_PORT:-8000}"
echo "Log in at /login with admin@example.com / demo-password"
echo ""

exec "$@"
