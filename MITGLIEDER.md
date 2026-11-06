# So stimmst du mit – Erklärung für Mitglieder

Diese Seite erklärt, wie eine Abstimmung mit dem Vereinsvoting funktioniert –
und warum sie gleichzeitig **anonym**, **sicher** und **nachvollziehbar** ist.

## In vier Schritten zur Stimme

### 1. Du bekommst eine Einladung per E-Mail

Wenn eine Abstimmung gestartet wird, erhältst du eine E-Mail mit einem
**persönlichen Link** (Token). Der Link ist für dich allein bestimmt und
kann nur einmal verwendet werden. Er öffnet eine Seite, auf der du deinen
Abstimmungscode abholst. Die Mail führt dich der Reihe nach durch die drei
Schritte: Abstimmungscode abholen, abstimmen und nach dem Abstimmungsende die
Ergebnisse prüfen.

> **Was ist ein „Token“?** Der persönliche Link enthält einen einmaligen
> Code. Er bleibt gültig, bis du damit deinen Abstimmungscode abholst – danach
> wird er gelöscht und ist verbraucht.

Nichts bekommen? Schau im Spam-Ordner nach und frage bei der Verwaltung
nach, ob deine Adresse im Mitgliederstand aktuell ist.

### 2. Du holst dir deinen Abstimmungscode

Auf der Link-Seite zeigt dir das System einen **Abstimmungscode**: 6 Zeichen
aus Buchstaben und Ziffern, vom System zufällig erzeugt – du wählst ihn nicht
selbst. Damit können keine schwachen, erratbaren Codes entstehen
(Geburtstage, Namen, einfache Muster). Auf der Seite gibst du deine
Mitgliedsnummer ein und bestätigst den angezeigten Code mit
„Abstimmungscode bestätigen“. Mit
„Neuen Code erzeugen“ kannst du dir jederzeit einen anderen Code erzeugen.

Der Abstimmungscode ist ab dann **dein persönliches Passwort für diese
Abstimmung**. Niemand kennt ihn außer dir – nicht die Verwaltung, nicht der
Webseiten-Betreiber. Er wird dir **nur einmal angezeigt**: notiere oder
kopiere ihn sofort. Auch der Server speichert ihn nicht: dort liegt nur ein
unumkehrbarer „Fingerabdruck“ deines Codes, mit dem er bei der Abstimmung
verglichen wird.
Mehr dazu: [Passwort-Hashing (Wikipedia)](https://de.wikipedia.org/wiki/Passwort-Hashing).

Falls du deinen Abstimmungscode verlierst: Die Verwaltung kann ihn weder
einsehen noch wiederherstellen. Sie setzt den Code zurück; dabei wird der
bisherige Code ungültig. Du bekommst eine neue Einladung mit einem neuen
persönlichen Link und holst dir damit einen neuen Code. Das geht nur, solange
du noch nicht abgestimmt hast und die Abstimmung noch nicht beendet ist.
Melde dich dazu über das Kontaktformular.

Falls du nur die Einladung (den Link) verlierst und noch keinen
Abstimmungscode abgeholt hast: Melde dich über das Kontaktformular bei der
Verwaltung. Sie kann dir dieselbe Einladung erneut schicken. Der bereits
verschickte Link bleibt dann gültig, bis du damit einen Abstimmungscode
abholst. Einen separaten Widerruf des alten Links gibt es in diesem Fall
nicht.

### 3. Du gibst deine Stimme ab

Im Abstimmungszeitraum öffnest du die Abstimmungsseite und gibst **Ja**,
**Nein** oder **Enthaltung** an – zusammen mit Mitgliedsnummer und deinem
Abstimmungscode.

* Eine Person kann nur **einmal** abstimmen.
* Nach **5 falschen Eingaben** ist die Abstimmung für dich 15 Minuten
gesperrt – dein Abstimmungscode lässt sich also nicht durchprobieren.
* Deine Stimme und die Markierung „hat abgestimmt“ werden in **einem
einzigen, ununterbrechbaren Schritt** gespeichert. Ein Absturz mitten im
Vorgang führt nie zu einer doppelten oder verlorenen Stimme.

### 4. Du sicherst deine Quittung

Direkt danach zeigt das Plugin einen **anonymen Quittungscode** – dein
Beleg, dass die Stimme angekommen ist. Notiere dir die **ersten 5 Stellen**
des Codes (Screenshot oder Notiz) – mehr brauchst du später nicht, um deine
Stimme wiederzufinden. Der Code enthält weder deinen Namen noch deine
Mitgliedsnummer.

> **Was ist das für ein Code?** Er wird aus deiner Stimme berechnet wie ein
> Fingerabdruck aus deinem Finger – aus dem Code lässt sich aber nichts
> zurückrechnen. Mehr: [Kryptografische Hashfunktion (Wikipedia)](https://de.wikipedia.org/wiki/Kryptografische_Hashfunktion).

### Nach dem Mitgliederentscheid: selbst nachrechnen

Wenn die Abstimmung beendet ist, zeigt die Website das **Ergebnis**
(Ja, Nein, Enthaltung) und darunter eine **Prüftabelle** aller
Quittungscodes mit der jeweiligen Stimme. Die ersten fünf Zeichen jedes
Codes sind fett hervorgehoben – suche darin deine: Dann siehst du, dass
deine Stimme korrekt gezählt wurde. Und du kannst selbst nachzählen: alle
Zeilen der Tabelle ergeben zusammen das angezeigte Ergebnis.

---

## Warum ist der Mitgliederentscheid anonym?

Weil **Zuordnung** und **Stimme** getrennt gespeichert sind:

* An einem Ort steht, **wer** du bist (Mitgliederliste) und **ob** du
abgestimmt hast.
* In der **Urne** steht ausschließlich: welche Abstimmung, welche Antwort
(Ja/Nein/Enthaltung) und der anonyme Quittungscode – kein Name, keine
Mitgliedsnummer, keine E-Mail.

Zwischen beiden gibt es **keine Verbindung**. Deshalb kann auch der
Administrator im Backend nur sehen, *dass* und *wann* du abgestimmt hast –
aber **nie, wie** du abgestimmt hast. Das ist kein Versehen, sondern
Absicht.

Dazu kommt: In keiner Log-Datei, Fehlermeldung oder Statistik tauchen
Mitgliedsnummern, E-Mail-Adressen, Abstimmungscodes oder Stimmen auf. Die
Einladungs-E-Mails enthalten kein Öffnungs- oder Klick-Tracking.

## Warum ist der Mitgliederentscheid sicher?

* **Einmal-Link:** Der Einladungslink ist personenbezogen und wird nach
der Benutzung gelöscht.
* **6-stelliger Abstimmungscode:** Vom System erzeugt, bekannt nur bei dir,
gespeichert nur als Fingerabdruck, nach 5 Fehlversuchen 15 Minuten
gesperrt ([Brute Force (Wikipedia)](https://de.wikipedia.org/wiki/Brute-Force-Methode)).
* **EINE Stimme pro Person:** Nach der Stimmabgabe ist Schluss.
* **Abstimmungszeitraum:** Vor Start und nach Ende gibt es gar kein
Eingabefeld.
* **Ununterbrechbarer Schritt:** Stimme und Markierung werden in einer
[Transaktion](https://de.wikipedia.org/wiki/Transaktion_(Datenbank))
gespeichert – entweder beides oder gar nichts.
* **Offener Code statt Geheimniskrämerei:** Der Quelltext des Plugins liegt
öffentlich auf GitHub: <https://github.com/ziffit/wp_vereins-voting>.
Jeder kann ihn lesen, prüfen und selbst betreiben. Die Sicherheit dieser
Abstimmung stammt nicht aus geheimen Verfahren, sondern aus
nachvollziehbaren Regeln – wer misstraut, kann sie im Code nachlesen.

## Warum ist der Mitgliederentscheid nachvollziehbar?

Dein Quittungscode ist der digitale Stimmzettel-Beleg: Er beweist, dass
deine Stimme gewertet wurde, verrät aber nicht, wer du bist. Die öffentliche
Prüftabelle macht das Ergebnis für alle prüfbar – jeder kann seinen Code
wiederfinden und selbst nachzählen, statt einem Bericht glauben zu müssen.

Zusätzlich hält das System am Abstimmungsende einen **Abgabefingerprint**
fest: eine eindeutige Prüfzahl, die sich aus allen Quittungscodes ergibt.
Speichert jemand eine frühere Ergebnisseite, kann er später nachweisen, dass
an der Urne nichts mehr verändert wurde.

---

## Häufige Fragen

**Kann die Verwaltung sehen, wie ich abgestimmt habe?**
Nein. Sie sieht in der Teilnehmerliste nur, *ob* und *wann* du abgestimmt
hast – nicht deine Antwort. Aus den Zeitangaben lässt sich die Stimme
ebenfalls nicht ableiten: Die Urne enthält keine Uhrzeit und keine laufende
Nummer, und die Prüftabelle zeigt keine Zeitangaben. Es gibt daher keinen
Wert, der sich mit deinem Abstimmungszeitpunkt abgleichen ließe.

**Kann ich zweimal abstimmen?**
Nein. Nach deiner Stimme wird der Zugang für dich geschlossen.

**Wir haben eine gemeinsame E-Mail-Adresse – geht das?**
Ja. Entscheidend ist die Mitgliedsnummer – zwei Personen mit einer Adresse
sind zwei Berechtigungen.

**Was, wenn ich meinen Quittungscode jemandem zeige?**
Der Code allein verrät keine Person – er steht in der Tabelle ohne Namen.
Aber er verrät die Stimme dahinter. Behandle ihn wie einen Stimmzettel:
deine Sache.

**Was, wenn ich die Einladung nie bekommen habe?**
Spam-Ordner prüfen, dann bei der Verwaltung melden – die Adresse ist dann
vermutlich veraltet.

**Was ist, wenn ich die falsche Option gewählt habe?**
Einmal abgegebene Stimmen können nicht geändert werden – das ist der Preis
der Einmaligkeit. Klick dich bei der nächsten Abstimmung in Ruhe durch.

---

*Fachbegriffe sind verlinkt; die Wikipedia-Artikel erklären die Hintergründe
ohne Vorkenntnisse.*
