#!/usr/bin/env bash
# Gegenproben zu den Fassungen 1.25.5 bis 1.25.9.
#
# Eine Gegenprobe baut genau einen Fehler ein, faehrt die Suite, die die
# Behauptung prueft, und verlangt, dass GENAU diese Behauptung rot wird. Green
# waere das schlechte Ergebnis: dann traegt die Behauptung nichts.
#
# Das Werkzeug liegt im Repository und nicht in /tmp. Es stand zweimal in
# /tmp/opencode und wurde zweimal von einem geleerten /tmp mitgenommen, jedes Mal
# mit dem Aufwand, alle Stoerungen neu zu schreiben. Es gehoert zu den Pruefungen
# und nicht zum Produkt — im Paket `arbeitsdienste.zip` ist es nicht enthalten,
# weil dort nur der Ordner `arbeitsdienste/` liegt.
#
# Aufruf:  bash tests/gegenproben/gegenproben.sh [nummer]
set -u
WERKZEUG=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
PROJEKT=$(cd "$WERKZEUG/../.." && pwd)
MOUNT=${FG_MOUNT:-/home/felix/Projekte/WPDEV/my-plugin}
ARBEIT=$WERKZEUG/.lauf
SOLL="$ARBEIT/soll"
LOG="$ARBEIT/ergebnis.tsv"
NUR=${1:-}

rm -rf "$ARBEIT"; mkdir -p "$SOLL"
# Der Soll-Stand ist eine Kopie des INHALTS, nicht des Ordners. Mit
# `cp -r arbeitsdienste "$SOLL/"` landet der Ordner selbst in "$SOLL", und das
# naechste `rsync --delete` schiebt einen Ordner in einen Ordner und loescht vorher
# den ganzen Baum des Plugins. Genau das ist zweimal passiert.
cp -r "$PROJEKT/arbeitsdienste/." "$SOLL/"
[ -f "$SOLL/arbeitsdienste.php" ] || { echo "ABBRUCH: $SOLL ist keine Kopie des Plugins"; exit 1; }

cd "$PROJEKT/tests"
: > "$LOG"

# `rsync -a` nimmt dem wiederhergestellten Baum die Zeitstempel des Soll-Standes
# mit — und das ist hier nicht ein Detail: Apache haelt jede Datei uebersetzt im
# opcache und prueft bei jedem Zugriff nur die Zeit. Eine Stoerung und ihre
# Ruecknahme fallen in dieselbe Sekunde, dann stimmt die Zeit, und der Container
# laeuft weiter mit der gestoerten Fassung. Sichtbar war das an sechs
# Admin-Behauptungen, die nach einem Gegenprobenlauf rot wurden, obwohl der Baum
# und der Mount byteweise gleich waren.
frisch() {
	sleep 1
	find "$1" -type f -exec touch {} +
}

zurueck() {
	if [ ! -f "$SOLL/arbeitsdienste.php" ] || [ ! -d "$SOLL/includes" ]; then
		echo "ABBRUCH: $SOLL ist keine Kopie des Plugins, es wird nichts geloescht"
		exit 1
	fi
	rsync -a --delete "$SOLL/" "$PROJEKT/arbeitsdienste/"
	frisch "$PROJEKT/arbeitsdienste"
}

suite_fahren() {
	docker cp "$PROJEKT/tests/." wpdev-wordpress-1:/tmp/fgtests/ >/dev/null
	docker exec wpdev-wordpress-1 php /tmp/fgtests/http_setup.php > "$PROJEKT/tests/fixture.json" 2>/dev/null
	docker exec wpdev-wordpress-1 php /tmp/fgtests/state.php recorder on >/dev/null
	bash "$1" > "$ARBEIT/$2.log" 2>&1
	docker exec wpdev-wordpress-1 php /tmp/fgtests/state.php recorder off >/dev/null
}

befund() {
	# $1 Log, $2 Behauptung, $3 erwartet, $4 Nummer und Name
	if grep -qF "FAIL $2 ::" "$ARBEIT/$1.log"; then ist=rot; else ist=gruen; fi
	if [ "$ist" = "$3" ]; then
		# Zwei %s und drei Argumente waere eine Vorlage, die bash ein zweites Mal
		# abarbeitet: printf wiederholt sie, sobald mehr Argumente da sind als
		# %s darin vorkommen.
		printf '%s\t%s\tOK\n' "$4" "$2" >> "$LOG"
		printf '  %-58s %s (erwartet: %s)\n' "$2" "$ist" "$3"
	else
		printf '%s\t%s\tFEHLER\n' "$4" "$2" >> "$LOG"
		printf '  %-58s %s (erwartet: %s)  <-- Gegenprobe ohne Wirkung\n' "$2" "$ist" "$3"
		# Die Nadel hat nicht gegriffen. Damit das nicht als "die Behauptung ist
		# gruen" gelesen wird, stehen die rot gewordenen Zeilen des Laufs dabei:
		# Meistens passt die Nadel nicht zum Namen der Behauptung, sondern zu
		# ihrem Detailtext — und das sieht von hier aus aus wie eine gruene
		# Behauptung.
		grep -F 'FAIL ' "$ARBEIT/$1.log" | head -6 | sed 's/^/      /'
	fi
}

probe() {
	local nummer=$1 name=$2 datei=$3 datei_alt=$4 suite=$5 log=$6 behauptung=$7
	[ -n "$NUR" ] && [ "$NUR" != "$nummer" ] && return 0
	echo "[$nummer] $name"
	zurueck
	# Vor der Stoerung muss der Baum deckungsgleich mit dem Soll-Stand sein. Ohne
	# diese Pruefung laeuft eine Gegenprobe auf einem Rest aus der vorherigen, und
	# dann zeigt das Fehlerbild der falschen Stelle: Zwei Laeufe mit demselben
	# Fehlerbild sind das Zeichen, dass die naechste Stoerung gar nicht wirkt.
	if ! diff -rq "$SOLL" "$PROJEKT/arbeitsdienste" > "$ARBEIT/baum.txt" 2>&1; then
		printf '%s\t%s\tBAUM SCHMUTZIG\n' "$nummer" "$behauptung" >> "$LOG"
		printf '  %-58s Baum nicht sauber zurueckgesetzt\n' "$behauptung"
		head -3 "$ARBEIT/baum.txt"
		return 0
	fi
	cp "$datei_alt" "$datei"
	if ! python3 "$WERKZEUG/stoerung.py" "$nummer" "$datei" 2> "$ARBEIT/fehler.txt"; then
		printf '%s\t%s\tNICHT EINGEBAUT\n' "$nummer" "$behauptung" >> "$LOG"
		printf '  %-58s die Stoerung passt nicht: %s\n' "$behauptung" "$(tail -1 "$ARBEIT/fehler.txt")"
		return 0
	fi
	if diff -q "$datei_alt" "$datei" >/dev/null; then
		printf '%s\t%s\tNICHT EINGEBAUT\n' "$nummer" "$behauptung" >> "$LOG"
		printf '  %-58s die Stoerung aendert nichts\n' "$behauptung"
		return 0
	fi
	[ -f "$PROJEKT/arbeitsdienste/arbeitsdienste.php" ] || { echo "ABBRUCH: das Plugin fehlt"; exit 1; }
	rsync -a --delete "$PROJEKT/arbeitsdienste/" "$MOUNT/"
	suite_fahren "$suite" "$log"
	befund "$log" "$behauptung" rot "$nummer $name"
	zurueck
}

# --- 1.25.5: der Treffpunkt
probe 1 "Positivliste ohne meeting_point" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-repository.php" \
	"$SOLL/includes/class-fg-repository.php" admin.sh gg1 \
	"and a duty can be given a meeting point again"

probe 2 "das Formular schickt die Felder immer mit" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-admin-events.php" \
	"$SOLL/includes/class-fg-admin-events.php" admin.sh gg2 \
	"and a save that does not carry the field does not throw the meeting point away"

probe 3 "{{Treffpunkt}} als Zeile hinter dem Beginn" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-mail-texts.php" \
	"$SOLL/includes/class-fg-mail-texts.php" admin.sh gg3 \
	"and it names no meeting point"

probe 4 "die Karte zeichnet die Adresse ohne Text" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-public-events.php" \
	"$SOLL/includes/class-fg-public-events.php" http.sh gg4 \
	"no card shows a meeting point of a duty that has none"

probe 5 "fester Wortlaut statt des Textes vom Dienst" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-mailer.php" \
	"$SOLL/includes/class-fg-mailer.php" http.sh gg5 \
	"the mail carries the meeting point as a link with the text of the duty"

probe 6 "links_of() kennt die zweite Form nicht" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-mail-texts.php" \
	"$SOLL/includes/class-fg-mail-texts.php" http.sh gg6 \
	"the mail carries the meeting point as a link with the text of the duty"

probe 7 "keine Laengengrenze fuer den Treffpunkt" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-admin-events.php" \
	"$SOLL/includes/class-fg-admin-events.php" admin.sh gg7 \
	"a meeting point that is too long is refused"

# --- 1.25.6: der neue Tab
probe 8 "die Seite oeffnet den Treffpunkt nicht im neuen Tab" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-public-events.php" \
	"$SOLL/includes/class-fg-public-events.php" http.sh gg8 \
	"the meeting point of the first card opens beside the page"

probe 9 "die Mails oeffnen den Treffpunkt nicht im neuen Tab" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-mail-templates.php" \
	"$SOLL/includes/class-fg-mail-templates.php" http.sh gg9 \
	"the meeting point in the mail opens in a new tab"

probe 10 "der Anker setzt den neuen Tab bei jedem Link" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-mail-templates.php" \
	"$SOLL/includes/class-fg-mail-templates.php" http.sh gg10 \
	"the unregister link in the same mail stays in the same tab"

# --- 1.25.7: die Startzeit als Pflichtfeld
probe 11 "das Formular laesst das Pflichtfeld weg" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-admin-events.php" \
	"$SOLL/includes/class-fg-admin-events.php" admin.sh gg11 \
	"the form has a required time field fg_event_time"

probe 12 "der Server nimmt eine leere Startzeit hin" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-admin-events.php" \
	"$SOLL/includes/class-fg-admin-events.php" admin.sh gg12 \
	"a duty without a start time is refused by name"

# --- 1.25.8: die Teilnehmerliste als Datei
probe 13 "die Datei maskiert ihre Felder nicht" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-csv.php" \
	"$SOLL/includes/class-fg-csv.php" admin.sh gg13 \
	"every row of the list comes back out of the file unchanged"

probe 14 "das Zeichen fuer einen Formelwert fehlt" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-csv.php" \
	"$SOLL/includes/class-fg-csv.php" admin.sh gg14 \
	"a value that a spreadsheet would take for a formula is marked as text"

probe 15 "die Datei holt sich den Abbruch-Token dazu" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-csv.php" \
	"$SOLL/includes/class-fg-csv.php" admin.sh gg15 \
	"the file carries no token that could cancel a registration"

probe 16 "die Berechtigung wird nicht geprueft" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-admin-events.php" \
	"$SOLL/includes/class-fg-admin-events.php" admin.sh gg16 \
	"and it is the sentence of the plugin"

# --- 1.25.9: der Testversand vor dem Speichern
probe 17 "erst speichern, dann schicken" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-admin-mails.php" \
	"$SOLL/includes/class-fg-admin-mails.php" admin.sh gg17 \
	"and the stored wording is the one from before"

probe 18 "der Testversand geht aus dem gespeicherten Wortlaut" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-admin-mails.php" \
	"$SOLL/includes/class-fg-admin-mails.php" admin.sh gg18 \
	"the sentence that was typed is in the message that went out"

probe 19 "der Testversand geht an eine fremde Adresse" \
	"$PROJEKT/arbeitsdienste/includes/class-fg-admin-mails.php" \
	"$SOLL/includes/class-fg-admin-mails.php" admin.sh gg19 \
	"the test message went to the address of the logged-in account"

zurueck
rsync -a --delete "$PROJEKT/arbeitsdienste/" "$MOUNT/"
frisch "$MOUNT"

# Der Ruecklauf. Nach allem, was hier getan wurde, muss der Baum wieder der
# unveraenderte Stand sein und die Umgebung ihn auch lesen — sonst steht am Ende
# ein Zustand, den niemand geprueft hat. Genau daran ist dieses Werkzeug
# gescheitert: Der Baum war richtig und der Container trotzdem nicht.
echo
echo "=== Ruecklauf: der Baum muss wieder der unveraenderte Stand sein ==="
suite_fahren admin.sh gg0
if grep -q '^== .* 0 failed' "$ARBEIT/gg0.log"; then
	printf 'Admin-Ebene nach dem Lauf: %s\n' "$(tail -1 "$ARBEIT/gg0.log")"
else
	printf 'Admin-Ebene nach dem Lauf: %s  <-- der Container liest etwas anderes als der Baum\n' "$(tail -1 "$ARBEIT/gg0.log")"
	grep '^  FAIL' "$ARBEIT/gg0.log" | head -5 | sed 's/^/    /'
fi

echo
echo "=== Auswertung ==="
python3 "$WERKZEUG/auswertung.py" "$LOG"
