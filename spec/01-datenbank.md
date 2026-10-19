# Modul 1: Datenbank & Aktivierung (DB)

## [FU001] Datenbankschema & Tabellen-Setup

### Beschreibung

Bei der Plugin-Aktivierung legt das System drei isolierte Tabellen an:
`wp_voting_polls` (Abstimmungen), `wp_voting_voters` (Berechtigte &
PIN-Hashes) und `wp_voting_ballots` (anonyme Stimmen).

### Akzeptanzkriterien

- **[FU001#AK01]** Bei der Aktivierung des Plugins werden die Tabellen
  `wp_voting_polls`, `wp_voting_voters` und `wp_voting_ballots` mit den
  korrekten Feldtypen und Indizes angelegt.

- **[FU001#AK02]** Bei der Deaktivierung oder Deinstallation bleiben die
  Daten erhalten (oder werden gemäß Einstellungen behandelt; Standard:
  Daten behalten zum Schutz der Wahlergebnisse).
