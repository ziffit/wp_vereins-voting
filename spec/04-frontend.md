# Modul 4: Frontend-Workflows & Shortcodes (FE)

## [FU007] Shortcode 1: `[verein_voting_activate]` (PIN-Registrierung)

### Beschreibung

Seite, über die das Mitglied nach Klick auf den E-Mail-Link seinen
persönlichen Wahl-PIN festlegt.

### Akzeptanzkriterien

- **[FU007#AK01]** Beim Aufrufen mit gültigem `token` und passender `poll_id`
  öffnet sich ein Registrierungsformular (Felder: Mitgliedsnummer, E-Mail,
  Neuer Wahl-PIN [6-stellig], PIN bestätigen).

- **[FU007#AK02]** Das System prüft, ob die eingegebenen Daten mit dem
  `wp_voting_voters`-Datensatz übereinstimmen.

- **[FU007#AK03]** Nach erfolgreicher Validierung wird der PIN als
  `pin_hash` (`password_hash()`) gespeichert, der `activation_token`
  gelöscht (Einmalverwendung) und eine Erfolgsmeldung ausgegeben.

- **[FU007#AK04]** Das Formular ist durch ein Nonce gegen CSRF geschützt;
  nach 5 Fehlversuchen (falsche Mitgliedsdaten oder PIN) ist die Aktivierung
  für 15 Minuten gesperrt.

- **[FU007#AK05]** Jede Ausgabe der drei Shortcodes `[verein_voting_activate]`,
  `[verein_voting_form]` und `[verein_voting_results]` endet mit einer
  Versionszeile `vereins-voting X.Y.Z` (CSS-Klasse `vv-version`), damit auf
  jeder Seite sichtbar ist, welche Plugin-Version ausliefert.

## [FU008] Shortcode 2: `[verein_voting_form]` (Die Stimmabgabe)

### Beschreibung

Das eigentliche Wahlformular während des aktiven Wahlzeitraums.

### Akzeptanzkriterien

- **[FU008#AK01]** Das Formular fragt Mitgliedsnummer, E-Mail, den
  persönlichen Wahl-PIN und die Stimmoption (Radio-Buttons: Ja / Nein /
  Enthaltung) ab.

- **[FU008#AK02]** Das System validiert bei der Absendung:

  1. Liegt das aktuelle Datum im Zeitraum `start_date` bis `end_date`?
  2. Existiert die Kombination aus Mitgliedsnummer und E-Mail?
  3. Hat das Mitglied `has_voted == 0`?
  4. Ist der eingegebene PIN korrekt (`password_verify()`)?

- **[FU008#AK03]** Bei erfolgreicher Validierung läuft eine Transaktion
  (`START TRANSACTION`):

  1. Setze `has_voted = 1` und `voted_at = NOW()` in `wp_voting_voters`.
  2. Generiere einen anonymen `receipt_hash` (sha256).
  3. Speichere nur `poll_id`, `vote_option` und `receipt_hash` in
     `wp_voting_ballots`. Die Urne führt bewusst **keinen Zeitstempel und
     keine laufende Nummer** (Primärkey = `receipt_hash`): `voted_at` in der
     Wählerliste und die Urnenzeile sind damit weder über einen Zeitpunkt
     noch über die Einwurf-Reihenfolge einer Person zuzuordnen.

- **[FU008#AK04]** Nach erfolgreicher Stimmabgabe wird dem Wähler der
  anonyme `receipt_hash` auf dem Bildschirm angezeigt mit dem Hinweis, die
  ersten 5 Stellen des Codes zu notieren – sie reichen normalerweise aus,
  um die Stimme in der Prüfliste eindeutig wiederzufinden. Das Formular
  wird ausgeblendet.

- **[FU008#AK05]** Das Stimmformular ist durch ein Nonce gegen CSRF
  geschützt; nach 5 falschen PIN-Eingaben ist die Stimmabgabe für diese
  Person 15 Minuten gesperrt (Brute-Force-Schutz).

- **[FU008#AK06]** Das Stimmformular erscheint nur innerhalb des offenen
  Zeitraums einer nicht geschlossenen Abstimmung. Vor dem Start und nach dem
  Ende werden keine Eingabefelder ausgegeben; stattdessen erscheint eine
  Meldung mit dem Wahlzeitraum („noch nicht aktiv“ bzw. „beendet“).

## [FU009] Shortcode 3: `[verein_voting_results]` (Ergebnisse & Prüfliste)

### Beschreibung

Nach Ablauf der Wahl zeigt diese Komponente das offizielle Ergebnis und die
Prüftabelle.

### Akzeptanzkriterien

- **[FU009#AK01]** Wenn die Abstimmung noch läuft (`status = active`), wird
  ein Hinweis angezeigt, dass die Wahl noch im Gange ist.

- **[FU009#AK02]** Ist die Abstimmung beendet (`status = closed`), zeigt das
  Frontend das offizielle Gesamtergebnis (Stimmenverteilung in Prozent und
  absoluten Zahlen).

- **[FU009#AK03]** Es wird eine vollständige, anonyme Prüftabelle aller in
  der Urne befindlichen `receipt_hash`-Werte samt ihrer Stimmabgabe
  angezeigt. Jedes Mitglied kann hier seinen eigenen Hash suchen und
  verifizieren, dass seine Stimme korrekt gezählt wurde. Die ersten fünf
  Zeichen jedes Prüfcodes werden fett hervorgehoben; sie reichen
  normalerweise aus, um eine Stimme bei bis zu einigen Tausend Wählern
  eindeutig wiederzufinden. Die Prüfliste zeigt ausschließlich Prüfcode und
  gewählte Option – **ohne Uhrzeit und in der Reihenfolge des Prüfcodes**
  statt nach Einwurfzeit; sie erlaubt keine Rückschlüsse auf den Zeitpunkt
  einer Stimme und damit keine Korrelation mit „Gewählt am“ in der
  Teilnehmerliste.
