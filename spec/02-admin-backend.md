# Modul 2: WordPress Admin-Backend (BE)

## [FU002] Verwaltung von Abstimmungen (CRUD)

### Beschreibung

Admins können im Backend neue Abstimmungen anlegen, bearbeiten, einsehen und
löschen.

### Akzeptanzkriterien

- **[FU002#AK01]** Der Admin kann eine neue Abstimmung mit Titel,
  Beschreibung/Frage, Start-Datum/Uhrzeit und End-Datum/Uhrzeit anlegen.
  Beim Speichern werden Pflichtfelder und Zeitraum geprüft (Ende muss nach dem
  Start liegen); zu lange Titel werden auf 190 Zeichen gekürzt. Start und Ende
  liegen immer auf vollen Stunden (z. B. 18:00); Zeiten mit Minuten werden
  mit einer Meldung in der Maske abgewiesen.

- **[FU002#AK02]** Bestehende Abstimmungen können bearbeitet werden, solange
  sie den Status `draft` haben. Bei aktiven oder geschlossenen Abstimmungen
  sind sicherheitsrelevante Felder gesperrt.

- **[FU002#AK03]** Der Statuswechsel `draft → active` ist nur innerhalb des
  Zeitraums möglich; außerhalb wird er verworfen und die Abstimmung startet
  automatisch beim ersten gültigen Votum im Zeitraum.

- **[FU002#AK04]** Ist in WordPress keine Zeitzone gesetzt (UTC ohne
  `timezone_string`/`gmt_offset`), erscheint im Backend ein Warnhinweis,
  damit Wahlzeiten nicht unbemerkt verschoben werden.

- **[FU002#AK05]** Alle Backend-Masken (Abstimmung, CSV-Import,
  Einladungsversand) bieten neben der Hauptaktion einen Abbrechen-Link ohne
  Datenänderung; nach dem Speichern bzw. Löschen erscheint eine
  Bestätigungsmeldung. Eingabefehler der Abstimmungsmaske (fehlende
  Pflichtfelder, Ende vor dem Start) werden als Meldung in der Maske
  angezeigt; die eingegebenen Werte bleiben erhalten und die Admin-Seite
  wird nicht durch `wp_die()` ersetzt.

- **[FU002#AK06]** Der Admin-Handler verarbeitet ausschließlich POSTs mit der
  plugin-eigenen Aktion `vv_action`. Fremde Admin-Anfragen (z. B. Heartbeat
  oder andere Plugins) bleiben unberührt; eigene Aktionen erfordern weiterhin
  Nonce und `manage_options`.

- **[FU002#AK07]** Jede Abstimmung erhält eine zufällige UUID v4 als ID
  (`CHAR(36)`, keine AUTO_INCREMENT-Zahl). Links und Formulare enthalten
  damit keine erratbaren, fortlaufenden IDs.

## [FU003] Mitglieder-CSV-Import & Token-Generierung

### Beschreibung

Import einer Mitgliederliste per CSV-Upload pro Abstimmung.

### Akzeptanzkriterien

- **[FU003#AK01]** Der Admin kann eine CSV-Datei mit Kopfzeile hochladen.
  Pflichtspalten sind *Mitgliedsnummer*, *E-Mail*, *Vorname* und *Name*
  (alternativ *Nachname*); die Zuordnung erfolgt über die Spaltennamen
  (Reihenfolge frei, Groß-/Kleinschreibung und Leerzeichen egal). Weitere
  Spalten werden ignoriert. Trennzeichen (`,` oder `;`) und ein UTF-8-BOM
  werden automatisch behandelt. Fehlt eine Pflichtspalte, bricht der Import
  mit einer Meldung ab, die die erkannten Spalten nennt.

- **[FU003#AK02]** Das System validiert die E-Mail-Adressen und verhindert
  doppelte Einträge pro Abstimmung: eindeutig ist die Kombination aus
  Mitgliedsnummer und E-Mail (E-Mail case-insensitiv). Dieselbe E-Mail-Adresse
  darf für verschiedene Mitgliedsnummern mehrfach vorkommen (z. B. Ehepaare).
  Für jeden Datensatz wird ein eindeutiger, sicherer `activation_token`
  generiert. Vorname und Nachname werden als `first_name`/`last_name`
  gespeichert (max. 100 Zeichen). Bei einem erneuten Import werden leere
  Namensfelder bestehender Zeilen nachgetragen, ohne vorhandene Werte zu
  überschreiben.

- **[FU003#AK03]** Zeilen ohne Pflichtwert (Mitgliedsnummer, Vorname, Name
  oder gültige E-Mail) werden übersprungen und im Ergebnis als übersprungen
  gezählt; der Import läuft weiter. Leerzeilen zählen nicht als Fehler.

## [FU004] E-Mail-Versand-Tool (Einladungen)

### Beschreibung

Massenversand der Einladungs-E-Mails an alle importierten Wähler einer
Abstimmung.

### Akzeptanzkriterien

- **[FU004#AK01]** Im Admin-Bereich gibt es einen „Einladungen versenden“-
  Button je Abstimmung. Vor dem Versand wird die Anzahl der Empfänger ohne
  PIN in einer Bestätigungsabfrage angezeigt.

- **[FU004#AK02]** Das System versendet über `wp_mail()` individualisierte
  E-Mails mit einem Aktivierungslink, der den `activation_token` als
  URL-Parameter enthält.

- **[FU004#AK03]** Betreff und Text werden beim Versand als Vorlage
  gespeichert und beim nächsten Aufruf wieder vorbelegt.

## [FU005] Admin-Audit- & Mitglieder-Übersicht

### Beschreibung

Transparenz im Backend über die Wahlbeteiligung ohne Verletzung des
Stimmgeheimnisses.

### Akzeptanzkriterien

- **[FU005#AK01]** Der Admin sieht eine Liste aller importierten Mitglieder
  (Mitgliedsnummer, Name aus Vor-/Nachname, E-Mail) mit Status
  (*Hat gewählt: Ja/Nein* und *Gewählt am: [Zeitstempel]*); fehlt der Name,
  steht dort „–“. Die Liste trägt die Überschrift „Stimmberechtigte
  Mitglieder“ und wird seitenweise angezeigt (100 Einträge pro Seite); die
  Abstimmungsübersicht zeigt die UUID je Abstimmung, den Titel ohne
  Verlinkung sowie Teilnehmer- und Stimmenzahl und die Aktionen
  „Bearbeiten“ und „Teilnehmer & Einladung“.

- **[FU005#AK02]** Es ist technisch unmöglich, im Backend einzusehen, welche
  Option (ja, nein, enthaltung) ein spezifisches Mitglied gewählt hat.

- **[FU005#AK03]** Der Admin sieht nach Ablauf der Wahl die aggregierten
  Gesamtergebnisse (Anzahl Ja, Nein, Enthaltung).
