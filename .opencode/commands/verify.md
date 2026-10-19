---
description: Plugin linten + Unit-Tests laufen lassen
---

Führe die Verifikations-Pipeline des Projekts aus (Lint + Unit-Tests):

!`docker run --rm -v "$(pwd)":/proj wordpress:latest sh -c 'php -l /proj/vereins-voting/vereins-voting.php && php -l /proj/vereins-voting/includes/admin.php && php -l /proj/vereins-voting/includes/common.php && php -l /proj/vereins-voting/includes/frontend.php'`

!`bash tests/run.sh`

Bewerte das Ergebnis: Wenn Lint oder Tests fehlschlagen, behebe die Ursache
(im Plugin oder in den Tests) und starte die Pipeline erneut. Bei Erfolg:
kurze Zusammenfassung — pro Modul ein Gründon, Auffälligkeiten nennen.
