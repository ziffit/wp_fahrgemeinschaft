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
# A structural check: the given text is handed to python on stdin, the snippet decides.
struct() { if python3 -c "$3" <<< "$1"; then ok "$2"; else bad "$2" "$4"; fi; }
# The value of a hidden field, read out of the page. Every token this suite
# sends is taken from a rendered page and never computed: a test that builds the
# value itself proves only that the value it built is the value it sent.
val() { python3 -c "
import re,sys
h=sys.stdin.read()
m=re.search(r'name=\"$1\"[^>]*value=\"([^\"]*)\"',h) or re.search(r'value=\"([^\"]*)\"[^>]*name=\"$1\"',h)
print(m.group(1) if m else '')"; }

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
# The form on the token page carries a nonce of its own. Without it the
# confirmation is refused with the sentence about a stale form, not with the one
# about a dead link: a page that was opened a while ago is fixed by reloading
# it, and that is what the visitor is told. The check happens while the entry is
# still pending, so the refusal cannot come from the entry being gone already.
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$PENDING_REF" \
	--data-urlencode "intent=confirm" \
	--data-urlencode "token=$CONFIRM_TOKEN" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID")
if printf '%s' "$loc" | grep -q "fg_notice=form_expired"; then ok "confirmation without the form nonce is refused"; else bad "confirmation without the form nonce is refused" "$loc"; fi
if [ "$(s statuses)" = "pending,published," ]; then ok "and publishes nothing"; else bad "and publishes nothing" "$(s statuses)"; fi
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$PENDING_REF" \
	--data-urlencode "intent=confirm" \
	--data-urlencode "token=$CONFIRM_TOKEN" \
	--data-urlencode "token_nonce=falsch" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID")
if printf '%s' "$loc" | grep -q "fg_notice=form_expired"; then ok "a wrong form nonce is refused too"; else bad "a wrong form nonce is refused too" "$loc"; fi
if [ "$(s statuses)" = "pending,published," ]; then ok "and publishes nothing there either"; else bad "and publishes nothing there either" "$(s statuses)"; fi
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
# The answer of the contact form is the same whether the request was forwarded
# or dropped — that is the point, and it is why the answer alone cannot be used
# to tell the two apart in a check. What tells them apart is the counter: the
# server counts an address it refused, and counts one it forwarded. So the cases
# are read against those two counters, and the neutral answer is checked on top.
# An earlier version of this block used the address of a member who is not signed
# up for the duty and asserted only the answer; it passed either way, which is
# how the mail suite came to be the first thing to notice that the rule had
# changed underneath it.
#
# The published ride of the fixture belongs to a member who is not signed up
# for that duty, and a request is dropped if the creator is not a participant
# either. So this section signs that member up for the duty itself and takes the
# registration off again afterwards. With three fixture members that yields the
# four cases that matter: two members of the duty (forwarded), the creator
# asking about the own entry (dropped silently), a member who did not sign up
# (refused), and an address of no member at all (refused).
ERSTELLER_NR=0043
ERSTELLER_MAIL=berta@angeln.example.org
s drop-registration "$(jq event_id)" "$ERSTELLER_NR" > /dev/null 2>&1
s register "$(jq event_id)" "$ERSTELLER_NR" "$ERSTELLER_MAIL" > /dev/null
NICHT_ANGEMELDET=$(jq member_free_mail)
FRAGE=$(jq member_taken_mail)
# One counter per name. Reading two counters into one pair of variables and then
# comparing them with each other is a comparison of two different things, and it
# answers the wrong question in both directions at once.
ungueltig() { s stat contact_invalid_email; }
gueltig() { s stat contact_valid_email; }
vorher_u=$(ungueltig); vorher_g=$(gueltig)
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$PUBLISHED_REF" \
	--data-urlencode "fg_contact_email=$FRAGE" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=$contact_nonce")
if printf '%s' "$loc" | grep -q "fg_notice=contact_received"; then ok "a member of the duty is answered neutrally"; else bad "a member of the duty is answered neutrally" "$loc"; fi
if [ "$(gueltig)" -gt "$vorher_g" ]; then ok "and that one was forwarded ($vorher_g -> $(gueltig))"; else bad "a contact between two members of the duty is forwarded" "$vorher_g -> $(gueltig)"; fi
if [ "$(ungueltig)" = "$vorher_u" ]; then ok "and was not refused ($vorher_u)"; else bad "a contact between two members of the duty is not refused" "$vorher_u -> $(ungueltig)"; fi
# The own entry: answered exactly the same way, and counted nowhere. There is no
# counter for it, and there must not be one — a case that is only noticed by a
# difference in the answer would tell a requester whether the entry is their own.
vorher_u=$(ungueltig); vorher_g=$(gueltig)
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$PUBLISHED_REF" \
	--data-urlencode "fg_contact_email=$ERSTELLER_MAIL" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=$contact_nonce")
if printf '%s' "$loc" | grep -q "fg_notice=contact_received"; then ok "the creator asking about the own entry is answered identically"; else bad "the creator asking about the own entry is answered identically" "$loc"; fi
if [ "$(ungueltig)" = "$vorher_u" ] && [ "$(gueltig)" = "$vorher_g" ]; then ok "and is counted neither way ($vorher_u/$vorher_g)"; else bad "the own entry is counted nowhere" "$vorher_u/$vorher_g -> $(ungueltig)/$(gueltig)"; fi
vorher_u=$(ungueltig); vorher_g=$(gueltig)
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$PUBLISHED_REF" \
	--data-urlencode "fg_contact_email=$NICHT_ANGEMELDET" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=$contact_nonce")
if printf '%s' "$loc" | grep -q "fg_notice=contact_received"; then ok "a member who did not sign up is answered identically"; else bad "a member who did not sign up is answered identically" "$loc"; fi
if [ "$(ungueltig)" -gt "$vorher_u" ]; then ok "and the server counted that one as refused ($vorher_u -> $(ungueltig))"; else bad "a member who did not sign up is refused" "$vorher_u -> $(ungueltig)"; fi
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$PUBLISHED_REF" \
	--data-urlencode "fg_contact_email=fremd@example.com" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=$contact_nonce")
if printf '%s' "$loc" | grep -q "fg_notice=contact_received"; then ok "a foreign address answered identically too"; else bad "a foreign address answered identically too" "$loc"; fi
s drop-registration "$(jq event_id)" "$ERSTELLER_NR" > /dev/null 2>&1

# --- 5a. after sending, the message is brought into view
# The form stands at the bottom of the page. Without a fragment in the redirect
# the visitor stays where they pressed the button and the message sits above
# the window, so the answer to "did it work?" is not to be seen. The fragment
# and the id on the page have to be the same name; if only one of the two
# changes, the jump goes nowhere and this check is the one that notices.
echo "[5a] the return after sending"
anchor=$(printf '%s' "$loc" | sed -n 's/.*#\([A-Za-z0-9_-]*\)$/\1/p')
if [ -n "$anchor" ]; then ok "the redirect names a place to jump to (#$anchor)"; else bad "the redirect names a place to jump to" "$loc"; fi
notice_page=$(curl -sk "$BASE/?page_id=$PAGE_ID&fg_notice=contact_received")
if [ -n "$anchor" ]; then has "that place exists on the page" "$notice_page" "id=\"$anchor\""; fi
# the same for the error case, which is the one a visitor reads twice
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$PUBLISHED_REF" \
	--data-urlencode "fg_contact_email=cem@angeln.example.org" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=abgelaufen")
if printf '%s' "$loc" | grep -q "fg_notice=form_expired#"; then ok "an expired form jumps to the message as well"; else bad "an expired form jumps to the message as well" "$loc"; fi
if [ -n "$anchor" ]; then has "the message for the error case carries the same place" "$(curl -sk "$BASE/?page_id=$PAGE_ID&fg_notice=form_expired")" "id=\"$anchor\""; fi
# a jump that ends under a header which stays in place is no jump
struct "$notice_page" "the place to jump to leaves room above itself" "
import re, sys
h = sys.stdin.read()
m = re.search(r'<div id=\"([a-z0-9-]+)\" class=\"([^\"]*)\"', h)
sys.exit(0 if m and 'fg-jump' in m.group(2) else 1)
" "the message is not marked as a place to jump to"

# --- 5b. contact form on the page
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
has "contact form is present" "$body" 'class="fg-contact-form"'
hasnt "contact form has no free text" "$body" "textarea"

# --- 5c. compact list: one line per entry, one note for the whole list
echo "[5c] compact listing"
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
# The contact form sits in a details element that is not open, so it only
# appears once the visitor asks for it. A form that is written into the page
# every time is the version before the change.
hasnt "the contact form is not open on arrival" "$body" "<details class=\"fg-contact\" open"
struct "$body" "every entry opens its contact form behind a toggle" "
import re, sys
h = sys.stdin.read()
blocks = re.findall(r'<details class=\"fg-contact\">.*?</details>', h, re.S)
ok = blocks and all('<summary' in b and 'fg-contact-form' in b for b in blocks)
sys.exit(0 if ok else 1)
" "an entry has no toggle or no form behind it"
struct "$body" "every toggle carries one label and a line of text behind it" "
import re, sys
h = sys.stdin.read()
toggles = re.findall(r'<details class=\"fg-contact\">\s*<summary class=\"fg-button fg-contact-toggle\">(.*?)</summary>\s*<span class=\"fg-contact-latch\">(.*?)</span>', h, re.S)
ok = toggles and all(t.strip() == 'Kontaktieren' and l.strip() == 'Kontaktieren' for t, l in toggles)
sys.exit(0 if ok else 1)
" "a toggle has no latch, or the two do not carry the same word"
# The word that closes the form is gone, and the button that offered it is gone
# with it. What stays is a summary with one word, and a span that is not a
# control: nothing on the page folds the form again, neither with the mouse nor
# with the keyboard.
hasnt "no toggle offers to close the form again" "$body" "Schließen"
has "the control that opened the form is the only one" "$body" '<summary class="fg-button fg-contact-toggle">Kontaktieren</summary>'
has "and the line that stands in for it is plain text" "$body" '<span class="fg-contact-latch">Kontaktieren</span>'
has "the form is sent with a button of its own" "$body" '<button class="fg-button" type="submit">Absenden</button>'
hasnt "the old wording is gone" "$body" "Kontakt aufnehmen"
# The control and the line of text are both in the document at all times, and
# this is the only thing that keeps the one away that does not belong to the
# state. If the stylesheet is older than the markup, both are drawn and the word
# stands there twice; that is not visible in the HTML and only shows up in the
# browser, so the file is read at the address the page itself links to.
css_url=$(printf '%s' "$body" | grep -o "href='[^']*arbeitsdienste\.css?ver=[^']*'" | head -1 | sed "s/^href='//;s/'$//")
if [ -n "$css_url" ]; then
	has "the stylesheet is requested with a version" "$css_url" "?ver="
	style=$(curl -sk "$css_url")
	struct "$style" "both forms swap the control for the line of text" "
import re, sys
css = sys.stdin.read()
# comments are stripped first: a comment sits in front of a selector and would
# be counted as part of it
css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
rules = re.findall(r'([^{}]+)\{([^}]*)\}', css)
def eigenschaft(body, name):
    # declarations are read one by one and the last one counts, as in a
    # browser. A pattern like r'display:\s*(?!none)' does not do: \s* can take
    # no characters at all, then the lookahead looks at the space before the
    # word and succeeds, so every rule holding a display would pass.
    gefunden = None
    for teil in body.split(';'):
        if ':' not in teil:
            continue
        k, _, w = teil.partition(':')
        if k.strip() == name:
            gefunden = w.strip()
    return gefunden
def staerke(sel):
    # only these shapes occur here: element names, classes, [open]
    s = re.sub(r'::?[a-z-]+(\([^)]*\))?', ' ', sel)
    s = re.sub(r'#[a-z-]+', ' ', s)
    return len(re.findall(r'\.', s)) + len(re.findall(r'\[', s))
def regeln(pfad, offen):
    return [(s, b) for s, b in rules
            if pfad in s and ('[open]' in s) == offen]
# Both forms are the same decision, so both are checked: a rule that only ever
# named one of them would leave the other with a second button and nothing would
# say so.
for name in ('fg-contact', 'fg-signup'):
    schalter, zeile = 'summary.%s-toggle' % name, '.%s-latch' % name
    # The open state is the one that matters. If the summary is not put away
    # there, the form can be folded again by a click, and the second button the
    # decision was about is back on the page.
    weg = regeln(schalter, True)
    if not weg or not all(eigenschaft(b, 'display') == 'none' for _, b in weg):
        sys.exit(1)
    # and the line of text has to come out, or the word stands there twice
    her = regeln(zeile, True)
    if not her or not all(eigenschaft(b, 'display') not in (None, 'none')
                          for _, b in her):
        sys.exit(1)
    # the shut state must put the line away, or it stands next to the control
    zu = regeln(zeile, False)
    if not zu or not all(eigenschaft(b, 'display') == 'none' for _, b in zu):
        sys.exit(1)
    # Both rules for the line of text name the same path, and the open one
    # carries the attribute selector. That is what makes it win: a theme that
    # moves its own rules around cannot outrank it, and the order inside the
    # file does not matter.
    if max(staerke(s) for s, _ in her) <= max(staerke(s) for s, _ in zu):
        sys.exit(1)
sys.exit(0)
" "one of the two forms keeps a clickable control while it is open, or leaves its line of text standing beside it"
	struct "$style" "the label of the contact field is kept on one line" "
import re, sys
css = re.sub(r'/\*.*?\*/', '', sys.stdin.read(), flags=re.S)
rules = re.findall(r'([^{}]+)\{([^}]*)\}', css)
ok = False
for sel, body in rules:
    s = sel.strip()
    if 'fg-contact-row' in s and s.endswith('label') and 'white-space: nowrap' in body:
        ok = True
sys.exit(0 if ok else 1)
" "nothing keeps the label of the contact field from wrapping"
	struct "$style" "a jump leaves room above itself" "
import re, sys
css = re.sub(r'/\*.*?\*/', '', sys.stdin.read(), flags=re.S)
rules = re.findall(r'([^{}]+)\{([^}]*)\}', css)
ok = any(s.strip() == '.fg-jump' and 'scroll-margin-top' in b for s, b in rules)
sys.exit(0 if ok else 1)
" "a jump would end under a header that stays in place"
	# The button must not carry a font size of its own. A size written on the
	# button detaches its label from the text around it, and a theme is free to
	# have an opinion about the body size, so only an inherited size is right.
	# The contact toggle is drawn by .fg-button and, for its shape, by a rule of
	# its own, so every rule that names a button is looked at, not just one.
	struct "$style" "the button takes its font size from the text" "
import re, sys
css = re.sub(r'/\*.*?\*/', '', sys.stdin.read(), flags=re.S)
rules = re.findall(r'([^{}]+)\{([^}]*)\}', css)
treffen = [(s, b) for s, b in rules
           if re.search(r'\.fg-button\b|\.fg-contact-toggle\b', s)]
if not treffen:
    sys.exit(1)                                   # nothing draws a button
for _, b in treffen:
    if re.search(r'(^|;)\s*font-size\s*:', b, re.M):
        sys.exit(1)                               # a button with a size
if not any(re.search(r'(^|;)\s*font\s*:\s*inherit', b, re.M) for _, b in treffen):
    sys.exit(1)                                   # no button inherits
sys.exit(0)
" "a button sets a font size of its own, or none of them inherits the size of the text"
	# The colour of the button is a decision, and white on it has to stay
	# readable. The check reads the value out of the stylesheet instead of
	# holding on to the name, so it still means something after the colour is
	# changed once more: a light button on white text has to fail here.
	struct "$style" "white on the button stays readable" "
import re, sys
css = re.sub(r'/\*.*?\*/', '', sys.stdin.read(), flags=re.S)
farbe = re.search(r'--fg-button\s*:\s*(#[0-9a-fA-F]{3,8})', css)
if not farbe:
    sys.exit(1)                                   # the colour is not set
def lum(h):
    h = h.lstrip('#')
    if len(h) == 3:
        h = ''.join(c * 2 for c in h)
    r, g, b = (int(h[i:i+2], 16) / 255 for i in (0, 2, 4))
    f = lambda c: c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b)
weiss = lum('#ffffff')
sys.exit(0 if (weiss + 0.05) / (lum(farbe.group(1)) + 0.05) >= 4.5 else 1)
" "the colour of the button is missing, or white on it is under 4.5:1"
	# The confirm page brings its own stylesheet with it, written out in one line
	# with the colour written into it instead of a variable. Two copies of one
	# colour drift apart the first time only one of them is changed, so the two
	# are compared here. The page was fetched in section 3, which does not change
	# anything; confirming it is a POST in section 4.
	if [ -s "$DIR/confirm.html" ]; then
		confirm=$(cat "$DIR/confirm.html")
		struct "$style
%%FG_TRENNER%%
$confirm" "the confirm page uses the same button colour" "
import re, sys
style, confirm = sys.stdin.read().split('%%FG_TRENNER%%')
oeffentlich = re.search(r'--fg-button\s*:\s*(#[0-9a-fA-F]{3,8})', style)
if not oeffentlich:
    sys.exit(1)
# the inline rule is minified, so the background is the only colour that is not
# a border or a text colour; it is matched as \"background:\" with no space
bestaetigung = re.search(r'button\{[^}]*background\s*:\s*(#[0-9a-fA-F]{3,8})', confirm)
if not bestaetigung:
    sys.exit(1)
sys.exit(0 if oeffentlich.group(1).lower() == bestaetigung.group(1).lower() else 1)
" "the confirm page and the public page show the button in two different colours"
		struct "$confirm" "the confirm page button takes its font size from the text" "
import re, sys
html = sys.stdin.read()
regel = re.search(r'(^|[;{}])button\{([^}]*)\}', html)
if not regel:
    sys.exit(1)
koerper = regel.group(2)
if re.search(r'(^|;)\s*font-size\s*:', koerper, re.M):
    sys.exit(1)
sys.exit(0 if re.search(r'(^|;)\s*font\s*:\s*inherit', koerper, re.M) else 1)
" "the confirm page button has a font size of its own, or does not take the one of the text"
	else
		bad "the confirm page was fetched for the button check" "no $DIR/confirm.html"
	fi
else
	bad "the stylesheet is linked" "no stylesheet with a version on the page"
fi
# The work duty and its date are one heading. A date in a paragraph of its own
# is the version before the change and would put them on two lines again.
hasnt "the date is no line of its own" "$body" '<p class="fg-date">'
struct "$body" "the date stands inside the work duty heading" "
import re, sys
h = sys.stdin.read()
dates = re.findall(r'class=\"fg-date\"', h)
headings = [b for b in re.findall(r'<h3[^>]*>.*?</h3>', h, re.S) if 'fg-date' in b]
sys.exit(0 if dates and len(dates) == len(headings) else 1)
" "a date is outside the heading or the heading is missing"
struct "$body" "every entry carries mode, origin and name in one heading" "
import re, sys
h = sys.stdin.read()
titles = re.findall(r'<h4 class=\"fg-ride-title\">.*?</h4>', h, re.S)
def complete(t):
    if 'fg-badge' not in t or 'fg-origin' not in t:
        return False
    parts = [p.strip() for p in re.sub(r'<[^>]+>', '\n', t).split('\u00b7')]
    return len(parts) == 3 and all(parts)
sys.exit(0 if titles and all(complete(t) for t in titles) else 1)
" "a heading is missing mode, origin or name"
struct "$body" "field and button share one row" "
import re, sys
h = sys.stdin.read()
idx = [m.start() for m in re.finditer(r'<div class=\"fg-contact-row\">', h)]
rows = [h[i:h.find('</form>', i)] for i in idx]
ok = rows and all('type=\"email\"' in r and '<button' in r and 'type=\"submit\"' in r for r in rows)
sys.exit(0 if ok else 1)
" "a row is missing the field or the button"
# The note is the same for every entry and is therefore stated once. The count
# only says something with more than one entry, so both belong in one check: a
# separate one would pass on a page that happens to have a single entry.
struct "$body" "the note about the address is stated once for the whole list" "
import re, sys
h = sys.stdin.read()
entries = len(re.findall(r'<article class=\"fg-ride\">', h))
notes = h.count('wird nur an den Ersteller der Fahrgemeinschaft gesendet')
sys.exit(0 if entries > 1 and notes == 1 else 1)
" "not more than one entry, or the note is not stated exactly once"

# --- 5d. the list comes first, the form below, a link leads down to it
echo "[5d] list before form"
struct "$body" "the link, the list and the form stand in that order" "
import re, sys
h = sys.stdin.read()
def at(pattern):
    m = re.search(pattern, h)
    return m.start() if m else -1
link = at(r'class=\"fg-toplink\"')
if link == -1:
    sys.exit(1)                                    # the link is always there
listed = at(r'id=\"fg-list-heading\"')
# the offer form is the one that submits a new entry, not a contact request
offer = at(r'value=\"fg_submit_ride\"')
if re.findall(r'<article class=\"fg-ride\">', h):
    # a list with entries: link, then list, then form, all three present
    sys.exit(0 if -1 not in (listed, offer) and link < listed < offer else 1)
# An empty list means no work duty is coming up. The list belongs to the work
# duties and the form asks for one, so both are left out together. The messages
# that stand in their place are covered in smoke.php [3b].
sys.exit(0 if listed == -1 and offer == -1 else 1)
" "the link, the list and the form are not in that order, or one of them is missing where it belongs"
struct "$body" "the link at the top leads to the form" "
import re, sys
h = sys.stdin.read()
m = re.search(r'class=\"fg-toplink\">\s*<a href=\"#([a-z0-9-]+)\">\s*([^<]+?)\s*</a>', h)
if not m:
    sys.exit(1)
target, label = m.group(1), m.group(2)
if ('id=\"%s\"' % target) not in h:
    sys.exit(1)                                    # the link would go nowhere
sys.exit(0 if label else 1)
" "the link has no text, or it points at something that is not on the page"

# --- 5e. the name is meant to be a name, contact details are still refused
# The server counts what it treats as a contact detail. That counter is the
# only place where the distinction shows: a rejected entry and an entry that
# fails later look the same to the caller, both answer not_created.
echo "[5e] a first name is a name"
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
has "the form asks for a first name" "$body" ">Vorname oder Spitzname</label>"
hasnt "the old field name is gone" "$body" "Öffentliche Bezeichnung"
has "the name is announced as public" "$body" "Steht in der Liste öffentlich"
has "the consent names the name as public" "$body" "Dazu gehören mein Vorname oder Spitzname"
hasnt "the ambiguous wording in the consent is gone" "$body" "persönlichen Kontaktdaten"
hasnt "the hint no longer asks for an unidentifying text" "$body" "nicht identifizierende"
has "the hint names what the server refuses" "$body" "Telefonnummern und E-Mail-Adressen werden von der Serverseite zurückgewiesen"
has "the area field carries its examples" "$body" "Abfahrtsort, Stadtteil, z. B. Langwasser, Nürnberg Nord, S-Bahnstation Ostring."
hasnt "no address field of the form invites more than the column holds" "$body" 'maxlength="254"'
has "the address of the form stops at the width of the column" "$body" 'name="fg_contact_email" maxlength="190"'

event_ref=$(printf '%s' "$body" | grep -o '<option value="[0-9a-f]\{32\}"' | head -1 | sed 's/.*value="//;s/"//')
offer_nonce=$(printf '%s' "$body" | grep -o 'name="fg_submit_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
# An address that is on no event, so the probe is refused for that reason and
# leaves nothing behind. The fixture only registers the three @angeln.example.org
# addresses as participants; adding this one there would turn the probe into a
# real entry and the check would quietly start writing rides.
probe() {
	curl -sk -o /dev/null -X POST "$BASE/wp-admin/admin-post.php" \
		--data-urlencode "action=fg_submit_ride" \
		--data-urlencode "fg_mode=offer" \
		--data-urlencode "fg_event_ref=$event_ref" \
		--data-urlencode "fg_alias=$1" \
		--data-urlencode "fg_origin=$2" \
		--data-urlencode "fg_contact_email=fremd@example.com" \
		--data-urlencode "fg_consent=1" \
		--data-urlencode "fg_website=" \
		--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
		--data-urlencode "fg_submit_nonce=$offer_nonce"
}
# Reads the counter before and after a list of probes and prints "start end".
# Every argument is one "alias::origin" pair, so that a value can be put into
# either field. One call can therefore assert on several values at once.
count_around() {
	mark=$(s stat publish_personal_data)
	for pair in "$@"; do
		probe "${pair%%::*}" "${pair#*::}"
	done
	end=$(s stat publish_personal_data)
	printf '%s %s' "$mark" "$end"
}
if [ -n "$event_ref" ] && [ -n "$offer_nonce" ]; then
	# A first name and a nickname are not contact details. The area stays a
	# fixed harmless value so that only the alias can move the counter.
	read -r before after <<< "$(count_around 'Peter::Suedstadt' 'Käse::Suedstadt' 'Amsel-Gruppe::Suedstadt')"
	if [ "$before" = "$after" ]; then ok "a first name and a nickname are not contact details ($after)"; else bad "a first name and a nickname are not contact details" "$before -> $after"; fi
	probe "0176 12345678" "Suedstadt"
	after_phone=$(s stat publish_personal_data)
	if [ "$after_phone" -gt "$after" ]; then ok "a phone number is still refused ($after -> $after_phone)"; else bad "a phone number is still refused" "$after -> $after_phone"; fi
	# The three examples under the area field must survive the same filter. A
	# hint that offers values the server refuses is worse than no hint: the
	# entry is dropped with the same neutral message as a wrong address.
	read -r before after <<< "$(count_around 'Peter::Langwasser' 'Peter::Nürnberg Nord' 'Peter::S-Bahnstation Ostring')"
	if [ "$before" = "$after" ]; then ok "the three examples of the area hint are accepted ($after)"; else bad "the three examples of the area hint are accepted" "$before -> $after"; fi
else
	bad "the form offers a work duty to submit against" "no event reference on the page"
fi

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
	--data-urlencode "fg_contact_email=$(jq member_taken_mail)" \
	--data-urlencode "fg_consent=1" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_submit_nonce=$submit_nonce")
if printf '%s' "$loc" | grep -q "fg_notice=pending"; then ok "submission is vorkereed ($loc)"; else bad "submission is vorkereed" "$loc"; fi

# A ride is offered for one duty, and it may only be offered by somebody who is
# in that duty. The address alone decides, and the member does not even have to
# know it: whoever signs up for a duty can then find a ride, and whoever does
# not cannot put their address into the list of a duty they are not part of.
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_submit_ride" \
	--data-urlencode "fg_event_ref=$event_ref" \
	--data-urlencode "fg_mode=search" \
	--data-urlencode "fg_alias=Fremdfahrt" \
	--data-urlencode "fg_origin=Weststadt" \
	--data-urlencode "fg_contact_email=$(jq member_free_mail)" \
	--data-urlencode "fg_consent=1" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_submit_nonce=$submit_nonce")
if printf '%s' "$loc" | grep -q "fg_notice=not_created"; then
	ok "a member who is not in the duty cannot offer a ride"
else
	bad "a member who is not in the duty cannot offer a ride" "$loc"
fi
hasnt "and the ride is nowhere on the page" "$(curl -sk "$BASE/?page_id=$PAGE_ID")" "Fremdfahrt"
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

# --- 8. the list of work duties on a page of its own
# The list is a shortcode of its own and gets a page of its own, so it is
# fetched over the same address a visitor would use. Everything asserted here
# is visible on the page, not in a table: the point of the check is that the
# work a visitor gets is the work the plugin says it did.
echo "[8] the list of work duties"
LIST_PATH=$(jq list_page_path)

# The free places of every card on the page, as a title/number pair list, and
# the two closed cards by title. Both are handed to the checks through the
# environment, because a title is a sentence a member can write and a title
# that lands in a python snippet inside the shell is a quoting problem waiting
# for the first apostrophe in a name.
#
# The number is worked out here in the shell out of the two numbers in the
# tables, and not read out of the fixture: the fixture asks the function of the
# plugin what the answer is, and a check that compares the page with the
# function would confirm any number the function ever prints, right and wrong
# alike. Here the difference of two stored numbers is the expectation, so only
# the page can be wrong.
frei_von() {
	bedarf=$(s event "$1" demand)
	angemeldet=$(s count-registrations "$1")
	if [ "$bedarf" -gt "$angemeldet" ]; then
		echo $(( bedarf - angemeldet ))
	else
		echo 0
	fi
}
FREI_HAUPT=$(frei_von "$(jq event_id)")
FREI_VOLL=$(frei_von "$(jq full_event_id)")
FREI_OHNE=$(frei_von "$(jq no_demand_event_id)")
export FG_FREIE_PLATZE=$(python3 -c "
import json
d = json.load(open('$F'))
paare = [
    (d['event_title'], int('$FREI_HAUPT')),
    (d['full_event_title'], int('$FREI_VOLL')),
    (d['no_demand_title'], int('$FREI_OHNE')),
]
print(json.dumps(paare, ensure_ascii=False))
")
export FG_TITEL_VOLL=$(jq full_event_title)
export FG_TITEL_OHNE=$(jq no_demand_title)
list=$(curl -sk "$BASE$LIST_PATH")
LIST_CODE=$(curl -sk -o /dev/null -w '%{http_code}' "$BASE$LIST_PATH")
if [ "$LIST_CODE" = "200" ]; then ok "the list page answers ($LIST_CODE)"; else bad "the list page answers" "$LIST_CODE"; fi
has "the list names itself" "$list" "Kommende Arbeitsdienste"
has "the list stands in a section with an anchor" "$list" 'aria-labelledby="fg-dienste"'
# One card per work duty that is ahead and visible, and no other. The number
# comes from the tables, so a duty that is silently left out of the page, or
# one that is on the page without being due, makes the two disagree.
erwartet=$(s count-events)
karten=$(printf '%s' "$list" | grep -c '<article class="fg-event-card"')
if [ "$erwartet" -gt 0 ] && [ "$karten" = "$erwartet" ]; then
	ok "every due duty has exactly one card ($karten of $erwartet)"
else
	bad "every due duty has exactly one card" "$karten cards for $erwartet duties"
fi
struct "$list" "every card holds a table of details" "
import re, sys
h = sys.stdin.read()
karten = re.findall(r'<article class=\"fg-event-card\".*?</article>', h, re.S)
if not karten:
    sys.exit(1)
for karte in karten:
    if len(re.findall(r'<table class=\"fg-event-data\">', karte)) != 1:
        sys.exit(1)
    if '<tbody>' not in karte:
        sys.exit(1)
sys.exit(0)
" "a card has no table, more than one table, or no body for the rows"
# The date carries the weekday, the word "den" and the date format of the site.
# What the site would print on any other day is not the date of this duty, so
# the real date of the first card is looked up and compared.
erste=$(s first-event)
if [ "$erste" -gt 0 ]; then
	erwartetes_datum=$(s format-date "$erste")
	struct "$list" "the first card carries its own date with the weekday" "
import re, sys
h = sys.stdin.read()
karte = re.search(r'<article class=\"fg-event-card\".*?</article>', h, re.S)
if not karte:
    sys.exit(1)
zelle = re.search(r'<th scope=\"row\">Datum</th>\s*<td>(.*?)</td>', karte.group(0), re.S)
if not zelle:
    sys.exit(1)
text = re.sub(r'<[^>]+>', '', zelle.group(1)).strip()
sys.exit(0 if text == '''$erwartetes_datum''' and ', den ' in text else 1)
" "the date row of the first card is not \"Wochentag, den <Datum>\" of that duty"
fi
# Every card that has a place to give away carries exactly one signup form, and
# every card that is closed carries none. A form on a full duty would be a
# button that is guaranteed to fail, and no form on a duty that has a place
# would make the club answer the phone for what the page could have done.
#
# Which card is open is read from the tables, not from the page: a check that
# takes the state off the page itself cannot notice a page that shows a form
# everywhere, because on such a page nothing looks closed. That is not a guess.
# With can_register() forced to true this check stayed green while both closed
# cards carried a button, because both of them then looked open.
struct "$list" "one signup form per duty that can be signed up for" "
import json, os, re, sys
h = sys.stdin.read()
erwartet = dict(json.loads(os.environ['FG_FREIE_PLATZE']))
karten = re.findall(r'<article class=\"fg-event-card\".*?</article>', h, re.S)
if not karten:
    sys.exit(1)
if len(karten) != len(erwartet):
    print('%d cards on the page, %d duties in the tables' % (len(karten), len(erwartet)), file=sys.stderr)
    sys.exit(1)
for karte in karten:
    titel = re.search(r'<h3 class=\"fg-event-title\">(.*?)</h3>', karte, re.S).group(1).strip()
    if titel not in erwartet:
        print('a card names a duty that is not in the fixture: %s' % titel, file=sys.stderr)
        sys.exit(1)
    formen = re.findall(r'<form class=\"fg-signup-form\"', karte)
    zu = '<p class=\"fg-signup-closed\">' in karte
    if erwartet[titel] > 0:
        if len(formen) != 1 or zu:
            print('%s has a place free and carries %d forms, closed note: %s' % (titel, len(formen), zu), file=sys.stderr)
            sys.exit(1)
    elif zu and not formen:
        continue
    else:
        print('%s has no place free and carries %d forms, closed note: %s' % (titel, len(formen), zu), file=sys.stderr)
        sys.exit(1)
sys.exit(0)
" "a card has a form although it is closed, or none although it is open, or more than one"

# The row of free places is the number the club asks about on the phone, and it
# is read from the tables rather than from the page, so a card that printed a
# number of its own making cannot pass.
struct "$list" "the free places row says what the tables say" "
import json, os, re, sys
h = sys.stdin.read()
for titel, erwartet in json.loads(os.environ['FG_FREIE_PLATZE']):
    karte = None
    for k in re.findall(r'<article class=\"fg-event-card\".*?</article>', h, re.S):
        if re.search(r'<h3 class=\"fg-event-title\">' + re.escape(titel) + r'</h3>', k):
            karte = k
            break
    if karte is None:
        print('no card for ' + titel, file=sys.stderr)
        sys.exit(1)
    zelle = re.search(r'<th scope=\"row\">Verfügbare freie Plätze</th>\s*<td[^>]*>(.*?)</td>', karte, re.S)
    if not zelle:
        print('no free places row on ' + titel, file=sys.stderr)
        sys.exit(1)
    text = re.sub(r'<[^>]+>', '', zelle.group(1)).strip()
    if text != str(erwartet):
        print('%s: page says %s, tables say %d' % (titel, text, erwartet), file=sys.stderr)
        sys.exit(1)
sys.exit(0)
" "the free places of a card do not match the tables"

# A card marks a zero and leaves a number unmarked, so that the two states are
# told apart on sight. The class is what the stylesheet hangs the difference on,
# and a card that printed both numbers the same way would look like a duty with
# a place free.
struct "$list" "only the card without a free place is marked" "
import json, os, re, sys
h = sys.stdin.read()
paare = dict(json.loads(os.environ['FG_FREIE_PLATZE']))
for k in re.findall(r'<article class=\"fg-event-card\".*?</article>', h, re.S):
    titel = re.search(r'<h3 class=\"fg-event-title\">(.*?)</h3>', k, re.S).group(1).strip()
    zelle = re.search(r'<th scope=\"row\">Verfügbare freie Plätze</th>\s*<td([^>]*)>', k, re.S)
    if not zelle:
        print('no free places row on ' + titel, file=sys.stderr)
        sys.exit(1)
    attribute = zelle.group(1)
    if paare[titel] > 0:
        if 'fg-places-none' in attribute:
            print(titel + ' has a place free and is marked as full', file=sys.stderr)
            sys.exit(1)
    elif 'fg-places-none' not in attribute:
        print(titel + ' has no place free and is not marked', file=sys.stderr)
        sys.exit(1)
sys.exit(0)
" "the free places are marked on the wrong cards"

# The two refusals on the closed cards are two different sentences. A duty that
# asked for people and got them is full; a duty that never stated a demand was
# never open, and calling that full would be a claim about the duty that is not
# true. Which of the two a card shows is decided by the same two numbers.
struct "$list" "a full duty and a duty without a demand say different things" "
import json, os, re, sys
h = sys.stdin.read()
def geschlossen(titel):
    for k in re.findall(r'<article class=\"fg-event-card\".*?</article>', h, re.S):
        if re.search(r'<h3 class=\"fg-event-title\">' + re.escape(titel) + r'</h3>', k):
            m = re.search(r'<p class=\"fg-signup-closed\">(.*?)</p>', k, re.S)
            return re.sub(r'<[^>]+>', '', m.group(1)).strip() if m else ''
    return ''
voll = geschlossen(os.environ['FG_TITEL_VOLL'])
ohne = geschlossen(os.environ['FG_TITEL_OHNE'])
if not voll or not ohne or voll == ohne:
    print('full: %r / ohne Bedarf: %r' % (voll, ohne), file=sys.stderr)
    sys.exit(1)
if 'kein Bedarf' not in ohne or 'kein Bedarf' in voll:
    print('the duty without a demand is not named as such: %r' % ohne, file=sys.stderr)
    sys.exit(1)
sys.exit(0)
" "the two closed cards do not carry two different sentences"

# The name of a member who signed up is not on the page. Only the number of
# places is, because the club does not publish who is doing what.
hasnt "the list shows no member name" "$list" "Angeln"
hasnt "the list shows no member of the fixture" "$list" "Cemu"
has "the signup form asks for the member number" "$list" 'name="fg_member_no"'
has "and for the address" "$list" 'name="fg_member_email"'
# Both address fields stop where the column stops. 254 characters are a legal
# address length, so a field that offers them asks a visitor for something the
# schema will refuse: the request comes back with a general error and no word
# about the field that was too long.
hasnt "no address field of the list invites more than the column holds" "$list" 'maxlength="254"'
has "the address of the signup stops at the width of the column" "$list" 'name="fg_member_email" maxlength="190"'
has "the button carries the promised wording" "$list" "verbindlich anmelden"
has "the control that opens the form says Eintragen" "$list" '<summary class="fg-button fg-signup-toggle">Eintragen</summary>'
has "and the line that stands in for it is plain text" "$list" '<span class="fg-signup-latch">Eintragen</span>'
hasnt "and no toggle offers to close the form again" "$list" "Schließen"
# The theme and WordPress bring scripts of their own onto every page, so only
# what the plugin puts into the page is looked at: nothing inside its own block,
# and no file of the plugin loaded from anywhere.
struct "$list" "the plugin puts no script into the block it writes" "
import re, sys
h = sys.stdin.read()
block = re.search(r'<section class=\"fg-section\" aria-labelledby=\"fg-dienste\">.*?</section>', h, re.S)
if not block:
    sys.exit(1)
sys.exit(0 if '<script' not in block.group(0) else 1)
" "the plugin's own block contains a script"
# A script outside that block would be invisible to the check above, so the
# whole page is compared with the other page of the plugin, which carries the
# other shortcode. Theme and core bring the same scripts onto both and cancel
# out; anything the list adds on its own stands in the difference. The number
# of script elements is compared as well, because a script without a source of
# its own is invisible in a list of sources: an inline script would slip
# through a comparison of addresses and be caught only by the count.
referenz=$(curl -sk "$BASE/?page_id=$PAGE_ID")
struct "$referenz
%%FG_TRENNER%%
$list" "the list page loads no script the other plugin page does not" "
import re, sys
referenz, liste = sys.stdin.read().split('%%FG_TRENNER%%')
def skripte(h):
    return set(re.findall(r'<script[^>]*src=[\"\x27]([^\"\x27]+)', h))
extra = sorted(skripte(liste) - skripte(referenz))
if extra:
    print('zusaetzlich geladen: %s' % extra, file=sys.stderr)
    sys.exit(1)
nur_liste = len(re.findall(r'<script', liste)) - len(re.findall(r'<script', referenz))
if nur_liste > 0:
    print('%d weiteres Skriptelement ohne Quelle' % nur_liste, file=sys.stderr)
    sys.exit(1)
sys.exit(0)
" "the list page carries a script of its own"
# Nothing from the tables reaches the page. The one identifier a duty is named
# by in the form is its public reference, a 32 character name that says which
# duty is meant and holds nothing else. The record number out of the table is
# not on the page: with it, anyone could walk the duties that are not visible.
hasnt "the list hides the record number of a duty" "$list" "fg_event=$erste"
hasnt "the list hides internal ids" "$list" "fg_event="
has "the form names the duty by its public reference" "$list" 'name="fg_event_ref" value="'
gelesene_ref=$(printf '%s' "$list" | grep -o 'name="fg_event_ref" value="[0-9a-f]*"' | head -1 | sed 's/.*value="//;s/"//')
if [ "$gelesene_ref" = "$(s event "$erste" public_ref)" ]; then
	ok "and it is the public reference of the first duty"
else
	bad "and it is the public reference of the first duty" "page says $gelesene_ref, table says $(s event "$erste" public_ref)"
fi
hasnt "the list hides the work duty uuid" "$list" "$(s event "$erste" event_uuid)"
hasnt "the list hides contact addresses" "$list" "@angeln.example.org"
# The stylesheet has to come with the page, otherwise the cards arrive as an
# unstyled pile of tables.
list_css=$(printf '%s' "$list" | grep -o "href='[^']*arbeitsdienste\.css?ver=[^']*'" | head -1 | sed "s/^href='//;s/'$//")
if [ -n "$list_css" ]; then
	ok "the list page brings the stylesheet with it"
	has "the stylesheet is requested with a version" "$list_css" "?ver="
	list_style=$(curl -sk "$list_css")
	struct "$list_style" "the stylesheet knows the card and its table" "
import re, sys
css = re.sub(r'/\*.*?\*/', '', sys.stdin.read(), flags=re.S)
regeln = re.findall(r'([^{}]+)\{([^}]*)\}', css)
def hat(klassenname, eigenschaft):
    return any(klassenname in s and eigenschaft in b for s, b in regeln)
if not hat('.fg-event-card', 'border'):
    sys.exit(1)
if not hat('.fg-event-data', 'width'):
    sys.exit(1)
if not hat('details.fg-signup', 'cursor'):
    sys.exit(1)
if not hat('.fg-signup-latch', 'display'):
    sys.exit(1)
if not hat('.fg-signup-closed', 'color'):
    sys.exit(1)
if not hat('.fg-places-none', 'color'):
    sys.exit(1)
sys.exit(0)
" "the card, the table, the signup block, its latch or the free places note has no rule"
else
	bad "the list page brings the stylesheet with it" "no stylesheet with a version on the page"
fi

# --- 9. a member signs up, gets a mail, and takes the place back out of it
echo "[9] signing up for a work duty and leaving it again"
# The recorder of the mail is a fixture of the site, not of this suite, and it
# is off by default because a real mail plugin would swallow the message first.
# It is switched on here for the same reason mail.sh switches it on, and
# run-all.sh switches it off again afterwards.
docker exec wpdev-wordpress-1 php -r "
require '/var/www/html/wp-load.php';
update_option( 'fg_test_mail_enabled', '1' );
"
clear_mails() { docker exec wpdev-wordpress-1 php -r "
require '/var/www/html/wp-load.php';
global \$wpdb;
\$table = \$wpdb->prefix . 'fg_test_mail_log';
if ( \$table === \$wpdb->get_var( \$wpdb->prepare( 'SHOW TABLES LIKE %s', \$table ) ) ) {
	\$wpdb->query( \"DELETE FROM {\$table}\" );
}
"; }
# The newest message, as recipient, subject and the plain text body.
mail_body() { docker exec wpdev-wordpress-1 php -r "
require '/var/www/html/wp-load.php';
global \$wpdb;
\$table = \$wpdb->prefix . 'fg_test_mail_log';
\$row = \$wpdb->get_row( \$wpdb->prepare( 'SELECT mail_body FROM ' . \$table . ' ORDER BY id DESC LIMIT %d', 1 ), ARRAY_A );
if ( ! \$row ) { exit( 1 ); }
echo \$row['mail_body'];
"; }
mail_to() { docker exec wpdev-wordpress-1 php -r "
require '/var/www/html/wp-load.php';
global \$wpdb;
\$table = \$wpdb->prefix . 'fg_test_mail_log';
\$row = \$wpdb->get_row( \$wpdb->prepare( 'SELECT mail_to FROM ' . \$table . ' ORDER BY id DESC LIMIT %d', 1 ), ARRAY_A );
if ( ! \$row ) { exit( 1 ); }
echo \$row['mail_to'];
"; }
mail_subject() { docker exec wpdev-wordpress-1 php -r "
require '/var/www/html/wp-load.php';
global \$wpdb;
\$table = \$wpdb->prefix . 'fg_test_mail_log';
\$row = \$wpdb->get_row( \$wpdb->prepare( 'SELECT mail_subject FROM ' . \$table . ' ORDER BY id DESC LIMIT %d', 1 ), ARRAY_A );
if ( ! \$row ) { exit( 1 ); }
echo \$row['mail_subject'];
"; }

MITGLIED_NR=$(jq member_free_no)
MITGLIED_MAIL=$(jq member_free_mail)
DIENST=$(jq event_id)
DIENST_TITEL=$(jq event_title)

clear_mails
list=$(curl -sk "$BASE$LIST_PATH")
# The nonce is read out of the page and not computed: a check that builds the
# value itself proves only that the value it built is the value it sent.
SIGN_NONCE=$(printf '%s' "$list" | grep -o 'name="fg_register_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
SIGN_REF=$(printf '%s' "$list" | grep -o 'name="fg_event_ref" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
if [ -n "$SIGN_NONCE" ] && [ -n "$SIGN_REF" ]; then
	ok "the page hands out a nonce and a reference for the duty"
else
	bad "the page hands out a nonce and a reference for the duty" "nonce: $SIGN_NONCE / reference: $SIGN_REF"
fi

VORHER=$(s count-registrations "$DIENST")
PLATZE_VORHER=$(s free-places "$DIENST")
loc=$(curl -sk -o "$DIR/anmeldung.html" -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=$SIGN_NONCE" \
	--data-urlencode "fg_event_ref=$SIGN_REF" \
	--data-urlencode "fg_member_no=$MITGLIED_NR" \
	--data-urlencode "fg_member_email=$MITGLIED_MAIL" \
	--data-urlencode "fg_website=" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
has "the registration is answered with the confirmation" "$loc" "fg_notice=registered"
if [ "$(s count-registrations "$DIENST")" = "$((VORHER + 1))" ]; then
	ok "and the member is in the duty"
else
	bad "and the member is in the duty" "was $VORHER, now $(s count-registrations "$DIENST")"
fi
if [ "$(s free-places "$DIENST")" = "$((PLATZE_VORHER - 1))" ]; then
	ok "and one place less is free"
else
	bad "and one place less is free" "was $PLATZE_VORHER, now $(s free-places "$DIENST")"
fi

MAIL_TO=$(mail_to)
MAIL_SUBJECT=$(mail_subject)
MAIL_BODY=$(mail_body)
if [ "$MAIL_TO" = "$MITGLIED_MAIL" ]; then
	ok "the mail goes to the address of the member"
else
	bad "the mail goes to the address of the member" "$MAIL_TO"
fi
has "the subject names the duty" "$MAIL_SUBJECT" "$DIENST_TITEL"
has "the body names the duty" "$MAIL_BODY" "$DIENST_TITEL"
has "the body carries the unregister link" "$MAIL_BODY" "fg_duty_action=view"
has "and the link is for removing the registration" "$MAIL_BODY" "intent=unregister"
has "the body says that the deletion has to be confirmed" "$MAIL_BODY" "auf der du das Löschen noch einmal bestätigen musst"
# The name in a mail is a decision, not an accident: the address belongs to a
# member, the member has a first name in the member list, and a club mail that
# greets a member with a bare "Hallo" is one of the things a club complains
# about. The full name does not go out — the first name says who is meant, and
# the surname is a second piece of personal data that the sentence does not need.
has "the body greets the member by the first name" "$MAIL_BODY" "Hallo Cem,"
hasnt "the body does not carry the full name" "$MAIL_BODY" "Cem Cemu"
hasnt "the body does not carry the surname" "$MAIL_BODY" "Cemu"
hasnt "and no last name" "$MAIL_BODY" "Cemu"
hasnt "and no record number of the member" "$MAIL_BODY" "member_id"
ABMELDE_URL=$(printf '%s' "$MAIL_BODY" | grep -o "$BASE/?fg_duty_action=view[^ ]*" | head -1)
if [ -n "$ABMELDE_URL" ]; then ok "the link out of the mail is extracted"; else bad "the link out of the mail is extracted" "none in the body"; fi
# The reference out of that link, read once and used for every further request.
# It is a 32 character name of one registration and not a number of a table: it
# says which registration is meant and nothing else.
ABMELDE_REF=$(printf '%s' "$ABMELDE_URL" | grep -o 'signup_ref=[^&]*' | sed 's/signup_ref=//')

# A mail scanner follows every link in a message. A link that deleted the
# registration on the first fetch would take the place away from a member who
# never asked for it, so the link only opens the page that asks.
head=$(curl -sk -D - -o "$DIR/abmelden.html" "$ABMELDE_URL")
html=$(cat "$DIR/abmelden.html")
has "the link opens the page that asks" "$html" "Anmeldung löschen"
has "the page names the duty" "$html" "$DIENST_TITEL"
has "the page names the member number" "$html" "$MITGLIED_NR"
has "the page warns that it is immediate" "$html" "sofort und ohne weitere Rückfrage"
has "the page offers a button and not a deletion" "$html" "<button"
has "the form carries the token verifier" "$html" 'name="token_nonce"'
# The link in the mail opens a page of its own and not the list: a mail reader
# has to be able to show the page it opens. Behind that page the member has to
# arrive on the list they signed up from, and which list that is comes from the
# registration, not from the page that happens to forward the click.
has "the page points back at the list the member came from" "$html" "page_id=$(jq list_page_id)"
hasnt "the page is not the ride page" "$html" "fg_ride_action"
hasnt "the page carries no theme stylesheet" "$html" "wp-content/themes"
has "x-robots-tag header" "$head" "X-Robots-Tag: noindex"
has "content security policy" "$head" "Content-Security-Policy"
has "no-store cache header" "$head" "no-store"
if [ "$(s count-registrations "$DIENST")" = "$((VORHER + 1))" ]; then
	ok "the page itself removed nothing"
else
	bad "the page itself removed nothing" "$(s count-registrations "$DIENST")"
fi

TOKEN=$(printf '%s' "$html" | val token)
TOKEN_NONCE=$(printf '%s' "$html" | val token_nonce)
# A token that was never given out opens nothing, and a reference that names no
# registration opens nothing. Both are checked here, while the registration is
# still there: after the successful removal every link to this row leads to the
# same page whether the token was right or wrong, and the check would pass even
# with the token comparison taken out. The first run of that counter-probe
# showed exactly that.
html=$(curl -sk "$BASE/?fg_duty_action=view&signup_ref=$ABMELDE_REF&intent=unregister&token=falsch")
has "a wrong token opens nothing" "$html" "Link nicht gültig"
hasnt "and does not show the form for it" "$html" 'name="token_nonce"'
html=$(curl -sk "$BASE/?fg_duty_action=view&signup_ref=0000000000000000000000000000&intent=unregister&token=$TOKEN")
has "an unknown reference opens nothing" "$html" "Link nicht gültig"
# The form carries a nonce of its own, and the confirmation is refused without
# it. Otherwise anybody who saw a link — a member on the phone, a scanner with
# the text of the mail — could answer for them by posting to the address. The
# check happens while the registration is still there, so that the refusal
# cannot come from the row already being gone.
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_duty_token" \
	--data-urlencode "signup_ref=$ABMELDE_REF" \
	--data-urlencode "intent=unregister" \
	--data-urlencode "token=$TOKEN" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
has "the confirmation without the form nonce is refused" "$loc" "fg_notice=form_expired"
if [ "$(s count-registrations "$DIENST")" = "$((VORHER + 1))" ]; then
	ok "and deletes nothing"
else
	bad "and deletes nothing" "$(s count-registrations "$DIENST")"
fi
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_duty_token" \
	--data-urlencode "signup_ref=$ABMELDE_REF" \
	--data-urlencode "intent=unregister" \
	--data-urlencode "token=$TOKEN" \
	--data-urlencode "token_nonce=falsch" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
has "a wrong form nonce is refused too" "$loc" "fg_notice=form_expired"
if [ "$(s count-registrations "$DIENST")" = "$((VORHER + 1))" ]; then
	ok "and deletes nothing there either"
else
	bad "and deletes nothing there either" "$(s count-registrations "$DIENST")"
fi
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_duty_token" \
	--data-urlencode "signup_ref=$ABMELDE_REF" \
	--data-urlencode "intent=unregister" \
	--data-urlencode "token=$TOKEN" \
	--data-urlencode "token_nonce=$TOKEN_NONCE" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
has "the confirmation removes the registration" "$loc" "fg_notice=unregistered"
has "and the member is put back onto the list" "$loc" "page_id=$(jq list_page_id)"
if [ "$(s count-registrations "$DIENST")" = "$VORHER" ]; then
	ok "and the member is out of the duty again"
else
	bad "and the member is out of the duty again" "$(s count-registrations "$DIENST")"
fi
if [ "$(s free-places "$DIENST")" = "$PLATZE_VORHER" ]; then
	ok "and the place is free again"
else
	bad "and the place is free again" "$(s free-places "$DIENST")"
fi
# Signing out is signing out of one duty, not out of the club. The member
# record belongs to the club and is not touched by anything a member does on
# the list page.
if [ "$(s member-by-no "$MITGLIED_NR" email)" = "$MITGLIED_MAIL" ]; then
	ok "the member is still in the club"
else
	bad "the member is still in the club" "$(s member-by-no "$MITGLIED_NR" email)"
fi
# The same link a second time is dead. A member who clicks twice, or a scanner
# that fetches the link again after the club deleted the row by hand, must not
# delete a registration that has meanwhile been made by somebody else.
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_duty_token" \
	--data-urlencode "signup_ref=$ABMELDE_REF" \
	--data-urlencode "intent=unregister" \
	--data-urlencode "token=$TOKEN" \
	--data-urlencode "token_nonce=$TOKEN_NONCE" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
has "the same link a second time is refused" "$loc" "fg_notice=invalid_token"
if [ "$(s count-registrations "$DIENST")" = "$VORHER" ]; then
	ok "and removes nothing the second time"
else
	bad "and removes nothing the second time" "$(s count-registrations "$DIENST")"
fi

# The card of the duty offers the place again. The count on the page is read
# from the tables, so a card that printed a number of its own making cannot
# pass this.
list=$(curl -sk "$BASE$LIST_PATH")
has "the card offers the place again" "$list" '<summary class="fg-button fg-signup-toggle">Eintragen</summary>'
if [ "$(s free-places "$DIENST")" = "$PLATZE_VORHER" ]; then
	ok "and the tables say the same number again"
else
	bad "and the tables say the same number again" "$(s free-places "$DIENST")"
fi

# One address belongs to one member, and one member can be in as many duties as
# they like. A second duty of the same run takes the same member again, which
# is the case a schema with one row per member could not answer.
ZWEITER=$(s make-event "Zweiter Dienst aus dem HTTP-Test" "$(date -d '+40 days' +%Y-%m-%d)" 2)
s register "$ZWEITER" "$MITGLIED_NR" "$MITGLIED_MAIL" >/dev/null
if [ "$(s count-registrations "$ZWEITER")" = "1" ]; then
	ok "the same member is in a second duty as well"
else
	bad "the same member is in a second duty as well" "$(s count-registrations "$ZWEITER")"
fi
s delete-event "$ZWEITER" >/dev/null

# The number and the address have to belong to one and the same member, and
# this is checked on the public path because that is the only way a member ever
# enters them. A number that is right and an address that belongs to somebody
# else would otherwise be a way to sign somebody else up.
FREMDE_NR=$(jq member_taken_no)
FREMDE_MAIL=$(jq member_taken_mail)
loc=$(curl -sk -o "$DIR/falsches-paar.html" -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=$SIGN_NONCE" \
	--data-urlencode "fg_event_ref=$SIGN_REF" \
	--data-urlencode "fg_member_no=$FREMDE_NR" \
	--data-urlencode "fg_member_email=$MITGLIED_MAIL" \
	--data-urlencode "fg_website=" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
has "a number with the address of somebody else is refused" "$loc" "fg_notice=not_registered"
if [ "$(s count-registrations "$DIENST")" = "$VORHER" ]; then
	ok "and writes nothing"
else
	bad "and writes nothing" "$(s count-registrations "$DIENST")"
fi
# The refusal is one sentence for both halves, and it does not say which of the
# two was wrong. The page is public: a hint would tell a passer-by whether a
# guessed number exists in the club at all.
hinweis=$(python3 -c "
import re,html
h=open('$DIR/falsches-paar.html',encoding='utf-8').read()
m=re.search(r'<div id=\"fg-hinweis\"[^>]*>(.*?)</div>', h, re.S)
print(html.unescape(re.sub(r'<[^>]+>','',m.group(1))).strip() if m else '')")
hasnt "the refusal does not name the number as wrong" "$hinweis" "Nummer"
hasnt "and does not name the address as wrong" "$hinweis" "Adresse"

# A registration whose mail does not arrive is a place that is taken and cannot
# be given back: the link that would free it was in that mail. So the row has to
# go again, and the free place has to come back. Without the rollback the member
# is left with a registration they never learned about, and a second attempt
# answers "already registered" to somebody who received no message at all.
mail_fail() { docker exec wpdev-wordpress-1 php -r "
require '/var/www/html/wp-load.php';
delete_option( 'fg_test_mail_fail' );
if ( '1' === '$1' ) { update_option( 'fg_test_mail_fail', '1' ); }
"; }
mail_fail 1
list=$(curl -sk "$BASE$LIST_PATH")
SIGN_NONCE=$(printf '%s' "$list" | grep -o 'name="fg_register_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/\"//')
SIGN_REF=$(printf '%s' "$list" | grep -o 'name="fg_event_ref" value="[^"]*"' | head -1 | sed 's/.*value="//;s/\"//')
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=$SIGN_NONCE" \
	--data-urlencode "fg_event_ref=$SIGN_REF" \
	--data-urlencode "fg_member_no=$MITGLIED_NR" \
	--data-urlencode "fg_member_email=$MITGLIED_MAIL" \
	--data-urlencode "fg_website=" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
has "an undelivered mail is reported" "$loc" "fg_notice=email_failed"
if [ "$(s count-registrations "$DIENST")" = "$VORHER" ]; then
	ok "and the registration is gone again"
else
	bad "and the registration is gone again" "$(s count-registrations "$DIENST")"
fi
if [ "$(s free-places "$DIENST")" = "$PLATZE_VORHER" ]; then
	ok "and the place is free again"
else
	bad "and the place is free again" "$(s free-places "$DIENST")"
fi
# The failure leaves nothing behind that a second attempt would run into.
mail_fail 0
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=$SIGN_NONCE" \
	--data-urlencode "fg_event_ref=$SIGN_REF" \
	--data-urlencode "fg_member_no=$MITGLIED_NR" \
	--data-urlencode "fg_member_email=$MITGLIED_MAIL" \
	--data-urlencode "fg_website=" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
has "a second attempt after the failure is a plain registration" "$loc" "fg_notice=registered"
if [ "$(s count-registrations "$DIENST")" = "$((VORHER + 1))" ]; then
	ok "and not a refusal of something that was still there"
else
	bad "and not a refusal of something that was still there" "$(s count-registrations "$DIENST")"
fi
# And the member takes it back out again, so the section leaves the fixture the
# way it found it. The link cannot be used for that: its token is stored as a
# hash and can therefore not be read back out of the database.
s drop-registration "$DIENST" "$MITGLIED_NR" >/dev/null
if [ "$(s count-registrations "$DIENST")" = "$VORHER" ]; then
	ok "the section leaves the duty as it found it"
else
	bad "the section leaves the duty as it found it" "$(s count-registrations "$DIENST")"
fi

echo
echo "== $pass passed, $fail failed =="
[ "$fail" -eq 0 ]
