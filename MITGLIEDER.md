# So wählst du mit – Erklärung für Mitglieder

Diese Seite erklärt, wie eine Abstimmung mit dem Vereinsvoting funktioniert –
und warum sie gleichzeitig **anonym**, **sicher** und **nachvollziehbar** ist.

## In vier Schritten zur Stimme

### 1. Du bekommst eine Einladung per E-Mail

Wenn eine Abstimmung gestartet wird, erhältst du eine E-Mail mit einem
**persönlichen Link**. Der Link ist für dich allein bestimmt und kann nur
einmal verwendet werden. Er öffnet eine Seite, auf der du deinen
Wahl-PIN festlegst. Die Mail führt dich der Reihe nach durch die drei
Schritte: PIN setzen, abstimmen und nach Wahlende die Ergebnisse prüfen.

> **Was ist ein „Token"?** Der persönliche Link enthält einen einmaligen
> Code, der nach der Benutzung gelöscht wird. Wer den Link später mitliest,
> kann nichts mehr damit anfangen.

Nichts bekommen? Schau im Spam-Ordner nach und frage im Vorstand nach, ob
deine Adresse im Mitgliederstand aktuell ist.

### 2. Du legst deinen Wahl-PIN fest

Auf der Link-Seite gibst du ein:

1. deine Mitgliedsnummer,
2. deine E-Mail-Adresse,
3. deinen neuen Wahl-PIN (6 Stellen, zweimal zur Bestätigung).

Der PIN ist ab dann **dein persönliches Passwort für diese Wahl**. Niemand
kennt ihn außer dir – nicht der Vorstand, nicht der Webseiten-Betreiber.
Auch der Server speichert ihn nicht: dort liegt nur ein unumkehrbarer
„Fingerabdruck" deines PIN, mit dem er bei der Abstimmung verglichen wird.
Mehr dazu: [Passwort-Hashing (Wikipedia)](https://de.wikipedia.org/wiki/Passwort-Hashing).

Falls du deinen PIN vergisst: Der Vorstand kann ihn zurücksetzen – du
bekommst dann einen neuen Einladungs-Link und setzt dir einen neuen PIN.

### 3. Du gibst deine Stimme ab

Im Wahlzeitraum öffnest du die Wahlseite und wählst **Ja**, **Nein** oder
**Enthaltung** – zusammen mit Mitgliedsnummer, E-Mail und deinem PIN.

* Eine Person kann nur **einmal** abstimmen.
* Nach **5 falschen Eingaben** ist die Abstimmung für dich 15 Minuten
  gesperrt – dein PIN lässt sich also nicht durchprobieren.
* Deine Stimme und die Markierung „hat gewählt" werden in **einem einzigen,
  ununterbrechbaren Schritt** gespeichert. Ein Absturz mitten im Vorgang
  führt nie zu einer doppelten oder verlorenen Stimme.

### 4. Du sichert deine Quittung

Direkt danach zeigt das Plugin einen **anonymen Quittungscode** – dein
Beleg, dass die Stimme angekommen ist. Notiere dir die **ersten 5 Stellen**
des Codes (Screenshot oder Notiz) – mehr brauchst du später nicht, um deine
Stimme wiederzufinden. Der Code enthält weder deinen Namen noch deine
Mitgliedsnummer.

> **Was ist das für ein Code?** Er wird aus deiner Stimme berechnet wie ein
> Fingerabdruck aus deinem Finger – aus dem Code lässt sich aber nichts
> zurückrechnen. Mehr: [Kryptografische Hashfunktion (Wikipedia)](https://de.wikipedia.org/wiki/Kryptografische_Hashfunktion).

### Nach der Wahl: selbst nachrechnen

Wenn die Wahl geschlossen ist, zeigt die Website das **Ergebnis**
(Ja, Nein, Enthaltung) und darunter eine **Prüftabelle** aller
Quittungscodes mit der jeweiligen Stimme. Die ersten fünf Zeichen jedes
Codes sind fett hervorgehoben – suche darin deine: Dann siehst du, dass
deine Stimme korrekt gezählt wurde. Und du kannst selbst nachzählen: alle
Zeilen der Tabelle ergeben zusammen das angezeigte Ergebnis.

---

## Warum ist die Wahl anonym?

Weil **Zuordnung** und **Stimme** getrennt gespeichert sind:

* An einem Ort steht, **wer** du bist (Mitgliederliste) und **ob** du
  abgestimmt hast.
* In der **Urne** steht ausschließlich: welche Abstimmung, welche Antwort
  (Ja/Nein/Enthaltung) und der anonyme Quittungscode – kein Name, keine
  Mitgliedsnummer, keine E-Mail.

Zwischen beiden gibt es **keine Verbindung**. Deshalb kann auch der
Administrator im Backend nur sehen, *dass* und *wann* du abgestimmt hast –
aber **nie, was** du gewählt hast. Das ist kein Versehen, sondern
Absicht.

Dazu kommt: In keiner Log-Datei, Fehlermeldung oder Statistik tauchen
Mitgliedsnummern, E-Mail-Adressen, PINs oder Stimmen auf. Die
Einladungs-E-Mails enthalten kein Öffnungs- oder Klick-Tracking.

## Warum ist die Wahl sicher?

* **Einmal-Link:** Der Einladungslink ist personenbezogen und wird nach
  der Benutzung gelöscht.
* **6-stelliger PIN:** Bekannt nur bei dir, gespeichert nur als
  Fingerabdruck, nach 5 Fehlversuchen 15 Minuten gesperrt
  ([Brute Force (Wikipedia)](https://de.wikipedia.org/wiki/Brute-Force-Methode)).
* **EINE Stimme pro Person:** Nach erfolgreicher Abstimmen ist Schluss.
* **Wahlzeitraum:** Vor Start und nach Ende gibt es gar kein
  Eingabefeld.
* **Ununterbrechbarer Schritt:** Stimme und Markierung werden in einer
  [Transaktion](https://de.wikipedia.org/wiki/Transaktion_(Datenbank))
  gespeichert – entweder beides oder gar nichts.
* **Offener Code statt Geheimniskrämerei:** Der Quelltext des Plugins liegt
  öffentlich auf GitHub: <https://github.com/ziffit/wp_vereins-voting>.
  Jeder kann ihn lesen, prüfen und selbst betreiben. Die Sicherheit dieser
  Wahl stammt nicht aus geheimen Verfahren, sondern aus nachvollziehbaren
  Regeln – wer misstraut, kann sie im Code nachlesen.

## Warum ist die Wahl nachvollziehbar?

Dein Quittungscode ist der digitale Stimmzettel-Beleg: Er beweist, dass
deine Stimme gewertet wurde, verrät aber nicht, wer du bist. Die öffentliche
Prüftabelle macht das Ergebnis für alle prüfbar – jeder kann seinen Code
wiederfinden und selbst nachzählen, statt einem Bericht glauben zu müssen.

---

## Häufige Fragen

**Kann der Vorstand sehen, wie ich gestimmt habe?**
Nein. Er sieht nur, dass (und wann) du abgestimmt hast – nicht die Antwort.

**Kann ich zweimal abstimmen?**
Nein. Nach deiner Stimme wird der Zugang für dich geschlossen.

**Wir haben eine gemeinsame E-Mail-Adresse – geht das?**
Ja. Berechtigt ist immer die Kombination aus Mitgliedsnummer **und**
E-Mail-Adresse – zwei Personen mit einer Adresse sind zwei Berechtigungen.

**Was, wenn ich meinen Quittungscode jemandem zeige?**
Der Code allein verrät keine Person – er steht in der Tabelle ohne Namen.
Aber er verrät die Stimme dahinter. Behandle ihn wie einen Stimmzettel:
deine Sache.

**Was, wenn ich die Einladung nie bekommen habe?**
Spam-Ordner prüfen, dann im Vorstand melden – die Adresse ist dann
vermutlich veraltet.

**Was ist, wenn ich die falsche Option gewählt habe?**
Einmal abgegebene Stimmen können nicht geändert werden – das ist der Preis
der Einmaligkeit. Klick dich bei der nächsten Wahl in Ruhe durch.

---

*Fachbegriffe sind verlinkt; die Wikipedia-Artikel erklären die Hintergründe
ohne Vorkenntnisse.*
