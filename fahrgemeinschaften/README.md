# Fahrgemeinschaften

Minimales WordPress-Plugin zur Koordination von Fahrgemeinschaften für Vereinsarbeitsdienste.

## Installation

1. Den Ordner `fahrgemeinschaften` in `wp-content/plugins/` kopieren.
2. Das Plugin im WordPress-Backend aktivieren.
3. Unter **Fahrgemeinschaften → Arbeitsdienste** einen Arbeitsdienst mit Datum und Teilnehmer-E-Mails anlegen.
4. Eine beliebige WordPress-Seite erstellen und den Shortcode `[fahrgemeinschaften]` einfügen.
5. Für zuverlässige E-Mails ein SMTP-Plugin einrichten und SPF, DKIM sowie DMARC prüfen.

## Öffentliche Seite

- Listet nur veröffentlichte Fahrgemeinschaften zu aktiven Arbeitsdiensten.
- Reihenfolge von oben nach unten: Sprunglink **Eintrag anlegen**, Liste der Fahrgemeinschaften, erst dann das Formular. Die meisten Besucher kommen für die Liste; wer selbst etwas eintragen will, springt über den Link hinunter. Der Link zeigt mit einem Pfeil nach unten, wohin er führt.
- Jeder Eintrag steht in einer Zeile: Angebotsart, Abfahrtsbereich und Vorname oder Spitzname. Das Formular nennt unter dem Namensfeld und unter dem Abfahrtsbereich je einen Hinweis; die drei Beispiele für den Bereich (Langwasser, Nürnberg Nord, S-Bahnstation Ostring) sind gegen die Serverseite geprüft, damit der Hinweis keine Werte anbietet, die sie zurückweist. Das Kontaktformular ist eingeklappt und öffnet sich über die Schaltfläche **Kontaktieren**; danach bietet dieselbe Schaltfläche **Schließen** an, das Formular selbst sendet über **Absenden** ab.
- Die Beschriftung **E-Mail** im Kontaktformular bleibt in einer Zeile. Ohne das bricht der Browser sie am Bindestrich um, und aus „E-Mail“ werden zwei Zeilen.
- Nach dem Absenden springt der Browser zur Meldung: Die Weiterleitung trägt den Sprungziel `fg-hinweis` als Endung der Adresse, und die Meldung trägt diese `id`. Beide Namen stehen in `FG_NOTICE_ANCHOR`, damit sie nicht auseinanderlaufen können. Ohne den Sprung bliebe der Besucher unten am Formular stehen und die Meldung stünde über dem Bildschirmrand.
- Die öffentliche Seite kommt ohne JavaScript aus. Das Aufklappen ist ein natives `details`-Element; eine Formular, das nur ein Skript öffnen könnte, wäre ohne Skript nicht erreichbar.
- Neue Einträge bleiben zunächst vorgemerkt und werden erst nach E-Mail-Bestätigung veröffentlicht.
- Die Bestätigungs-E-Mail enthält eine Vorschau, einen Veröffentlichungs-Link und einen Link zum Verwerfen der Vormerkung.
- Die Kontaktaufnahme erfolgt ohne E-Mail-Bestätigung. Die öffentliche Antwort ist unabhängig von der Gültigkeit einer Adresse gleich.
- E-Mail-Adressen, interne IDs, UUIDs und Tokens werden nicht öffentlich ausgegeben.

## Adminbereich

- **Arbeitsdienste:** anlegen, ändern, dauerhaft löschen und Teilnehmer-E-Mails pflegen. Die UUID ist nur lesbar.
- **Fahrgemeinschaften:** vorgemerkte und veröffentlichte Einträge einsehen und dauerhaft löschen. Es gibt bewusst kein Bearbeitungsformular: was jemand anderes veröffentlicht hat, wird nicht nachträglich umgeschrieben.
- **Einstellungen:** Logo und Fußzeile der E-Mails, dazu eine Vorschau des HTML-Teils.
- **Statistik:** aggregierte Formular- und Bot-Signale ohne personenbezogene Einzelangaben.

## Datenspeicherung

Es gibt keine eigenen WordPress-Beitragstypen. Die Daten stehen in zwei eigenen Tabellen, `{prefix}fg_events` und `{prefix}fg_rides`, die das Plugin beim Aktivieren anlegt. Die Teilnehmer-E-Mails eines Arbeitsdienstes liegen als Textspalte mit einer Adresse je Zeile in derselben Zeile. Damit kann es für diese Daten weder Permalinks noch Revisionen, Entwürfe, Papierkorb, REST-API oder Beitrags-Versionen geben.

Bestätigungs- und Löschtoken werden als zufällige Werte erzeugt und nur als SHA-256-Hash gespeichert. Jeder Token wird über ein bedingtes `UPDATE` genau einmal verbraucht.

Das Plugin bringt keine eigene Berechtigung mit und verändert keine Rolle. Für das Ansehen und Bearbeiten genügt `edit_posts`, für das endgültige Löschen `delete_posts`. Damit sehen Autoren und Redakteure im Verzeichnis der Fahrgemeinschaften auch alle Kontaktadressen.

Neben den Tabellen speichert das Plugin eine Option `fg_settings` ohne Autoload: die ID des Logo-Anhangs sowie die drei Fußzeilen der E-Mail. Sie wird beim Deinstallieren mitgelöscht. Die Fußzeilen sind Vereinsangaben und keine personenbezogenen Daten einzelner Mitglieder; die Logo-ID verweist auf einen Anhang der Mediathek.

**Achtung beim Deinstallieren:** Das Deinstallieren des Plugins löscht beide Tabellen samt aller Arbeitsdienste, Fahrgemeinschaften und Teilnehmerlisten endgültig. Vor einer Deinstallation die Daten aus dem Adminbereich exportieren.

## Technische Voraussetzungen

- WordPress 6.4 oder neuer und PHP 7.4 oder neuer.
- WordPress 6.9 oder neuer für das Logo in der E-Mail. `wp_mail()` kann erst ab dieser Version ein eingebettetes Bild übernehmen (`$embeds`, seit 6.9.0). Mit einem älteren WordPress wird das Argument stillschweigend ignoriert, die E-Mail geht als `multipart/alternative` raus, das Logo fehlt aber als leeres Bild. Für den Rest des Mailbetriebs genügt WordPress 6.4.
- Eine erreichbare HTTPS-Installation. Ist die Website-Adresse mit `https://` konfiguriert, wird die öffentliche Shortcode-Seite und jede Bestätigungs-/Löschseite bei HTTP auf HTTPS umgeleitet, das Formular wird über HTTP nicht ausgegeben und eine Formular-Mutation über HTTP abgewiesen.
- Ohne TLS im Betrieb (etwa in einer lokalen Entwicklungsinstallation) gibt es keine sichere Seitenvariante, auf die verwiesen werden könnte. Das Plugin leitet dann nicht um, zeigt das Formular und verarbeitet die Anfrage, wie sie ankommt, und weist im Adminbereich auf den fehlenden HTTPS-Betrieb hin.
- Ein SMTP-Plugin muss die Zustellung über `wp_mail()` sicherstellen. Das Plugin setzt für alle eigenen Nachrichten einen kontrollierten Absender (die Website-Administratoradresse) und übergibt den Text als `text/plain`. Den Inhaltstyp der fertigen Nachricht bestimmt PHPMailer: Weil ein HTML-Teil dazukommt, wird aus jeder Nachricht ein `multipart/alternative`, in dem der Text als erste Alternative steht. Das Plugin setzt deshalb selbst keinen Inhaltstyp.
- Das Plugin bringt kein eigenes Mail-Plugin mit und registriert keinen eigenen `pre_wp_mail`-Filter. Es ist mit jedem Transport kompatibel, der `wp_mail()` abfängt. Ein SMTP-Plugin darf den Absender nicht hart überschreiben: Der Filter `wp_mail_from` liefert die Administratoradresse, damit SPF und DMARC der eigenen Domain greifen. Erzwingt das Mail-Plugin einen eigenen Absender, muss dieser beim Mailanbieter freigegeben sein.
- Die WordPress-Zeitzone wird für Datum, optionale Uhrzeit, Ablauf und Statistik verwendet. Ohne Uhrzeit bleibt ein Arbeitsdienst bis 23:59 Uhr des angegebenen Tages aktiv.
- `FG_VERSION` steht als Parameter an der Adresse des Stylesheets und muss mit **jeder** Änderung an `assets/css/fahrgemeinschaften.css` mitwachsen, ebenso im Plugin-Kopf. Bleibt die Zahl stehen, liefert die Seite neue Beschriftung mit alter Optik: Der Browser hält die Datei unter derselben Adresse im Cache und lädt sie nicht nach. Genau das ist passiert, als die beiden Labels der Kontaktschaltfläche nebeneinander standen, weil die neue Regel „das offene Label ist verborgen“ nur in der nachgeladenen Datei stand. Die Zahl auch dann erhöhen, wenn nur die Optik und nicht der Text geändert wurde.

## Bedienung und Datenfluss

Das Plugin-Menü **Fahrgemeinschaften** enthält vier Unterseiten in dieser Reihenfolge:
**Arbeitsdienste**, **Fahrgemeinschaften**, **Einstellungen**, **Statistik**. Ein Klick auf den Namen
"Fahrgemeinschaften" führt zur ersten Unterseite, WordPress verlinkt den
Obermenüpunkt immer auf den ersten Eintrag der Liste.

1. Im Plugin-Menü **Fahrgemeinschaften → Arbeitsdienste** einen Dienst mit Titel, Datum, optionaler Uhrzeit, Teilnehmer-E-Mails und der Option „Öffentlich sichtbar“ anlegen.
2. Auf einer WordPress-Seite `[fahrgemeinschaften]` einfügen.
3. Eine öffentliche Eintragung wird mit Status `pending` gespeichert. Die Bestätigungs-E-Mail enthält eine Vorschau sowie getrennte Links zum Bestätigen und Verwerfen. Erst die POST-Bestätigung schaltet den Eintrag auf `published`.
4. Nach der Veröffentlichung wird ein neuer Löschlink per E-Mail versendet. Der Link ist bis mindestens 30 Tage nach dem Ende des Arbeitsdienstes gültig. Vormerkungen und ihre Token werden nach 48 Stunden automatisch entfernt.
5. Kontaktanfragen werden nur bei einer für genau diesen Arbeitsdienst hinterlegten Adresse weitergeleitet. Die öffentliche Antwort bleibt bei gültiger und ungültiger Adresse gleich.
6. Im Plugin-Menü **Fahrgemeinschaften → Einstellungen** das Logo aus der Mediathek wählen und Absender, Kontakt und rechtlichen Hinweis eintragen. Die drei Textfelder sind Pflicht: Ist eines leer, wird nichts gespeichert. Über **E-Mail ansehen** lässt sich der HTML-Teil mit Beispieltext ansehen, ohne eine echte Mail zu verschicken.

Öffentliche Formulare enthalten keine freienbeschreibenden Felder und keine Platzanzahl. Der Vorname oder Spitzname und der Abfahrtsbereich werden als Textfelder mit Längenbegrenzung geprüft. Die E-Mail-Adresse bleibt ausschließlich im geschützten Admin-/E-Mail-Verkehr.

## Datenschutz, Sicherheit und Tests

Arbeitsdienste und Fahrgemeinschaften liegen in eigenen Tabellen und sind damit konstruktionsbedingt nicht öffentlich abfragbar, nicht im Suchindex, nicht über die REST-API erreichbar und kennen weder Revisionen noch Papierkorb. Interne Datensatz-IDs, UUIDs und Token-Spalten werden nicht in öffentlichen Formularen oder Listen verwendet; stattdessen werden zufällige öffentliche Referenzen eingesetzt. E-Mail-Adressen und Token werden nicht in öffentliches HTML oder öffentliche JavaScript-Daten geschrieben.

Unter **Fahrgemeinschaften → Statistik** stehen die Zähler für Heute, die letzten 7 Tage und die letzten 30 Tage. Die Option enthält ausschließlich tägliche Aggregate und wird nach 90 Tagen bereinigt. Es werden keine Rohformulare, Namen, E-Mail-Adressen oder IP-Adressen gespeichert.

WordPress' Datenschutz-Tools exportieren Einträge und Arbeitsdienst-Teilnahmen für eine angefragte E-Mail-Adresse und entfernen die dort gespeicherten Fahrgemeinschaften sowie die Adresse aus den Teilnehmerlisten. Das Plugin registriert dafür automatisch einen Vorschlag in WordPress' Datenschutz-Richtlinie.

Vor einer Installation sollten in einer Staging-Installation mindestens diese Fälle geprüft werden:

- HTTPS-Weiterleitung und kein Formular-Mutation-POST über HTTP,
- eine nicht hinterlegte E-Mail kann weder veröffentlichen noch Kontakte auslösen,
- E-Mail-Scanner öffnen die Handhabungsseite, löschen oder veröffentlichen aber nicht,
- Bestätigungs- und Löschlinks funktionieren einmalig und laufen nach Ablauf ab,
- ein abgelaufener Arbeitsdienst verschwindet aus der öffentlichen Liste,
- das endgültige Löschen eines Arbeitsdienstes löscht alle zugehörigen Einträge,
- die öffentliche HTML-Ausgabe enthält keine E-Mail-Adresse, UUID oder interne Datensatz-ID,
- die E-Mail ist im Spam-Ordner angekommen: Eine HTML-Nachricht von einer kleinen Vereinsdomain ohne eigenes SPF/DKIM wird häufiger als reine Textmail aussortiert. Der Plain-Text-Teil senkt das Risiko, beseitigt es aber nicht. Der erste echte Versand sollte deshalb mit einem Blick in den Spam-Ordner beginnen.

## Verhalten bei Fehlern und Grenzfällen

- Alle drei öffentlichen Mutationen (Eintrag, Kontakt, Bestätigen/Löschen) beginnen mit einer POST-Prüfung. Jede andere Methode beantwortet WordPress mit Status 405 und `Allow: POST`, ohne etwas zu verändern.
- Vormerkungs-E-Mail nicht zustellbar: Der eben angelegte Eintrag wird wieder endgültig gelöscht, der Zähler `mail_send_failed` steigt, die öffentliche Antwort lautet `email_failed`.
- E-Mail mit dem Löschlink nach der Bestätigung nicht zustellbar: Die Veröffentlichung wird zurückgenommen, der Eintrag bleibt vorgemerkt und beide Vormerkungs-Token bleiben gültig, damit derselbe Bestätigungslink erneut funktioniert. Die öffentliche Antwort lautet `publish_failed`.
- Veraltetes oder manipuliertes Formular-Token: Die Anfrage wird nicht ausgeführt, die öffentliche Antwort lautet `form_expired`.
- Ein abgelaufener oder schon verbrauchter Token-Link führt zu `invalid_token` und verändert nichts.
- Die tägliche Bereinigung läuft über WP-Cron (`fg_daily_cleanup`). Zusätzlich führt der Adminbereich sie einmal täglich als Rückfallebene aus; die Marker-Option `fg_last_cleanup` verhindert mehrfache Läufe.
- Es gibt keinen Papierkorb. Beide Objekte werden ausschließlich endgültig gelöscht, über einen nonce-geschützten Link im Bearbeitungsformular des Arbeitsdienstes beziehungsweise auf der Detailseite einer Fahrgemeinschaft.
- Ungültige Daten im Adminbereich werden nicht stillschweigend gespeichert: Ein ungültiges Datum, eine ungültige Uhrzeit oder ein leerer Titel werden mit einem Hinweis abgewiesen, es bleibt der vorherige Stand stehen. Ungültige Teilnehmer-Adressen werden verworfen, der Rest gespeichert. Ein Arbeitsdienst ohne gültiges Datum kann nicht öffentlich sichtbar gespeichert werden. Eine veröffentlichte Fahrgemeinschaft ohne gültige Kontaktadresse erscheint nicht öffentlich. Mehrere Hinweise zu einem Speichervorgang werden als Stapel ausgegeben (maximal fünf).

## Bewusste Abweichungen und Entscheidungen

- **Eigene Tabellen statt eigener Beitragstypen.** Arbeitsdienste und Fahrgemeinschaften brauchen keine Beitragsfunktionen: keine Permalinks, keine Revisionen, keinen Entwurfszustand, keinen Papierkorb, keine REST-API, kein `post_status`, keine Meta-Key-Abfragen. Dafür bleiben zwei Tabellen, und der Preis ist, dass die alten WordPress-Funktionen (`WP_Query`, `get_posts`, die Revisionen, die „Beiträge“-Ansichten) für diese Daten nicht greifen.
- **Keine eigene Berechtigung.** Das Plugin nutzt `edit_posts` und `delete_posts`. Eine eigene Fähigkeit wäre nur nötig, wenn das Plugin Rechte feinabstimmen müsste; dafür ist der Aufwand zu groß. Folge: Autoren und Redakteure sehen im Ride-Verzeichnis alle Kontaktadressen aller Arbeitsdienste.
- **Sprungziele statt Sprungskript.** Nach dem Absenden eines Formulars hängt die Weiterleitung das Sprungziel als Endung an (`…&fg_notice=pending#fg-hinweis`), und der Browser holt die Meldung damit von selbst in den Bildschirm. Ein Skript, das nach dem Laden auf die Meldung scrollt, wäre dafür nicht nötig; die Sprungweite ist nur eine Zahl im Stylesheet (`scroll-margin-top`), weil eine Kopfzeile, die stehen bleibt, das Sprungziel sonst verdeckt. Die Höhe einer Theme-Kopfzeile kennt das Plugin nicht, deshalb ist der Wert großzügig gewählt.
- **Fahrgemeinschaften sind im Admin nur lesbar und löschbar.** Der Eintrag kommt aus dem öffentlichen Formular und wird von der Person selbst bestätigt oder zurückgezogen. Ein Bearbeitungsformular würde bedeuten, dass eine Administratorin den Text eines anderen Menschen umschreiben kann; das ist bewusst nicht vorgesehen. Unvollständige Datensätze fallen dadurch aus der öffentlichen Liste, statt repariert zu werden.
- **Sichtbarkeit statt Entwurfszustand.** Ein Arbeitsdienst hat nur ein Kästchen „Öffentlich sichtbar“ und ein Datum. Ein Entwurf mit kaputtem Datum, der sich später nicht reparieren lässt, ist damit nicht möglich. Der Preis: es gibt keinen Entwurf, der schon vor dem Datum gepflegt werden kann — ein Arbeitsdienst ohne Datum existiert nicht.
- **Kein Freitextfeld und keine Platzanzahl.** Das Formular fragt nur Art, Arbeitsdienst, Vorname oder Spitzname, Abfahrtsbereich, E-Mail-Adresse und Einwilligung ab. Der Vorname oder Spitzname wird bewusst öffentlich angezeigt; wer nicht mit Namen auftreten möchte, wählt einen Spitznamen. Zusätzlich weist die Serverseite E-Mail-Adressen, Telefonnummern und „Straße + Hausnummer“ in Name und Bereich ab; der Zähler `publish_personal_data` weist auf solche Versuche hin. Einen vollständigen Namen erkennt die Serverseite nicht zuverlässig — dafür bleibt die Bestätigungsseite, auf der die Person vor dem Veröffentlichen genau das liest, was öffentlich wird.
- **Die Einwilligung benennt, was öffentlich wird.** Sie sagt wörtlich, dass Vorname oder Spitzname, Angebotsart, Abfahrtsbereich und Arbeitsdienst angezeigt werden und E-Mail-Adresse und Telefonnummer nicht. Vorher stand dort „meine persönlichen Kontaktdaten“, was man auch so lesen konnte, dass eine Person nicht öffentlich erscheint — mit einem erwarteten Vornamen im öffentlichen Listenaufbau wäre das widersprüchlich gewesen. `FG_CONSENT_VERSION` steht deshalb auf `1.1`. Bereits erteilte Einwilligungen behalten ihren alten Vermerk; es gibt keine Migration und keine Nachfrage, denn die Einwilligung galt schon damals für die Anzeige der eingegebenen Angaben und der Name ist eine davon.
- **Die öffentliche Seite kommt ohne JavaScript aus.** Das Kontaktformular eines Eintrags ist eingeklappt und öffnet sich über die Schaltfläche „Kontaktieren“; dieselbe Schaltfläche bietet danach „Schließen“ an, abgesendet wird über „Absenden“. Das Aufklappen ist ein natives `details`-Element mit zwei Beschriftungen, von denen das Stylesheet die zum Zustand passende zeigt — ein nicht dargestellter Text nimmt keinen Platz ein und wird auch nicht vorgelesen, die Schaltfläche heißt also immer nach dem, was sie gerade tut. Eine einzige Schaltfläche, die sich beim Öffnen in „Absenden“ umbenennt und beim zweiten Klick sendet, ginge nur mit Skript; dann wäre die Kontaktaufnahme ohne Skript unerreichbar, weil das Formular vorher für jeden Besucher offen sichtbar war. Der Preis ist eine zweite Schaltfläche im geöffneten Zustand.
- **Die UUID wird automatisch erzeugt.** Die Arbeitsdienst-UUID entsteht einmalig beim Anlegen und ist danach nur lesbar. Von Hand eingetragene UUIDs sind eine vermeidbare Fehlerquelle, weil die Kennung in Bestätigungs-Mails und Statistik wiederkehrt.
- **Neutrale Kontaktantwort.** Unabhängig von der Gültigkeit einer Adresse lautet die öffentliche Antwort immer gleich: „Vielen Dank für deine Anfrage. Wir informieren den Ersteller, sofern die angegebene E-Mail-Adresse für den gewählten Arbeitsdienst hinterlegt ist.“
- **Öffentliche Referenz statt Datensatz-ID.** Listen, Formulare und auch der Löschlink aus der E-Mail verwenden durchgängig die zufällige öffentliche Referenz (`public_ref`), nie die interne ID oder die UUID. Einzige dokumentierte Ausnahme ist das Feld `source_url`, das die vollständige Seiten-URL des Formulars enthält und damit die WordPress-Seiten-ID transportieren kann.
- **Die Farbe der Schaltfläche steht in einer eigenen Variablen.** Der grüne Balken links an einer Meldung und der Grundton der Schaltflächen waren lange eine Variable (`--fg-accent`). Als die Schaltfläche auf `#004488` wechselte, wären beide Farben gemeinsam umgesprungen, und der grüne Hintergrund der Erfolgsmeldung (`#eef8f2`) stünde unter einem blauen Balken. Deshalb heißen sie jetzt `--fg-button` und `--fg-notice-bar`, und der Name sagt, wofür die Farbe dasteht. Die Schaltfläche trägt außerdem keine eigene Schriftgröße: `font: inherit` nimmt die Größe des Textes, sodass eine Theme-Regel für `button` die Beschriftung nicht von der Umgebung wegschieben kann. Was eine Schaltfläche groß wirken lässt, ist der Innenabstand, und der ist klein gewählt.
- **Die Handhabungsseite bringt ihre Formatierung selbst mit.** Sie entsteht beim Klick auf den Link aus der E-Mail, ohne das Theme und ohne das Stylesheet des Plugins, deshalb schreibt `includes/class-fg-security.php` die nötigen Regeln als eine Zeile in die Seite. Das ist die einzige Stelle, an der eine Farbe des Plugins zweimal im Code steht, und `tests/http.sh` vergleicht deshalb beide, weil zwei Kopien auseinanderlaufen, sobald nur eine von ihnen geändert wird.
- **Serverseitige Verträge.** `confirmed_at` kennzeichnet bestätigt veröffentlichte Fahrgemeinschaften. Die öffentliche Liste verlangt diesen Zeitstempel zusätzlich zu Status `published`, gültigem Modus, Abfahrtsbereich, Kontaktadresse und öffentlicher Referenz.
- **HTTPS-Erzwingung nur bei vorhandenem HTTPS.** Eine Weiterleitung ist nur sinnvoll, wenn die Zieladresse existiert. Ist die Website-Adresse nicht mit `https://` konfiguriert, bleibt die Anfrage unverändert bearbeitbar und der fehlende TLS-Betrieb wird im Adminbereich auf den Plugin-Seiten gemeldet, statt die öffentliche Seite durch eine nicht beantwortbare Adresse unbrauchbar zu machen.
- **Keine Migration.** Aus einer älteren, auf eigenen Beitragstypen basierenden Fassung wird nichts übernommen. Die alten Datensätze sind vor dem Umstieg zu löschen, die alten Rollenberechtigungen (`manage_fahrgemeinschaften`, `edit_fahrgemeinschaften` und Verwandte) bleiben zurück, weil das Plugin sie nicht kennt und nicht entfernt; sie lassen sich in der Rollenverwaltung oder über `WP_Role::remove_cap()` zurückziehen.
- **Das E-Mail-Layout liegt im Plugin, nicht im Adminbereich.** Das Markup, die Formatvorlagen und die Outlook-Abfragen stehen in `includes/class-fg-mail-templates.php`; einstellbar sind nur Logo und Fußzeile. Ein Feld für eigenes HTML wäre die einzige Stelle, an der beliebiges Markup in jede ausgehende Nachricht der Website gelangt, und der Nutzen — die Optik zu ändern — ist gering gegenüber diesem Risiko. Wer das Layout ändern will, ändert die Datei; das ist eine bewusste Entscheidung und keine vergessene Funktion.
- **Der Text einer Nachricht steht genau einmal.** Der Wortlaut wird als Plain Text geschrieben und vom Layout in HTML übersetzt; beide Varianten können nicht auseinanderlaufen. Die Übersetzung ist bewusst schlicht: Leerzeilen trennen Absätze, ein einfacher Zeilenumbruch wird zu einem `<br>`. URLs im Text werden nicht automatisch zu Links gemacht — der Klartext bleibt der Text, und ein Client, der nur Text anzeigt, verliert nichts.
- **Das Logo wird eingebettet, nicht nachgeladen.** Es kommt aus der Mediathek und wird als Datei an die Nachricht gehängt (`cid:logo`). Damit sieht die E-Mail auch dann vollständig aus, wenn der Empfänger fremde Bilder sperrt, und die IP-Adresse des Empfängers wird nicht an einen fremden Server weitergegeben. Der Preis: Das Logo muss einmal in die Mediathek hochgeladen werden, und WordPress ab 6.9 wird dafür vorausgesetzt.
- **Die Fußzeile ist Pflicht.** Absender, Kontakt und rechtlicher Hinweis müssen ausgefüllt sein, sonst wird nichts gespeichert. Eine E-Mail ohne Absenderangabe und ohne Pflichtinhalt ist der einzige Fehler, der sich später nicht mehr korrigieren lässt, weil sie beim Empfänger liegt.
- **`wp_mail()` bekommt den Text, der HTML-Teil kommt über `phpmailer_init`.** `wp_mail()` hat kein Argument für die zweite Alternative einer `multipart/alternative`-Nachricht. Deshalb wird der Text als Nachricht übergeben und im `phpmailer_init`-Aktion auf `AltBody` und `Body` verteilt. Das Plugin setzt deshalb keinen Inhaltstyp mehr: PHPMailer erkennt am zweiten Teil selbst, dass er aus zwei Alternativen besteht.
- **Statistik ohne Sperre.** Die Tageszähler werden gelesen, erhöht und zurückgeschrieben, ohne Sperre. Bei sehr gleichzeitigen Abläufen kann dadurch ein einzelner Zählerstand verloren gehen. Betroffen sind ausschließlich aggregierte Tageswerte ohne Personenbezug; für die Minimalversion wurde darauf bewusst verzichtet.
- **Offener Nebenläufigkeitsfall.** Klickt jemand „bestätigen“ und „verwerfen“ gleichzeitig, kann ein Token-Paar einen einzelnen Aufruf überleben. Der Bestätigungspfad ist der sicherere, weil er den Veröffentlichungszustand prüft, während der Verwerfpfad den Eintrag vollständig entfernt.
