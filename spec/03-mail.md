# Modul 3: E-Mail-Templates (MAIL)

## [FU006] Transaktionale Einladungs-E-Mails

### Beschreibung

Einladungen sind transaktionale HTML-Mails mit einem festen Layout, globalen
Texten und einem persönlichen Aktivierungslink. Vorschau und Testversand
arbeiten ausschließlich mit erfundenen Beispieldaten.

### Akzeptanzkriterien

- **[FU006#AK01]** Die Einladungs-E-Mail enthält den Vereinsnamen, den Titel
  der Abstimmung, den Wahlzeitraum und den persönlichen
  Aktivierungslink — **nicht** die Mitgliedsnummer (wer die Mail eines
  anderen erhält, kennt damit nicht automatisch auch dessen
  Mitgliedsnummer). Der Standard begrüßt mit
  „Hallo {vorname} {nachname},“ und weist auf die Anonymität der Stimme hin.
  Nach dem Textblock der Abstimmung führt die Mail durch die Schritte:
  (1) Wahl-Code erzeugen per Aktivierungsbutton, (2) im Wahlzeitraum abstimmen
  (Pflicht-URL der Stimmformular-Seite), (3) nach Wahlende die Ergebnisse
  prüfen (Pflicht-URL der Ergebnisseite); dabei wird erklärt, dass die
  ersten fünf Zeichen des Prüfcodes zum Wiederfinden der eigenen Stimme
  reichen. Die URLs der Stimmformular-Seite und der Ergebnisseite sind
  Pflichtfelder: ohne gültige http(s)-Adressen wird das Speichern der
  E-Mail-Einstellungen abgewiesen und es wird nicht versendet. Ein optionaler
  Website-Link der Abstimmung erscheint als Hinweis „Mehr zum Ablauf der
  Wahl und der eingesetzten Software“.

- **[FU006#AK02]** Die Mail wird als HTML mit UTF-8 und als Plain-Text-
  Alternative versendet. Das HTML-Layout ist fest im Plugin (Tabellenlayout,
  inline CSS, kein Framework, keine externen Bilder, kein Öffnungs- oder
  Klick-Tracking). Der Footer steht in beiden Teilen. Es wird kein `From`
  überschrieben; `Reply-To` ist die WordPress-Adminadresse. Es werden keine
  `List-Unsubscribe`-, `Precedence: bulk`- oder sonstigen Bulk-Header gesetzt.
  Für die Zustellbarkeit sind SMTP sowie SPF, DKIM und DMARC erforderlich; die
  Einstellungsseite weist darauf hin. SMTP-/Server-Logs dürfen den Mailinhalt
  inklusive Aktivierungstoken nicht protokollieren.

- **[FU006#AK03]** Betreff, Einleitung und Footer sind global im Backend
  editierbar und bleiben als Optionen gespeichert. Der Versand überschreibt
  sie nicht. Der bisherige Betreff `vv_invite_subject` bleibt als Fallback
  lesbar; der bisherige Text `vv_invite_body` bleibt in der Datenbank, wird
  aber nicht automatisch in die neue Vorlage übernommen. Der Textblock und
  der optionale Website-Link je Abstimmung liegen in
  `voting_polls.mail_text`/`mail_url`.

- **[FU006#AK04]** Betreff, Einleitung, Footer und der Text je Abstimmung
  dürfen die Platzhalter `{vorname}`, `{nachname}`, `{first_name}`,
  `{last_name}`, `{poll_title}`, `{question}`, `{start}`, `{end}`,
  `{site_name}`, `{site_url}` und `{privacy_url}` verwenden.
  Die Mitgliedsnummer ist bewusst **kein** Mail-Platzhalter (FU006#AK01).
  Sie werden je Empfänger ersetzt; leere Namen ergeben eine leere Stelle.
  Unbekannte Platzhalter werden beim Speichern mit einer Meldung abgewiesen.
  Der persönliche Aktivierungslink wird nicht als Platzhalter im Freitext
  erwartet, sondern immer als Button „Wahl-Code erzeugen“ gesetzt.

- **[FU006#AK05]** Vorschau und Testversand sind auf der globalen
  Einstellungsseite und je Abstimmung verfügbar. Beide nutzen die
  gespeicherten Werte und ausschließlich erfundene Beispieldaten (Name,
  E-Mail und ein ungültiger Beispieltoken); echte
  Mitgliedsdaten werden nicht angezeigt oder versendet. Die Testmail geht an
  das angemeldete WordPress-Konto und verändert weder den Versandstatus noch
  den Queue-Zähler. Ohne Footer oder gültige Aktivierungs-URL ist kein
  Testversand möglich; die Vorschau zeigt dann einen Hinweis. Fehlen auf
  Bestandsinstallationen die Pflicht-URLs der Stimmformular- oder
  Ergebnisseite, weist die Vorschau
  darauf hin, dass die zugehörigen Schritte (2 und 3) in der Mail entfallen.
