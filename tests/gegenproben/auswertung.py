#!/usr/bin/env python3
# Liest das Ergebnis der Gegenproben und sagt in einem Satz, was es bedeutet.
# Getrennt von gegenproben.sh, weil die Zahlen hierher gehoeren und nicht ins
# Skript, das sie produziert.
#
# Aufruf:  python3 auswertung.py [ergebnisdatei]
import io
import sys

pfad = sys.argv[1] if len(sys.argv) > 1 else None
if pfad is None:
    import os
    pfad = os.path.join(os.path.dirname(os.path.abspath(__file__)), '.lauf', 'ergebnis.tsv')

zeilen = [z for z in io.open(pfad, encoding='utf-8').read().split('\n') if z.strip()]
ohne_wirkung = []
wirksam = 0

for z in zeilen:
    teile = z.split('\t')
    if len(teile) != 3:
        ohne_wirkung.append('%s (unlesbar)' % z)
        continue
    nummer, behauptung, ist = teile
    if ist == 'OK':
        wirksam += 1
    else:
        ohne_wirkung.append('%s: %s (%s)' % (nummer, behauptung, ist))

print('Gegenproben: %d von %d' % (wirksam, len(zeilen)))
print('mit dem gestellten Fehlerbild rot: %d' % wirksam)
if ohne_wirkung:
    print('ohne Wirkung:')
    for z in ohne_wirkung:
        print('  ' + z)
    sys.exit(1)
print('keine Gegenprobe ohne Wirkung')
