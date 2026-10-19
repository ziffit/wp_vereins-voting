---
description: E2E-Kette (16 Schritte) gegen frische DB ausführen
---

Führt den E2E-Läufer aus `_verify/` gegen eine Wegwerf-Datenbank aus:

!`docker network create vv-tests >/dev/null 2>&1 || true; docker rm -f vv-db >/dev/null 2>&1 || true; docker run -d --name vv-db --network vv-tests -e MARIADB_ROOT_PASSWORD=vvroot -e MARIADB_DATABASE=vvtest -e MARIADB_USER=vv -e MARIADB_PASSWORD=vvpw mariadb:10.6 >/dev/null; for i in $(seq 1 40); do docker exec vv-db mariadb -uvv -pvvpw vvtest -e "SELECT 1" >/dev/null 2>&1 && break; sleep 1; done`

!`for s in activate schema create_poll import_csv send_invites activate_pin vote_not_yet vote_ok vote_double vote_guard_tamper locked_edit close_and_results results_running results_running_hidden delete_poll smoke; do docker run --rm --network vv-tests -v "$(pwd)":/proj wordpress:latest php /proj/_verify/runner.php $s vv-db >/dev/null 2>&1 && echo "e2e OK: $s" || echo "e2e FAIL: $s"; done`

!`docker rm -f vv-db >/dev/null 2>&1; docker network rm vv-tests >/dev/null 2>&1 || true`

Bewerte: Es darf kein Schritt FAIL zeigen. Bei FAIL den betroffenen Schritt
mit sichtbarer Ausgabe wiederholen (`php /proj/_verify/runner.php <schritt> vv-db`
im Container), Ursache beheben (Plugin oder Shim), dann die Kette erneut.
Beim Erfolg: kurze Zusammenfassung der grünen Schritte.
