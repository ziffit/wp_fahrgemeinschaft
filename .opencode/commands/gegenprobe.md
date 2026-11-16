---
description: Gegenprobe fahren — genau ein Fehler, genau eine rote Behauptung
---

Baut genau einen Fehler in `arbeitsdienste/` ein, fährt die zugehörige Suite und
verlangt, dass **genau** die zugehörige Behauptung rot wird. Grün wäre der
Fehlschlag: dann trägt die Behauptung nichts.

!`bash tests/gegenproben/gegenproben.sh`

Die Nummern stehen in `tests/gegenproben/gegenproben.sh` (Fassungen 1.25.5–1.25.9);
ohne Argument laufen alle. Das Ergebnis liegt in
`tests/gegenproben/.lauf/ergebnis.tsv`.
