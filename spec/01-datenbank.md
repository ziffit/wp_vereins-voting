# Modul 1: Datenbank & Aktivierung (DB)

## [FU001] Datenbankschema & Tabellen-Setup

### Beschreibung

Bei der Plugin-Aktivierung legt das System drei isolierte Tabellen an:
`wp_voting_polls` (Abstimmungen), `wp_voting_voters` (Berechtigte &
PIN-Hashes) und `wp_voting_ballots` (anonyme Stimmen).

### Akzeptanzkriterien

- **[FU001#AK01]** Bei der Aktivierung des Plugins werden die Tabellen
  `wp_voting_polls`, `wp_voting_voters` und `wp_voting_ballots` mit den
  korrekten Feldtypen und Indizes angelegt. `wp_voting_polls` verwendet als
  Primärschlüssel eine zufällige UUID (`CHAR(36)`), keine aufsteigende Zahl.
  `wp_voting_voters` enthält neben Mitgliedsnummer, E-Mail, Token, PIN-Hash
  und Beteiligungsstatus die optionalen Felder `first_name` und `last_name`
  (je VARCHAR(100), NULL) für die persönliche Ansprache. Eindeutig ist die
  Kombination `(poll_id, member_no, email)`; die E-Mail-Adresse allein ist
  nicht eindeutig (Ehepaare können sie teilen).

- **[FU001#AK02]** Bei der Deaktivierung oder Deinstallation bleiben die
  Daten erhalten (oder werden gemäß Einstellungen behandelt; Standard:
  Daten behalten zum Schutz der Wahlergebnisse).

- **[FU001#AK03]** Plugin-Updates ziehen fehlende Tabellen und Spalten
  additiv nach (`dbDelta`, Option `vv_db_version`); bestehende Zeilen und
  Wahlergebnisse bleiben dabei unverändert erhalten. Numerische Poll-IDs aus
  Versionen vor 1.3 werden auf UUIDs umgestellt und in Wählern und Urne
  mitgeführt.
