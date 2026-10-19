# Modul 2: WordPress Admin-Backend (BE)

## [FU002] Verwaltung von Abstimmungen (CRUD)

### Beschreibung

Admins können im Backend neue Abstimmungen anlegen, bearbeiten, einsehen und
löschen.

### Akzeptanzkriterien

- **[FU002#AK01]** Der Admin kann eine neue Abstimmung mit Titel,
  Beschreibung/Frage, Start-Datum/Uhrzeit und End-Datum/Uhrzeit anlegen.

- **[FU002#AK02]** Bestehende Abstimmungen können bearbeitet werden, solange
  sie den Status `draft` haben. Bei aktiven oder geschlossenen Abstimmungen
  sind sicherheitsrelevante Felder gesperrt.

## [FU003] Mitglieder-CSV-Import & Token-Generierung

### Beschreibung

Import einer Mitgliederliste per CSV-Upload pro Abstimmung.

### Akzeptanzkriterien

- **[FU003#AK01]** Der Admin kann eine CSV-Datei mit den Spalten
  *Mitgliedsnummer* und *E-Mail* hochladen.

- **[FU003#AK02]** Das System validiert die E-Mail-Adressen, verhindert
  Duplikate pro Abstimmung und generiert für jeden Datensatz einen
  eindeutigen, sicheren `activation_token`.

## [FU004] E-Mail-Versand-Tool (Einladungen)

### Beschreibung

Massenversand der Einladungs-E-Mails an alle importierten Wähler einer
Abstimmung.

### Akzeptanzkriterien

- **[FU004#AK01]** Im Admin-Bereich gibt es einen „Einladungen versenden“-
  Button je Abstimmung.

- **[FU004#AK02]** Das System versendet über `wp_mail()` individualisierte
  E-Mails mit einem Aktivierungslink, der den `activation_token` als
  URL-Parameter enthält.

## [FU005] Admin-Audit- & Beteiligungs-Übersicht

### Beschreibung

Transparenz im Backend über die Wahlbeteiligung ohne Verletzung des
Stimmgeheimnisses.

### Akzeptanzkriterien

- **[FU005#AK01]** Der Admin sieht eine Liste aller importierten Mitglieder
  mit Status (*Hat gewählt: Ja/Nein* und *Gewählt am: [Zeitstempel]*).

- **[FU005#AK02]** Es ist technisch unmöglich, im Backend einzusehen, welche
  Option (ja, nein, enthaltung) ein spezifisches Mitglied gewählt hat.

- **[FU005#AK03]** Der Admin sieht nach Ablauf der Wahl die aggregierten
  Gesamtergebnisse (Anzahl Ja, Nein, Enthaltung).
