---
description: FU/AK-Änderung umsetzen — Spec + Code + Test immer zusammen
---

Umsetzung der Änderung: $ARGUMENTS

Vorgehen (Pflicht):
1. Betroffene FU/AK-IDs in `spec/` identifizieren. Ohne passende ID: vorher mit
   dem Anwender klären (neue Anforderung → neue ID vorschlagen).
2. Reihenfolge einhalten: erst `spec/` aktualisieren (inkl. neuer Versionszeile
   in `spec/05-change-control.md`), dann Code ändern, dann Unit-Test in `tests/`
   mit der Trace-ID im Testnamen ergänzen.
3. Verifikation gemäß AGENTS.md: PHP-Lint + `bash tests/run.sh` grün; bei
   Server-Verhaltensänderungen zusätzlich die E2E-Kette (`/e2e`).
4. Rückgabe: kompakte Zusammenfassung (geänderte FU/AK-IDs, Diff-Umriss,
   Testergebnis).
