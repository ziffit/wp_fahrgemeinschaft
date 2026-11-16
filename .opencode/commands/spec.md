---
description: Verhaltensänderung umsetzen — Code + Doku + Prüfung zusammen
---

Umsetzung: $ARGUMENTS

Verbindliche Beschreibung des Verhaltens ist `arbeitsdienste/README.md`; der
ursprüngliche Auftrag steht in `wordpress-fahrgemeinschaften-plugin.md`. Dieses
Projekt führt keine FU/AK-IDs.

Vorgehen (Pflicht):
1. Betroffene Stellen in `arbeitsdienste/README.md` finden und **zuerst** dort
   aktualisieren (Verhalten, Begründung, Datenspeicherung).
2. Dann den Code in `arbeitsdienste/` ändern; die Fassung in `arbeitsdienste.php`
   (Kopf `Version:` und `FG_VERSION`) zusammen erhöhen, wenn etwas ausgeliefert wird.
3. Eine Behauptung in der passenden Suite ergänzen (`smoke.php`, `http.sh`,
   `mail.sh` oder `admin.sh`) und `bash tests/run-all.sh` grün fahren.
4. Rückgabe: geänderte Dateien, Diff-Umriss, Prüfergebnis.
