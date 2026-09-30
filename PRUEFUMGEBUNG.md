# Prüfumgebung

Diese Datei beschreibt, wie das Plugin funktional geprüft wird: welche Umgebung dafür
verwendet wird, wie sie jederzeit wiederherstellbar ist und was die vier Testläufe
tatsächlich belegen. Sie gehört nicht zum Plugin und wird nicht mitgeliefert.

Letzter Lauf: 30.09.2026 — **öffentliches HTTP 236, Mail-Ebene 124, Admin-Ebene 658,
0 Fehler**, gegen den Stand **Plugin 1.25.2, Schema 1.8.0**, dazu **achtundzwanzig von
achtundzwanzig Gegenproben mit dem gestellten Fehlerbild rot**. HTTP und Mail sind
unverändert: Diese Fassung berührt nichts, was ein Browser liest und nichts, was in einer
E-Mail steht. Die Admin-Ebene ist um 2 höher: **Eine** falsche Behauptung aus 1.25.0 wurde
ersetzt (die Teilnehmerliste suchte über eine ganze Seite nach einem Wort, das im
Vornamen des Prüfmitglieds stand) und drei kamen dazu, die die Tabelle über den Namen ihrer
Spalte lesen; eine Zeile Aufräumen stand doppelt und ist weg. **Die Regel für die
Fassungsnummern hat sich geändert:** ab 1.25.1 nur noch Bugfix-Sprünge, `Z` steigt bei
jeder Änderung. Die Mail-Ebene 124 ist die Summe aus 66 Prüfungen im
Shell-Satz `mail.sh` und 58 im MIME-Satz `mail-mime.php`; `mail.sh` addiert beide selbst und
gibt 124 aus. Die HTTP-Zahl ist um eine Prüfung höher als bei 1.20.0, und die Mail-Ebene um
acht: sechs davon sind der neue Abschnitt `[5b]` (die sechs Formen eines Mail-Händlers), zwei
sind die ersetzten Behauptungen über Inhaltstyp und Rumpf, und der Rest verteilt sich auf die
Prüfungen, die an die HTML-Form der Nachricht angepasst wurden.

**Die CLI-Suite ist bei diesem Lauf nicht gefahren worden.** Sie stand zuletzt mit **CLI 533**
gegen den Stand **Plugin 1.16.0** (Commit `9883f85`) und ist damit die einzige der vier
Zahlen, die nicht zu diesem Stand gehört; sie zu wiederholen wäre ein Lauf, der am Anfang
alle Arbeitsdienste, Fahrgemeinschaften und Mitglieder löscht, und dafür lag an diesem
Nachmittag keine Zustimmung vor. Das ist inzwischen über zwei Fassungen mehr als eine Lücke in der
Tabelle: **Die Fassung 1.20.0 ändert einen Datenbankschlüssel**, und die Prüfung, die ihn
ansieht, liegt in der CLI-Suite. Sie ist geschrieben, aber **nicht gelaufen** — siehe den
Abschnitt zu 1.20.0. Wer nachrechnen will, findet die Zahl also nicht zu 1.17.0,
sondern zu 1.16.0 — und diese Lücke ist eine bewusste und keine vergessene. Die drei
HTTP-Suiten brauchen diese Zustimmung nicht, weil sie ihre Fixture selbst aufbauen und
sonst nichts löschen; `http.sh` und `mail.sh` leeren dabei nur die Daten, die ihre eigene
Fixture eben angelegt hat.

Die Zahlen vom Vortag desselben Tages waren **CLI 527, Admin-Ebene 555, HTTP 201,
Mail-Ebene 113** gegen **Plugin 1.15.0**. Dazwischen liegt die Fassung 1.16.0 (Anrede
mit beiden Namen, Kontaktformular mit der Mitgliedsnummer) und der Satz Prüfungen,
der zu ihr gehört. Jede der vier Zahlen ist der Wert, den die Suite selbst ausgegeben
hat, und keine davon ist gerechnet oder aus dem Diff hochgerechnet: Ein Teil der
neuen Prüfungen steht in einer Schleife über die Fälle des Paares und läuft deshalb
mehrfach, die Differenz zu den Zeilen in der Datei ist also größer als die Zahl der
neuen Zeilen — wer nachrechnen will, muss die Schleife mitzählen. Die Zahl 166 aus dem
Kopf desselben Tages war falsch gezählt — der MIME-Satz stand ein zweites Mal darin.
Nachgezählt am damaligen Stand: 47 Aufrufe im Shell-Satz plus 59 im MIME-Satz, also
106. Das ist an den Zeilen der Suite nachgewiesen und nicht aus dem Log abgelesen,
weil der damalige Lauf nicht wiederholbar ist, ohne das Plugin auf 1.14.0
zurückzusetzen.

Die vier Zahlen stammen aus **einem** Lauf gegen den Endstand, in der
Reihenfolge CLI zuerst: Die CLI-Suite leert die Tabellen, und `http.sh` und
`mail.sh` bauen ihre Fixture ohnehin vorher neu auf — das ist der Grund für diese
Reihenfolge und nicht der Weg des geringsten Widerstandes. Während der Arbeit sind
die Suiten mehrfach gegen Zwischenstände gelaufen, nach jeder Gegenprobe und nach
jeder Korrektur an einer Prüfung; gezählt wird nur dieser eine Lauf. Das ist die
einzige Zahlensorte, die sich nicht von selbst versteht: Eine Suite, die nach jeder
Änderung neu gezählt wird, meldet am Ende immer eine Zahl, und ohne die Regel
„ein Lauf, ein Stand" wäre in diesem Dokument eine Zahl stehen, die zu irgendwann
stand, aber nicht zu dem, was ausgeliefert wird.

Alle vier Suiten sind an diesem Tag gegen denselben Stand gelaufen, zuerst die CLI
über `WPDEV/setup.sh` und dann die drei HTTP-Suiten von Hand. Die CLI-Suite ist
mit ausdrücklicher Zustimmung gefahren, weil sie am Anfang alle Arbeitsdienste,
Fahrgemeinschaften und Mitglieder löscht. `run-all.sh` ist damit zum ersten Mal
nicht der Weg dieses Tages gewesen, obwohl `setup.sh` inzwischen wieder läuft: Vor
diesem Lauf trug es zwei Fehler, die beide den Abbruch vor der Installation
verursachten — es spiegelte `fahrgemeinschaften/` statt `arbeitsdienste/` in das
Plugin-Verzeichnis, und es zählte Fahrten über `FG_RIDE_STATUS_PENDING`, eine
Konstante, die mit dem Zustand selbst verschwunden ist. Beide sind repariert; die
zweite Zeile ist nicht umgeschrieben, sondern weggefallen, weil ein Filter auf
einen Status, den jede Zeile hat, immer dasselbe zählt wie `count_rides()`.

Der Lauf ist kein `run-all.sh`, und die CLI-Suite ist auch nicht über den normalen Weg
gefahren: `smoke.php` kennt nur den Schritt `all`, und der löscht am Anfang alle
Arbeitsdienste, Fahrgemeinschaften und Mitglieder. Das ist beim ersten Versuch dieser
Reihe passiert und hat sieben von Hand eingetragene Arbeitsdienste und zwei Fahrten
gekostet; seitdem wird das nur noch mit ausdrücklicher Zustimmung getan. Der CLI-Lauf
vom 28.09.2026 ist mit Zustimmung gefahren, gegen denselben Stand, den die drei
anderen Suiten desselben Tages gesehen haben, und zwar zuerst: Ein Lauf, der die
Tabellen leert, zerstört keinen Zustand, den die anderen Suiten brauchen —
`http.sh` und `mail.sh` bauen ihre Fixture ohnehin vorher neu auf. Vor diesem Lauf
ist die Verwaltung aufgeräumt worden: 142 veröffentlichte Seiten namens
„Fahrgemeinschaften“ sowie „Arbeitsdienste“ (ID 106 und 108) sind gelöscht,
geblieben ist die Seite ID 98 mit dem Shortcode, weil `http.sh` und `mail.sh` sie
brauchen. Das Aufräumen ist eine eigene Handlung gewesen und nicht Teil eines Laufs.

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
Fehlerquelle, die sich als Fehlschlag zeigte. Seit 1.15.0 gibt es den zweiten
Filter der Fahrtliste nicht mehr, also ist auch der Fall aus dieser Zeit nicht mehr
da: Es bleibt der Filter nach dem Arbeitsdienst, und der wird in `[4b]` geprüft —
die Fahrt des gewählten Dienstes bleibt stehen, die des zweiten verschwindet, und
ohne Parameter stehen beide da. Der Filter ist mit dem Zustand weggekommen, weil
er zwei Zeilen nicht hätte unterscheiden können.

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
`fg_schema_version` auf `1.5.0`, die fünf Tabellen `wp_fg_events`, `wp_fg_rides`,
`wp_fg_members`, `wp_fg_event_members` und `wp_fg_mail_templates` vorhanden, die Spalte
`member_id` in `wp_fg_rides` angelegt, `fg_daily_cleanup` geplant, keine
Rollenberechtigung des Plugins, Mail-Log leer.

## Testlauf

```bash
bash tests/run-all.sh
```

`run-all.sh` ruft zuerst `setup.sh` auf und startet dann vier Suiten. Jeder Lauf ist
isoliert: `smoke.php` und `http_setup.php` löschen vorher alle Arbeitsdienste,
Fahrgemeinschaften und Mitglieder sowie die Statistik-Option, es gibt also keinen Zustand vom Vorlauf.

| Suite | Datei | Vorgehen |
| --- | --- | --- |
| CLI | `tests/smoke.php` | WordPress im Container, Abschnitte 0–20: Tabellen, Aktivitätsgrenze, öffentliche Seite samt beider Leermeldungen, Token-Links, Kontakt, Löschung, Ablehnungen, Admin, Bereinigung, Datenschutz, HTTPS, Markup-Hygiene und die Mitgliederverwaltung; dazu die Abschnitte `[2a]` (die vier freiwilligen Angaben eines Arbeitsdienstes), `[3c]` (die Liste der Arbeitsdienste) und `[1b]` (die Migration: eine Fahrt mit einer Adresse, die zu einem Mitglied gehört, wird über einen JOIN an dieses Mitglied gehängt; eine mit einer fremden Adresse wird gelöscht und gezählt; beide Alt-Spalten werden geleert; eine Fahrt ohne Adresse und ohne Mitglied bleibt stehen; eine Zeile im Zustand vor der Veröffentlichung wird gelöscht und im selben Zähler gemeldet) |
| Öffentlich | `tests/http_setup.php` + `tests/http.sh` | `curl` gegen Apache über TLS: Weiterleitung, Standalone-Seiten mit Kopfzeilen, 405 bei GET, Hinweise, keine personenbezogenen Daten im HTML, Aufbau der kompakten Liste, Reihenfolge von Sprunglink, Liste und Formular, Rückkehrweg mit Sprungziel, Abfahrtsbereich gegen Kontaktdaten über den Zähler `publish_personal_data`, Verhalten der Schaltflächen im Stylesheet; dazu die Abschnitte `[5]` (drei Paare, von denen je nur eine Hälfte stimmt, und ein Paar aus zwei fremden Werten: alle vier werden gleich beantwortet und gleich gezählt), `[5c]`/`[5e]` (der Abfahrtsbereich über die volle Breite, die drei Formulare des Plugins — zwei auf der Angebotsseite, eines auf der Dienstseite — fragen dasselbe Paar in denselben zwei Worten), `[5e]` (die Mitgliedsnummer als Schlüssel: das Formular fragt Nummer und Adresse, nennt keinen Namen mehr, und beide Längenbegrenzungen stehen im HTML), `[6]` und `[9]` (die Absage nennt beide Angaben, im Angebot- wie im Anmeldeformular mit demselben Satz), `[8]` für die Arbeitsdienstliste auf einer eigenen Seite und `[9]` für den vollständigen Weg von der Anmeldung über die E-Mail bis zum Abmelden |
| Mail-Ebene | `tests/mail.sh` + `tests/mail-mime.php` | Die Meldungen, die ein Browseraufruf wirklich an `wp_mail()` übergibt: Wortlaut, Empfänger, Zustellfehler, dazu `[4d]` (eine richtige Nummer mit fremder Adresse: kein Empfänger, keine Mail, Zähler wie bei einer ganz fremden Adresse). Dazu die fertige MIME-Struktur: `multipart/alternative`, Text als erste Alternative, HTML als zweite, eingebettetes Logo unter `cid:logo`, und die Links beider Teile: im HTML ein `<a href>` mit einem Wortlaut, der die Handlung nennt, im Text die Adresse in Klarschrift |
| Admin | `tests/admin.sh` | Echter Login, echte Roundtrips über `admin-post.php`: Navigation (Name des Obermenüpunkts, Reihenfolge und Markierung der sechs Unterseiten auf jeder Seite, die neue Seite E-Mails eingeschlossen), Arbeitsdienst anlegen, ändern, ungültige Daten, nonce-geschütztes endgültiges Löschen, Kaskadenlöschung, Einstellungen der E-Mail inklusive Pflichtprüfung, Mediathek-Auswahl und Vorschau; dazu die Abschnitte `[9]` (Mitgliederverwaltung), `[10]` (CSV-Import), `[11]` (Anmeldung zu einem Dienst) und `[12]` (Aufräumen um alle Mitglieder ohne Arbeitsdienst) und `[13]` mit `[13b]` (Anrede der Nachrichten) und `[13c]` (Zurückhaltung bei einem unbekannten Platzhalter) — Wortlaut der vier E-Mails, Platzhalter je Nachricht, Vorschau, Zurücksetzen |

Zwei Eigenheiten der Suiten, die man kennen muss, bevor man einem Fehlschlag traut:

- **Jede Suite baut ihre Fixture selbst auf.** `http_setup.php` liest und schreibt
  `tests/fixture.json`; `http.sh` verbraucht es. Wird zwischen zwei Läufen keine neue
  Fixture gebaut, laufen `[1]` bis `[6]` gegen Einmalwerte und schlagen mit
  `invalid_token` fehl — nicht, weil das Plugin etwas kaputt gemacht hätte, sondern
  weil die Werte schon verbraucht waren. Das gilt auch, wenn eine Suite von Hand
gefahren wird statt über `run-all.sh`: `admin.sh` braucht für den Importbericht und für
ein paar Zählerstände das Mitglied `0042` in der Datenbank und liest die Fixture-Datei nicht.
  `http.sh` läuft dann gegen leere Werte und meldet 24 rote Zeilen, von denen keine
  einen Fehler des Plugins bezeichnet. Vor jeder von Hand gefahrenen Suite also
  `http_setup.php` laufen lassen.
- **Eine Suite, die man zur Fehlersuche zweimal fährt, verbraucht ihre Fixture
  selbst.** Am 28.09.2026 ist das mit 18 roten Zeilen in `http.sh` passiert: Der
  erste Lauf hatte die Vormerkung der Fixture bestätigt und die veröffentlichte Fahrt
  gelöscht, der zweite las diese Werte erneut und meldete sie als Fehler. Seit
  1.15.0 sind es die Löschungen in `[7]`, `[7b]` und `[7c]`, die eine Fixture
  verbrauchen, und die drei teilen sich ihre Werte absichtlich: Zwei Einträge der
  Fixture und der über das Formular eingereichte, jeder mit seinem eigenen Link.
  Wer `[7]` zweimal fährt, trifft denselben roten Bild. Der Grund war
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
prüfen, E-Mail öffnen, Link folgen, Seite lesen, Abmeldung abschicken, zweite
Abmeldung schicken, nachsehen, dass nichts passiert. Der Abmeldelink wird aus dem
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

`[13]` in `admin.sh` ist der Wortlaut der vier E-Mails. Drei Dinge darin sind nicht
selbstverständlich und deshalb hier festgehalten:

**Der Vorschau-Link wird nach seiner Nachricht gewählt, nicht als erster genommen.** Die
Seite trägt vier Vorschau-Links — einen je Zeile der Liste — und der erste gehört zur
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
| `gegenproben.txt` | Eine Zeile je Gegenprobe: `Suite ~~~ Datei ~~~ alte Stelle ~~~ neue Stelle ~~~ Muster`. Der Trenner der Felder ist die Dreier-Tilde, weil in einer Bruchstelle auch Pipes vorkommen; `@N@` steht für einen Zeilenumbruch und **nicht** `\n`, weil fast jede Bruchstelle in einer PHP-Zeichenkette liegt und dort `\n` zwei Zeichen für sich sind |
| `gegenprobe.sh` | Sichert die Datei, baut die Bruchstelle ein, kopiert das Plugin in den Container, lässt die Suite laufen, stellt die Datei wieder her. Er nimmt `admin`, `http` und `mail`; `smoke.php` steht nicht darin, weil die Suite am Anfang Arbeitsdienste, Fahrgemeinschaften und Mitglieder löscht und das nicht in ein Werkzeug gehört, das eine Datei vorsätzlich kaputt macht |
| `alle-gegenproben.sh` | Fährt die Liste der Reihe nach ab und schreibt ein Protokoll |

Drei Vorkehrungen, ohne die das Werkzeug sich selbst widerlegt: Es verlangt, dass die
alte Stelle **genau einmal** in der Datei vorkommt, und bricht sonst ab, und es
protokolliert die eingesetzte Zeile mit (`--- Gebrochen: …`). Ohne die ersten beiden hat
die erste Fassung dieser Reihe gemessen, was sie messen sollte, ohne es zu messen: Sie
teilte die Zeilen mit `cut -d'~'`, obwohl der Trenner `~~~` ist, bekam also einen leeren
Dateinamen, setzte nichts ein — und meldete ein Suite-Ergebnis. Das Ergebnis war echt, die
Gegenprobe dazu war es nicht.

Die dritte kam bei der Reihe zu Fassung 1.17.0 hinzu, und sie ist derselbe Fehler an
einer anderen Stelle: **Die Suite aß den Rest der Liste.** Die Schleife liest die Liste
über die Standardeingabe, und die Suite erbte sie — ein Lauf, der eine Datei über
`admin-post.php` prüft, liest gelegentlich selbst von der Standardeingabe, und danach
war die Liste leer. Der Lauf nach dem ersten war deshalb derselbe wie der erste, und
zwanzig Einträge im Protokoll waren zwei. Zwei Änderungen im Werkzeug: Die Liste läuft
über einen eigenen Kanal (`read -u 3 … 3<`), und die Suite bekommt `< /dev/null`. Beide
sind billig und beide sind beim Schreiben nicht vorherzusehen; ein Werkzeug, das sich
selbst prüft, findet sie erst, wenn es läuft.

Derselbe Lauf hat einen zweiten Fehler derselben Art gezeigt, nämlich in der Liste
selbst: Eine Bruchstelle über mehrere Zeilen muss als ein Platzhalter in der Listendatei
stehen und nicht als echter Zeilenumbruch. Sonst zerfällt eine Gegenprobe in so viele Zeilen,
wie ihre Bruchstelle Zeilen hat, und der Leser meldet für jede „Feldzahl 3 statt 5“.

Der Platzhalter war anfangs `\n`, und damit war die Liste bei der Gegenprobe zu Fassung
1.19.0 **nicht in der Lage, den Bruchpunkt auszudrücken**: Fast jede Bruchstelle in diesem
Plugin liegt in einer PHP-Zeichenkette, und dort steht `\n` als zwei Zeichen für sich da. Ein
Leser, der beide nicht unterscheiden kann, verwandelt beim Einlesen den Quelltext in einen
Syntaxfehler und meldet „nur 0 Fundstellen“. Der Platzhalter ist jetzt `@N@`, und die
Unterscheidung ist in der Datei `gegenproben-119.txt` derselbe Bruchpunkt, der vorher nicht
darstellbar war. Das ist keine Feinheit des Werkzeugs, sondern eine Einschränkung, die eine
Gegenprobe unmöglich machte: Ein Werkzeug, das eine Frage nicht stellen kann, meldet die
Abwesenheit einer Antwort nicht.

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
bleibt unverändert. Dasselbe gilt seit 1.15.0 für den Löschlink der
Fahrgemeinschaften: `claim_delete_token()` beansprucht das Token mit einem
`UPDATE … WHERE id AND delete_hash AND delete_expires`, und danach wird die Zeile
noch einmal gelesen. Nimmt man eine der beiden Prüfungen weg, bleibt die andere,
und für den Besucher sichtbar ändert sich nichts. Was
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
Eintrag trägt Angebotsart, Abfahrtsbereich und den Vorname des Mitglieds in einer Zeile, Feld und
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

Dass der einzige Freitext des Formulars — der Abfahrtsbereich — keine Kontaktdaten
annimmt, lässt sich nicht an der Meldung ablesen: Ein abgewiesener Eintrag und ein
Eintrag, der später an einer nicht passenden Kombination aus Nummer und Adresse
scheitert, antworten beide mit `not_created`. Der Unterschied steht nur im Zähler
`publish_personal_data`, den der Server selbst führt. Die Prüfung liest ihn vor dem
Absenden und danach und verlangt, dass er bei „Südstadt“, „Langwasser“,
„Nürnberg Nord“ und „S-Bahnstation Ostring“ stehen bleibt, bei einer Telefonnummer und
bei einer Straßenadresse aber steigt. Dafür nimmt sie bewusst eine Nummer mit einer
Adresse, die zu keinem für den Dienst angemeldeten Mitglied gehören: Dann entsteht
kein Eintrag, und die Probe hinterlässt nichts. Vor der Fassung 1.14.0 stand hier der
Vorname, und das Namensfeld war derselbe Gegenstand der Prüfung; das Feld gibt es
nicht mehr, und die Prüfung ist mit ihm auf den Abfahrtsbereich umgezogen statt
weggefallen. An ihre Stelle ist der Abschnitt `[5e]` getreten: Das Formular fragt
`fg_member_no` und `fg_member_email`, nennt weder ein Namensfeld noch die alte
Formulierung „Vorname oder Spitzname“, trägt beide Längenbegrenzungen im HTML und
sagt im Hinweis, dass beide Angaben zu einem Mitglied passen müssen. Die
Textprüfungen dieses Abschnitts sind gegen den Stand von `HEAD` geprüft: Aus dem alten
Plugin ausgeliefert schlagen sie fehl, unter anderem die wegen der Dativform
„persönliche**n** Kontaktdaten“ — mit der Endung `n` im Suchbegriff wäre sie auch
an der alten Fassung vorbeigelaufen und hätte nichts geprüft.

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
und schreibt über `FG_Repository` (`statuses`, `count`, `count-published`, `count-origin`,
`event`, `ride`, `make-event`, `make-ride`, `find-event`, `exists-*`, `delete-*`, `purge`),
damit die Skripte den Zustand der Installation prüfen können, ohne WordPress-Beiträge zu
kennen. Feldzugriffe laufen über eine Whitelist; jeder Befehl gibt genau einen Wert aus.
Dazu kommen für den Wortlaut der E-Mails `mail-text-rows`, `mail-text-body`,
`mail-text-subject`, `mail-text-set`, `mail-text-reset`, `mail-text-notices` und
`mail-text-clear` — und für die Nachrichten, die wirklich hinausgehen,
`mail-anrede`, `mail-greeting` und `mail-holdback`. `mail-text-set` schreibt ohne die
Prüfung des Formulars, weil sonst kein Formular den Zustand bauen könnte, den
`mail-holdback` prüft.

Drei Befehfe haben mit der Mitgliedsnummer als Schlüssel ihre Form geändert, und das ist
der Grund, warum sie hier stehen und nicht nur im Quelltext:

| Befehl | früher | jetzt |
| --- | --- | --- |
| `make-ride` | `<event_id> <mode> <origin> <alias> [published]` | `<event_id> <mode> <origin> <member_no> [published]` |
| `count-alias` | zählte die Fahrten mit diesem Namen | heißt `count-origin` und zählt die Fahrten mit diesem Abfahrtsbereich |
| `mail-anrede` | `<event_id> <ersteller_mail> <fragende_mail>` | `<event_id> <ersteller_nr> <fragende_nr> [geloescht]` |

`mail-anrede` nimmt Mitglieder**nummern**, weil die Mail über die Nummer geht: Der Rückfall
„Hallo“ ohne Namen ist auf diesem Weg nicht mehr erreichbar, weil jede Nachricht zu einer
Fahrgemeinschaft an ein Mitglied geht. Der vierte Schalter sagt, dass der Kontakt zu einer
Fahrt gehört, die es nicht (mehr) gibt; vorher stand dieser Fall nicht auf der Liste,
weil eine Fahrt ohne Absenderadresse nicht vorkam.

Dazu kommen `settings`, `settings-json` und `settings-restore`
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
vergleicht die beiden Listen für alle vier Nachrichten und verlangt zusätzlich
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

### Die zehnte Reihe (12 Gegenproben zu Fassung 1.16.0)

Sie ging die beiden sichtbaren Änderungen an: die Anrede und das Paar im
Kontaktformular. Zwölf Gegenproben, davon zwei, die beim ersten Mal **nichts**
gebrochen haben und erst beim zweiten Mal die gestellte Frage trafen — beide stehen
unten bei ihrem Befund, weil eine Reihe, die sich selbst bestehen lässt, wertlos ist.

| Fehlerbild | Erwartete Prüfung | Was tatsächlich rot wurde |
| --- | --- | --- |
| die Nummer im Kontaktformular heißt nur noch „Nummer“ | alle drei Formulare fragen dasselbe Paar in denselben Worten | `both forms of the page ask for the pair in the same words` |
| die Adresse im Anmeldeformular heißt nur noch „E-Mail“ | dasselbe, über die Dienstseite | `the signup form asks for the pair in the words the offer form uses` — und **nur** diese: die beiden Formulare auf der Angebotsseite bleiben grün, weil sie sich nicht verändert haben |
| die Absage des Angebotsformulars nennt nur noch die Adresse | die Absage nennt das Paar in einem Satz | `the refusal of the offer form names the pair in one sentence` |
| die Absage des Anmeldeformulars nennt nur noch die Adresse | dasselbe, und dieselben Worte wie im Angebotformular | 2 Prüfungen: `the signup form refuses in the words the offer form uses` und die Satzregel `no sentence of the refusal names one of the two without the other` |
| `find_member_for_registration()` vergleicht die Adresse nicht mehr | ein Paar, von dem nur eine Hälfte stimmt, wird abgewiesen | 2 Prüfungen: die Zählerprüfung im Paar-Fall von `[5]` und eine bestehende Prüfung in `[6]`; siehe unten |
| die Schaltfläche sagt wieder „Eintragung vormerken“ | die Schaltfläche nennt den Druck | 2 Prüfungen: `the button names what the press does` und `and no button promises a step in front of the entry` |
| der Abfahrtsbereich verliert seine volle Breite | er hat eine Zeile für sich, das Paar teilt sich eine | `the area has a line of its own and the pair shares one` |
| die Anrede nennt nur den Vornamen, öffentliche Ebene | der Gruß nennt beide Namen, und der Nachname steht sonst nirgends | 2 Prüfungen: `the body greets the member with both names` und `and the surname stands only in the greeting` |
| dieselbe Bruchstelle auf der Mail-Ebene | dasselbe in einer wirklich verschickten Nachricht | 2 Prüfungen: `requester mail greets the asking member with both names` und `creator mail greets the member who offered the ride` |
| die Beschriftung `publish_valid_email` ohne die Nummer | die Zähler der beiden Formen nennen das Paar | `the counter of an entry names the number too` |
| die Beschriftung `contact_valid_email` ohne die Nummer | dasselbe, und zwar für alle vier Zähler | `the contact counter names the number too` |

Der Fall mit der gelockerten Paar-Prüfung ist der einzige, bei dem eine Prüfung **zu
Recht** grün blieb. Die Antwort auf eine Kontaktanfrage ist seit `1.14.0` neutral, das
heißt: Sie ist dieselbe, wenn das Paar passt, und dieselbe, wenn es nicht passt. Eine
gelockerte Prüfung nimmt dem Server nur das Nehmen, sie erfindet aber keine Mail — der
Briefkasten bleibt leer, und die Seite sagt dasselbe wie vorher. Genau darin liegt der
Zweck der neutralen Antwort, und genau darum kann sie als Messgerät dafür nicht dienen.
Was sie **kann**, ist der Zähler: `contact_invalid_email` steht still, wenn die
Verwaltung eine Anfrage annimmt, die sie ablehnen soll. Die Schleife in `[5]` prüft
deshalb beides, die Antwort **und** den Zähler, und nur die Zählerprüfung wurde rot.

Eine Prüfung dieser Reihe war zunächst **gar nicht vorhanden**: Die Beschriftung von
`contact_valid_email` wurde von keiner Prüfung gelesen, und die erste Gegenprobe auf
eine Zählerbeschriftung traf deshalb eine Stelle, die niemand prüft — die Suite blieb
grün und meldete das als Erfolg. Der Befund ist mehr als eine fehlende Zeile: Von den
vier Beschriftungen der beiden Formen waren zwei geprüft. Zwei Prüfungen sind kommen
nicht dazu, weil sie billiger wirken, sondern weil eine Liste von vier zur Hälfte
geprüft keine Liste ist, sondern ein Zufall. Es sind jetzt alle vier, und die
Gegenprobe auf jede der beiden neuen ist die elfte und zwölfte Zeile der Tabelle.

Die zweite Gegenprobe, die nichts brach, war eine Fehlzählung und keine Blindheit:
`fg-field-full` steht viermal in `class-fg-public.php`, und die Bruchstelle wurde als
„die dritte“ bezeichnet — die erste davon ist aber ein `<fieldset>`, und die Suche lief
nach `<div`, also war der dritte `<div>`-Treffer die Einwilligungszeile und nicht der
Abfahrtsbereich. Das Werkzeug hat die Bruchstelle nicht beanstandet, weil es prüft,
**dass** die Stelle mehrfach vorkommt, und nicht **welche** davon gemeint war. Mit „die
zweite“ wurde der Abfahrtsbereich getroffen und die Prüfung rot.

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

## Die Mitgliedsnummer als Schlüssel (Schema 1.4.0)

Bis `1.13.0` stand an einer Fahrgemeinschaft eine frei gewählte Bezeichnung (`alias`) und
die Adresse der anbietenden Person (`contact_email`). Seit `1.4.0` steht dort
`member_id`, und die beiden alten Spalten sind leer. Die Migration läuft in drei
Schritten, jeder eine freistehende statische Methode in `FG_Schema`, aufgerufen von
`install()` — dasselbe Muster wie `clear_legacy_participants()`:

```php
FG_Schema::adopt_ride_members();          // UPDATE … JOIN contact_email -> fg_members.email
FG_Schema::clear_legacy_ride_contacts();  // DELETE … WHERE member_id = 0 AND contact_email <> ''
FG_Schema::take_dropped_rides_notice();   // Zahl in eine Option, danach weg
```

Der Löschpfad ist bewusst über das `WHERE` verengt und nicht über `member_id = 0` allein.
Jede Zeile einer Fassung vor `1.4.0` hat eine Adresse in `contact_email` — das Schema der
alten Spalte ist `NOT NULL DEFAULT ''`, aber sie wurde beim Anlegen immer gefüllt, weil
das Formular sie verlangt hat. Eine Zeile ohne Adresse **und** ohne Mitglied kann deshalb
nicht aus der alten Fassung stammen; sie gehört zu keiner Fassung und bleibt stehen. Ohne
die Verengung hätte die Migration auch solche Zeilen gelöscht, und mit ihnen Daten, die
niemandem gehören und niemandem schaden.

Der Abschnitt `[1b]` der CLI-Suite prüft alle vier Fälle getrennt: eine Fahrt mit der
Adresse eines Mitglieds wird an dieses Mitglied gehängt, eine mit einer fremden Adresse
wird gelöscht und in der Option gemeldet, eine ohne Adresse und ohne Mitglied bleibt
stehen, und die Zahl in der Option verschwindet nach dem ersten Lesen. Die Reihenfolge
diese Prüfungen ist wichtig — sie lesen denselben Zustand, und wer sie umstellt, prüft
eine Option, die schon geleert ist, und meldet zu Recht eine Null.

## Der Zustand vor der Veröffentlichung ist weg (Schema 1.5.0)

Bis `1.14.0` stand eine Fahrt erst im Zustand `pending` und wurde erst durch einen Klick
in der Mail veröffentlicht; die Tabelle hatte dafür vier Spalten, die Liste im
Adminbereich eine Statusspalte und einen Statusfilter, und es gab zwei E-Mails zu einer
Fahrt statt einer. Seit `1.15.0` wird die Zeile in dem einen Statement geschrieben, in dem
sie sichtbar wird, und der Zustand davor hat keinen Leser und keinen Schreiber mehr.

```php
FG_Schema::clear_pending_rides();         // DELETE … WHERE status = 'pending'
FG_Schema::take_dropped_rides_notice();   // Zahl in dieselbe Option wie oben, danach weg
```

Dass die Fahrten im Zustand `pending` **gelöscht** und nicht veröffentlicht werden, ist
eine Entscheidung und keine Nebensache: Eine solche Zeile ist eine Einreichung, auf die
niemand geantwortet hat, und ein Verein kann die Stille nicht als Zustimmung zu einem
öffentlichen Eintrag lesen. Wer sie wollte, hat sie mit einem Formular angeboten. Ihre Zahl
kommt in dieselbe Option wie die der Fahrten ohne Mitglied, damit der Verein **einmal**
über beides unterrichtet wird und nicht zweimal.

Der Zähler wird von zwei Schritten gefüllt, `clear_legacy_ride_contacts()` und
`clear_pending_rides()`. `add_option()` wäre hier falsch gewesen, weil es nichts tut, wenn
die Option schon steht; stattdessen gibt es einen privaten Akkumulator
`count_dropped_rides()`, der mit `update_option()` addiert. Die Suite liest die Option
zweimal, um den Fall „es wurde nichts gezählt" überhaupt erst herstellen zu können, und
prüft dann beide Zähler getrennt.

Die vier Spalten `pending_*` bleiben im DDL stehen, obwohl sie niemand mehr liest und
niemand mehr schreibt. Sie sind der Rückfall für eine zurückgerollte Fassung, aus
demselben Grund wie `alias`, `contact_email` und `participants`. Der Unterschied ist nur
der, dass hier das `DEFAULT 'published'` in der Spalte `status` mitentscheidet: Eine
zurückgerollte Fassung, die eine Zeile anlegt, ohne einen Status zu schreiben, bekommt
damit einen öffentlichen Eintrag und keinen vorgemerkten — was nach dem Wegfall des
Zustands das Richtige ist, aber aus einem anderen Grund als beabsichtigt. Der Kommentar
über `$statements` in `class-fg-schema.php` sagt das an der Stelle, an der es jemand
liest, der das DDL anfasst.

Der Abschnitt `[1b]` prüft die Migration mit einer rohen Zeile, weil für den Zustand
`pending` kein Schreiber mehr existiert: Die Zeile wird direkt in die Tabelle geschrieben,
die Option vorher gelöscht, `FG_Schema::install()` gelaufen, und danach geprüft, dass die
Zeile weg ist, die Option 1 trägt und `count_rides( array( 'status' => 'pending' ) )` null
antwortet. Der Status wird dabei als **Literal** verglichen und nicht über eine Konstante,
denn die Konstante ist mit dem Zustand weg.

## Fassung 1.16.0: Anrede und Kontaktformular

Bis `1.15.0` stand in der Anrede aller vier Nachrichten nur der Vorname, und das
Kontaktformular eines Eintrags fragte nach der Adresse allein. Seit `1.16.0` lautet die
Anrede „Hallo Vorname Nachname“, und das Kontaktformular fragt nach Mitgliedsnummer **und**
Adresse. Beides ist an der ausgelieferten Seite und an der wirklich verschickten Nachricht
geprüft, nicht am Quelltext.

### Die Anrede

Die Anrede wird an vier Stellen und aus drei Ebenen geprüft, weil ein Wort in einer Ebene
zu wenig geprüft worden ist:

| Ebene | Stelle | Was geprüft wird |
| --- | --- | --- |
| CLI | `smoke.php` | Anmeldebestätigung, Angebotsmail, Kontaktmail: die erste Zeile des Textteils ist der Gruß mit beiden Namen |
| Mail | `mail.sh`, `mail-mime.php` | derselbe Gruß im HTML- **und** im Textteil, in beiden Anreden der Kontaktmail |
| Admin | `admin.sh` `[13b]` | die Anrede der Nachrichten, die wirklich verschickt wurden, plus die Vorschau auf der Seite **E-Mails** |

Dazu kommt in `smoke.php` die Umkehrung einer alten Prüfung: Bis `1.15.0` stand dort
`the name of the member is not in the mail`, und sie war nur grün, weil der Gruß den
Nachnamen nicht enthielt. Seit `1.16.0` prüft die Stelle beides — der Gruß **ist**
„Hallo Teilnehmer Probe,“ und der Nachname steht **sonst nirgends** im Text
(`substr_count( …, 'Probe' ) === 1`). Ein Gruß, der den Nachnamen ein zweites Mal in einen
Satz setzt, wäre eine zweite Stelle mit personenbezogener Angabe im Wortlaut.

### Das Paar in allen drei Formularen

Das Kontaktformular ist der dritte Ort, an dem dasselbe Paar geprüft wird. Die Regel steht
an einer Stelle im Code, `FG_Repository::find_member_for_registration()`, und alle drei
Formulare rufen sie. Geprüft wird das an der Oberfläche, weil eine Regel, die an drei
Stellen gleich aussehen soll, an drei Stellen gleich aussehen **muss**:

- **Die Felder stehen nebeneinander und heißen gleich.** `http.sh` liest die Beschriftungen
  aus dem ausgelieferten HTML und stellt die drei Formulare **nebeneinander**, bevor es sie
  vergleicht: Die beiden der Angebotsseite stehen in derselben Seite, das der Anmeldung
  steht auf der Dienstseite, also werden beide Seiten mit einer Trennmarke in eine Eingabe
  gelegt. Geprüft wird, dass alle drei dieselben zwei Worte in derselben Reihenfolge
  nennen. Der Vergleich Formular für Formular hätte nichts bewiesen: Zwei Formulare, die
  gleich lauten, und ein drittes mit eigener Fassung, sind zwei Regeln mit einer
  Ausnahme.
- **Die Absage des Angebotformulars nennt die Regel, den nächsten Schritt und keinen
  Befund.** Bis 1.25.2 wurde die Meldung als **Text** gelesen (`hinweis_text()`, ohne
  Markup) und **wörtlich** mit „Die Eintragung konnte nicht angelegt werden. „ + fester
  Paar-Satz verglichen — eine Festlegung auf den ganzen Satz, die jede Umformulierung als
  Fehler meldete und dabei die Frage nach sich selbst nicht stellen konnte. Seit 1.25.2
  sind es drei Behauptungen über den Text: die Absage nennt **beide Werte** des Paares,
  sie nennt die **zweite Bedingung** (das für diesen Arbeitsdienst angemeldet sein), und sie
  sagt, **was zu tun** ist.
  - Die vierte Behauptung ist die wichtige, und sie hatte bis 1.25.2 keine Entsprechung:
    die Absage nennt **keinen Befund**. Sieben Wortlaute sind aufgeführt, die einen Befund
    nennen würden, und keiner darf im Text stehen. Jede Nadel beginnt mit einem Wort, das
    nie großgeschrieben wird — einem Feldnamen, „Mitglied" oder einem Satzteilstück; eine
    Nadel mit kleinem Anfang wird von einem Satz, der damit beginnt, umgangen, weil der
    Vergleich Groß- und Kleinschreibung unterscheidet. Das ist keine Formalie, sondern
    gefunden: Die erste Fassung dieser Liste begann mit „deine Mitgliedsnummer stimmt", und
    eine Gegenprobe mit dem Satz „**Deine** Mitgliedsnummer stimmt." ließ sie grün.
    Zusammen mit der älteren Prüfung „kein Satz nennt ein Feld ohne das andere" decken die
    beiden die Grenze des Plugins ab: Ein Satz, der sagt, dass die Nummer richtig war, kann
    nur erscheinen, wenn sie es war, und sein Erscheinen verrät einem Fremden mit geratener
    Nummer, dass es sie gibt. Die Regel stand seit 1.16.0 im **Code**; sie stand bis 1.25.2
    in **keiner** Prüfung.
  - **Der Formvergleich vergleicht den Teil, den beide Absagen tragen müssen, und nicht
    den ganzen Satz.** „Beide Absagen nennen das Paar in denselben Worten" liest in beiden
    Texten den Abschnitt, der die beiden Werte und den Verein nennt; was hinter dem Verb
    steht, ist Sache der jeweiligen Form — die Absage des Angebots läuft mit der Bedingung
    des Dienstes weiter, die des Anmeldeformulars endet dort. Die Regel „kein Satz nennt ein
    Feld ohne das andere" gilt für **beide** Texte, seit 1.25.2 auch für den dreisätzigen.
  - Der Wortlaut des Anmeldeformulars („Die Anmeldung ist nicht möglich. …“) bleibt
    eigenständig und nennt weiterhin **nur** das Paar. Das ist keine Ungleichheit, sondern
    die richtige Angabe: Dieses Formular legt die Anmeldung an, es gibt keine zweite
    Bedingung, an der es scheitern könnte. Die alte Formulierung dieses Punktes — „der
    zweite Satz muss in beiden derselbe sein“ — beschrieb einen Wortlaut, der es so nicht
    mehr gibt, und ist mit ihm ersetzt.
- **Keine der beiden Hälften wird herausgestellt.** Der Satz „… müssen zu einem Mitglied
  des Vereins passen“ nennt Nummer und Adresse in einem Satz. Eine Prüfung, die jede
  Nennung einzeln prüft, wäre die falsche: Sie fände in einem Satz, der nur die Adresse
  nennt, keinen Fehler, obwohl genau das der Fehler wäre. Geprüft wird deshalb die
  Bedingung, **dass kein Satz des Textes ein Feld ohne das andere nennt** — ein Satz wie
  „Die Mitgliedsnummer ist nicht bekannt.“ macht die Prüfung rot.
- **Der Fall, in dem nur eine Hälfte stimmt.** `http.sh` schickt drei Paare (richtige
  Nummer mit fremder Adresse, fremde Nummer mit richtiger Adresse, zwei richtige Angaben,
  die zu keinem Mitglied gehören) und prüft je Paar dieselben zwei Dinge: dieselbe
  neutrale Antwort **und** denselben Zähler. Den Zähler holt die Schleife aus dem
  Zustandsbefehl `stat`, nicht aus dem Text der Antwort: Eine Antwort kann richtig
  aussehen und der Zähler trotzdem der falsche sein, und der umgekehrte Fall — die Antwort
  nennt einen Grund, die Tabelle zählt es nicht — wäre genauso still.

### Zwei Fehler in den Prüfungen selbst, gefunden beim Einbau

Beide sind gefunden worden, weil die Prüfung rot wurde, als sie ehrlich wurde — nicht weil
sie vorher falsch grün gewesen wär, der zweite schon:

**Die Absage-Prüfung las einen leeren Rumpf.** Der `curl`-Aufruf, der die Meldung der
Anmeldung holt, war ohne `-L`; eine abgewiesene Anmeldung endet aber in einem 303 mit
leerem Rumpf, und der Satz steht auf der Seite, auf die die Umleitung zeigt. Die Datei war
0 Byte groß, die Prüfungen darüber waren für das falsche Grün grün, und der Satz wurde
überhaupt nie gelesen. Zwei Prüfungen sind nicht verschwunden, sondern ersetzt: Die
`hasnt`-Zeilen für „nennt die Nummer nicht als falsch“ und „nennt die Adresse nicht als
falsch“ prüften beide nichts; der Weg ist jetzt `hinweis_text()` gefolgt der Umleitung, ein
Vergleich des genauen Textes und die Satz-Bedingung oben. Dazu eine Prüfung, die nur meldet,
ob überhaupt eine Meldung auf der Seite stand — sie fängt den Zustand wieder ab, in dem die
Datei leer war.

**Der Vergleich der Beschriftungen zählte mit.** Zwei Einträge auf einer Seite tragen
zweimal das Kontaktformular, also vier Beschriftungen statt zwei; die Dienstseite trägt
das Anmeldeformular je Arbeitsdienst. Der Vergleich hielt deshalb vier gegen zwei und
wurde rot, wenn zwei Einträge auf der Seite standen — er prüfte nicht die
Beschriftungen, sondern die Zahl der Einträge. Beide Vergleiche lesen die Worte jetzt
**einmalig je Seite** (`einmalig()` und `worte()`) und vergleichen Mengen, nicht
Positionen: Die Zahl der Einträge auf einer Seite ist eine Sache für sich und wird
an anderer Stelle geprüft.

Ein dritter Fehler war derselbe Fehler an anderer Stelle und ist nur durch das Nachsehen
gefallen: Das Muster für ein Feld mit Attributen endete auf `[^>]*/>`, die ausgelieferten
Eingabefelder enden aber auf `required>`. Die Prüfung „das Feld steht in derselben Zeile
wie die Schaltfläche“ hätte gegen keine Zeile gegriffen und wäre ebenfalls für das falsche
Grün grün gewesen; das Muster ist `[^>]*>`.

## Fassung 1.17.0: die Hinweise der drei Formulare

Der Hinweis unter den beiden Feldern stand im Angebotformular in der Zelle des
E-Mail-Feldes, also über die halbe Breite, und im Kontaktformular unter der Schaltfläche,
weil der Knopf in derselben Zeile stand wie die Felder. Seit `1.17.0` steht er in allen
drei Formularen als Absatz zwischen den Feldern und dem Knopf, und der Knopf des
Kontaktformulars hat die Feldzeile verlassen. Der Satz über das Postfach steht in allen
drei; am Arbeitsdienst fiel der Satz „Vorname und Nachname tragen wir für dich ein.“
weg.

Das ist die vierte Fassung an einem Tag (die fünfte ist 1.18.0), und damit ist die Regel „ein Lauf, ein Stand“
wieder wichtig: `http.sh` prüft die Reihenfolge über das gerenderte Markup, nicht über
den Quelltext, und die Reihenfolge zweier Elemente ist im Quelltext eine Frage der
Einrückung.

### Was geprüft wird und warum es nicht `has` sein kann

| Prüfung | Ebene | Was sie behauptet |
| --- | --- | --- |
| `in every contact form the note stands between the fields and the button` | gerendertes Markup | in **jedem** Kontaktformular: Feldzeile vor der Nummer, Hinweis vor dem Knopf, und der Knopf nicht in der Zeile |
| `the note of the offer form is a line of its own, between the grid and the button` | gerendertes Markup | der Absatz steht nach dem Raster, vor dem Knopf, und **nicht** in der Zelle des Adressfeldes |
| `in the signup form the note stands between the fields and the button` | gerendertes Markup | dieselbe Reihenfolge, und der Text **als Ganzes** |
| `all three notes stand in the same place and say the same thing about the mailbox` | beide Seiten | alle drei Hinweise tragen `fg-hint fg-hint-row`, und die drei Texte sind genau die drei erwarteten |

Eine `has`-Prüfung auf die Reihenfolge wäre für das Falsche grün: Sie würde nur prüfen,
dass alle drei Stücke da sind, und die Reihenfolge wäre ihr egal. Deshalb wird die
**Position** gelesen und verglichen (`stelle()` gibt den Index der Fundstelle, und die
drei Indizes müssen aufsteigen), und deshalb wird beim Textvergleich nicht nach zwei
Sätzen gesucht, sondern der ganze Absatz mit dem erwarteten Satz verglichen: Der
weggefallene Satz am Arbeitsdienst ist genau der Fall, an dem eine Suche nach den zwei
Sätzen, die bleiben, nichts gemerkt hätte.

Die letzte Prüfung vergleicht die **drei** Texte miteinander und nicht jeden für sich: Die
drei Formulare stehen auf zwei Seiten, also gehen beide Seiten mit einer Trennmarke in
eine Eingabe, und die Hinweise werden an ihrem Satzanfang erkannt (nicht an ihrer
Position im Formular) und als Mengen verglichen. Erkannt wird über den Satzanfang, weil
auf beiden Seiten noch andere Hinweise stehen — der Link zur Datenschutzerklärung in
jedem Anmeldeformular und der eine Hinweis unter der Liste. Eine Prüfung, die alle
`<p class="fg-hint">` einsammelt, wäre an der Datenschutzerklärung hängen geblieben.

### Vier Gegenproben

| Fehlerbild | Erwartete Prüfung | Was tatsächlich rot wurde |
| --- | --- | --- |
| der Hinweis am Arbeitsdienst trägt wieder den alten Satz mit den Namen | der Text ist der eine erwartete Satz | 2 Prüfungen: `in the signup form the note stands …` und der Seitenvergleich |
| der Hinweis im Angebot wird aus dem Absatz gelöscht | er steht zwischen Raster und Knopf | 3 Prüfungen: der Absatz, `the hint names the source of the public name` und der Seitenvergleich |
| der Knopf des Kontaktformulars wandert zurück in die Feldzeile | er steht unter dem Hinweis | 3 Prüfungen: die Reihenfolge, `and says that neither of the two is published` und der Seitenvergleich |
| der Satz über das Postfach fehlt im Angebot | alle drei sagen dasselbe darüber | der Seitenvergleich |

Die zweite und die dritte Gegenprobe haben mehr Prüfungen rot gemacht als benannt, und
das ist richtig so: Beide Bruchstellen haben den Hinweistext gleich mit verschoben oder
mitgelöscht, und die Prüfungen, die nur auf die **Wörter** des Hinweises sehen, haben
das mitbekommen. Eine Bruchstelle, die genau eine Prüfung rot macht, ist der Fall, in dem
man sich das Fehlerbild noch einmal ansieht.

## Fassung 1.18.0: die Einwilligung über den beiden Feldern

Der Auftrag war eine Zeile: die Checkbox der Einwilligung über die Eingabe der
Mitgliedsnummer und der E-Mail-Adresse. Am Stylesheet ändert sich nichts, die Zelle ist
dieselbe und wandert nur im Raster nach oben; `FG_VERSION` wächst trotzdem, weil die Zahl
die ausgelieferte Fassung kennzeichnet.

`http.sh` prüft die Reihenfolge über das gerenderte Markup und über **Positionen**:
Abfahrtsbereich vor der Checkbox vor der Nummer vor der Adresse. Das ist die fünfte
Prüfung, die in diesem Zustand nicht mit einem `has` auskommt, und der Grund ist immer
derselbe: Eine Suche nach den drei Teilen wäre grün, sobald die Checkbox wieder unten
steht, weil sie nicht weiß, in welcher Reihenfolge sie dastehen.

### Eine Gegenprobe

| Fehlerbild | Erwartete Prüfung | Was tatsächlich rot wurde |
| --- | --- | --- |
| die Checkbox wandert zurück ans Ende des Formulars | sie steht über den beiden Feldern | genau diese eine Prüfung, 223 von 224 bleiben grün |

Dass hier **nur eine** Prüfung rot wurde, ist das bemerkenswerte an dieser Reihe und der
Gegensatz zu den vier Gegenproben der Fassung 1.17.0: Dort hatte jede Bruchstelle den
Hinweistext mit verschoben, und die Prüfungen, die nur auf die **Wörter** des Hinweises
sehen, haben das mitbekommen. Hier gibt es keinen Text, der mitwandert — die Bruchstelle
verschiebt drei Blöcke und sonst nichts. Eine Gegenprobe, die nur eine Prüfung rot macht,
ist der Fall, in dem man sich das Fehlerbild noch einmal ansieht; sie ist der Beweis dafür,
dass die übrigen 223 Prüfungen an dieser Stelle nichts zu tun haben.

## Fassung 1.19.0: die Beschreibung verdoppelt ihre Zeilenumbrüche nicht mehr

Der Fehler kam aus dem Verein: Beim Speichern eines Arbeitsdienstes kamen mehr und mehr
Leerzeilen in das Freitextfeld. Die Ursache ist eine Zeile, `FG_Admin_Events::read_text()`:

```php
trim( str_replace( "\r", "\n", $value ) )   // bis 1.19.0
```

Ein Browser schickt die Zeilen eines `<textarea>` mit `CRLF`. `str_replace( "\r", "\n", … )`
ersetzt das `CR` und lässt das `LF` daneben stehen, aus einem Umbruch werden zwei. Weil das
Formular den gespeicherten Wert in dasselbe Feld zurückschreibt, verdoppelt sich der Text bei
jedem Speichern: 2, 4, 8, 16 Umbrüche. Der Kommentar derselben Methode hatte die richtige
Absicht notiert („A browser sends the lines of a textarea separated by CRLF. Only that is
straightened out here …“) — der Code tat das Gegenteil.

### Warum keine Prüfung es gesehen hat

`admin.sh` schickte die Beschreibung mit einem **nackten LF** im Shell-String. Mit dieser
Nutzlast ist `str_replace( "\r", "\n", … )` unschuldig, und die Prüfung „description stored
with its line break“ war grün und sah belastbar aus. Das ist die dritte Art von Fehler, die
diese Reihe schon kennt: nicht ein Muster, das zu eng ist, sondern eine **Nutzlast, die es in
der Praxis nicht gibt**. Solange eine Prüfung eine Eingabe schickt, die kein Browser schickt,
prüft sie den Zustand neben dem Ding, das sie prüft.

Dazu kam eine zweite Lücke, und die ist die wichtigere: Es gab keine einzige Prüfung, die
**denselben Datensatz zweimal speichert und danach vergleicht**. Die Behauptung, um die es hier
geht, ist nicht „ein Zeilenumbruch übersteht ein Speichern“, sondern „**Speichern ändert den
Text nicht**“ — und die war nirgends gemessen.

### Was dazugekommen ist

| Prüfung | Was sie behauptet |
| --- | --- |
| `description stored with its line break` (unverändert, aber mit CRLF gespeist) | drei Zeilen bleiben drei Zeilen, auch über ein Formular hinweg |
| `the form hands out a nonce and the text to send back` | beide Werte für das zweite Speichern sind da, bevor gesendet wird |
| `saving the form again does not change the description` | der Text aus dem Formular, als Browser mit CRLF zurückgesendet, ergibt in der Tabelle **denselben** Wert |
| `and the other fields are still the ones of the form` | das zweite Speichern hat an den übrigen Feldern nichts geändert |

Der neue Helfer `flaeche()` liest den **Inhalt** eines `<textarea>` aus der gerenderten Seite
(`sys.stdout.write`, nicht `print`: ein von `print` angehängter Zeilenumbruch wäre genau die
Änderung, die man messen will).

### Eine Prüfung, die grün war, ohne zu messen

Die dritte Prüfung oben ist zuerst **falsch grün** gewesen, und das gehört hierher: Sie rief
`val` mit einem Argument auf, während `val` in `admin.sh` Datei und Feldnamen braucht. Die
Folge war ein leerer Nonce, das zweite Speichern wurde abgelehnt, die Tabelle behielt den Wert
des ersten Speicherns — und der Vergleich fand zweimal denselben Wert und nannte es einen
Durchgang. Die Meldung `Zeile 28: $2 ist nicht gesetzt` stand im Protokoll und wurde zunächst
übersehen, weil die Prüfung grün war.

Zwei Lehren daraus, beide im Werkzeug festgeschrieben: **Ein Schritt, der einen leeren Wert
erzeugen kann, braucht eine eigene rote Zeile, bevor er weitergeht** — deshalb prüft
`the form hands out a nonce and the text to send back` beide Werte. Und **das Protokoll wird
gelesen, wenn eine Prüfung grün ist, die es vorher nicht war**: Diese Prüfung war vorher
grün, sie ist es immer noch, und nur die Meldung neben ihr hat es aufgedeckt.

### Gegenprobe

| Fehlerbild | Erwartete Prüfung | Was tatsächlich rot wurde |
| --- | --- | --- |
| `read_text()` wieder auf `str_replace( "\r", "\n", … )` | Speichern ändert den Text nicht | 2 Prüfungen: `saving the form again does not change the description` und `description stored with its line break` |

**Eine Beobachtung, inzwischen erklärt.** In diesem einen Lauf meldeten zusätzlich drei
Prüfungen aus den Abschnitten `[13b]` und der Anmeldung **KEINE-MAIL** — also: Es kam keine
Nachricht an, die gelesen werden konnte. Derselbe Bruchpunkt, von Hand gebaut und `admin.sh`
gefahren, gab zwei rote Zeilen und keine davon; drei weitere Läufe mit dem reparierten Code gaben
564/0, einer davon mit `< /dev/null` an der Suite, weil das der Verdacht war.

**Die Erklärung kam mit Fassung 1.21.0 und steht dort im Abschnitt über das Werkzeug der
Gegenproben:** `admin.sh` schaltet den Mail-Recorder nicht selbst ein, und `gegenprobe.sh`
ließ ihn aus. Im normalen Lauf war er an (von Hand), im Gegenprobelauf aus — daher dieselben
Prüfungen mit `KEINE-MAIL` nur dort. Was hier als „nicht gefunden" stand, war ein Fehler im
Werkzeug und nicht in der Suite. Die Lehre ist die alte: Ein Symptom, das nur im Werkzeug
auftritt und nie im normalen Lauf, ist kein Befund über den geprüften Code, sondern einer über
das Werkzeug — und es gehört die **vollständige** Ausgabe eines solchen Laufs gesichert, nicht
nur die Zeilen mit `FAIL`, weil die Antwort im verworfenen Teil stand. Was die Prüfungen gemeinsam haben: Sie bauen ihre Mitglieder vorher selbst
(`neu 7201 …`) und schicken eine echte Anfrage an einen echten Dienst; wenn das Anlegen des
Mitglieds scheitert, entfällt die Nachricht und der Befehl meldet `KEINE-MAIL`. Für den nächsten
Fall dieser Art ist der vollständige Lauf zu sichern, nicht nur die Zeilen mit `FAIL` — das
Werkzeug der Gegenproben wirft genau den Teil weg, in dem die Antwort stünde.

## Fassung 1.20.0: eine Adresse darf bei zwei Mitgliedern stehen

Der Befund aus dem Verein: Bei Ehepaaren kommt dieselbe E-Mail-Adresse doppelt vor, obwohl
die Mitgliedsnummer eindeutig ist. Das war im Plugin nicht nur unerwünscht, sondern bis hier
unmöglich — `UNIQUE KEY email (email)` hätte die zweite Zeile abgewiesen, und der Import
hätte die Datei vorher abgelehnt.

### Die Migration, und warum sie ein eigener Schritt ist

`dbDelta` baut einen eindeutigen Schlüssel nicht in einen gewöhnlichen um. Ohne
`FG_Schema::relax_member_email_index()` wäre die DDL-Zeile geändert, der Schlüssel aber
eindeutig geblieben — und **jede** Prüfung dieses Abschnitts, die über das Plugin läuft,
würde trotzdem grün sein, nur der Datenbankschlüssel wäre es nicht. Deshalb liest eine
Prüfung in der CLI-Suite `SHOW INDEX` und fragt `Non_unique`, und nicht den Quelltext.

Die Messung dieser Aussage ist hier ausdrücklich **nicht** über die CLI-Suite gelaufen. Sie
wurde mit einem Wegwerf-Skript im Container gemacht, das genau die Abfrage der neuen
Prüfung ausführt:

| | |
| --- | --- |
| `SHOW INDEX … email` | 1 Index, `Non_unique = 1` |
| `SHOW INDEX … member_no` | 1 Index, `Non_unique = 0` |
| zwei Mitglieder auf einer Adresse | 3711 / 3712, beide geschrieben |
| dieselbe Nummer ein zweites Mal | abgelehnt |

Das ist eine Messung und kein Laufnachweis: Sie sagt, dass die Aussage am lebenden System
zutrifft, und **nicht**, dass die neue Prüfung in `smoke.php` sie auch fände. Die ist
geschrieben, `php -l` ist sauber, sie ist aber nie gelaufen. Die vierte Gegenprobe dieses
Abschnitts — die Migration abzuschalten und zu sehen, ob die Prüfung rot wird — ist aus
demselben Grund nicht gefahren. Beides steht hier, damit der nächste Lauf, der die
CLI-Suite fährt, weiß, was er noch nachzuholen hat.

### Drei Gegenproben, die gelaufen sind

| Fehlerbild | Erwartete Prüfung | Was tatsächlich rot wurde |
| --- | --- | --- |
| die doppelte Nummer in der Datei wird nicht mehr abgelehnt | eine Nummer bleibt ein Schlüssel | `a number that stands twice in the file is named` |
| der Hinweis auf eine geteilte Adresse entfällt | das Adminformular nennt das andere Mitglied | 2 Prüfungen: `and the note names the member that already holds it` und `and it says that the address is shared` |
| die Migration tut nichts | — | **nichts**, und das ist der Befund dieser Zeile |

Die dritte Gegenprobe hat nichts bewegt, weil die Testinstanz zu dem Zeitpunkt **schon** auf
Schema 1.6.0 stand: `FG_Schema::install()` läuft bei gleicher Fassung gar nicht, ein
abgeschalteter Migrationsschritt also erst gar nicht. Eine Gegenprobe, die eine Stelle
abschaltet, die der Lauf nicht erreicht, misst nichts — und sieht dabei aus, als hätte sie
etwas geprüft. Genau für diesen Fall war in der zehnten Reihe notiert, dass eine
Bruchstelle **zielen** muss; hier war das Ziel eine Codestelle, und die Voraussetzung war
eine andere als gedacht.

### Die ersetzten Prüfungen

| Vorher | Jetzt |
| --- | --- |
| `smoke.php`: *an address belongs to one member only* | *two members may share one address*, *and the address reads back as two members*, *in the order the rows were written*, *and the pair of number and address finds the first of them*, *and the same address with the other number finds the second*, *while a number of nobody is still refused on that address* |
| `admin.sh`: *a taken address names the member that holds it* + *and it says why* | *a shared address is saved*, *and the note names the member that already holds it*, *and it says that the address is shared*, *and the old refusal is gone*, *the second member is written*, *and both rows carry the same address* |
| Import: **keine** Prüfung für `ERROR_DUPLICATE_NUMBER` | *a number that stands twice in the file is named*, *and both of its lines are named*, *and it says nothing was imported* |

Der dritte Strich ist eine Lücke, die dieser Abschnitt schließt und nicht eine, die er
erfindet: Die doppelte Nummer in der Datei war seit dem Import **nie** geprüft, weil vorher
beide Schlüssel gleich behandelt wurden und nur die Adresse durchgespielt wurde. Jetzt ist
sie der einzige Schlüssel, und der einzige Schlüssel ohne Prüfung ist der schlechteste Fall.

Zwei eigene Fehler sind beim Einbau dieser Prüfungen aufgelaufen und stehen hier, weil beide
zu grünen Zeilen geführt hätten: Ein `report` statt der Bildschirmseite für die Ablehnung
(bei einem abgelehnten Import gibt es **keinen** Bericht, die Meldung steht als Hinweis auf
dem Bildschirm), und ein Hinweistext mit Klammern in Klammern.

### Ein ungewollter Umbau, den die Strukturprüfung des Stylesheets fand

Während dieser Fassung lag im Arbeitsbaum eine Änderung am Stylesheet, die niemand
bestellt hatte: `.fg-event-card, .fg-ride` verloren `border` und `border-radius`, und das
seitliche Padding wurde von `1.25rem` auf `1.25rem 0` gesetzt. Sie stand in keinem Commit
und ließ sich keinem Werkzeug dieser Sitzung zuordnen — die Gegenprobenlisten enthalten
keine CSS-Datei, und `git stash` wie `git checkout` waren nie im Spiel. Zurückgesetzt wurde
sie mit `git checkout --`, und `http.sh` war danach wieder bei 224/0.

**Nachtrag aus Fassung 1.22.0: Dieselbe Änderung lag ein zweites Mal im Arbeitsbaum,
und sie ist gewollt.** Sie wurde wieder mit `git checkout --` zurückgesetzt, kam im
selben Durchgang wieder und wurde daraufhin als beabsichtigt bestätigt: Die Karte hat
keinen Rahmen mehr, und der seitliche Innenabstand ist mit ihm verschwunden. Zwei Lehren
aus demselben Befund, und die zweite ist die wichtigere: Die Strukturprüfung des
Stylesheets hat ihren Job beide Male gemacht — beim ersten Mal hat sie eine Änderung
gefunden, die niemand zugeordnet werden konnte, beim zweiten Mal hat sie eine gefunden,
die niemand **zugeordnet** hatte. Ein beabsichtigter Umbau braucht in diesem Projekt einen
Ort, an dem er behauptet wird; sonst ist er im nächsten Arbeitsbaum eine Überraschung,
und die richtige Reaktion auf eine Überraschung ist hier das Zurücksetzen. `README.md`
und `wordpress-fahrgemeinschaften-plugin.md` tragen den Zustand jetzt, und die Prüfung
hält ihn in **beide** Richtungen: ein `padding`, das nicht das vorhandene ist, macht sie
rot, und ein `border`, der zurückkommt, auch.

Der Befund ist die Prüfung `the stylesheet knows the card and its table`: Sie liest das
Stylesheet, das die Seite **verlinkt**, und fragt sechs Regeln einzeln ab, darunter `.fg-event-card`
mit einer `border`-Eigenschaft. Eine Karte ohne Rahmen ist eine sichtbare Änderung, und eine
sichtbare Änderung, die niemand bestellt hat, gehört nicht in ein Paket. Zwei Lehren: Eine
Strukturprüfung für das Stylesheet ist keine Formalie, sondern fängt genau das ab, was keine
PHP-Prüfung fände; und ein Diff, den man nicht erklären kann, wird zurückgesetzt und
protokolliert, nicht mitgenommen.

## Fassung 1.21.0: die Mail trägt ihr Layout in die Nachricht hinein

Der Befund aus dem Verein: Die vier Nachrichten des Plugins kommen ohne Logo, ohne
Hintergrund und ohne Links an, obwohl die Vorschau auf der Seite **Einstellungen** alles
zeigt. Im Quelltext der empfangenen Mail stand `Content-Type: text/plain`.

### Der gemessene Weg

`FG_Mailer::send()` gab bis hier den **reinen Text** an `wp_mail()` und hängte das Layout an
die Aktion `phpmailer_init`. Die Aktion feuert nur der eigene Mailversand von WordPress
selbst. Eine Mail-Erweiterung, die `wp_mail()` übernimmt, baut die Nachricht selbst und feuert
sie nie — und auf der Testinstanz ist eine aktiv (SureMails).

Gemessen wurde an derselben Naht, an der eine Erweiterung die Nachricht übernimmt: über
`pre_wp_mail`, also über den Filter, an dem auch der Recorder der Testumgebung hängt.

| | bis 1.20.0 | ab 1.21.0 |
| --- | --- | --- |
| Nachricht | 364 Bytes Text | 8415 Bytes, beginnt mit `<!DOCTYPE html>` |
| Header | nur `Reply-To:` | zusätzlich `Content-Type: text/html; charset=UTF-8` |
| Layout, Fußzeile | fehlen | vorhanden |
| Logo | `cid:logo` ohne Bilddatei im Umschlag | `<img src="https://…/wp-content/uploads/…">` |
| Links | keiner | `<a … href="…fg_ride_action=view&#038;ride_ref=…">` |

### Warum keine Prüfung es gesehen hat

Zwei Lücken nebeneinander, und jede allein hätte gereicht.

**Der MIME-Satz baute die Nachricht von Hand.** `fg_mime_build()` setzte den Text als Rumpf
und rief dann `apply_alternative()` auf einem frischen PHPMailer auf — es maß also, was
`FG_Mail_Templates` bauen **kann**, und nicht, was `FG_Mailer` übergibt. Dass beides zwei
verschiedene Wege sind, stand in keiner Prüfung und in keinem Kommentar.

**Der Recorder sah nur den Text.** Er hängt an `pre_wp_mail` und damit genau dort, wo eine
Mail-Erweiterung die Nachricht übernimmt — er *ist* eine. Er schrieb den Rumpf in die
Tabelle `wp_fg_test_mail_log`, und die Prüfungen suchten darin nach Wörtern aus dem Text
(`has "the body names the duty" …`). Eine Nachricht ohne Layout enthält diese Wörter
weiterhin; sie enthält nur nicht die zweite Hälfte des Auftrags. Die Tabelle hat eine Spalte
`mail_content_type`, und keine einzige Prüfung hat sie angesehen.

### Was dazugekommen ist

- **`wp_mail()` bekommt das HTML.** `FG_Mailer::send()` übergibt `FG_Mail_Templates::render()`
  und den Header `Content-Type: text/html; charset=…`. Der reine Text bleibt drin, aber nur
  als `AltBody` über `phpmailer_init`. Dort schreibt PHPMailer den Textteil vor den HTML-Teil;
  die Reihenfolge „Text zuerst, HTML als zweite Alternative" ist unverändert, und auf einem
  Weg, der nicht PHPMailer ist, entfällt die Alternative — die Fußzeile steht auch im Layout.
- **Das Logo steht als Adresse des eigenen Servers im Layout.** `get_logo_embed()` und die
  Konstante `LOGO_CID` sind weg, der `cid:`-Sonderfall in `logo_block()` mit ihnen, und das
  sechste Argument von `wp_mail()`. Die Adresse kommt aus `wp_get_attachment_image_url( $id,
  'full' )` — dieselbe, die auch die Vorschau auf **Einstellungen** und die auf **E-Mails**
  benutzt. Damit fällt die Voraussetzung **WordPress 6.9** weg, denn `$embeds` gab es erst
  dort.
- **Ein Hinweis, wenn eine fremde Erweiterung `wp_mail()` übernimmt.**
  `FG_Mail_Templates::foreign_mail_handler()` nennt sie auf den Seiten **Einstellungen** und
  **E-Mails**. Der Layout-Verlust ist damit behoben; der Hinweis betrifft noch die verlorene
  Textfassung.
- **Was der Hinweis nicht sieht, steht im Code und nicht nur in diesem Abschnitt:** eine
  Erweiterung, die den Filter `pre_wp_mail` nur **anwendet**, statt sich an ihn zu hängen.
  SureMails in der Testumgebung tut genau das und wird deshalb nicht erkannt. Eine Registrierung,
  die es nicht gibt, kann man nicht anzeigen.

### Der Preis, offen benannt

Ein Client, der fremde Bilder sperrt, zeigt kein Logo mehr; und das Öffnen der Mail löst einen
Abruf auf dem **eigenen** Webserver aus, und der Verein sieht, dass eines seiner Postfächer eine
Datei von ihm geladen hat. Die Adresse des Empfängers geht dabei nirgends hin. Der Grund für
den Wechsel ist der Befund oben: Fehlendes Layout, fehlendes Logo und fehlende Links zusammen
sind schlimmer als ein fehlendes Bild, denn eine Mail ohne Layout ist keine Mail dieses
Vereins, und ein gesperrtes Bild ist immer noch eine lesbare Mail.

### Die ersetzten Prüfungen

| Vorher | Jetzt |
| --- | --- |
| `mail.sh`: *plugin forces no content type* (`text/plain`) | *the message is declared as html* |
| `mail.sh`: *headers carry no content type* | *and the header says so too* |
| `mail.sh`: *plain text alternative has no html* | *the body is the layout of the plugin* + *and it carries the layout's own background* + *and the logo by its address* + *on the club's own site* |
| `mail.sh`: *plain text alternative has no html links* | *the link is a link in the html* + *and it uses the https site* |
| `mail.sh`: *requester mail has no link* / *has no html* | *requester mail is the layout as well* |
| `mail-mime.php`: *the logo is embedded under the id logo*, *the logo is sent inline*, *the logo is sent as base64*, *the html part refers to the embedded logo* | *the html part refers to the logo by its address*, *and that address is a file of this installation*, *the logo is not embedded any more*, *and no third host is loaded for it* |
| `mail-mime.php`: `fg_mime_build()` baut den Rumpf von Hand | `fg_mime_build()` holt den Rumpf aus `FG_Mail_Templates::render()`, wie `send()` es tut |
| `http.sh`: *and the surname stands only in the greeting* (eine Prüfung, zwei Behauptungen) | *and the surname stands once in the mail* + *and the text block opens with the greeting* |
| `state.php`: erste Zeile des Rumpfes, an drei Stellen neu geschrieben | `fg_state_greeting()` liest den markierten Textblock, einmal geschrieben |
| — | **neu:** `mail.sh` Abschnitt `[5b]`: sechs Prüfungen zu `foreign_mail_handler()` |

Drei weitere Prüfungen in `http.sh` und `admin.sh` sind nicht ersetzt, sondern **an die
HTML-Form angepasst**: Sie zogen den Löschen- und den Abmeldelink aus dem Rumpf (`&` steht im
Attribut als `&#038;`, der Suchausdruck endet jetzt am schließenden Anführungszeichen) und sie
lasen die Anrede als erste Zeile der Datei. Beides ist an der **richtigen** Stelle behoben —
in einer Lesehilfe, die `mail-anrede`, `mail-greeting` und `mail-recorded-greeting` gemeinsam
benutzen, statt an drei Stellen einzeln.

### Die Gegenproben

| Fehlerbild | Erwartete Prüfung | Was tatsächlich rot wurde |
| --- | --- | --- |
| `send()` übergibt wieder `text_part( … )` | Der Rumpf ist das Layout, der Löschlink funktioniert | 14 Prüfungen, darunter alle sieben neuen des Layouts, der Löschlink, die Löschseite und die Aufräumprüfung |
| Der `Content-Type`-Header in `send()` entfernt | Die Nachricht ist als HTML angesagt | 2 Prüfungen (`text/plain` fällt dann auf den Vorgabe-Wert von PHPMailer zurück) |
| Die Auslassung des Recorders in `foreign_mail_handler()` auf `if ( false )` gesetzt | Der Recorder der Testumgebung wird nicht als Mail-Erweiterung gemeldet | 6 Prüfungen im Abschnitt `[5b]` |
| `fg_state_greeting()` liest die ganze Datei statt des markierten Textblocks | Die Anrede steht am Anfang des Textblocks | 3 Prüfungen in `admin.sh` (statt der Anrede kam der Betreff) und 1 in `http.sh` |

### Drei Fehler, die erst beim Messen auffielen

1. **`apply_alternative( $phpmailer, '', $body, $links )`.** Der erste Entwurf wollte den
   Betreff nicht doppelt rendern und übergab deshalb eine leere Zeichenkette. Diese Methode
   schrieb aber bis hier **beides** — `AltBody` und `Body` — und hätte die fertige Nachricht
   mit einem Betreff ohne Wort überschrieben. Seitdem setzt sie nur noch die Alternative.
2. **`WP_Hook::callbacks` ist zweistufig.** Die erste Fassung von `foreign_mail_handler()` las
   eine Ebene und bekam aus dem Array `null`, was sie als „unbekannt" meldete — während der
   Name eine Ebene tiefer dasteht. Dazu zwei kleinere: `getClosureCalledClass()` gibt ein
   `ReflectionClass` zurück (nicht die Klasse), und die Bedingung für den anonymen Klassennamen
   war verkehrt herum, sodass sie `class@anonymous` samt Datei und Zeilennummer ausgab.
3. **Vier falsche Nadeln in den eigenen Messskripten.** Gesucht wurde nach `<a href`, im Layout
   steht `<a style="…" href="…">`; nach dem grünen Hintergrund, den das Layout **nicht** hat
   (darin stehen nur `#f1f1f1`, `#ffffff`, `#111111`, `#666666` und `#1a82e2`); nach einem
   Header ohne JSON-Escaping (`text\/html` steht in der Tabelle); und der Listenschreiber der
   Gegenproben vergaß einmal die `@N@`-Ersetzung, sodass die Bruchstelle mit echten
   Zeilenumbrüchen in der Liste stand. Keiner dieser vier Fehler hat das Ergebnis verfälscht —
   sie hätten es als Grün aussehen lassen.

### Ein Werkzeugdefekt, der zwei Gegenproben wertlos machte

`gegenprobe.sh` spielt `tests/` **nicht** in den Container, obwohl die Suiten `state.php` von
dort aufrufen. Eine Bruchstelle in `tests/` erreicht die Suite damit nicht: Der Lauf sieht den
unveränderten Zustand, bleibt grün, und das Werkzeug meldet das wie einen Erfolg. Zwei
Gegenproben dieser Fassung sind so gelaufen und haben nichts gezeigt. Das Werkzeug meldet eine
grüne Suite jetzt ausdrücklich als „die Bruchstelle hat nichts bewegt" und liefert `tests/`
mit aus.

Dasselbe Werkzeug ließ außerdem den Mail-Recorder aus, während `admin.sh` ihn nicht selbst
einschaltet. Damit meldete jede Admin-Gegenprobe bei den Anmeldeprüfungen `KEINE-MAIL` statt
des gesuchten Fehlers — **das ist die Erklärung der offenen Beobachtung aus Fassung 1.19.0**,
die dort als „der Auslöser ist nicht gefunden" stand: Dieselben Prüfungen, immer `KEINE-MAIL`,
nur im Gegenprobelauf und nie im normalen Lauf. Zwei der vier Gegenproben dieser Fassung sind
erst mit der Reparatur des Werkzeugs rot geworden, vorher waren sie grün.

## Fassung 1.22.0: die Redaktion trägt Mitglieder in den Arbeitsdienst ein

Der Wunsch aus dem Verein: Ein Mitglied selbst in einen Arbeitsdienst eintragen, mit einer
Suche nach Namen oder Mitgliedsnummer, mit einem Kästchen, das fragt, ob es dabei
benachrichtigt werden soll, mit einem Zähler, der sagt, wie oft es das geworden ist, und
mit der Möglichkeit, dieselbe Nachricht später noch einmal rauszuschicken. Und das Ganze
so, dass **eintragen** und **benachrichtigen** zwei verschiedene Dinge bleiben: Man kann
einen Dienst im September planen und im Oktober ankündigen.

### Die eine Entscheidung, die alles andere kostet

„Alle Abmeldelinks bleiben gültig" ist keine Zusatzfunktion, sondern eine neue Tabelle. Bis
hier stand die Prüfsumme des Tokens an der Anmeldung selbst, **eine** pro Anmeldung. Eine
zweite Nachricht an dasselbe Mitglied brauchte ein zweites Token, und ein zweites Token
passte an diese eine Stelle nicht: Es hätte das erste ersetzt, und der Link aus der ersten
Mail wäre tot gewesen. Ein Club, der zweimal zuschreibt, hätte seinen Mitgliedern damit einen
toten Link geschickt.

Also steht seit Schema 1.7.0 in `fg_event_member_tokens` eine Zeile je Token. Die beiden
Spalten an der Anmeldung bleiben im Schema stehen — aus demselben Grund wie `alias` in den
Fahrgemeinschaften: Ein zurückgesetztes WordPress darf nicht an einem Feld scheitern, das es
nicht kennt —, werden aber weder gelesen noch geschrieben.

**Die Migration ist der Teil, der eine stille Regression verursachen würde.** Ohne sie wird
auf jeder Vereinsseite mit dem Update jeder bereits verschickte Link ungültig, und nichts auf
der Seite sagt das: Diese Mails liegen seit Wochen in Postfächern, und niemand klickt sie
noch einmal. `FG_Schema::copy_unregister_tokens()` kopiert deshalb jedes Token aus der alten
Spalte in die neue Tabelle, **idempotent** — der erste Lauf kann halb abbrechen, und der
nächste muss die Arbeit fertig machen statt derselben Zeile ein zweites Mal einzufügen. Und
sie lässt sich **jederzeit** prüfen, was bei einer Migration sonst nicht geht:
`state.php migrate-tokens` schreibt eine Anmeldung mit Token an der alten Stelle, ruft die
Kopie auf und zählt. Der Abschnitt wartet damit nicht darauf, dass eine Instanz noch nicht
migriert ist.

### Die Zählung steht dort, wo der Versand geklappt hat

`FG_Mailer::send_duty_signup()` erhöht den Zähler **nach** einem erfolgreichen `send()`. Das
ist die einzige Stelle, an der eine Anmelde-Mail entsteht — ob das Mitglied selbst klickt, ob
die Redaktion es einträgt, oder ob dieselbe Mail zum zweiten Mal rausgeht —, und damit auch
die einzige Stelle, an der gezählt werden muss. Eine Zustellung, die nicht klappt, zählt
nicht mit, weil sie kein Mitglied erreicht hat.

Zwei Folgen daraus sind beide geprüft: Der Zähler steht bei **0**, wenn die Redaktion ohne
Häkchen einträgt — das ist der gewünschte Fall und er brauchte eine eigene Prüfung —, und er
steht nach einem gescheiterten erneuten Versand weiter. Zwei Spalten in der Liste sagen beides
neben dem Zähler: **Eingetragen von** (Mitglied selbst oder Redaktion) und **E-Mails** (die
Zahl).

### Was die Redaktion darf, was die öffentliche Seite darf

Der Bedarf ist eine **Ansage** des Vereins an die Mitglieder, keine Grenze für den Redakteur.
Über dem Bedarf wird eingetragen, und die Liste sagt dann, wie viele Mitglieder für wie viele
angekündigte Plätze drinstehen und was die öffentliche Seite daraus macht. Der öffentliche Weg
bleibt unverändert: Er weist über dem Bedarf ab. Beides steht in zwei Sätzen auf derselben
Seite, weil ein Redakteur, der dreizehn Mitglieder für zwölf Plätze einträgt, wissen muss,
dass die öffentliche Seite diesen Dienst als voll zeigt.

### Drei Fehler, die erst das Messen zeigte

1. **`$wpdb->delete()` mit einem Array.** `delete_event_member_tokens()` reichte eine Liste von
   Anmeldungsnummern an diese Methode. Sie nimmt einen Wert je Spalte; ein Array darin
   scheitert nicht laut, sondern wird zu `registration_id = 0`, und die Methode meldet
   „nichts gelöscht". Damit haben **alle vier Löschwege** ihre Tokenzeilen stehen lassen — auch
   der Weg, auf dem die Datenschutflöschung ein Mitglied entfernt. Sichtbar geworden ist es im
   Handlauf, weil nach dem Löschen eines Mitglieds die Zeile noch da war.
2. **Zwei Token für eine Mail.** `create_registration()` stellt ein Token aus, und der
   Verwaltungsweg stellte ein zweites, bevor er die Mail schickte; die Tabelle enthielt damit
   eine Zeile ohne Mail. Der Verwaltungsweg benutzt jetzt das Token aus der Anmeldung, und ein
   Eintrag ohne Mail nimmt seines wieder mit sich. In der Tabelle steht damit **eine Zeile je
   zugestellter Mail** — die einzige Aussage, die man über sie treffen kann.
3. **Ein Formular je Zeile statt eines Links.** Zwei gleichartige Links auf einer Seite gehen
   nicht: Der zweite hätte den ersten aufgehoben. Jede Zeile hat jetzt ein eigenes Formular,
   mit dem Nonce **dieser** Anmeldung.

### Ein Werkzeug, das die ganze Reihe in Frage stellt

Die Prüfung liest Registrierungsnummer und Nonce **aus der Zeile des Mitglieds** und nicht von
der Seite: Die Teilnehmerliste zeigt alle Mitglieder des Dienstes, und die Suche darüber füllt
nur die Auswahlliste. Ein Test, der die erste Nonce der Seite nimmt, benachrichtigt ein
Mitglied und misst ein anderes. Genau das ist zweimal passiert, und beide Male sah es nach
einem Fehler im Plugin aus — „der Zähler bleibt stehen, obwohl eine Mail rausging". Der Leser
`hidden_in_row` in `admin.sh` weiß, welche Zeile gemeint ist; er ist keine Probe auf das
Plugin, sondern die Voraussetzung dafür, dass eine Probe das Plugin misst.

### Die ersetzten und die neuen Prüfungen

| Vorher | Jetzt |
| --- | --- |
| `http.sh`: `.fg-event-card` mit `border` | `.fg-event-card` mit `padding: 1.25rem 0` **und** **ohne** `border` — der Zustand wird in beide Richtungen gehalten |
| `smoke.php`: *the cleanup finds the row*, über die Spalte der Anmeldung | *and the token is still there, only past its date* + *only the token is dropped*, beide über die Tokentabelle |
| `smoke.php`: `update_event_member( … 'unregister_expires' … )` | `update_event_member_token( … )` — dieselbe Absicht an der Stelle, an der die Spalte jetzt steht |
| — | **neu** `admin.sh [5g]`, 47 Prüfungen: Block, Suche, Kästchen, Zähler, beide Links, Fehlschlag, über dem Bedarf, ohne Nonce, per GET wirkungslos, Tabelle, Spalten, Migration, drei Löschwege |
| — | **neu** `state.php`: `notified`, `mail-count`, `mail-bodies`, `mail-recipients`, `mail-fail`, `schema`, `migrate-tokens` |

Die Suche der Mitgliederliste ist dabei mitverändert worden: Sie suchte feldweise, und
„Kaputt Test" fand niemanden, weil kein einzelnes Feld beide Wörter enthält. Sie sucht jetzt
**Wort für Wort**, und jedes Wort muss irgendwo in der Zeile stehen. An der Mitgliederseite ist
das eine sichtbare Verbesserung, und an der neuen Seite die Voraussetzung dafür, dass „Suche
nach Namen" das tut, was der Wunsch beschreibt.

### Die Gegenproben

| Fehlerbild | Erwartete Prüfung | Was tatsächlich rot wurde |
| --- | --- | --- |
| `note_duty_notification()` aus dem Mailer entfernt | Der Zähler steigt | 3 Prüfungen |
| Das Token wandert nicht in die Tabelle | Ein Link je Mail, und er geht auf | 7 Prüfungen |
| `valid_unregister_token()` immer `false` | Beide Links öffnen | 4 Prüfungen |
| Das Kästchen wird ignoriert (`$notify = true`) | Ohne Häkchen keine Mail, Zähler 0 | 5 Prüfungen |
| Der Zähler steigt auch im Fehlerfall | Ein Fehlschlag zählt nicht | 4 Prüfungen |
| `drop_unregister_token()` abgeschaltet | Ein Fehlschlag lässt kein Token zurück | 2 Prüfungen |
| Die Suche wieder feldweise | „Kaputt Test" findet das Mitglied | 1 Prüfung |
| Die Tokenlöschung beim Löschen der Anmeldung entfällt | Der Löschlink nimmt den Link mit | 1 Prüfung |
| … beim Löschen des Mitglieds entfällt | Dasselbe für die Mitgliederseite | 1 Prüfung |
| … beim Löschen des Dienstes entfällt | Dasselbe für die Kaskade | 1 Prüfung |

Die letzten drei Zeilen dieser Liste sind der Grund, warum diese Prüfungen erst spät kamen:
Der Bruch war wirkungslos, solange keine Prüfung das behauptete. Eine Gegenprobe, die grün
bleibt, ist kein beruhigendes Zeichen, sondern ein fehlender Test — und die Reihenfolge „erst
die Prüfung, dann die Gegenprobe" ist in dieser Fassung zweimal gescheitert: einmal, weil
`$wpdb->delete()` still nichts tat, und einmal, weil die Prüfung die falsche Zeile gelesen
hat.

## Fassung 1.23.0: die Dienstliste auf dem Telefon

Zwei Aufträge aus dem Verein, beide am Stylesheet, und der zweite war größer als er klang:
Auf einem Telefon soll die Tabelle eines Arbeitsdienstes **zuverlässig** gehen, und ihre
linke Spalte soll **über** dem Inhalt stehen.

### Was vorher schiefging

Die Tabelle `.fg-event-data` ist eine echte Tabelle mit zwei Spalten: links der Name der
Zeile (`th`, `width: 1%`, `white-space: nowrap`), rechts der Wert. Das ist auf einem
Desktop richtig und auf einem Telefon falsch. `width: 1%` gibt dem Namen genau so viel Platz,
wie sein Wort braucht, und der Rest bleibt dem Wert — bei einer Beschreibung von zweihundert
Wörtern bleiben dem Wert auf einem 360-Pixel-Bildschirm etwa neunzig Pixel. Der Wert ist
genau der Teil, den man lesen will.

Zweiter Fund in derselben Regel: `.fg-rides` stand auf
`grid-template-columns: repeat(auto-fit, minmax(380px, 1fr))`. Eine feste Mindestbreite von
380px ist **breiter als ein Telefon**, und ein Raster, dessen Spalte breiter ist als sein
Behälter, schiebt die ganze Seite zur Seite: Man scrollt waagerecht durch eine Liste, die
senkrecht gelesen werden sollte. `minmax(min(380px, 100%), 1fr)` macht aus der Mindestbreite
ein „380px, aber nicht mehr, als da ist" — und die Regel steht damit **außerhalb** der
Media-Abfrage, weil sie für jede Breite gilt und nicht nur für schmale.

### Die Regel, die weg soll

`.fg-section, .fg-event-card, .fg-ride { padding: 1rem; }` innerhalb von
`@media (max-width: 640px)` ist entfernt. Seit 1.22.0 trägt die Karte `padding: 1.25rem 0` —
oben und unten Luft, an den Seiten keiner. Die Telefonregel hat diesen Zustand wieder
verdreht: Auf dem Telefon wäre an den Seiten doch wieder Luft entstanden, nur geringere. Der
Innenabstand ist damit auf beiden Wegen derselbe, was auch der Grund ist, warum keine
Ersatzregel nötig war.

### Was auf dem Telefon jetzt gilt

Innerhalb von `@media (max-width: 640px)`:

- Tabelle, `tbody`, Zeile, Kopf- und Datenzelle werden **Blöcke**, und `width: auto` nimmt
  dem Namen die feste Breite. Der Name steht über dem Wert, und der Wert nimmt die ganze
  Breite.
- `white-space: normal` auf dem Namen: „Beschreibung" muss nicht in eine Zeile gepresst
  werden, wenn der Platz ohnehin breit ist.
- Die Linie **zwischen** zwei Zeilen wandert mit: Sie steht über dem Namen der zweiten
  Zeile (`tr + tr th { border-top }`) und **nicht** zwischen Name und Wert
  (`tr + tr td { border-top: 0 }`). Eine Linie zwischen Name und Wert würde den Wert von
  seinem Namen abschneiden — das ist der Fehler, den man macht, wenn man die alte Regel
  einfach auf Blöcke umschreibt.
- Die zwei Felder der Anmeldung stehen **untereinander**: `.fg-member-row .fg-field` bekommt
  `flex: 1 1 100%`. Bisher standen sie auf einem Telefon nebeneinander, jede so breit wie
  möglich, und ein Feld mit 14rem in einem Telefon von 15rem hat für seine eigene
  Fehlermeldung nichts übrig.
- Der Knopf des Formulars nimmt die **volle Breite**: `.fg-actions .fg-button`. Zwei Knöpfe
  nebeneinander auf einem Telefon sind jeder ein Streifen, und der, der das Formular
  abschließt, ist der, den ein Daumen finden muss.

### Die Prüfung liest den Media-Block und zählt die Klammern

Die neue Prüfung `the stylesheet stacks the duty table on a narrow screen` liest **nur den
Block für schmale Bildschirme**, und sie findet ihn, indem sie die Klammern zählt statt an
einer Absatzgrenze zu scheitern. Das ist der Punkt: Dieselben Selektoren stehen außerhalb
des Blocks für den Desktop, und eine Prüfung, die die beiden nicht unterscheiden kann, wäre
für ein Stylesheet grün, in dem die Tabelle auf dem Telefon noch zweispaltig ist.

Sie behauptet sieben Dinge, darunter eines über eine **Abwesenheit**: die entfernte
`padding: 1rem`-Regel für Karten, Abschnitte und Fahrgemeinschaften darf nicht wiederkehren.
Für Abwesenheiten ist eine Prüfung das richtige Werkzeug — sie ist die einzige Stelle, an der
sich „weg" festhalten lässt.

Die Gitterregel wird in derselben Prüfung aus dem **ganzen** Stylesheet gelesen und nicht
aus dem Media-Block, weil sie dort zu Recht nicht steht. Das war der erste Fehlschlag dieser
Prüfung: Sie suchte `min(380px, 100%)` im Block für schmale Bildschirme und wäre für ein
Stylesheet rot gewesen, in dem die Regel genau richtig steht.

### Die Gegenproben

| Fehlerbild | Erwartete Prüfung | Was tatsächlich rot wurde |
| --- | --- | --- |
| Die Stapel-Regeln der Tabelle entfernt | Der Name steht über dem Wert | 1 Prüfung |
| Die entfernte `padding: 1rem`-Regel wiederhergestellt | Sie bleibt weg | 1 Prüfung |
| `minmax(380px, 1fr)` wieder ohne `min()` | Das Raster ist nie breiter als die Seite | 1 Prüfung |

Ein Detail, das beim Messen auffiel und nicht am Plugin liegt: Die Prüfung las zuerst eine
alte Datei. Das Stylesheet war nach dem Schreiben nicht in die Testinstanz gespiegelt, und
`http.sh` holt es über die Seite, die es verlinkt — also die Datei auf dem Server, nicht die
im Arbeitsbaum. Die Prüfung war richtig und die Messung falsch; `rsync` vor dem Lauf ist
deshalb kein Ritual, sondern die Voraussetzung dafür, dass eine Prüfung das Plugin misst und
nicht den Stand von vor drei Minuten.

## Fassung 1.24.0: nur der Name einer Zeile ist fett

Der Wunsch aus dem Verein, kurz: **Die Datenseite soll nie fett sein, nur die linke.** Auf
einer Karte mit sieben Zeilen war das Auge an zwei Werten hängen geblieben — am Datum und an
der Zahl der freien Plätze — und damit an Inhalt statt an Struktur.

### Was daran Regel war und nicht nur Geschmack

Bis hier hatte das Stylesheet zwei Stellen, an denen ein **Wert** gesetzt war:

- `.fg-event-data tr:first-child td { font-weight: 600 }` — der Kommentar daneben sagte
  ausdrücklich: „The date is the one thing a reader looks for first, so it is not a note next
  to the title like it is in the list of rides."
- `.fg-places { font-weight: 600 }` — mit der Begründung, ein freier Platz sei „in der
  Stärke des Bedarfs darüber" gesetzt, und eine Null sei ein Hinweis und keine Warnung.

Beide Regeln sind weg, und mit ihnen ihre Begründungen. Was bleibt, ist die Formulierung,
die trägt: **Der Name einer Zeile ist der Rahmen, der Wert ist der Text.** Ein Wert in der
Stärke seines Namens konkurriert mit dem Namen um den Blick; auf einer Karte mit sieben Zeilen
gewinnt er zweimal und verliert fünfmal den Aufbau. Das Datum ist die **erste** Zeile, und
eine erste Zeile ist ohnehin die, auf die das Auge zuerst fällt.

Bei den freien Plätzen bleibt die **Farbe** als Unterscheidung: `.fg-places-none` ist der
gedämpfte Ton für die Null. Eine Regel `.fg-places` gibt es danach nicht mehr — der Klassenname
im Markup bleibt, weil er der Haken ist, an dem die Prüfung des öffentlichen Satzes hängt und
an dem eine Regel eines Themes ansetzen könnte.

### Die Prüfung behauptet beide Hälften

`only the name of a row is set in the weight` liest das Stylesheet zeilenweise und teilt jede
Regel an ihrem letzten Selektor-Bein: Endet es auf `td`, ist es ein **Wert**, und dort darf
kein `font-weight` stehen; endet es auf `th` und steht dort eins, ist der **Name** fett.

Beide Hälften zu behaupten ist der entscheidende Punkt. Eine Prüfung, die nur sagt „ein Wert
ist nicht fett\", ist auch für ein Stylesheet grün, in dem **gar nichts** fett ist — sie prüft
dann eine Abwesenheit und übersieht den Verlust der einen Fettung, die bleiben sollte. Der
Gegenlauf der ersten beiden Zeilen dieser Reihe zeigt den Unterschied: Nachprobe 1 und 2
nehmen einem Wert die Fettung, Nachprobe 3 nimmt sie dem Namen, und alle drei machen dieselbe
Prüfung rot.

Die Meldung ist für beide Fälle eine, weil sie beide nennt: „a value of the duty table is set
in the weight, or the name of a row is not". Das ist eine Ungenauigkeit, die man in Kauf
nimmt, solange die Zahl der Fälle zwei ist; bei fünf würde sie lästig.

### Die Gegenproben

| Fehlerbild | Erwartete Prüfung | Was tatsächlich rot wurde |
| --- | --- | --- |
| `font-weight: 600` wieder am Wert der ersten Zeile | Kein Wert ist fett | 1 Prüfung |
| `.fg-places { font-weight: 600 }` wiederhergestellt | Dasselbe für die freien Plätze | 1 Prüfung |
| `font-weight: 600` am Namen der Zeile entfernt | Der Name ist fett | 1 Prüfung |

Mail- und Admin-Ebene sind unverändert, weil diese Fassung keinen Formulartext, keine Mail und
keinen Zustandsschritt berührt: Sie dreht an einer Eigenschaft, die der Browser liest. HTTP
ist um eine Prüfung höher.

## Fassung 1.25.0: das Mitglied trägt eine Arbeitsgruppe

Der Wunsch aus dem Verein: Der Import soll eine Spalte **E001: Arbeitsdienst** lesen, das
Mitglied soll eine Arbeitsgruppe tragen, die Mitgliedertabelle soll sie zeigen, und die
Teilnehmerliste eines Arbeitsdienstes soll sie auch zeigen. Drei Antworten waren dazu
nötig, und alle drei sind vom Verein gekommen.

### Eine freiwillige Spalte, und was „freiwillig" genau heißt

Der Import verlangt bisher vier Spalten und weist eine Datei ohne eine davon zurück. Die
Arbeitsgruppe ist die **fünfte** und wird **nicht** verlangt. Der Grund ist nicht
Bequemlichkeit: Sie ist kein Schlüssel, und in keinem Feld des Plugins dreht sich etwas darum.
Wichtig ist nur, was daraus folgt, und beides steht jetzt im Code statt nur in diesem
Abschnitt:

- **Fehlt die Spalte, bleibt stehen, was dasteht.** Der Zeilenaufbau nimmt den Schlüssel
  `work_group` nur mit, wenn die Spalte in der Datei stand. Ein Schlüssel, der nicht in der
  Zeile ist, ist ein Feld, über das niemand etwas gesagt hat — und das ist etwas anderes als
  eine leere Zelle.
- **Eine leere Zelle leert den gespeicherten Wert.** Dieselbe Regel wie bei Adresse, Vor- und
  Nachname: Die Datei ist die Wahrheit. Ohne diese Regel wäre ein von Hand eingetragener Wert
  einer, den der Verein über die Datei nie mehr loswird.

Die beiden Fälle landen im selben Feld und werden an zwei Stellen unterschieden. Beide Stellen
hatten vorher einen Vergleich über **vier** Felder, und beide hätten eine Änderung der
Arbeitsgruppe als „nichts hat sich geändert" gemeldet und **nichts geschrieben** — der
Vergleich im Import (`unchanged`) und der Vergleich im Repository (`update_member()`, der bei
Gleichheit gar nicht erst schreibt). Der zweite Ort ist beim Handlaufen aufgefallen, der erste
beim Lesen; beide sind jetzt in den Prüfungen.

### Die Namen, unter denen die Spalte gelesen wird

`e001: arbeitsdienst`, `arbeitsdienst`, `dienst`, `gruppe`, `arbeitsgruppe` — die Zahl gehört
zum Namen, weil sie im Namen steht. Der Importbildschirm zeigt alle fünf in der Zeile der
Arbeitsgruppe und **markiert diese Zeile als „freiwillig"**, weil ein Verein, der gerade die
Namen der Spalten liest, dort entscheidet, ob er die Spalte füllen muss. Die Prüfung liest die
Namen **vom Bildschirm** und bietet sie dem Import an; eine Liste, die der Test selbst
mitbrächte, bliebe grün, wenn die beiden auseinanderlaufen — und genau das ist der Fehler,
den diese Reihe schon einmal gesehen hat.

### Was an der Oberfläche dazukam

- Eine Spalte **Arbeitsgruppe** in der Mitgliederliste, zwischen Nachname und Adresse. Eine
  leere Zelle bekommt einen Strich und nicht nichts: „leer" und „nicht angegeben" sollen
  unterschiedlich aussehen.
- Ein Feld **Arbeitsgruppe** im Mitgliederformular, mit derselben Grenze (80 Zeichen) wie die
  beiden Namensfelder und **ohne** `required` — ein Verein, der einem Mitglied keine Gruppe
  gibt, muss es trotzdem anlegen können. Das Formular ist der ganze Datensatz: Was dort
  fehlt, wurde geleert.
- Eine Spalte **Arbeitsgruppe** in der Teilnehmerliste des Arbeitsdienstes, mit dem
  ausdrücklichen Kommentar, dass es die Arbeitsgruppe des **Mitglieds** ist und nicht die
  Gruppe des Dienstes. Zwei Dinge, die eine Spalte „Gruppe" nicht unterscheidet.
- Ein Satz auf dem Importschirm, der die Regel in Wörtern sagt.
- Die Arbeitsgruppe steht im **Bericht zur Datenauskunft**: Der Bericht verspricht, was dieses
  Plugin speichert, und ein weggelassenes Feld wäre ein Versprechen, das nicht gehalten wird.

### Die Prüfungen, die von „vier" auf „fünf" umgestellt wurden

Drei Behauptungen in der Suite sprachen von **vier** Feldern des Mitglieds, und alle drei sind
ersetzt statt gestrichen:

| Vorher | Jetzt |
| --- | --- |
| *the screen names a row for each of the four fields* | *… of the five fields*, und *the row of the work group says that a file may leave it out* |
| *A member has four fields and nothing else* (Kommentar über der Formularprüfung) | Vier Pflichtfelder als Pflicht-Textfelder, **plus** die Arbeitsgruppe als Textfeld **ohne** `required` |
| Die Schleife über die Namen mit einem `case` je Feld (fünfter Fall: Arbeitsgruppe) | dieselbe Schleife, ein Fall mehr — und der Fall schreibt den Wert in die Datei, damit die Behauptung „der Import findet das Feld unter diesem Namen" auch eine Zelle prüft |

### Die Gegenproben

| Fehlerbild | Erwartete Prüfung | Was tatsächlich rot wurde |
| --- | --- | --- |
| `read_member_fields()` lässt den Schlüssel fallen | Das Formular speichert die Gruppe | 12 Prüfungen |
| Die Spalte wird zur Pflicht gemacht | Eine Datei ohne sie wird gelesen | **47** Prüfungen |
| Der Vergleich `unchanged` im Import übersieht sie | Die Zähler stimmen | 3 Prüfungen |
| Der Vergleich in `update_member()` übersieht sie | Eine leere Zelle leert den Wert | 6 Prüfungen |
| Der Zeilenaufbau setzt den Schlüssel immer | Fehlende Spalte lässt den Wert stehen | 3 Prüfungen |
| Die Spalte fehlt in der Teilnehmerliste | Die Liste zeigt sie | 1 Prüfung |
| Das Formularfeld bekommt `required` | Es ist nicht als Pflicht markiert | 1 Prüfung |

Die zweite Zeile ist die interessanteste: Die Spalte zur Pflicht zu machen ist nicht ein
Detail, es reißt **jede** bestehende Datei eines Vereins weg, weil in der ganzen Suite jede
Datei vier Spalten hat — 47 Prüfungen fallen. Das ist die Messung für die Frage „Pflicht oder
freiwillig", und sie ist in Zahlen beantwortet, bevor die Frage gestellt wurde.

## Fassung 1.25.1: die Teilnehmerliste zeigt die Arbeitsgruppe

Gemeldet aus dem Verein: In den Arbeitsdiensten stehe in der Spalte *Arbeitsgruppe* immer
nur ein Strich. Die Frage dazu: „Sind die Angaben kopiert?" — und der Wunsch, die
Mitgliedsdaten in den Arbeitsdiensten **aus den Daten des Mitglieds** zu zeigen.

### Nein, es ist nichts kopiert — und es bleibt nichts kopiert

`fg_event_members` führt `event_id`, `member_id`, `registered_at`, `public_ref`,
`source_url`, `notified_count` und `added_by_admin`. Keine Kopie von Name, Adresse oder
Arbeitsgruppe. Die Teilnehmerliste holt sich das Mitglied über einen `INNER JOIN` dazu, und
sie zeigt deshalb immer den Stand des Mitglieds. Eine Kopie müsste bei jeder Änderung
mitgezogen werden, und der Moment, in dem das einmal vergessen wurde, ist der Moment, in
dem die Liste etwas Falsches behauptet.

Der Preis dieser Entscheidung ist ein Ort, an dem man aufpassen muss, und genau dort ist
der Fehler passiert.

### Die Ursache: eine Zeile, die fehlte

`FG_Store::query_event_member_rows()` baut das Mitglied der verbundenen Zeile **von Hand**:

```php
$member = new FG_Member();
$member->id         = (int) $row['member_id'];
$member->member_no  = (string) $row['member_no'];
$member->email      = (string) $row['email'];
$member->first_name = (string) $row['first_name'];
$member->last_name  = (string) $row['last_name'];
```

Die Abfrage holt `m.work_group` mit — der Wert lag in der Zeile. Er wurde nur nie ins
Objekt kopiert, das Feld behielt seinen Standard `''`, und die Zelle druckt für `''` den
Strich. Von Hand gebaut wird das Objekt mit Recht: Die Zeile mischt zwei Tabellen, ihre
`id` stammt aus `r.*` und gehört der Anmeldung; `to_member()` hätte sie als Mitglieds-ID
gelesen. Die Handarbeit ist nur nicht von selbst vollständig — die Zeile für `work_group`
fehlte, und damit die fünfte.

Dieselbe Fehlerform wie die beiden „vier Felder"-Vergleiche aus 1.25.0, und dieselbe
Ursache: eine Liste, die bei vier Feldern stehen bleibt. Der Kommentar über dem Objekt
sagt es jetzt ausdrücklich, damit der Ort beim nächsten Feld nicht wieder übersehen wird.

### Die Prüfung von 1.25.0 war falsch und ist ersetzt

Sie stand auf dem Namen des Prüfmitglieds:

```bash
neu "$GRUPPE_NR" dienstgruppe@example.org DienstGruppe Teilnehmer   # "DienstGruppe" = VORNAME
if printf '%s' "$seite" | grep -qF "DienstGruppe"; then ok ...
```

„DienstGruppe" war der **Vorname** des Prüfmitglieds, und gesucht wurde auf der **ganzen
Seite**. Die Namenszelle beantwortete die Frage, die Arbeitsgruppenzelle stand auf dem
Strich, und die Zeile war grün. Der Fehler ist damit nicht durch eine zu schwache, sondern
durch eine **falsche** Behauptung gegangen; sie ist nicht ergänzt, sondern ersetzt.

Die neue Prüfung liest die Tabelle **über den Spaltennamen**:

- Neuer Helfer `zelle <wert in der ersten Zelle> <Spaltenname>`. Er sucht die Tabelle,
  deren Kopf die genannte Spalte führt, nimmt deren Index aus dem Kopf und gibt die Zelle
  aus der Zeile aus, die mit diesem Wert in der ersten Zelle anfängt. Er meldet
  `keine-spalte:`, `keine-zeile:` und `zu-wenig-zellen:`, damit eine rote Zeile sagt, was
  gefehlt hat. Der vorhandene Helfer `cell` wurde dafür **nicht** erweitert: Er zählt
  Spalten und findet seine Zeile an einem Link, und beides trägt für die Mitgliederliste
  nicht — eine eingefügte Spalte würde jede Zelle dahinter um eins verschieben, und die
  Zeile ist hier durch die Mitgliedsnummer benannt.
- Das Prüfmitglied bekommt eine **gefüllte** Arbeitsgruppe, denn eine leere Zelle beweist
  nichts. `make-member` hat dafür ein fünftes, freies Argument bekommen, das über
  `insert_member()` geschrieben wird — derselbe Weg, den der Import nimmt.
- **Der Erwartungswert steht nicht im Test**, er wird aus dem Mitglied gelesen
  (`s member-by-no … work_group`).
- Die zweite Behauptung ist die gegen den Fehler von eben: die **Namenszelle** derselben
  Zeile darf den Wert **nicht** tragen. Eine Liste, die die Arbeitsgruppe in der Spalte des
  Namens druckte, würde die erste Behauptung mit richtigem Text am falschen Ort beantworten.

### Die Gegenproben

| Fehlerbild | Erwartet rot | Tatsächlich rot |
| --- | --- | --- |
| Die Zeile im Speicher entfällt (Zelle liest `—`) | die Zell-Prüfung | **1** (die Zell-Prüfung) |
| Die Spalte *Arbeitsgruppe* druckt den Vornamen | die Zell-Prüfung | **1** (die Zell-Prüfung, Zelle liest „Dienst") |
| Die Spalte *Name* druckt die Arbeitsgruppe | die Namenszell-Prüfung | **2** |

Die dritte Gegenprobe ist stärker als geplant: Sie macht zusätzlich die ältere Prüfung aus
`[3]` rot, die auf der Dienstseite nach dem Namen des Mitglieds sucht („the member of this
run is named in it"). Das ist kein Messfehler, sondern ein zweites, unabhängiges Zeugnis
dafür, dass die Namenszelle den Namen trägt — und es zeigt zugleich, dass jene Prüfung
die schwächere Sorte ist: Sie sucht ein Wort auf der ganzen Seite und wäre von einer
Arbeitsgruppe im falschen Feld nicht gestört worden.

### Was sich sonst geändert hat

Nichts. `fg_event_members` wird nicht angefasst, es gibt keine Migration, und das Schema
bleibt 1.8.0. Die Auswahlliste im Block „Mitglied zuweisen" bleibt unverändert; dort steht
eine Liste von Kandidaten, keine Auskunft über die Eingetragenen.

### Die Fassungsregel

Ab dieser Fassung nur noch **Bugfix-Versionssprünge**, außer es wird anders gesagt:
`FG_VERSION` wächst als `X.Y.Z`, und `Z` steigt bei jeder Änderung, auch bei einem neuen
Feld. Festgelegt vom Nutzer am 29.09.2026. Das Schema folgt seiner eigenen Regel und steigt
nur dort, wo sich die Datenhaltung ändert — deshalb steht hier 1.25.1 neben Schema 1.8.0.

## Fassung 1.25.2: die Absage nennt den nächsten Schritt

Die Frage aus dem Verein: Was passiert beim Angebot einer Fahrgemeinschaft, wenn
Mitgliedsnummer und E-Mail-Adresse stimmen, das Mitglied aber **nicht für diesen
Arbeitsdienst** angemeldet ist — und gibt es dafür eine passende Meldung?

### Das Verhalten war richtig, die Meldung war es nicht

`FG_Actions::submit_ride()` löst zuerst das Paar auf und fragt danach mit einem eigenen
Aufruf `is_event_participant()`. Fehlt die Anmeldung am Dienst, wird **nichts geschrieben**
— kein Eintrag, nicht einmal ein vorgemerkt —, es geht keine Mail, und der Besucher
landet mit `fg_notice=not_created` auf der Seite zurück. Das war gemessen
(`http.sh`: *a member who is not in the duty cannot offer a ride*, und die Fahrt steht
danach nirgends) und bleibt.

Die Meldung lautete: „Die Eintragung konnte nicht angelegt werden. Mitgliedsnummer und
E-Mail-Adresse müssen zu einem Mitglied des Vereins passen." Für genau diesen Fall ist der
zweite Satz **falsch** — das Paar passt. Der Leser wird in Nummer und Adresse geschickt,
nach einem Fehler, den es dort nicht gibt, und die eigentliche Bedingung kommt in der
Antwort nicht vor.

Dabei steht die Regel an zwei anderen Stellen richtig: im Hinweis unter dem Angebotformular
(„… das sich für diesen Arbeitsdienst eingetragen hat") und in der Empfangsbestätigung des
Kontaktformulars. Nur die Absage nannte eine Bedingung von zweien.

### Warum sie so allgemein ist, und was daraus folgt

Fünf Stellen im Plugin führen in diese Absage: ein POST ohne HTTPS, der Honigtopf, ein
Block aus **acht** Bedingungen, das fehlende Angebot und ein Schreibfehler. Ein Satz, der
auf alle fünf passt, kann keine Besonderheit des einen Falls nennen, in dem er
ausgerechnet erscheint. Der Ausweg ist nicht die Spezialmeldung, sondern ein Wechsel: die
**Bedingung** statt des **Ergebnisses** zu nennen. Die Bedingung steht ohnehin unter dem
Formular — sie zu wiederholen verrät nichts, und sie ist das Einzige, was alle fünf Fälle
gemeinsam haben.

### Die Grenze, die damit ausgesprochen ist

Die Absage darf nie sagen, **welche** der beiden Bedingungen fehlte. Ein Satz wie „deine
Mitgliedsnummer stimmt, aber du bist für den Dienst nicht angemeldet" erscheint nur dann,
wenn das Paar stimmt — und sein Erscheinen verrät einem Fremden mit geratener
Mitgliedsnummer, dass es sie gibt. Diese Regel steht seit 1.16.0 im Code; sie stand bis
1.25.2 in **keiner** Prüfung, und die Absage stand knapp davor, in genau diese Richtung
umgeschrieben zu werden.

Der Hinweis auf den nächsten Schritt („Bist du noch nicht angemeldet, trag dich zuerst für
diesen Dienst ein") steht deshalb für **alle** Leser da und nicht als Erklärung: Für ein
Mitglied ohne Anmeldung ist er die Hilfe, und für einen mit falscher Nummer ist er ein
Satz, der auch ohne sein Paar gilt.

### Der Wortlaut, und was nicht mehr gilt

> Die Eintragung konnte nicht angelegt werden. Sie ist nur möglich, wenn
> Mitgliedsnummer und E-Mail-Adresse zu einem Mitglied des Vereins passen, das für
> diesen Arbeitsdienst angemeldet ist. Bist du noch nicht angemeldet, trag dich zuerst
> für diesen Dienst ein.

Das Anmeldeformular sagt unverändert „Die Anmeldung ist nicht möglich. Mitgliedsnummer
und E-Mail-Adresse müssen zu einem Mitglied des Vereins passen." — und das ist richtig so:
Dieses Formular **legt** die Anmeldung an, es gibt keine zweite Bedingung, an der es
scheitern könnte. Beide Formulierungen beginnen mit demselben Paar-Satz und unterscheiden
sich danach, weil sie verschiedene Bedingungen haben.

### Die Prüfung, die ersetzt wurde

`http.sh` hielt den Wortlaut **wörtlich** fest: Der gelesene Text musste gleich
„Die Eintragung konnte nicht angelegt werden. " + fester Paar-Satz sein. Das ist eine
Festlegung auf den ganzen Satz — jede Umformulierung meldete sich als Fehler, und die Frage,
die hier gestellt wurde, konnte sie nicht beantworten. Sie ist ersetzt durch vier
Behauptungen über den Text:

| Behauptung | Wofür sie steht |
| --- | --- |
| die Absage nennt **beide Werte** des Paares | das Formular fragt zwei Werte |
| die Absage nennt die **zweite Bedingung** | das Angebot prüft zwei Bedingungen |
| die Absage sagt, **was zu tun** ist | der Fall, um den es geht |
| die Absage nennt **keinen Befund** (sieben aufgeführte Wortlaute) | die Grenze des Plugins |

Die vierte ist die einzige, die die Grenze überhaupt festhält.

### Der Kommentar beim Zähler

Der Kommentar über `publish_invalid_email` sprach von „three refusals" und nannte zwei;
im Code gibt es genau zwei Stellen, die ihn erhöhen. Beschriftung und Code stimmten
zusammen, der Kommentar nicht. Er ist auf zwei gebracht und nennt die beiden Stellen.

**Ein Irrtum, der bei dieser Fassung passiert ist und dokumentiert gehört:** Vor der
Prüfung wurde behauptet, die Beschriftung des Zählers sei falsch, weil sie nur das Paar
nenne. Sie nennt beide Gründe — „… passen nicht zu einem Mitglied, **oder das Mitglied ist
für diesen Arbeitsdienst nicht angemeldet**" — und der Kommentar darüber sagt ausdrücklich,
warum. Die Quelle des Irrtums war eine `has`-Nadel in `admin.sh`, die nur den Anfang der
Beschriftung nennt und für die ganze gehalten wurde. **Eine Nadel in einer Prüfung ist kein
Volltext**, und eine Behauptung über einen Bildschirm ist durch eine Prüfung nicht belegt,
wenn die Prüfung nur einen Ausschnitt von ihm prüft. Am Zähler und an seiner Beschriftung
ist deshalb nichts geändert worden.

### Die Gegenproben

| Fehlerbild | Erwartet rot | Tatsächlich rot |
| --- | --- | --- |
| Die Absage nennt einen Befund (ein zusätzlicher Satz, sonst alles unverändert) | die Grenzprüfung | **2** — die Grenzprüfung **und** die ältere Prüfung „kein Satz nennt ein Feld ohne das andere" |
| Die zweite Bedingung fällt aus der Absage | die Bedingungsprüfung | **2** — die Bedingungsprüfung **und** der Formvergleich, der behauptet, dass die Absage des Angebots mit der Bedingung des Dienstes weiterläuft |

Jede Gegenprobe hat neben ihrer eigenen Behauptung noch eine zweite, unabhängige rot werden
lassen, und beide Male passt das zum Fehlerbild: Der eingeschobene Befundssatz nennt ein
Feld allein, und die fehlende Bedingung macht die Behauptung des Formvergleichs
unwahr. Ein Messergebnis also, das über die Erwartung hinausgeht, und keines, bei dem
etwas Unerwartetes rot geworden wäre.

Die erste Fassung der ersten Gegenprobe war unbrauchbar und ist durch diese ersetzt: Sie
hatte den **ganzen** Satz ersetzt und dabei die beiden anderen Bedingungen mit
weggerissen, zeigte also vier Folgen statt einer. Jetzt wird ein Satz **ergänzt** und
sonst nichts angefasst, damit die Folge der Messung die Grenze ist und nicht der Zufall
eines Ersetzens. Der dritte Durchlauf fand eine Lücke in der Prüfung selbst, siehe unten.

**Eine Lücke, die erst die Gegenprobe gezeigt hat:** Die Nadeln der Grenzprüfung begannen
mit kleinen Wörtern („deine Mitgliedsnummer stimmt"). Der eingesetzte Satz hieß aber
„**Deine** Mitgliedsnummer stimmt.", und der Vergleich unterscheidet Groß- und
Kleinschreibung — die Grenzprüfung blieb grün, und nur die ältere Strukturprüfung hat es
gemerkt. Jede Nadel beginnt jetzt mit einem Wort, das nie großgeschrieben wird: einem
Feldnamen, „Mitglied" oder einem Satzteilstück. Das ist im Kommentar an der Prüfung
festgehalten, weil der Fehler beim nächsten Wortlaut wieder möglich wäre.

Für den korrigierten Kommentar über dem Zähler gibt es keine Gegenprobe: Ein Kommentar ist
nicht messbar, und das wird hier gesagt, statt es zu behaupten.

### Läufe

HTTP **236**, Mail 124, Admin 658, 0 Fehler. HTTP ist um 9 höher als bei 1.25.1: Eine
Festlegung auf den ganzen Satz wurde durch **zehn** Behauptungen ersetzt (beide Werte des
Paares, die zweite Bedingung, der nächste Schritt und sieben aufgeführte Befundswortlaute),
und zwei bestehende Prüfungen wurden auf **beide** Absagen umgestellt, ohne zu wachsen.
Mail und Admin sind unverändert — diese Fassung berührt keinen Formulartext außer dem der
Absage und keinen Zustandsschritt.

## Mail-Auswertung

`tests/mail-log.php` wird als `wp-content/mu-plugins/fg-mail-log.php` eingespielt. Es
hängt an `pre_wp_mail`, schreibt Empfänger, Betreff, Header, den effektiven Inhaltstyp,
den Rumpf und den Rückgabewert in die Tabelle `wp_fg_test_mail_log` und meldet eine
Zustellung als erfolgreich zurück. Damit ist belegt, dass das Plugin `wp_mail()` mit den
richtigen Werten aufruft, ohne dass ein SMTP-Server nötig ist.

Weil der Recorder `wp_mail()` an dieser Stelle abbricht, sieht er nur das, was das Plugin
als Argument übergibt — und das ist seit 1.21.0 das **Layout**. Genau hier war der Grund,
weshalb der Fehler so lange unbemerkt blieb: Der Recorder ist selbst eine Mail-Erweiterung,
seine Sicht ist die Sicht einer solchen Erweiterung, und er sieht seit dieser Fassung genau
das, was auch sie sieht. `wp_mail()` hat weiterhin kein Argument für eine zweite Fassung der
Nachricht; der reine Text kommt über die Aktion `phpmailer_init` in `AltBody`, und diese
Eigenschaft kann der Recorder nicht mehr sehen. Deshalb prüft `tests/mail-mime.php` die
fertige Nachricht getrennt: Es baut sie in der Reihenfolge von `wp_mail()` nach, ruft
`preSend()` auf und untersucht die Bytes, die hinausgehen würden. Geprüft werden der
Inhaltstyp, die Reihenfolge beider Teile, das Escaping eines Besuchereingabewerts, die
Fußzeile und die Adresse des Logos. Die Fußzeile
hat einen eigenen Abschnitt `[4]`: das eine Feld mit seiner Leerzeile zwischen den Abschnitten,
die drei Felder der Fassung davor, die beim Lesen zu einem Block verbunden werden, und die
Sache, dass ein gespeichertes Feld nicht zu einem liegengebliebenen der alten drei addiert
wird.

Die Option `fg_test_mail_fail=1` lässt die Zustellung fehlschlagen. Damit wird seit
1.15.0 ein einziger Fehlerpfad geprüft, `email_failed`: Die Zeile ist geschrieben,
der Eintrag steht in der Liste, und die Mail mit dem Löschlink ist nicht
angekommen. Die Suite prüft getrennt, dass der Zähler `mail_send_failed` steigt,
dass `publish_published` *nicht* steigt, dass der Eintrag öffentlich bleibt, und
dass ein zweiter Versuch mit derselben Nummer eine ganz normale Fahrt mit einem
eigenen Löschlink wird und als Veröffentlichung gezählt wird. Vor 1.15.0 kam ein
zweiter Pfad dazu, `publish_failed`, samt Rücknahme der Veröffentlichung und
Wiederverwendbarkeit desselben Bestätigungslinks; beide sind mit dem Zustand vor
der Veröffentlichung weggefallen, denn es gibt nichts mehr zurückzunehmen.
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

1. Eine echte Einreichung über das öffentliche Formular. Der Löschlink steht dann
   im Mail-Log unter SureMails → Email Log, Spalte „Body“; es ist die einzige
   Nachricht zu der Fahrt, und der Eintrag steht im selben Moment öffentlich.
2. Eine echte Anmeldung zu einem Arbeitsdienst auf der `[arbeitsdienste]`-Seite. Die
   Nachricht mit dem Abmeldelink steht dort unter demselben Ersteller; sie nennt keinen
   Namen, nur den Dienst und den Link.
3. Ein Kontaktaufruf zwischen zwei Mitgliedern desselben Dienstes. Erwartet werden zwei
   Einträge: einer an den Ersteller, einer an den Anfragenden.
4. Ein Klick auf den Löschlink einer Fahrgemeinschaft. Die Rückfrageseite erscheint
   mit dem Namen des Eintrags, dem Abfahrtsbereich und einem Knopf; erst der
   zweite Klick löscht, und danach erscheint kein Status im Log, weil keine
   Nachricht mehr gesendet wird.
5. Ein Klick auf den Abmeldelink einer Anmeldung. Erwartet wird zuerst die Rückfrage und
   keine Löschung; gelöscht wird erst nach dem zweiten Klick.

Der Recorder ist eine Einrichtung der Testumgebung. Am Plugin ist für SureMails nichts zu
tun: `class-fg-mailer.php` ruft ausschließlich `wp_mail()` auf. Davor hängt es die Filter
`wp_mail_from` und `wp_mail_from_name` ein und danach die Aktion `phpmailer_init`, die den
reinen Text als zweite Fassung setzt; alle drei werden nach dem Aufruf wieder entfernt. Der
Inhaltstyp steht als **Header** an der Nachricht (`Content-Type: text/html`) und nicht als
Filter — der Filter `wp_mail_content_type`, den dieser Absatz hier früher nannte, ist nie im
Plugin gewesen; `git log -S` findet keine Zeile dazu, und der Satz stand hier falsch.

Der Hinweis, den die Seiten **Einstellungen** und **E-Mails** für eine fremde Erweiterung
anzeigen, erscheint in dieser Umgebung **nicht**: SureMails wendet `pre_wp_mail` an, hängt sich
aber nicht daran, und nur eine Registrierung lässt sich anzeigen. Für die Sichtprüfung heißt
das: Der Hinweis ist hier nicht zu sehen, und das ist richtig.

## Was die Umgebung nicht prüft

- **Nicht den echten Export der Mitgliederverwaltung.** Der Import erwartet eine
  CSV-Datei mit einer Kopfzeile und den vier Spalten für Mitgliedsnummer, E-Mail-Adresse,
  Vorname und Nachname; die fünfte Spalte für die Arbeitsgruppe ist freiwillig und darf unter
  fünf Namen kommen, von denen `E001: Arbeitsdienst` der des Vereins ist. Welche Überschriften eine Mitgliederverwaltung dafür schreibt,
  ist eine Vermutung: `class-fg-member-import.php` trägt pro Feld eine Liste von
  Alternativen, und der Test füttert sie mit Namen, die plausibel sind, nicht mit den
  des Vereins. Geprüft ist damit der Importweg, nicht die Erkennung der Spalten des
  Vereins — und die Liste der Alternativen steht an genau einer Stelle im Code,
  `FG_Member_Import::$header_aliases`.
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
