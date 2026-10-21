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
  sind sicherheitsrelevante Felder gesperrt. Beim formellen Abschluss
  (`active → closed`) wird der Abgabefingerprint erzeugt und in
  `urn_fingerprint` festgehalten – SHA-256 über alle Prüfcodes in
  aufsteigender Sortierung und die Option-Zählungen (ja, nein, Enthaltung);
  das Rezept ist auf der Ergebnisseite dokumentiert. Die Registerkarte
  „Wahlergebnis“ berechnet den Wert bei jeder Ansicht aus der Urne nach und meldet
  Abweichungen deutlich sichtbar („Abgabefingerprint abweichend“), da jede
  nachträgliche Urnen-Änderung den Wert verändert. Ohne formellen Abschluss
  (bspw. automatisches Auslaufen) gibt es keinen Vergleichswert. Hat die
  Abstimmung den Status `closed`, zeigt die Bearbeiten-Maske keine
  Speichern-/Abbrechen-Aktionen mehr, sondern nur „Zurück“ – es ist keine
  Aktion mehr möglich; entfällt auch in der Teilnehmeransicht der
  CSV-Import sowie das Einladungsformular.

- **[FU002#AK03]** Der Statuswechsel `draft → active` ist nur innerhalb des
  Zeitraums möglich; außerhalb wird er verworfen und die Abstimmung startet
  automatisch beim ersten gültigen Votum im Zeitraum.

- **[FU002#AK04]** Ist in WordPress keine Zeitzone gesetzt (UTC ohne
  `timezone_string`/`gmt_offset`), erscheint im Backend ein Warnhinweis,
  damit Wahlzeiten nicht unbemerkt verschoben werden.

- **[FU002#AK05]** Alle Backend-Masken (Abstimmung, CSV-Import,
  Einladungsversand, E-Mail-Einstellungen) bieten neben der Hauptaktion einen
  Abbrechen-Link ohne Datenänderung; nach dem Speichern bzw. Löschen erscheint
  eine Bestätigungsmeldung. Die Detailansicht einer Abstimmung ist in vier
  Registerkarten gegliedert – **Wahl** (Titel, Frage, Zeitraum, Status,
  vorzeitiges Beenden, Löschen im Entwurf), **Teilnehmer** (CSV-Import,
  Liste mit PIN-Reset, Räumung der Teilnehmerdaten), **E-Mail**
  (Einladungsversand mit Textblock und Website-URL, Vorschau, Testmail,
  Versandstatus) und **Wahlergebnis** (Gesamtergebnis, Abgabefingerprint,
  Wahlniederschrift).
  Es werden nur mögliche Aktionen angeboten; nicht mögliche erscheinen als
  Hinweistext (z. B. „Die Wahl läuft bereits – es können keine Mitglieder
  mehr hinzugefügt werden.“). Die Übersicht verlinkt je Abstimmung nur noch
  „Details“. Eingabefehler der Abstimmungsmaske (fehlende
  Pflichtfelder, Ende vor dem Start) werden als Meldung in der Maske
  angezeigt; die eingegebenen Werte bleiben erhalten und die Admin-Seite
  wird nicht durch `wp_die()` ersetzt. Fehler beim Speichern von E-Mail-Texten
  werden ebenfalls als Meldung angezeigt; die eingegebenen Werte bleiben
  erhalten.

- **[FU002#AK06]** Der Admin-Handler verarbeitet ausschließlich POSTs mit der
  plugin-eigenen Aktion `vv_action`. Fremde Admin-Anfragen (z. B. Heartbeat
  oder andere Plugins) bleiben unberührt; eigene Aktionen erfordern weiterhin
  Nonce und `manage_options`.

- **[FU002#AK07]** Jede Abstimmung erhält eine zufällige UUID v4 als ID
  (`CHAR(36)`, keine AUTO_INCREMENT-Zahl). Links und Formulare enthalten
  damit keine erratbaren, fortlaufenden IDs.

- **[FU002#AK08]** Bei beendeten Abstimmungen kann der Administrator die
  Teilnehmerdaten löschen: Wählerzeilen (Mitgliedsnummer, Name, E-Mail,
  Token, PIN-Hash, alle Zeitstempel) werden entfernt, während Urne,
  Ergebnis und Prüfliste vollständig erhalten bleiben. Die Aktion ist nur
  im Status `closed` möglich und erfordert Nonce und Bestätigungsdialog mit
  dem Hinweis, die Wahlniederschrift (FU005#AK05) vorher zu erstellen und
  zu archivieren. Existieren keine Teilnehmerdaten mehr (bereits gelöscht
  oder nicht importiert), erscheint statt der Schaltfläche die Meldung
  „Keine Teilnehmerdaten mehr vorhanden“. Es bleiben keine Kopien oder
  Papierkorbeinträge zurück;
  die Gesamtlöschen-Regel (nur im Status `draft`) und FU001#AK02 bleiben
  unberührt.

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

Gedrosselter Massenversand der Einladungs-E-Mails an alle importierten Wähler
einer Abstimmung. Die Gestaltung und die globalen Texte gelten für alle
Abstimmungen; je Abstimmung gibt es nur einen Textblock und einen optionalen
Website-Link.

### Akzeptanzkriterien

- **[FU004#AK01]** Im Admin-Bereich gibt es je Abstimmung eine
  Einladungsseite mit „Einladungen versenden“-Button. Vor dem Start zeigt eine
  Bestätigungsabfrage die Zahl der offenen Empfänger (Aktivierungstoken
  vorhanden, noch nicht gesendet, weniger als drei Fehlversuche). Die Seite
  zeigt außerdem offene, gesendete und endgültig fehlgeschlagene Einladungen
  sowie die globalen Einstellungen unter
  `Vereinsvoting → E-Mail-Einstellungen`. Geschlossene oder abgelaufene
  Abstimmungen erhalten keinen Versand-Button.

- **[FU004#AK02]** Die globalen E-Mail-Einstellungen enthalten Betreff,
  Einleitung/Anrede, Footer, URL der Aktivierungsseite, URL der
  Stimmformular-Seite, URL der Ergebnisseite und Versandrate
  (gesetzte URLs müssen http(s)-Adressen mit Host sein).
  Footer, Aktivierungs-, Stimmformular- und Ergebnis-URL sind Pflicht;
  fehlt eine der Angaben, wird das Speichern abgewiesen und nicht
  versendet. Die Rate liegt zwischen 1 und 500 Mails pro 5 Minuten (Standard
  10) und gilt global über alle Abstimmungen. Das HTML-Layout ist fest im
  Plugin; ein Roh-HTML-Feld gibt es nicht. Betreff, Einleitung, Footer und
  der Text je Abstimmung werden vor dem Speichern auf unbekannte Platzhalter
  geprüft. Der bisherige Betreff `vv_invite_subject` dient als Fallback; der
  bisherige Text `vv_invite_body` bleibt in der Datenbank, wird aber nicht
  automatisch übernommen.

- **[FU004#AK03]** Je Abstimmung lassen sich ein Textblock und optional eine
  Website-URL speichern. Der Textblock darf die dokumentierten Platzhalter
  verwenden; die URL muss `http` oder `https` sein. Die Mail führt durch die
  Schritte des Ablaufs: Wahl-PIN setzen (Aktivierungsbutton, immer), im
  Wahlzeitraum abstimmen (Button zur Stimmformular-Seite), nach Wahlende
  Ergebnisse prüfen (Link zur Ergebnisseite). Der
  Website-Link erscheint optional als Hinweis „Mehr zum Ablauf der Wahl und
  der eingesetzten Software“. Der Textblock ersetzt
  nicht die globale Einleitung oder den Footer.

- **[FU004#AK04]** Der Versand läuft in Batches über `wp_mail()` und WP-Cron.
  Ein globales 5-Minuten-Fenster begrenzt die Versuche; ein Lock verhindert
  parallele Läufe und verfällt nach 10 Minuten. Vor dem Batch wird der nächste
  Lauf geplant. Erfolgreiche Mails erhalten `invited_at` und werden nie erneut
  gesendet. Pausieren stoppt den nächsten Batch; der laufende Batch wird
  beendet. Fortsetzen plant sofort einen neuen Batch. Ist `DISABLE_WP_CRON`
  aktiv, weist die Oberfläche darauf hin; der Admin kann Batches manuell über
  „Weitere Einladungen jetzt senden“ auslösen.

- **[FU004#AK05]** Schlägt `wp_mail()` fehl, wird `invite_attempts` erhöht.
  Nach drei Fehlversuchen stoppt der automatische Versand; ein manueller
  Retry setzt den Zähler der fehlgeschlagenen Zeilen auf 0 und plant sie
  erneut ein. Bereits erfolgreich gesendete Zeilen bleiben unberührt. Ein
  PIN-Reset löscht `invited_at` und `invite_attempts`, damit die Person über
  einen erneuten Start wieder eingeladen wird.

## [FU005] Admin-Audit- & Mitglieder-Übersicht

### Beschreibung

Transparenz im Backend über die Wahlbeteiligung ohne Verletzung des
Stimmgeheimnisses.

### Akzeptanzkriterien

- **[FU005#AK01]** Der Admin sieht eine Liste aller importierten Mitglieder
  (Mitgliedsnummer, Name aus Vor-/Nachname, E-Mail) mit Status
  (*Hat gewählt: Ja/Nein* und *Gewählt am: [Zeitstempel]*); fehlt der Name,
  steht dort „–“. Die Liste trägt die Überschrift „Stimmberechtigte
  Mitglieder“ und wird seitenweise angezeigt (100 Einträge pro Seite). Sie
  zeigt je Person, ob eine PIN gesetzt ist (Ja/Nein) und ob die Einladung
  offen, gesendet oder fehlgeschlagen ist. Die Abstimmungsübersicht zeigt die
  UUID je Abstimmung, den Titel ohne Verlinkung sowie Teilnehmer- und
  Stimmenzahl und öffnet per „Details“ die Registerkarten-Ansicht
  (FU002#AK05).

- **[FU005#AK02]** Es ist technisch unmöglich, im Backend einzusehen, welche
  Option (ja, nein, enthaltung) ein spezifisches Mitglied gewählt hat.

- **[FU005#AK03]** Der Admin sieht nach Ablauf der Wahl die aggregierten
  Gesamtergebnisse (Anzahl Ja, Nein, Enthaltung) in der Registerkarte
  „Wahlergebnis“; vor dem Wahlende zeigt die Registerkarte einen Hinweis
  statt einer Tabelle.

- **[FU005#AK04]** Der Admin kann eine gesetzte PIN vor der Stimmabgabe
  zurücksetzen (z. B. bei vergessener PIN). Dabei werden nur der PIN-Hash
  gelöscht und ein neuer `activation_token` erzeugt; außerdem werden
  `invited_at` und `invite_attempts` zurückgesetzt, damit die Person über
  einen erneuten Versandstart wieder eingeladen wird. Die Urne und das
  Wahlgeheimnis bleiben unberührt. Nach der Stimmabgabe und bei geschlossenen
  Abstimmungen ist der Reset nicht möglich; eine bestehende Fehlversuchssperre
  wird aufgehoben.

- **[FU005#AK05]** Der Administrator kann zu jeder Abstimmung eine
  Wahlniederschrift als druckbare Seite erzeugen (Standalone-HTML ohne
  Backend-Design, im Browser über „Drucken“ als PDF speicherbar; erreichbar
  nur mit `manage_options` und Nonce). Sie enthält: Titel, Frage,
  Wahlzeitraum, Status, Erstellungszeitpunkt, das Gesamtergebnis je Option
  (absolute Zahlen und Anteile), den Abgabefingerprint samt Rezept und
  Vergleich zur aktuellen Urne, die vollständige Prüfliste (Prüfcode,
  gewählte Option, geordnet nach Prüfcode) sowie eine Teilnahmetabelle je
  Person mit Mitgliedsnummer, Name, E-Mail und den Zeitpunkten Einladung
  (`invited_at`), PIN-Registrierung (`pin_set_at`) und Stimmabgabe
  (`voted_at`). Vor dem Löschen der Teilnehmerdaten (FU002#AK08) dient sie
  als Archiv; danach zeigt sie Ergebnis und Prüfliste weiterhin und
  kennzeichnet fehlende Teilnehmerzeiten mit „Keine Teilnehmerzeiten
  vorhanden“.
