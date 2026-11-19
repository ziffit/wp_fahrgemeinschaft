# AGENTS.md — wp_fahrgemeinschaft

WordPress-Plugin **„Arbeitsdienste"** (Ordner `arbeitsdienste/`): Vereins-
Arbeitsdienste planen, Mitglieder tragen sich selbst ein, und je Dienst werden
Fahrgemeinschaften koordiniert. Fassung **1.25.12**, Schema **1.9.0**.

Sprache: alle sichtbaren Texte und die Dokumentation auf **Deutsch**.
Kommentare folgen dem Bestand (Plugin-Klassen überwiegend englisch, Prüfskripte
deutsch).

## Layout

| Pfad | Inhalt |
|---|---|
| `arbeitsdienste/` | Das Plugin — distributierbar. Enthält **keine** Test- oder Prüfbestandteile. |
| `tests/` | Die vier Prüfsuiten (`smoke.php`, `http.sh`, `mail.sh`, `admin.sh`), Fixture-Aufbau (`http_setup.php`) und das Gegenproben-Werkzeug (`gegenproben/`). |
| `PRUEFUMGEBUNG.md` | Beschreibt die Prüfumgebung und die letzten Läufe. Gehört **nicht** zum Plugin. |
| `wordpress-fahrgemeinschaften-plugin.md` | Ursprünglicher Auftrag/Plan; überholte Stellen sind vor Ort markiert. |
| `promt.txt` | Ursprüngliche Aufgabenstellung. |

## Verifikation

Die Suiten brauchen den Compose-Stack der Testsite **außerhalb** dieses Repos.
`WPDEV` muss auf dessen Verzeichnis zeigen (`$WPDEV/setup.sh`,
`$WPDEV/docker-compose.yaml`); Einzelheiten in `PRUEFUMGEBUNG.md`, Abschnitt
„Stack".

- **Alles auf einmal:** `bash tests/run-all.sh` — Setup, dann vier Suiten:
  CLI (`smoke.php`), öffentlich (`http_setup.php` + `http.sh`), Mail-Ebene
  (`mail.sh`), Admin (`admin.sh`).
- **Zustimmung nötig:** Die CLI-Suite (`smoke.php all`) **löscht am Anfang alle
  Arbeitsdienste, Fahrgemeinschaften und Mitglieder**. Nur mit ausdrücklicher
  Zustimmung fahren.
- **Vor jeder von Hand gefahrenen Suite** `http_setup.php` laufen lassen, sonst
  läuft sie gegen verbrauchte Einmalwerte und meldet rote Zeilen ohne Plugin-Fehler.
- **Bytecode-Cache:** Nach dem Spiegeln von `arbeitsdienste/` ~3 s warten bzw. die
  Dateien anfassen (`touch`), sonst läuft die Suite gegen den alten Stand.
- **Gegenproben:** `bash tests/gegenproben/gegenproben.sh [nummer]` baut genau einen
  Fehler ein und verlangt, dass **genau** die zugehörige Behauptung rot wird; grün
  wäre der Fehlschlag.

Ohne laufenden Stack sind die Suiten nicht ausführbar (`docker`/`podman` fehlen
hier). Dann nur statisch arbeiten (PHP-Lint, Doku) und das offenlegen.

## Architektur-Regeln

- **Keine** Custom Post Types. Fünf eigene Tabellen: `{prefix}fg_events`,
  `{prefix}fg_rides`, `{prefix}fg_members`, `{prefix}fg_event_members`,
  `{prefix}fg_mail_templates`. Schema über `FG_Schema` (Version in
  `FG_Schema::VERSION`, gespeichert in `FG_Schema::OPTION`); Änderungen laufen
  ohne Reaktivierung.
- **Die Fassung steht zweimal** in `arbeitsdienste.php` — als `Version:` im
  Plugin-Kopf und als `FG_VERSION` — und wird **zusammen** gesetzt.
- Die **Mitgliedsnummer** ist der Schlüssel einer Fahrgemeinschaft; die
  E-Mail-Adresse ist Kontaktweg, nicht Identität.
- **Nichts Öffentliches:** E-Mail-Adressen, interne IDs, UUIDs und Lösch-Tokens
  erscheinen nie im Frontend. Der Löschlink ist der einzige Link der Mail.
- **Ein Link wird nur gezeichnet, wenn Text daneben steht.**
- Shortcodes `[arbeitsdienste]`, `[fahrgemeinschaften]` und `[anmeldung_pruefen]`;
  die öffentliche Seite kommt ohne JavaScript aus (native `details`).

## Datenschutz (hart)

Niemals personenbezogene Daten öffentlich ausgeben oder protokollieren — keine
E-Mail-Adresse, Mitgliedsnummer, UUID oder Lösch-Token in Verbindung mit einer
Person. Fehlermeldungen nennen den Grund, nicht die eingegebenen Werte.

## Stil

- Kleinste vollständige Änderung; Löschung vor Erweiterung.
- Keine Features ohne Anforderung.
- Verhaltensänderungen ziehen `arbeitsdienste/README.md` (und ggf. den Auftrag)
  sowie eine Behauptung in der passenden Suite nach: **Code + Doku + Prüfung
  zusammen**.
