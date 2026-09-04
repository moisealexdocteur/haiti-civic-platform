#!/bin/sh
set -eu

: "${TEST_DATABASE:?TEST_DATABASE absent}"

: "${RUNTIME_DB_USERNAME:?RUNTIME_DB_USERNAME absent}"
: "${RUNTIME_DB_PASSWORD:?RUNTIME_DB_PASSWORD absent}"

: "${MIGRATION_DB_USERNAME:?MIGRATION_DB_USERNAME absent}"
: "${MIGRATION_DB_PASSWORD:?MIGRATION_DB_PASSWORD absent}"

echo "== Migration de la base de test =="

MIGRATE_LOG="$(mktemp)"

cleanup()
{
    rm -f "$MIGRATE_LOG"
}

trap cleanup EXIT HUP INT TERM

set +e

env \
  CI_ENVIRONMENT=development \
  'database.default.hostname=db' \
  "database.default.database=${TEST_DATABASE}" \
  "database.default.username=${MIGRATION_DB_USERNAME}" \
  "database.default.password=${MIGRATION_DB_PASSWORD}" \
  'database.default.DBDriver=MySQLi' \
  'database.default.port=3306' \
  php spark migrate \
  >"$MIGRATE_LOG" 2>&1

MIGRATE_RC=$?

set -e

cat "$MIGRATE_LOG"

if [ "$MIGRATE_RC" -ne 0 ]; then
    echo >&2
    echo "ERREUR : spark migrate a retourné ${MIGRATE_RC}." >&2
    exit 1
fi

if grep -Eq \
    '^\[[^]]*Exception\]' \
    "$MIGRATE_LOG"
then
    echo >&2
    echo "ERREUR : exception pendant les migrations." >&2
    exit 1
fi

echo
echo "== Vérification du registre des migrations =="

EXPECTED_VERSIONS="$(
    find app/Database/Migrations \
      -maxdepth 1 \
      -type f \
      -name '*.php' \
      -print \
    | sed 's#.*/##; s/_.*$//' \
    | sort -u
)"

APPLIED_VERSIONS="$(
    php -r '
        mysqli_report(
            MYSQLI_REPORT_ERROR
            | MYSQLI_REPORT_STRICT
        );

        $db = new mysqli(
            "db",
            getenv("RUNTIME_DB_USERNAME"),
            getenv("RUNTIME_DB_PASSWORD"),
            getenv("TEST_DATABASE"),
            3306
        );

        $result = $db->query(
            "SELECT DISTINCT version
             FROM migrations
             WHERE namespace = '\''App'\''
             ORDER BY version"
        );

        $versions = [];

        while ($row = $result->fetch_assoc()) {
            $versions[] = $row["version"];
        }

        echo implode(PHP_EOL, $versions);
    '
)"

if [ "$EXPECTED_VERSIONS" != "$APPLIED_VERSIONS" ]; then
    echo >&2
    echo "ERREUR : registre des migrations incomplet." >&2
    exit 1
fi

echo "Toutes les migrations App sont enregistrées."

echo
echo "== PHPUnit avec compte runtime limité =="

exec php vendor/bin/phpunit "$@"
