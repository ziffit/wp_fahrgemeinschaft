# Prüfumgebung

Diese Datei beschreibt, wie das Plugin funktional geprüft wird: welche Umgebung dafür
verwendet wird, wie sie jederzeit wiederherstellbar ist und was die vier Testläufe
tatsächlich belegen. Sie gehört nicht zum Plugin und wird nicht mitgeliefert.

Letzter Lauf: 28.09.2026 — **Admin-Ebene 537, öffentliches HTTP 181, Mail-Ebene 163,
0 Fehler**. Die CLI-Suite ist nicht gelaufen, weil `smoke.php` am Anfang
alle Arbeitsdienste, Fahrgemeinschaften und Mitglieder löscht und dafür eine
ausdrückliche Zustimmung braucht; ihre 482 Prüfungen stammen aus dem freigegebenen Lauf
vom 27.09.2026 und sind seither unverändert. Der Lauf ist damit keine Viersuiten-Zahl,
und er wird auch nicht als eine angegeben.

Der Lauf ist kein `run-all.sh`, und die CLI-Suite ist auch nicht über den normalen Weg
gefahren: `smoke.php` kennt nur den Schritt `all`, und der löscht am Anfang alle
Arbeitsdienste, Fahrgemeinschaften und Mitglieder. Das ist beim ersten Versuch dieser
Reihe passiert und hat sieben von Hand eingetragene Arbeitsdienste und zwei Fahrten
gekostet; seitdem wird das nur noch mit ausdrücklicher Zustimmung getan. Der CLI-Lauf
vom 27.09.2026 ist mit Zustimmung gefahren, gegen den Stand, der auch die drei
anderen Suiten gesehen haben. Vor diesem Lauf ist die Verwaltung aufgeräumt worden:
142 veröffentlichte Seiten namens „Fahrgemeinschaften“ sowie „Arbeitsdienste“ (ID 106
und 108) sind gelöscht, geblieben ist die Seite ID 98 mit dem Shortcode, weil
`http.sh` und `mail.sh` sie brauchen. Das Aufräumen ist eine eigene Handlung gewesen
und nicht Teil eines Laufs.

Reihenfolge und Zustand: `http.sh` und `mail.sh` lesen `tests/fixture.json` und
verbrauchen es; die Datei wird deshalb vor ihrem Lauf neu gebaut. `admin.sh` liest sie
nicht mehr. Es brauchte sie für zwei Dinge — den Arbeitsdienst, an dem es arbeitet, und
den Pfad der öffentlichen Seite — und beides waren Tatsachen über die Installation, die
es sich hätte selbst verschaffen können. Der Dienst entsteht jetzt im Testlauf mit
`make-event`, die Seite mit dem neuen Befehl `state.php list-page-path`, der die
veröffentlichte Seite mit dem Shortcode sucht. Der Test ist damit ohne die Fixture der
HTTP-Suite lauffähig, was vorher nicht galt: Ein Lauf, der nur deshalb scheiterte, weil
die andere Suite nicht vor ihm war, sagt nichts über den Adminbereich aus. Dieselbe
Erkenntnis stand schon bei `[4b]`, wo der Filter „veröffentlicht“ vorher die Fahrt aus
der fremden Fixture las; die HTTP-Suite beweist, dass sie genau diese Fahrt löschen
kann. Die Reihenfolge Admin vor HTTP war damit keine Vorliebe, sondern eine
Fehlerquelle, die sich als Fehlschlag zeigte.

Der Test braucht für zwei Prüfungen weiterhin Zustand, den er selbst herstellt: Der
Dienst in Abschnitt `[3]` trägt ein Mitglied aus demselben Lauf, weil der Satz über die
belegten Plätze und die nur lesbare Liste der Angemeldeten nur für einen Dienst
existieren, für den sich jemand eingetragen hat. Vorher saß dort die Anmeldung, die die
HTTP-Suite dagelassen hatte, und die Prüfung las sie, ohne es zu wissen.

Und eine Prüfung brauchte eine Karte, die es eindeutig zu lesen gilt. Sie suchte die
Karte des geprüften Dienstes über dessen Titel, und zwei Dienste können denselben
Titel tragen — sie standen nebeneinander, beide aus diesem Testlauf, und die Prüfung las
die falsche. Der Fehlschlag fiel auf, weil der gelesene Dienst einen anderen Bedarf
hatte als der gesuchte; bei zwei gleichnamigen Diensten mit gleichem Stand wäre die
Prüfung grün gewesen und hätte die falsche Karte gemessen. Die Karte trägt jetzt die
öffentliche Referenz des Dienstes als Sprungziel (`fg-dienst-<public_ref>`), und der
Testhelfer `karte()` sucht danach. Das ist eine Änderung am Plugin, keine im Test: Ein
Titel benennt keine Karte, während ein Link auf genau einen Dienst zeigen muss — und
genau diese Unbestimmtheit ist der Grund, warum die Karte überhaupt einen Anker
bekommt.

Vor jedem Lauf: `arbeitsdienste/` nach `my-plugin/` spiegeln, `tests/*.sh` und
`tests/*.php` nach `/tmp/fgtests/` kopieren, drei Sekunden warten (der Bytecode-Cache
im Container ist nicht sofort neu). Ein Lauf gegen einen alten Stand ist grün und
beweist nichts.


## Stack

| Teil | Ort |
| --- | --- |
| Compose-Datei | `$WPDEV/docker-compose.yaml` |
| Einrichtung | `$WPDEV/setup.sh` |
| TLS | `$WPDEV/tls.sh`, Vhost `fg-ssl.conf`, Zertifikat in `$WPDEV/tls` |
| Testskripte | `tests/` in diesem Projekt |

`$WPDEV` steht für das Verzeichnis mit dem Compose-Stack. Es liegt außerhalb dieses
Projekts und ist nicht Teil des Repos, weil es die Testumgebung des jeweiligen
Rechners beschreibt. Die Skripte erwarten es in der Umgebungsvariable `WPDEV`.

- `wordpress:latest` (im letzten Lauf 7.1.2), `mariadb:10.6`, `phpmyadmin:latest`
- `8080` HTTP, `8443` TLS, `8081` phpMyAdmin
- `WORDPRESS_DEBUG=1`, damit PHP-Hinweise in den Antworten sichtbar bleiben
- Der Plugin-Ordner `arbeitsdienste/` ist als `wp-content/plugins/my-plugin` eingebunden; Änderungen wirken sofort. Die Hauptdatei darin heißt `arbeitsdienste.php`; die Tests rechnen mit dieser Datei, wenn sie das Plugin aktivieren und deaktivieren.
- Testadresse: `https://localhost:8443`, Administrator `fg_admin` / `Test1234!`
- TLS ist ein selbstsigniertes Zertifikat, daher arbeiten alle Aufrufe mit `curl -k`.
- Das Dateisystem des WordPress-Containers ist nicht persistent. Nach jedem `docker compose up -d`
  müssen Zertifikat, Vhost und `mod_ssl` neu eingespielt werden; `setup.sh` erledigt das.
- Alle Daten sind Wegwerf. `docker compose down -v` entfernt das Datenbank-Volume und stellt
  den Ausgangszustand her; danach genügt ein erneuter Lauf von `setup.sh`.

## Umgebung herstellen

`WPDEV` muss auf das Verzeichnis mit dem Compose-Stack zeigen. Ohne die Variable
findet `run-all.sh` kein Einrichtungsskript und bricht mit einer Meldung ab, statt
eine leere Pfadangabe zu verwenden.

```bash
export WPDEV=/pfad/zum/compose-stack
bash "$WPDEV/setup.sh"
```

Das Skript ist idempotent und in fünf Schritten aufgeteilt:

1. `docker compose up -d`
2. Warten, bis die Datenbank eine Verbindung annimmt
3. TLS-Vhost und Zertifikat einspielen, danach prüfen, ob der TLS-Port antwortet
4. Plugin-Ordner synchronisieren und die Testdateien in den Container kopieren
5. Installation und Aktivierung: `bootstrap.php repair`, `install`, `configure`, Mail-Log leeren, Erreichbarkeit prüfen, Zustand ausgeben

`tests/bootstrap.php` arbeitet in drei Phasen:

- **repair** läuft **vor** WordPress und benutzt eine rohe Datenbankverbindung. Eine halb
  fertige Installation (Tabellen ohne gültige Optionen) lässt sich mit WordPress nicht
  reparieren, weil `is_blog_installed()` vorher `dead_db()` aufruft. Solche Reste werden
  hier entfernt.
- **install** legt das Schema nur an, wenn `siteurl` noch fehlt. `wp_install()` leitet
  `siteurl` und `home` aus der Anfrage ab; im CLI ist das leer, deshalb werden `$_SERVER`
  und die URLs zusätzlich gesetzt.
- **configure** setzt Site-URLs, Zeitzone, Administrator, Plugin-Aktivierung, Rewrite-Regeln,
  Cron und den Mail-Recorder und meldet, was fehlt.

Nach dem Setup ist die Umgebung in dem Zustand, den die Tests erwarten: Plugin aktiv,
`fg_schema_version` auf `1.2.0`, die vier Tabellen `wp_fg_events`, `wp_fg_rides`,
`wp_fg_members` und `wp_fg_event_members` vorhanden,
`fg_daily_cleanup` geplant, keine Rollenberechtigung des Plugins, Mail-Log leer.

## Testlauf

```bash
bash tests/run-all.sh
```

`run-all.sh` ruft zuerst `setup.sh` auf und startet dann vier Suiten. Jeder Lauf ist
isoliert: `smoke.php` und `http_setup.php` löschen vorher alle Arbeitsdienste,
Fahrgemeinschaften und Mitglieder sowie die Statistik-Option, es gibt also keinen Zustand vom Vorlauf.

| Suite | Datei | Vorgehen |
| --- | --- | --- |
| CLI | `tests/smoke.php` | WordPress im Container, Abschnitte 0–20: Tabellen, Aktivitätsgrenze, öffentliche Seite samt beider Leermeldungen, Vormerkung, Token-Links, Kontakt, Löschung, Ablehnungen, Admin, Bereinigung, Datenschutz, HTTPS, Markup-Hygiene und die Mitgliederverwaltung; dazu die Abschnitte `[2a]` (die vier freiwilligen Angaben eines Arbeitsdienstes) und `[3c]` (die Liste der Arbeitsdienste) |
| Öffentlich | `tests/http_setup.php` + `tests/http.sh` | `curl` gegen Apache über TLS: Weiterleitung, Standalone-Seiten mit Kopfzeilen, 405 bei GET, Hinweise, keine personenbezogenen Daten im HTML, Aufbau der kompakten Liste, Reihenfolge von Sprunglink, Liste und Formular, Rückkehrweg mit Sprungziel, Namensfeld gegen Kontaktdaten über den Zähler `publish_personal_data`, Verhalten der Schaltflächen im Stylesheet; dazu die Abschnitte `[8]` für die Arbeitsdienstliste auf einer eigenen Seite und `[9]` für den vollständigen Weg von der Anmeldung über die E-Mail bis zum Abmelden |
| Mail-Ebene | `tests/mail.sh` + `tests/mail-mime.php` | Die Meldungen, die ein Browseraufruf wirklich an `wp_mail()` übergibt: Wortlaut, Empfänger, Zustellfehler. Dazu die fertige MIME-Struktur: `multipart/alternative`, Text als erste Alternative, HTML als zweite, eingebettetes Logo unter `cid:logo`, und die Links beider Teile: im HTML ein `<a href>` mit einem Wortlaut, der die Handlung nennt, im Text die Adresse in Klarschrift |
| Admin | `tests/admin.sh` | Echter Login, echte Roundtrips über `admin-post.php`: Navigation (Name des Obermenüpunkts, Reihenfolge und Markierung der sechs Unterseiten auf jeder Seite, die neue Seite E-Mails eingeschlossen), Arbeitsdienst anlegen, ändern, ungültige Daten, nonce-geschütztes endgültiges Löschen, Kaskadenlöschung, Einstellungen der E-Mail inklusive Pflichtprüfung, Mediathek-Auswahl und Vorschau; dazu die Abschnitte `[9]` (Mitgliederverwaltung), `[10]` (CSV-Import), `[11]` (Anmeldung zu einem Dienst) und `[12]` (Aufräumen um alle Mitglieder ohne Arbeitsdienst) und `[13]` mit `[13b]` (Anrede der Nachrichten) und `[13c]` (Zurückhaltung bei einem unbekannten Platzhalter) — Wortlaut der fünf E-Mails, Platzhalter je Nachricht, Vorschau, Zurücksetzen |

Zwei Eigenheiten der Suiten, die man kennen muss, bevor man einem Fehlschlag traut:

- **Jede Suite baut ihre Fixture selbst auf.** `http_setup.php` liest und schreibt
  `tests/fixture.json`; `http.sh` verbraucht es. Wird zwischen zwei Läufen keine neue
  Fixture gebaut, laufen `[1]` bis `[6]` gegen Einmalwerte und schlagen mit
  `invalid_token` fehl — nicht, weil das Plugin etwas kaputt gemacht hätte, sondern
  weil die Werte schon verbraucht waren. Das gilt auch, wenn eine Suite von Hand
  gefahren wird statt über `run-all.sh`: `admin.sh` braucht für den Importbericht den
  Mitglied `0042` aus derselben Fixture und verbraucht sie dabei. Ein danach gefahrenes
  `http.sh` läuft dann gegen leere Werte und meldet 24 rote Zeilen, von denen keine
  einen Fehler des Plugins bezeichnet. Vor jeder von Hand gefahrenen Suite also
  `http_setup.php` laufen lassen.
- **Eine Suite, die man zur Fehlersuche zweimal fährt, verbraucht ihre Fixture
  selbst.** Am 28.09.2026 ist das mit 18 roten Zeilen in `http.sh` passiert: Der
  erste Lauf hatte die Vormerkung der Fixture bestätigt und die veröffentlichte Fahrt
  gelöscht, der zweite las diese Werte erneut und meldete sie als Fehler. Der Grund war
  nicht im Plugin, sondern im Ablauf. Zwei Regeln daraus: Vor **jedem** Lauf
  `http_setup.php`, und die Ausgabe **einmal** in eine Datei statt zweimal auf den
  Bildschirm, weil ein zweiter Blick auf dasselbe Log kein zweiter Lauf ist, sondern
  derselbe — außer man schreibt vorher die Fixture neu.
- **Der Bericht eines Imports wird aus einem Rahmen gelesen, nicht aus der Seite.**
  Auf dem Bildschirm der Mitglieder steht der Bericht *und* die Liste aller Mitglieder.
  Eine Prüfung, die eine Mitgliedsnummer auf der ganzen Seite sucht, findet sie in
  beiden Teilen und bleibt grün, auch wenn der Bericht sie nicht nennt. `admin.sh`
  schneidet den Bericht deshalb mit einem eigenen Helfer heraus; genau daran ist eine
  Gegenprobe der ersten Reihe rot geworden, siehe unten.
- **Auf der öffentlichen Seite wird nach dem Anker einer Karte gesucht, nicht nach
  ihrem Titel.** Die Liste trägt eine Karte je sichtbarem Dienst, und zwei Dienste
  können denselben Titel haben. Eine Suche über die ganze Seite beantwortet die
  Frage, ob ein Wort irgendwo steht; eine Suche nach dem Titel beantwortet sie für den
  ersten der beiden. Der Testhelfer `karte()` in `admin.sh` schneidet die eine Karte
  heraus, an der `id="fg-dienst-<public_ref>"` steht. Dieselbe Vorsicht gilt für den
  Helfer `feld()` (ein Feldtype und `required` im selben `<input>`-Tag) und `notice()`
  (nur der Meldungsrahmen, nie die ganze Seite).

### Die Abschnitte `[2a]`, `[3c]`, `[8]`, `[9]`, `[10]`, `[11]` und `[12]`

`[2a]` prüft die vier neuen Felder dort, wo sie hingehören: an der Tabelle. Der
Nachweis steht am Anfang und heißt, dass die vier Spalten am **Ende** einer bereits
bestehenden Tabelle stehen — eine Neuinstallation und eine Aktualisierung müssen
dieselbe Spaltenreihenfolge haben, sonst meint dieselbe Spaltennummer auf zwei
Installationen etwas anderes. Danach Rundlauf, Vorgabewerte, Teilaktualisierung,
Zeichen- statt Bytegrenze und die Fälle, in denen das Speichern verweigert wird.

Eine Grenze trägt nur dann etwas, wenn ein Wert sie auch wirklich erreicht. Bei der
Gruppe ist das anders als bei der Beschreibung, und der Unterschied ist gemessen:
`$wpdb->insert()` weist einen Wert, der länger ist als die Spalte, mit einer
WordPress-Meldung ab, das `text`-Feld der Beschreibung nimmt dagegen 501 Zeichen
ohne Murren. Die Prüfung, dass eine zu lange Beschreibung abgelehnt wird, beweist
deshalb die Grenze des Plugins. Bei der Gruppe decken sich die Grenze des Plugins
und die Breite der Spalte; die Prüfung merkt es also nicht, ob die Zeile im Plugin
weggelassen wurde oder die Spalte es verhindert hat. Sie bleibt trotzdem stehen, weil
sie das beobachtbare Verhalten festhält — nichts gespeichert, nichts abgeschnitten —
und dieser Nachweis ist der, der zählt.

`[3c]` prüft die Liste, wie sie im Browser entsteht, und schließt die Wege, die ein
Fehler leicht öffnet: leerer Text und die Zahl 0, Mark-up in der Beschreibung, ein
Zeilenumbruch, der ein Zeilenumbruch bleiben muss, Reihenfolge einschließlich des
ganztägigen Dienstes, und das Fehlen von Formular und Script. Der Wochentag wird
zweimal geprüft, weil `date()` auch bei gesetzter `LC_TIME` „Monday“ liefert: einmal
gegen das erwartete deutsche Wort und einmal dagegen, dass kein englischer Wochentag
auf der Seite steht.

`[8]` holt dieselbe Seite über TLS, so wie ein Besucher sie aufruft. Die Zahl der
Karten wird mit der Zahl der Datensätze aus der Tabelle verglichen, damit ein
Dienst, der stillschweigend fehlt, nicht durchrutscht. Beim Script-Vergleich wird
nicht die ganze Seite geprüft — Theme und WordPress bringen auf jede Seite ihre
eigenen mit —, sondern die Differenz zur anderen Plugin-Seite, zusätzlich die Zahl der
Script-Elemente, weil ein Script ohne eigene Datei in einer Liste von Adressen
unsichtbar wäre.

Die Zahl der freien Plätze wird in diesem Abschnitt **nicht** aus dem Plugin geholt.
Sie ist die Differenz aus zwei Zahlen der Tabelle, und der Test rechnet sie selbst:

```bash
frei_von() {
	bedarf=$(s event "$1" demand)
	angemeldet=$(s count-registrations "$1")
	...
}
```

Das ist der einzige Weg, auf dem die Zahl unabhängig geprüft werden kann. Ein Test,
der `free_places()` fragt und die Antwort mit dem vergleicht, was die Seite anzeigt,
bestätigt auch eine falsche Zahl — beide kommen aus derselben Funktion, und sie
stimmen dann überein. Genau das ist beim ersten Versuch passiert: `free_places()`
wurde testweise auf `demand` umgestellt, die Seite zeigte 4 statt 3, und die Prüfung
blieb grün. Mit der eigenen Rechnung wurde dieselbe Änderung sofort rot.

`[9]` in `http.sh` ist der ganze Weg eines Mitglieds über HTTP, und zwar in der
Reihenfolge, in der es stattfindet: Anmeldung abschicken, Meldung lesen, Tabellenstand
prüfen, E-Mail öffnen, Link folgen, Seite lesen, Bestätigung abschicken, zweite
Bestätigung schicken, nachsehen, dass nichts passiert. Der Abmeldelink wird aus dem
Text der E-Mail gelesen und nicht zusammengebaut, und Token wie Nonce kommen
aussehends von der gerenderten Seite, nie aus einer eigenen Rechnung: Ein Test, der
den Wert selbst bildet, den er sendet, beweist nur, dass der gebildete Wert der
gesendete war. Aus demselben Grund wird für die Anmeldung die Nummer eines Mitglieds
genommen, das echt eingetragen ist, und nicht irgendeine — sonst prüft der Test einen
Weg, den es im Betrieb nicht gibt.

`[9]`, `[10]` und `[11]` in `admin.sh` decken die drei Seiten ab, die es vorher nicht
gab: die Mitgliederverwaltung, den Import und die Anmeldung im Adminbereich. Für
die Löschschaltflächen liest `admin.sh` den Link aus dem HTML und prüft, dass die
Rückfrage im `onclick` steht — das ist der Nachweis dafür, dass
`wp_kses_post()` hier nicht mehr benutzt wird, denn es wirft dieses Attribut weg und
eine Schaltfläche ohne Rückfrage löscht, ohne zu fragen. Weil die Dienstseite jetzt
für jede Anmeldung eine eigene Löschschaltfläche trägt, unterscheidet der Helfer
`link <typ>` nach dem übergebenen Typ, statt die erste Löschschaltfläche der Seite
zu nehmen.

`[12]` in `admin.sh` ist der einzige Abschnitt der Suite, der etwas löscht, das er
nicht selbst angelegt hat, und der einzige, der zwei Zahlen auf einmal nennen muss. Die
beiden Zahlen unterhalb der Liste werden deshalb beide einzeln gelesen und beide gegen
die Tabellen gerechnet, nicht gegeneinander: Die Rechnung ist `anzahl = mit + ohne`, und
eine Prüfung, die nur prüft, dass `mit` und `ohne` zusammenpassen, ist bei zwei
vertauschten Zahlen grün. Die Menge der Betroffenen wird aus der Übersicht und aus
der Tabelle gelesen und als **Satz** (sortiert) verglichen, nicht als Zeichenkette. Der
Grund ist derselbe wie oben bei den Mitgliedsnummern: Die Nummern der Betroffenen
stehen auch in der Mitgliederliste derselben Seite, und eine Suche nach einer Nummer
über die ganze Seite wird von der Zeile des ersten Treffers beantwortet. Die Adressen
werden zusätzlich einzeln geprüft, denn zwei Nummern können sich wie die eine der
anderen anfangen und zwei Adressen nicht. Für den Hinweis nach dem Löschen gilt
dasselbe: Geprüft wird der **ganze** Text, nicht ein Wort daraus — die Seite erklärt im
Abschnitt darüber in Worten, dass gelöscht wird, eine Prüfung, die die Seite liest, ist
also immer grün.

`[13]` in `admin.sh` ist der Wortlaut der fünf E-Mails. Drei Dinge darin sind nicht
selbstverständlich und deshalb hier festgehalten:

**Der Vorschau-Link wird nach seiner Nachricht gewählt, nicht als erster genommen.** Die
Seite trägt fünf Vorschau-Links — einen je Zeile der Liste — und der erste gehört zur
ersten Nachricht, nicht zu der, deren Formular offen ist. Ein Test, der den ersten nimmt,
prüft eine andere Nachricht und bleibt grün. Das ist beim ersten Lauf des Abschnitts
passiert: Die Prüfung auf den fertigen Betreff schlug fehl, während die auf den Textteil
und die auf den HTML-Teil grün waren, und der Betreff stand in der Tabelle — nur in der
falschen Zeile.

**Die Klage über einen abgelehnten Text wird aus der Box gelesen, die sie trägt.** Die
Formulare drucken unter dem Textfeld eine Tabelle mit allen erlaubten Platzhaltern, und
eine Suche über die ganze Seite findet `{{Abmeldelink}}` dort, was der Server auch geantwortet
hat. Die Klage steht in der Box, die WordPress für den Admin druckt, die Hinweise auf
zurückgehaltene Nachrichten in einer anderen; beide tragen `notice-error` und sind
deshalb über das Wort nicht zu unterscheiden. Der neue Helfer `kasten()` nimmt **ein Wort aus
der Klassenliste**, während `rahmen()` die ganze Klasse verlangt — beide braucht es, und
der Unterschied steht am Helfer.

**Die Adressen der echten Mitglieder werden aus der Tabelle gelesen, nicht fest verdrahtet.**
Die Vorschau zeigt erfundene Namen, und der Grund ist die Abwesenheit echter Daten. Eine
feste Adresse im Test wäre eine zweite Wahrheit, die stimmen könnte, ohne dass die Vorschau
etwas damit zu tun hat; gelesen wird `members-csv`, und die Zahl der geprüften Adressen
wird mitprotokolliert, weil eine Schleife über keine Adresse nichts beweist und grün wäre.
Die Adresse **des Vereins** steht dagegen mit Absicht in der Vorschau: Der Footer ist Teil
der Nachricht, und eine Vorschau ohne ihn wäre genau das Bild, das diese Seite
ausgelöst hat. Geprüft wird deshalb nicht die Domain, sondern jede einzelne
Mitgliederadresse — und der Footer zusätzlich **im Textteil**, was seit 1.11.2 der
eigentliche Fehlerort war.

`[13b]` liest die Anreden aus den Nachrichten, die **wirklich verschickt wurden**: Der
Zustandsbefehl `mail-anrede` legt eine Fahrt an, schickt die beiden Nachrichten der
Kontaktanfrage an eigene Adressen und gibt die erste Zeile beider Nachrichten zurück;
`mail-greeting` macht dasselbe für die Anmeldebestätigung. Zwei Fehler sind dabei gefallen und
beide wären still gewesen: Der Befehl las zuerst die **neueste** Zeile des Mailsystems, und
weil nichts verschickt worden war — der Dienst war voll — las er die Zeile eines früheren
Laufs und meldete dreimal denselben Namen. Jetzt wird die höchste Nummer vor dem Versand
gemerkt und nur eine spätere Zeile gelesen, und kommt keine, antwortet der Befehl mit dem
Wort `KEINE-MAIL` statt mit einer leeren Zeile: Eine leere Zeile wäre eine Anrede, die
mitgeprüft würde. Und die Adressen, die zu einem Mitglied gehören, baut sich der Abschnitt
selbst — ein Abschnitt davor räumt jedes Mitglied ohne Anmeldung weg, eine Fixture-Adresse
wäre also wieder eine Fremde gewesen, und die Begründung wäre auf den Algorithmmus
zurückgefallen.

`[13c]` ist der einzige Abschnitt, der einen Zustand schreibt, den kein Formular erzeugen
kann: `mail-text-set` legt einen Text mit einem Platzhalter in die Tabelle, den diese Fassung
nicht kennt, weil das Formular ihn ablehnen würde. Danach lässt `mail-holdback` eine echte
Nachricht an eine echte Fahrt gehen und gibt vier Dinge zurück: den Rückgabewert des
Versands, die Zahl der Zeilen im Mailsystem **vorher und nachher** und die Zahl der
Hinweise. Drei Fehler können dort unabhängig voneinander passieren, und eine Prüfung, die
nur eine davon misst, wäre bei den anderen beiden grün. Der Befehl räumt die Fahrt und
den Text weg, **nicht** den Hinweis — sonst hätte der Abschnitt nichts zu lesen; gelöscht wird
er am Ende des Abschnitts, wo auch geprüft wird, dass er sich überhaupt löschen lässt. Der
Hinweis darf keine Warteschlange versprechen, weil es keine gibt; diese eine Prüfung sucht
nach den Worten und wacht darüber.

Der Abschnitt prüft außerdem das, was beim Löschen einzelner Anmeldungen sonst niemand
prüft: dass ein **abgewiesener** Aufruf nichts bewirkt. Ein falscher Nonce muss mit 403
enden und die Zahl der Mitglieder unverändert lassen; die Prüfung schickt ihn und
misst danach, nicht vorher.

Eine Feinheit der Prüftechnik, die sich hier bewährt hat: Die Wochentagsprüfung
vergleicht nicht mit einem festen Wort, sondern mit dem, was `format_event_date_long()`
aus der Tabelle rechnet — sonst wäre sie eine zweite Implementierung derselben
Regel.

## Gegenproben

Eine Prüfung, die nie rot wird, beweist nichts. Zu jeder neuen Prüfung gehört deshalb
die Frage: *welche Änderung an der Datei müsste sie rot machen?* Die Antwort wird
umgesetzt, das Ergebnis protokolliert und die Datei zurückgebaut. Das Werkzeug dafür
liegt nicht im Repo, weil es Dateien verändert, die einem laufenden Test gehören; es
läuft aus `/tmp/opencode/fg/`:

| Datei | Zweck |
| --- | --- |
| `gegenproben.txt` | Eine Zeile je Gegenprobe: `Suite ~~~ Datei ~~~ alte Stelle ~~~ neue Stelle ~~~ Muster`. Der Trenner der Felder ist die Dreier-Tilde, weil in einer Bruchstelle auch Pipes vorkommen; `\n` steht für einen Zeilenumbruch |
| `gegenprobe.sh` | Sichert die Datei, baut die Bruchstelle ein, kopiert das Plugin in den Container, lässt die Suite laufen, stellt die Datei wieder her |
| `alle-gegenproben.sh` | Fährt die Liste der Reihe nach ab und schreibt ein Protokoll |

Zwei Vorkehrungen, ohne die das Werkzeug sich selbst widerlegt: Es verlangt, dass die
alte Stelle **genau einmal** in der Datei vorkommt, und bricht sonst ab, und es
protokolliert die eingesetzte Zeile mit (`--- Gebrochen: …`). Ohne beides hat die erste
Fassung dieser Reihe gemessen, was sie messen sollte, ohne es zu messen: Sie teilte die
Zeilen mit `cut -d'~'`, obwohl der Trenner `~~~` ist, bekam also einen leeren Dateinamen,
setzte nichts ein — und meldete ein Suite-Ergebnis. Das Ergebnis war echt, die
Gegenprobe dazu war es nicht.

Dasselbe Schema hat beim Auswerten des Ergebnisses den einen Fehlbefund dieser Reihe
erzeugt, den es weiter unten als solchen gibt: Das Werkzeug erkennt eine Gegenprobe, die
grün bleiben soll, an einem `!` vor dem Muster, und verglich die Zusammenfassungszeile
mit einem Suchtext, der sie am Zeilenanfang festnagelte. Die Zeile lautet
`== 166 passed, 0 failed ==`; der Suchtext fand sie nie, und das Werkzeug meldete zu
richtig-grünen Läufen „die Suite wurde doch rot“. Die Prüfung war grün, die Auswertung
nicht.

Zwei Ergebnisse sind es wert, hier aufgeschrieben zu werden, weil beide eine Prüfung
verändert haben und nicht nur einen Befund erzeugt haben:

**Die freien Plätze prüften sich selbst.** Die Zahl auf der Karte wurde mit dem
Fixture verglichen, und das Fixture hatte die Zahl aus `free_places()`. Die Gegenprobe
setzte `free_places()` testweise auf `Bedarf` und die Seite zeigte daraufhin 4 statt 3
freie Plätze — die Prüfung blieb grün. Zwei Stellen, die dasselbe aus derselben Funktion
nehmen, können nicht voneinander abweichen. Seitdem rechnet `http.sh` die Erwartung
selbst, aus `demand` und der Zahl der Anmeldungen in der Tabelle.

**„Genau ein Formular je Karte“ prüfte die Karte gegen sich selbst.** Die Prüfung
fragte die Seite, welche Karte geschlossen ist, und erwartete dann, dass eine geschlossene
Karte kein Formular trägt. Mit `can_register()`, das immer `true` liefert, zeigten
beide geschlossenen Karten eine Schaltfläche — und damit waren nach dieser Logik beide
nicht geschlossen. Die Prüfung blieb grün. Seitdem kommt die erwartete Karte aus den
Tabellen: Eine Karte mit einem freien Platz muss genau ein Formular tragen und darf den
Schließsatz nicht führen, eine ohne freien Platz umgekehrt.

Eine dritte Gegenprobe hat nichts gefunden, und das ist kein Fehlbefund: Der Abmeldevorgang
prüft das Token zweimal, einmal vor dem Löschen und einmal im `DELETE … WHERE id AND
unregister_hash`. Nimmt man nur eine der beiden Prüfungen weg, bleibt die andere, und das
für den Besucher sichtbare Verhalten — ein zweiter Klick auf denselben Link tut nichts —
bleibt unverändert. Dasselbe gilt für den Bestätigungslink der Fahrgemeinschaften. Was
sich nicht testen lässt, ist der Gleichstand zweier Anfragen, und genau dafür ist die
zweite Prüfung da. Sie ist damit nicht überflüssig, nur nicht von hier aus beweisbar;
im Werkzeug ist dieser Fall mit einem `!` vor dem Muster als „muss grün bleiben“
gekennzeichnet, damit er nicht als ausgefallene Gegenprobe gelesen wird.

Die Mediathek-Auswahl des Logos wird nicht angeklickt, sondern über ihre Stellung im
Dokument geprüft: Das Script hängt mit `wp_add_inline_script()` an `media-views` und wird
deshalb hinter der Mediathek und hinter den beiden Buttons ausgegeben, die es sucht. Ein
Vergleich der Positionen fängt genau den Fehler, dass beide Buttons vorhanden sind und
doch stumm bleiben. Zusätzlich wird geprüft, dass die Buttons zunächst deaktiviert
ausgeliefert werden: Läuft das Script nicht, sieht man das an der Oberfläche, statt es
an einem Klick zu bemerken.

Die Admin-Suite legt einen Arbeitsdienst an, ändert ihn und verwirft eine Speicherung.
Stand am 26. September 2026 blieb dieser Datensatz liegen: Nach jedem Lauf erschien ein
weiterer aktiver Dienst auf 2027 im Auswahlfeld des öffentlichen Formulars, weil die
Suite die Einstellungen zurücknahm, den eigenen Datensatz aber nicht. Der Lauf räumt ihn
jetzt selbst ab und prüft das am Ende über `exists-event`, das `0` für einen gelöschten
und `1` für einen vorhandenen Dienst liefert. Ohne diese Prüfung wäre die Beseitigung eine
Behauptung in der Beschreibung, und genau daran ist es gescheitert: 139 Prüfungen waren
grün, während der Datensatz liegen blieb.

Der Aufbau der öffentlichen Liste wird über die ausgelieferte Seite geprüft, nicht über
den Quelltext: Arbeitsdienst und Datum stehen in einer gemeinsamen Überschrift, jeder
Eintrag trägt Angebotsart, Abfahrtsbereich und Vorname oder Spitzname in einer Zeile, Feld und
Schaltfläche liegen in derselben Zeile, und der Hinweis zur E-Mail-Adresse steht einmal
für die ganze Liste statt einmal je Eintrag. Der letzte Punkt prüft Anzahl der Einträge
und Anzahl der Hinweise in einer Bedingung, weil ein Vergleich auf einer Seite mit nur
einem Eintrag nichts beweist. Gegen den Stand vor dem Umbau schlagen alle fünf Prüfungen
fehl; das wurde beim Einbau so geprüft.

Dass das Kontaktformular erst auf Wunsch erscheint, prüft dieselbe Suite an der
ausgelieferten Seite über den Aufbau: Das Formular liegt in einem `details`-Element, das
nicht mit `open` ausgeliefert wird, die Schaltfläche darüber trägt beide Beschriftungen,
und gesendet wird über eine eigene Schaltfläche „Absenden“. Sichtbarkeit lässt sich mit
`curl` nicht messen; dafür steht das native Verhalten des Elements. Der Absendeweg
selbst wird zusätzlich über HTTP geprüft: die Antwort ist `contact_received`, und zwar
bei einer hinterlegten wie bei einer nicht hinterlegten Adresse.

Beide Beschriftungen stehen stets im Dokument, versteckt wird die unpassende allein durch
das Stylesheet. Deshalb liest die Suite die Dateien über genau die Adresse, die die Seite
verlinkt, und prüft die Kaskade: Für den geschlossenen Zustand muss eine Regel ohne
`[open]` das offene Label auf `display: none` setzen, für den geöffneten eine Regel mit
`[open]` es wieder sichtbar machen, und die zweite Regel muss stärker sein als die erste.
Damit wird der Fehler gefangen, der sich im Browser nicht im HTML zeigt: Erscheinen beide
Beschriftungen nebeneinander, liest die Schaltfläche „KontaktierenSchließen“. Gegen den
Stand vor dem Umbau schlägt die Prüfung fehl. Eine Grenze bleibt: Sie kann nur das
eigene Stylesheet beurteilen. Ob ein Theme des Zielauftritts die Regeln überstimmt, ist
in dieser Installation nicht nachprüfbar, weil hier gar kein Theme-Stylesheet geladen
wird — die Selektoren tragen deshalb den ganzen Pfad (`details` > `summary` > Label),
damit eine lockere Theme-Regel für `summary` oder `span` sie nicht überstimmen kann.

Dass das Label **E-Mail** nicht umbricht, wird im selben Stylesheet geprüft: Es braucht
eine Regel für das Label in der Kontaktzeile mit `white-space: nowrap`. Ohne sie setzt der
Browser am Bindestrich um, und aus einer Zeile werden zwei.

Dass das Namensfeld einen Vornamen oder Spitzname erwartet und keine Kontaktdaten, lässt
sich nicht an der Meldung ablesen: Ein abgewiesener Eintrag und ein Eintrag, der später
an einer nicht hinterlegten Adresse scheitert, antworten beide mit `not_created`. Der
Unterschied steht nur im Zähler `publish_personal_data`, den der Server selbst führt. Die
Prüfung liest ihn vor dem Absenden und danach und verlangt, dass er bei „Peter“, „Käse“
und „Amsel-Gruppe“ stehen bleibt, bei einer Telefonnummer aber steigt. Dafür nimmt sie
bewusst eine Adresse, die zu keinem für den Dienst angemeldeten Mitglied gehört: Dann
entsteht kein Eintrag, und die Probe hinterlässt nichts. Die sieben Textprüfungen desselben Abschnitts sind gegen
den Stand von `HEAD` geprüft: Aus dem alten Plugin ausgeliefert schlagen alle sieben fehl,
unter anderem die wegen der Dativform „persönliche**n** Kontaktdaten“ — mit der Endung `n`
im Suchbegriff wäre sie auch an der alten Fassung vorbeigelaufen und hätte nichts geprüft.

Der Hinweis unter dem Abfahrtsbereich nennt drei Beispiele, und die Suite prüft, dass der
Server alle drei annimmt. Das ist keine Formsache: Ein Hinweis, der Werte anbietet, die der
Server zurückweist, ist schlechter als keiner, weil der Eintrag mit derselben neutralen
Meldung verschwindet wie bei einer falschen Adresse. Stand 26. September 2026 sind das
`Langwasser`, `Nürnberg Nord` und `S-Bahnstation Ostring`; jede der drei Beispiele wird
tatsächlich zugelassen. Die Gegenprobe mit `Marktplatz 3` lässt die Prüfung schlagen, sie
prüft also den Zähler und nicht ihr eigenes Gerüst.

Dieselbe Prüfung hat eine Grenze sichtbar gemacht, die bleibt. Das Muster für „Straße +
Hausnummer“ sucht die Straßennamen als Teilzeichenfolge, nicht als ganze Wörter, und
`Ostring` enthält `ring`. Deshalb wird jeder Wert abgewiesen, in dem auf ein solches Wort
innerhalb von zwölf Zeichen eine Ziffer folgt:

| eingegeben | Ergebnis |
| --- | --- |
| `S-Bahnstation Ostring` | angenommen |
| `S-Bahnstation Ostring Gleis 2` | zurückgewiesen, Grund `publish_personal_data` |
| `S-Bahnstation Ostring, 2. Stock` | zurückgewiesen, Grund `publish_personal_data` |
| `Ostringring 12` | zurückgewiesen, Grund `publish_personal_data` |

Die erste Zeile ist der Fall, den die Suite prüft; `Ostringring 12` ist eine echte Straße
in Nürnberg und wird zu Recht abgewiesen. Eine Grenze links im Muster (`\b` vor der Gruppe)
würde die beiden mittleren Zeilen freigeben, ließe aber `Ostringring 12` durch. Ein kürzerer
Abstand zwischen Straßennamen und Hausnummer (`\D{0,12}` zu `\D{0,2}`) würde
`Ostring Gleis 2` freigeben, dafür aber `Straße des 17. Juni 112` — ebenfalls ein realer
Straßenname. Jede Verschärfung dieser Art tauscht also eine echte Adresse gegen eine zu enge
Ablehnung, und die Wahl ist eine Abwägung des Vereins, keine technische. Stand heute wird
nichts geändert, und der Hinweis nennt bewusst nur Ort und Stadtteil.

Das Aussehen der Schaltflächen wird im ausgelieferten Stylesheet geprüft, und zwar an
Eigenschaften statt an Zahlen. Vier Prüfungen: Keine Regel, die eine Schaltfläche zeichnet,
darf eine eigene Schriftgröße setzen, und mindestens eine muss `font: inherit` verwenden.
Diese Regel erfasst jede Regel, deren Selektor `.fg-button` oder `.fg-contact-toggle`
nennt, nicht nur `.fg-button` allein — die Kontaktfläche wird zwar von `.fg-button` gezeichnet,
trägt aber aus Gründen der Absicherung eine zweite Regel mit dem ganzen Pfad, und die kann
ebenso gut eine Größe bekommen. Die zweite Prüfung liest den Wert von `--fg-button` und
misst Weiß darauf: 9,62:1, über den 4,5:1, die kleine Schrift braucht. Sie hält nicht den
Namen und nicht den Farbwert fest, sondern das Verhältnis, damit ein späterer Farbwechsel an
der Lesbarkeit gemessen wird und nicht an einer veralteten Ziffer. Gegenprobe mit einem
hellen Gelb lässt sie schlagen; fehlt die Variable, ebenfalls.

Die dritte und vierte Prüfung betreffen die Handhabungsseite, die ihre Formatierung als
eine Zeile selbst mitbringt und in der die Farbe deshalb ein zweites Mal im Code steht.
Die dritte vergleicht die Farbe dort mit der des Stylesheets — zwei Kopien laufen
auseinander, sobald nur eine geändert wird —, die vierte verlangt auch dort ein
`font: inherit` ohne eigene Größe. Beide lesen die Seite, die Abschnitt 3 bereits geladen
hat; das bloße Abrufen verändert nichts, erst der POST in Abschnitt 4 bestätigt. Alle vier
Gegenproben sind gelaufen: eigene Größe auf `.fg-button`, eigene Größe auf der
Handhabungsseite, dort eine andere Farbe, dort `font: inherit` entfernt — jede schlägt an,
und jede an der passenden Prüfung. Gegenprobe B ist dabei mit einem Fehler in meinem
Prüfgerät aufgefallen: Es lud die Seite am Anfang selbst und überschrieb die Änderung, sodass
drei Prüfungen zunächst grün meldeten, ohne geprüft zu haben. Ein Prüfgerät, das seine eigene
Eingabe überschreibt, ist das gleiche Problem wie ein Test, der sich selbst bestätigt.

Der Rückkehrweg nach dem Absenden wird über die echte Weiterleitung geprüft, nicht über
den Quelltext: Die Adresse, auf die der Kontaktversand und ein abgelaufenes Formular
umleiten, muss ein Sprungziel als Endung tragen, und die Seite, die daraus entsteht, muss
eine `id` mit genau diesem Namen führen. Die Prüfung liest den Namen aus der Weiterleitung
und sucht ihn auf der Seite — ändert sich nur eine der beiden Stellen, springt der Browser
ins Leere, und die Prüfung ist die, die es merkt. Für den Fehlerfall gilt dasselbe, denn
das ist die Meldung, die zweimal gelesen wird. Zusätzlich muss die Meldung als Sprungziel
markiert sein (`scroll-margin-top`), sonst landet sie unter einer Kopfzeile, die stehen
bleibt.

Reihenfolge und Sprunglink der Seite werden an der ausgelieferten Seite geprüft: Der
Sprunglink steht vor der Liste, die Liste vor dem Formular, und das `href` des Sprunglinks
findet eine `id` auf derselben Seite. Ein Verweis ohne Ziel wäre die stillste Stelle im
ganzen Dokument. Die Reihenfolge wird nur verlangt, wenn die Liste etwas zu zeigen hat; steht
kein Arbeitsdienst an, gehören Listenabschnitt und Formular beide zu ihm und entfallen beide,
und die Prüfung verlangt dann ihr Fehlen. Gegenproben: Liste entfernt, Formular stehen
gelassen, und Formular entfernt, Liste mit Einträgen stehen gelassen — beide schlagen an.

Die beiden Leermeldungen der öffentlichen Seite erreicht die CLI-Suite in Abschnitt 3b, und
zwar ohne einen einzigen Datensatz anzufassen. Eine Meldung, die nur erscheint, wenn eine
Tabelle leer ist, lässt sich nicht erzeugen, indem man die Tabelle leert: Der Lauf müsste die
Arbeitsdienste der Nutzerin offline nehmen und wieder hinstellen, und ein Lauf, der auf halbem
Weg abbricht, hinterlässt kaputte Daten. Stattdessen beantwortet die Suite die eine `SELECT`,
die den Zustand füllt, mit null Zeilen. Dazu hängt sie sich an den Filter `query` von WordPress
und setzt die Bedingung vor das `WHERE`: `WHERE 1=0 AND …`. Vor das `WHERE` und nicht ans Ende,
weil eine Abfrage an einem `LIMIT` enden kann, wo ein angehängtes `AND` nicht mehr übersetzt
wird. Geschrieben wird nichts, und der Filter wird sofort wieder entfernt.

Geprüft wird nicht der Wortlaut allein, sondern die Zahl der Meldungen. Eine Prüfung, die nach
einer Formulierung sucht, beweist nur, dass diese Formulierung fehlt; die alte Fassung trug
zwei Meldungen nebeneinander, und die zweite wäre an jeder Textprüfung vorbeigelaufen. Der
Zähler `class="fg-empty"` muss in beiden Zuständen genau eins sein — das ist die Eigenschaft,
um die es geht. Zustand ohne Arbeitsdienst: eine Meldung, kein Listenabschnitt, kein Formular,
der Sprunglink bleibt. Zustand mit Arbeitsdienst ohne Einträge: eine Meldung, Liste und
Formular stehen. Am Ende steht eine Kontrolle, die ohne Filter rendert und prüft, dass die
Dienste und alle Einträge wieder da sind; die beiden Seiten werden dabei nicht als Ganzes
verglichen, denn das Formular trägt ein Nonce und die Sekunde seines Starts, zwei Aufrufe eine
Sekunde auseinander unterscheiden sich also von selbst. Verglichen wird die Zahl der
Einträge, weil die sich nicht ändern darf.

Gegen den Stand von `HEAD` schlagen vier der dreizehn Prüfungen an: die neue Formulierung in
beiden Zuständen, das Fehlen des Listenabschnitts und der Zähler, der die doppelte Meldung
findet.

Die Wirkung des Stylesheets ist mit `curl` nicht prüfbar. Zweispaltigkeit, Zeilenhöhe
und Schriftgrade der Einwilligung sind von Hand im Browser anzusehen.
`run-all.sh` endet mit Schritt `5/5 handover`, der den Mail-Recorder wieder abschaltet. Ohne
diesen Schritt stünde die Instanz anschließend nicht für die Handprüfung mit SureMails zur
Verfügung (siehe unten). **Wer eine Suite von Hand fährt, fährt diesen Schritt nicht mit** —
`http.sh` und `mail.sh` schalten den Recorder am Anfang selbst ein, und niemand schaltet ihn
wieder aus. Danach landet keine einzige Mail in SureMails, was sich nicht wie ein Fehler des
Plugins liest, sondern wie ein Fehler am Netz: Der Recorder hängt sich an `pre_wp_mail` und
beantwortet `wp_mail()`, ohne es weiterzureichen. Der Zustand lässt sich abfragen und
umschalten:

    docker exec wpdev-wordpress-1 php /tmp/fgtests/state.php recorder
    docker exec wpdev-wordpress-1 php /tmp/fgtests/state.php recorder off

Ein Name, der weder `on` noch `off` ist, schaltet nichts und antwortet mit dem Zustand
— ein Tippfehler, der stillschweigend nichts tut, wäre hier genau die Sorte Fehler, die
später als Fehler des Plugins gesucht wird.

`tests/state.php` ist die Schnittstelle zwischen den Shell-Suiten und den Tabellen. Es liest
und schreibt über `FG_Repository` (`statuses`, `count`, `count-published`, `count-alias`,
`event`, `ride`, `make-event`, `make-ride`, `find-event`, `exists-*`, `delete-*`, `purge`),
damit die Skripte den Zustand der Installation prüfen können, ohne WordPress-Beiträge zu
kennen. Feldzugriffe laufen über eine Whitelist; jeder Befehl gibt genau einen Wert aus.
Dazu kommen für den Wortlaut der E-Mails `mail-text-rows`, `mail-text-body`,
`mail-text-subject`, `mail-text-set`, `mail-text-reset`, `mail-text-notices` und
`mail-text-clear` — und für die Nachrichten, die wirklich hinausgehen,
`mail-anrede`, `mail-greeting` und `mail-holdback`. `mail-text-set` schreibt ohne die
Prüfung des Formulars, weil sonst kein Formular den Zustand bauen könnte, den
`mail-holdback` prüft. Dazu kommen `settings`, `settings-json` und `settings-restore`
für die Option `fg_settings`:
Die Admin-Suite liest die Option vor dem eigenen Lauf und stellt sie danach wieder her, damit
eine von Hand gepflegte Fußzeile den Testlauf übersteht. Weil die Option dabei nicht leer
sein muss, vergleichen die Prüfungen des Speicherns jeweils den Zustand davor und danach, statt
auf ein leeres Feld zu prüfen.

### Zwei weitere Reihen von Gegenproben

**Die zweite Reihe (19 Proben) fand drei Prüfungen, die nie rot werden konnten.** Alle drei
sind umgestellt, und die drei Bruchstellen sind nachgelaufen:

| Fundstelle | Was die Prüfung behauptete | Warum sie immer grün war |
| --- | --- | --- |
| `if ( false )` statt des Honigtopf-Zweigs in `register_member()` | Eine Anmeldung mit gefülltem Honigtopf-Feld schreibt nichts | Sie sandte die Nummer `0851`, und das ist ein Mitglied, das für den getesteten Dienst **schon** angemeldet war. Die Prüfung bekam `already_registered` und las das für die Honigtopf-Antwort. Sie prüfte nie die Honigtopf-Falle, sondern dass sich ein Mitglied nicht zweimal eintragen lässt. Auch richtig, aber etwas anderes. |
| Suche nach `name="fg_first_name"` und die Aussage „alle vier Felder sind als Pflicht markiert“ | Das Formular führt vier Pflichtfelder | Zwei getrennte Behauptungen an einer ganzen Seite. Die erste findet den Feldnamen auch bei `type="hidden"`; die zweite findet `required` irgendwo im Dokument, und das `required` der Dateiauswahl im Import-Formular stand schon vorher darin. Beide blieben grün, als das Vornamefeld zum versteckten Feld gemacht wurde. |
| Suche nach `0042` im ganzen Seitenquelltext | Der Bericht nennt ein Mitglied, das nicht in der Datei steht | Bericht und Mitgliederliste stehen auf **derselben** Seite, und `0042` steht in beiden. Die Prüfung fand die Nummer in der Liste und meldete den Bericht als geprüft. Sie blieb grün, als der Bericht niemanden mehr nannte. |

Daraus sind drei Dinge entstanden, zwei im Plugin und eines in der Suite:

- Der Bericht steht jetzt in einem eigenen Rahmen (`<div class="fg-import-report">`).
  Das ist Markup in einem Adminbereich, also ohne Datenschutzfrage, und es gibt der
  Liste und dem Bericht je eine Adresse, an der eine Prüfung sie einzeln lesen kann.
  `admin.sh` schneidet den Rahmen mit dem Helfer `report()` heraus, der die zugehörige
  Klammer zählt statt die nächste zu nehmen: Der Bericht enthält eine eigene Hinweisbox,
  und ein Suchen nach der ersten schließenden `</div>` hätte genau den ersten Absatz
  geliefert und die Tabelle darunter verschluckt.
- Die Honigtopf-Probe sendet jetzt `0852 / zwei@example.org`, ein Mitglied, das für
  keinen der getesteten Dienste eingetragen ist, und prüft zusätzlich die Meldung.
- Die Formularprüfung liest je Feld ein einzelnes `<input>`-Element und verlangt
  Feldtype und `required` **im selben Tag**; das Adressfeld wird als `type="email"`
  verlangt, denn das ist es. Der alte zweite Satz („alle vier Felder sind als Pflicht
  markiert“) ist entfallen — er war die Stelle, an der ein `required` irgendwo genügte.

**Die dritte Reihe (6 Proben) prüfte die neuen Prüfungen selbst.** Sie fand keine leere
Prüfung, aber einen echten Fehler im Plugin, einen in der Fixture und einen in einer
Prüfung, die schon älter war:

| Fundstelle | Wirkung | Behoben |
| --- | --- | --- |
| `strlen( $email ) > 254` statt der Spaltenbreite in `normalize_email()` | Eine Adresse von 200 Zeichen ist nach RFC 5321 gültig, besteht `is_email()` und wird dann von `$wpdb` **stillschweigend** abgelehnt, weil beide Adressspalten 190 Zeichen breit sind. Über HTTP sieht der Besucher „konnte nicht gespeichert werden“ bei einer Adresse, die er nach den Vorgaben des Formulars schreiben durfte. | Grenze auf `FG_Schema::CONTACT_EMAIL_MAX` (190) gesetzt und die vier Formularfelder auf dieselbe Zahl gebracht, damit der Browser nichts mehr einlädt, was der Server ablehnt. Geprüft in `admin.sh` am Mitglied und in `http.sh` an Fahrt und Liste, je mit der Feldangabe **und** mit dem Verhalten. |
| `is_event_participant()` immer `true` | Die Kontaktaufnahme nimmt jede Adresse an, auch eine, die zu keinem für den Dienst angemeldeten Mitglied gehört. Die benannte Prüfung schlägt an — und zusätzlich die ältere Prüfung für den Angebotsweg, dieselbe Regel an einem anderen Eingang. | Kein Fehler: der Test bestätigt die Regel an beiden Eingängen. |
| `$creator_email === $email` entfallen | Der eigene Eintrag würde gezählt, und ein Anfragender könnte an einem Zähler unterscheiden, ob ein Eintrag ihm gehört. | Kein Fehler. Die Prüfung trägt den Namen und schlägt an. |

Zwei Nebenschauplätze, die dabei mitgefallen sind und beide dasselbe Muster haben — eine
Prüfung, deren Name mehr behauptet als ihre Messung:

- `http.sh` prüfte die Kontaktaufnahme nur an der Antwort. Die Antwort lautet
  `contact_received`, egal ob weitergeleitet oder verworfen wurde; die Prüfung konnte
  also nicht unterscheiden, ob der Anfragende im Dienst ist. Sie stand dazu auf einem
  Adressenpaar, das gar nicht angemeldet war, und hieß „participant contact“. Jetzt liest
  sie die beiden Zähler des Kontaktwegs (`contact_valid_email`,
  `contact_invalid_email`) zusätzlich zur Antwort, und der eigene Eintrag wird gegen
  **beide** geprüft.
- Die Reihenfolge der Suiten ist eine Bedingung, keine Vorliebe: `admin.sh` liest in
  `[4b]` eine veröffentlichte Fahrt und legt sich inzwischen selbst eine an. Vorher las
  es die Fahrt aus der Fixture der HTTP-Suite, und die HTTP-Suite beweist, dass es
  genau diese Fahrt löschen kann. Der Fehlschlag hing also an der Reihenfolge und sagte
  nichts über den Filter aus.

Und einer, der auf die Fixture zurückfällt: `http.sh` Abschnitt `[5]` prüft die
Kontaktaufnahme, und die Fixture legt die veröffentlichte Fahrt auf ein Mitglied, das
für diesen Dienst gar nicht angemeldet ist. Damit war **kein** erfolgreicher Kontaktfall
herstellbar — die Regel deckte jeden Fall ab. Der Abschnitt trägt die Anmeldung des
Erstellers jetzt selbst ein und nimmt sie am Ende wieder weg, so wie `mail.sh` es für
seine zwei weiteren Mitglieder tut. Dieselbe Erkenntnis hat die Mail-Suite überhaupt erst
zum Laufen gebracht: Sie war die erste, die an der neuen Regel scheiterte, weil sie als
einzige zählt, ob Mails wirklich hinausgehen.

Das Werkzeug hat in diesen beiden Reihen zweimal selbst gemessen statt gebrochen, und
beide Male ist es erst beim Auswerten aufgefallen:

- Eine Bruchstelle darf sich jetzt auf die *n-te* Fundstelle beziehen, geschrieben als
  `3x|text`. Das ist nötig, weil `if ( ! empty( $_POST['fg_website'] ) ) {` in
  `class-fg-actions.php` dreimal steht — Anmeldung, Angebot, Kontakt — und jede der drei
  Stellen etwas anderes prüft. Ohne diese Schreibweise hätte man keine davon einzeln
  treffen können und wäre auf eine Ersetzung ausgewichen, die alle drei trifft und damit
  selbst keine Gegenprobe ist.
- Der Auswerter nimmt den Pfad des Protokolls als Argument. Vorher las er fest
  `gegenproben-lauf.log`, und beim Nachtragen einer Runde hätte er die alte gezeigt und
  die neue als „nicht gefahren“ ausgelegt.

### Die vierte Reihe (13 Vergleiche, 4 Proben)

Sie ging die vierte Änderung an, den Wegfall der Schaltfläche **Schließen**, und fand
eine Prüfung, die genau gegen den gesuchten Fehler blind war.

Die Änderung selbst brauchte einen Umweg, der im Markup steht und nicht in einer
Eigenschaft: Ein `summary` **ist** der Schalter seines `details`, und mit CSS lässt sich
keiner ausschalten. Bliebe er sichtbar, klappte er das Formular auf Enter genauso wieder
zu, und die zweite Schaltfläche wäre zurück. Die Schaltfläche verschwindet deshalb, sobald
ihr Formular offen ist, und eine gewöhnliche Textzeile (`fg-contact-latch`,
`fg-signup-latch`) tritt an ihre Stelle — Text ist nicht fokussierbar und nicht
anklickbar, also kann nichts mehr zuklappen.

| Fundstelle | Was die Prüfung behauptete | Warum sie immer grün war |
| --- | --- | --- |
| `not all(re.search(r'display:\s*(?!none)', b) for b in her)` in der Prüfung des Kontakt-Umschalters | Ist das Formular offen, wird die Textzeile gezeigt | `re.search` probiert **alle** Längen von `\s*` durch. Bei `display: none` nimmt es auch null Zeichen, der Blick nach vorn steht dann auf dem Leerzeichen **vor** `none`, und `none` beginnt dort nicht — der Blick gelingt. Die Prüfung fand also in jeder Regel, die ein `display` überhaupt nennt, einen Treffer, und die Gegenprobe „Textzeile im offenen Zustand ausgeblendet“ blieb grün. |

Umgestellt ist sie auf einen Meldungsleser (`eigenschaft()`), der die Meldungen einer
Regel einzeln durchgeht und die letzte zählen lässt, wie ein Browser es macht. Ein Muster
mit Blick nach vorn und einer Variable davor ist für „irgendwo steht das nicht“ nicht zu
gebrauchen: Nicht der Anker ist das Problem, sondern die Rückwärtsverkürzung dahinter.

Drei Dinge sind dabei mitgefallen:

- Die Prüfung las nur das **Kontakt**formular. Beide Formulare sind dieselbe
  Entscheidung, und eine Regel, die nur die eine nennt, ließe die andere mit einer
  zweiten Schaltfläche stehen, ohne dass etwas auffällt. Die Schleife läuft jetzt über
  beide Pfade.
- Der Direktvergleich des Prüfkörpers gegen die echte Datei und **zwölf** Fehlerbilder
  (je vier an beiden Formularen, dazu gelöschte Regeln, eine umbenannte Klasse und
  eine leere Datei) wurde vor dem Lauf auf der Seite gemacht. Das kostet Sekunden statt
  Minuten und hat zwei weitere Fehler in meinem eigenen Prüfrahmen aufgedeckt, nicht im
  Plugin: `sys.exit(0)` am Ende einer Prüfung ist kein Fehlschlag, und der Rumpf liest
  die Datei von `sys.stdin`.
- `display: none` gefolgt von `display: block` bleibt grün. Das ist richtig und bleibt
  es: der Browser nimmt die letzte Meldung, das Formular ist zu.

### Die fünfte Reihe (10 Fehlerbilder an den vier Layoutprüfungen)

Sie ging die drei Änderungen an den E-Mail-Vorlagen an: **ein** Fußzeilenfeld statt drei,
14 px für den Inhalt und 12 px für die Fußzeile, und kein grauer Balken zwischen Überschrift
und Inhalt. Der Direktvergleich lief diesmal nicht gegen eine Datei, sondern gegen das
gerenderte Dokument — die Vorschau aus dem Adminbereich ist die einzige Stelle in den
Suiten, an der das Layout vollständig gerendert vorliegt.

| Fundstelle | Was die Prüfung behauptete | Warum sie immer grün gewesen wäre |
| --- | --- | --- |
| `re.search(r'font-size:\s*[0-9.]+em', h)` in der Größenprüfung | Keine Zelle rechnet ihre Schriftgröße um | Das Muster suchte im **ganzen** Dokument und fand die absichtliche Regel `p { font-size: 1em }` aus dem Style-Sheet. Die Behauptung war zu weit gefasst: Sie wollte die Zellen prüfen, denn nur sie entscheiden, und griff deshalb für jede relative Größe — auch für die richtige. |
| `w.strip().strip('"\'<> ')` beim Lesen einer Angabe | Der Innenabstand der grauen Container ist `0` | Die letzte Angabe eines Tags trägt das schließende Anführungszeichen und den spitzen Klammer mit, der gelesene Wert war also `0">` und niemals `0`. Der Wert wird jetzt aus dem `style`-Attribut gelesen, wo ihn diese Zeichen umschließen. |
| `for tag in re.findall(..., region('copy block', 'copy'))` ohne Rücksicht auf die Farbe | Kein Container rückt etwas ein | Die Zone **enthält** die weiße Inhaltszelle, und deren 12 px 24 px sind genau das, was den Abstand des Containers ersetzt hat. Die Prüfung hätte beim richtigen Dokument gemeckert und beim Fehlerbild `padding: 0 24px` am grauen Container stillschweigend hingenommen. Jetzt werden nur die Zellen ohne Weiß geprüft, dazu die Gegenprobe, dass die weiße Inhaltszelle ihren eigenen Abstand behält. |
| ein ungeschütztes `"` im Python-Rumpf einer `pruef`-Zeile | dieselbe | Ein solches Zeichen **schließt die bash-Zeichenkette**. Die Datei blieb syntaktisch gültig, weil im Rest der Datei genug einfache Anführungszeichen folgten, um sie wieder zu schließen — `bash -n` meldete nichts, und die Prüfung bekam bis zum Zeilenende verschobenen Text. `bash -n` schlägt inzwischen an, sobald die Kette nicht zufällig ausbalanciert ist; es ist deshalb vor jedem Lauf zu beachten. |

Alle zehn Fehlerbilder (Container wieder auf 12 px, Luft unter der Überschrift, Container
beider Zonen wieder eingerückt, Inhalt und Fußzeile wieder relativ, Inhalt auf 12 px,
Fußzeile auf 14 px, Inhalt wieder grau, Leerzeile zwischen den Abschnitten weg) ließen
genau die benannte Prüfung rot werden, und jede nennt den schuldigen Wert im Fehlerbild.
Die Grundlinie war vorher vollständig grün.

Beim Prüfen der Anker ist noch etwas gefallen, das nichts mit dem Layout zu tun hat: Die
Gegenproben an Zeilen aus dem Suite-Bild **und** den Vergleich sämtlicher Prüfungsnamen
gegen `HEAD` gestellt zu haben, hat vier stillschweigend verschluckte Prüfungen gezeigt.
Beim Einsetzen des neuen Prüfblocks waren vier ältere Prüfungen zur Vorschau mitgegangen —
das Escaping eines Besucherwerts, das rohe kaufmännische Und, das Laden von einer fremden
Adresse und die unaufgelöste Schablone. Sie sind wieder hergestellt; der Abschnitt [8] läuft
wieder mit allen Prüfungen, und die Suite mit 392.

### Die sechste Reihe (7 Fehlerbilder am Aufräumen der Mitgliederseite)

Sie ging den Abschnitt `[12]` an, das Löschen aller Mitglieder, die für keinen Arbeitsdienst
angemeldet sind. Die Prüfungen standen in einem Aufbau, der sich für eine Handlung eigens
bewährt hat, und die Reihe prüft, ob dieser Aufbau die Handlung überhaupt trägt.

Der Aufbau ist: drei Mitglieder ohne jeden Dienst anlegen, den Bildschirm nach seinen beiden
Zahlen fragen, den ersten Klick verfolgen, die Übersicht gegen die Tabelle halten, einen
falschen Nonce schicken und dann den zweiten Klick tun. Sieben Fehlerbilder, an je einer
Stelle:

| Fehlerbild | Erwartete Prüfung | Was tatsächlich rot wurde |
| --- | --- | --- |
| `DELETE FROM fg_members` ohne die Bedingung | die beiden angemeldeten Mitglieder bleiben | beide „survived“, dazu die Memberzahl und der Hinweis: 4 Prüfungen |
| `render()` ruft vor der Übersicht die Löschung auf | das Aufrufen der Übersicht löscht nichts | 8 Prüfungen, zuerst „the overview deletes nothing“ — und die Übersicht stand danach leer, ohne Schaltfläche und ohne Formular |
| `check_admin_referer()` entfernt | ein falscher Nonce wird abgewiesen | „a wrong nonce is refused“ meldete 303 statt 403, danach waren alle drei weg; die Hinweisprüfung sah zusätzlich **zwei** Hinweise |
| die Übersicht zeigt nur den ersten Betroffenen | sie nennt genau die Mitglieder, die sie nimmt | 3 Prüfungen: die Schaltfläche meldete „1 Mitglied endgültig löschen“, die Mengenprüfung fand 0960 gegen 0960 0961 0962 |
| der Hinweis nennt für beide Zahlen dieselbe | der Hinweis nennt beide Zahlen | genau 1 Prüfung |
| die beiden Zahlen auf der Liste sind vertauscht | die Liste nennt beide Zahlen richtig | 3 Prüfungen, zuerst „it names how many would be deleted“ |
| `current_user_can( 'delete_posts' )` entfernt | — | **keine**, und das ist keine Wirkung des Fehlers, sondern eine Lücke der Umgebung; siehe unten |

Drei Dinge sind dabei mitgefallen, und alle drei waren Fehler im Prüfrahmen, nicht im Plugin:

- **Die Reihenfolge der Fehlerbilder war zunächst falsch.** Die Bilder wurden im Repo
  vorgenommen und nach jedem Bild nur in den Teststand zurückgespiegelt, nicht ins Repo
  selbst. Dadurch trug Bild 2 sein Fehlverhalten in Bild 3 hinein, und die Prüfung meldete
  12 rote Zeilen für einen Fehler, der genau eine betraf. Der Rahmen stellt jetzt nach
  jedem Bild den Stand in **beiden** Orten wieder her und bricht ab, wenn die beiden
  nicht mehr bytegleich sind. Aus demselben Grund wird am Ende `diff -r` über Repo und
  Teststand geschrieben: Ein Feature, das mit einem Fehlerbild zurückbleibt, ist sonst
  von einem grünen Lauf nicht zu unterscheiden.
- **Ein Fehlerbild hat den Bildschirm statt des Hinweises geprüft.** Der Hinweis wurde über
  die ganze Seite gesucht, und die Seite erklärt im Abschnitt darüber in Worten, dass
  gelöscht wird. Die Prüfung ist jetzt ein Vergleich des **ganzen** Hinweistextes, nicht
  zweier Teilzeichenketten: Sie trägt beide Zahlen und nennt beim Scheitern, was sie
  gelesen hat. Dass das mehr trägt als die beiden Teilprüfungen, hat Bild 3 gezeigt — dort
  standen zwei Hinweise untereinander, weil die abgewiesene Anfrage durchgegangen war.
- **Eine Prüfung suchte eine Zahl an der falschen Stelle.** Die Nummern der Betroffenen
  standen in der Seite der Übersicht und wurden einzeln gesucht. Eine Mitgliedsnummer ist
  Text, und die eines einen Anfänger haben kann: Die Suche nach `004` wird von der Zeile
  der 0042 beantwortet. Gehalten werden jetzt beide Mengen als **satz**, sortiert, und
  verglichen; die Adressen zusätzlich einzeln, denn zwei Adressen können sich nicht wie
  zwei Nummern ähneln.

Die siebte Zeile der Tabelle ist offen und bleibt offen: Die Suite meldet sich als
Verwalter an und hat damit `edit_posts` und `delete_posts` beide. Eine Prüfung, die die
Berechtigung des Löschvorgangs verlangt, brauchte einen zweiten Benutzer mit weniger
Rechten und einen zweiten Login — das ist dieselbe Lücke, die bei allen endgültigen
Löschungen des Plugins besteht, und sie ist unter „Was die Umgebung nicht prüft“ vermerkt.
Der Preis dafür, dass hier eine offene Lücke steht, ist ehrlicher als eine Prüfung, die
grün ist, weil sie den Fall nie erreicht.

Der Abschnitt hat außerdem eine Nebenwirkung auf den Zustand, die die anderen Suiten
spüren: Er löscht alle Mitglieder, die für keinen Dienst angemeldet sind — und das sind nach
einem Lauf der HTTP-Suite auch die, die `http_setup.php` angelegt hat. `http.sh` und
`mail.sh` bauen ihre Fixture ohnehin vor ihrem Lauf neu, was hier keine neue Vorschrift ist;
`admin.sh` braucht für seinen Bericht über fehlende Dateizeilen ein Mitglied, das nicht in
der Datei steht, und nimmt dafür 0042 aus der Fixture. Nach einem Lauf der neuen Reihe ist
deshalb `http_setup.php` zu fahren, bevor `admin.sh` wieder etwas über den Importbericht
behauptet. Beim ersten Lauf nach den Fehlerbildern ist genau das passiert: Zwei rote
Zeilen in `[10]` über den Bericht, weil Bild 1 in seiner Variante ohne Bedingung auch die
Fixture-Mitglieder mitgenommen hatte.

### Die siebte Reihe (7 Fehlerbilder an den Spaltennamen des Imports)

Sie ging die Tabelle an, die auf der Importseite sagt, welche Namen die Kopfzeile
haben darf. Die Behauptung dahinter ist eine Behauptung über **zwei** Dinge, die
dasselbe sagen müssen: Der Import nimmt einen Namen an, und der Bildschirm nennt
ihn. Ein Getter (`FG_Member_Import::accepted_columns()`) liefert beiden dieselbe
Liste, und die Prüfung fragt den Bildschirm ab und bietet **jede** Antwort dem
Import an — ein Import je Name, 23 Dateien, in allen anderen Spalten die Etiketten.

| Fehlerbild | Erwartete Prüfung | Was tatsächlich rot wurde |
| --- | --- | --- |
| die Anzeige ist eine eigene Liste: `surname` steht da, der Import nimmt es nicht | jeder angezeigte Name wird auch genommen | 1 Prüfung, und sie nennt den Namen: `every name of the screen is taken for the member number :: 8 taken, refused: surname` |
| `name` steht in der Liste der Vornamen | der Import liest `Name` als Nachnamen | 2 Prüfungen: der Import lehnt die Datei ab, und die Werte stehen nirgends |
| `name` fehlt in beiden Listen | dasselbe | dieselben 2 Prüfungen |
| die drei Sätze über der Tabelle fehlen | sie nennen ganze Zelle und Großschreibung | 2 Prüfungen, beide mit dem Wortlaut, den sie vermissten |
| der Block wird nicht gerendert | der Rahmen steht auf der Seite | 8 Prüfungen: der Rahmen, beide Sätze, die Zahl der Felder und die vier Spalten |
| eine Zeile der Anzeige fehlt, der Import liest sie | vier Zeilen, eine je Feld | 2 Prüfungen: `3 rows: E-Mail-Adresse Mitgliedsnummer Nachname` und die Spalte Vorname mit `0 taken` |
| eine fünfte Zeile nur in der Anzeige | vier Felder, und kein Etikett ohne Namen | 2 Prüfungen: `5 rows` und die unbekannten Zeilen |

Fünf Fehlerbilder an dieser Reihe waren Fehler im Prüfrahmen, nicht im Plugin:

- **Der Rahmen behandelte die Liste als Wörter.** Zwei der Namen enthalten ein
  Leerzeichen (`first name`, `member number`), und eine Schleife über eine
  Wortliste hätte sie zerschnitten und den Import mit `first` gefragt. Die Namen
  werden jetzt zeilenweise gelesen und in einem Feld getrennt.
- **Eine Prüfung suchte einen Vornamen in einer Zeile, die es nicht geben kann.**
  Die vier Namen standen in einer durch Leerzeichen getrennten Liste, und das
  Muster `names*Vorname*` passte auf kein einziges Wort. Jetzt wird die Zeile des
  Vornamen geholt und die Namen als **Menge** mit Komma an beiden Enden verglichen,
  damit ein Name, der zufällig mit `name` beginnt, nicht für `name` gehalten wird.
- **Die erste Fassung der Zählprüfung war eine Magic Number.** Sie verlangte „mehr
  als 20 Namen“, um eine leere Liste zu fangen, und wäre an einem echten Header
  des Vereins rot geworden, der weniger Namen mitbringt. Jetzt steht dort die
  Zahl der **Zeilen** — vier, eines je Feld —, und die ist eine Konstante der
  Sache und keine Vermutung über die Namen darin.
- **Eine fünfte Zeile wäre durchgerutscht.** Der `case` über die Felder hat einen
  Zweig `*) continue`, und eine Zeile mit unbekanntem Etikett wäre stillschweigend
  übersprungen worden — also nie dem Import angeboten. Die Übersprungenen werden
  gezählt und gemeldet.
- **Das Fehlerbild mit dem fünften Feld war falsch gewählt.** Es hing ein Feld
  `Telefon` in **beide** Listen, und der Import brach daran zusammen: alle vier
  Zeilen fielen in den unbekannten Zweig, und die Prüfung meldete acht rote
  Zeilen für eine Behauptung, die nur eine betraf. Das Bild ist jetzt eine Zeile
  nur in der Anzeige — ein Handfehler neben dem Import, nicht in ihm.

Was diese Reihe **nicht** beweisen kann, steht in derselben Liste: Der Import kann
einen Namen annehmen, den die Seite nicht nennt, und die Prüfung sieht das nicht.
Sie liest die Namen vom Bildschirm; für die andere Richtung bräuchte sie eine
zweite Liste, und eine zweite Liste in einem Test ist genau das, was altert. Die
Lücke ist damit die Umkehrung der, die die Reihe schließt, und sie ist unter
„Was die Umgebung nicht prüft“ vermerkt.

### Die achte Reihe (1 Fehlerbild am Textteil der Mail)

Sie ging die Fußzeile in der Nachricht an. Die Behauptung war: **jeder Teil der
Nachricht trägt sie**. Bis 1.11.2 stand sie nur im HTML-Teil, und die Prüfung
`mail-mime.php` suchte sie im **ganzen MIME-String** — sie fand sie im HTML-Teil
und meldete Erfolg. Eine Prüfung, die alle Teile auf einmal liest, ist bei
`multipart/alternative` genauso blind wie eine, die die ganze Adminseite liest.

| Fehlerbild | Erwartete Prüfung | Was tatsächlich rot wurde |
| --- | --- | --- |
| `AltBody` bekommt wieder den rohen Text | der Textteil trägt die Fußzeile | 3 Prüfungen: `the plain text part carries the footer too`, die Leerzeile zwischen den Abschnitten, und die Fußzeile hinter dem Text |

Die HTML-Prüfung blieb dabei grün — zu Recht, sie behauptet etwas anderes. Genau
das ist der Punkt: dieselbe Zeichenkette ist in einem Teil vorhanden und im
anderen nicht, und eine Suche über beide unterscheidet das nicht. `fg_mime_part()`
liest jetzt einen Teil einzeln, und beide Teile werden einzeln geprüft.

Zwei Prüfungen kamen beim Schreiben hinzu, weil das Nachrüsten des Textteils zwei
neue Fehlerquellen mitbringt, die es vorher nicht gab: Der Textteil enthält jetzt
Umlaute, und ohne `charset=UTF-8` kämen sie als zwei Fragezeichen an, während die
Prüfung nach den ASCII-Zeilen der Fußzeile weiterhin grün bliebe. Und die Fußzeile
darf kein HTML des Layouts in den Textteil tragen.

### Die neunte Reihe (8 Fehlerbilder an den Links in beiden Teilen)

Sie ging die Links der Nachrichten an. Die Behauptung war: **jeder Link ist im
HTML-Teil ein `<a href>` mit einem Wortlaut, der die Handlung nennt, und im
Textteil steht die Adresse.** Bis 1.13.0 stand die Adresse als Klartext im
Wortlaut, und `paragraphs()` escapte jede Zeile — sie war im HTML-Teil damit
weder klickbar noch kürzbar, und aus einem Nebensatz wurden fünfundneunzig
Zeichen in einer Zeile.

| Fehlerbild | Erwartete Prüfung | Was tatsächlich rot wurde |
| --- | --- | --- |
| `{{Loeschlink}}` wird wie jeder andere Platzhalter durch `strtr()` ersetzt | der HTML-Teil trägt einen Anker | 2 Prüfungen: `the html part has one anchor per link` und `no placeholder is left in the html part` |
| der Anker entsteht aus dem escaped Text | keine Adresse steht als Text im HTML-Teil | `no address of a link is left standing in the text of the html part` (sie prüft es über den HTML-Teil allein, mit abgenommenen `href`-Attributen) |
| der Wortlaut des Ankers wird nicht escaped | Markup des Vereins bleibt Text | `a wording from the club brings no markup into the html part` |
| `esc_url()` fehlt am `href` | ein Attribut mit `&` wird zur Entität | `an ampersand in an address is written as an entity` |
| ein Link ohne eigenen Wortlaut fällt auf den Vorgabewortlaut | Text gespeicherter Texte bleibt ein Link | `a link without a wording takes the one of the message` |
| ein Wortlaut hinter einem Doppelpunkt gilt für **jeden** Platzhalter | ein Name, der kein Link dieser Nachricht ist, wird abgelehnt | 2 Prüfungen: `a wording behind a link of another message is refused` und `a wording behind a name that is no link at all is refused` |
| die Ablehnung nennt nur die nackten Namen | die erlaubte Menge nennt beide Formen | `the complaint names the form with a colon as allowed` |
| der Textteil bekommt denselben Wortlaut wie das HTML | der Textteil trägt die Adresse | `the text part carries the address of the confirmation` |

Eine Prüfung in dieser Reihe ist **keine** Prüfung, sondern eine Bedingung für
die anderen: Der Vorgabewortlaut eines Links steht in zwei Listen derselben
Definition, `links` und `placeholders`, und ein Name, der nur in einer davon
steht, ist ein Link, den die Nachricht gar nicht anbietet. `mail-mime.php`
vergleicht die beiden Listen für alle fünf Nachrichten und verlangt zusätzlich
einen Wortlaut und eine Beispieladresse. Ohne diese Prüfung wären die Zeilen
darüber grün, während die Vorschau im Adminbereich einen Link zeigt, der
nirgends hingesetzt wird.

Und eine Prüfung musste an einem **Muster** wachsen, nicht an einer Zeile:
Das Muster, das übrig gebliebene Platzhalter in der Vorschau suchte, kannte
nur Buchstaben — `\{\{[A-Za-z]*\}\}` — und wäre für einen Wortlaut hinter
einem Doppelpunkt blind gewesen. Gegengerechnet an sechs Fallen:

| Fall | altes Muster `\{\{[A-Za-z]*\}\}` | neues Muster `\{\{[^{}]*\}\}` |
| --- | --- | --- |
| `{{Loeschlink}}` | gefunden | gefunden |
| `{{Loeschlink:Fahrgemeinschaft löschen}}` | **nichts** | gefunden |
| `{{Abmeldelink:Teilnahme am Arbeitsdienst abmelden}}` | **nichts** | gefunden |
| `{{Loeschlink:}}` | **nichts** | gefunden |
| `{{Loeschlink:{{Name}}}}` | findet `{{Name}}` | findet `{{Name}}` |
| Wortlaut über zwei Zeilen | **nichts** | gefunden |

Eine Prüfung, die an einem Muster gewachsen ist, ist an genau einem Muster
blind — und dieses hier wäre es für die Form geworden, die diese Fassung
gerade eingeführt hat.

## Umstieg von den eigenen Beitragstypen

Die Testinstanz lief ursprünglich mit einer Fassung, die eigene WordPress-Beitragstypen
verwendete. Beim Umstieg wurden einmalig auf dieser Instanz

- die alten Beiträge der Typen `fg_arbeitsdienst` und `fg_fahrgemeinschaft` samt `_fg_*`-Meta gelöscht und
- die alten Rollenberechtigungen (`manage_fahrgemeinschaften`, `edit_fahrgemeinschaften`,
  `publish_fahrgemeinschaften`, `delete_fahrgemeinschaften` und Verwandte) entfernt.

Das Plugin macht das nicht selbst, weil es diese Daten nicht kennt; auf einer echten
Installation sind die beiden Schritte vor dem Aktivieren der neuen Fassung manuell zu
machen. `tests/bootstrap.php` meldet in Zeile `bootstrap: plugin capabilities`, falls
solche Berechtigungen noch vorhanden sind.

## Umstieg auf die Mitgliederverwaltung (Schema 1.2.0)

Vor dieser Fassung lag die Teilnehmerliste eines Arbeitsdienstes als Textspalte
`participants` in seiner Zeile: eine E-Mail-Adresse je Zeile, von Hand gepflegt. Seit
`1.2.0` gibt es die Tabellen `wp_fg_members` und `wp_fg_event_members`, und die
Migration leert diese Spalte **einmalig**:

```php
FG_Schema::clear_legacy_participants();
```

Der Inhalt wird nicht übernommen. Eine Adresse allein sagt nicht, wer das Mitglied ist,
und die neue Anmeldung verlangt Nummer und Adresse zusammen; ein Versuch, die
Adressen zuzuordnen, wäre Raten. Wer die alten Listen behalten will, hat sie vorher
zu sichern, oder er pflegt die Mitgliedernummer nach — der Import nimmt eine
Mitgliedsnummer auf, und ab dem zweiten Lauf findet er dieselbe Nummer wieder und
aktualisiert sie.

Die Spalte `participants` bleibt in der Tabelle stehen, damit eine ältere Fassung, die
zurückgerollt wird, nicht an einem unbekannten Feld scheitert. Sie ist ab `1.2.0`
immer leer.

## Mail-Auswertung

`tests/mail-log.php` wird als `wp-content/mu-plugins/fg-mail-log.php` eingespielt. Es
hängt an `pre_wp_mail`, schreibt Empfänger, Betreff, Header, den effektiven Inhaltstyp,
den Text und den Rückgabewert in die Tabelle `wp_fg_test_mail_log` und meldet eine
Zustellung als erfolgreich zurück. Damit ist belegt, dass das Plugin `wp_mail()` mit den
richtigen Werten aufruft, ohne dass ein SMTP-Server nötig ist.

Weil der Recorder `wp_mail()` an dieser Stelle abbricht, sieht er nur das, was das Plugin
als Argument übergibt — und das ist der Text. `wp_mail()` hat kein Argument für die zweite
Alternative einer `multipart/alternative`-Nachricht; die HTML-Fassung setzt das Plugin über
die Aktion `phpmailer_init` in `Body` und `AltBody`. Der Recorder kann diese Eigenschaften
nicht mehr sehen, deshalb prüft `tests/mail-mime.php` die fertige Nachricht getrennt: Es baut
sie in der Reihenfolge von `wp_mail()` nach, ruft `preSend()` auf und untersucht die Bytes,
die hinausgehen würden. Geprüft werden der Inhaltstyp, die Reihenfolge beider Teile, das
Escaping eines Besuchereingabewerts, die Fußzeile und das eingebettete Logo. Die Fußzeile
hat einen eigenen Abschnitt `[4]`: das eine Feld mit seiner Leerzeile zwischen den Abschnitten,
die drei Felder der Fassung davor, die beim Lesen zu einem Block verbunden werden, und die
Sache, dass ein gespeichertes Feld nicht zu einem liegengebliebenen der alten drei addiert
wird.

Die Option `fg_test_mail_fail=1` lässt die Zustellung fehlschlagen. Damit werden die
Fehlerpfade `email_failed` und `publish_failed` über echtes HTTP geprüft, inklusive
Rücknahme der Veröffentlichung und der Wiederverwendbarkeit desselben Bestätigungslinks.
Dieselbe Option prüft den dritten Fehlerpfad, den es erst mit der Mitgliederverwaltung
gibt: Geht die E-Mail mit dem Abmeldelink nicht raus, nimmt `register_member()` die
Anmeldung wieder aus der Tabelle (`delete_registration_by_pair()`), der Zähler
`mail_send_failed` steigt und der Platz wird wieder frei. Geprüft wird beides getrennt,
weil eine Anmeldung ohne Abmeldeweg nur noch über den Adminbereich zurückgenommen werden
kann. Dass danach ein zweiter Versuch mit derselben Nummer eine ganz normale
`registered`-Meldung liefert und nicht `already_registered`, steht in derselben Folge.

### Der Recorder ist abschaltbar

`pre_wp_mail` bricht `wp_mail()` ab. Jedes andere Mail-Plugin, das denselben Filter
auswertet, sieht deshalb keine Nachricht. SureMails prüft ihn an zwei Stellen
(`inc/emails/handler/mail-handler.php` und `inc/emails/default-mail-handler.php`) und
kehrt sofort zurück, sobald ein Filter einen Wert ungleich `null` liefert. Mit aktivem
Recorder landet dort also nichts, und die Simulation von SureMails zeigt keinerlei
Einträge.

Deshalb ist der Recorder **standardmäßig aus** und wird nur für den Testlauf eingeschaltet:

- `tests/bootstrap.php` setzt in der Phase `configure` die Option `fg_test_mail_enabled=1`.
- `tests/mail.sh` schaltet sie bei einem eigenständigen Lauf selbst ein und meldet das.
- `tests/run-all.sh` schaltet sie im Schritt `5/5 handover` wieder ab. Die Instanz ist
  danach für die Handprüfung mit SureMails oder echtem SMTP bereit. `KEEP_RECORDER=1`
  lässt sie aktiv.
- `WPDEV/setup.sh` gibt den Zustand als letzte Zeile aus: `mail recorder: …`.
- `tests/state.php recorder on|off` schaltet ihn von Hand um und meldet es in einer Zeile.

Ohne Recorder läuft der Nachrichtentransport unverändert über `wp_mail()`; am Plugin ist
dafür keine Zeile anzufassen.

**Nach jeder von Hand gefahrenen Suite ausschalten.** `run-all.sh` erledigt das im
Schritt `5/5 handover`, eine von Hand gefahrene Suite nicht. Am Lauf vom 28.09.2026 hat
das zwei der sechs roten Zeilen des Admin-Laufs verursacht: Die fünf Prüfungen des
Abschnitts `[13b]` — die Anrede der Nachrichten — lesen das Mail-Protokoll, und bei
ausgeschaltetem Recorder steht dort nichts, woraus sie lesen könnten. Sie melden
zuverlässig `KEINE-MAIL`, was wie ein Fehler im Plugin aussieht und keiner ist. Wer
eine Suite einzeln fährt, schaltet den Recorder vorher mit `state.php recorder on` ein
und danach wieder aus.

## Handprüfung mit SureMails

Für die Sichtprüfung der Mails ist in der Testinstanz **SureMails** installiert und aktiv.
Deren Simulation ist eingeschaltet (`email_simulation=yes` in `suremails_connections`), das
Mail-Log ist aktiv (`log_emails=yes`). Eine eingetragene Verbindung ist nicht nötig, die
Simulation versendet nichts.

Damit die Nachrichten dort ankommen, muss der Mail-Recorder ausgeschaltet sein (siehe oben).
Der Zustand ist die Voraussetzung für:

1. Eine echte Einreichung über das öffentliche Formular. Der Bestätigungslink steht dann
   im Mail-Log unter SureMails → Email Log, Spalte „Body“.
2. Eine echte Anmeldung zu einem Arbeitsdienst auf der `[arbeitsdienste]`-Seite. Die
   Nachricht mit dem Abmeldelink steht dort unter demselben Ersteller; sie nennt keinen
   Namen, nur den Dienst und den Link.
3. Ein Kontaktaufruf zwischen zwei Mitgliedern desselben Dienstes. Erwartet werden zwei
   Einträge: einer an den Ersteller, einer an den Anfragenden.
4. Ein Klick auf den Bestätigungslink einer Fahrgemeinschaft. Der Status `sent` erscheint,
   und die Meldung zur Veröffentlichung mit dem Lösch-Link wird geloggt.
5. Ein Klick auf den Abmeldelink einer Anmeldung. Erwartet wird zuerst die Rückfrage und
   keine Löschung; gelöscht wird erst nach dem zweiten Klick.

Der Recorder ist eine Einrichtung der Testumgebung. Am Plugin ist für SureMails nichts zu
tun: `class-fg-mailer.php` ruft ausschließlich `wp_mail()` auf und setzt davor nur
`wp_mail_from`, `wp_mail_from_name` und `wp_mail_content_type` per Filter, die es danach
wieder entfernt.

## Was die Umgebung nicht prüft

- **Nicht den echten Export der Mitgliederverwaltung.** Der Import erwartet eine
  CSV-Datei mit einer Kopfzeile und den vier Spalten für Mitgliedsnummer, E-Mail-Adresse,
  Vorname und Nachname. Welche Überschriften eine Mitgliederverwaltung dafür schreibt,
  ist eine Vermutung: `class-fg-member-import.php` trägt pro Feld eine Liste von
  Alternativen, und der Test füttert sie mit Namen, die plausibel sind, nicht mit den
  des Vereins. Geprüft ist damit der Importweg, nicht die Erkennung der Spalten des
  Vereins. Bis eine echte Kopfzeile vorliegt, ist das eine Vermutung, und sie steht an
  genau einer Stelle im Code.
- **Keinen echten SMTP-Transport.** Es läuft kein Mailserver; die Zustellung wird nur
  simuliert. Geprüft wird, was das Plugin übergibt; die Zustellung selbst ist eine
  Betriebsvoraussetzung der Installation
  (siehe `arbeitsdienste/README.md`, Abschnitt „Technische Voraussetzungen“).
- **Keine Fremd-Plugins und Themes.** SureMails ist installiert, weil der Mailversand
  sonst nicht prüfbar wäre; es ist kein Teil der Lieferung und die Testläufe hängen
  nicht davon ab. Alle anderen Erweiterungen Dritter sind nicht installiert; das
  Zusammenspiel mit ihnen ist nicht Teil dieser Prüfung.
- **Kein Lasttest.** Die Statistik schreibt ohne Sperre zurück; das ist als bekannte
  Einschränkung dokumentiert und nicht als Test abgedeckt. Dasselbe gilt für zwei
  Mitglieder, die sich im selben Augenblick um den letzten Platz eines Dienstes bewerben:
  `UNIQUE (event_id, member_id)` verhindert nur das doppelte Anmelden desselben
  Mitglieds, nicht die doppelte Belegung des letzten Platzes durch zwei verschiedene.
  Geprüft ist die Zählung hinterher, nicht das Verhalten im Gleichstand.
- **Nicht die Arbeitsdienstliste über HTTP.** Die Testinstanz ist an beiden Ports
  erreichbar, und `http.sh` prüft die Umleitung an der Fahrgemeinschaftenseite und an
  der Handhabungsseite. Die Arbeitsdienstliste wird umgeleitet oder nicht, je nachdem ob
  das Plugin es tut — und es tut es absichtlich nicht. Dieser Fall ist damit nicht
  abgedeckt, und das ist eine Entscheidung und keine Lücke im Aufbau; siehe
  `arbeitsdienste/README.md`, Abschnitt „Bewusste Abweichungen“.
- **Keine Berechtigungen unterhalb des Verwalters.** Die Suite meldet sich als Verwalter an
  und hat damit `edit_posts` und `delete_posts` gleichzeitig. Jede `current_user_can()`-Prüfung
  im Plugin bleibt damit unberührt: Sie kann immer nur annehmen, dass sie nichts verweigert.
  Geprüft wird deshalb an jeder Stelle, was mit der Berechtigung **passiert** — der
  Löschvorgang, der eine Anmeldung entfernt, verlangt `delete_posts` und läuft darum über
  `check_admin_referer()` und die gespeicherte Meldung, nicht über einen Link — aber nicht,
  dass sie ohne die Berechtigung einmal greift. Das trifft den Abschnitt `[12]` genauso wie
  das endgültige Löschen eines Arbeitsdienstes; die fehlende Prüfung ist beim Massenlöschen
  zusätzlich unter den Fehlerbildern der sechsten Reihe aufgeführt worden, mit dem
  Ergebnis „keine“ und ohne den Versuch, das zu überspielen.
- **Kein Name, den der Import annimmt und die Seite nicht nennt.** Die Prüfungen der
  siebten Reihe lesen die Namen vom Bildschirm und bieten sie dem Import an; damit
  ist bewiesen, dass **jeder angezeigte Name funktioniert**, aber nicht, dass **jeder
  funktionierende Name angezeigt wird**. Die andere Richtung ließe sich nur mit einer
  zweiten Namenliste im Test prüfen, und eine zweite Liste in einem Test ist genau
  die, die veraltet: Sie würde grün bleiben, während die Seite veraltet. Der Preis
  ist eine Lücke zugunsten einer Seite, die dem Verein keinen Namen anbietet, den
  er nicht probieren kann — und die Liste, die er probieren muss, steht in einer
  Datei von genau einer Stelle im Plugin.
- **Keine Barrierefreiheits- und Browserprüfung.** Die HTML-Ausgabe wird auf Struktur und
  Escaping geprüft, nicht auf Darstellung in verschiedenen Browsern. Für die beiden
  `details`-Schaltflächen heißt das auch: Es wird geprüft, dass die offene und die
  geschlossene Beschriftung im Stylesheet vorhanden sind, nicht, dass ein bestimmter
  Browser den Marker des Elements wegräumt.
- **Kein Mailclient, der auf einen Link klickt.** Die Prüfungen der neunten Reihe lesen
  die Bytes der fertigen MIME-Nachricht: Sie finden den `<a href>` mit der richtigen
  Adresse und dem Wortlaut als Textinhalt, und sie rechnen die `href`-Attribute von der
  Seite ab, um zu behaupten, dass keine Adresse sichtbar stehenbleibt. Damit ist
  **behauptet**, nicht **gesehen**, dass die Anzeige stimmt. Outlook, der Mail-Client von
  Apple und die von Googlemail entfernen oder ersetzen unter Umständen einzelne
  `style`-Angaben, und ein Anker, dem der Client die Farbe und die Unterstreichung
  nimmt, ist immer noch ein Anker — aber das steht hier nicht gemessen. Die
  Handprüfung mit SureMails zeigt die Nachricht so, wie der Client sie anzeigt, und
  dort ist der Link anzuklicken; sie ist der einzige Weg, der diese Lücke schließt.
