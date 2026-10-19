# AGENTS.md — vereins-voting

WordPress-Plugin „vereins-voting": anonymes Abstimmungssystem für Vereine
(Spezifikation FU001–FU009). Arbeits- und UI-Sprache: **Deutsch**.

## Layout

| Pfad | Inhalt |
|---|---|
| `spec/` | Verbindliche Spezifikation (FU/AK-IDs). Jede Verhaltensänderung **muss** hier aktualisiert werden. |
| `vereins-voting/` | Das Plugin — distributierbar. Enthält **keine** Test- oder Sandbox-Bestandteile. |
| `tests/` | Unit-Tests. `bash tests/run.sh` (Docker-Wegwerf-Umgebung). Vor jeder Übergabe grün. |
| `_verify/` | E2E-Runner + WP-Shim (Test-Sandbox; **nicht** mit dem Plugin ausliefern). |

## Verifikation (vor jeder Übergabe)

1. PHP-Lint jeder geänderten Datei:
   `docker run --rm -v "$(pwd)":/proj wordpress:latest php -l <datei>`
2. `bash tests/run.sh` — Unit-Tests müssen vollständig grün sein.
3. Bei Verhaltensänderungen zusätzlich die E2E-Kette `_verify/runner.php`
   (16 Schritte) gegen eine frische Datenbank ausführen.

## Architektur-Regeln (nur mit gleichzeitiger Spec-Änderung brechbar)

- Drei isolierte Tabellen: `voting_polls`, `voting_voters`, `voting_ballots`.
  **Keine Foreign Keys**, keine Verknüpfung von Identitäts- und Urne-Tabelle.
- PIN nur als `password_hash()` / `password_verify()`, niemals Klartext.
- Stimmabgabe in einer Transaktion mit Zeilensperre; `receipt_hash` = sha256
  (64 hex, UNIQUE). Die Urne speichert **nur** `poll_id`, `vote_option`, `receipt_hash`.
- Statusübergänge: `draft → active → closed`, nur vorwärts. Neue Wahl startet
  als `draft`. Löschen nur im Status `draft`. Deaktivierung/Deinstallation
  löscht **keine** Daten (FU001#AK02).
- Der Admin kann technisch nicht sehen, welche Option eine Person wählte (FU005#AK02).
- CSS: kein Framework, nur minimales semantisches Basis-CSS (`assets/vv.css`).
- CSV-Import: `,`/`;`-Trennzeichen, Kopfzeilen-Erkennung, Duplikatsperre pro Wahl,
  sichere `activation_token`.

## Stil

- Kleinste vollständige Änderung statt Aufrüstung; Löschung vor Erweiterung.
- Keine Features/Flexibilität ohne Anforderung; keine Abhängigkeiten für wenige Zeilen.
- Neue Logik (Branch, Parser, Guards) bekommt einen Unit-Test mit FU/AK-Trace-ID
  ("Spec + Code + Test" immer zusammen).
- Validation an Vertrauensgrenzen niemals entfernen; Fehlerbehandlung erhalten,
  wenn Code umzieht oder verschmilzt.
