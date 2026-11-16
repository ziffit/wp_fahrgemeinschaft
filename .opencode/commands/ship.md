---
description: Distributierbares Plugin-ZIP bauen (ohne Test-Bestandteile)
---

Baut `arbeitsdienste.zip` — nur der Ordner `arbeitsdienste/`, ohne `tests/`,
`PRUEFUMGEBUNG.md` und die übrigen Wurzeldateien (siehe `.gitignore`):

!`python3 -c 'import os,zipfile; files=[os.path.join(b,n) for b,d,ns in os.walk("arbeitsdienste") for n in ns if not n.startswith(".") and not n.endswith((".pyc",".orig",".rej"))]; z=zipfile.ZipFile("arbeitsdienste.zip","w",zipfile.ZIP_DEFLATED); [z.write(f,f) for f in sorted(files)]; z.close()' && unzip -l arbeitsdienste.zip`

Prüfe das Inhaltsverzeichnis: Es darf ausschließlich Dateien unter
`arbeitsdienste/` geben — kein Eintrag aus `tests/`. Außerdem muss die Fassung
in `arbeitsdienste.php` zusammen gesetzt sein (Kopf `Version:` **und**
`FG_VERSION`). Bei Auffälligkeiten das ZIP nicht weitergeben.
