# Änderungshistorie (Change Control)

Keine Änderung der Funktionalität ohne Aktualisierung dieses Dokuments.

| Version | Status | Inhalt |
|---------|--------|--------|
| 1.0 | Abgelöst | Grundkonzept mit getrennten Tabellen, Self-Service-PIN per E-Mail-Link, Receipt-Hash-Prüfung und minimalem Theme-CSS. |
| 1.1 | Abgelöst | Datetime-Normalisierung, Zeitzonen-Hinweis, Aktivierung nur im Zeitraum, CSV-BOM-Behandlung, Einladungs-Vorlage persistent, Teilnehmerliste paginiert, Ajax-freie Übersicht in einer Abfrage, CSRF-Nonces und Rate-Limit für Frontend-Formulare. |
| 1.1.1 | Abgelöst | Bugfix Admin-Handler (fremde Admin-POSTs werden nicht mehr am Nonce-Check blockiert), Abbrechen-Links in allen Backend-Masken, Bestätigungen für Speichern/Löschen/Massenversand, Prüfung Zeitraum und Titellänge. |
| 1.1.2 | Abgelöst | Eingabefehler der Abstimmungsmaske (Pflichtfelder, Ende vor Start) erscheinen als Meldung in der Maske; die Eingaben bleiben erhalten statt vollflächigem `wp_die()`. |
| 1.2 | Abgelöst | Vorname/Nachname je Mitglied (`first_name`/`last_name`, additives Upgrade ohne Datenverlust); CSV-Import ordnet die Pflichtspalten Mitgliedsnummer, E-Mail, Vorname und Name/Nachname über die Kopfzeile zu, ignoriert weitere Spalten und trägt bei Re-Import leere Namen nach; Namensspalte im Backend; `{first_name}`/`{last_name}` in Einladungs-Mails. |
| 1.3 | Aktuell | Poll-IDs sind zufällige UUIDs (`CHAR(36)`, Upgrade stellt alte Zahlen-IDs um); Stimmformular erscheint nur im offenen Zeitraum mit Hinweis auf Start/Ende; Start/Ende nur auf vollen Stunden; eindeutig ist Mitgliedsnummer + E-Mail (E-Mail allein nicht, z. B. Ehepaare); Hersteller Felix Tensing; Überschrift „Stimmberechtigte Mitglieder“; Logging-Verbot für mitglieder-, stimm- und PIN-bezogene Daten in Prinzipien und KI-Regeln verankert. |
