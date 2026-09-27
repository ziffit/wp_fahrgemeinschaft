# Prüfumgebung

Diese Datei beschreibt, wie das Plugin funktional geprüft wird: welche Umgebung dafür
verwendet wird, wie sie jederzeit wiederherstellbar ist und was die vier Testläufe
tatsächlich belegen. Sie gehört nicht zum Plugin und wird nicht mitgeliefert.

Letzter vollständiger Lauf: 26.09.2026 — **399 Prüfungen, 0 Fehler**
(CLI 153, öffentliches HTTP 41, Mail-Ebene 66, Admin-Ebene 139).

Seit diesem Lauf ist die Arbeitsdienstliste dazugekommen. Ein vollständiger Lauf
`run-all.sh` ist bis heute nicht gefahren, weil er am Anfang alle Arbeitsdienste und
Fahrgemeinschaften löscht und die Testdaten des Vereins damit nicht mehr da wären.
Stattdessen sind die neuen Prüfungen einzeln gegen die laufende Installation gefahren
worden, mit unverändertem Prüftext aus den eingecheckten Dateien:

| Was | Lauf | Ergebnis |
| --- | --- | --- |
| CLI-Abschnitte `[2a]` und `[3c]` | 63 | 0 Fehler |
| Admin-Suite, vollständig | 195 | 0 Fehler |
| HTTP-Abschnitt `[8]` | 15 | 0 Fehler |

Die Zahlen der drei Läufe lassen sich nicht mit denen vom 26.09. verrechnen: Sie
enthalten je Suite nur den Teil, der ohne den zerstörenden Aufbau zu fahren war. Ein
Gesamtlauf steht noch aus.


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
- Der Plugin-Ordner `fahrgemeinschaften/` ist als `wp-content/plugins/my-plugin` eingebunden; Änderungen wirken sofort.
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
`fg_schema_version` gesetzt, beide Tabellen `wp_fg_events` und `wp_fg_rides` vorhanden,
`fg_daily_cleanup` geplant, keine Rollenberechtigung des Plugins, Mail-Log leer.

## Testlauf

```bash
bash tests/run-all.sh
```

`run-all.sh` ruft zuerst `setup.sh` auf und startet dann vier Suiten. Jeder Lauf ist
isoliert: `smoke.php` und `http_setup.php` löschen vorher alle Arbeitsdienste und
Fahrgemeinschaften sowie die Statistik-Option, es gibt also keinen Zustand vom Vorlauf.

| Suite | Datei | Vorgehen |
| --- | --- | --- |
| CLI | `tests/smoke.php` | WordPress im Container, Abschnitte 0–16: Tabellen, Aktivitätsgrenze, öffentliche Seite samt beider Leermeldungen, Vormerkung, Token-Links, Kontakt, Löschung, Ablehnungen, Admin, Bereinigung, Datenschutz, HTTPS, Markup-Hygiene; dazu die Abschnitte `[2a]` (die vier freiwilligen Angaben eines Arbeitsdienstes) und `[3c]` (die Liste der Arbeitsdienste) |
| Öffentlich | `tests/http_setup.php` + `tests/http.sh` | `curl` gegen Apache über TLS: Weiterleitung, Standalone-Seiten mit Kopfzeilen, 405 bei GET, Hinweise, keine personenbezogenen Daten im HTML, Aufbau der kompakten Liste, Reihenfolge von Sprunglink, Liste und Formular, Rückkehrweg mit Sprungziel, Namensfeld gegen Kontaktdaten über den Zähler `publish_personal_data`, Verhalten der Schaltflächen im Stylesheet; dazu Abschnitt `[8]` für die Arbeitsdienstliste auf einer eigenen Seite |
| Mail-Ebene | `tests/mail.sh` + `tests/mail-mime.php` | Die Meldungen, die ein Browseraufruf wirklich an `wp_mail()` übergibt: Wortlaut, Empfänger, Zustellfehler. Dazu die fertige MIME-Struktur: `multipart/alternative`, Text als erste Alternative, HTML als zweite, eingebettetes Logo unter `cid:logo` |
| Admin | `tests/admin.sh` | Echter Login, echte Roundtrips über `admin-post.php`: Navigation (Name des Obermenüpunkts, Reihenfolge und Markierung der vier Unterseiten auf jeder Seite), Arbeitsdienst anlegen, ändern, ungültige Daten, nonce-geschütztes endgültiges Löschen, Kaskadenlöschung, Einstellungen der E-Mail inklusive Pflichtprüfung, Mediathek-Auswahl und Vorschau; dazu die vier freiwilligen Angaben im Formular, ihr Rundlauf durch die Tabelle und die Fälle, in denen das Speichern verweigert wird |

### Die Abschnitte `[2a]`, `[3c]` und `[8]`

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

Zwei Feinheiten der Prüftechnik, die sich hier bewährt haben: Die Wochentagsprüfung
vergleicht nicht mit einem festen Wort, sondern mit dem, was `format_event_date_long()`
aus der Tabelle rechnet — sonst wäre sie eine zweite Implementierung derselben
Regel. Und der Container hält den alten Bytecode eine Weile fest, nachdem eine Datei
ersetzt wurde: Ein Gegenprobenlauf, der direkt nach dem Kopieren misst, prüft die
vorige Fassung. Deshalb misst jede Gegenprobe zweimal.

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
bewusst eine Adresse, die auf keinem Dienst hinterlegt ist: Dann entsteht kein Eintrag,
und die Probe hinterlässt nichts. Die sieben Textprüfungen desselben Abschnitts sind gegen
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
Verfügung (siehe unten).

`tests/state.php` ist die Schnittstelle zwischen den Shell-Suiten und den Tabellen. Es liest
und schreibt über `FG_Repository` (`statuses`, `count`, `count-published`, `count-alias`,
`event`, `ride`, `make-event`, `make-ride`, `find-event`, `exists-*`, `delete-*`, `purge`),
damit die Skripte den Zustand der Installation prüfen können, ohne WordPress-Beiträge zu
kennen. Feldzugriffe laufen über eine Whitelist; jeder Befehl gibt genau einen Wert aus.
Dazu kommen `settings`, `settings-json` und `settings-restore` für die Option `fg_settings`:
Die Admin-Suite liest die Option vor dem eigenen Lauf und stellt sie danach wieder her, damit
eine von Hand gepflegte Fußzeile den Testlauf übersteht. Weil die Option dabei nicht leer
sein muss, vergleichen die Prüfungen des Speicherns jeweils den Zustand davor und danach, statt
auf ein leeres Feld zu prüfen.

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
Escaping eines Besuchereingabewerts, die drei Fußzeilen und das eingebettete Logo.

Die Option `fg_test_mail_fail=1` lässt die Zustellung fehlschlagen. Damit werden die
Fehlerpfade `email_failed` und `publish_failed` über echtes HTTP geprüft, inklusive
Rücknahme der Veröffentlichung und der Wiederverwendbarkeit desselben Bestätigungslinks.

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

Ohne Recorder läuft der Nachrichtentransport unverändert über `wp_mail()`; am Plugin ist
dafür keine Zeile anzufassen.

## Handprüfung mit SureMails

Für die Sichtprüfung der Mails ist in der Testinstanz **SureMails** installiert und aktiv.
Deren Simulation ist eingeschaltet (`email_simulation=yes` in `suremails_connections`), das
Mail-Log ist aktiv (`log_emails=yes`). Eine eingetragene Verbindung ist nicht nötig, die
Simulation versendet nichts.

Damit die Nachrichten dort ankommen, muss der Mail-Recorder ausgeschaltet sein (siehe oben).
Der Zustand ist die Voraussetzung für:

1. Eine echte Einreichung über das öffentliche Formular. Der Bestätigungslink steht dann
   im Mail-Log unter SureMails → Email Log, Spalte „Body“.
2. Ein Kontaktaufruf zwischen zwei Teilnehmern. Erwartet werden zwei Einträge: einer an
   den Ersteller, einer an den Anfragenden.
3. Ein Klick auf den Bestätigungslink. Der Status `sent` erscheint, und die Meldung zur
   Veröffentlichung mit dem Lösch-Link wird geloggt.

Der Recorder ist eine Einrichtung der Testumgebung. Am Plugin ist für SureMails nichts zu
tun: `class-fg-mailer.php` ruft ausschließlich `wp_mail()` auf und setzt davor nur
`wp_mail_from`, `wp_mail_from_name` und `wp_mail_content_type` per Filter, die es danach
wieder entfernt.

## Was die Umgebung nicht prüft

- **Kein echter SMTP-Transport.** Es läuft kein Mailserver; die Zustellung wird nur
  simuliert. Geprüft wird, was das Plugin übergibt; die Zustellung selbst ist eine
  Betriebsvoraussetzung der Installation
  (siehe `fahrgemeinschaften/README.md`, Abschnitt „Technische Voraussetzungen“).
- **Keine Fremd-Plugins und Themes.** SureMails ist installiert, weil der Mailversand
  sonst nicht prüfbar wäre; es ist kein Teil der Lieferung und die Testläufe hängen
  nicht davon ab. Alle anderen Erweiterungen Dritter sind nicht installiert; das
  Zusammenspiel mit ihnen ist nicht Teil dieser Prüfung.
- **Kein Lasttest.** Die Statistik schreibt ohne Sperre zurück; das ist als bekannte
  Einschränkung dokumentiert und nicht als Test abgedeckt.
- **Keine Barrierefreiheits- und Browserprüfung.** Die HTML-Ausgabe wird auf Struktur und
  Escaping geprüft, nicht auf Darstellung in verschiedenen Browsern.
