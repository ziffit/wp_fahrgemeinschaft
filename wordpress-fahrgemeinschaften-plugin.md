# Plan: WordPress-Plugin „Fahrgemeinschaften“

> **Hinweis (26.09.2026):** Dieses Dokument ist der ursprüngliche Auftrag und bleibt als
> Spezifikation stehen. Die Speicheranforderung aus Abschnitt 1 („zwei nicht öffentliche
> Custom Post Types“) ist in der Umsetzung durch zwei eigene Datenbanktabellen
> (`{prefix}fg_events`, `{prefix}fg_rides`) ersetzt worden; die Gründe, die betroffenen
> Meta-Schlüssel und die abweichenden Entscheidungen stehen in
> `~/.opencode/plan/fahrgemeinschaften-eigene-tabellen.md` und in
> `fahrgemeinschaften/README.md` unter „Bewusste Abweichungen und Entscheidungen“.

## Ziel

Ein schlankes WordPress-Plugin für Vereinsmitglieder, das öffentlich sichtbare Angebote und Suchwünsche für kommende Arbeitsdienste sowie eine sichere Kontaktvermittlung per E-Mail ermöglicht.

## Festgelegte Entscheidungen

- Keine WordPress-Anmeldung für die öffentliche Nutzung.
- Gültige Teilnehmeradressen werden vorab je Arbeitsdienst durch Administratoren eingetragen.
- Eine Veröffentlichung wird nur nach Bestätigung des E-Mail-Links öffentlich angezeigt.
- Die Kontaktaufnahme benötigt keine E-Mail-Bestätigung.
- Keine Mengenbegrenzung und keine automatische Bot-Sperre.
- Keine öffentliche Anzeige von E-Mail-Adressen, internen IDs, UUIDs oder Lösch-Tokens.
- Keine vollständigen Namen, exakten Adressen, Telefonnummern oder sonstigen privaten Angaben in öffentlichen Feldern.
- Keine Speicherung von Kontaktverläufen oder personenbezogenen IP-Adressen.

## Minimale technische Umsetzung

### 1. Plugin-Grundstruktur

Ein einzelnes Custom Plugin ohne externe Mitgliederverwaltungs- oder Formular-Abhängigkeit:

- öffentliche Ausgabe über einen WordPress-Block oder Shortcode,
- serverseitige Formularverarbeitung,
- WordPress-native E-Mail-Verarbeitung mit `wp_mail()`,
- SMTP als Betriebsvoraussetzung,
- eigene Admin-Menüs für Arbeitsdienste und Fahrgemeinschaften.

Für die einfache Umsetzung werden zwei nicht öffentliche Custom Post Types verwendet:

1. `Arbeitsdienst`
   - Name
   - Datum und optional Uhrzeit
   - vorab eingetragene Teilnehmer-E-Mails
   - nur im Admin sichtbare UUID
   - Revisionen und REST-API-Veröffentlichung deaktiviert

2. `Fahrgemeinschaft`
   - übergeordneter Arbeitsdienst
   - Status `pending` bis zur Bestätigung, danach `publish`
   - Angebot/Suche
   - Vorname oder Spitzname
   - Abfahrtsbereich
   - kontakt-E-Mail des Erstellers
   - Revisionen und REST-API-Veröffentlichung deaktiviert

Beim Löschen eines Arbeitsdienstes werden automatisch alle zugehörigen Fahrgemeinschaften und Tokens gelöscht.

### 2. Öffentliche Seite

Die öffentliche Seite zeigt ausschließlich veröffentlichte Fahrgemeinschaften zu Arbeitsdiensten, die noch nicht beendet sind. Oben steht die Liste, darunter das Formular, darüber ein Sprunglink „Eintrag anlegen“. Nach dem Absenden eines Formulars führt die Rückkehr zur Meldung, damit Erfolg oder Fehler ohne Suchen lesbar sind.

Anzeige:

- Art: „Ich biete“ oder „Ich suche“
- Vorname oder Spitzname
- Arbeitsdienst und Datum
- grober Abfahrtsbereich
- Schaltfläche „Kontaktieren“; das darunterliegende Kontaktformular erscheint erst auf Wunsch und wird über „Absenden“ gesendet

Nicht öffentlich:

- E-Mail-Adresse
- interne Datensatz-ID
- Arbeitsdienst-UUID
- Bestätigungs- oder Lösch-Token

Vergangene Arbeitsdienste werden sofort anhand von Datum/Uhrzeit und Zeitzone ausgeblendet. Wenn nur ein Datum angegeben ist, gilt der Arbeitstag bis Tagesende.

### 3. Veröffentlichung mit E-Mail-Bestätigung

Ablauf:

1. Pflichtfelder, Höchstlängen, E-Mail-Format und Datumszustand prüfen.
2. Prüfen, ob die E-Mail dem gewählten Arbeitsdienst zugeordnet ist.
3. Unangehakte Einwilligungs-Checkbox verlangen.
4. Einfachen Honeypot gegen sehr einfache Bots einsetzen.
5. Eintrag als `pending` speichern, aber nicht veröffentlichen.
6. Zwei zufällige, getrennte Tokens erzeugen und nur deren Hash speichern:
   - Bestätigung der Veröffentlichung,
   - Löschen der Vormerkung.
7. Bestätigungs-E-Mail mit Vorschau der öffentlichen Daten versenden.
8. Nach Bestätigung den Eintrag auf `publish` setzen.
9. Einen neuen, separaten Lösch-Token für den veröffentlichten Eintrag erzeugen und die Erfolgs-E-Mail inklusive Lösch-Link versenden.

Die E-Mail enthält:

- öffentliche Vorschau,
- Hinweis, keine privaten Informationen zu veröffentlichen,
- Link „Veröffentlichung bestätigen“,
- Link „Eintrag löschen und nicht veröffentlichen“.

Alle Links öffnen zunächst eine Handhabungsseite; die eigentliche Aktion wird erst per Button und `POST` ausgeführt. Dadurch lösen E-Mail-Sicherheitsprogramme keine Aktion automatisch aus. Bestätigungs- und Vormerkungs-Links laufen beispielsweise nach 48 Stunden ab.

### 4. Kontaktaufnahme ohne Bestätigung

1. E-Mail-Adresse prüfen und normalisieren.
2. Mitgliedschaft genau in diesem Arbeitsdienst serverseitig prüfen.
3. Der öffentlichen Formularseite immer dieselbe neutrale Antwort ausgeben.
4. Nur bei gültiger Adresse:
   - den Ersteller per E-Mail informieren,
   - dem Anfragenden eine Bestätigung senden.
5. Bei ungültiger Adresse keine E-Mail versenden und keinen öffentlichen Hinweis auf die Gültigkeit geben.

Neutrale Antwort:

> Vielen Dank für deine Anfrage. Wir informieren den Ersteller, sofern die angegebene E-Mail-Adresse für den gewählten Arbeitsdienst hinterlegt ist.

Zusätzliche Bot-Signale wie ein auffällig kurzer Absendezeitpunkt dürfen statistisch erfasst, aber nicht als Mengenlimit verwendet werden.

### 5. Einfache Missbrauchsstatistik

Keine vollständige Benutzerhistorie. Nur tägliche aggregierte Zähler, beispielsweise:

- Veröffentlichungsformulare gesamt,
- gültige/ungültige E-Mail-Adressen,
- vorgemerkte Einträge,
- bestätigte Veröffentlichungen,
- Kontaktversuche gesamt,
- gültige/ungültige Kontaktadressen,
- zugestellte E-Mail-Versuche,
- Honeypot- und auffällige Schnellabsendungen.

Speicherung ohne E-Mail-Adressen, Namen, Labels, IP-Adressen oder Rohformulare. Auswertung im Adminbereich für Today, 7 Tage und 30 Tage; older Tageswerte können nach 90 Tagen gelöscht werden.

### 6. E-Mails

- E-Mails als `multipart/alternative` mit festem Absender auf der Vereinsdomain: der Text als Plain-Text in der ersten Alternative, das Layout als HTML in der zweiten.
- Das Layout, das Logo und die Fußzeile stehen in jeder Mail des Plugins. Das Layout wird im Plugin mitgeliefert und ist nicht über den Adminbereich änderbar; konfigurierbar sind nur Logo und Fußzeile.
- Keine aus Benutzereingaben erzeugten HTML-Inhalte oder Mailheader: Benutzereingaben stehen im HTML-Teil ausschließlich escaped.
- `Reply-To` für die Kontakt-E-Mail verwenden.
- SMTP-Zustellung einrichten und SPF/DKIM/DMARC prüfen.
- Mailversandfehler nur aggregiert zählen; keine vollständigen Mailinhalte dauerhaft protokollieren.

## Datenschutz und Sicherheit

- E-Mail-Adressen und Token niemals in öffentliches HTML, REST-Antworten oder öffentliche JavaScript-Daten ausgeben.
- Token mit mindestens 256 Zufallsbits erzeugen, nur gehasht speichern und zeitlich begrenzen.
- Token-Seiten mit `Cache-Control: no-store` und `Referrer-Policy: no-referrer` ausgeben.
- HTTPS voraussetzen.
- Prepared Statements für alle Datenbankabfragen verwenden.
- Eingaben validieren; Ausgaben mit passenden WordPress-Escaping-Funktionen maskieren.
- Adminaktionen mit Capabilities und Nonces schützen.
- Für öffentliche Formulare zusätzlich Honeypot und Statistik verwenden, aber keine Rate Limits.
- Datenschutzerklärung um öffentliche Angaben, E-Mail-Verarbeitung, Kontaktvermittlung, Speicherdauer und Missbrauchsstatistik ergänzen.
- Keine Trackingdienste oder externen Ressourcen auf Bestätigungs- und Löschseiten laden.

## Adminbereich

### Arbeitsdienste

- Liste kommender und vergangener Dienste
- Anlegen, Ändern und Löschen
- Teilnehmer-E-Mails pflegen
- Datum/Uhrzeit und schreibgeschützte UUID anzeigen
- vor Löschung die Anzahl betroffener Fahrgemeinschaften anzeigen

### Fahrgemeinschaften

- alle vorgemerkten und veröffentlichten Einträge
- Filter nach Arbeitsdienst und Status
- öffentliche Felder und kontakt-E-Mail für berechtigte Administratoren
- Ändern und Löschen
- keine Tokens im Admin anzeigen

## Abnahmekriterien

- Nur bestätigte Einträge sind öffentlich sichtbar.
- E-Mail-Adressen, UUIDs, interne IDs und Tokens sind im öffentlichen Quellcode nicht vorhanden.
- Ungültige oder fremde E-Mail-Adressen können keinen Eintrag veröffentlichen.
- Bestätigungs- und Löschen-Links funktionieren einmalig und laufen korrekt ab.
- Eine Vormerkung kann gelöscht und anschließend neu erstellt werden.
- Der Kontakt erzeugt unabhängig von der Adressgültigkeit dieselbe öffentliche Antwort.
- Nur gültige Teilnehmeradressen lösen Kontakt-E-Mails aus.
- Vergangene Arbeitsdienste verschwinden automatisch aus der öffentlichen Anzeige.
- Das Löschen eines Arbeitsdienstes löscht auch alle zugehörigen Fahrgemeinschaften.
- Formulareingaben können weder HTML-/JavaScript-Injection noch SQL-Injection auslösen. Im HTML-Teil der E-Mails werden sie escaped eingesetzt.
- Die Statistik enthält keine personenbezogenen Einzelangaben.

## Bewusst nicht Bestandteil der Minimalversion

- WordPress-Anmeldung für Mitglieder
- E-Mail-Bestätigung für Kontaktanfragen
- Rate Limits oder CAPTCHA
- öffentliche Bearbeitung von Fahrgemeinschaften
- Telefonnummern oder direkte öffentliche Kontakt-Links
- detaillierte Nutzerhistorie oder externe Analyse
- separate Benutzeroberfläche außerhalb von WordPress

## Umsetzungsstand: Ergänzungen gegenüber dem Plan

Die Umsetzung folgt dem Plan, ergänzt ihn aber an den folgenden Stellen. Die ausführliche Begründung steht in `fahrgemeinschaften/README.md` unter „Bewusste Abweichungen und Entscheidungen“.

### Eigene Beitragstypen

- Die UUID wird beim Anlegen einmalig erzeugt (`_fg_event_uuid`) und danach nur noch angezeigt. Sie dient als Schlüssel in Bestätigungs-Mails und Statistik; ein fehlender oder doppelter Wert wäre dort sonst schwer zu erkennen.
- Der WordPress-Status `future` wird neben `publish` als „aktiv“ gewertet, damit ein geplanter Arbeitsdienst auf der öffentlichen Seite erscheint.
- `_fg_confirmed_at` ist die verbindliche Veröffentlichungsmarke. Sie entsteht bei der E-Mail-Bestätigung und zusätzlich dann, wenn ein Administrator einen Eintrag direkt veröffentlicht. Die öffentliche Liste zeigt nur Einträge mit dieser Marke und mit gültigem Modus, Herkunft, Kontaktadresse und öffentlicher Referenz.
- `_fg_admin_fields_initialized` schützt serverseitig gesetzte Felder vor einer späteren Überschreibung durch das öffentliche Formular.
- Ungültige Daten werden nicht stillschweigend übernommen: ein ungültiges Datum setzt den Arbeitsdienst auf Entwurf, ein unvollständiger Eintrag wird nicht veröffentlicht. Mehrere Hinweise pro Speichervorgang werden als Stapel (maximal fünf) ausgegeben.
- Der Papierkorb ist für beide Beitragstypen gesperrt (`pre_trash_post`). Beide Datensätze werden ausschließlich endgültig gelöscht, damit keine unbeteiligten Fristen und Token zurückbleiben. Der Link „Endgültig löschen“ steht in der Listenansicht und im Bearbeitungsformular.

### Öffentliche Seite und Formulare

- Das Formular enthält kein Freitextfeld für eine Beschreibung und keine Platzanzahl, sondern nur Art, Arbeitsdienst, Vorname oder Spitzname, Abfahrtsbereich, E-Mail-Adresse und Einwilligung.
- Die Serverseite weist zusätzlich personenbezogene Angaben in den öffentlich sichtbaren Feldern ab: E-Mail-Adressen, Telefonnummern und „Straße + Hausnummer“. Solche Versuche landen in der neutralen Antwort `not_created` und im Zähler `publish_personal_data`.
- Öffentliche Formulare nutzen WordPress-Nonces. Die langlebigen Token-Seiten nutzen stattdessen eine eigene Formularprüfung, die per HMAC mit `wp_salt()` aus Token und Aktion abgeleitet wird. Damit hängt die Prüfung am geheimen Token und nicht an einer Sitzung.

### HTTP-Verhalten und Fehlerfälle

- Alle drei öffentlichen Mutationen verlangen `POST`. Alle anderen Methoden beantworten WordPress mit Status 405 und `Allow: POST`, ohne etwas zu verändern.
- Die HTTPS-Erzwingung setzt voraus, dass die Website überhaupt mit `https://` konfiguriert ist (`FG_Security::site_uses_https()`). Nur dann werden öffentliche Seiten umgeleitet, das Formular gesperrt und Mutationen abgewiesen. Auf einer Installation ohne TLS gibt es keine erreichbare HTTPS-Variante; eine erzwungene Umleitung dorthin erzeugt im Browser einen Protokollfehler und macht die Seite unbenutzbar. Ohne TLS wird die Anfrage deshalb normal bearbeitet und der Adminbereich weist auf den fehlenden HTTPS-Betrieb hin.
- Ein abgelaufenes oder manipuliertes Formular-Token führt zu `form_expired`, ohne eine Aktion auszuführen.
- Kann die Vormerkungs-E-Mail nicht zugestellt werden, wird der eben angelegte Eintrag wieder gelöscht (`email_failed`).
- Kann die E-Mail mit dem Löschlink nach der Bestätigung nicht zugestellt werden, wird die Veröffentlichung zurückgenommen; die Vormerkungs-Token bleiben gültig, damit derselbe Bestätigungslink erneut funktioniert (`publish_failed`).
- Die Bestätigungs-Mails enthalten weiterhin wörtlich „Ist der Arbeitsdienst vorbei, wird dein Eintrag automatisch aus der öffentlichen Anzeige entfernt.“, die Antwortmail an den Anfragenden „Wir haben den Ersteller der Fahrgemeinschaft benachrichtigt.“

### Betrieb

- Die tägliche Bereinigung hängt an WP-Cron (`fg_daily_cleanup`). Weil WP-Cron nur bei Traffic läuft, führt der Adminbereich dieselbe Bereinigung als Rückfallebene einmal täglich aus; die Marker-Option `fg_last_cleanup` begrenzt das auf einen Lauf pro Tag.
- Die Statistik schreibt ihre Tagesaggregate ohne Sperre zurück. Bei sehr gleichzeitigen Abläufen kann ein Zählerstand verloren gehen; betroffen sind nur aggregierte Werte ohne Personenbezug.
- Die Deinstallation behält Arbeitsdienste und Fahrgemeinschaften, entfernt aber Statistik, Marker-Option und die vergebenen Capabilities.
