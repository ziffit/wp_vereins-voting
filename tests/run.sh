#!/usr/bin/env bash
# Läuft die Unit-Tests in einer Wegwerf-Docker-Umgebung (mariadb + PHP).
# Voraussetzung: docker + die Images mariadb:10.6 / wordpress:latest (bereits gezogen).
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NET=vv-tests
DB=vv-db
RCODE=0
KEEP=0
[ "${1:-}" = "--keep" ] && KEEP=1

cleanup() {
	if [ "$KEEP" -eq 0 ]; then
		docker rm -f "$DB" >/dev/null 2>&1
		docker network rm "$NET" >/dev/null 2>&1
	fi
}

docker network create "$NET" >/dev/null 2>&1 || true
docker rm -f "$DB" >/dev/null 2>&1 || true

docker run -d --name "$DB" --network "$NET" \
	-e MARIADB_ROOT_PASSWORD=vvroot \
	-e MARIADB_DATABASE=vvtest \
	-e MARIADB_USER=vv \
	-e MARIADB_PASSWORD=vvpw \
	mariadb:10.6 >/dev/null

# Auf MariaDB warten:
for i in $(seq 1 60); do
	docker exec "$DB" mariadb -uvv -pvvpw vvtest -e "SELECT 1" >/dev/null 2>&1 && break
	sleep 1
done

# wordpress:latest enthält PHP CLI inklusive pdo_mysql — als PHP-Runner nutzen.
docker run --rm --network "$NET" -v "$ROOT":/proj -w /proj --entrypoint php wordpress:latest \
	/proj/tests/run.php vv-db vv vvpw vvtest || RCODE=1

cleanup
exit "$RCODE"
