#!/usr/bin/env bash
# HTTP level checks against the running WordPress instance.
set -u
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE="https://localhost:8443"
HTTP="http://localhost:8080"
F="$DIR/fixture.json"
jq() { python3 -c "import json,sys;d=json.load(open('$F'));print(d['$1'])"; }

PAGE_ID=$(jq page_id)
PENDING_REF=$(jq pending_ref)
CONFIRM_TOKEN=$(jq confirm_token)
CONFIRM_NONCE=$(jq confirm_nonce)
PUBLISHED_REF=$(jq published_ref)
DELETE_TOKEN=$(jq delete_token)
DELETE_NONCE=$(jq delete_nonce)
EVENT_UUID=$(jq event_uuid)

STATE="docker exec wpdev-wordpress-1 php /tmp/fgtests/state.php"

# Read the tables of the plugin instead of guessing from the rendered page.
s() { $STATE "$@"; }

pass=0; fail=0
ok()   { printf '  ok   %s\n' "$1"; pass=$((pass+1)); }
bad()  { printf '  FAIL %s :: %s\n' "$1" "$2"; fail=$((fail+1)); }
has()  { if printf '%s' "$2" | grep -qF -- "$3"; then ok "$1"; else bad "$1" "missing: $3"; fi; }
hasnt(){ if printf '%s' "$2" | grep -qF -- "$3"; then bad "$1" "found: $3"; else ok "$1"; fi; }

echo "== HTTP level tests =="

# --- 1. public page over HTTPS
echo "[1] public page"
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
has "page renders the offer form" "$body" "Fahrgemeinschaft anbieten"
has "page lists the published ride" "$body" "Amsel-Gruppe"
hasnt "page hides the event address" "$body" "anton@angeln.example.org"
hasnt "page hides the creator address" "$body" "berta@angeln.example.org"
hasnt "page hides the event uuid" "$body" "$EVENT_UUID"
hasnt "page hides internal ids" "$body" "fg_fahrgemeinschaft="
hasnt "page hides internal meta" "$body" "_fg_"
hasnt "page hides tokens" "$body" "token="

# --- 2. HTTPS enforcement
echo "[2] HTTPS enforcement"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' "http://localhost:8080/?page_id=$PAGE_ID")
if [ "$loc" = "https://localhost:8443/?page_id=$PAGE_ID" ]; then
	ok "http request is redirected to https with the same query"
else
	bad "http request is redirected to https with the same query" "$loc"
fi
loc=$(curl -s -o /dev/null -w '%{redirect_url}' "http://localhost:8080/?fg_ride_action=view&ride_ref=$PENDING_REF&intent=confirm&token=$CONFIRM_TOKEN")
if printf '%s' "$loc" | grep -q "^https://localhost:8443/"; then
	ok "token page over http is redirected to https"
else
	bad "token page over http is redirected to https" "$loc"
fi
code=$(curl -sk -o /dev/null -w '%{http_code}' "$BASE/?page_id=999999")
if [ "$code" = "404" ] || [ "$code" = "200" ]; then ok "unrelated pages are untouched ($code)"; else bad "unrelated pages are untouched" "$code"; fi

# --- 3. token landing page
echo "[3] token landing page"
head=$(curl -sk -D - -o "$DIR/confirm.html" "$BASE/?fg_ride_action=view&ride_ref=$PENDING_REF&intent=confirm&token=$CONFIRM_TOKEN")
html=$(cat "$DIR/confirm.html")
has "confirm page renders" "$html" "Veröffentlichung bestätigen"
has "confirm page shows the alias" "$html" "Moewe-Trupp"
hasnt "confirm page hides the address" "$html" "anton@angeln.example.org"
has "form posts to admin-post.php" "$html" "wp-admin/admin-post.php"
has "form carries the token verifier" "$html" "name=\"token_nonce\""
hasnt "no theme stylesheet" "$html" "wp-content/themes"
hasnt "no theme script" "$html" "<script"
has "x-robots-tag header" "$head" "X-Robots-Tag: noindex"
has "content security policy" "$head" "Content-Security-Policy"
has "no-store cache header" "$head" "no-store"
has "nosniff header" "$head" "X-Content-Type-Options: nosniff"
has "referrer policy" "$head" "Referrer-Policy: no-referrer"

echo "[3b] invalid token page"
html=$(curl -sk "$BASE/?fg_ride_action=view&ride_ref=$PENDING_REF&intent=confirm&token=falsch")
has "invalid token is refused" "$html" "Link nicht gültig"

echo "[3c] token page over GET does not change anything"
code=$(curl -sk -o /dev/null -w '%{http_code}' "$BASE/?fg_ride_action=view&ride_ref=$PENDING_REF&intent=delete&token=$DELETE_TOKEN")
pending_state=$(s statuses)
if [ "$pending_state" = "pending,published," ]; then ok "GET never changes a status ($pending_state)"; else bad "GET never changes a status" "$pending_state"; fi

# --- 4. confirm over real HTTPS POST
echo "[4] confirmation over POST"
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$PENDING_REF" \
	--data-urlencode "intent=confirm" \
	--data-urlencode "token=$CONFIRM_TOKEN" \
	--data-urlencode "token_nonce=$CONFIRM_NONCE" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID")
if printf '%s' "$loc" | grep -q "fg_notice=published"; then ok "confirmation succeeds ($loc)"; else bad "confirmation succeeds" "$loc"; fi
state=$(s count-pending-token)
if [ "$state" = "0" ]; then ok "pending token is consumed"; else bad "pending token is consumed" "$state"; fi
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
has "confirmed ride appears in the list" "$body" "Moewe-Trupp"

echo "[4b] replay of the same token"
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$PENDING_REF" \
	--data-urlencode "intent=confirm" \
	--data-urlencode "token=$CONFIRM_TOKEN" \
	--data-urlencode "token_nonce=$CONFIRM_NONCE" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID")
if printf '%s' "$loc" | grep -q "fg_notice=invalid_token"; then ok "replay is refused ($loc)"; else bad "replay is refused" "$loc"; fi

echo "[4c] mutations are POST only"
get_head=$(curl -sk -D - -o /dev/null "$BASE/wp-admin/admin-post.php?action=fg_process_ride_token&ride_ref=$PUBLISHED_REF&intent=delete&token=$DELETE_TOKEN")
code=$(printf '%s' "$get_head" | head -1 | grep -o '[0-9]\{3\}')
if [ "$code" = "405" ]; then ok "GET on admin-post.php answers 405"; else bad "GET on admin-post.php answers 405" "$code"; fi
if printf '%s' "$get_head" | grep -qi "^allow: POST"; then ok "405 carries an Allow: POST header"; else bad "405 carries an Allow: POST header" "$(printf '%s' "$get_head" | tr -d '\r' | tr '\n' '|')"; fi
still=$(s count-published)
if [ "$still" = "2" ]; then ok "refused requests changed nothing ($still published)"; else bad "refused requests changed nothing" "$still"; fi

# --- 5. contact form over POST
echo "[5] contact request"
submit_nonce=$(curl -sk "$BASE/?page_id=$PAGE_ID" | grep -o 'name="fg_contact_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
contact_nonce=$(curl -sk "$BASE/?page_id=$PAGE_ID&ride_ref=$PUBLISHED_REF" | grep -o 'name="fg_contact_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$PUBLISHED_REF" \
	--data-urlencode "fg_contact_email=cem@angeln.example.org" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=$contact_nonce")
if printf '%s' "$loc" | grep -q "fg_notice=contact_received"; then ok "participant contact answered neutrally"; else bad "participant contact answered neutrally" "$loc"; fi
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$PUBLISHED_REF" \
	--data-urlencode "fg_contact_email=fremd@example.com" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=$contact_nonce")
if printf '%s' "$loc" | grep -q "fg_notice=contact_received"; then ok "foreign address answered identically"; else bad "foreign address answered identically" "$loc"; fi

echo "[5b] contact form on the page"
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
has "contact dialog is present" "$body" "Kontakt"
hasnt "contact form has no free text" "$body" "textarea"

# --- 6. deletion over POST
echo "[6] self service deletion"
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$PUBLISHED_REF" \
	--data-urlencode "intent=delete" \
	--data-urlencode "token=$DELETE_TOKEN" \
	--data-urlencode "token_nonce=$DELETE_NONCE" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID")
if printf '%s' "$loc" | grep -q "fg_notice=deleted"; then ok "deletion succeeds"; else bad "deletion succeeds" "$loc"; fi
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
hasnt "deleted ride is gone" "$body" "Amsel-Gruppe"

# --- 7. plain submission over real POST
echo "[7] submission with a form nonce from the page"
submit_nonce=$(curl -sk "$BASE/?page_id=$PAGE_ID" | grep -o 'name="fg_submit_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
event_ref=$(python3 -c "import json;print(json.load(open('$F'))['event_ref'])")
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_submit_ride" \
	--data-urlencode "fg_event_ref=$event_ref" \
	--data-urlencode "fg_mode=search" \
	--data-urlencode "fg_alias=Testfahrt Curl" \
	--data-urlencode "fg_origin=Weststadt" \
	--data-urlencode "fg_contact_email=cem@angeln.example.org" \
	--data-urlencode "fg_consent=1" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_submit_nonce=$submit_nonce")
if printf '%s' "$loc" | grep -q "fg_notice=pending"; then ok "submission is vorkereed ($loc)"; else bad "submission is vorkereed" "$loc"; fi
hasnt "pending ride stays private" "$(curl -sk "$BASE/?page_id=$PAGE_ID")" "Testfahrt Curl"
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_submit_ride" \
	--data-urlencode "fg_event_ref=$event_ref" \
	--data-urlencode "fg_mode=search" \
	--data-urlencode "fg_alias=Testfahrt Curl" \
	--data-urlencode "fg_origin=Weststadt" \
	--data-urlencode "fg_contact_email=cem@angeln.example.org" \
	--data-urlencode "fg_consent=1" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_submit_nonce=manipuliert")
if printf '%s' "$loc" | grep -q "fg_notice=form_expired"; then ok "manipulated nonce gets a reload hint ($loc)"; else bad "manipulated nonce gets a reload hint" "$loc"; fi

echo
echo "== $pass passed, $fail failed =="
[ "$fail" -eq 0 ]
