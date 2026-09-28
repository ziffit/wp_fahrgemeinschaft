# Plan: WordPress-Plugin „Fahrgemeinschaften“

> **Hinweis (26.09.2026):** Dieses Dokument ist der ursprüngliche Auftrag und bleibt als
> Spezifikation stehen. Die Speicheranforderung aus Abschnitt 1 („zwei nicht öffentliche
> Custom Post Types“) ist in der Umsetzung durch zwei eigene Datenbanktabellen
> (`{prefix}fg_events`, `{prefix}fg_rides`) ersetzt worden; die Gründe, die betroffenen
> Meta-Schlüssel und die abweichenden Entscheidungen stehen in
> `~/.opencode/plan/fahrgemeinschaften-eigene-tabellen.md` und in
> `arbeitsdienste/README.md` unter „Bewusste Abweichungen und Entscheidungen“.
>
> **Hinweis (27.09.2026):** Der Auftrag ist um die Mitgliederverwaltung erweitert worden
> (Plugin 1.9.0, Schema 1.2.0). Damit fällt die Entscheidung „gültige Teilnehmeradressen
> werden vorab je Arbeitsdienst durch Administratoren eingetragen“ weg: Die Teilnehmer
> tragen sich selbst ein, und die Adressen liegen in einer Mitgliedertabelle statt in
> einer Textspalte am Arbeitsdienst. Zwei weitere Tabellen kommen dazu
> (`{prefix}fg_members`, `{prefix}fg_event_members`), der Arbeitsdienst bekommt einen
> Bedarf und damit eine Zahl freier Plätze, und der Kontaktweg verlangt zusätzlich die
> Anmeldung für genau diesen Dienst. Die betroffenen Stellen sind an Ort und Stelle als
> überholt markiert; was daraus geworden ist, steht unten unter
> „Umsetzungsstand: Mitgliederverwaltung (1.9.0)“.
>
> **Hinweis (28.09.2026):** Der Auftrag ist ein drittes Mal umgestellt worden
> (Plugin 1.14.0, Schema 1.4.0). Die Mitgliedsnummer ist jetzt der Schlüssel einer
> Fahrgemeinschaft: Das Angebotformular fragt **Nummer und E-Mail-Adresse** ab, und
> beides muss zu einem Mitglied passen, weil die Zuordnung an der Nummer hängt. Die
> Fahrt trägt `member_id`; Bezeichnung (`alias`) und Kontaktadresse
> (`contact_email`) stehen nicht mehr daran. Jede Nachricht zu einer Fahrgemeinschaft
> geht damit an ein Mitglied. Die betroffenen Stellen sind an Ort und Stelle als
> überholt markiert; was daraus geworden ist, steht unten unter
> „Umsetzungsstand: Mitgliedsnummer als Schlüssel (1.14.0)“.
>
> **Hinweis (28.09.2026, zweiter Umschlag desselben Tages):** Der Auftrag ist ein viertes
> Mal umgestellt worden (Plugin 1.15.0, Schema 1.5.0). Der Zustand vor der Veröffentlichung
> ist weg: Eine Fahrt steht in dem Moment öffentlich, in dem das Formular sie abschickt, es
> gibt keine zweite Nachricht zu einer Fahrt und damit auch keine Bestätigungs- und keine
> Verwerf-Adresse. Der Löschlink ist der einzige Link, den die E-Mail trägt. Fahrten, die
> zum Zeitpunkt der Aktualisierung noch vorgemerkt waren, werden von der Migration
> **gelöscht**, nicht veröffentlicht — siehe unten „Umsetzungsstand: Kein vorgemerkter
> Zustand (1.15.0, Schema 1.5.0)“.

> **Hinweis (28.09.2026, dritter Umschlag desselben Tages):** Der Auftrag ist ein fünftes Mal
> umgestellt worden (Plugin 1.16.0, Schema **unverändert** 1.5.0 — diese Fassung ändert keine
> Tabelle). Zwei sichtbare Dinge: Die Anrede aller vier Nachrichten lautet „Hallo Vorname
> Nachname“, und das Kontaktformular eines Eintrags fragt zusätzlich nach der
> Mitgliedsnummer. Damit steht das Paar in allen drei Formularen, es wird überall
> gleich geprüft und überall gleich abgelehnt — siehe unten „Umsetzungsstand: Anrede und
> Kontaktformular (1.16.0)“.

## Ziel

Ein schlankes WordPress-Plugin für Vereinsmitglieder, das öffentlich sichtbare Angebote und Suchwünsche für kommende Arbeitsdienste sowie eine sichere Kontaktvermittlung per E-Mail ermöglicht.

## Festgelegte Entscheidungen

- Keine WordPress-Anmeldung für die öffentliche Nutzung.
- ~~Gültige Teilnehmeradressen werden vorab je Arbeitsdienst durch Administratoren eingetragen.~~ **Überholt seit 1.9.0:** Die Anmeldung macht das Mitglied selbst; siehe „Umsetzungsstand: Mitgliederverwaltung (1.9.0)“.
- ~~Eine Veröffentlichung wird nur nach Bestätigung des E-Mail-Links öffentlich angezeigt.~~ **Überholt seit 1.15.0:** Ein Eintrag wird beim Absenden des Formulars öffentlich, und die E-Mail trägt nur noch den Löschlink. Der Grund ist derselbe wie bei der Anmeldung: Der Verein ist nicht der Arbeitgeber eines Dienstes, und niemand hat jemanden um Erlaubnis gefragt, der eine Fahrgemeinschaft anbietet — es gab also nichts, was bestätigt werden musste.
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
   - ~~Vorname oder Spitzname~~ **Überholt seit 1.14.0:** Der Vorname kommt aus dem
     Mitglied, zu dem die Fahrt gehört.
   - Abfahrtsbereich
   - ~~kontakt-E-Mail des Erstellers~~ **Überholt seit 1.14.0:** An der Fahrt steht
     die Mitgliedsnummer; die Adresse gehört zum Mitglied.
   - Revisionen und REST-API-Veröffentlichung deaktiviert

Beim Löschen eines Arbeitsdienstes werden automatisch alle zugehörigen Fahrgemeinschaften und Tokens gelöscht.

### 2. Öffentliche Seite

Die öffentliche Seite zeigt ausschließlich veröffentlichte Fahrgemeinschaften zu Arbeitsdiensten, die noch nicht beendet sind. Oben steht die Liste, darunter das Formular, darüber ein Sprunglink „Eintrag anlegen“. Nach dem Absenden eines Formulars führt die Rückkehr zur Meldung, damit Erfolg oder Fehler ohne Suchen lesbar sind.

Anzeige:

- Art: „Ich biete“ oder „Ich suche“
- ~~Vorname oder Spitzname~~ **Seit 1.14.0** der Vorname des Mitglieds, zu dem die
  Fahrt gehört, und die Mitgliedsnummer mit Vor- und Nachnamen
- Arbeitsdienst und Datum
- Abfahrtsbereich als Ort oder Stadtteil, im Formular mit drei Beispielen
- Schaltfläche „Kontaktieren“; das darunterliegende Kontaktformular erscheint erst auf Wunsch und wird über „Absenden“ gesendet
- ~~nur die E-Mail-Adresse~~ **Seit 1.16.0** Mitgliedsnummer **und** E-Mail-Adresse nebeneinander
  in einer Zeile, mit einem Hinweis darunter, dass beide zu einem Mitglied passen müssen
  und dass keine der beiden Angaben öffentlich steht
- **Seit 1.17.0** steht der Hinweis jedes der drei Formulare zwischen den beiden Feldern und
  der Schaltfläche, und zwar über die ganze Breite; vorher stand er im Angebotformular in der
  Zelle des E-Mail-Feldes und im Kontaktformular unter der Schaltfläche
- **Seit 1.17.0** sagen alle drei Hinweise wörtlich dasselbe über das Postfach: „Du bekommst
  eine E-Mail als Bestätigung. Prüfe deinen Spam-Ordner, wenn du keine erhältst.“ Der Hinweis
  am Arbeitsdienst trug vorher zusätzlich „Vorname und Nachname tragen wir für dich ein.“,
  und der ist weg

Nicht öffentlich:

- E-Mail-Adresse
- interne Datensatz-ID
- Arbeitsdienst-UUID
- Bestätigungs- oder Lösch-Token

Vergangene Arbeitsdienste werden sofort anhand von Datum/Uhrzeit und Zeitzone ausgeblendet. Wenn nur ein Datum angegeben ist, gilt der Arbeitstag bis Tagesende.

### 3. Veröffentlichung mit E-Mail-Bestätigung

> **Überholt seit 1.15.0 (Plugin 1.15.0, Schema 1.5.0).** Der Ablauf ist der um Zeile 5
> gekürzte: Statt „Eintrag als `pending` speichern, aber nicht veröffentlichen“ steht
> „Eintrag als `published` speichern“. Statt zweier getrennter Tokens gibt es einen, der
> Löschtoken; statt der Bestätigungs-E-Mail und der Erfolgs-E-Mail gibt es eine Nachricht.
> Die Absätze über Vorschau, Löschung der Vormerkung und Laufzeit der
> Vormerkungs-Links gehören zu diesem Zustand und sind mit ihm weggefallen. Alles andere
> in diesem Abschnitt — Pflichtfelder, Einwilligung, Honeypot, Handhabungsseite vor jeder
> Aktion — gilt unverändert und steht deshalb noch hier.

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
   - ~~den Ersteller~~ das Mitglied, das die Fahrt angeboten hat, per E-Mail
     informieren,
   - dem Anfragenden eine Bestätigung senden.
5. Bei ungültiger Adresse keine E-Mail versenden und keinen öffentlichen Hinweis auf die Gültigkeit geben.

Neutrale Antwort:

> Vielen Dank für deine Anfrage. Wir informieren ~~den Ersteller~~ das Mitglied, das die Fahrgemeinschaft angeboten hat, sofern die angegebene E-Mail-Adresse für den gewählten Arbeitsdienst hinterlegt ist.

> (Wortlaut seit 1.14.0; die Fassung oben ist der ursprüngliche Auftrag und bleibt
> stehen.)

Zusätzliche Bot-Signale wie ein auffällig kurzer Absendezeitpunkt dürfen statistisch erfasst, aber nicht als Mengenlimit verwendet werden.

### 5. Einfache Missbrauchsstatistik

Keine vollständige Benutzerhistorie. Nur tägliche aggregierte Zähler, beispielsweise:

- Veröffentlichungsformulare gesamt,
- gültige/ungültige E-Mail-Adressen,
- ~~vorgemerkte Einträge,~~ **Seit 1.15.0:** veröffentlichte Einträge — `publish_published`
- ~~bestätigte Veröffentlichungen,~~ **Seit 1.15.0:** gelöschte Einträge — `publish_deleted`,
- Kontaktversuche gesamt,
- gültige/ungültige Kontaktadressen,
- zugestellte E-Mail-Versuche,
- Honeypot- und auffällige Schnellabsendungen.

Speicherung ohne E-Mail-Adressen, Namen, Labels, IP-Adressen oder Rohformulare. Auswertung im Adminbereich für Today, 7 Tage und 30 Tage; older Tageswerte können nach 90 Tagen gelöscht werden.

### 6. E-Mails

- E-Mails als `multipart/alternative` mit festem Absender auf der Vereinsdomain: der Text als Plain-Text in der ersten Alternative, das Layout als HTML in der zweiten.
- Das Layout, das Logo und die Fußzeile stehen in jeder Mail des Plugins. Das Layout wird im Plugin mitgeliefert und ist nicht über den Adminbereich änderbar; konfigurierbar sind Logo, Fußzeile und der Wortlaut (Betreff und Text) der vier Nachrichten — der Rahmen um den Wortlaut nicht. Die Fußzeile steht in **beiden** Alternativen, im HTML und im reinen Text — sie nennt Absender, Kontakt und den rechtlichen Hinweis, und genau diese Angaben bleiben als Klartext am längsten hängen. Das Logo steht nur im HTML-Teil: ein Textteil hat keine Bilder, und der `From:`-Header nennt den Verein ohnehin.
- Keine aus Benutzereingaben erzeugten HTML-Inhalte oder Mailheader: Benutzereingaben stehen im HTML-Teil ausschließlich escaped. Für die Links der Nachricht heißt das: Der Wortlaut des Ankers wird wie jeder andere Wert escaped, und er entsteht nicht aus dem Text, sondern aus einem der im Code hinterlegten Vorgabewortlaute oder einem Wortlaut, den `FG_Mail_Templates::mark_links()` gegen genau die Links dieser Nachricht geprüft hat. Die Adresse selbst geht durch `esc_url()`.
- Jeder Link einer Nachricht ist ein `<a href>` im HTML-Teil, und sein Text nennt die Handlung. Bis 1.13.0 stand die Adresse als Klartext im Wortlaut, den `paragraphs()` Zeile für Zeile escaped; im HTML-Teil war sie damit weder klickbar noch kürzbar. Der Text, der an `wp_mail()` geht, ist trotzdem der fertige Textteil, nicht der Wortlaut mit den Marken: `FG_Mailer::send()` übergibt `FG_Mail_Templates::text_part()` an `wp_mail()` und den unaufgelösten Wortlaut an `phpmailer_init`. Filter auf `wp_mail()` — der Protokollierer dieses Plugins, ein Mail-Log, ein Plugin, das seine eigene Fußzeile anhängt — sehen also eine fertige Nachricht.
- Ein Link-Platzhalter darf einen eigenen Wortlaut hinter einem Doppelpunkt mitbringen, `{{Loeschlink:Fahrgemeinschaft löschen}}`. Ohne den Doppelpunkt gilt der Vorgabewortlaut der Nachricht, damit ein vor 1.13.0 gespeicherter Text nicht bricht. Diese Form wird nur für die Links der jeweiligen Nachricht zugelassen.
- Der Wortlaut einer Nachricht ist Platzhaltertext, kein HTML. `FG_Mail_Templates::render()` übersetzt ihn beim Versand, der Rahmen steht im Plugin; was ein Verein an Betreff und Text ändert, kann deshalb kein Markup in die Nachricht bringen. Ein Platzhalter, den die Nachricht nicht kennt, wird beim Speichern abgelehnt (mit Nennung der erlaubten Menge) und beim Senden zurückgehalten — die Nachricht geht nicht halb gefüllt hinaus, der Grund steht im Fehlerprotokoll und als Hinweis auf der Seite E-Mails.
- ~~Namen in E-Mails nur aus der Mitgliederverwaltung und nur der Vorname: gehört die Adresse zu keinem Mitglied, steht dort die vom Absender gewählte Bezeichnung, sonst nichts.~~ **Überholt seit 1.14.0, erneut seit 1.16.0:** Es gibt keine vom Absender gewählte Bezeichnung mehr, seit `1.14.0` kommt der Name aus dem Mitglied, und seit `1.16.0` steht in der Anrede der vollständige Name — „Hallo Vorname Nachname“. Jede Nachricht geht an ein Mitglied, ein Mitglied hat immer Vor- und Nachnamen, und es gibt deshalb keinen Zweig, in dem ein Name fehlen könnte. Der Nachname kommt dabei nur in der Anrede vor; `{{Vorname}}` setzt weiterhin nur den Vornamen, weil die öffentliche Liste den Vornamen zeigt. Die vollständigen Namen der angemeldeten Mitglieder stehen in keinem Tabellenfeld, das ein Formular auslesen kann, und eine Kontakt-E-Mail trägt als `Reply-To` die Adresse des anfragenden Mitglieds.
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

- ~~alle vorgemerkten und veröffentlichten Einträge~~ **Seit 1.15.0:** alle Einträge der
  Liste sind veröffentlicht; der Status hat keinen Leser mehr und die Liste braucht ihn nicht
- ~~Filter nach Arbeitsdienst und Status~~ **Seit 1.15.0:** nur der Filter nach dem
  Arbeitsdienst. Ein Statusfilter, der alle Zeilen behalten und keine unterscheiden würde,
  wäre eine Spalte, die nur aussieht — und eine, die jemand anklickt und sich wundert, warum
  sich nichts geändert hat
- öffentliche Felder, dazu die Mitgliedsnummer des anbietenden Mitglieds; die
  E-Mail-Adresse des Mitglieds nur auf der Detailseite, nicht in der Liste
- Ändern und Löschen
- keine Tokens im Admin anzeigen

## Abnahmekriterien

- ~~Nur bestätigte Einträge sind öffentlich sichtbar.~~ **Seit 1.15.0:** Jeder
  eingetragene Eintrag ist sofort öffentlich sichtbar. Nicht bestätigt wird nichts, weil
  niemand gefragt wurde.
- E-Mail-Adressen, UUIDs, interne IDs und Tokens sind im öffentlichen Quellcode nicht vorhanden.
- ~~Ungültige oder fremde E-Mail-Adressen können keinen Eintrag veröffentlichen.~~
  **Seit 1.14.0 verschärft:** Es genügt nicht, dass eine Adresse zu einem Mitglied
  gehört, es muss auch die Nummer dazugehören — und die E-Mail-Adresse wird an der
  Fahrt überhaupt nicht gespeichert.
- ~~Bestätigungs- und Löschen-Links funktionieren einmalig und laufen korrekt ab.~~
  **Seit 1.15.0:** Der Löschlink funktioniert einmalig und läuft korrekt ab; ein zweiter
  Klick mit demselben Link tut nichts und löscht nichts.
- ~~Eine Vormerkung kann gelöscht und anschließend neu erstellt werden.~~ **Seit 1.15.0
  gegenstandslos:** Es gibt keine Vormerkung. Der entsprechende Fall heißt jetzt: Ein
  gelöschter Eintrag kann jederzeit neu eingetragen werden, und der neue Eintrag hat einen
  eigenen Löschlink.
- Der Kontakt erzeugt unabhängig von der Adressgültigkeit dieselbe öffentliche Antwort.
- ~~Nur gültige Teilnehmeradressen lösen Kontakt-E-Mails aus.~~ **Seit 1.9.0 verschärft:** Es genügt nicht, dass eine Adresse zu einem Mitglied gehört — das Mitglied muss für genau den Arbeitsdienst eingetragen sein, um dessen Eintrag zu kontaktieren. Auf beiden Seiten des Kontakts, Anfragender wie das anbietende Mitglied.
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

Die Umsetzung folgt dem Plan, ergänzt ihn aber an den folgenden Stellen. Die ausführliche Begründung steht in `arbeitsdienste/README.md` unter „Bewusste Abweichungen und Entscheidungen“.

### Eigene Beitragstypen

- Die UUID wird beim Anlegen einmalig erzeugt (`_fg_event_uuid`) und danach nur noch angezeigt. Sie dient als Schlüssel in Bestätigungs-Mails und Statistik; ein fehlender oder doppelter Wert wäre dort sonst schwer zu erkennen.
- Der WordPress-Status `future` wird neben `publish` als „aktiv“ gewertet, damit ein geplanter Arbeitsdienst auf der öffentlichen Seite erscheint.
- `_fg_confirmed_at` ist die verbindliche Veröffentlichungsmarke. Sie entsteht bei der E-Mail-Bestätigung und zusätzlich dann, wenn ein Administrator einen Eintrag direkt veröffentlicht. Die öffentliche Liste zeigt nur Einträge mit dieser Marke und mit gültigem Modus, Herkunft, Kontaktadresse und öffentlicher Referenz.
- `_fg_admin_fields_initialized` schützt serverseitig gesetzte Felder vor einer späteren Überschreibung durch das öffentliche Formular.
- Ungültige Daten werden nicht stillschweigend übernommen: ein ungültiges Datum setzt den Arbeitsdienst auf Entwurf, ein unvollständiger Eintrag wird nicht veröffentlicht. Mehrere Hinweise pro Speichervorgang werden als Stapel (maximal fünf) ausgegeben.
- Der Papierkorb ist für beide Beitragstypen gesperrt (`pre_trash_post`). Beide Datensätze werden ausschließlich endgültig gelöscht, damit keine unbeteiligten Fristen und Token zurückbleiben. Der Link „Endgültig löschen“ steht in der Listenansicht und im Bearbeitungsformular.

### Öffentliche Seite und Formulare

- Das Formular enthält kein Freitextfeld für eine Beschreibung und keine Platzanzahl, sondern nur Art, Arbeitsdienst, Abfahrtsbereich, Mitgliedsnummer, E-Mail-Adresse und Einwilligung. ~~Vorname oder Spitzname~~ **Seit 1.14.0** nicht mehr abgefragt, der Vorname kommt aus dem Mitglied. Die Schaltfläche heißt seit `1.16.0` **Fahrgemeinschaft eintragen**; vorher stand dort „Eintragung vormerken“, was einen Schritt vor dem Eintrag versprach, den es seit `1.15.0` nicht gibt. Der Abfahrtsbereich liegt über die volle Breite, die beiden Felder des Paares teilen sich eine Zeile, und der Hinweis steht als eigener Absatz zwischen den Feldern und der Schaltfläche. **Seit 1.18.0** steht die Einwilligung über den beiden Feldern des Mitglieds und nicht mehr unter ihnen; vorher stand sie am Ende des Formulars.
- Die Serverseite weist zusätzlich personenbezogene Angaben in den öffentlich sichtbaren Feldern ab: E-Mail-Adressen, Telefonnummern und „Straße + Hausnummer“. Solche Versuche landen in der neutralen Antwort `not_created` und im Zähler `publish_personal_data`.
- Öffentliche Formulare nutzen WordPress-Nonces. Die langlebigen Token-Seiten nutzen stattdessen eine eigene Formularprüfung, die per HMAC mit `wp_salt()` aus Token und Aktion abgeleitet wird. Damit hängt die Prüfung am geheimen Token und nicht an einer Sitzung.

### HTTP-Verhalten und Fehlerfälle

- Alle drei öffentlichen Mutationen verlangen `POST`. Alle anderen Methoden beantworten WordPress mit Status 405 und `Allow: POST`, ohne etwas zu verändern.
- Die HTTPS-Erzwingung setzt voraus, dass die Website überhaupt mit `https://` konfiguriert ist (`FG_Security::site_uses_https()`). Nur dann werden öffentliche Seiten umgeleitet, das Formular gesperrt und Mutationen abgewiesen. Auf einer Installation ohne TLS gibt es keine erreichbare HTTPS-Variante; eine erzwungene Umleitung dorthin erzeugt im Browser einen Protokollfehler und macht die Seite unbenutzbar. Ohne TLS wird die Anfrage deshalb normal bearbeitet und der Adminbereich weist auf den fehlenden HTTPS-Betrieb hin.
- Ein abgelaufenes oder manipuliertes Formular-Token führt zu `form_expired`, ohne eine Aktion auszuführen.
- ~~Kann die Vormerkungs-E-Mail nicht zugestellt werden, wird der eben angelegte Eintrag wieder gelöscht (`email_failed`).~~ **Seit 1.15.0:** Die E-Mail des frisch eingetragenen Eintrags geht nicht raus, der Eintrag bleibt stehen und bleibt öffentlich, `mail_send_failed` steigt und `publish_published` steigt nicht. Gelöscht wird nichts mehr: Es gibt keinen Zustand zurückzunehmen, in dem die Zeile unsichtbar gewesen wäre, und der Besitzer verliert durch einen stehengebliebenen Eintrag nichts gegenüber einem gelöschten.
- ~~Kann die E-Mail mit dem Löschlink nach der Bestätigung nicht zugestellt werden, wird die Veröffentlichung zurückgenommen; die Vormerkungs-Token bleiben gültig, damit derselbe Bestätigungslink erneut funktioniert (`publish_failed`).~~ **Seit 1.15.0 entfallen:** Der Pfad gab es nur, weil die Veröffentlichung noch zurücknehmbar war.
- Die E-Mail zur eingetragenen Fahrgemeinschaft enthält weiterhin wörtlich „Ist der Arbeitsdienst vorbei, wird dein Eintrag automatisch aus der öffentlichen Anzeige entfernt.“, die Antwortmail an den Anfragenden ~~„Wir haben den Ersteller der Fahrgemeinschaft
  benachrichtigt.“~~ **Seit 1.14.0 überholt:** Sie lautet „Wir haben das Mitglied
  benachrichtigt, das die Fahrgemeinschaft angeboten hat.“

### Betrieb

- Die tägliche Bereinigung hängt an WP-Cron (`fg_daily_cleanup`). Weil WP-Cron nur bei Traffic läuft, führt der Adminbereich dieselbe Bereinigung als Rückfallebene einmal täglich aus; die Marker-Option `fg_last_cleanup` begrenzt das auf einen Lauf pro Tag.
- Die Statistik schreibt ihre Tagesaggregate ohne Sperre zurück. Bei sehr gleichzeitigen Abläufen kann ein Zählerstand verloren gehen; betroffen sind nur aggregierte Werte ohne Personenbezug.
- ~~Die Deinstallation behält Arbeitsdienste und Fahrgemeinschaften, entfernt aber Statistik, Marker-Option und die vergebenen Capabilities.~~ **Seit 1.9.0 überholt:** Sie entfernt alle vier Tabellen und damit Arbeitsdienste, Fahrgemeinschaften, Mitglieder und Anmeldungen vollständig, dazu die Optionen. Vor dem Deinstallieren exportieren.

## Umsetzungsstand: Mitgliederverwaltung (1.9.0)

Ergänzt um das, was der Auftrag am 27.09.2026 verlangt hat: Das Mitglied trägt sich für
einen Arbeitsdienst selbst ein, und der Verein verwaltet den Mitgliederkreis. Schema `1.2.0`.
Die ausführliche Begründung steht in `arbeitsdienste/README.md` unter „Bewusste
Abweichungen und Entscheidungen“, die Prüfungen in `PRUEFUMGEBUNG.md`.

### Tabellen

- `{prefix}fg_members` — der Mitgliedskreis: `member_no` (Text, eindeutig, ohne Beachtung
  der Großschreibung), `email` (eindeutig), `first_name`, `last_name`, `created_at`,
  `updated_at`.
- `{prefix}fg_event_members` — die Anmeldung: `event_id`, `member_id`, `registered_at`,
  `unregister_hash`, `unregister_expires`, `public_ref`, `source_url`; je Dienst und
  Mitglied genau eine Zeile (`UNIQUE (event_id, member_id)`).
- Die Spalte `participants` am Arbeitsdienst bleibt stehen und ist ab `1.2.0` leer. Der
  Inhalt wird **nicht** übernommen: Eine E-Mail-Adresse allein sagt nicht, wer das Mitglied
  ist, und die neue Anmeldung verlangt Nummer und Adresse zusammen. Wer die alten Listen
  behalten will, sichert sie vorher und pflegt die Nummern nach — der Import nimmt sie ab
  dem zweiten Lauf wieder auf.

### Anmeldung durch das Mitglied

- Der Arbeitsdienst bekommt **Bedarf** (`demand`, vorzeichenlose Ganzzahl, 0 = nicht
  angegeben) und damit **freie Plätze** = `max(0, Bedarf − Anmeldungen)`. Die Zahl steht in
  der Listenzeile „Verfügbare freie Plätze“; bei 0 ist die Zeile da und trägt den Satz
  „kein freier Platz“, und die Schaltfläche „Eintragen“ führt zum geschlossenen Zustand.
- Das Formular verlangt **Mitgliedsnummer und E-Mail-Adresse** und bestätigt mit
  „verbindlich anmelden“. Es gibt keinen vorgemerkten Zustand: Die E-Mail ist die
  Bestätigung. Sie trägt den Abmeldelink und **keinen** Namen — auf der Dienstseite steht
  die Zahl der freien Plätze, nicht wer sie belegt.
- Die Nummer ist Text, nicht Zahl: `0042` und `42` können zwei Mitglieder sein.
- Ein glücklicher Fund genügt nicht: Nummer **und** passende Adresse müssen zusammen
  gehören. Die Fehlermeldung sagt nicht, welche der beiden Hälften falsch war, weil die
  Seite öffentlich ist und ein Hinweis einem Vorbeigehenden sagen würde, ob eine
  geratene Nummer existiert.
- Bei 0 freien Plätzen ist eine Anmeldung unmöglich, und bei einem Bedarf von 0 auch. Die
  beiden Fälle tragen **verschiedene** Sätze, weil sie verschiedene Ursachen haben.
- Der Abmeldelink öffnet erst eine Seite und löscht erst beim zweiten Klick, mit eigenem
  Formular und Nonce. Ein Link, der beim ersten Abruf löscht, wird von jedem Mailscanner
  ausgeführt.

### Import

- Schlüssel ist die Mitgliedsnummer: unbekannt → anlegen, bekannt → Namen und Adresse
  aktualisieren, wenn sie sich unterscheiden. Sonst nichts anfassen, `updated_at` bleibt
  stehen.
- Die Kopfzeile ist Pflicht, die vier Spalten werden ohne Beachtung der Großschreibung
  über eine Liste von Alternativen erkannt, und fehlt eine, bricht der Import ab und
  nennt die fehlende Spalte. Nach Position zuzuordnen wäre bei einer unbekannten Datei
  ein Zufall, der beim nächsten Export aufhört.
- Die Datei wird **ganz** geprüft, bevor irgendetwas geschrieben wird; die Meldung nennt
  Zeilennummer und Art des Fehlers. Ein halb importierter Mitgliederbestand sieht gepflegt
  aus und ist es nicht.
- Der Bericht beantwortet die drei Fragen des Vereins: was ist neu, was hat sich geändert,
  und wer steht in der Datenbank, aber nicht in der Datei. Ein Import löscht nie jemanden —
  die Datei ist ein Export, und ein Export ist die Sicht von heute.
- Die Spaltenzuordnung ist eine Vermutung über fremde Exportformate. Sie ist an genau einer
  Stelle zu berichtigen (`$header_aliases` in `class-fg-member-import.php`), sobald der
  echte Export des Vereins vorliegt.
- Die Importseite nennt die Namen, die die Kopfzeile haben darf, und zwar aus derselben
  Liste, die der Import liest (`FG_Member_Import::accepted_columns()`). Ein abgeschriebener
  Absatz auf dem Bildschirm wäre die Liste, die altert: Der Verein schreibt einen Namen
  hin, den ihm die Seite versprochen hat, und wird mit „Spalte fehlt" abgewiesen, ohne zu
  erfahren, welche Kopfzeile er gelesen hat. Der Satz über der Tabelle — ganze Zelle,
  Groß- und Kleinschreibung egal — steht dort, weil er jede der drei Meldungen
  beantwortet, die der Import sonst nur als „Spalte fehlt" melden kann.
- `name` steht bei den Nachnamen. In deutscher Vereinssoftware heißt die Spalte mit Vor-
  und Nachname zusammen oft `Name`; der Import liest eine solche Datei als Nachname
  `Hans Meier`, statt sie abzulehnen. Das ist keine beschädigte Datei, sondern eine
  andere Bedeutung desselben Wortes.

### Verwaltung und Folge

- Der Adminbereich bekommt fünf Unterseiten: Arbeitsdienste, **Mitglieder**,
  Fahrgemeinschaften, Einstellungen, Statistik. Am Arbeitsdienst steht statt des
  Textfelds eine nur lesbare Liste der Angemeldeten mit einer Löschschaltfläche je Zeile.
- Die Kontaktvermittlung verlangt jetzt auf **beiden** Seiten die Anmeldung für genau
  diesen Dienst: Der Anfragende muss eingetragen sein, und das Mitglied, das den
  Eintrag angeboten hat, ebenso. Ein Arbeitsdienst ohne Bedarf oder ohne freien Platz kann damit auch keine
  Fahrtgemeinschaft anbieten — die Kehrseite derselben Regel, gewollt, weil die
  Arbeitsdienstliste die einzige ist.
- Adressen sind auf 190 Zeichen begrenzt, weil die Spalten so breit sind. Ohne diese Grenze
  nimmt der Server eine nach RFC 5321 gültige Adresse von 200 Zeichen an und lehnt sie
  beim Schreiben stillschweigend ab.
- Das Löschen eines Mitglieds nimmt die Anmeldungen mit und die angebotenen
  Fahrgemeinschaften nicht. Der Stammdatensatz selbst wird nur über die Mitgliederliste
  geändert, nicht über die Datenschutz-Werkzeuge: Ein Löschantrag sagt, dass die
  Anmeldungen hier weg sollen, nicht dass der Mensch aus dem Verein verschwindet.

### Aufräumen um die Mitglieder ohne Arbeitsdienst (1.11.0)

Ergänzt um den Auftrag vom 28.09.2026: Die Mitgliederseite bekommt einen Vorgang, der
alle Mitglieder löscht, die für keinen Arbeitsdienst angemeldet sind. Gedacht für das
Aufräumen nach einem Testlauf und vor einem frischen Import, nicht als Werkzeug des
laufenden Betriebs. Schema unverändert `1.2.0`.

- **Der Vorgang steht auf der Mitgliederseite, nicht unter den Einstellungen.** Das
  Aufräumen ist eine Handlung an den Mitgliedern; die Seite Einstellungen gehört laut
  eigener Beschreibung dem Logo und der Fußzeile und sonst nichts.
- **Zwei Schritte, und der erste ist ein Link.** Der erste Klick öffnet eine Übersicht,
  die jeden Betroffenen einzeln nennt; der zweite ist ein gepostetes Formular mit Nonce
  und der Berechtigung `delete_posts`. Ein Link wird von Browsern und Proxys von selbst
  abgerufen, deshalb darf er nichts löschen, und deshalb nennt auch die Übersicht die
  Zahl noch einmal auf der Schaltfläche.
- **„Nicht verknüpft“ heißt wörtlich: keine Zeile in `fg_event_members`.** Auch ein
  Dienst, der längst vorbei ist, hält sein Mitglied am Leben. Das Aufräumen ist eine
  Frage nach Anmeldungen und keine über Vereinsmitgliedschaften; wer die Frage
  umgedreht gestellt haben will, findet dieselbe Liste im Importbericht, der
  Mitglieder ohne Anmeldung ausdrücklich als solche nennt.
- **Die Bedingung steht in der `DELETE`-Anweisung selbst**, nicht in einer vorher
  gelesenen Liste. Wer sich zwischen der Übersicht und dem Klick für einen Dienst
  einträgt, behält damit sein Mitglied; mit einer Liste davor ginge es verloren, ohne
  dass es jemand ausgelöst hätte.
- **Fahrgemeinschaften bleiben.** Eine Fahrt ist ein eigener Eintrag und wird nicht
  mit dem Mitglied gelöscht — dieselbe Semantik wie beim Löschen eines einzelnen
  Mitglieds. Seit `1.14.0` gilt das mit einer Änderung: Die Fahrt trägt die
  Mitgliedsnummer, aber keine Adresse mehr, und ohne das Mitglied ist sie eine Zeile
  ohne Namen. Sie bleibt in der Tabelle, verschwindet aus der öffentlichen Liste und
  steht im Admin als „Fahrgemeinschaft ohne Mitglied“.

## Umsetzungsstand: Mitgliedsnummer als Schlüssel (1.14.0, Schema 1.4.0)

Ergänzt um den Auftrag vom 28.09.2026. Der Auftrag hieß: Die Mitgliedsnummer soll der
Schlüssel einer Fahrgemeinschaft sein, das Formular soll Mitgliedsnummer und E-Mail
abfragen, und beides soll zu einem Mitglied passen. Das ist eine Normalisierung, kein
Lochstopfen: Eine Fahrt gehörte vorher zu einer Adresse, und eine Adresse sagt nichts
darüber, **welches** Mitglied gemeint ist — und genau daran hängt die Kontaktvermittlung.

### Das Formular

- **Zwei Felder statt eines.** `fg_member_no` (Text, 40 Zeichen) und `fg_member_email`
  (E-Mail, 190 Zeichen). Beide stehen mit `maxlength` im HTML.
- **Beide müssen zu derselben Zeile passen.** Nicht „die Nummer ist bekannt“ und nicht
  „die Adresse ist bekannt“, sondern `member_no` **und** `email` zusammen. Eine falsche
  Adresse zu einer richtigen Nummer wird abgewiesen und umgekehrt, und beide Fehler
  bekommen dieselbe Meldung, damit die Antwort nicht verrät, welche Hälfte falsch war.
- **Die Adresse wird nicht gespeichert.** Sie ist ein Beweis, kein Feld. In `fg_rides`
  steht seit `1.4.0` überhaupt keine E-Mail-Adresse mehr; die einzige Adresse eines
  Mitglieds liegt in `fg_members.email`.
- **Der Name ist weg.** Es gibt kein `fg_alias` mehr. Der Vorname kommt aus dem Mitglied,
  und der Abfahrtsbereich ist der einzige Freitext des Formulars.

### Die Tabelle

`fg_rides` bekommt die Spalte `member_id` mit einem Index, und `alias` sowie
`contact_email` bleiben als leere Spalten stehen — aus demselben Grund wie `participants`:
ein zurückgerolltes Plugin darf nicht an einem unbekannten Feld scheitern. Die Migration
läuft über `maybe_install()` und besteht aus drei freistehenden statischen Methoden in
`FG_Schema`, aufgerufen von `install()`:

| Schritt | Wirkung |
| --- | --- |
| `adopt_ride_members()` | `UPDATE … JOIN` von `fg_members.email` auf `fg_rides.contact_email`, mit der Schranke `member_id = 0` im `WHERE` |
| `clear_legacy_ride_contacts()` | löscht die Zeilen ohne Mitglied und **mit** einer Adresse, leert danach beide Alt-Spalten und schreibt die Zahl in eine Option |
| `take_dropped_rides_notice()` | gibt die Zahl einmal zurück und löscht sie |

Der Löschpfad ist bewusst `WHERE member_id = 0 AND contact_email <> ''` und nicht bloß
`member_id = 0`: Jede Zeile einer Fassung vor `1.4.0` hat eine Adresse, weil das Formular
sie verlangt hat. Eine Zeile **ohne** Adresse und ohne Mitglied kann also nicht aus der
alten Fassung stammen, gehört zu keiner Fassung und bleibt stehen.

### Was das für den Menschen bedeutet

- **Eine Fahrt ohne Mitglied ist kein Fehler**, aber sie ist auch kein Angebot mehr. Die
  öffentliche Liste lässt sie weg, die Adminliste nennt sie „Fahrgemeinschaft ohne
  Mitglied“, und die Detailseite sagt „nicht mehr im Verein“ in **einer** Zeile statt in
  vier leeren — vier leere Zellen lesen sich wie ein Formular, das niemand ausgefüllt hat.
- **Jede Nachricht zu einer Fahrgemeinschaft geht an ein Mitglied.** Damit ist der
  Rückfall „Hallo“ ohne Namen auf diesem Weg nicht mehr erreichbar, und `{{Anrede}}`
  bleibt trotzdem ein Platzhalter: Ein gespeichertes „Hallo {{Vorname}},“ würde den
  Vornamen zweimal setzen.
- **Eine Fahrt, deren Mitglied später austritt, verliert ihre Adresse.** Die Adresse
  stammt aus dem Mitglied; ohne Mitglied gibt es keine mehr. Genau deshalb bleibt die
  Fahrt stehen und wird nicht mitgelöscht.

### Was offen bleibt

Der Vormame im Verein kann anders lauten als in der Mitgliederverwaltung, und die
Mitgliedsnummer ist das, was im Verein gilt. Beides ist eine bewusste Entscheidung für
die Quelle der Wahrheit: Die Mitgliederverwaltung, nicht das Angebot.

Eine Sperre „eine Fahrt je Mitglied und Dienst“ ist mit dieser Fassung **nicht**
eingeführt. Sie ist im Auftrag nicht verlangt worden und wäre eine eigene Entscheidung mit
eigenen Folgen für den öffentlichen Text; sie gehört in einen eigenen Schritt, nicht in
diesen.

## Umsetzungsstand: Kein vorgemerkter Zustand (1.15.0, Schema 1.5.0)

Ergänzt um den Auftrag vom 28.09.2026, am selben Tag wie die Mitgliedsnummer als Schlüssel.
Der Auftrag hieß: Fahrten sofort veröffentlichen, nur die Infomail mit dem Löschlink
schicken, und die Vormerkung soll weg. Das ist kein Refactoring, es ist eine Änderung am
Ablauf — und sie hat eine Entscheidung, die man nicht aus dem Auftrag ableiten kann und die
deshalb hier steht: **Was mit den Zeilen passiert, die zum Zeitpunkt der Aktualisierung noch
vorgemerkt waren.**

### Der Ablauf

| Vorher (bis 1.14.0) | Jetzt (ab 1.15.0) |
| --- | --- |
| Pflichtfelder, Einwilligung, Honeypot prüfen | unverändert |
| Zeile als `pending` schreiben | Zeile als `published` schreiben |
| zwei Tokens erzeugen (`confirm`, `discard`) | ein Token erzeugen (`delete`) |
| Bestätigungs-E-Mail mit zwei Links | Infomail mit einem Link |
| Klick auf `confirm` → `published`, zweite Mail | entfällt |
| Klick auf `discard` → Zeile weg | entfällt |
| — | Klick auf `delete` → Rückfrageseite, dann POST → Zeile weg |

Status, Zeitstempel und Löschtoken stehen in **einem** Statement. Das ist der ganze
Mechanismus von 1.15.0: Es gibt kein Fenster, in dem die Zeile geschrieben, aber noch nicht
sichtbar ist, und keinen Pfad, auf dem sie sichtbar wäre, ohne einen Löschlink zu haben.

### Die Migration: löschen, nicht veröffentlichen

```php
FG_Schema::clear_pending_rides();         // DELETE … WHERE status = 'pending'
FG_Schema::take_dropped_rides_notice();   // Zahl in dieselbe Option wie die Migration von 1.4.0
```

Eine vorgemerkte Zeile ist eine Einreichung, auf die niemand geantwortet hat. Ein Verein kann
diese Stille nicht als Zustimmung zu einem öffentlichen Eintrag lesen, und er hat auch kein
Werkzeug, sie zu prüfen: Der Bestätigungslink, den es dafür gäbe, ist mit dem Zustand weg.
Die Alternativen waren **veröffentlichen** (aus einer unbeantworteten Einreichung wird ein
öffentlicher Eintrag — nicht vertretbar) und **löschen** (der, der es wollte, bietet es mit
einem Formular noch einmal an). Gewählt ist das Löschen, und die Zahl geht in dieselbe Option
wie die der Fahrten ohne Mitglied aus der Migration von 1.4.0, damit der Verein **einmal**
über beide Sorten von Verlust unterrichtet wird.

Der Zähler wird von zwei Schritten gefüllt. `add_option()` wäre falsch: Es tut nichts, wenn
die Option schon steht, und der zweite Schritt ginge verloren. Stattdessen addiert ein
privater Akkumulator `count_dropped_rides()` per `update_option()`.

### Was am Gerüst bleibt

Die vier Spalten `pending_confirm_hash`, `pending_confirm_expires`,
`pending_discard_hash` und `pending_discard_expires` bleiben im DDL. Sie werden weder
gelesen noch geschrieben. Der Grund ist derselbe wie bei `alias`, `contact_email` und
`participants`, und er ist in diesem Dokument schon einmal begründet worden: Ein
zurückgerolltes Plugin darf nicht an einem unbekannten Feld scheitern. Die Begründung steht
im Kommentarblock über `$statements` in `class-fg-schema.php`, weil das DDL sonst
wegoptimiert wird und der Grund mit ihm.

Eine Besonderheit gibt es nur hier, und sie wird in der DDL-Datei ausdrücklich genannt: Die
Spalte `status` behält `DEFAULT 'published'`. Eine zurückgerollte Fassung, die eine Zeile
anlegt, ohne einen Status zu schreiben, bekommt damit einen **öffentlichen** Eintrag statt
einen vorgemerkten. Nach dem Wegfall des Zustands ist das die richtige Folge, aber aus
anderem Grund als beabsichtigt, und wer das DDL anfasst, muss das wissen.

### Der Menschen

- **Eine E-Mail zu einer Fahrt statt zwei.** Ihr Betreff ist „Deine Fahrgemeinschaft ist
  eingetragen – {{Arbeitsdienst}}“, sie nennt die öffentlichen Angaben und sagt ausdrücklich,
  dass nichts mehr zu bestätigen ist. Der einzige Link trägt den Wortlaut „Fahrgemeinschaft
  löschen“.
- **Der Löschlink ist nicht ungefährlicher als vorher.** Er öffnet eine Handhabungsseite, die
  den Eintrag benennt und den Knopf erst danach zeigt, und erst der POST löscht. Ein Link
  in einer Mail wird von Scannern und von Menschen geklickt, die alles anklicken; die Seite
  steht deshalb noch zwischen dem Klick und der Wirkung.
- **Ein Fehler beim Mailversand löscht nichts mehr.** Die Zeile bleibt stehen und bleibt
  öffentlich, `mail_send_failed` steigt, `publish_published` steigt nicht, und der Besitzer
  kann den Eintrag über den Adminbereich löschen. Vorher wäre derselbe Fehler ein Grund
  gewesen, den frisch veröffentlichten Eintrag wieder zu nehmen.
- **Die Adminliste hat keine Statusspalte und keinen Statusfilter mehr.** Beide sind mit dem
  Zustand weg; die Detailseite nennt **Eingetragen am** statt „Bestätigt am“.

### Die Zähler

| Vorher | Jetzt |
| --- | --- |
| `publish_pending` — Vormerkungen | `publish_published` — veröffentlichte Einträge |
| `publish_confirmed` — aus Vormerkungen gewordene Einträge | `publish_deleted` — gelöschte Einträge |
| `publish_failed` — misslungene Veröffentlichung | entfällt |

Die beiden Namen sind nicht umbenannt, sondern ersetzt: `publish_pending` hätte seit dieser
Fassung nichts mehr gezählt, und eine Tabelle im Adminbereich, die eine Zahl zeigt, die nie
wächst, ist schlechter als eine, die es nicht gibt. `publish_deleted` zählt den Weg über den
Löschlink, `publish_published` jeden Eintrag beim Absenden des Formulars.

## Umsetzungsstand: Anrede und Kontaktformular (1.16.0)

Ergänzt um den Auftrag vom 28.09.2026, am selben Tag wie die beiden Fassungen davor. Der
Auftrag hieß: In **allen** Nachrichten soll die Anrede „Hallo Vorname Nachname“ lauten, und
das Kontaktformular eines Eintrags soll zusätzlich nach der Mitgliedsnummer fragen. Beides
ist sichtbar und beides betrifft das, was ein Verein im Postfach liest.

### Die Anrede

```php
private function person_from_member( FG_Member $member )   // war: person() mit $ersatz_name
```

| Vorher (bis 1.15.0) | Jetzt (ab 1.16.0) |
| --- | --- |
| `person( $email, $ersatz )` und `person_from_member( $member, $ersatz )` | `person_from_member( FG_Member $member )` |
| „Hallo Anton“ | „Hallo Anton Berger“ |
| `{{Vorname}}`, `{{Name}}` je Nachricht anders beschrieben | `{{Vorname}}`, `{{Name}}` je Nachricht passend beschrieben, `{{Anrede}}` überall derselbe Satz |

Drei Entscheidungen stecken darin, und keine folgt aus dem Auftrag:

- **Der Nachname kommt in keinen eigenen Platzhalter.** `{{Name}}` gibt es, aber der ergibt
  sich aus `{{Vorname}}` plus Leerzeichen plus `{{Name}}`; ein dritter Platzhalter für denselben
  Nachnamen wäre eine zweite Schreibweise für eine Sache, und ein gespeicherter Wortlaut, der
  `{{Nachname}}` nicht kennt, würde beim Speichern abgelehnt. Der Nachname steht nur in der
  Anrede, und das ist die einzige Stelle einer Nachricht, an der ein Name als Ganzes gelesen
  wird.
- **Der Parameter ist ein Mitglied, keine Liste von Namen.** Die alte Fassung brauchte einen
  `$ersatz_name` für den Fall, dass die Adresse zu keinem Mitglied gehört; dieser Fall ist seit
  `1.14.0` auf diesem Weg unerreichbar, und ein Parameter, den niemand setzen kann, ist eine
  Einladung, ihn zu erfinden. Der Typ `FG_Member` ohne Vorgabewert macht das jetzt am Aufruf
  sichtbar: Ein Aufrufer ohne Mitglied bekommt nicht einen leeren Gruß, sondern einen Fehler
  beim Aufruf.
- **Die Anmeldebestätigung holt das Mitglied nicht ein zweites Mal.** `send_duty_signup()` hat
  das Mitglied bereits geladen und holte sich die Anrede trotzdem über `person( $to, '' )` —
  das war eine zweite Abfrage (`get_member_by_email()`) auf eine Adresse, die in derselben
  Zeile stand. Jetzt ruft sie `person_from_member( $member )` mit dem Mitglied auf, das sie
  ohnehin hat, und `person()` ist gelöscht.

### Das Kontaktformular

| Vorher (bis 1.15.0) | Jetzt (ab 1.16.0) |
| --- | --- |
| Feld `fg_contact_email` | Felder `fg_contact_member_no` und `fg_contact_email` |
| `normalize_email( post_value( 'fg_contact_email' ) )`, dann `get_member_by_email( $email )` | ein Aufruf: `find_member_for_registration( post_value( 'fg_contact_member_no' ), post_value( 'fg_contact_email' ) )` |
| `Reply-To` an die eingegebene Adresse | `Reply-To` an das gefundene Mitglied, dieselbe Adresse |
| `E-Mail` als Beschriftung | `E-Mail-Adresse`, wie in den beiden anderen Formularen |

Der Präfix `fg_contact_` unterscheidet die Feldnamen im DOM, die **Frage** ist dieselbe wie im
Anmeldeformular; deshalb steht in allen drei Formularen `Mitgliedsnummer` und `E-Mail-Adresse`
nebeneinander, und alle drei lehnen mit demselben Satz ab. Der Prüfaufruf ist derselbe
Aufruf: `FG_Repository::find_member_for_registration()`. Das ist der Punkt des ganzen Schritts —
drei Formulare, eine Regel, eine Stelle im Code, an der sie steht.

Der Preis, um den es geht, ist derselbe wie beim Angebotformular und er ist hier der
wichtigere, weil die Kontaktvermittlung an ihm hängt: Seit `1.14.0` kann eine Adresse zu
**zwei** Mitgliedern gehören, wenn jemand ausgeschieden ist und seine Adresse im Bestand
steht. Ohne die Nummer wäre die Kontaktvermittlung eine Lotterie, und die Anfrage ginge
gelegentlich an das falsche (frühere) Mitglied. Umgekehrt gilt: Ein Besucher ohne Mitgliedsnummer
kontaktiert niemanden, und das ist der Preis.

### Der Briefkasten

- `FG_Mail_Texts::mails()` trägt für `{{Anrede}}` in allen vier Nachrichten dasselbe Beispiel
  („Hallo Anton Berger“) und dieselbe Bedingung („Nummer und Adresse müssen zu einem Mitglied
  passen“). `{{Vorname}}` und `{{Name}}` bleiben in der Anmeldebestätigung, weil die Liste dort
  den Vornamen zeigt.
- Die Testmail auf der Seite **E-Mails** begrüßt mit demselben Namen wie die Vorschau, und
  der Betreff ist der der Eintragungsmail.

### Die Zähler

Die vier Zähler `contact_valid_email` und `contact_invalid_email` sowie
`publish_valid_email` und `publish_invalid_email` behalten ihre Schlüssel und bekommen nur
neue Beschriftungen. Ein Umbenennen würde die Tabelle verwerfen, in der seit dem
Einführen der Zähler steht, was der Verein in einer Woche hatte, und die sichtbare Beschriftung
lässt sich ohne Datenverlust an ein geändertes Paar anpassen.

| Schlüssel | Beschriftung ab 1.16.0 |
| --- | --- |
| `publish_valid_email` | Einträge mit passender Mitgliedsnummer und E-Mail-Adresse |
| `publish_invalid_email` | Abgewiesene Einträge: Nummer und E-Mail-Adresse passen nicht zu einem Mitglied, oder das Mitglied ist für diesen Arbeitsdienst nicht angemeldet |
| `contact_valid_email` | Kontaktanfragen mit passender Mitgliedsnummer und E-Mail-Adresse |
| `contact_invalid_email` | Abgewiesene Kontaktanfragen: Nummer und E-Mail-Adresse passen nicht zu einem Mitglied, das Mitglied ist für diesen Arbeitsdienst nicht angemeldet oder die Eintragung ist nicht sichtbar |

## Umsetzungsstand: Die Hinweise der drei Formulare (1.17.0)

Ergänzt um den Auftrag vom 28.09.2026, nach den drei Fassungen desselben Tages. Der Auftrag
hieß: Der Hinweis unter den beiden Feldern im Angebotformular soll über die ganze Breite gehen,
im Kontaktformular soll er **oberhalb** der Schaltfläche stehen, am Arbeitsdienst soll ein
anderer Text stehen, und der Satz über das Postfach soll in allen drei Formularen stehen.

### Derselbe Aufbau in allen drei Formularen

| Vorher (bis 1.16.0) | Jetzt (ab 1.17.0) |
| --- | --- |
| Angebot: Hinweis in der Zelle des E-Mail-Feldes, halbe Breite | Hinweis als Absatz zwischen Raster und Schaltfläche |
| Anmeldung: Hinweis über der Schaltfläche | unverändert, jetzt mit derselben Klasse |
| Kontakt: Knopf in der Zeile der beiden Felder, Hinweis darunter | Knopf in einem eigenen `.fg-actions`, Hinweis darüber |
| drei verschiedene Abstände für den Hinweis | eine Klasse `fg-hint-row` für alle drei |

```css
.fg-hint-row { margin: 0.75rem 0 0; }
```

Mit dem Umzug des Knopfes fallen zwei Regeln weg, die es nur für ihn gab: die kleine
Schaltfläche in der Feldzeile (`.fg-member-row .fg-button`) und der Abstand, den das
Kontaktformular sich über eine fremde Klasse (`fg-list-hint`) auf dem Hinweis gebucht hat. Der
Knopf des Kontaktformulars ist dadurch größer als vorher — das ist der Preis, und er ist der
gleiche in allen drei Formularen.

### Die drei Texte

| Formular | Wortlaut ab 1.17.0 |
| --- | --- |
| Angebot | Beide Angaben müssen zu einem Mitglied des Vereins passen, das sich für diesen Arbeitsdienst eingetragen hat. Dein Vorname steht in der Liste öffentlich; E-Mail-Adresse und Mitgliedsnummer nicht. Du bekommst eine E-Mail als Bestätigung. Prüfe deinen Spam-Ordner, wenn du keine erhältst. |
| Anmeldung | Beide Angaben müssen zu einem Mitglied des Vereins passen. Du bekommst eine E-Mail als Bestätigung. Prüfe deinen Spam-Ordner, wenn du keine erhältst. |
| Kontakt | Beide Angaben müssen zu einem Mitglied des Vereins passen, das sich für diesen Arbeitsdienst eingetragen hat. Deine Mitgliedsnummer und deine E-Mail-Adresse stehen nirgends öffentlich. Du bekommst eine E-Mail als Bestätigung. Prüfe deinen Spam-Ordner, wenn du keine erhältst. |

Der weggefallene Satz „Vorname und Nachname tragen wir für dich ein.“ hatte einen Grund, der
nicht mehr trägt: Die Mail, die ohnehin ankommt, begrüßt seit `1.16.0` mit Vor- und
Nachnamen, und der bessere Ort für beide Namen ist die Mail und nicht der Hinweis unter einem
Formular.

**Der Satz über das Postfach steht auch im Angebotformular, obwohl die Mail dort keine
Bestätigung ist.** Sie sagt selbst „es ist nichts mehr zu bestätigen“, und `smoke.php` prüft
genau diesen Satz im Mailtext. Die Entscheidung ist bewusst so gefallen: drei Formulare, drei
Formulierungen wären drei Regeln mit zwei Ausnahmen, und ein Besucher, der den Satz in einem
Formular liest, soll ihn in den beiden anderen wiedererkennen. Der Mailtext bleibt unangetastet.

## Umsetzungsstand: Die Einwilligung über den beiden Feldern (1.18.0)

Ergänzt um den Auftrag vom selben Tag, nach der Fassung 1.17.0: Die Checkbox der
Einwilligung soll über der Eingabe der Mitgliedsnummer und der E-Mail-Adresse stehen.

| Vorher (bis 1.17.0) | Jetzt (ab 1.18.0) |
| --- | --- |
| Ich …, Arbeitsdienst, Abfahrtsbereich, Nummer, E-Mail-Adresse, Einwilligung | Ich …, Arbeitsdienst, Abfahrtsbereich, **Einwilligung**, Nummer, E-Mail-Adresse |
| die Einwilligung am Ende des Formulars | die Einwilligung über den beiden Feldern, über die ihr letzter Satz handelt |
| Hinweis, dann Schaltfläche | unverändert |

Der Wortlaut der Einwilligung ist **unverändert** geblieben, und das ist der Kern der
Entscheidung: Ihr letzter Satz lautet „Meine E-Mail-Adresse und meine Mitgliedsnummer werden
dabei nicht öffentlich angezeigt.“ Bis hierher stand dieser Satz über zwei Feldern, die noch
kamen — der Leser musste sich merken, worüber er da liest. Jetzt steht er über ihnen. Das
„oben gemachten Angaben“ im ersten Satz bleibt richtig, weil alles, was die Einwilligung
aufzählt (Vorname aus der Mitgliederverwaltung, Art des Angebots, Abfahrtsbereich,
Arbeitsdienst), weiterhin über ihr steht.

Der Preis ist die Lesereihenfolge, und er wird hier nicht wegdiskutiert: Eine Einwilligung
gehört sonst ans Ende eines Formulars, und jemand, der erst am Ende abklicken wollte, muss
bis zum Ende der Seite blättern. Auf der anderen Seite nennt dieses Formular als einziges
der drei zwei Werte, die **nicht** veröffentlicht werden, und beide stehen unter der
Einwilligung.

Am Stylesheet ändert sich nichts, die Zelle ist dieselbe (`fg-field-full`) und wandert nur
im Raster nach oben. Die Fassung wächst trotzdem auf 1.18.0, weil sie die ausgelieferte
Fassung kennzeichnet und sich das Formular geändert hat; das Schema bleibt 1.5.0.

Geprüft wird die Reihenfolge über das gerenderte Markup und über **Positionen**, nicht über
die Anwesenheit der drei Teile: Abfahrtsbereich, Einwilligung, Nummer, Adresse. Eine
Prüfung, die nur nach den Feldern gesucht hätte, wäre grün gewesen, mit der Checkbox wieder
unten — das ist an einer Stelle des Codes so geschehen und mit einer Gegenprobe belegt.

## Umsetzungsstand: Die Beschreibung verdoppelt ihre Zeilenumbrüche nicht mehr (1.19.0)

Ergänzt um den Fehlerbefund vom 28.09.2026: Beim Speichern eines Arbeitsdienstes kamen
ständig mehr Leerzeilen in das Freitextfeld.

### Die Ursache

```php
// bis 1.19.0, FG_Admin_Events::read_text()
return trim( str_replace( "\r", "\n", $value ) );
```

Ein Browser schickt die Zeilen eines `<textarea>` mit `CRLF`. `str_replace( "\r", "\n", … )`
ersetzt das `CR` und lässt das `LF` des Paares stehen, aus **einem** Umbruch werden also
**zwei**:

| | Zeilenumbrüche | Leerzeilen |
| --- | --- | --- |
| Eingabe „Zeile eins␍␊Zeile zwei␍␊␍␊Absatz zwei“ | 3 | 1 |
| nach `read_text()` bis 1.19.0 | 6 | 3 |
| seit 1.19.0 | 3 | 1 |

Weil das Formular den gespeicherten Wert wieder in dasselbe Feld schreibt, verdoppelt
sich der Text bei jedem Speichern: 2, 4, 8, 16 Zeilenumbrüche. Öffentlich sichtbar, weil die
Dienstseite die Beschreibung mit `nl2br()` ausgibt, und mit Folgen für die Länge, weil
`DESCRIPTION_MAX` 500 ist — ein oft gespeicherter Text wird irgendwann mit „Beschreibung ist
zu lang“ abgelehnt, und der letzte Stand bleibt stehen, obwohl niemand etwas hinzugefügt hat.

Der Kommentar derselben Methode beschrieb die richtige Absicht und nannte die richtige
Regel („A browser sends the lines of a textarea separated by CRLF. Only that is straightened
out here …“); der Code tat das Gegenteil.

### Die Korrektur

```php
return trim( str_replace( array( "\r\n", "\r" ), "\n", $value ) );
```

Das ist die Schreibweise, die `FG_Member_Import` im selben Plugin seit Anfang an benutzt.
`FG_Mail_Templates` macht es mit `str_replace( "\r\n", "\n", … )` ohne das einzelne `CR`; für
einen Text aus einem Formular ist das nicht erreichbar, für einen aus der Datenbank auch
nicht, und deshalb bleibt es so.

Warum die beiden anderen Freitextfelder nicht betroffen waren: Die Mailtexte und die
Fußzeile werden über `sanitize_textarea_field()` gelesen, das `CRLF` bereits richtig zu `LF`
macht. Die Gruppenbezeichnung lief durch dieselbe Methode wie die Beschreibung, ersetzt aber
direkt danach jedes `LF` durch ein Leerzeichen — das Verdoppeln blieb unsichtbar.

### Die Bestandsdaten

Bereits gespeicherte Beschreibungen tragen die Leerzeilen weiter in sich. Sie werden **nicht**
automatisch bereinigt: Die Anzahl der Umbrüche ist ein Vielfaches des ursprünglichen, ein Text
mit einer gewollten Absatzleerzeile ist von einem ohne nicht unterscheidbar, und jede
automatische Bereinigung — ob beim Speichern oder einmalig — würde genau die Leerzeilen
zerstören, die jemand gewollt hat. Der Verein löscht die überzähligen Leerzeilen von Hand.
Ab 1.19.0 wächst der Text nicht mehr.
