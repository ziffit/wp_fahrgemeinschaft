---
description: Die vier Prüfsuiten der Reihe nach fahren (run-all.sh)
---

Fährt Setup und alle vier Suiten gegen die Testsite. Voraussetzung: `WPDEV`
zeigt auf den Compose-Stack (siehe `PRUEFUMGEBUNG.md`, Abschnitt „Stack").

!`bash tests/run-all.sh`

Bewerte: Jede Suite muss grün enden. Bei Rot **zuerst** prüfen, ob die Fixture
verbraucht war (dann `http_setup.php` neu laufen lassen) und ob der Bytecode-
Cache einen alten Stand hält (Dateien anfassen). Erst danach einen Plugin-Fehler
annehmen. Bei Erfolg: kurze Zusammenfassung mit den vier Zahlen.
