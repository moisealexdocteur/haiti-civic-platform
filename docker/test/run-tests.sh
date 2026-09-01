#!/bin/sh
set -eu

: "${TEST_DATABASE:?TEST_DATABASE absent}"
: "${DB_USERNAME:?DB_USERNAME absent}"
: "${DB_PASSWORD:?DB_PASSWORD absent}"

echo "== Migration de la base de test =="

env \
  CI_ENVIRONMENT=development \
  'database.default.hostname=db' \
  "database.default.database=${TEST_DATABASE}" \
  "database.default.username=${DB_USERNAME}" \
  "database.default.password=${DB_PASSWORD}" \
  'database.default.DBDriver=MySQLi' \
  'database.default.port=3306' \
  php spark migrate

echo
echo "== PHPUnit =="

exec php vendor/bin/phpunit "$@"
