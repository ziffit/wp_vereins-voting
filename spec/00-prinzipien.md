# [0] Allgemeine Prinzipien & Architektur

## Plugin-Identität

- **Plugin-Slug / Name:** `vereins-voting`
- **Hersteller:** Felix Tensing

## Styling-Prinzip

Kein Tailwind oder fremde CSS-Frameworks. Ausschließlich minimales,
semantisches Basis-CSS für Formulare und Tabellen, damit sich das Plugin
nahtlos in jedes WordPress-Theme einfügt.

## Sicherheits-Architektur

Strikte Trennung von **Identitätsprüfung/Wahlberechtigung**
(`wp_voting_voters`) und **anonymer Stimmurne** (`wp_voting_ballots`).

- Keine Foreign Keys zwischen den Tabellen.
- Speicherung des Wahl-Codes ausschließlich als sicherer Hash (`password_hash`).
  Der Wahl-Code (6 Zeichen, mindestens 1 Buchstabe und 1 Ziffer) wird
  **vom System erzeugt** – nie vom Mitglied gewählt; er hängt nicht von
  Geburtstag, Name oder Datum ab und ist nur in der Aktivierungsansicht
  einmal sichtbar.

## Datenschutz & Logging (hart)

Es werden **niemals** Daten geloggt, die Rückschlüsse auf ein Mitglied, seine
Stimmabgabe oder seinen Wahl-Code zulassen. Das gilt für Code, Tests, Betrieb und
gleichermaßen für Vorschläge von KI-Werkzeugen.

- Verboten in Logs, Debug-Ausgaben, Fehlermeldungen, Transients und
  Support-Antworten: Mitgliedsnummer, E-Mail, Name, Wahl-Code (auch gehasht),
  `activation_token`, `receipt_hash` in Verbindung mit einer Person, die
  gewählte Option sowie IP-Adresse in Verbindung mit einer Stimme.
- Fehlermeldungen nennen nur den Grund, nie die eingegebenen Werte.
- Rate-Limit-Schlüssel werden gehasht (`vv_rate_key()`). Die Urne enthält
  ausschließlich `poll_id`, `vote_option`, `receipt_hash`.
- Der Einladungsversand speichert nur pollbezogene Statuswerte und Zähler;
  E-Mail-Adressen, Namen, Mitgliedsnummern, Token und Wahl-Code-Hashes gehören nicht
  in Queue-Optionen, Cron-Ausgaben oder Fehlermeldungen. Vorschau und
  Testversand verwenden ausschließlich erfundene Beispieldaten und einen
  ungültigen Beispieltoken.
- E-Mails enthalten keine externen Bilder und kein Öffnungs- oder
  Klick-Tracking. SMTP-Betriebslogs dürfen keine Aktivierungstoken enthalten.
- Neue Features dürfen keine personenbezogenen Daten an Dritte, in Logs oder
  in Analyse-Tools übertragen. Verstöße werden wie Sicherheitslücken behandelt.
