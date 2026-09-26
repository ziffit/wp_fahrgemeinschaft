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
- Neue Einträge bleiben zunächst vorgemerkt und werden erst nach E-Mail-Bestätigung veröffentlicht.
- Die Bestätigungs-E-Mail enthält eine Vorschau, einen Veröffentlichungs-Link und einen Link zum Verwerfen der Vormerkung.
- Die Kontaktaufnahme erfolgt ohne E-Mail-Bestätigung. Die öffentliche Antwort ist unabhängig von der Gültigkeit einer Adresse gleich.
- E-Mail-Adressen, interne IDs, UUIDs und Tokens werden nicht öffentlich ausgegeben.

## Adminbereich

- **Arbeitsdienste:** anlegen, ändern, dauerhaft löschen und Teilnehmer-E-Mails pflegen. Die UUID ist nur lesbar.
- **Fahrgemeinschaften:** vorgemerkte und veröffentlichte Einträge einsehen und dauerhaft löschen. Es gibt bewusst kein Bearbeitungsformular: was jemand anderes veröffentlicht hat, wird nicht nachträglich umgeschrieben.
- **Statistik:** aggregierte Formular- und Bot-Signale ohne personenbezogene Einzelangaben.

## Datenspeicherung

Es gibt keine eigenen WordPress-Beitragstypen. Die Daten stehen in zwei eigenen Tabellen, `{prefix}fg_events` und `{prefix}fg_rides`, die das Plugin beim Aktivieren anlegt. Die Teilnehmer-E-Mails eines Arbeitsdienstes liegen als Textspalte mit einer Adresse je Zeile in derselben Zeile. Damit kann es für diese Daten weder Permalinks noch Revisionen, Entwürfe, Papierkorb, REST-API oder Beitrags-Versionen geben.

Bestätigungs- und Löschtoken werden als zufällige Werte erzeugt und nur als SHA-256-Hash gespeichert. Jeder Token wird über ein bedingtes `UPDATE` genau einmal verbraucht.

Das Plugin bringt keine eigene Berechtigung mit und verändert keine Rolle. Für das Ansehen und Bearbeiten genügt `edit_posts`, für das endgültige Löschen `delete_posts`. Damit sehen Autoren und Redakteure im Verzeichnis der Fahrgemeinschaften auch alle Kontaktadressen.

**Achtung beim Deinstallieren:** Das Deinstallieren des Plugins löscht beide Tabellen samt aller Arbeitsdienste, Fahrgemeinschaften und Teilnehmerlisten endgültig. Vor einer Deinstallation die Daten aus dem Adminbereich exportieren.

## Technische Voraussetzungen

- WordPress 6.4 oder neuer und PHP 7.4 oder neuer.
- Eine erreichbare HTTPS-Installation. Ist die Website-Adresse mit `https://` konfiguriert, wird die öffentliche Shortcode-Seite und jede Bestätigungs-/Löschseite bei HTTP auf HTTPS umgeleitet, das Formular wird über HTTP nicht ausgegeben und eine Formular-Mutation über HTTP abgewiesen.
- Ohne TLS im Betrieb (etwa in einer lokalen Entwicklungsinstallation) gibt es keine sichere Seitenvariante, auf die verwiesen werden könnte. Das Plugin leitet dann nicht um, zeigt das Formular und verarbeitet die Anfrage, wie sie ankommt, und weist im Adminbereich auf den fehlenden HTTPS-Betrieb hin.
- Ein SMTP-Plugin muss die Zustellung über `wp_mail()` sicherstellen. Das Plugin setzt für alle eigenen Nachrichten den Inhaltstyp `text/plain` und verwendet die Website-Administratoradresse als kontrollierten Absender.
- Das Plugin bringt kein eigenes Mail-Plugin mit und registriert keinen eigenen `pre_wp_mail`-Filter. Es ist mit jedem Transport kompatibel, der `wp_mail()` abfängt. Ein SMTP-Plugin darf den Absender nicht hart überschreiben: Der Filter `wp_mail_from` liefert die Administratoradresse, damit SPF und DMARC der eigenen Domain greifen. Erzwingt das Mail-Plugin einen eigenen Absender, muss dieser beim Mailanbieter freigegeben sein.
- Die WordPress-Zeitzone wird für Datum, optionale Uhrzeit, Ablauf und Statistik verwendet. Ohne Uhrzeit bleibt ein Arbeitsdienst bis 23:59 Uhr des angegebenen Tages aktiv.

## Bedienung und Datenfluss

Das Plugin-Menü **Fahrgemeinschaften** enthält drei Unterseiten in dieser Reihenfolge:
**Arbeitsdienste**, **Fahrgemeinschaften**, **Statistik**. Ein Klick auf den Namen
"Fahrgemeinschaften" führt zur ersten Unterseite, WordPress verlinkt den
Obermenüpunkt immer auf den ersten Eintrag der Liste.

1. Im Plugin-Menü **Fahrgemeinschaften → Arbeitsdienste** einen Dienst mit Titel, Datum, optionaler Uhrzeit, Teilnehmer-E-Mails und der Option „Öffentlich sichtbar“ anlegen.
2. Auf einer WordPress-Seite `[fahrgemeinschaften]` einfügen.
3. Eine öffentliche Eintragung wird mit Status `pending` gespeichert. Die Bestätigungs-E-Mail enthält eine Vorschau sowie getrennte Links zum Bestätigen und Verwerfen. Erst die POST-Bestätigung schaltet den Eintrag auf `published`.
4. Nach der Veröffentlichung wird ein neuer Löschlink per E-Mail versendet. Der Link ist bis mindestens 30 Tage nach dem Ende des Arbeitsdienstes gültig. Vormerkungen und ihre Token werden nach 48 Stunden automatisch entfernt.
5. Kontaktanfragen werden nur bei einer für genau diesen Arbeitsdienst hinterlegten Adresse weitergeleitet. Die öffentliche Antwort bleibt bei gültiger und ungültiger Adresse gleich.

Öffentliche Formulare enthalten keine freienbeschreibenden Felder und keine Platzanzahl. Die öffentliche Bezeichnung und der Abfahrtsbereich werden als Textfelder mit Längenbegrenzung geprüft. Die E-Mail-Adresse bleibt ausschließlich im geschützten Admin-/E-Mail-Verkehr.

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
- die öffentliche HTML-Ausgabe enthält keine E-Mail-Adresse, UUID oder interne Datensatz-ID.

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
- **Fahrgemeinschaften sind im Admin nur lesbar und löschbar.** Der Eintrag kommt aus dem öffentlichen Formular und wird von der Person selbst bestätigt oder zurückgezogen. Ein Bearbeitungsformular würde bedeuten, dass eine Administratorin den Text eines anderen Menschen umschreiben kann; das ist bewusst nicht vorgesehen. Unvollständige Datensätze fallen dadurch aus der öffentlichen Liste, statt repariert zu werden.
- **Sichtbarkeit statt Entwurfszustand.** Ein Arbeitsdienst hat nur ein Kästchen „Öffentlich sichtbar“ und ein Datum. Ein Entwurf mit kaputtem Datum, der sich später nicht reparieren lässt, ist damit nicht möglich. Der Preis: es gibt keinen Entwurf, der schon vor dem Datum gepflegt werden kann — ein Arbeitsdienst ohne Datum existiert nicht.
- **Kein Freitextfeld und keine Platzanzahl.** Das Formular fragt nur Art, Arbeitsdienst, öffentliche Bezeichnung, Abfahrtsbereich, E-Mail-Adresse und Einwilligung ab. Damit kann niemand versehentlich Namen, Adressen oder Telefonnummern in einen öffentlich sichtbaren Text schreiben. Zusätzlich weist die Serverseite E-Mail-Adressen, Telefonnummern und „Straße + Hausnummer“ in Bezeichnung und Bereich ab; der Zähler `publish_personal_data` weist auf solche Versuche hin.
- **Die UUID wird automatisch erzeugt.** Die Arbeitsdienst-UUID entsteht einmalig beim Anlegen und ist danach nur lesbar. Von Hand eingetragene UUIDs sind eine vermeidbare Fehlerquelle, weil die Kennung in Bestätigungs-Mails und Statistik wiederkehrt.
- **Neutrale Kontaktantwort.** Unabhängig von der Gültigkeit einer Adresse lautet die öffentliche Antwort immer gleich: „Vielen Dank für deine Anfrage. Wir informieren den Ersteller, sofern die angegebene E-Mail-Adresse für den gewählten Arbeitsdienst hinterlegt ist.“
- **Öffentliche Referenz statt Datensatz-ID.** Listen, Formulare und auch der Löschlink aus der E-Mail verwenden durchgängig die zufällige öffentliche Referenz (`public_ref`), nie die interne ID oder die UUID. Einzige dokumentierte Ausnahme ist das Feld `source_url`, das die vollständige Seiten-URL des Formulars enthält und damit die WordPress-Seiten-ID transportieren kann.
- **Serverseitige Verträge.** `confirmed_at` kennzeichnet bestätigt veröffentlichte Fahrgemeinschaften. Die öffentliche Liste verlangt diesen Zeitstempel zusätzlich zu Status `published`, gültigem Modus, Abfahrtsbereich, Kontaktadresse und öffentlicher Referenz.
- **HTTPS-Erzwingung nur bei vorhandenem HTTPS.** Eine Weiterleitung ist nur sinnvoll, wenn die Zieladresse existiert. Ist die Website-Adresse nicht mit `https://` konfiguriert, bleibt die Anfrage unverändert bearbeitbar und der fehlende TLS-Betrieb wird im Adminbereich auf den Plugin-Seiten gemeldet, statt die öffentliche Seite durch eine nicht beantwortbare Adresse unbrauchbar zu machen.
- **Keine Migration.** Aus einer älteren, auf eigenen Beitragstypen basierenden Fassung wird nichts übernommen. Die alten Datensätze sind vor dem Umstieg zu löschen, die alten Rollenberechtigungen (`manage_fahrgemeinschaften`, `edit_fahrgemeinschaften` und Verwandte) bleiben zurück, weil das Plugin sie nicht kennt und nicht entfernt; sie lassen sich in der Rollenverwaltung oder über `WP_Role::remove_cap()` zurückziehen.
- **Statistik ohne Sperre.** Die Tageszähler werden gelesen, erhöht und zurückgeschrieben, ohne Sperre. Bei sehr gleichzeitigen Abläufen kann dadurch ein einzelner Zählerstand verloren gehen. Betroffen sind ausschließlich aggregierte Tageswerte ohne Personenbezug; für die Minimalversion wurde darauf bewusst verzichtet.
- **Offener Nebenläufigkeitsfall.** Klickt jemand „bestätigen“ und „verwerfen“ gleichzeitig, kann ein Token-Paar einen einzelnen Aufruf überleben. Der Bestätigungspfad ist der sicherere, weil er den Veröffentlichungszustand prüft, während der Verwerfpfad den Eintrag vollständig entfernt.
