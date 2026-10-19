# Modul 3: E-Mail-Templates (MAIL)

## [FU006] Transaktionale E-Mail-Vorlagen

### Beschreibung

Bereitstellung anpassbarer E-Mail-Texte für Einladung und Bestätigung.

### Akzeptanzkriterien

- **[FU006#AK01]** Einladungs-E-Mail enthält den Vereinsnamen, den Titel der
  Abstimmung, Fristen und den personalisierten Aktivierungslink.

- **[FU006#AK02]** Bestätigungs-E-Mail (optional oder direkt im Browser)
  enthält den anonymen `receipt_hash` nach erfolgreicher Stimmabgabe.

- **[FU006#AK03]** Betreff und Text der Einladung sind im Backend editierbar
  und bleiben als Vorlage für den nächsten Versand gespeichert.

- **[FU006#AK04]** Betreff und Text können die Platzhalter `{first_name}` und
  `{last_name}` verwenden; sie werden je Empfänger aus den importierten
  Namensfeldern ersetzt (leer, wenn keine Namen gespeichert sind). Die
  Standardvorlage begrüßt mit „Hallo {first_name},“.
