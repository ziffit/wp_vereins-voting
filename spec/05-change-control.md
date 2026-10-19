# Änderungshistorie (Change Control)

Keine Änderung der Funktionalität ohne Aktualisierung dieses Dokuments.

| Version | Status | Inhalt |
|---------|--------|--------|
| 1.0 | Abgelöst | Grundkonzept mit getrennten Tabellen, Self-Service-PIN per E-Mail-Link, Receipt-Hash-Prüfung und minimalem Theme-CSS. |
| 1.1 | Abgelöst | Datetime-Normalisierung, Zeitzonen-Hinweis, Aktivierung nur im Zeitraum, CSV-BOM-Behandlung, Einladungs-Vorlage persistent, Teilnehmerliste paginiert, Ajax-freie Übersicht in einer Abfrage, CSRF-Nonces und Rate-Limit für Frontend-Formulare. |
| 1.1.1 | Abgelöst | Bugfix Admin-Handler (fremde Admin-POSTs werden nicht mehr am Nonce-Check blockiert), Abbrechen-Links in allen Backend-Masken, Bestätigungen für Speichern/Löschen/Massenversand, Prüfung Zeitraum und Titellänge. |
| 1.1.2 | Aktuell | Eingabefehler der Abstimmungsmaske (Pflichtfelder, Ende vor Start) erscheinen als Meldung in der Maske; die Eingaben bleiben erhalten statt vollflächigem `wp_die()`. |
