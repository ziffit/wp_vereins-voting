# Unit-Tests für vereins-voting

Unit-Tests gegen die Plugin-Logik. Jeder Test ist zustandslos: vor jedem Test
wird das Schema gedroppt und über den Plugin-Aktivierungshook ([FU001])
neu aufgebaut `vv_db_reset()` in `_bootstrap.php`.

## Ausführen

```bash
bash tests/run.sh          # Docker-Wegwerf-Umgebung, Ausgabe + Exit-Code
bash tests/run.sh --keep   # Container nach dem Lauf behalten (Debug)
```

Voraussetzungen: `docker` plus die Images `mariadb:10.6` und
`wordpress:latest` (bietet das PHP-CLI mit `mysqli`).

## Struktur

| Datei | Inhalt |
|-------|--------|
| `run.php` | Läufer: sammelt `test_*.php`, führt mit Schema-Reset aus, Zusammenfassung + Exit-Code |
| `_bootstrap.php` | Lädt den WP-Shim (aus `_verify/shim.php`), bootet das Plugin, Schema-Reset |
| `helpers.php` | `vv_check` (werfendes Check), Fixtures (Poll/Voter/Ballot/CSV-Upload), HTTP-Simulation |
| `test_01_fu001_db.php` | Tabellen, Spalten, kein FK, UNIQUE Receipt, Datenbleibensrichtlinie |
| `test_02_fu002_crud.php` | Anlegen/Bearbeiten/Statuswechsel/Löschen (draft-only-Latch) |
| `test_03_fu003_csv.php` | Import, Trennzeichen-Erkennung, Duplikatsperre, Tokens |
| `test_04_fu004_mail.php` | Einladungsversand, Slots-Fallback, nur ausstehende Empfänger |
| `test_05_fu005_results.php` | `vv_get_results` Zählung, Backend-Ansicht anonym |
| `test_06_fu007_activation.php` | PIN-Registrierung: Guards, Hash, Token-Verbrauch |
| `test_07_fu008_voting.php` | Guards, auto-active, Transaktion, Doppelvoting |
| `test_08_fu009_frontend.php` | Shortcodes' Ergebnisse + Formular-Felder |

Test-Namen spiegeln die Akzeptanzkriterien der Spezifikation in `spec/`:
`test_XX_fuYYY_<kriterium>`. Assertions drucken `VV-OK: [...]` mit
Spec-Trace-IDs.

## Hinweise

- E2E-Szenariotest bleibt in `_verify/runner.php` (sequenzieller Ablauf
  draft → active → closed), unabhängig von diesen Units.
- Die Tests TERFERen direkt gegen die Modulfunktionen (`vv_save_poll`,
  `vv_cast_vote`, `vv_shortcode_*`, …); der `wpDA`-Ersatz ist im Shim.
