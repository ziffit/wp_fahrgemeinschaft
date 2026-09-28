#!/usr/bin/env bash
# Admin level checks with a real login, including one save round trip.
set -u
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE="https://localhost:8443"
JAR="$DIR/cookies.txt"
STATE="php /tmp/fgtests/state.php"
rm -f "$JAR"

# Read or change plugin state from the table layer.
s() { docker exec wpdev-wordpress-1 $STATE "$@"; }

# Build a member for the run, first clearing any member that already holds the
# number. A refused insert is a zero, and a zero is a value every later step
# would use without complaining, so a number left over from an earlier run of
# the suite would turn into a chain of silent wrong answers. A refusal is only
# allowed to reach the assertions when the value itself is the problem.
neu() { s delete-member "$(s member-by-no "$1" id)" > /dev/null 2>&1; s make-member "$@"; }

pass=0; fail=0
ok()  { printf '  ok   %s\n' "$1"; pass=$((pass+1)); }
bad() { printf '  FAIL %s :: %s\n' "$1" "$2"; fail=$((fail+1)); }
has()  { if printf '%s' "$2" | grep -qF -- "$3"; then ok "$1"; else bad "$1" "missing: $3"; fi; }
hasnt(){ if printf '%s' "$2" | grep -qF -- "$3"; then bad "$1" "found: $3"; else ok "$1"; fi; }
clean() { if printf '%s' "$1" | grep -qE "Warning:</i>|Notice:</i>|Deprecated:</i>|Fatal error|Uncaught"; then
	bad "no php diagnostics: $2" "$(printf '%s' "$1" | grep -oE '(Warning|Notice|Deprecated|Fatal error|Uncaught)[^<]{0,140}' | head -3 | tr '\n' '|')"
else ok "no php diagnostics: $2"; fi; }
val() { python3 -c "
import re,sys
h=open('$1').read()
m=re.search(r'name=\"$2\"[^>]*value=\"([^\"]*)\"',h) or re.search(r'value=\"([^\"]*)\"[^>]*name=\"$2\"',h)
print(m.group(1) if m else '')"; }
# The one notice the shortcode prints, without the rest of the page. A refusal
# has to be read here and not over the whole document: the list page also prints
# a sentence per duty card, and one of those can hold the very words a notice
# must not hold. Reading the whole page would let a check pass on the card and
# say nothing about what the server answered.
notice() { python3 -c "
import re,sys,html
h=sys.stdin.read()
m=re.search(r'<div id=\"fg-hinweis\"[^>]*>(.*?)</div>', h, re.S)
print(html.unescape(re.sub(r'<[^>]+>','',m.group(1))).strip() if m else '')"; }
# The text of a frame of the screen, without the rest of the document. The
# argument is the class of the frame. A screen that prints two things at once
# needs this: the member screen carries the list of every member as well as the
# report of an import, and a number that a check looks for over the whole page is
# found in the list even when the report says nothing about it — the three fixture
# members are in the database, the import file holds two others, and both are on
# the page twice. Reading one frame is the difference between a check on the
# report and a check on the page.
rahmen() { python3 -c "
import re,sys,html
h=sys.stdin.read()
m=re.search(r'<div class=\"$1\">', h)
if not m:
    print('')
else:
    # The closing tag is found by counting, not by the next '</div>': the report
    # holds a notice of its own, and a search for the first closing tag would
    # hand back the first paragraph of the report and drop the table below it.
    # The count starts at 1 because the opening tag of the frame itself is
    # already behind the search position.
    tiefe, i = 1, m.end()
    while i < len(h):
        naechste = re.compile(r'<div\b|</div>').search(h, i)
        if not naechste:
            break
        if naechste.group(0) == '</div>':
            tiefe -= 1
            if tiefe == 0:
                i = naechste.start()
                break
        else:
            tiefe += 1
        i = naechste.end()
    print(html.unescape(re.sub(r'<[^>]+>',' ', h[m.end():i])).replace('  ',' '))"; }
# The same, but the argument is one word out of the class list instead of the
# whole attribute. rahmen() needs "class=\"notice notice-error\"" to be exactly
# what it is given, and both boxes of the mail screen carry three words; a helper
# that fits one class attribute only would either not find them or, once the
# words are dropped to make it fit, would find a box that is a different one. The
# word is matched on a word border, so "notice-error" does not match
# "notice-error fg-mail-meldung" by accident but "mel" does not match the first.
kasten() { python3 -c "
import re,sys,html
h=sys.stdin.read()
m=re.search(r'<div class=\"[^\"]*\b$1\b[^\"]*\"[^>]*>', h)
if not m:
    print('')
else:
    tiefe, i = 1, m.end()
    while i < len(h):
        naechste = re.compile(r'<div\b|</div>').search(h, i)
        if not naechste:
            break
        if naechste.group(0) == '</div>':
            tiefe -= 1
            if tiefe == 0:
                i = naechste.start()
                break
        else:
            tiefe += 1
        i = naechste.end()
    print(html.unescape(re.sub(r'<[^>]+>',' ', h[m.end():i])).replace('  ',' '))"; }
report() { rahmen 'fg-import-report'; }
# The block of the import screen that names the accepted header columns, in a
# form a caller can work with: one line "text<TAB>..." with the sentences, and
# one line "names<TAB>label<TAB>name" for every single name, not one line for a
# row. It reads that block and not the whole page, for the same reason rahmen()
# exists — the member list stands on the same screen. The names are not looked
# for one at a time, because a name is text: "name" is a surname here, and a
# search over the whole page would be answered by the sentence above the table.
# The sentences stop at the table for the same reason.
spalten() { python3 -c "
import re,sys,html
h=sys.stdin.read()
m=re.search(r'<details class=\"$1\">(.*?)</details>', h, re.S)
if not m:
    print('')
else:
    b=m.group(1)
    text=html.unescape(re.sub(r'<[^>]+>',' ', b.split('<table')[0]))
    print('text\t'+' '.join(text.split()))
    for row in re.findall(r'<tr[^>]*>(.*?)</tr>', b, re.S):
        zellen=re.findall(r'<t[hd][^>]*>(.*?)</t[hd]>', row, re.S)
        if len(zellen)!=2:
            continue
        namen=[html.unescape(x).strip() for x in re.findall(r'<code[^>]*>(.*?)</code>', zellen[1], re.S)]
        feld=html.unescape(re.sub(r'<[^>]+>','',zellen[0])).strip()
        for name in namen:
            print('names\t'+feld+'\t'+name)"; }
# The number in a named span, without the sentence around it. A screen that
# prints a count and names it in words makes two claims, and only the span is the
# one about the number: "Mitglieder sind für keinen Arbeitsdienst angemeldet" is
# true whether the span says 3 or 3000.
zahl() { python3 -c "
import re,sys,html
h=sys.stdin.read()
m=re.search(r'<span class=\"$1\">([^<]*)</span>', h)
print(html.unescape(m.group(1)).strip() if m else '')"; }
# The first cell of every data row of the screen, space separated. The rows are
# taken from the tbody and not from the whole table, so the heading row is not read
# as a member. A caller compares the result with the tables instead of searching
# for one number at a time: a member number is text, one of them can be the
# beginning of another, and a search for 004 is answered by the row of 0042.
nummern() { python3 -c "
import re,sys,html
h=sys.stdin.read()
koerper=re.search(r'<tbody[^>]*>(.*?)</tbody>', h, re.S)
a=koerper.group(1) if koerper else ''
n=[]
for row in re.findall(r'<tr[^>]*>(.*?)</tr>', a, re.S):
    c=re.findall(r'<td[^>]*>(.*?)</td>', row, re.S)
    if c:
        n.append(re.sub(r'\s+',' ',html.unescape(re.sub(r'<[^>]+>','',c[0]))).strip())
print(' '.join(n))"; }
# The text of the notices at the top of an admin screen, notices joined by " | ".
# A notice is read on its own because the member screen says in words what the
# cleanup section does — "gelöscht werden alle Mitglieder, die für keinen
# Arbeitsdienst angemeldet sind" — and a number searched for over the whole page
# can be answered by that sentence instead of by the notice. The public screen has
# the same helper for the same reason.
hinweis() { python3 -c "
import re,sys,html
h=sys.stdin.read()
m=re.findall(r'<div class=\"notice [^\"]*\"><p>(.*?)</p></div>', h, re.S)
print(' | '.join(html.unescape(re.sub(r'<[^>]+>','',x)).strip() for x in m))"; }
# The URL of the link whose query carries a given fragment. `link` is bound to the
# delete links because a duty screen carries one of those per row and taking the
# first one found would click the wrong button; a link that is named by its own
# query key is read with this.
ziel() { python3 -c "
import re,sys,html
h=sys.stdin.read()
m=re.search(r'href=\"([^\"]*$1[^\"]*)\"', h)
print(html.unescape(m.group(1)) if m else '')"; }
link() { python3 -c "
import re,sys
h=sys.stdin.read()
# The argument narrows the search to one kind of record. The screen of a work
# duty carries a delete link for the duty and one for every registration in it,
# and taking the first one found would click the wrong button: the first is a
# registration as soon as anybody has signed up, and the count of rides and
# registrations after it would then answer for a registration.
m=re.search(r'href=\"([^\"]*action=fg_delete_record[^\"]*type=$1[^\"]*)\"',h)
print(m.group(1).replace('&#038;','&').replace('&amp;','&') if m else '')"; }
# Reads one cell of the work duty overview for a given record. The record is
# named, because the numbers of the other columns are numbers too and a search
# over the whole screen would be measuring the wrong thing. The row is cut out
# of the list of rows first: a single non-greedy match from the first <tr> in
# the document would run across rows and answer with the cells of the first
# one, which is a record of its own.
cell() { python3 -c "
import re,sys
h=sys.stdin.read()
rows=re.findall(r'<tr[^>]*>.*?</tr>', h, re.S)
row=next((r for r in rows if 'event=$1' in r), '')
if not row:
    print('no-row')
else:
    c=re.findall(r'<td[^>]*>(.*?)</td>', row, re.S)
    print(re.sub(r'<[^>]+>','',c[$2]).strip() if len(c) > $2 else 'too-few-cells:%d' % len(c))"; }
# What a named field must be: an input tag carrying all the given attributes. A
# check that only asks whether a name appears somewhere on the page is answered
# just as well by a hidden field, and a hidden field cannot be typed into — the
# check would say the form asks for something while the form asks for nothing.
# Every attribute has to stand in the same tag, otherwise a form could satisfy
# this with the name in one place and the type in another.
feld() { python3 -c "
import re,sys
h=sys.stdin.read()
tags=[t for t in re.findall(r'<input[^>]*>', h, re.S) if 'name=\"$1\"' in t]
if not tags:
    print('kein feld')
else:
    fehlt=[a for a in $2 if not any(a in t for t in tags)]
    print('ok' if not fehlt else 'fehlt: ' + ','.join(fehlt))"; }

# The card of one work duty on the public list, as text. The list shows every
# visible duty, so a check that asks the whole page whether a word appears is
# answered by whichever duty happens to carry it — and on an installation with
# a second duty, by the wrong one. The card is named by the public reference in
# its anchor and not by its title: two duties can carry the same title, and a
# search by title then reads the first of them. Cards do not nest, so a non-greedy
# match is enough to get exactly one.
karte() { python3 -c "
import html,re,sys
h=sys.stdin.read()
m=re.search(r'<article class=\"fg-event-card\" id=\"fg-dienst-$1\">.*?</article>', h, re.S)
print(re.sub(r'\s+',' ',html.unescape(re.sub(r'<[^>]+>',' ',m.group(0)))).strip() if m else 'no-card')"; }
# The same card, cut at its signup form. A duty that cannot be signed up for has
# no form and therefore no reference, and the reason for it has to stay readable.
formular() { python3 -c "
import html,re,sys
h=sys.stdin.read()
m=re.search(r'<article class=\"fg-event-card\" id=\"fg-dienst-$1\">.*?</article>', h, re.S)
a=m.group(0) if m else ''
f=re.search(r'<form class=\"fg-signup-form\".*?</form>', a, re.S)
print(re.sub(r'\s+',' ',html.unescape(re.sub(r'<[^>]+>',' ',f.group(0)))).strip() if f else 'kein-formular')"; }

# One claim about a rendered document, decided on the document itself. The
# python may print what it found; that text becomes the detail of a failure, so
# a red line names the value that is wrong instead of only the claim. The
# reasons a claim about a stylesheet is read as declarations instead of as a
# pattern are written down where the body that does it says them.
pruef() { local grund; if grund=$(python3 -c "$3" <<< "$1"); then ok "$2"; else bad "$2" "${4:+$4 — }$grund"; fi; }

echo "== Admin level tests =="

# The work duty this suite works on belongs to this suite. It used to be read
# out of tests/fixture.json, which is the fixture of the HTTP suite — and that
# suite builds its own duty and consumes it. So the admin suite could only run
# when the HTTP suite had just run, and a green run said nothing about the admin
# screen if the other suite had not been before it. The same reasoning that made
# [4b] build its own published ride applies here.
EVENT_TITEL="Arbeitsdienst aus der Admin-Suite"
EVENT_ID=$(s make-event "$EVENT_TITEL" "$(date -d '+30 days' +%Y-%m-%d)" 4 "08:00")
if [ "$EVENT_ID" -gt 0 ]; then ok "the work duty of this suite exists ($EVENT_ID)"; else bad "the work duty of this suite exists" "$EVENT_ID"; exit 1; fi

# One of this suite's own members sits on this suite's own duty, because the
# sentence about the taken places and the read-only list of the members only
# exist for a duty somebody has registered for. It used to be somebody else's
# registration, left behind by the HTTP suite: the check passed only because
# another suite had filled the duty it was reading.
EVENTMITGLIED=$(neu "0842" "verwalter@example.org" "Verwalter" "Test")
if [ "$EVENTMITGLIED" -gt 0 ]; then
	s register "$EVENT_ID" "0842" "verwalter@example.org" > /dev/null
	if [ "$(s count-registrations "$EVENT_ID")" = "1" ]; then ok "one member of this suite is on the duty"; else bad "one member of this suite is on the duty" "$(s count-registrations "$EVENT_ID")"; fi
else
	bad "one member of this suite is on the duty" "member $EVENTMITGLIED was refused"
fi

# --- login
login=$(curl -sk -c "$JAR" -d "log=fg_admin&pwd=Test1234!&wp-submit=Anmelden&redirect_to=$BASE/wp-admin/&testcookie=1" "$BASE/wp-login.php")
if grep -q "wordpress_logged_in" "$JAR"; then ok "login works"; else bad "login works" "$(printf '%s' "$login" | grep -oE 'id="login_error"[^<]*<[^<]*' | head -1)"; exit 1; fi

# --- statistics page
echo "[1] statistics screen"
stats=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften")
has "menu page renders" "$stats" "Statistik"
# The top level entry carries the name of the tool and links to whatever the
# first submenu is, so a click on it lands on the work duty list. The name
# therefore stands twice, once in the top level and once as the first submenu.
# The collapsed submenu head takes its text from the same label, so both are
# read here.
nav=$(printf '%s' "$stats" | python3 -c "
import re, sys
html = sys.stdin.read()
# The search is anchored on the list item id, because the slug also occurs in
# inline scripts.
m = re.search(r'id=\"toplevel_page_fahrgemeinschaften\">(.*?)</ul>', html, re.S)
if not m:
    print('')
else:
    block = m.group(1)
    name = re.search(r\"<div class='wp-menu-name'>([^<]*)</div>\", block)
    head = re.search(r\"<li class='wp-submenu-head'[^>]*>([^<]*)</li>\", block)
    top = re.search(r\"<a href='([^']*)'\", block)
    print((name.group(1).strip() if name else '') + '|' + (head.group(1).strip() if head else '') + '|' + (top.group(1) if top else ''))")
if [ "${nav%%|*}" = "Arbeitsdienste" ] && [ "$(printf '%s' "$nav" | cut -d'|' -f2)" = "Arbeitsdienste" ]; then
	ok "top level entry keeps the plugin name"
else
	bad "top level entry keeps the plugin name" "found: ${nav:-<no menu block>}"
fi
# The label promises the work duty list, so the entry has to lead there.
if [ "$(printf '%s' "$nav" | cut -d'|' -f3)" = "admin.php?page=fahrgemeinschaften-events" ]; then
	ok "top level entry leads to the work duties"
else
	bad "top level entry leads to the work duties" "found: $(printf '%s' "$nav" | cut -d'|' -f3)"
fi
has "page heading matches" "$stats" "<h1>Statistik</h1>"

# The submenu order is part of the interface: Arbeitsdienste, Mitglieder,
# Fahrgemeinschaften, Einstellungen, Statistik. WordPress links the top level entry to whatever is first, so this
# also decides where a click on the plugin name lands.
order=$(printf '%s' "$stats" | python3 -c "
import re, sys
html = sys.stdin.read()
m = re.search(r'id=\"toplevel_page_fahrgemeinschaften\">(.*?)</ul>', html, re.S)
if not m:
    print('')
else:
    # Only the entries inside the submenu list, the top level anchor above it
    # carries the same href and would be counted twice.
    block = m.group(1)
    block = block[block.find(\"<ul class='wp-submenu\"):]
    print(' > '.join(label.strip() for _, label in re.findall(r\"admin\.php\?page=(fahrgemeinschaften[a-z\-]*)'[^>]*>([^<]*)<\", block)))")
if [ "$order" = "Arbeitsdienste > Mitglieder > Fahrgemeinschaften > Einstellungen > E-Mails > Statistik" ]; then
	ok "submenu shows the screens in the intended order"
else
	bad "submenu shows the screens in the intended order" "found: ${order:-<no menu block>}"
fi
has "shows the retention note" "$stats" "nach 90 Tagen entfernt"
has "shows the three periods" "$stats" "Letzte 30 Tage"
has "shows counter labels" "$stats" "Vorgemerkte Einträge"
hasnt "statistics show no address" "$stats" "@example"
has "work duty screen is linked" "$stats" "page=fahrgemeinschaften-events"
has "member screen is linked" "$stats" "page=fahrgemeinschaften-members"
has "ride screen is linked" "$stats" "page=fahrgemeinschaften-rides"
has "settings screen is linked" "$stats" "page=fahrgemeinschaften-settings"
clean "$stats" "statistics screen"

# The same order has to be visible from every screen of the plugin, and the
# entry of the screen that is open has to be the marked one.
for pair in "fahrgemeinschaften-events:Arbeitsdienste" "fahrgemeinschaften-members:Mitglieder" "fahrgemeinschaften-rides:Fahrgemeinschaften" "fahrgemeinschaften-settings:Einstellungen" "fahrgemeinschaften-mails:E-Mails"; do
	page="${pair%%:*}"
	label="${pair##*:}"
	body=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=$page")
	IFS='|' read -r here there <<<"$(printf '%s' "$body" | python3 -c "
import re, sys
html = sys.stdin.read()
m = re.search(r'id=\"toplevel_page_fahrgemeinschaften\">(.*?)</ul>', html, re.S)
if not m:
    print('|')
else:
    block = m.group(1)
    block = block[block.find(\"<ul class='wp-submenu\"):]
    # The open screen is marked on the anchor, the classes sit on both the li
    # and the a element, and an li without attributes is written as well.
    items = []
    for chunk in re.findall(r\"<li.*?</li>\", block, re.S):
        m = re.search(r\"href='admin\.php\?page=(fahrgemeinschaften[a-z\-]*)'[^>]*>([^<]*)<\", chunk)
        if m:
            items.append((m.group(1), m.group(2).strip(), 'current' in chunk))
    current = [label for _slug, label, is_current in items if is_current]
    print((current[0] if current else '') + '|' + ' > '.join(label for _s, label, _c in items))")"
	if [ "$here" = "$label" ]; then
		ok "on $label that entry is marked as current"
	else
		bad "on $label that entry is marked as current" "found: ${here:-none}"
	fi
	if [ "$there" = "Arbeitsdienste > Mitglieder > Fahrgemeinschaften > Einstellungen > E-Mails > Statistik" ]; then
		ok "order holds on the $label screen"
	else
		bad "order holds on the $label screen" "found: ${there:-<no menu block>}"
	fi
	clean "$body" "$label screen"
done

# --- event list
echo "[2] work duty list"
events=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events")
has "event is listed" "$events" "$EVENT_TITEL"
has "date column exists" "$events" ">Datum<"
has "group column exists" "$events" ">Gruppe<"
has "demand column exists" "$events" ">Bedarf<"
has "visibility column exists" "$events" "Öffentlich sichtbar"
has "registration column exists" "$events" ">Teilnehmer<"
has "free places column exists" "$events" ">Freie Plätze<"
has "edit link exists" "$events" "event=$EVENT_ID"
hasnt "no trash column" "$events" ">Papierkorb<"
# The four fields are optional, so the list has to be able to say "nothing
# stated" without inventing a number for it. That is checked further down, on
# a record of this run: the club keeps its own entries on its own duties, and
# those carry real numbers. Reading a cell of a foreign record would only prove
# what somebody else typed in.
clean "$events" "event list"

# --- event form
echo "[3] work duty form"
screen=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events&event=$EVENT_ID")
has "date field present" "$screen" 'name="fg_event_date"'
has "time field present" "$screen" 'name="fg_event_time"'
# The list of addresses the club used to type in by hand is gone. What stands
# in its place is a read-only list of the members who registered themselves, so
# the form must not offer a field to write into any more.
hasnt "no field writes the participants by hand" "$screen" 'name="fg_event_participants"'
has "the registered members are listed" "$screen" "Angemeldete Mitglieder"
# Both of these read the duty of this run, which carries the member of this
# run. Reading only the heading would hold for every duty, including one
# nobody has signed up for.
has "and the member of this run is named in it" "$screen" "Verwalter"
has "it says how many of the places are taken" "$screen" "1 von höchstens 4 Plätzen belegt"
has "visibility checkbox present" "$screen" 'name="fg_event_active"'
has "group field present" "$screen" 'name="fg_group_name"'
has "demand field present" "$screen" 'name="fg_demand"'
has "duration field present" "$screen" 'name="fg_duration_hours"'
has "description field present" "$screen" 'name="fg_description"'
# The time is the point where the duty starts, so the field says so.
has "the time field is named as the start" "$screen" "Beginn (Uhrzeit, optional)"
has "the start is explained" "$screen" "weil der Dienst dann beginnt"
# A browser stops a long text in the field; a post does not have to.
has "the group field carries its limit" "$screen" 'maxlength="100"'
has "the description field carries its limit" "$screen" 'maxlength="500"'
has "the demand field refuses fractions" "$screen" 'step="1"'
has "uuid is shown" "$screen" "$(s event "$EVENT_ID" event_uuid)"
has "uuid is read only" "$screen" 'readonly'
hasnt "no internal table name on the screen" "$screen" "fg_events"
has "delete link present" "$screen" "action=fg_delete_record"
has "delete link carries a nonce" "$screen" "_wpnonce="
hasnt "no post editor" "$screen" "post.php?post="
clean "$screen" "work duty form"

echo "[3b] the empty form offers a new work duty"
newform=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events&event=0")
has "new work duty heading" "$newform" "Neuer Arbeitsdienst"
has "title field present" "$newform" 'name="fg_title"'
has "uuid is empty for a new record" "$newform" 'value="" readonly'
clean "$newform" "empty work duty form"

# --- ride screens
echo "[4] ride screens"
rides=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-rides")
has "ride list renders" "$rides" "Moewe-Trupp"
has "event filter present" "$rides" 'name="fg_event_filter"'
has "status filter present" "$rides" 'name="fg_status_filter"'
has "contact address is visible to the administrator" "$rides" "anton@angeln.example.org"
hasnt "no form to change a ride" "$rides" 'name="fg_origin"'
hasnt "no delete form" "$rides" '<form method="post"'
clean "$rides" "ride list"

RIDE_ID=$(s make-ride "$EVENT_ID" offer "Wartende Fahrt" Innenstadt anton@angeln.example.org)
detail=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-rides&ride=$RIDE_ID")
has "detail screen renders" "$detail" "Wartende Fahrt"
has "detail shows the status" "$detail" "Vorgemerkt"
has "detail offers the permanent delete" "$detail" "action=fg_delete_record"
hasnt "detail has no save button" "$detail" 'name="fg_alias"'
clean "$detail" "ride detail screen"

# The published ride of this section belongs to this run. It used to be read out
# of the fixture, which is the fixture of the HTTP suite — and that suite proves
# it can delete that ride. The filter check then failed whenever the two suites
# ran in the wrong order, which says nothing about the filter.
PUBLISHED_RIDE=$(s make-ride "$EVENT_ID" search "Elster-Gruppe" Suedstadt anton@angeln.example.org published)

echo "[4b] filtering the ride list"
filtered=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-rides&fg_status_filter=pending&fg_event_filter=$EVENT_ID")
has "pending filter keeps the pending ride" "$filtered" "Wartende Fahrt"
hasnt "pending filter hides the published ride" "$filtered" "Elster-Gruppe"
other=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-rides&fg_status_filter=published")
has "published filter keeps the published ride" "$other" "Elster-Gruppe"
hasnt "published filter hides the pending ride" "$other" "Wartende Fahrt"
clean "$other" "filtered ride list"

# --- save round trip: create a new work duty
echo "[5] creating a work duty in the admin"
NONCE=$(val /dev/stdin fg_event_nonce <<< "$newform")
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/saved.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_event" \
	--data-urlencode "fg_event_id=0" \
	--data-urlencode "fg_event_nonce=$NONCE" \
	--data-urlencode "fg_title=Neuer Dienst aus dem Admin" \
	--data-urlencode "fg_event_date=2027-03-04" \
	--data-urlencode "fg_event_time=07:30" \
	--data-urlencode "fg_event_active=1" \
	--data-urlencode "fg_group_name=Gartenpflege Nord" \
	--data-urlencode "fg_demand=8" \
	--data-urlencode "fg_duration_hours=4" \
	--data-urlencode "fg_description=Bitte festes Schuhwerk mitbringen.
Handschuhe sind vorhanden." \
	)
saved=$(cat "$DIR/saved.html")
has "save confirms the new record" "$saved" "Der Arbeitsdienst wurde angelegt."
has "save opens the new record" "$out" "event="
NEW_ID=$(s find-event "Neuer Dienst aus dem Admin")
if [ "$NEW_ID" -gt 0 ]; then ok "new work duty is stored ($NEW_ID)"; else bad "new work duty is stored" "$NEW_ID"; fi
if [ "$(s event "$NEW_ID" event_date)" = "2027-03-04" ]; then ok "date stored"; else bad "date stored" "$(s event "$NEW_ID" event_date)"; fi
if [ "$(s event "$NEW_ID" is_active)" = "1" ]; then ok "visibility stored"; else bad "visibility stored" "$(s event "$NEW_ID" is_active)"; fi
if [ -n "$(s event "$NEW_ID" event_uuid)" ]; then ok "uuid generated on insert"; else bad "uuid generated on insert" "empty"; fi
if [ "$(s event "$NEW_ID" group_name)" = "Gartenpflege Nord" ]; then ok "group stored"; else bad "group stored" "$(s event "$NEW_ID" group_name)"; fi
if [ "$(s event "$NEW_ID" demand)" = "8" ]; then ok "demand stored"; else bad "demand stored" "$(s event "$NEW_ID" demand)"; fi
if [ "$(s event "$NEW_ID" duration_hours)" = "4" ]; then ok "duration stored"; else bad "duration stored" "$(s event "$NEW_ID" duration_hours)"; fi
if [ "$(s event "$NEW_ID" description)" = "Bitte festes Schuhwerk mitbringen.
Handschuhe sind vorhanden." ]; then ok "description stored with its line break"; else bad "description stored with its line break" "$(s event "$NEW_ID" description)"; fi
clean "$saved" "work duty create"

echo "[5a] the four fields come back into the form"
back=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events&event=$NEW_ID")
has "group is filled in" "$back" 'value="Gartenpflege Nord"'
has "demand is filled in" "$back" 'value="8"'
has "duration is filled in" "$back" 'value="4"'
has "description is filled in" "$back" "Bitte festes Schuhwerk mitbringen."
clean "$back" "form after the four fields"

echo "[5b] editing the new work duty"
editform=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events&event=$NEW_ID")
has "form is filled with the stored title" "$editform" 'value="Neuer Dienst aus dem Admin"'
has "form is filled with the stored time" "$editform" 'value="07:30"'
has "form is filled with the stored demand" "$editform" 'value="8"'
ENONCE=$(val /dev/stdin fg_event_nonce <<< "$editform")
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/edited.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_event" \
	--data-urlencode "fg_event_id=$NEW_ID" \
	--data-urlencode "fg_event_nonce=$ENONCE" \
	--data-urlencode "fg_title=Geänderter Dienst" \
	--data-urlencode "fg_event_date=2027-03-05" \
	--data-urlencode "fg_event_time=" \
	--data-urlencode "fg_group_name=Winterdienst Süd" \
	--data-urlencode "fg_demand=1" \
	--data-urlencode "fg_duration_hours=" \
	--data-urlencode "fg_description=Neu: nur noch drei Personen gebraucht." \
	)
edited=$(cat "$DIR/edited.html")
has "save confirms the change" "$edited" "Der Arbeitsdienst wurde gespeichert."
if [ "$(s event "$NEW_ID" title)" = "Geänderter Dienst" ]; then ok "title stored"; else bad "title stored" "$(s event "$NEW_ID" title)"; fi
if [ "$(s event "$NEW_ID" event_date)" = "2027-03-05" ]; then ok "changed date stored"; else bad "changed date stored" "$(s event "$NEW_ID" event_date)"; fi
if [ "$(s event "$NEW_ID" event_time)" = "" ]; then ok "cleared time stored as empty"; else bad "cleared time stored as empty" "$(s event "$NEW_ID" event_time)"; fi
if [ "$(s event "$NEW_ID" group_name)" = "Winterdienst Süd" ]; then ok "changed group stored"; else bad "changed group stored" "$(s event "$NEW_ID" group_name)"; fi
if [ "$(s event "$NEW_ID" demand)" = "1" ]; then ok "changed demand stored"; else bad "changed demand stored" "$(s event "$NEW_ID" demand)"; fi
# An emptied field means the club no longer states a duration, and the stored
# number has to go with it.
if [ "$(s event "$NEW_ID" duration_hours)" = "0" ]; then ok "cleared duration stored as no statement"; else bad "cleared duration stored as no statement" "$(s event "$NEW_ID" duration_hours)"; fi
if [ "$(s event "$NEW_ID" description)" = "Neu: nur noch drei Personen gebraucht." ]; then ok "changed description stored"; else bad "changed description stored" "$(s event "$NEW_ID" description)"; fi
clean "$edited" "work duty edit"

echo "[5c1] a value the store does not accept is refused, and nothing changes"
LANG_GRUPPE=$(python3 -c "print('ä' * 101)")
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/toolong.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_event" \
	--data-urlencode "fg_event_id=$NEW_ID" \
	--data-urlencode "fg_event_nonce=$ENONCE" \
	--data-urlencode "fg_title=Geänderter Dienst" \
	--data-urlencode "fg_event_date=2027-03-05" \
	--data-urlencode "fg_group_name=$LANG_GRUPPE" \
	--data-urlencode "fg_demand=2" \
	)
toolong=$(cat "$DIR/toolong.html")
has "the too long group is named" "$toolong" "Gruppe ist zu lang"
has "the limit is named" "$toolong" "höchstens 100 Zeichen"
has "nothing was saved" "$toolong" "Es wurde nichts gespeichert."
if [ "$(s event "$NEW_ID" group_name)" = "Winterdienst Süd" ]; then ok "the previous group is kept ($(s event "$NEW_ID" group_name))"; else bad "the previous group is kept" "$(s event "$NEW_ID" group_name)"; fi
if [ "$(s event "$NEW_ID" demand)" = "1" ]; then ok "the other fields of the refused save are kept too"; else bad "the other fields of the refused save are kept too" "$(s event "$NEW_ID" demand)"; fi
clean "$toolong" "work duty save with a too long group"

LANG_TEXT=$(python3 -c "print('ö' * 501)")
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/toolong2.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_event" \
	--data-urlencode "fg_event_id=$NEW_ID" \
	--data-urlencode "fg_event_nonce=$ENONCE" \
	--data-urlencode "fg_title=Geänderter Dienst" \
	--data-urlencode "fg_event_date=2027-03-05" \
	--data-urlencode "fg_description=$LANG_TEXT" \
	)
toolong2=$(cat "$DIR/toolong2.html")
has "the too long description is named" "$toolong2" "Beschreibung ist zu lang"
has "the description limit is named" "$toolong2" "höchstens 500 Zeichen"
if [ "$(s event "$NEW_ID" description)" = "Neu: nur noch drei Personen gebraucht." ]; then ok "the previous description is kept"; else bad "the previous description is kept" "$(s event "$NEW_ID" description)"; fi
clean "$toolong2" "work duty save with a too long description"

for raw in -3 2.5 acht 1e3; do
	out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/count.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
		--data-urlencode "action=fg_save_event" \
		--data-urlencode "fg_event_id=$NEW_ID" \
		--data-urlencode "fg_event_nonce=$ENONCE" \
		--data-urlencode "fg_title=Geänderter Dienst" \
		--data-urlencode "fg_event_date=2027-03-05" \
		--data-urlencode "fg_demand=$raw" \
		)
	count_bad=$(cat "$DIR/count.html")
	has "the demand \"$raw\" is refused by name" "$count_bad" "Bedarf an Personen muss eine ganze Zahl"
	if [ "$(s event "$NEW_ID" demand)" = "1" ]; then ok "the previous demand is kept after \"$raw\""; else bad "the previous demand is kept after \"$raw\"" "$(s event "$NEW_ID" demand)"; fi
done
clean "$(cat "$DIR/count.html")" "work duty save with a demand that is not a count"

# Exactly on the limit is not a refusal, otherwise the field could never hold
# the number the club allows itself.
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/exact.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_event" \
	--data-urlencode "fg_event_id=$NEW_ID" \
	--data-urlencode "fg_event_nonce=$ENONCE" \
	--data-urlencode "fg_title=Geänderter Dienst" \
	--data-urlencode "fg_event_date=2027-03-05" \
	--data-urlencode "fg_group_name=$(python3 -c "print('ä' * 100)")" \
	--data-urlencode "fg_demand=0" \
	--data-urlencode "fg_description=$(python3 -c "print('ö' * 500)")" \
	)
exact=$(cat "$DIR/exact.html")
hasnt "a text exactly on the limit is not refused" "$exact" "Es wurde nichts gespeichert."
if [ "$(s event "$NEW_ID" demand)" = "0" ]; then ok "a demand of zero is stored as no statement"; else bad "a demand of zero is stored as no statement" "$(s event "$NEW_ID" demand)"; fi
# In the overview the two cells of that same record now have to say what the
# state says: a demand of zero is no statement and reads as a dash, while the
# group of 100 characters is a statement and has to appear. The cells are read
# for this record only, because the other rows carry numbers of their own.
overview=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events")
if [ "$(printf '%s' "$overview" | cell "$NEW_ID" 3)" = "—" ]; then
	ok "a duty with no demand shows a dash, not a zero"
else
	bad "a duty with no demand shows a dash, not a zero" "cell reads: $(printf '%s' "$overview" | cell "$NEW_ID" 3)"
fi
if [ "$(printf '%s' "$overview" | cell "$NEW_ID" 2)" = "$(python3 -c "print('ä' * 100)")" ]; then
	ok "a stated group of 100 characters shows all of them"
else
	bad "a stated group of 100 characters shows all of them" "cell reads: $(printf '%s' "$overview" | cell "$NEW_ID" 2)"
fi
# The two count columns are read from the same row. The demand of this record
# is 0 and nobody is registered for it, so both cells are a zero; that is the
# only case in which a zero is a true statement rather than a missing value.
if [ "$(printf '%s' "$overview" | cell "$NEW_ID" 4)" = "0" ]; then
	ok "a duty nobody signed up for counts zero registered"
else
	bad "a duty nobody signed up for counts zero registered" "cell reads: $(printf '%s' "$overview" | cell "$NEW_ID" 4)"
fi
if [ "$(printf '%s' "$overview" | cell "$NEW_ID" 5)" = "0" ]; then
	ok "and has no free place to give away"
else
	bad "and has no free place to give away" "cell reads: $(printf '%s' "$overview" | cell "$NEW_ID" 5)"
fi
clean "$overview" "work duty overview with a dash and a full group"
if [ "$(s event "$NEW_ID" group_name | wc -m)" = "101" ]; then ok "a group of exactly 100 characters is stored whole"; else bad "a group of exactly 100 characters is stored whole" "$(s event "$NEW_ID" group_name | wc -m)"; fi
if [ "$(s event "$NEW_ID" description | wc -m)" = "501" ]; then ok "a description of exactly 500 characters is stored whole"; else bad "a description of exactly 500 characters is stored whole" "$(s event "$NEW_ID" description | wc -m)"; fi
clean "$exact" "work duty save exactly on the limit"

# The fields hold text, not markup. A typed angle bracket has to come back as
# itself, and the page has to show it as itself.
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/plain.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_event" \
	--data-urlencode "fg_event_id=$NEW_ID" \
	--data-urlencode "fg_event_nonce=$ENONCE" \
	--data-urlencode "fg_title=Geänderter Dienst" \
	--data-urlencode "fg_event_date=2027-03-05" \
	--data-urlencode "fg_group_name=Gruppe < Nord" \
	--data-urlencode "fg_description=Motive < 2 m, <b>fett</b>" \
	)
if [ "$(s event "$NEW_ID" group_name)" = "Gruppe < Nord" ]; then ok "an angle bracket in the group survives"; else bad "an angle bracket in the group survives" "$(s event "$NEW_ID" group_name)"; fi
if [ "$(s event "$NEW_ID" description)" = "Motive < 2 m, <b>fett</b>" ]; then ok "a typed description is stored exactly as written"; else bad "a typed description is stored exactly as written" "$(s event "$NEW_ID" description)"; fi
plainform=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events&event=$NEW_ID")
has "the typed description comes back into the form" "$plainform" "Motive &lt; 2 m, &lt;b&gt;fett&lt;/b&gt;"
hasnt "the form does not run the description through a second escape" "$plainform" "&amp;lt;"
clean "$plainform" "form after a typed description"

echo "[5c] an invalid date is refused and nothing is stored"
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/bad.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_event" \
	--data-urlencode "fg_event_id=$NEW_ID" \
	--data-urlencode "fg_event_nonce=$ENONCE" \
	--data-urlencode "fg_title=Geänderter Dienst" \
	--data-urlencode "fg_event_date=2027-02-31" \
	--data-urlencode "fg_event_time=" \
	--data-urlencode "fg_event_active=1" \
	)
bad_html=$(cat "$DIR/bad.html")
has "invalid date is reported" "$bad_html" "Bitte ein gültiges Datum"
has "nothing was saved" "$bad_html" "Es wurde nichts gespeichert."
if [ "$(s event "$NEW_ID" event_date)" = "2027-03-05" ]; then ok "previous date is kept ($(s event "$NEW_ID" event_date))"; else bad "previous date is kept" "$(s event "$NEW_ID" event_date)"; fi
clean "$bad_html" "work duty save with an invalid date"

echo "[5d] a missing nonce changes nothing"
out=$(curl -sk -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_event" \
	--data-urlencode "fg_event_id=$NEW_ID" \
	--data-urlencode "fg_event_nonce=manipuliert" \
	--data-urlencode "fg_title=Ohne Erlaubnis" \
	--data-urlencode "fg_event_date=2027-03-05" \
	)
if [ "$out" = "403" ]; then ok "a wrong nonce is refused ($out)"; else bad "a wrong nonce is refused" "$out"; fi
if [ "$(s event "$NEW_ID" title)" = "Geänderter Dienst" ]; then ok "title unchanged after a refused save"; else bad "title unchanged after a refused save" "$(s event "$NEW_ID" title)"; fi

echo "[5e] the registration list of the duty is read-only"
people=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events&event=$NEW_ID")
hasnt "the form offers no field for the registered members" "$people" 'name="fg_event_participants"'
hasnt "and no field for a list of addresses under another name" "$people" 'name="fg_participants"'
clean "$people" "work duty with its registration list"

echo "[5f] a new record is not reachable without a nonce"
out=$(curl -sk -b "$JAR" -o "$DIR/anon.html" -w '%{http_code}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_event" \
	--data-urlencode "fg_event_id=0" \
	--data-urlencode "fg_title=Ohne Sitzung" \
	--data-urlencode "fg_event_date=2027-03-05" \
	)
if [ "$out" = "302" ] || [ "$out" = "403" ]; then ok "an anonymous save does not succeed ($out)"; else bad "an anonymous save does not succeed" "$out"; fi
if [ "$(s find-event "Ohne Sitzung")" = "0" ]; then ok "no record was created anonymously"; else bad "no record was created anonymously" "created"; fi

# --- permanent deletion of a ride
echo "[6] permanent deletion of a ride"
ridedel=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-rides&ride=$RIDE_ID")
# The question is part of the link, not a paragraph above it, so it has to
# survive whatever the link is filtered through. A link whose question is
# stripped is a button that deletes without asking, and nothing on the screen
# says what it deletes.
has "the delete link asks what it deletes" "$ridedel" "return confirm("
has "and the question names the record" "$ridedel" "Diese Fahrgemeinschaft endgültig löschen?"
del_url=$(printf '%s' "$ridedel" | link "ride")
if [ -n "$del_url" ]; then ok "delete link found"; else bad "delete link found" "no link on the screen"; fi
out=$(curl -sk -b "$JAR" -L "$del_url")
has "delete notice shown" "$out" "endgültig gelöscht"
clean "$out" "ride deletion"
if [ "$(s exists-ride "$RIDE_ID")" = "0" ]; then ok "ride is gone"; else bad "ride is gone" "still there"; fi

echo "[6b] the same delete link does not work twice"
out=$(curl -sk -b "$JAR" -o "$DIR/twice.html" -w '%{http_code}' "$del_url")
if [ "$out" = "403" ] || [ "$out" = "500" ]; then ok "replayed delete link is refused ($out)"; else bad "replayed delete link is refused" "$out"; fi

# --- permanent deletion with cascade
echo "[7] permanent deletion of a work duty"
CASCADE=$(s make-event "Kaskade" "$(date -d '+30 days' +%Y-%m-%d)" 3)
# One member is signed in for the duty, so the delete question has to count a
# registration next to the two rides.
KASKADE_MEMBER=$(neu "0900" "kette@example.org" "Kette" "Probe")
s register "$CASCADE" "0900" "kette@example.org" >/dev/null
s make-ride "$CASCADE" offer "Kaskadenfahrt" Innenstadt kette@example.org published >/dev/null
s make-ride "$CASCADE" search "Kaskadenfahrt zwei" Suedstadt kette@example.org >/dev/null
if [ "$(s count-event-rides "$CASCADE")" = "2" ]; then ok "cascade source has two rides"; else bad "cascade source has two rides" "$(s count-event-rides "$CASCADE")"; fi
form=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events&event=$CASCADE")
has "the delete link itself asks what it takes" "$form" "return confirm("
has "delete question counts the rides" "$form" "2 zugehörige Fahrgemeinschaften"
# Both nouns have to agree with their own number. A duty with one member in it
# and two rides reads "1 Anmeldung und 2 zugehörige Fahrgemeinschaften"; the
# same sentence with the two numbers the other way round is what a single
# plural rule on one of them produces.
has "delete question counts the registrations" "$form" "Diesen Arbeitsdienst, 1 Anmeldung und 2 zugehörige Fahrgemeinschaften endgültig löschen?"
del_url=$(printf '%s' "$form" | link "event")
if [ -n "$del_url" ]; then ok "delete link found"; else bad "delete link found" "no link on the screen"; fi
out=$(curl -sk -b "$JAR" -L "$del_url")
has "delete notice mentions the cascade" "$out" "zugehörige"
clean "$out" "cascade deletion"
if [ "$(s count-event-rides "$CASCADE")" = "0" ]; then ok "rides of the deleted work duty are gone"; else bad "rides of the deleted work duty are gone" "$(s count-event-rides "$CASCADE")"; fi
if [ "$(s count-registrations "$CASCADE")" = "0" ]; then ok "registrations of the deleted work duty are gone"; else bad "registrations of the deleted work duty are gone" "$(s count-registrations "$CASCADE")"; fi
if [ "$(s exists-event "$CASCADE")" = "0" ]; then ok "work duty is gone"; else bad "work duty is gone" "still there"; fi
# A duty goes, the member does not. The registration is what belonged to the
# duty; the person it was made for belongs to the club.
if [ -n "$KASKADE_MEMBER" ] && [ "$KASKADE_MEMBER" -gt 0 ]; then
	if [ "$(s member "$KASKADE_MEMBER" member_no)" = "0900" ]; then
		ok "the member who was signed in is still in the club"
	else
		bad "the member who was signed in is still in the club" "$(s member "$KASKADE_MEMBER" member_no)"
	fi
else
	bad "the member who was signed in is still in the club" "the member was not created"
fi

# --- settings of the e-mails
echo "[8] settings screen"
# The suite stores a footer of its own and gives the previous one back at the
# end, so a footer that was configured by hand is not lost.
SETTINGS_BEFORE=$(s settings-json)
screen=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-settings")
has "screen renders" "$screen" "<h1>Einstellungen</h1>"
has "logo field present" "$screen" 'name="fg_logo_attachment_id"'
has "media picker script present" "$screen" "wp.media"
# The picker has to run behind the media library and behind its own buttons.
# Printed during admin_enqueue_scripts it would land in the head, where
# wp.media does not exist yet and the buttons would do nothing in silence.
has "media library is loaded" "$screen" 'id="media-views-js"'
has "picker script is attached to the media library" "$screen" 'id="media-views-js-after"'
if python3 -c "
import sys
h = sys.stdin.read()
lib = h.find('id=\"media-views-js\"')
after = h.find('id=\"media-views-js-after\"')
body = h.find('id=\"fg-logo-pick\"')
sys.exit(0 if -1 not in (lib, after, body) and body < after and lib < after else 1)
" <<< "$screen"; then
	ok "picker runs after the media library and after the buttons"
else
	bad "picker runs after the media library and after the buttons" "wrong order in the document"
fi
has "the pick button waits for the script" "$screen" 'id="fg-logo-pick" disabled'
# One field, not three. The field is asked for as what it has to be: a
# textarea, and a mandatory one, both in the same tag. Looking for the name
# alone would be answered by a hidden field, and "required" somewhere on the
# page says nothing about which field it belongs to.
has "the footer has one field" "$screen" 'id="fg-footer" name="fg_footer" rows="9" class="large-text" required'
hasnt "and no second field for the sender" "$screen" 'name="fg_footer_organisation"'
hasnt "and no third field for the contact" "$screen" 'name="fg_footer_contact"'
hasnt "and no fourth field for the legal notice" "$screen" 'name="fg_footer_legal"'
hasnt "no field offers a path to a file" "$screen" "logo_path"
hasnt "no field offers a url for the logo" "$screen" "logo_url"
has "preview link present" "$screen" "action=fg_mail_preview"
has "preview link carries a nonce" "$screen" "action=fg_mail_preview&#038;_wpnonce="
clean "$screen" "settings screen"

echo "[8b] the mandatory footer refuses an empty one"
SNONCE=$(val /dev/stdin fg_settings_nonce <<< "$screen")
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/settings-bad.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_settings" \
	--data-urlencode "fg_settings_nonce=$SNONCE" \
	--data-urlencode "fg_logo_attachment_id=0" \
	--data-urlencode "fg_footer=   ")
refused=$(cat "$DIR/settings-bad.html")
has "the missing field is named" "$refused" "weil die Fußzeile fehlt"
has "it says that nothing was saved" "$refused" "Es wurde nichts gespeichert"
has "it returns to the settings screen" "$out" "page=fahrgemeinschaften-settings"
# Compared with the state of before the run and not with "empty": a footer that
# was configured by hand has to stay untouched as well.
if [ "$(s settings-json)" = "$SETTINGS_BEFORE" ]; then ok "nothing was stored"; else bad "nothing was stored" "$(s settings-json)"; fi
clean "$refused" "settings save with an empty footer"

echo "[8c] a complete footer is stored"
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/settings-saved.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_settings" \
	--data-urlencode "fg_settings_nonce=$SNONCE" \
	--data-urlencode "fg_logo_attachment_id=0" \
	--data-urlencode "fg_footer=Musterverein e.V.
Musterstraße 1

info@angeln.example.org

Angaben gemäß § 5 TMG: Musterverein e.V.")
stored=$(cat "$DIR/settings-saved.html")
has "save confirms" "$stored" "Die Einstellungen wurden gespeichert."
# The whole footer is compared in one go, line break and blank line included.
# Three checks of three fields could each pass while the sections came back in
# the wrong order or the blank line between them was lost.
if [ "$(s settings footer | tr '\n' ',')" = "Musterverein e.V.,Musterstraße 1,,info@angeln.example.org,,Angaben gemäß § 5 TMG: Musterverein e.V.," ]; then
	ok "the footer is stored as it was typed, blank lines and all"
else
	bad "the footer is stored as it was typed, blank lines and all" "$(s settings footer | tr '\n' ',')"
fi
# The three names of the version before must be gone from the option, or a
# later read would find them and join them to the one field.
if printf '%s' "$(s settings-json)" | grep -q "footer_"; then
	bad "the option holds nothing but the one field" "$(s settings-json)"
else
	ok "the option holds nothing but the one field"
fi
if [ "$(s settings logo_attachment_id)" = "0" ]; then ok "no logo stored"; else bad "no logo stored" "$(s settings logo_attachment_id)"; fi
AFTER_SAVE=$(s settings-json)
clean "$stored" "settings save"

echo "[8d] a wrong nonce changes nothing"
out=$(curl -sk -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_settings" \
	--data-urlencode "fg_settings_nonce=manipuliert" \
	--data-urlencode "fg_footer=Ohne Erlaubnis")
if [ "$out" = "403" ]; then ok "a wrong nonce is refused ($out)"; else bad "a wrong nonce is refused" "$out"; fi
# Compared with the values of the save before and not with the values of the
# run before it, which the site may have had configured by hand.
if [ "$(s settings-json)" = "$AFTER_SAVE" ]; then ok "the stored settings are unchanged"; else bad "the stored settings are unchanged" "$(s settings-json)"; fi

echo "[8e] the preview shows the stored footer"
preview_url=$(printf '%s' "$screen" | python3 -c "
import re, sys
html = sys.stdin.read()
m = re.search(r'href=\"([^\"]*action=fg_mail_preview[^\"]*)\"', html)
print(m.group(1).replace('&#038;', '&').replace('&amp;', '&') if m else '')")
if [ -n "$preview_url" ]; then ok "preview link extracted"; else bad "preview link extracted" "no link"; fi
preview=$(curl -sk -b "$JAR" "$preview_url")
has "preview is a full html document" "$preview" "<!DOCTYPE html>"
has "preview carries the layout" "$preview" 'class="body-wrap"'
has "preview carries the stored footer" "$preview" "Musterverein e.V."
has "preview carries the stored contact" "$preview" "info@angeln.example.org"
has "preview carries the stored legal notice" "$preview" "§ 5 TMG"
has "preview escapes a visitor value" "$preview" "Müller &amp; Söhne"
hasnt "preview keeps the raw ampersand" "$preview" "Müller & Söhne"
hasnt "preview loads no foreign address" "$preview" 'src="http'
hasnt "preview has no unresolved token" "$preview" "{{"
# The blank line the club typed has to survive into the mail as a blank line,
# not as a single break: it is what tells the three sections apart. nl2br puts
# a newline behind every break, so a blank line is two breaks and two newlines,
# and a search that leaves the newlines out would find nothing and pass for
# another reason.
pruef "$preview" "preview keeps the blank line between the sections" "
import sys
h = sys.stdin.read()
# nl2br setzt hinter jeden Umbruch einen Zeilenumbruch. Eine Leerzeile ist
# deshalb zwei Umbrueche und zwei Zeilenumbrueche — eine Suche, die die
# Zeilenumbrueche weglässt, fände nichts und wäre aus einem anderen Grund grün.
gesucht = 'Musterstraße 1<br />\n<br />\ninfo@angeln.example.org'
if gesucht not in h:
    print('die Abschnitte stehen nicht mit einer Leerzeile getrennt nebeneinander')
    sys.exit(1)
sys.exit(0)
" "im HTML der Vorschau"
pruef "$preview" "the copy is written at 14px and the footer at 12px" "
import re, sys
h = sys.stdin.read()
def region(von, bis):
    return h.split('<!-- start %s -->' % von, 1)[-1].split('<!-- end %s -->' % bis, 1)[0]
def zelle(teil):
    tags = re.findall(r'<td\b[^>]*>', teil)
    return tags[0] if tags else ''
def groesse(tag):
    gefunden = re.findall(r'font-size:\s*([0-9.]+)\s*(px|em)\b', tag)
    return ''.join(gefunden[-1]) if gefunden else ''
# Eine Größe in em hängt an dem, was die Zelle darüber sagt, und der Körper
# sagt 14px: aus 0.8em wurden 11.2px, und das stand an Inhalt und Fußzeile
# gleichermaßen. Die Regel im Style-Sheet bleibt außen vor, denn p ist dort
# mit Absicht 1em, und 1em von 14px ist 14px. Gelesen werden nur die Zellen,
# weil nur sie entscheiden.
for tag in re.findall(r'<td\b[^>]*>', h):
    if re.search(r'font-size:\s*[0-9.]+em', tag):
        print('eine Zelle rechnet ihre Schriftgröße um: ' + str(groesse(tag)))
        sys.exit(1)
inhalt = groesse(zelle(region('copy', 'copy')))
fuss = groesse(zelle(region('footer text', 'footer text')))
if inhalt != '14px':
    print('der Inhalt steht in ' + (inhalt or 'keiner festgelegten Größe'))
    sys.exit(1)
if fuss != '12px':
    print('die Fußzeile steht in ' + (fuss or 'keiner festgelegten Größe'))
    sys.exit(1)
sys.exit(0)
" "der Inhalt oder die Fußzeile steht in einer anderen Größe"
pruef "$preview" "the copy is in the colour of the body" "
import re, sys
h = sys.stdin.read()
teil = h.split('<!-- start copy -->', 1)[-1].split('<!-- end copy -->', 1)[0]
# Das Grau der Fußzeile stand an dem Container des Inhalts und der Text hat es
# geerbt. Die Zelle mit dem Text nennt die Farbe des Körpers jetzt selbst, damit
# die beiden nicht wieder auseinanderlaufen können. Gelesen wird wie beim
# Innenabstand aus dem style-Attribut, und die letzte Angabe zählt.
gefunden = None
for tag in re.findall(r'<td\b[^>]*>', teil):
    stil = re.search(r'style=\"([^\"]*)\"', tag)
    if not stil:
        continue
    for stueck in stil.group(1).split(';'):
        if ':' not in stueck:
            continue
        k, _, w = stueck.partition(':')
        if k.strip() == 'color':
            gefunden = w.strip()
if gefunden != '#111111':
    print('die Zelle mit dem Text nennt ' + str(gefunden))
    sys.exit(1)
sys.exit(0)
" "die Zelle mit dem Text nennt die Farbe des Körpers nicht"
pruef "$preview" "the headline and the copy touch, no bar of grey between them" "
import re, sys
h = sys.stdin.read()
def region(von, bis):
    return h.split('<!-- start %s -->' % von, 1)[-1].split('<!-- end %s -->' % bis, 1)[0]
def zellen(teil):
    return re.findall(r'<td\b[^>]*>', teil)
def eigenschaft(tag, name):
    # Der Wert wird aus dem style-Attribut gelesen und nicht aus dem ganzen
    # Tag: die letzte Angabe eines Tags trägt das schließende Anführungszeichen
    # und den spitzen Klammer mit, und beides muss weg, bevor verglichen wird.
    # Dann Angabe für Angabe, und die letzte zählt, wie im Browser. Ein Muster
    # mit einer Look-Ahead und etwas Variablem davor kann das nicht: die Variable
    # kann nichts matchen, dann sieht die Look-Ahead das Leerzeichen vor dem
    # Wort und gelingt, und jede Regel mit der Eigenschaft besteht.
    stil = re.search(r'style=\"([^\"]*)\"', tag)
    if not stil:
        return None
    gefunden = None
    for stueck in stil.group(1).split(';'):
        if ':' not in stueck:
            continue
        k, _, w = stueck.partition(':')
        if k.strip() == name:
            gefunden = w.strip()
    return gefunden
def weiss(teil):
    return [t for t in zellen(teil) if '#ffffff' in t]
# Der graue Grund zeigt überall dort, wo keine weiße Box ist. Keiner der beiden
# Container darf deshalb etwas einrücken: sonst steht ein Balken zwischen den
# weißen Boxen, und die Boxen selbst wären auf einem schmalen Fenster nicht
# gleich breit, weil sie unterschiedlich weit eingerückt wären. Die Zellen der
# bedingten Outlook-Ausgabe tragen kein style und werden mitgezählt, sonst
# hinge die Aussage an einem Konstrukt, das nur ein Client sieht.
for bezeichnung, zone in (('des Inhalts', region('copy block', 'copy')), ('der Überschrift', region('hero', 'hero'))):
    for tag in zellen(zone):
        if '#ffffff' in tag:
            continue
        p = eigenschaft(tag, 'padding')
        if p not in (None, '0'):
            print('der Container ' + bezeichnung + ' trägt padding: ' + str(p))
            sys.exit(1)
# Die weiße Box der Überschrift darf an ihrem unteren Ende keinen Abstand
# tragen, sonst steht derselbe Balken am anderen Ende derselben Kante.
held = weiss(region('hero', 'hero'))
if not held:
    print('die Überschrift hat keine weiße Box')
    sys.exit(1)
unten = (eigenschaft(held[0], 'padding') or '').split()
if not unten or unten[-1] not in ('0', '0px'):
    print('die weiße Box der Überschrift trägt unten ' + str(unten))
    sys.exit(1)
# Und die weiße Box des Inhalts muss ihren eigenen Abstand behalten. Er ist
# das, was den Abstand des Containers ersetzt hat: ohne ihn stünde der Text
# direkt an der Kante, und eine Prüfung, die nur nach dem Container sieht,
# hätte das nicht bemerkt.
innen = weiss(region('copy', 'copy'))
if not innen:
    print('der Inhalt hat keine weiße Box')
    sys.exit(1)
p = eigenschaft(innen[0], 'padding')
if not p or p == '0':
    print('die weiße Box des Inhalts trägt keinen eigenen Abstand: ' + str(p))
    sys.exit(1)
sys.exit(0)
" "eine Box lässt den grauen Grund sichtbar, oder die beiden weißen Boxen unterscheiden sich in der Breite"
clean "$preview" "mail preview"

echo "[8f] the preview needs a nonce"
out=$(curl -sk -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/wp-admin/admin-post.php?action=fg_mail_preview")
if [ "$out" = "403" ]; then ok "the preview is refused without a nonce ($out)"; else bad "the preview is refused without a nonce" "$out"; fi

# --- the member administration
echo "[9] the member administration"
MEMBERS="$BASE/wp-admin/admin.php?page=fahrgemeinschaften-members"
members=$(curl -sk -b "$JAR" "$MEMBERS")
has "screen renders" "$members" ">Mitglieder</h1>"
has "a member can be added" "$members" "Neues Mitglied"
has "the list has a column for the number" "$members" ">Mitgliedsnummer<"
has "the list has a column for the address" "$members" ">E-Mail-Adresse<"
has "the list says how many duties a member is in" "$members" ">Angemeldete Dienste<"
has "the import form is on the same screen" "$members" 'name="fg_member_file"'
has "the import form carries a nonce" "$members" 'name="fg_import_nonce"'
has "the search field is there" "$members" 'name="fg_member_search"'
clean "$members" "member list"

# A member has four fields and nothing else. A fifth column in the form would
# be a fifth thing the club has to keep in step with its own administration.
#
# Each field is asked for as what it has to be: a text input, and a required
# one. Looking for the name alone would be answered by a hidden field, and
# "required" somewhere on the page says nothing about which field it belongs to.
s delete-member "$(s member-by-no "0700" id)" > /dev/null 2>&1
form=$(curl -sk -b "$JAR" "$MEMBERS&member=0")
for feldangabe in 'fg_member_no:text' 'fg_member_email:email' 'fg_first_name:text' 'fg_last_name:text'; do
	feldname=${feldangabe%%:*}
	feldart=${feldangabe##*:}
	if [ "$(printf '%s' "$form" | feld "$feldname" "['type=\"$feldart\"', 'required']")" = "ok" ]; then
		ok "the form has a required $feldart field $feldname"
	else
		bad "the form has a required $feldart field $feldname" "$(printf '%s' "$form" | feld "$feldname" "['type=\"$feldart\"', 'required']")"
	fi
done
# The browser is told the same length the column has. An address field that
# offers 254 characters while the column holds 190 invites a visitor to type
# something that is checked, accepted and then dropped by the schema.
if [ "$(printf '%s' "$form" | feld fg_member_email "['maxlength=\"190\"']")" = "ok" ]; then
	ok "the address field stops where the column does"
else
	bad "the address field stops where the column does" "$(printf '%s' "$form" | feld fg_member_email "['maxlength=\"190\"']")"
fi
for unerwartet in fg_member_address fg_member_street fg_member_birthday fg_member_phone fg_member_name; do
	hasnt "the form has no field named $unerwartet" "$form" "name=\"$unerwartet\""
done
has "the form carries a nonce" "$form" 'name="fg_member_nonce"'
clean "$form" "member form"

MNONCE=$(val /dev/stdin fg_member_nonce <<< "$form")
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/member-new.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_member" \
	--data-urlencode "fg_member_id=0" \
	--data-urlencode "fg_member_nonce=$MNONCE" \
	--data-urlencode "fg_member_no=0700" \
	--data-urlencode "fg_member_email=neu@example.org" \
	--data-urlencode "fg_first_name=Neu" \
	--data-urlencode "fg_last_name=Probe")
neu=$(cat "$DIR/member-new.html")
has "save confirms the new member" "$neu" "Das Mitglied wurde angelegt."
NEU_ID=$(s member-by-no "0700" id)
if [ "$NEU_ID" -gt 0 ]; then ok "the new member is stored ($NEU_ID)"; else bad "the new member is stored" "$NEU_ID"; fi
if [ "$(s member "$NEU_ID" email)" = "neu@example.org" ]; then ok "the address is stored"; else bad "the address is stored" "$(s member "$NEU_ID" email)"; fi
# The number is a text, not a count, so a leading zero has to survive the round
# trip. A club whose numbers are 700 and 0700 has two members.
if [ "$(s member "$NEU_ID" member_no)" = "0700" ]; then ok "the leading zero of the number survives"; else bad "the leading zero of the number survives" "$(s member "$NEU_ID" member_no)"; fi
has "the new member is in the list" "$(curl -sk -b "$JAR" "$MEMBERS")" "0700"
clean "$neu" "member create"

# The two keys are unique, and a refusal says which of the two it was. A
# message that only says "not saved" leaves the club guessing which field to
# change.
newform=$(curl -sk -b "$JAR" "$MEMBERS&member=0")
MNONCE=$(val /dev/stdin fg_member_nonce <<< "$newform")
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/member-dup.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_member" \
	--data-urlencode "fg_member_id=0" \
	--data-urlencode "fg_member_nonce=$MNONCE" \
	--data-urlencode "fg_member_no=0700" \
	--data-urlencode "fg_member_email=anders@example.org" \
	--data-urlencode "fg_first_name=Doppelt" \
	--data-urlencode "fg_last_name=Probe")
dup=$(cat "$DIR/member-dup.html")
has "a taken number is named" "$dup" "Die Mitgliedsnummer 0700 ist bereits vergeben"
has "and it says nothing was saved" "$dup" "Bitte wähle eine andere"
if [ "$(s member-by-no "0700" email)" = "neu@example.org" ]; then ok "the member that holds the number is untouched"; else bad "the member that holds the number is untouched" "$(s member-by-no "0700" email)"; fi
clean "$dup" "member with a taken number"

out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/member-mail.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_member" \
	--data-urlencode "fg_member_id=0" \
	--data-urlencode "fg_member_nonce=$MNONCE" \
	--data-urlencode "fg_member_no=0701" \
	--data-urlencode "fg_member_email=neu@example.org" \
	--data-urlencode "fg_first_name=Doppelt" \
	--data-urlencode "fg_last_name=Probe")
mail=$(cat "$DIR/member-mail.html")
# The address is the key of the registration, so it has to belong to exactly one
# member. The message names the member that holds it, because the person at the
# keyboard usually knows that and does not know the numbers.
has "a taken address names the member that holds it" "$mail" "gehört bereits zu Mitglied 0700"
has "and it says why" "$mail" "Jede E-Mail-Adresse gehört zu genau einem Mitglied"
if [ "$(s member-by-no "0701" id)" = "missing" ]; then ok "no second member was written"; else bad "no second member was written" "$(s member-by-no "0701" id)"; fi
clean "$mail" "member with a taken address"

# The four fields are required, and the form marks them as such; a request that
# leaves one out anyway has to be refused on the server, not only in the
# browser.
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/member-leer.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_member" \
	--data-urlencode "fg_member_id=0" \
	--data-urlencode "fg_member_nonce=$MNONCE" \
	--data-urlencode "fg_member_no=0702" \
	--data-urlencode "fg_member_email=leer@example.org" \
	--data-urlencode "fg_first_name=" \
	--data-urlencode "fg_last_name=Probe")
leer=$(cat "$DIR/member-leer.html")
has "a missing name is named" "$leer" "Bitte Vor- und Nachnamen eingeben"
if [ "$(s member-by-no "0702" id)" = "missing" ]; then ok "nothing was written"; else bad "nothing was written" "$(s member-by-no "0702" id)"; fi
clean "$leer" "member without a name"

out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/member-uns.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_member" \
	--data-urlencode "fg_member_id=0" \
	--data-urlencode "fg_member_nonce=$MNONCE" \
	--data-urlencode "fg_member_no=0703" \
	--data-urlencode "fg_member_email=keine-mail" \
	--data-urlencode "fg_first_name=Ohne" \
	--data-urlencode "fg_last_name=Mail")
uns=$(cat "$DIR/member-uns.html")
has "an address that is not one is named" "$uns" "Bitte eine gültige E-Mail-Adresse eingeben"
if [ "$(s member-by-no "0703" id)" = "missing" ]; then ok "nothing was written"; else bad "nothing was written" "$(s member-by-no "0703" id)"; fi
clean "$uns" "member with an address that is not one"

# An address can be a proper address and still be too long for the column. It is
# 200 characters here, and 254 characters are legal, so a check that only asks
# "is it an address" lets it through to a column of 190 — where the insert is
# refused by the schema and the club is told nothing more than "could not be
# created". The message has to be the one that names the field.
LANGE_MAIL=$(python3 -c "print('a' * 185 + '@' + 'b' * 10 + '.org')")
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/member-lang.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_member" \
	--data-urlencode "fg_member_id=0" \
	--data-urlencode "fg_member_nonce=$MNONCE" \
	--data-urlencode "fg_member_no=0705" \
	--data-urlencode "fg_member_email=$LANGE_MAIL" \
	--data-urlencode "fg_first_name=Lang" \
	--data-urlencode "fg_last_name=Adresse")
lang=$(cat "$DIR/member-lang.html")
has "an address longer than the column is named as the address" "$lang" "Bitte eine gültige E-Mail-Adresse eingeben"
hasnt "and it is not the vague message" "$lang" "Das Mitglied konnte nicht angelegt werden"
if [ "$(s member-by-no "0705" id)" = "missing" ]; then ok "and no member was written for it"; else bad "and no member was written for it" "$(s member-by-no "0705" id)"; fi
clean "$lang" "member with an address longer than the column"

# The four fields come back into the form, and a change is stored.
edit=$(curl -sk -b "$JAR" "$MEMBERS&member=$NEU_ID")
has "the form is filled with the number" "$edit" 'value="0700"'
has "the form is filled with the address" "$edit" 'value="neu@example.org"'
has "the form is filled with the first name" "$edit" 'value="Neu"'
has "the form is filled with the last name" "$edit" 'value="Probe"'
ENONCE2=$(val /dev/stdin fg_member_nonce <<< "$edit")
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/member-edit.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_member" \
	--data-urlencode "fg_member_id=$NEU_ID" \
	--data-urlencode "fg_member_nonce=$ENONCE2" \
	--data-urlencode "fg_member_no=0700" \
	--data-urlencode "fg_member_email=neuanders@example.org" \
	--data-urlencode "fg_first_name=Neu" \
	--data-urlencode "fg_last_name=Geaendert")
geandert=$(cat "$DIR/member-edit.html")
has "save confirms the change" "$geandert" "Das Mitglied wurde gespeichert."
if [ "$(s member "$NEU_ID" email)" = "neuanders@example.org" ]; then ok "the new address is stored"; else bad "the new address is stored" "$(s member "$NEU_ID" email)"; fi
if [ "$(s member "$NEU_ID" last_name)" = "Geaendert" ]; then ok "the new name is stored"; else bad "the new name is stored" "$(s member "$NEU_ID" last_name)"; fi
clean "$geandert" "member edit"

# A wrong nonce changes nothing.
out=$(curl -sk -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_member" \
	--data-urlencode "fg_member_id=$NEU_ID" \
	--data-urlencode "fg_member_nonce=manipuliert" \
	--data-urlencode "fg_member_no=0700" \
	--data-urlencode "fg_member_email=ohne@example.org" \
	--data-urlencode "fg_first_name=Ohne" \
	--data-urlencode "fg_last_name=Erlaubnis")
if [ "$out" = "403" ]; then ok "a wrong nonce is refused ($out)"; else bad "a wrong nonce is refused" "$out"; fi
if [ "$(s member "$NEU_ID" email)" = "neuanders@example.org" ]; then ok "the address is unchanged after a refused save"; else bad "the address is unchanged after a refused save" "$(s member "$NEU_ID" email)"; fi

# The member of [5] is registered for a duty of this run, so the removal has to
# take the registration with it.
LOESCH=$(s make-event "Loeschprobe" "$(date -d '+30 days' +%Y-%m-%d)" 2)
s register "$LOESCH" "0700" "neuanders@example.org" >/dev/null
if [ "$(s count-registrations "$LOESCH")" = "1" ]; then ok "the member of the list is signed in for a duty"; else bad "the member of the list is signed in for a duty" "$(s count-registrations "$LOESCH")"; fi
del=$(curl -sk -b "$JAR" "$MEMBERS&member=$NEU_ID")
has "the delete link asks what it takes" "$del" "return confirm("
del_url=$(printf '%s' "$del" | link "member")
if [ -n "$del_url" ]; then ok "delete link found"; else bad "delete link found" "no link on the screen"; fi
# The question has to say what goes with the member. A member who is signed in
# for three duties leaves three places behind, and the person clicking the
# button is the one who has to know that.
if [ -n "$del_url" ]; then
	has "the delete question names the registration" "$del" "1 Anmeldung"
	out=$(curl -sk -b "$JAR" -L "$del_url")
	has "delete notice shown" "$out" "endgültig gelöscht"
	clean "$out" "member deletion"
fi
if [ "$(s member "$NEU_ID" id)" = "missing" ]; then ok "the member is gone"; else bad "the member is gone" "still there"; fi
if [ "$(s count-registrations "$LOESCH")" = "0" ]; then ok "its registration went with it"; else bad "its registration went with it" "$(s count-registrations "$LOESCH")"; fi
s delete-event "$LOESCH" >/dev/null

# --- the member import
echo "[10] importing the member list"
IMPORT_CSV="$DIR/mitglieder.csv"
screen=$(curl -sk -b "$JAR" "$MEMBERS")
INONCE=$(val /dev/stdin fg_import_nonce <<< "$screen")

# The first import is the export of the whole list, read back. It has to change
# nothing at all, and it is the only state in which the report can honestly say
# that everybody in the database stood in the file. An import that rewrote
# every row on every run would still fill this file in correctly and would
# still report the right names, so what is measured here is the counts.
s members-csv > "$IMPORT_CSV"
MITGLIEDER_VORHER=$(s count-members)
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/import1.html" -w '%{url_effective}' \
	-X POST "$BASE/wp-admin/admin-post.php" \
	-F "action=fg_import_members" \
	-F "fg_import_nonce=$INONCE" \
	-F "fg_member_file=@$IMPORT_CSV;type=text/csv")
erster=$(cat "$DIR/import1.html")
has "the import is confirmed" "$erster" "Der Import wurde verarbeitet."
# The report is the answer to the three questions the club asked: what is new,
# what changed, and who is in the database but not in the file. Every reading of
# it goes through report(), never over the whole screen: the screen lists every
# member as well, so a sentence of the report could be missing and the check
# would still find its words in the list.
ersterbericht=$(printf '%s' "$erster" | report)
has "the report counts the rows it read" "$ersterbericht" "$MITGLIEDER_VORHER Zeilen gelesen"
has "the import of the whole list creates nobody" "$ersterbericht" "0 neue Mitglieder angelegt"
has "and changes nobody" "$ersterbericht" "0 Mitglieder geändert"
has "and says they are all unchanged" "$ersterbericht" "$MITGLIEDER_VORHER unverändert"
has "and that nobody is missing from the file" "$ersterbericht" "Alle in der Datenbank vorhandenen Mitglieder standen auch in der Datei"
if [ "$(s count-members)" = "$MITGLIEDER_VORHER" ]; then ok "the member count is the same after it"; else bad "the member count is the same after it" "$(s count-members) was $MITGLIEDER_VORHER"; fi
clean "$erster" "import report of the whole list read back"

# A file with two members the database does not have.
printf 'Mitgliedsnummer;E-Mail-Adresse;Vorname;Nachname\n0801;import1@example.org;Import;Eins\n0802;import2@example.org;Import;Zwei\n' > "$IMPORT_CSV"
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/import2.html" -w '%{url_effective}' \
	-X POST "$BASE/wp-admin/admin-post.php" \
	-F "action=fg_import_members" \
	-F "fg_import_nonce=$INONCE" \
	-F "fg_member_file=@$IMPORT_CSV;type=text/csv")
zweites=$(cat "$DIR/import2.html")
zweitesbericht=$(printf '%s' "$zweites" | report)
has "the report counts the rows it read" "$zweitesbericht" "2 Zeilen gelesen"
has "the report counts the new members" "$zweitesbericht" "2 neue Mitglieder angelegt"
has "the report says nothing changed" "$zweitesbericht" "0 Mitglieder geändert"
# The three fixture members are in the database and not in this file, so the
# list of who is missing has to name them. That list is the whole point of the
# report: without it "2 angelegt" could just as well mean "2 of forty". It is
# read out of the report and not out of the page, because 0042 stands on the
# page as a row of the member list as well, and a check that finds it there says
# nothing about the report.
has "the report names a member that is not in the file" "$zweitesbericht" "0042"
has "and the name that goes with it" "$zweitesbericht" "Anton"
has "and says how many are missing" "$zweitesbericht" "stehen in der Datenbank, aber nicht in der Datei"
hasnt "and the two new members are not in the missing list" "$zweitesbericht" "0801"
if [ "$(s member-by-no "0801" id)" != "missing" ]; then ok "the first row of the file is in the database"; else bad "the first row of the file is in the database" "missing"; fi
if [ "$(s member-by-no "0802" first_name)" = "Import" ]; then ok "and its names land in the right columns"; else bad "and its names land in the right columns" "$(s member-by-no "0802" first_name)"; fi
clean "$zweites" "import report of a file with new members"

# The same file a second time changes nothing and says so. An import that
# reported "geändert" here would have rewritten every row on every run and
# would have left an updated_at behind that means nothing.
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/import3.html" -w '%{url_effective}' \
	-X POST "$BASE/wp-admin/admin-post.php" \
	-F "action=fg_import_members" \
	-F "fg_import_nonce=$INONCE" \
	-F "fg_member_file=@$IMPORT_CSV;type=text/csv")
drittes=$(cat "$DIR/import3.html")
drittesbericht=$(printf '%s' "$drittes" | report)
has "the second run creates nobody" "$drittesbericht" "0 neue Mitglieder angelegt"
has "the second run changes nobody" "$drittesbericht" "0 Mitglieder geändert"
has "and it says they are unchanged" "$drittesbericht" "2 unverändert"
clean "$drittes" "import report of the same file again"

# A file with a changed name updates that member and leaves the other alone.
printf 'Mitgliedsnummer;E-Mail-Adresse;Vorname;Nachname\n0801;import1@example.org;Import;EinsGeaendert\n0802;import2@example.org;Import;Zwei\n' > "$IMPORT_CSV"
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/import4.html" -w '%{url_effective}' \
	-X POST "$BASE/wp-admin/admin-post.php" \
	-F "action=fg_import_members" \
	-F "fg_import_nonce=$INONCE" \
	-F "fg_member_file=@$IMPORT_CSV;type=text/csv")
viertes=$(cat "$DIR/import4.html")
viertesbericht=$(printf '%s' "$viertes" | report)
has "the report counts the changed member" "$viertesbericht" "1 Mitglieder geändert"
has "and the unchanged one" "$viertesbericht" "1 unverändert"
if [ "$(s member-by-no "0801" last_name)" = "EinsGeaendert" ]; then ok "the changed name is stored"; else bad "the changed name is stored" "$(s member-by-no "0801" last_name)"; fi
if [ "$(s member-by-no "0802" last_name)" = "Zwei" ]; then ok "the other name is kept"; else bad "the other name is kept" "$(s member-by-no "0802" last_name)"; fi
clean "$viertes" "import report of a changed file"

# The third question, on its own: who is in the database but not in the file.
# The club has to be able to see that list, because an import that only ever
# adds can otherwise never be used to remove a member who has left.
printf 'Mitgliedsnummer;E-Mail-Adresse;Vorname;Nachname\n0802;import2@example.org;Import;Zwei\n' > "$IMPORT_CSV"
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/import5.html" -w '%{url_effective}' \
	-X POST "$BASE/wp-admin/admin-post.php" \
	-F "action=fg_import_members" \
	-F "fg_import_nonce=$INONCE" \
	-F "fg_member_file=@$IMPORT_CSV;type=text/csv")
funftes=$(cat "$DIR/import5.html")
funftesbericht=$(printf '%s' "$funftes" | report)
# 0801 is in the database and stands on the screen as a row of the member list,
# so both of these are read out of the report. Otherwise they hold for a report
# that names nobody at all.
has "the report names the number of a missing member" "$funftesbericht" "0801"
has "and the name that goes with it" "$funftesbericht" "EinsGeaendert"
has "and its address" "$funftesbericht" "import1@example.org"
has "it says nobody was deleted" "$funftesbericht" "Ein Import entfernt nie jemanden"
if [ "$(s member-by-no "0801" id)" != "missing" ]; then ok "the member missing from the file is still there"; else bad "the member missing from the file is still there" "gone"; fi
clean "$funftes" "import report with a member missing from the file"

# A file the parser cannot read is refused with the line it is on, and the
# whole file is refused: a half-imported member list is worse than none.
KOPF='Mitgliedsnummer;E-Mail-Adresse;Vorname;Nachname'
printf '%s\n0803;kaputt\n' "$KOPF" > "$IMPORT_CSV"
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/import6.html" -w '%{url_effective}' \
	-X POST "$BASE/wp-admin/admin-post.php" \
	-F "action=fg_import_members" \
	-F "fg_import_nonce=$INONCE" \
	-F "fg_member_file=@$IMPORT_CSV;type=text/csv")
sechstes=$(cat "$DIR/import6.html")
has "a short row is named by its line" "$sechstes" "Zeile 2 hat 2 Felder"
has "and it says nothing was imported" "$sechstes" "Es wurde nichts importiert"
if [ "$(s member-by-no "0803" id)" = "missing" ]; then ok "the refused file wrote nothing"; else bad "the refused file wrote nothing" "$(s member-by-no "0803" id)"; fi
clean "$sechstes" "refused import"

# The good row in front of the bad one is not written either. Half a file is not
# a state a member list should ever be in, and a report that said "2 von 3
# Zeilen übernommen" would be an invitation to do it again next week.
printf '%s\n0804;gueltig@example.org;Gueltig;Probe\n;leer@example.org;Leer;Probe\n' "$KOPF" > "$IMPORT_CSV"
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/import7.html" -w '%{url_effective}' \
	-X POST "$BASE/wp-admin/admin-post.php" \
	-F "action=fg_import_members" \
	-F "fg_import_nonce=$INONCE" \
	-F "fg_member_file=@$IMPORT_CSV;type=text/csv")
siebtes=$(cat "$DIR/import7.html")
has "a row without a number is named by its line" "$siebtes" "In Zeile 3 fehlt die Mitgliedsnummer"
if [ "$(s member-by-no "0804" id)" = "missing" ]; then ok "the good row in front of it was not written either"; else bad "the good row in front of it was not written either" "$(s member-by-no "0804" id)"; fi
clean "$siebtes" "refused import with a good row in front"

# A file without the column the plugin reads is named by its name, because the
# club has to know which column to rename in their export.
printf 'Nummer;Vorname;Nachname\n0805;falsch@example.org;Falsch;Probe\n' > "$IMPORT_CSV"
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/import8.html" -w '%{url_effective}' \
	-X POST "$BASE/wp-admin/admin-post.php" \
	-F "action=fg_import_members" \
	-F "fg_import_nonce=$INONCE" \
	-F "fg_member_file=@$IMPORT_CSV;type=text/csv")
achtes=$(cat "$DIR/import8.html")
has "the missing column is named" "$achtes" "fehlt: E-Mail-Adresse"
has "and nothing was imported" "$achtes" "Es wurde nichts importiert"
if [ "$(s member-by-no "0805" id)" = "missing" ]; then ok "and no row of the file was written"; else bad "and no row of the file was written" "$(s member-by-no "0805" id)"; fi
clean "$achtes" "import with the wrong header"

# The short names of the columns are read on purpose. A club that exports
# "Nummer" and "Mail" does not have to rename anything for this import, so a
# file it exports must not be refused over the names.
printf 'Nummer;Mail;Vorname;Nachname\n0806;kurz@example.org;Kurz;Form\n' > "$IMPORT_CSV"
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/import9.html" -w '%{url_effective}' \
	-X POST "$BASE/wp-admin/admin-post.php" \
	-F "action=fg_import_members" \
	-F "fg_import_nonce=$INONCE" \
	-F "fg_member_file=@$IMPORT_CSV;type=text/csv")
neuntes=$(cat "$DIR/import9.html")
has "a header with the short names is read" "$neuntes" "1 neue Mitglieder angelegt"
if [ "$(s member-by-no "0806" email)" = "kurz@example.org" ]; then ok "and the address lands in its column"; else bad "and the address lands in its column" "$(s member-by-no "0806" email)"; fi
clean "$neuntes" "import with the short header names"
s delete-member "$(s member-by-no 0806 id)" > /dev/null

# An address longer than the column is not a valid address for this plugin, and
# the export of another system does not know that. It has to be refused like
# every other bad row, with its line named — and the whole file with it, because
# a member list that took the good rows and dropped this one is a list the club
# believes to be complete.
printf '%s\n0807;%s;Zu;Lang\n' "$KOPF" "$LANGE_MAIL" > "$IMPORT_CSV"
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/import10.html" -w '%{url_effective}' \
	-X POST "$BASE/wp-admin/admin-post.php" \
	-F "action=fg_import_members" \
	-F "fg_import_nonce=$INONCE" \
	-F "fg_member_file=@$IMPORT_CSV;type=text/csv")
zehntes=$(cat "$DIR/import10.html")
has "an address longer than the column is named" "$zehntes" "In Zeile 2 steht keine gültige E-Mail-Adresse"
has "and nothing of that file was imported" "$zehntes" "Es wurde nichts importiert"
if [ "$(s member-by-no "0807" id)" = "missing" ]; then ok "and the row was not written behind the message"; else bad "the refused row was not written" "$(s member-by-no "0807" id)"; fi
clean "$zehntes" "import with an address longer than the column"

# A file that is not a file at all is refused by the upload, not by the parser.
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/import-no-file.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_import_members" \
	--data-urlencode "fg_import_nonce=$INONCE")
ohne=$(cat "$DIR/import-no-file.html")
has "an import without a file is named" "$ohne" "Es wurde keine Datei gewählt"
clean "$ohne" "import without a file"

# A wrong nonce changes nothing.
out=$(curl -sk -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/wp-admin/admin-post.php" \
	-F "action=fg_import_members" \
	-F "fg_import_nonce=manipuliert" \
	-F "fg_member_file=@$IMPORT_CSV;type=text/csv")
if [ "$out" = "403" ]; then ok "an import with a wrong nonce is refused ($out)"; else bad "an import with a wrong nonce is refused" "$out"; fi
if [ "$(s member-by-no "0805" id)" = "missing" ]; then ok "and it wrote nothing"; else bad "and it wrote nothing" "$(s member-by-no "0805" id)"; fi

# The members of this run are removed again, so the screen is not left filled
# with import probes.
for nummer in 0801 0802; do
	s delete-member "$(s member-by-no "$nummer" id)" > /dev/null
done
if [ "$(s member-by-no "0801" id)" = "missing" ] && [ "$(s member-by-no "0802" id)" = "missing" ]; then
	ok "the imported members are gone again"
else
	bad "the imported members are gone again" "0801=$(s member-by-no "0801" id) 0802=$(s member-by-no "0802" id)"
fi

# --- the header names the import accepts, and the screen that names them
# The screen shows the names and the import reads the very same list. Neither
# claim is checked against a list written into this test: a test that carried its
# own copy of the names would go on being green when the two drift apart, which
# is the one thing that must not happen here. So the test asks the screen what it
# shows, and then offers every answer to the import.
screen=$(curl -sk -b "$JAR" "$MEMBERS")
kopfzeilen=$(printf '%s' "$screen" | spalten 'fg-import-columns')
if [ -n "$kopfzeilen" ]; then
	ok "the import screen names the columns it accepts"
else
	bad "the import screen names the columns it accepts" "no block fg-import-columns on the screen"
fi
regel=$(printf '%s\n' "$kopfzeilen" | grep '^text	')
has "it says the whole cell has to carry one of the names" "$regel" "muss genau einer der unten genannten Namen sein"
has "and that the case of a name does not matter" "$regel" "Groß- und Kleinschreibung spielt keine Rolle"
# One line per name, taken from the screen. mapfile and not a for over a list of
# words: two of the names contain a space ("first name"), and a list of words
# would cut them in half and ask the import about "first".
mapfile -t KOPFZEILEN < <(printf '%s\n' "$kopfzeilen" | grep -e '^names	')
# Four rows, one for each field, and no fifth. The member has four fields, and
# that is a constant of the thing itself and not a guess about the names in it,
# so this number can be written down. What cannot be written down is how many
# names each row carries: that is the import's own list, and a test that carried
# its own copy of it would go on being green when the two drift apart — which is
# exactly the failure the loop below is there to catch, from the other side.
ANZ_FELDER=$(printf '%s\n' "${KOPFZEILEN[@]}" | cut -f2 | sort -u | grep -c .)
if [ "$ANZ_FELDER" -eq 4 ]; then
	ok "the screen names a row for each of the four fields"
else
	bad "the screen names a row for each of the four fields" "$ANZ_FELDER rows: $(printf '%s\n' "${KOPFZEILEN[@]}" | cut -f2 | sort -u | tr '\n' ' ')"
fi
# A fourth name, and the file that goes with it. "Name" is how a lot of German
# member administrations write the last name, so it is a name the import has to
# take. The check reads the member that came out of the file and not the report:
# an import that is accepted proves only that every column of the file found some
# field. Were "name" in the list of the first names instead, the fourth column
# would find the first name already taken and the file would be refused; were it
# in both lists at once, the values below would land in the wrong place.
printf 'Nummer;E-Mail;Vorname;Name\n0930;name@example.org;Aliase;Musterfrau\n' > "$IMPORT_CSV"
curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/import-name.html" -X POST "$BASE/wp-admin/admin-post.php" \
	-F "action=fg_import_members" \
	-F "fg_import_nonce=$INONCE" \
	-F "fg_member_file=@$IMPORT_CSV;type=text/csv" > /dev/null
namensbericht=$(report < "$DIR/import-name.html")
has "a header of Name is not refused" "$namensbericht" "1 neue Mitglieder angelegt"
if [ "$(s member-by-no "0930" first_name)" = "Aliase" ] && [ "$(s member-by-no "0930" last_name)" = "Musterfrau" ]; then
	ok "and its values land in the surname, not in the forename"
else
	bad "and its values land in the surname, not in the forename" "forename=$(s member-by-no "0930" first_name) surname=$(s member-by-no "0930" last_name)"
fi
clean "$(cat "$DIR/import-name.html")" "import of a file with a header of Name"
# And the other way round. "name" is a surname, so it must not be offered as a
# first name: a forename column in a real file is not called "name", and one that
# is would be a file the club wrote itself. The row is read as a set with commas
# on both sides, so a name that merely starts with "name" is not the name.
vornamen=$(printf '%s\n' "${KOPFZEILEN[@]}" | grep -e '	Vorname	' | cut -f3)
case ",$vornamen," in
	*,name,*) bad "name is not offered as a forename" "found: name" ;;
	*) ok "name is not offered as a forename" ;;
esac
# Every name the screen shows has to work, because a club reads the screen and
# writes the name down. This is the check that keeps the two from drifting apart,
# and it is the reason this test carries no list of names of its own: it takes
# the names off the screen and hands each of them to the import, one file per
# name. The other three columns of such a file carry the labels, so the name
# under test is the only one in it that can be refused.
LAUF=0
UNBEKANNT=0
ERFOLG_MITGL=0; ERFOLG_MITGL_FEHLT=""
ERFOLG_MAIL=0; ERFOLG_MAIL_FEHLT=""
ERFOLG_VORN=0; ERFOLG_VORN_FEHLT=""
ERFOLG_NACH=0; ERFOLG_NACH_FEHLT=""
for zeile in "${KOPFZEILEN[@]}"; do
	IFS=$'\t' read -r _ feld name <<< "$zeile"
	[ -n "$name" ] || continue
	LAUF=$((LAUF+1))
	case "$feld" in
		'Mitgliedsnummer') kopf="$name;E-Mail-Adresse;Vorname;Nachname" ;;
		'E-Mail-Adresse')  kopf="Mitgliedsnummer;$name;Vorname;Nachname" ;;
		'Vorname')         kopf="Mitgliedsnummer;E-Mail-Adresse;$name;Nachname" ;;
		'Nachname')        kopf="Mitgliedsnummer;E-Mail-Adresse;Vorname;$name" ;;
		*) UNBEKANNT=$((UNBEKANNT+1)); continue ;;
	esac
	nummer=$(printf '17%02d' "$LAUF")
	# A number of an earlier run could still be there, and the import would
	# change that member instead of writing a new one. The check asks whether
	# the file was taken, so the member has to be gone first.
	s delete-member "$(s member-by-no "$nummer" id)" > /dev/null 2>&1
	printf '%s\n%s;kopfzeile%d@example.org;Probe;Probe\n' "$kopf" "$nummer" "$LAUF" > "$IMPORT_CSV"
	curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/import-spalte.html" -X POST "$BASE/wp-admin/admin-post.php" \
		-F "action=fg_import_members" \
		-F "fg_import_nonce=$INONCE" \
		-F "fg_member_file=@$IMPORT_CSV;type=text/csv" > /dev/null
	angenommen=no
	if [ "$(s member-by-no "$nummer" id)" != "missing" ]; then angenommen=yes; fi
	case "$feld:$angenommen" in
		'Mitgliedsnummer:yes') ERFOLG_MITGL=$((ERFOLG_MITGL+1)) ;;
		'Mitgliedsnummer:no')  ERFOLG_MITGL_FEHLT="$ERFOLG_MITGL_FEHLT $name" ;;
		'E-Mail-Adresse:yes')  ERFOLG_MAIL=$((ERFOLG_MAIL+1)) ;;
		'E-Mail-Adresse:no')   ERFOLG_MAIL_FEHLT="$ERFOLG_MAIL_FEHLT $name" ;;
		'Vorname:yes')         ERFOLG_VORN=$((ERFOLG_VORN+1)) ;;
		'Vorname:no')          ERFOLG_VORN_FEHLT="$ERFOLG_VORN_FEHLT $name" ;;
		'Nachname:yes')        ERFOLG_NACH=$((ERFOLG_NACH+1)) ;;
		'Nachname:no')         ERFOLG_NACH_FEHLT="$ERFOLG_NACH_FEHLT $name" ;;
	esac
done
clean "$(cat "$DIR/import-spalte.html")" "import of a file for every name on the screen"
# A row whose label this test does not know is skipped by the loop below, and a
# skipped row is a row that was never offered to the import. Counting it here
# keeps a fifth field from passing as four.
if [ "$UNBEKANNT" -eq 0 ]; then
	ok "and no row carries a label this test does not know"
else
	bad "and no row carries a label this test does not know" "$UNBEKANNT rows"
fi
# One check per column and not one for the whole block: a screen that shows four
# fields has four claims, and a single red line would name the one that failed
# only by accident. Each of them counts what it accepted, because a block of four
# empty rows would otherwise pass as four columns that refused nothing.
if [ "$ERFOLG_MITGL" -gt 0 ] && [ -z "$ERFOLG_MITGL_FEHLT" ]; then ok "every name of the screen is taken for the member number ($ERFOLG_MITGL)"; else bad "every name of the screen is taken for the member number" "$ERFOLG_MITGL taken, refused:$ERFOLG_MITGL_FEHLT"; fi
if [ "$ERFOLG_MAIL" -gt 0 ] && [ -z "$ERFOLG_MAIL_FEHLT" ]; then ok "and for the address ($ERFOLG_MAIL)"; else bad "and for the address" "$ERFOLG_MAIL taken, refused:$ERFOLG_MAIL_FEHLT"; fi
if [ "$ERFOLG_VORN" -gt 0 ] && [ -z "$ERFOLG_VORN_FEHLT" ]; then ok "and for the forename ($ERFOLG_VORN)"; else bad "and for the forename" "$ERFOLG_VORN taken, refused:$ERFOLG_VORN_FEHLT"; fi
if [ "$ERFOLG_NACH" -gt 0 ] && [ -z "$ERFOLG_NACH_FEHLT" ]; then ok "and for the surname ($ERFOLG_NACH)"; else bad "and for the surname" "$ERFOLG_NACH taken, refused:$ERFOLG_NACH_FEHLT"; fi
# The members of this loop go again, so the screen is not left with them and the
# sections after this one count what they counted before.
GERAUMT=0
for nummer in $(seq -f '17%02g' 1 "$LAUF"); do
	s delete-member "$(s member-by-no "$nummer" id)" > /dev/null 2>&1
	[ "$(s member-by-no "$nummer" id)" = "missing" ] && GERAUMT=$((GERAUMT+1))
done
s delete-member "$(s member-by-no "0930" id)" > /dev/null 2>&1
if [ "$GERAUMT" = "$LAUF" ] && [ "$(s member-by-no "0930" id)" = "missing" ]; then
	ok "the members of the name tests are gone again ($GERAUMT)"
else
	bad "the members of the name tests are gone again" "$GERAUMT of $LAUF, 0930=$(s member-by-no "0930" id)"
fi


# --- a full duty refuses the registration on the server as well
echo "[11] a full duty refuses the registration on the server"
VOLLEDUTY=$(s make-event "Voller Dienst aus dem Admin" "$(date -d '+30 days' +%Y-%m-%d)" 1)
VOLLMITGLIED=$(neu "0850" "voll@example.org" "Voll" "Belegt")
s register "$VOLLEDUTY" "0850" "voll@example.org" >/dev/null
FREIES=$(s make-event "Dienst mit freiem Platz" "$(date -d '+30 days' +%Y-%m-%d)" 2)
# The member who tries to get in is created first. A pair that does not belong
# to anybody is refused before the places are even looked at, and a check of
# the full-duty refusal that ran against a member who did not exist would be
# measuring that earlier refusal.
FREIMITGLIED=$(neu "0851" "frei@example.org" "Frei" "Platz")
if [ "$FREIMITGLIED" -gt 0 ]; then ok "the member who signs up was created"; else bad "the member who signs up was created" "$FREIMITGLIED"; fi

LIST_PATH=$(s list-page-path)
# The public page is the only place the signup nonce exists, so without it the
# whole signup section would measure an empty string. Said here in one sentence
# rather than eleven times as a missing nonce.
if [ -n "$LIST_PATH" ]; then ok "the public duty list was found ($LIST_PATH)"; else bad "a published page with the duty list is there" "no page carries [arbeitsdienste]"; exit 1; fi
list=$(curl -sk -b "$JAR" "$BASE$LIST_PATH")
SNONCE=$(val /dev/stdin fg_register_nonce <<< "$list")
if [ -n "$SNONCE" ]; then ok "the signup form hands out a nonce"; else bad "the signup form hands out a nonce" "no nonce on the page"; fi

# The button is not there on a full duty, but a form can be posted by hand. The
# refusal has to come from the server, or the demand would be a suggestion.
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/voller-duty.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=$SNONCE" \
	--data-urlencode "fg_event_ref=$(s event "$VOLLEDUTY" public_ref)" \
	--data-urlencode "fg_member_no=0851" \
	--data-urlencode "fg_member_email=frei@example.org" \
	--data-urlencode "fg_website=" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
voller=$(cat "$DIR/voller-duty.html")
# Only the notice is read. The page also prints a sentence per duty card, and
# the card of the full duty holds a word the notice must not be searched for.
has "a full duty is refused" "$(printf '%s' "$voller" | notice)" "keine freien Plätze mehr"
hasnt "and it is not called a missing demand" "$(printf '%s' "$voller" | notice)" "kein Bedarf"
if [ "$(s count-registrations "$VOLLEDUTY")" = "1" ]; then ok "the refused registration wrote nothing"; else bad "the refused registration wrote nothing" "$(s count-registrations "$VOLLEDUTY")"; fi
clean "$voller" "refused registration for a full duty"

# The same request against a duty that has a place free is taken. Without this
# the refusal above would also pass if every registration were refused.
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/freier-duty.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=$SNONCE" \
	--data-urlencode "fg_event_ref=$(s event "$FREIES" public_ref)" \
	--data-urlencode "fg_member_no=0851" \
	--data-urlencode "fg_member_email=frei@example.org" \
	--data-urlencode "fg_website=" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
freier=$(cat "$DIR/freier-duty.html")
has "a duty with a free place accepts the registration" "$(printf '%s' "$freier" | notice)" "Du bist für diesen Arbeitsdienst angemeldet"
if [ "$(s count-registrations "$FREIES")" = "1" ]; then ok "and the registration is stored"; else bad "and the registration is stored" "$(s count-registrations "$FREIES")"; fi
clean "$freier" "registration for a duty with a free place"

# A member who is already in is told so, and nothing is written a second time.
# Two confirmation mails with two unregister links in one inbox would leave the
# member with a link that does not work.
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/zweite-anmeldung.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=$SNONCE" \
	--data-urlencode "fg_event_ref=$(s event "$FREIES" public_ref)" \
	--data-urlencode "fg_member_no=0851" \
	--data-urlencode "fg_member_email=frei@example.org" \
	--data-urlencode "fg_website=" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
zweite=$(cat "$DIR/zweite-anmeldung.html")
has "a second sign-up for the same duty is answered" "$(printf '%s' "$zweite" | notice)" "bereits angemeldet"
if [ "$(s count-registrations "$FREIES")" = "1" ]; then ok "and wrote no second row"; else bad "and wrote no second row" "$(s count-registrations "$FREIES")"; fi
clean "$zweite" "second sign-up for the same duty"

# The overview answers the same two questions the club asks on the phone: how
# many are in, and how many are still needed. The two cells are read from the
# row of this run's own duty, because every other row carries numbers of its
# own.
overview=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events")
if [ "$(printf '%s' "$overview" | cell "$FREIES" 4)" = "1" ]; then
	ok "the overview counts the one member that signed up"
else
	bad "the overview counts the one member that signed up" "cell reads: $(printf '%s' "$overview" | cell "$FREIES" 4)"
fi
if [ "$(printf '%s' "$overview" | cell "$FREIES" 5)" = "1" ]; then
	ok "and shows the one place that is left of the two"
else
	bad "and shows the one place that is left of the two" "cell reads: $(printf '%s' "$overview" | cell "$FREIES" 5)"
fi
if [ "$(printf '%s' "$overview" | cell "$VOLLEDUTY" 5)" = "0" ]; then
	ok "a full duty shows no free place"
else
	bad "a full duty shows no free place" "cell reads: $(printf '%s' "$overview" | cell "$VOLLEDUTY" 5)"
fi
clean "$overview" "overview with the two count columns"

# The duty of [5c] now holds a place that was given away and taken back, and
# the count has to follow. A registration that was made and then removed must
# not leave its mark in the list.
ANZAHL_VORHER=$(s count-registrations "$NEW_ID")
neu "0852" "zwei@example.org" "Zwei" "Platz" >/dev/null
s register "$NEW_ID" "0852" "zwei@example.org" >/dev/null
ANZAHL_DAZU=$(s count-registrations "$NEW_ID")
if [ "$ANZAHL_DAZU" = "$((ANZAHL_VORHER + 1))" ]; then
	ok "a registration on this run's duty is counted"
else
	bad "a registration on this run's duty is counted" "was $ANZAHL_VORHER, now $ANZAHL_DAZU"
fi

# A duty that states no demand is refused, and the refusal is a different
# sentence from the one for a full duty. "Fully booked" for a duty nobody asked
# anybody for would be a claim about the duty that is not true, and the card
# on the page already says so; a refusal that contradicted the card would leave
# the member reading one thing and being told another.
OHNE=$(s make-event "Dienst ohne Bedarf aus dem Admin" "$(date -d '+30 days' +%Y-%m-%d)" 0)
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/ohne-bedarf.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=$SNONCE" \
	--data-urlencode "fg_event_ref=$(s event "$OHNE" public_ref)" \
	--data-urlencode "fg_member_no=0851" \
	--data-urlencode "fg_member_email=frei@example.org" \
	--data-urlencode "fg_website=" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
ohne_bedarf=$(cat "$DIR/ohne-bedarf.html")
has "a duty without a demand is refused" "$(printf '%s' "$ohne_bedarf" | notice)" "kein Bedarf eingetragen"
hasnt "and it is not called full" "$(printf '%s' "$ohne_bedarf" | notice)" "freien Plätze"
if [ "$(s count-registrations "$OHNE")" = "0" ]; then ok "and the refusal wrote nothing"; else bad "and the refusal wrote nothing" "$(s count-registrations "$OHNE")"; fi
clean "$ohne_bedarf" "refused registration for a duty without a demand"

# A duty that is not visible is not registrable either, even with its reference
# in hand. The reference is not a key, it only names which duty is meant.
WEG=$(s make-event "Nicht sichtbarer Dienst" "$(date -d '+30 days' +%Y-%m-%d)" 3 "" 0)
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/unsichtbar.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=$SNONCE" \
	--data-urlencode "fg_event_ref=$(s event "$WEG" public_ref)" \
	--data-urlencode "fg_member_no=0851" \
	--data-urlencode "fg_member_email=frei@example.org" \
	--data-urlencode "fg_website=" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
if [ "$(s count-registrations "$WEG")" = "0" ]; then
	ok "a duty that is not visible takes no registration"
else
	bad "a duty that is not visible takes no registration" "$(s count-registrations "$WEG")"
fi
clean "$(cat "$DIR/unsichtbar.html")" "registration for a duty that is not visible"

# A reference that names no duty is refused like a duty that is not there.
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/ohne-ref.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=$SNONCE" \
	--data-urlencode "fg_event_ref=0000000000000000000000000000" \
	--data-urlencode "fg_member_no=0851" \
	--data-urlencode "fg_member_email=frei@example.org" \
	--data-urlencode "fg_website=" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
has "a reference that names no duty is refused" "$(printf '%s' "$(cat "$DIR/ohne-ref.html")" | notice)" "nicht möglich"
clean "$(cat "$DIR/ohne-ref.html")" "registration with a reference that names nothing"

# The pair has to belong to one member. A number of a member with somebody
# else's address is refused without saying which half was right, because the
# page is public and a hint would tell a passer-by whether a guessed number
# exists.
out=$(curl -sk -b "$JAR" -L -o "$DIR/falsches-paar.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=$SNONCE" \
	--data-urlencode "fg_event_ref=$(s event "$FREIES" public_ref)" \
	--data-urlencode "fg_member_no=0850" \
	--data-urlencode "fg_member_email=frei@example.org" \
	--data-urlencode "fg_website=" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
falsch=$(cat "$DIR/falsches-paar.html")
has "a number with the wrong address is refused" "$(printf '%s' "$falsch" | notice)" "nicht möglich"
hasnt "and it does not say which half was wrong" "$(printf '%s' "$falsch" | notice)" "Mitgliedsnummer stimmt"
if [ "$(s count-registrations "$FREIES")" = "1" ]; then ok "and no row was added" ; else bad "and no row was added" "$(s count-registrations "$FREIES")"; fi
clean "$falsch" "registration with a pair that does not belong together"

# A wrong nonce changes nothing. The public form is not behind a login, so a
# stale form is not answered with a refusal page but with the notice that asks
# for a fresh page — what matters here is that nothing is written.
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/ohne-erlaubnis.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=manipuliert" \
	--data-urlencode "fg_event_ref=$(s event "$FREIES" public_ref)" \
	--data-urlencode "fg_member_no=0851" \
	--data-urlencode "fg_member_email=frei@example.org" \
	--data-urlencode "fg_website=" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
kein_bestand=$(printf '%s' "$(cat "$DIR/ohne-erlaubnis.html")" | notice)
has "a signup with a wrong nonce is not taken" "$kein_bestand" "Formular ist nicht mehr gültig"
if [ "$(s count-registrations "$FREIES")" = "1" ]; then ok "and it wrote nothing"; else bad "and it wrote nothing" "$(s count-registrations "$FREIES")"; fi
clean "$(cat "$DIR/ohne-erlaubnis.html")" "signup with a wrong nonce"

# The honeypot field is empty in every honest form. A bot fills in every field
# it can see, so one that comes in is never written.
#
# The member of this POST has to be one that is not yet on this duty. With the
# member of the checks above the POST would be refused anyway — that member is
# already registered, and a second registration of the same member is turned
# down whatever the honeypot says. Such a POST proves nothing about the
# honeypot, and the counter-probe over exactly this line showed it: with the
# honeypot check taken out of the code the suite stayed green here.
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/honigtopf.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=$SNONCE" \
	--data-urlencode "fg_event_ref=$(s event "$FREIES" public_ref)" \
	--data-urlencode "fg_member_no=0852" \
	--data-urlencode "fg_member_email=zwei@example.org" \
	--data-urlencode "fg_website=http://spam.example.org" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
honigtopf=$(printf '%s' "$(cat "$DIR/honigtopf.html")" | notice)
has "a form from the honeypot is refused like any other bad one" "$honigtopf" "nicht möglich"
if [ "$(s count-registrations "$FREIES")" = "1" ]; then ok "a form from the honeypot writes nothing"; else bad "a form from the honeypot writes nothing" "$(s count-registrations "$FREIES")"; fi
clean "$(cat "$DIR/honigtopf.html")" "signup with a filled honeypot"

# The internal number is not what the form posts. A form that took the record
# number would let anyone walk the whole table of duties, including the ones
# that are not visible.
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/mit-id.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=$SNONCE" \
	--data-urlencode "fg_event_ref=$FREIES" \
	--data-urlencode "fg_member_no=0851" \
	--data-urlencode "fg_member_email=frei@example.org" \
	--data-urlencode "fg_website=" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
hasnt "the record number is not accepted as a reference" "$(printf '%s' "$(cat "$DIR/mit-id.html")" | notice)" "angemeldet"
if [ "$(s count-registrations "$FREIES")" = "1" ]; then ok "and it wrote nothing" ; else bad "and it wrote nothing" "$(s count-registrations "$FREIES")"; fi
clean "$(cat "$DIR/mit-id.html")" "signup with the record number as reference"

# The screen of the duty carries a delete link for the registration, and it
# removes the place from this duty and nothing else: the member stays in the
# club, and the same member is still in the list of the other duty they signed
# up for. Removing a place is the one thing the club has to be able to do
# itself, and it has to be the one link, not the one for the duty.
detail=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events&event=$FREIES")
has "the duty screen lists the member number that signed up" "$detail" "0851"
has "and the address" "$detail" "frei@example.org"
has "it says what a removal does" "$detail" "Das Löschen einer Anmeldung gibt nur den Platz in diesem Arbeitsdienst frei"
reg_url=$(printf '%s' "$detail" | link "registration")
if [ -n "$reg_url" ]; then ok "the registration has its own delete link"; else bad "the registration has its own delete link" "no link on the screen"; fi
# The question names the member, so the person clicking knows which of several
# rows they are about to remove.
if [ -n "$reg_url" ]; then
	has "the question names the member it is about" "$detail" "Die Anmeldung von Mitglied 0851 für diesen Arbeitsdienst löschen?"
	out=$(curl -sk -b "$JAR" -L "$reg_url")
	has "the removal is confirmed" "$out" "wurde endgültig gelöscht"
	clean "$out" "removal of one registration"
fi
if [ "$(s count-registrations "$FREIES")" = "0" ]; then ok "the place is free again"; else bad "the place is free again" "$(s count-registrations "$FREIES")"; fi
if [ "$(s member "$FREIMITGLIED" member_no)" = "0851" ]; then ok "and the member is still in the club"; else bad "and the member is still in the club" "$(s member "$FREIMITGLIED" member_no)"; fi
# The same member is still in the other duty they signed up for. A removal that
# took the member out of the club would be a different operation, and one the
# club does on the member screen.
if [ "$(s count-registrations "$NEW_ID")" = "$ANZAHL_DAZU" ]; then ok "and is still signed up for the other duty"; else bad "and is still signed up for the other duty" "$(s count-registrations "$NEW_ID")"; fi
# The place that was freed is on the list again, and the number the club reads
# there is the number of places, not the number of members. The card of this
# duty is named, because the list carries a card for every visible duty and a
# word on the page says nothing about which one it was read from.
list=$(curl -sk -b "$JAR" "$BASE$LIST_PATH")
FREIREF=$(s event "$FREIES" public_ref)
has "the card of this duty offers the place again" "$(printf '%s' "$list" | karte "$FREIREF")" "Eintragen"
has "and the free places it reads are its own" "$(printf '%s' "$list" | karte "$FREIREF")" "Verfügbare freie Plätze 2"
hasnt "and it does not name the member who took the other place" "$(printf '%s' "$list" | karte "$FREIREF")" "0850"
clean "$list" "duty list after a place was freed"

# --- removing every member that is not registered for a work service
echo "[12] deleting the members without a work service"
# Three members who are in no duty at all, so the operation has something to take
# and the plural of the notice is certain whatever the fixture holds.
for nummer in 0960 0961 0962; do
	neu "$nummer" "muell$nummer@example.org" "Muell" "Probe$nummer" > /dev/null
done
PRUNE_VORHER=$(s unlinked)
PRUNE_ERWARTET=$(printf '%s' "$PRUNE_VORHER" | tr ' ' '\n' | grep -c .)
ALLE_VORHER=$(s count-members)
if [ "$PRUNE_ERWARTET" -ge 3 ]; then ok "three members are not in any duty ($PRUNE_ERWARTET in total)"; else bad "three members are not in any duty" "$PRUNE_ERWARTET"; fi

screen=$(curl -sk -b "$JAR" "$MEMBERS")
has "the cleanup section is on the member screen" "$screen" ">Aufräumen<"
has "it says what would be deleted" "$screen" "sind für keinen Arbeitsdienst angemeldet"
has "it says what stays" "$screen" "bleiben erhalten"
has "it says that rides are kept" "$screen" "Fahrgemeinschaften bleiben erhalten"
# The screen is asked for two numbers, and both are compared with the tables. A
# count that only ever appears in a sentence is a claim about the wording, not
# about the state of the club.
if [ "$(printf '%s' "$screen" | zahl fg-prune-ohne)" = "$PRUNE_ERWARTET" ]; then
	ok "it names how many would be deleted ($PRUNE_ERWARTET)"
else
	bad "it names how many would be deleted" "$(printf '%s' "$screen" | zahl fg-prune-ohne) instead of $PRUNE_ERWARTET"
fi
if [ "$(printf '%s' "$screen" | zahl fg-prune-mit)" = "$(( ALLE_VORHER - PRUNE_ERWARTET ))" ]; then
	ok "and how many would stay ($(( ALLE_VORHER - PRUNE_ERWARTET )))"
else
	bad "and how many would stay" "$(printf '%s' "$screen" | zahl fg-prune-mit) instead of $(( ALLE_VORHER - PRUNE_ERWARTET ))"
fi
PRUNE_URL=$(printf '%s' "$screen" | ziel 'fg_prune=ask')
if [ -n "$PRUNE_URL" ]; then ok "the link to the overview is there"; else bad "the link to the overview is there" "no link with fg_prune on the screen"; fi

uebersicht=$(curl -sk -b "$JAR" "$PRUNE_URL")
has "the overview has a heading of its own" "$uebersicht" "Mitglieder ohne Arbeitsdienst löschen"
has "it says the count was taken when the page was opened" "$uebersicht" "Diese Übersicht zählt beim Aufruf dieser Seite"
has "its button says how many it removes" "$uebersicht" "$PRUNE_ERWARTET Mitglieder endgültig löschen"
has "the form carries a nonce" "$uebersicht" 'name="fg_prune_nonce"'
if [ "$(printf '%s' "$uebersicht" | zahl fg-prune-ohne)" = "$PRUNE_ERWARTET" ]; then
	ok "the overview names the same number as the screen"
else
	bad "the overview names the same number as the screen" "$(printf '%s' "$uebersicht" | zahl fg-prune-ohne) instead of $PRUNE_ERWARTET"
fi
# Opening the overview is a link, and a link is fetched by browsers and proxies on
# their own. If it deleted anything, the safest click in the plugin would be the
# one nobody made.
if [ "$(s unlinked)" = "$PRUNE_VORHER" ]; then ok "opening the overview deletes nothing"; else bad "opening the overview deletes nothing" "$(s unlinked)"; fi

# The overview has to name exactly the people the statement would take. It names
# too many and it takes them with it; it names too few and a member is removed
# from a screen that said they would stay. Both lists are sorted before they are
# compared, because the claim is about the two sets and not about their order, and
# a member number is text: one of them can be the beginning of another, so a
# search for 004 would be answered by the row of 0042.
betroffen=$(printf '%s' "$uebersicht" | rahmen 'fg-prune-report')
AUF_SCREEN=$(printf '%s' "$uebersicht" | nummern | tr ' ' '\n' | sed '/^$/d' | sort | tr '\n' ' ')
IN_TABELLE=$(printf '%s' "$PRUNE_VORHER" | tr ' ' '\n' | sed 's/|.*$//; /^$/d' | sort | tr '\n' ' ')
if [ "$AUF_SCREEN" = "$IN_TABELLE" ]; then
	ok "the overview names exactly the members it would take ($PRUNE_ERWARTET)"
else
	bad "the overview names exactly the members it would take" "screen: [$AUF_SCREEN] tables: [$IN_TABELLE]"
fi
# The addresses are checked on top, one by one: two member numbers can look alike
# and two addresses cannot, so this is what catches a row that carries the right
# number with the wrong person behind it.
ADRESSEN=0
for eintrag in $PRUNE_VORHER; do
	if printf '%s' "$betroffen" | grep -qF "${eintrag##*|}"; then ADRESSEN=$((ADRESSEN+1)); fi
done
if [ "$ADRESSEN" = "$PRUNE_ERWARTET" ]; then
	ok "and it names the address of each of them"
else
	bad "and it names the address of each of them" "$ADRESSEN of $PRUNE_ERWARTET"
fi
# The two members of this suite that are registered for a duty of this run are the
# ones the operation must not touch. They are named by their number and not by
# theirs: "Verwalter Test" and "Voll Belegt" are the kind of name an overview
# would print, and a check that read a name would pass on the wrong member.
for angemeldet in 0842 0850; do
	hasnt "the registered member $angemeldet is not in the overview" "$betroffen" "$angemeldet"
done
has "the overview offers the way back" "$uebersicht" "Zurück zur Liste"
clean "$uebersicht" "the cleanup overview"

# The request that removes members is refused without a valid nonce, and the
# refusal changes nothing: a form that can be posted from anywhere would let a
# page in a browser window of a third party empty the member list with one request.
PNONCE=$(val /dev/stdin fg_prune_nonce <<< "$uebersicht")
out=$(curl -sk -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_prune_members" \
	--data-urlencode "fg_prune_nonce=manipuliert")
if [ "$out" = "403" ]; then ok "a wrong nonce is refused ($out)"; else bad "a wrong nonce is refused" "$out"; fi
if [ "$(s unlinked)" = "$PRUNE_VORHER" ]; then ok "and after the refusal nothing is gone"; else bad "and after the refusal nothing is gone" "$(s unlinked)"; fi

out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/member-prune.html" -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_prune_members" \
	--data-urlencode "fg_prune_nonce=$PNONCE")
geraeumt=$(cat "$DIR/member-prune.html")
# The notice is compared as a whole and not searched for in two pieces. It carries
# two numbers, and the claim is that it carries *these* two: a notice that named
# how many went and left the rest out would be answered by the first half of a
# two-part check.
notiz=$(printf '%s' "$geraeumt" | hinweis)
ERWARTETE_NOTIZ="$PRUNE_ERWARTET Mitglieder gelöscht, $(( ALLE_VORHER - PRUNE_ERWARTET )) Mitglieder bleiben angemeldt."
if [ "$notiz" = "$ERWARTETE_NOTIZ" ]; then
	ok "the notice names both numbers"
else
	bad "the notice names both numbers" "reads [$notiz], expected [$ERWARTETE_NOTIZ]"
fi
if [ "$(s unlinked)" = "" ]; then ok "no member without a work service is left"; else bad "no member without a work service is left" "$(s unlinked)"; fi
if [ "$(s count-members)" = "$(( ALLE_VORHER - PRUNE_ERWARTET ))" ]; then
	ok "and the member list holds the registered ones only"
else
	bad "and the member list holds the registered ones only" "$(s count-members) instead of $(( ALLE_VORHER - PRUNE_ERWARTET ))"
fi
# Two members of this suite are signed up for a duty of this run, and both must
# have survived with their registration. A group deletion that took the registered
# ones with it would empty the club on the second click of a test run.
for behalten in 0842 0850; do
	if [ "$(s member-by-no "$behalten" id)" != "missing" ]; then
		ok "the registered member $behalten survived"
	else
		bad "the registered member $behalten survived" "gone"
	fi
done
if [ "$(s count-registrations "$EVENT_ID")" = "1" ]; then ok "and their registration is still there"; else bad "and their registration is still there" "$(s count-registrations "$EVENT_ID")"; fi
if [ "$(s count-registrations "$VOLLEDUTY")" = "1" ]; then ok "the one on the full duty too"; else bad "the one on the full duty too" "$(s count-registrations "$VOLLEDUTY")"; fi
# The list is asked a second time: with nobody left to remove it has to say so
# and offer no form at all, because a button that removes nothing is a button
# that looks like it did something.
screen=$(curl -sk -b "$JAR" "$MEMBERS")
if [ "$(printf '%s' "$screen" | zahl fg-prune-ohne)" = "0" ]; then ok "the screen now counts nobody to remove"; else bad "the screen now counts nobody to remove" "$(printf '%s' "$screen" | zahl fg-prune-ohne)"; fi
has "and it says there is nothing to do" "$screen" "gibt nichts zu löschen"
hasnt "and it offers no link to the overview" "$screen" "fg_prune=ask"
uebersicht=$(curl -sk -b "$JAR" "$PRUNE_URL")
has "the overview says so too" "$uebersicht" "Es ist niemand mehr ohne Anmeldung im Bestand"
hasnt "and it carries no delete form" "$uebersicht" 'name="fg_prune_nonce"'
clean "$geraeumt" "removal of the members without a work service"
clean "$screen" "the member screen after the cleanup"

s delete-member "$VOLLMITGLIED" >/dev/null
s delete-member "$FREIMITGLIED" >/dev/null
s delete-member "$(s member-by-no 0852 id)" >/dev/null
s delete-member "$(s member-by-no 0900 id)" >/dev/null
s delete-member "$EVENTMITGLIED" >/dev/null


# --- the wording of the five messages
echo "[13] the wording of the messages"

ANREDE=$(s make-event "Anrede der Mails" "$(date -d '+40 days' +%Y-%m-%d)" 6 "09:00")
MAILS="$BASE/wp-admin/admin.php?page=fahrgemeinschaften-mails"

list=$(curl -sk -b "$JAR" "$MAILS")
clean "$list" "E-Mails screen"

# One row per message plus the header. Counting the rows is what a check against
# a word cannot do: the label of one message is not in another row, but both
# would be found on the page.
zeilen=$(printf '%s' "$list" | grep -c '<tr>')
if [ "$zeilen" -eq 6 ]; then
	ok "the list has one row per message and a header ($zeilen rows)"
else
	bad "the list has one row per message and a header" "$zeilen rows"
fi

for BESCHRIFTUNG in "Fahrgemeinschaft bestätigen" "Fahrgemeinschaft veröffentlicht" "Kontaktanfrage an den Ersteller" "Bestätigung an die anfragende Person" "Anmeldung zu einem Arbeitsdienst"; do
	has "the list names: $BESCHRIFTUNG" "$list" "$BESCHRIFTUNG"
done

# A message that nobody changed says so. Four of the five are untouched at the
# start of this section, and the fifth is put back to its default at the end of
# it, so all five rows carry the word and no row carries a date. The word is
# counted on a line of its own, because the page also says "Auf Standard
# zurücksetzen" below the list and that one is not a row.
standard=$(printf '%s' "$list" | grep -cE 'Standard[[:space:]]*</td>')
if [ "$standard" -eq 5 ]; then
	ok "an untouched message says Standard ($standard rows)"
else
	bad "an untouched message says Standard" "$standard of 5 rows"
fi
hasnt "an untouched message does not carry a date" "$list" "geändert am 1970"

# --- the form of one message
form=$(curl -sk -b "$JAR" "$MAILS&mail=duty_signup")
clean "$form" "mail form"
has "the form opens the requested message" "$form" "Anmeldung zu einem Arbeitsdienst"

# Every placeholder of this message has to be on the page with its meaning. The
# count is the plugin's own, so a placeholder that exists and is not offered
# shows up as a difference, and one that is offered and not understood shows up
# in the other direction.
angeboten=$(printf '%s' "$form" | grep -o 'data-placeholder="{{[A-Za-z]*}}"' | sort -u | grep -c .)
erklaert=$(printf '%s' "$form" | grep -o '<code>{{[A-Za-z]*}}</code>' | sort -u | grep -c .)
if [ "$angeboten" -eq 8 ] && [ "$erklaert" -eq 8 ]; then
	ok "every placeholder of the message is offered and explained ($angeboten)"
else
	bad "every placeholder of the message is offered and explained" "offered=$angeboten explained=$erklaert of 8"
fi
for PLATZHALTER in '{{Anrede}}' '{{Vorname}}' '{{Name}}' '{{Arbeitsdienst}}' '{{Arbeitsdienstdetails}}' '{{Datum}}' '{{Uhrzeit}}' '{{Abmeldelink}}'; do
	has "the form offers $PLATZHALTER" "$form" ">$PLATZHALTER<"
done

# A placeholder of another message must not be offered here. The unregister link
# belongs to a duty and not to a contact request, and offering it would let a
# club write a message that can never be filled.
hasnt "the form does not offer the confirmation link" "$form" '{{Bestaetigungslink}}'
hasnt "the form does not offer the interested person" "$form" '{{Interessent}}'

# The nonce fields of the two forms carry different names. They can carry the
# same one: every form posts its own hidden field, and what it costs is that the
# page stops saying which form a nonce belongs to — the one name then belongs to
# two actions, and only one of them can be the one WordPress checks.
anzahl_nonce=$(printf '%s' "$form" | grep -c 'name="fg_mail_nonce"')
if [ "$anzahl_nonce" -eq 1 ]; then ok "the nonce field of the save form is named once"; else bad "the nonce field of the save form is named once" "$anzahl_nonce"; fi

NONCE=$(printf '%s' "$form" | grep -o 'name="fg_mail_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
if [ -n "$NONCE" ]; then
	ok "the save form carries a nonce"
else
	bad "the save form carries a nonce" "no field with a value"
fi

# An unchanged message has nothing to undo, so the way back is not offered and
# the second form is not printed at all. A reset button that is there and greyed
# out would be a control that does nothing; a second nonce field on a page that
# has one form would be a form that cannot be seen.
anzahl_reset=$(printf '%s' "$form" | grep -c 'fg_mail_reset_nonce')
if [ "$anzahl_reset" -eq 0 ]; then
	ok "an unchanged message prints no reset form at all ($anzahl_reset)"
else
	bad "an unchanged message prints no reset form at all" "$anzahl_reset mentions"
fi

# --- a placeholder that this message does not have is refused
curl -sk -b "$JAR" -o /dev/null -d "action=fg_save_mail&mail=duty_signup&fg_mail_nonce=$NONCE" \
	--data-urlencode "fg_mail_subject=Mein Betreff" \
	--data-urlencode "fg_mail_body=Sehr geehrter {{Vorname}}, hier ist {{Unsinn}}." "$BASE/wp-admin/admin-post.php"

nachher=$(curl -sk -b "$JAR" "$MAILS&mail=duty_signup")
clean "$nachher" "after a refused save"
# Read out of the box that carries the complaint and not out of the whole page:
# the placeholder table on the form prints every allowed name, and a search over
# the page would find {{Abmeldelink}} there whatever the server answered. The box
# is addressed by the word that only it has — the complaint WordPress prints for
# the admin, and the box of the held-back messages further down are two different
# boxes that both carry notice-error.
klage=$(kasten 'is-dismissible' <<< "$nachher")
pruef "$klage" "a refused save says the text was not stored" "
import sys
h = sys.stdin.read()
sys.exit(0 if 'wurde nicht gespeichert' in h else 1)
" "the box of the complaint is empty"
pruef "$klage" "the complaint names the placeholder that is not allowed" "
import sys
h = sys.stdin.read()
sys.exit(0 if '{{Unsinn}}' in h else 1)
" "the box does not name {{Unsinn}}"
pruef "$klage" "the complaint names what is allowed instead" "
import sys
h = sys.stdin.read()
sys.exit(0 if '{{Abmeldelink}}' in h else 1)
" "the box does not name the allowed set"
# The complaint is about a name the message does not have, and it has to say so
# in words as well: {{Unsinn}} alone leaves a club guessing whether the server
# did not understand the braces or the word inside them.
pruef "$klage" "the complaint says these are the only ones possible" "
import sys
h = sys.stdin.read()
sys.exit(0 if 'nur diese' in h.lower() else 1)
" "the box does not say that the listed names are the only possible ones"
has "the text that was typed is still in the form" "$nachher" "hier ist {{Unsinn}}."
has "the subject that was typed is still in the form" "$nachher" 'value="Mein Betreff"'

rows=$(s mail-text-rows duty_signup)
if [ "$rows" = "0" ]; then ok "a refused text is not in the table"; else bad "a refused text is not in the table" "$rows rows"; fi

# --- a change is stored
NONCE=$(printf '%s' "$nachher" | grep -o 'name="fg_mail_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -sk -b "$JAR" -o /dev/null -d "action=fg_save_mail&mail=duty_signup&fg_mail_nonce=$NONCE" \
	--data-urlencode "fg_mail_subject=Angemeldet: {{Arbeitsdienst}} am {{Datum}}" \
	--data-urlencode "fg_mail_body={{Anrede}},

Servus, {{Vorname}}.

Arbeitsdienst: {{Arbeitsdienst}}
Datum: {{Datum}}
Abmelden: {{Abmeldelink}}" "$BASE/wp-admin/admin-post.php"

# One row, not two. The key is unique and the save is one statement, so a second
# save of the same message cannot end in a second row — and two rows would mean
# the message that goes out is the one that was written last, which nothing
# would say.
rows=$(s mail-text-rows duty_signup)
if [ "$rows" = "1" ]; then ok "a changed text stands in the table exactly once"; else bad "a changed text stands in the table exactly once" "$rows rows"; fi

liste=$(curl -sk -b "$JAR" "$MAILS")
clean "$liste" "after a save"
geaendert=$(printf '%s' "$liste" | grep -c 'geändert am 20')
if [ "$geaendert" -eq 1 ]; then
	ok "the list says that one message was changed ($geaendert row)"
else
	bad "the list says that one message was changed" "$geaendert rows"
fi
has "the list shows the new subject" "$liste" "Angemeldet: {{Arbeitsdienst}} am {{Datum}}"

formular=$(curl -sk -b "$JAR" "$MAILS&mail=duty_signup")
has "the form shows the saved subject" "$formular" 'value="Angemeldet: {{Arbeitsdienst}} am {{Datum}}"'
has "the form shows the saved text" "$formular" "Servus, {{Vorname}}."
has "a changed message offers the way back" "$formular" "Auf Standard zurücksetzen"

# What is in the table is what was typed, with its placeholders: the send path
# needs them and the screen is not the only thing that reads the row.
s mail-text-body duty_signup > "$DIR/fg-mail-body.txt"
if grep -q '{{Abmeldelink}}' "$DIR/fg-mail-body.txt"; then
	ok "the stored text keeps its placeholders"
else
	bad "the stored text keeps its placeholders" "$(head -1 "$DIR/fg-mail-body.txt")"
fi
if grep -q '{{Unsinn}}' "$DIR/fg-mail-body.txt"; then
	bad "the refused placeholder is not in the table" "found {{Unsinn}}"
else
	ok "the refused placeholder is not in the table"
fi
rm -f "$DIR/fg-mail-body.txt"

# --- the preview shows both parts, with invented names
# The link is the one that names this message. The page carries five of them,
# one per row of the list above the form, and taking the first hands back the
# preview of the first message — which is a different text with a different
# subject, and every check below it would then be about the wrong message
# without one of them being wrong.
vorschau_url=$(printf '%s' "$formular" | python3 -c "
import re, sys, html
links = [html.unescape(u) for u in re.findall(r'href=\"([^\"]*fg_mail_text_preview[^\"]*)\"', sys.stdin.read())]
print(next((u for u in links if 'mail=duty_signup' in u), ''))")
if [ -n "$vorschau_url" ]; then
	ok "the preview link of this message is findable among the five"
else
	bad "the preview link of this message is findable among the five" "no link with mail=duty_signup"
fi
vorschau=$(curl -sk -b "$JAR" "$vorschau_url")
clean "$vorschau" "mail preview"
has "the preview carries the finished subject" "$vorschau" "Angemeldet: Flussaktion am Samstag, den 12.06.2027"
has "the preview shows the HTML part" "$vorschau" "</html>"
has "the preview shows the text part" "$vorschau" "<pre"
has "the preview greets the invented member" "$vorschau" "Hallo Anton"

# A placeholder left over would be shown to the club as {{Datum}} in the preview
# and would go out as those seven characters in a real mail. The check counts
# them over the whole page, because both parts have to be free of them.
pruef "$vorschau" "no placeholder is left in the preview" "
import re, sys
rest = sorted(set(re.findall(r'\{\{[A-Za-z]*\}\}', sys.stdin.read())))
if rest:
    print('left over: ' + ', '.join(rest))
    sys.exit(1)
sys.exit(0)
" "the preview still shows a placeholder"

# No recipient may be on a page that is served to a browser, cached with it and
# screenshotted into a bug report. The names of the sample are read out of the
# table, and every address that a member of this installation really has has to
# be missing. The count of addresses is checked with it: a loop over no address
# proves nothing, and would be green for that reason.
geprueft=0
while IFS=';' read -r nummer adresse vorname nachname; do
	case "$adresse" in
		*@*) ;;
		*) continue ;;
	esac
	geprueft=$((geprueft+1))
	hasnt "the preview does not carry the address of member $nummer" "$vorschau" "$adresse"
	case "$vorname" in
		Anton) continue ;; # the sample is called Anton too, that cannot decide it
	esac
	[ -n "$vorname" ] && hasnt "the preview does not carry the first name of member $nummer" "$vorschau" "$vorname"
done < <(s members-csv)
# Two is the smallest number the fixture of this installation ever has, and one
# address in a loop is a check that would be green for the reason that it ran
# once. The number goes into the message, so a suite that leaves fewer members
# behind than it found says so instead of passing quietly.
if [ "$geprueft" -ge 2 ]; then
	ok "the check of the real addresses saw at least two of them ($geprueft)"
else
	bad "the check of the real addresses saw at least two of them" "$geprueft"
fi

# The other direction: the preview is meant to show the message as it goes out,
# and the mail as it goes out carries the footer of the club. A preview without
# it is the picture the club gets when the setting was never stored — which is
# what this screen was opened for.
# The other direction: the preview is meant to show the message as it goes out,
# and a message as it goes out carries the footer of the club. A preview without
# it is the picture a club gets when the setting was never stored — which is the
# complaint this whole screen was opened for. Every line of the stored footer
# has to be in the text part, not only in the layout: a footer that stands in the
# HTML and not in the text is a mail without a sender in half of the programmes.
vorschau_text=$(printf '%s' "$vorschau" | python3 -c "
import re, sys, html
m = re.search(r'<pre[^>]*>(.*?)</pre>', sys.stdin.read(), re.S)
print(html.unescape(m.group(1)) if m else '')")
fuss=$(s settings footer)
fuss_zeilen=0
fuss_fehlend=""
while IFS= read -r zeile; do
	[ -n "$zeile" ] || continue
	fuss_zeilen=$((fuss_zeilen+1))
	case "$vorschau_text" in
		*"$zeile"*) ;;
		*) fuss_fehlend="$fuss_fehlend | $zeile" ;;
	esac
done <<< "$fuss"
if [ -n "$fuss_fehlend" ]; then
	bad "the preview carries the stored footer in the text part" "missing:$fuss_fehlend"
elif [ "$fuss_zeilen" -lt 2 ]; then
	bad "the preview carries the stored footer in the text part" "only $fuss_zeilen lines of footer to look for"
else
	ok "the preview carries the stored footer in the text part ($fuss_zeilen lines)"
fi

# --- a wrong nonce changes nothing
rows_vorher=$(s mail-text-rows duty_signup)
curl -sk -o /dev/null -d "action=fg_save_mail&mail=duty_signup&fg_mail_nonce=verfalscht" \
	--data-urlencode "fg_mail_subject=Übernommen" --data-urlencode "fg_mail_body=Übernommen" "$BASE/wp-admin/admin-post.php"
if [ "$(s mail-text-rows duty_signup)" = "$rows_vorher" ]; then
	ok "a wrong nonce changes nothing"
else
	bad "a wrong nonce changes nothing" "the text changed anyway"
fi
has "a wrong nonce does not change the text" "$(s mail-text-body duty_signup)" "Servus, {{Vorname}}."

# --- one message is not the next
NONCE=$(printf '%s' "$formular" | grep -o 'name="fg_mail_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -sk -b "$JAR" -o /dev/null -d "action=fg_save_mail&mail=ride_pending&fg_mail_nonce=$NONCE" \
	--data-urlencode "fg_mail_subject=Fremd" --data-urlencode "fg_mail_body=Fremd" "$BASE/wp-admin/admin-post.php"

# The nonce of the form of one message opens the save of another. It has to:
# WordPress cannot know which message a form belongs to, and a nonce that
# belonged to one key would let a club open a form and then be told its own
# nonce is wrong for a message it never typed.
has "the text of another message can be saved with the nonce of this form" "$(s mail-text-body ride_pending)" "Fremd"

# A key that is not one of the five must not become a row of its own. The table
# has no foreign key to a list of keys, so this is the only thing that stops a
# row that no screen can edit and no send path reads.
fremd=$(s mail-text-rows fremde_mail)
if [ "$fremd" = "0" ]; then ok "an unknown key becomes no row"; else bad "an unknown key becomes no row" "$fremd rows"; fi

# The row above is put back now and not at the end of the section: the section
# continues with a check that makes a message of its own, and a leftover from
# this one would sit in the table as the state the section hands on.
s mail-text-reset ride_pending > /dev/null
if [ "$(s mail-text-rows ride_pending)" = "0" ]; then ok "the row of the other message is gone again"; else bad "the row of the other message is gone again" "$(s mail-text-rows ride_pending) rows"; fi

# --- back to the standard
formular_geaendert=$(curl -sk -b "$JAR" "$MAILS&mail=duty_signup")
RESET_NONCE=$(printf '%s' "$formular_geaendert" | grep -o 'name="fg_mail_reset_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
# The way back now exists, and its nonce has to be a field of its own. With the
# same field name as the save, one of the two actions could not be checked, and
# which one depends on the order the two handlers are registered in.
if [ -n "$RESET_NONCE" ]; then
	ok "a changed message offers a reset with a nonce of its own"
else
	bad "a changed message offers a reset with a nonce of its own" "no field with a value"
fi
if [ "$RESET_NONCE" = "$NONCE" ]; then
	bad "the nonce of the reset differs from the nonce of the save" "both are the same value"
else
	ok "the nonce of the reset differs from the nonce of the save"
fi

# The reset with the nonce of the save has to change nothing. The two fields have
# different names, and a check that only reads the names says nothing about what
# the server does with them: what counts is that the one nonce does not open the
# other action.
curl -sk -b "$JAR" -o /dev/null -d "action=fg_reset_mail&mail=duty_signup&fg_mail_reset_nonce=$NONCE" "$BASE/wp-admin/admin-post.php"
if [ "$(s mail-text-rows duty_signup)" = "1" ]; then
	ok "the save nonce does not open the reset"
else
	bad "the save nonce does not open the reset" "the text was reset anyway"
fi

curl -sk -b "$JAR" -o /dev/null -d "action=fg_reset_mail&mail=duty_signup&fg_mail_reset_nonce=$RESET_NONCE" "$BASE/wp-admin/admin-post.php"
rows=$(s mail-text-rows duty_signup)
if [ "$rows" = "0" ]; then ok "the reset removes the row"; else bad "the reset removes the row" "$rows rows"; fi

formular=$(curl -sk -b "$JAR" "$MAILS&mail=duty_signup")
has "the form shows the default text again" "$formular" "Der Link führt zu einer Seite, auf der du das Löschen noch einmal bestätigen musst."
hasnt "an unchanged message is not offered the way back" "$formular" "Auf Standard zurücksetzen"
s mail-text-body duty_signup | grep -q "{{Anrede}},$" && ok "the default text starts with the greeting" || bad "the default text starts with the greeting" "$(s mail-text-body duty_signup | head -1)"

# --- a key that is not one of the five opens no form
fremd_seite=$(curl -sk -b "$JAR" "$MAILS&mail=ganz_andere_mail")
clean "$fremd_seite" "unknown message"
hasnt "an unknown key opens no form" "$fremd_seite" 'name="fg_mail_body"'
hasnt "an unknown key opens no reset" "$fremd_seite" "Auf Standard zurücksetzen"

echo "[13b] the greeting of the messages"
# The greeting is the one place in a mail where a missing name shows up as a
# broken sentence, and it is the one thing a club cannot see: every address that
# does not belong to a member is a case the screen never shows. All four cases
# are read out of the messages that really went out.

IFS=$'\t' read -r an_ersteller an_fragend gesendet_e gesendet_f <<< "$(s mail-anrede "$ANREDE" Anredegruppe niemand@wohnen.example.org fremde.person@example.org)"
if [ "$an_ersteller" = "Hallo Anredegruppe," ]; then
	ok "an address of nobody is greeted by the designation ($an_ersteller)"
else
	bad "an address of nobody is greeted by the designation" "$an_ersteller"
fi
if [ "$an_fragend" = "Hallo," ]; then
	ok "an address of nobody with no designation is greeted plainly ($an_fragend)"
else
	bad "an address of nobody with no designation is greeted plainly" "$an_fragend"
fi

# Both addresses belong to members this section builds itself. Using a member of
# the fixture would tie the check to what the sections before it leave behind,
# and one of them prunes every member without a work service — so the address
# would be a stranger again and the greeting would fall back to the designation,
# green for a reason that has nothing to do with the code under test.
neu 7201 ersteller@angeln.example.org Greta Gruen
IFS=$'\t' read -r an_ersteller an_fragend _ _ <<< "$(s mail-anrede "$ANREDE" Anredegruppe ersteller@angeln.example.org fremde.person@example.org)"
if [ "$an_ersteller" = "Hallo Greta," ]; then
	ok "the member behind the address is greeted by the first name ($an_ersteller)"
else
	bad "the member behind the address is greeted by the first name" "$an_ersteller"
fi

neu 7202 fragende@angeln.example.org Frieda Fraglich
IFS=$'\t' read -r an_ersteller an_fragend _ _ <<< "$(s mail-anrede "$ANREDE" Anredegruppe niemand@wohnen.example.org fragende@angeln.example.org)"
if [ "$an_fragend" = "Hallo Frieda," ]; then
	ok "a member who asks for contact is greeted by the first name ($an_fragend)"
else
	bad "a member who asks for contact is greeted by the first name" "$an_fragend"
fi
s delete-member "$(s member-by-no 7201 id)" > /dev/null
s delete-member "$(s member-by-no 7202 id)" > /dev/null

anrede_dienst=$(s mail-greeting "$ANREDE" 7101 dienst@angeln.example.org Anton Beispiel)
if [ "$anrede_dienst" = "Hallo Anton," ]; then
	ok "the signup mail greets the member by the first name ($anrede_dienst)"
else
	bad "the signup mail greets the member by the first name" "$anrede_dienst"
fi

# The greeting has to be there in the finished message and not only in a helper.
# Two of the four cases above are the two ends of the fallback, and a member's
# name is a piece of personal data that leaves the club with the mail.
s mail-text-body duty_signup | grep -q '^{{Anrede}},' && ok "the default text of the signup mail begins with the greeting" || bad "the default text of the signup mail begins with the greeting" "$(s mail-text-body duty_signup | head -1)"

echo "[13c] a stored text with a placeholder this version does not know"
# A club can edit with a newer version and then go back to an older one. The
# text in the table then names a placeholder this version has no value for, and
# the message must not go out half-empty. The row is written directly, because
# the screen refuses it — no form can build the state that has to be tested.
ergebnis=$(s mail-holdback "$ANREDE")
IFS=$'\t' read -r weg vorher nachher notizen <<< "$ergebnis"
if [ "$weg" = "held-back" ]; then
	ok "the send reports that it sent nothing ($weg)"
else
	bad "the send reports that it sent nothing" "$weg"
fi
if [ "$nachher" = "$vorher" ]; then
	ok "the mail log does not grow ($vorher before, $nachher after)"
else
	bad "the mail log does not grow" "$vorher before, $nachher after"
fi
if [ "$notizen" -ge 1 ] 2>/dev/null; then
	ok "a notice stands for the club ($notizen)"
else
	bad "a notice stands for the club" "$notizen notices"
fi

meldung=$(kasten 'fg-mail-meldung' <<< "$(curl -sk -b "$JAR" "$MAILS")")
pruef "$meldung" "the notice names the message" "
import sys
sys.exit(0 if 'Fahrgemeinschaft bestätigen' in sys.stdin.read() else 1)
" "the box does not name the message"
pruef "$meldung" "the notice says the message was not sent" "
import sys
sys.exit(0 if 'nicht verschickt' in sys.stdin.read() else 1)
" "the box does not say that the message was held back"
pruef "$meldung" "the notice names the unknown placeholder" "
import sys
sys.exit(0 if '{{Erfunden}}' in sys.stdin.read() else 1)
" "the box does not name {{Erfunden}}"
# It must not promise a queue. There is no queue, and a sentence about one
# leaves a club waiting for a mail that is gone.
pruef "$meldung" "the notice does not promise a queue" "
import sys
h = sys.stdin.read()
sys.exit(1 if ('Warteschlange' in h or 'geht raus, sobald' in h) else 0)
" "the notice promises a queue that does not exist"

s mail-text-clear > /dev/null
if [ "$(s mail-text-notices)" = "0" ]; then ok "the notices can be cleared"; else bad "the notices can be cleared" "$(s mail-text-notices) left"; fi
hasnt "a cleared notice is gone from the screen" "$(curl -sk -b "$JAR" "$MAILS")" "nicht verschickt"
if [ "$(s mail-text-rows ride_pending)" = "0" ]; then ok "the held-back text is gone again"; else bad "the held-back text is gone again" "$(s mail-text-rows ride_pending) rows"; fi

# Every work duty of this run is removed again, so a repeated run does not pile
# them up in the offer form of a manual test afterwards. The list is written
# here, at the end, and holds every id the suite has built — not the ones the
# sections below happened to remember. A duty that is only removed in the one
# section that created it is a duty that is left behind as soon as that
# section changes, and that is how three of them survived a run that reported
# itself clean.
ALLEDIENSTE="$EVENT_ID $CASCADE $LOESCH $VOLLEDUTY $FREIES $OHNE $WEG $NEW_ID $ANREDE"
ERWARTET=0
GERAUMT=0
for dienst in $ALLEDIENSTE; do
	case "$dienst" in
		''|*[!0-9]*) continue ;;
	esac
	[ "$dienst" -gt 0 ] || continue
	ERWARTET=$((ERWARTET + 1))
	s delete-event "$dienst" > /dev/null
	if [ "$(s exists-event "$dienst")" = "0" ]; then
		GERAUMT=$((GERAUMT + 1))
	else
		bad "work duty $dienst is gone again" "still there"
	fi
done
if [ "$GERAUMT" = "$ERWARTET" ] && [ "$ERWARTET" -gt 0 ]; then
	ok "every work duty of this run is gone again ($GERAUMT)"
else
	bad "every work duty of this run is gone again" "$GERAUMT of $ERWARTET"
fi

s settings-restore "$SETTINGS_BEFORE" > /dev/null
if [ "$(s settings-json)" = "$SETTINGS_BEFORE" ]; then ok "the settings of before the run are back"; else bad "the settings of before the run are back" "$(s settings-json)"; fi

echo
echo "== $pass passed, $fail failed =="
[ "$fail" -eq 0 ]
