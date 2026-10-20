# vereins-voting – Spezifikation

Dieses Verzeichnis enthält die vollständige, präzise und erweiterte funktionale
Spezifikation des WordPress-Plugins **vereins-voting** (Version 1.5.0). Sie ist
in Module aufgeteilt; jede Funktion ist modular in **Funktionalitäten (FU)**
und **messbare Akzeptanzkriterien (AK)** unterteilt. Die IDs (`FUxxx#AKxx`)
des Original-Dokuments bleiben unverändert erhalten und dienen als
Rückverfolgbarkeitstoken zwischen Spezifikation, Code und Unit-Tests.

| Datei | Modul / Inhalt |
|-------|----------------|
| `00-prinzipien.md` | [0] Allgemeine Prinzipien & Architektur |
| `01-datenbank.md` | [FU001] Datenbankschema & Tabellen-Setup |
| `02-admin-backend.md` | [FU002]–[FU005] Admin-Backend, CSV-Import, Versand, Audit |
| `03-mail.md` | [FU006] E-Mail-Templates |
| `04-frontend.md` | [FU007]–[FU009] Shortcodes Frontend-Workflows |
| `05-change-control.md` | Änderungshistorie (Version 1.5.0) |

Verweise auf Implementierung und Tests (Rückverfolgbarkeit):

| Modul | Implementierung |
|-------|-----------------|
| FU001 | `vereins-voting/vereins-voting.php` |
| FU002–FU005 | `vereins-voting/includes/admin.php`, `vereins-voting/includes/common.php` |
| FU004 (Queue) | `vereins-voting/includes/queue.php` |
| FU006 | `vereins-voting/includes/mail.php` |
| FU007–FU009 | `vereins-voting/includes/frontend.php`, `vereins-voting/assets/vv.css` |

Konsolidierter Teststand (ausgeführt Δ, End-to-End): `_verify/runner.php` — Unit-Tests: `tests/`.
