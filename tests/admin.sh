#!/usr/bin/env bash
# Admin level checks with a real login, including one save round trip.
set -u
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE="https://localhost:8443"
JAR="$DIR/cookies.txt"
F="$DIR/fixture.json"
STATE="php /tmp/fgtests/state.php"
rm -f "$JAR"

# Read or change plugin state from the table layer.
s() { docker exec wpdev-wordpress-1 $STATE "$@"; }

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
link() { python3 -c "
import re,sys
h=sys.stdin.read()
m=re.search(r'href=\"([^\"]*action=fg_delete_record[^\"]*)\"',h)
print(m.group(1).replace('&#038;','&').replace('&amp;','&') if m else '')"; }

echo "== Admin level tests =="

EVENT_ID=$(python3 -c "import json;print(json.load(open('$F'))['event_id'])")

# --- login
login=$(curl -sk -c "$JAR" -d "log=fg_admin&pwd=Test1234!&wp-submit=Anmelden&redirect_to=$BASE/wp-admin/&testcookie=1" "$BASE/wp-login.php")
if grep -q "wordpress_logged_in" "$JAR"; then ok "login works"; else bad "login works" "$(printf '%s' "$login" | grep -oE 'id="login_error"[^<]*<[^<]*' | head -1)"; exit 1; fi

# --- statistics page
echo "[1] statistics screen"
stats=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften")
has "menu page renders" "$stats" "Statistik"
# The top level entry carries the plugin name. Its own page is the statistics
# screen, which is listed last, so the name of the top level entry and the name
# of its page are deliberately not the same word.
nav=$(printf '%s' "$stats" | python3 -c "
import re, sys
html = sys.stdin.read()
# Both the collapsed entry and the hidden submenu head take their text from the
# menu label passed to add_menu_page(). The search is anchored on the list item
# id, because the slug also occurs in inline scripts.
m = re.search(r'id=\"toplevel_page_fahrgemeinschaften\">(.*?)</ul>', html, re.S)
if not m:
    print('')
else:
    block = m.group(1)
    name = re.search(r\"<div class='wp-menu-name'>([^<]*)</div>\", block)
    head = re.search(r\"<li class='wp-submenu-head'[^>]*>([^<]*)</li>\", block)
    print((name.group(1).strip() if name else '') + '|' + (head.group(1).strip() if head else ''))")
if [ "$nav" = "Fahrgemeinschaften|Fahrgemeinschaften" ]; then
	ok "top level entry keeps the plugin name"
else
	bad "top level entry keeps the plugin name" "found: ${nav:-<no menu block>}"
fi
has "page heading matches" "$stats" "<h1>Statistik</h1>"

# The submenu order is part of the interface: Arbeitsdienste, Fahrgemeinschaften,
# Statistik. WordPress links the top level entry to whatever is first, so this
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
if [ "$order" = "Arbeitsdienste > Fahrgemeinschaften > Einstellungen > Statistik" ]; then
	ok "submenu shows the screens in the intended order"
else
	bad "submenu shows the screens in the intended order" "found: ${order:-<no menu block>}"
fi
has "shows the retention note" "$stats" "nach 90 Tagen entfernt"
has "shows the three periods" "$stats" "Letzte 30 Tage"
has "shows counter labels" "$stats" "Vorgemerkte Einträge"
hasnt "statistics show no address" "$stats" "@example"
has "work duty screen is linked" "$stats" "page=fahrgemeinschaften-events"
has "ride screen is linked" "$stats" "page=fahrgemeinschaften-rides"
has "settings screen is linked" "$stats" "page=fahrgemeinschaften-settings"
clean "$stats" "statistics screen"

# The same order has to be visible from every screen of the plugin, and the
# entry of the screen that is open has to be the marked one.
for pair in "fahrgemeinschaften-events:Arbeitsdienste" "fahrgemeinschaften-rides:Fahrgemeinschaften" "fahrgemeinschaften-settings:Einstellungen"; do
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
	if [ "$there" = "Arbeitsdienste > Fahrgemeinschaften > Einstellungen > Statistik" ]; then
		ok "order holds on the $label screen"
	else
		bad "order holds on the $label screen" "found: ${there:-<no menu block>}"
	fi
	clean "$body" "$label screen"
done

# --- event list
echo "[2] work duty list"
events=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events")
has "event is listed" "$events" "Arbeitsdienst Laber"
has "date column exists" "$events" ">Datum<"
has "group column exists" "$events" ">Gruppe<"
has "demand column exists" "$events" ">Bedarf<"
has "visibility column exists" "$events" "Öffentlich sichtbar"
has "participant column exists" "$events" ">Teilnehmer<"
has "edit link exists" "$events" "event=$EVENT_ID"
hasnt "no trash column" "$events" ">Papierkorb<"
# The four fields are optional, so the list has to be able to say "nothing
# stated" without inventing a number for it. The cell of one known record is
# read on its own: the counts of the other columns are numbers too, so a search
# over the whole screen would be measuring the wrong thing. The cell of the
# demand is the fourth, after title, date and group.
demand_cell=$(printf '%s' "$events" | python3 -c "
import re, sys
html = sys.stdin.read()
m = re.search(r'<tr[^>]*>.*?event=$EVENT_ID.*?</tr>', html, re.S)
if not m:
    print('no-row')
else:
    cells = re.findall(r'<td[^>]*>(.*?)</td>', m.group(0), re.S)
    print(re.sub(r'<[^>]+>', '', cells[3]).strip() if len(cells) > 3 else 'too-few-cells:%d' % len(cells))")
if [ "$demand_cell" = "—" ]; then
	ok "a duty with no demand shows a dash, not a zero"
else
	bad "a duty with no demand shows a dash, not a zero" "cell reads: $demand_cell"
fi
clean "$events" "event list"

# --- event form
echo "[3] work duty form"
screen=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events&event=$EVENT_ID")
has "date field present" "$screen" 'name="fg_event_date"'
has "time field present" "$screen" 'name="fg_event_time"'
has "participants field present" "$screen" 'name="fg_event_participants"'
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

echo "[4b] filtering the ride list"
filtered=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-rides&fg_status_filter=pending&fg_event_filter=$EVENT_ID")
has "pending filter keeps the pending ride" "$filtered" "Wartende Fahrt"
hasnt "pending filter hides the published ride" "$filtered" "Amsel-Gruppe"
other=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-rides&fg_status_filter=published")
has "published filter keeps the published ride" "$other" "Amsel-Gruppe"
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
	--data-urlencode "fg_event_participants=neu@example.org
zweite@example.org")
saved=$(cat "$DIR/saved.html")
has "save confirms the new record" "$saved" "Der Arbeitsdienst wurde angelegt."
has "save opens the new record" "$out" "event="
NEW_ID=$(s find-event "Neuer Dienst aus dem Admin")
if [ "$NEW_ID" -gt 0 ]; then ok "new work duty is stored ($NEW_ID)"; else bad "new work duty is stored" "$NEW_ID"; fi
if [ "$(s event "$NEW_ID" event_date)" = "2027-03-04" ]; then ok "date stored"; else bad "date stored" "$(s event "$NEW_ID" event_date)"; fi
if [ "$(s event "$NEW_ID" participants)" = "neu@example.org,zweite@example.org" ]; then ok "participants stored"; else bad "participants stored" "$(s event "$NEW_ID" participants)"; fi
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
	--data-urlencode "fg_event_participants=neu@example.org")
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
	--data-urlencode "fg_event_participants=neu@example.org")
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
	--data-urlencode "fg_event_participants=neu@example.org")
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
		--data-urlencode "fg_event_participants=neu@example.org")
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
	--data-urlencode "fg_event_participants=neu@example.org")
exact=$(cat "$DIR/exact.html")
hasnt "a text exactly on the limit is not refused" "$exact" "Es wurde nichts gespeichert."
if [ "$(s event "$NEW_ID" demand)" = "0" ]; then ok "a demand of zero is stored as no statement"; else bad "a demand of zero is stored as no statement" "$(s event "$NEW_ID" demand)"; fi
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
	--data-urlencode "fg_event_participants=neu@example.org")
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
	--data-urlencode "fg_event_participants=neu@example.org")
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
	--data-urlencode "fg_event_participants=neu@example.org")
if [ "$out" = "403" ]; then ok "a wrong nonce is refused ($out)"; else bad "a wrong nonce is refused" "$out"; fi
if [ "$(s event "$NEW_ID" title)" = "Geänderter Dienst" ]; then ok "title unchanged after a refused save"; else bad "title unchanged after a refused save" "$(s event "$NEW_ID" title)"; fi

echo "[5e] an invalid participant address is dropped, the rest is stored"
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/people.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_event" \
	--data-urlencode "fg_event_id=$NEW_ID" \
	--data-urlencode "fg_event_nonce=$ENONCE" \
	--data-urlencode "fg_title=Geänderter Dienst" \
	--data-urlencode "fg_event_date=2027-03-05" \
	--data-urlencode "fg_event_time=" \
	--data-urlencode "fg_event_active=1" \
	--data-urlencode "fg_event_participants=anton@angeln.example.org
kaputt")
people=$(s event "$NEW_ID" participants)
if [ "$people" = "anton@angeln.example.org" ]; then ok "invalid participant dropped ($people)"; else bad "invalid participant dropped" "$people"; fi
clean "$(cat "$DIR/people.html")" "work duty save with an invalid participant"

echo "[5f] a new record is not reachable without a nonce"
out=$(curl -sk -b "$JAR" -o "$DIR/anon.html" -w '%{http_code}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_event" \
	--data-urlencode "fg_event_id=0" \
	--data-urlencode "fg_title=Ohne Sitzung" \
	--data-urlencode "fg_event_date=2027-03-05" \
	--data-urlencode "fg_event_participants=neu@example.org")
if [ "$out" = "302" ] || [ "$out" = "403" ]; then ok "an anonymous save does not succeed ($out)"; else bad "an anonymous save does not succeed" "$out"; fi
if [ "$(s find-event "Ohne Sitzung")" = "0" ]; then ok "no record was created anonymously"; else bad "no record was created anonymously" "created"; fi

# --- permanent deletion of a ride
echo "[6] permanent deletion of a ride"
ridedel=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-rides&ride=$RIDE_ID")
del_url=$(printf '%s' "$ridedel" | link "action=fg_delete_record")
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
CASCADE=$(s make-event "Kaskade" "$(date -d '+30 days' +%Y-%m-%d)" "kette@example.org")
s make-ride "$CASCADE" offer "Kaskadenfahrt" Innenstadt kette@example.org published >/dev/null
s make-ride "$CASCADE" search "Kaskadenfahrt zwei" Suedstadt kette@example.org >/dev/null
if [ "$(s count-event-rides "$CASCADE")" = "2" ]; then ok "cascade source has two rides"; else bad "cascade source has two rides" "$(s count-event-rides "$CASCADE")"; fi
form=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events&event=$CASCADE")
has "delete question counts the rides" "$form" "2 zugehörige Fahrgemeinschaften"
del_url=$(printf '%s' "$form" | link "action=fg_delete_record")
if [ -n "$del_url" ]; then ok "delete link found"; else bad "delete link found" "no link on the screen"; fi
out=$(curl -sk -b "$JAR" -L "$del_url")
has "delete notice mentions the cascade" "$out" "zugehörige"
clean "$out" "cascade deletion"
if [ "$(s count-event-rides "$CASCADE")" = "0" ]; then ok "rides of the deleted work duty are gone"; else bad "rides of the deleted work duty are gone" "$(s count-event-rides "$CASCADE")"; fi
if [ "$(s exists-event "$CASCADE")" = "0" ]; then ok "work duty is gone"; else bad "work duty is gone" "still there"; fi

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
has "sender field present" "$screen" 'name="fg_footer_organisation"'
has "contact field present" "$screen" 'name="fg_footer_contact"'
has "legal field present" "$screen" 'name="fg_footer_legal"'
has "the three fields are marked as mandatory" "$screen" 'id="fg-footer-legal" name="fg_footer_legal" rows="4" class="large-text" required'
hasnt "no field offers a path to a file" "$screen" "logo_path"
hasnt "no field offers a url for the logo" "$screen" "logo_url"
has "preview link present" "$screen" "action=fg_mail_preview"
has "preview link carries a nonce" "$screen" "action=fg_mail_preview&#038;_wpnonce="
clean "$screen" "settings screen"

echo "[8b] the mandatory fields refuse an incomplete footer"
SNONCE=$(val /dev/stdin fg_settings_nonce <<< "$screen")
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/settings-bad.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_settings" \
	--data-urlencode "fg_settings_nonce=$SNONCE" \
	--data-urlencode "fg_logo_attachment_id=0" \
	--data-urlencode "fg_footer_organisation=Musterverein e.V." \
	--data-urlencode "fg_footer_contact=" \
	--data-urlencode "fg_footer_legal=Angaben gemäß § 5 TMG")
refused=$(cat "$DIR/settings-bad.html")
has "the missing field is named" "$refused" "Kontakt"
has "it says that nothing was saved" "$refused" "Es wurde nichts gespeichert"
has "it returns to the settings screen" "$out" "page=fahrgemeinschaften-settings"
# Compared with the state of before the run and not with "empty": a footer that
# was configured by hand has to stay untouched as well.
if [ "$(s settings-json)" = "$SETTINGS_BEFORE" ]; then ok "nothing was stored"; else bad "nothing was stored" "$(s settings-json)"; fi
clean "$refused" "settings save with an incomplete footer"

echo "[8c] a complete footer is stored"
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/settings-saved.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_settings" \
	--data-urlencode "fg_settings_nonce=$SNONCE" \
	--data-urlencode "fg_logo_attachment_id=0" \
	--data-urlencode "fg_footer_organisation=Musterverein e.V.
Musterstraße 1" \
	--data-urlencode "fg_footer_contact=info@angeln.example.org" \
	--data-urlencode "fg_footer_legal=Angaben gemäß § 5 TMG: Musterverein e.V.")
stored=$(cat "$DIR/settings-saved.html")
has "save confirms" "$stored" "Die Einstellungen wurden gespeichert."
if [ "$(s settings footer_organisation | tr '\n' ',')" = "Musterverein e.V.,Musterstraße 1," ]; then
	ok "sender stored with its line break"
else
	bad "sender stored with its line break" "$(s settings footer_organisation | tr '\n' ',')"
fi
if [ "$(s settings footer_contact)" = "info@angeln.example.org" ]; then ok "contact stored"; else bad "contact stored" "$(s settings footer_contact)"; fi
if [ "$(s settings logo_attachment_id)" = "0" ]; then ok "no logo stored"; else bad "no logo stored" "$(s settings logo_attachment_id)"; fi
AFTER_SAVE=$(s settings-json)
clean "$stored" "settings save"

echo "[8d] a wrong nonce changes nothing"
out=$(curl -sk -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_settings" \
	--data-urlencode "fg_settings_nonce=manipuliert" \
	--data-urlencode "fg_footer_organisation=Ohne Erlaubnis" \
	--data-urlencode "fg_footer_contact=info@angeln.example.org" \
	--data-urlencode "fg_footer_legal=Angaben gemäß § 5 TMG")
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
has "preview carries the stored sender" "$preview" "Musterverein e.V."
has "preview carries the stored contact" "$preview" "info@angeln.example.org"
has "preview carries the stored legal notice" "$preview" "§ 5 TMG"
has "preview escapes a visitor value" "$preview" "Müller &amp; Söhne"
hasnt "preview keeps the raw ampersand" "$preview" "Müller & Söhne"
hasnt "preview loads no foreign address" "$preview" 'src="http'
hasnt "preview has no unresolved token" "$preview" "{{"
clean "$preview" "mail preview"

echo "[8f] the preview needs a nonce"
out=$(curl -sk -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/wp-admin/admin-post.php?action=fg_mail_preview")
if [ "$out" = "403" ]; then ok "the preview is refused without a nonce ($out)"; else bad "the preview is refused without a nonce" "$out"; fi

# The work duty from [5] is an active record dated 2027 and would sit in the
# offer form of a manual test afterwards. Removing it keeps the suite from
# leaving a row behind on every run; the cascade count of [6c] stays untouched
# because that section deletes its own record.
if [ -n "$NEW_ID" ] && [ "$NEW_ID" -gt 0 ]; then
	s delete-event "$NEW_ID" > /dev/null
	if [ "$(s exists-event "$NEW_ID")" = "0" ]; then ok "the work duty of this run is gone again"; else bad "the work duty of this run is gone again" "id $NEW_ID still there"; fi
else
	bad "the work duty of this run is gone again" "no id to remove"
fi

s settings-restore "$SETTINGS_BEFORE" > /dev/null
if [ "$(s settings-json)" = "$SETTINGS_BEFORE" ]; then ok "the settings of before the run are back"; else bad "the settings of before the run are back" "$(s settings-json)"; fi

echo
echo "== $pass passed, $fail failed =="
[ "$fail" -eq 0 ]
