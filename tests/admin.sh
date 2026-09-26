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
if [ "$order" = "Arbeitsdienste > Fahrgemeinschaften > Statistik" ]; then
	ok "submenu shows the three screens in the intended order"
else
	bad "submenu shows the three screens in the intended order" "found: ${order:-<no menu block>}"
fi
has "shows the retention note" "$stats" "nach 90 Tagen entfernt"
has "shows the three periods" "$stats" "Letzte 30 Tage"
has "shows counter labels" "$stats" "Vorgemerkte Einträge"
hasnt "statistics show no address" "$stats" "@example"
has "work duty screen is linked" "$stats" "page=fahrgemeinschaften-events"
has "ride screen is linked" "$stats" "page=fahrgemeinschaften-rides"
clean "$stats" "statistics screen"

# The same order has to be visible from every screen of the plugin, and the
# entry of the screen that is open has to be the marked one.
for pair in "fahrgemeinschaften-events:Arbeitsdienste" "fahrgemeinschaften-rides:Fahrgemeinschaften"; do
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
	if [ "$there" = "Arbeitsdienste > Fahrgemeinschaften > Statistik" ]; then
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
has "visibility column exists" "$events" "Öffentlich sichtbar"
has "participant column exists" "$events" ">Teilnehmer<"
has "edit link exists" "$events" "event=$EVENT_ID"
hasnt "no trash column" "$events" ">Papierkorb<"
clean "$events" "event list"

# --- event form
echo "[3] work duty form"
screen=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events&event=$EVENT_ID")
has "date field present" "$screen" 'name="fg_event_date"'
has "time field present" "$screen" 'name="fg_event_time"'
has "participants field present" "$screen" 'name="fg_event_participants"'
has "visibility checkbox present" "$screen" 'name="fg_event_active"'
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
clean "$saved" "work duty create"

echo "[5b] editing the new work duty"
editform=$(curl -sk -b "$JAR" "$BASE/wp-admin/admin.php?page=fahrgemeinschaften-events&event=$NEW_ID")
has "form is filled with the stored title" "$editform" 'value="Neuer Dienst aus dem Admin"'
has "form is filled with the stored time" "$editform" 'value="07:30"'
ENONCE=$(val /dev/stdin fg_event_nonce <<< "$editform")
out=$(curl -sk -b "$JAR" -L -c "$JAR" -o "$DIR/edited.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_save_event" \
	--data-urlencode "fg_event_id=$NEW_ID" \
	--data-urlencode "fg_event_nonce=$ENONCE" \
	--data-urlencode "fg_title=Geänderter Dienst" \
	--data-urlencode "fg_event_date=2027-03-05" \
	--data-urlencode "fg_event_time=" \
	--data-urlencode "fg_event_participants=neu@example.org")
edited=$(cat "$DIR/edited.html")
has "save confirms the change" "$edited" "Der Arbeitsdienst wurde gespeichert."
if [ "$(s event "$NEW_ID" title)" = "Geänderter Dienst" ]; then ok "title stored"; else bad "title stored" "$(s event "$NEW_ID" title)"; fi
if [ "$(s event "$NEW_ID" event_date)" = "2027-03-05" ]; then ok "changed date stored"; else bad "changed date stored" "$(s event "$NEW_ID" event_date)"; fi
if [ "$(s event "$NEW_ID" event_time)" = "" ]; then ok "cleared time stored as empty"; else bad "cleared time stored as empty" "$(s event "$NEW_ID" event_time)"; fi
clean "$edited" "work duty edit"

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

echo
echo "== $pass passed, $fail failed =="
[ "$fail" -eq 0 ]
