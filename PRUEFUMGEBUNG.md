# Prüfumgebung

Diese Datei beschreibt, wie das Plugin funktional geprüft wird: welche Umgebung dafür
verwendet wird, wie sie jederzeit wiederherstellbar ist und was die vier Testläufe
tatsächlich belegen. Sie gehört nicht zum Plugin und wird nicht mitgeliefert.

Letzter vollständiger Lauf: 26.09.2026 — **399 Prüfungen, 0 Fehler**
(CLI 153, öffentliches HTTP 41, Mail-Ebene 66, Admin-Ebene 139).

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
| CLI | `tests/smoke.php` | WordPress im Container, Abschnitte 0–16: Tabellen, Aktivitätsgrenze, Vormerkung, Token-Links, Kontakt, Löschung, Ablehnungen, Admin, Bereinigung, Datenschutz, HTTPS, Markup-Hygiene |
| Öffentlich | `tests/http_setup.php` + `tests/http.sh` | `curl` gegen Apache über TLS: Weiterleitung, Standalone-Seiten mit Kopfzeilen, 405 bei GET, Hinweise, keine personenbezogenen Daten im HTML, Aufbau der kompakten Liste |
| Mail-Ebene | `tests/mail.sh` + `tests/mail-mime.php` | Die Meldungen, die ein Browseraufruf wirklich an `wp_mail()` übergibt: Wortlaut, Empfänger, Zustellfehler. Dazu die fertige MIME-Struktur: `multipart/alternative`, Text als erste Alternative, HTML als zweite, eingebettetes Logo unter `cid:logo` |
| Admin | `tests/admin.sh` | Echter Login, echte Roundtrips über `admin-post.php`: Navigation (Name des Obermenüpunkts, Reihenfolge und Markierung der vier Unterseiten auf jeder Seite), Arbeitsdienst anlegen, ändern, ungültige Daten, nonce-geschütztes endgültiges Löschen, Kaskadenlöschung, Einstellungen der E-Mail inklusive Pflichtprüfung, Mediathek-Auswahl und Vorschau |

Die Mediathek-Auswahl des Logos wird nicht angeklickt, sondern über ihre Stellung im
Dokument geprüft: Das Script hängt mit `wp_add_inline_script()` an `media-views` und wird
deshalb hinter der Mediathek und hinter den beiden Buttons ausgegeben, die es sucht. Ein
Vergleich der Positionen fängt genau den Fehler, dass beide Buttons vorhanden sind und
doch stumm bleiben. Zusätzlich wird geprüft, dass die Buttons zunächst deaktiviert
ausgeliefert werden: Läuft das Script nicht, sieht man das an der Oberfläche, statt es
an einem Klick zu bemerken.

Der Aufbau der öffentlichen Liste wird über die ausgelieferte Seite geprüft, nicht über
den Quelltext: Arbeitsdienst und Datum stehen in einer gemeinsamen Überschrift, jeder
Eintrag trägt Angebotsart, Abfahrtsbereich und Bezeichnung in einer Zeile, Feld und
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
