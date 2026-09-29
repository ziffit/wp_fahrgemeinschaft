#!/usr/bin/env bash
# HTTP level checks against the running WordPress instance.
set -u
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE="https://localhost:8443"
HTTP="http://localhost:8080"
F="$DIR/fixture.json"
jq() { python3 -c "import json,sys;d=json.load(open('$F'));print(d['$1'])"; }

PAGE_ID=$(jq page_id)
FIRST_ID=$(jq first_id)
FIRST_REF=$(jq first_ref)
FIRST_TOKEN=$(jq first_token)
FIRST_NONCE=$(jq first_nonce)
PUBLISHED_REF=$(jq published_ref)
SECOND_TOKEN=$(jq second_token)
SECOND_NONCE=$(jq second_nonce)
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

# The text of the message a page carries, read out of the page and stripped of
# its markup. Two notices that are compared have to be compared as the visitor
# reads them, and looking for one word in the raw HTML would not notice a word
# that the markup had swallowed.
hinweis_text() { python3 -c "
import re,sys,html
h=sys.stdin.read()
m=re.search(r'<div id=\"fg-hinweis\"[^>]*>(.*?)</div>', h, re.S)
print(html.unescape(re.sub(r'<[^>]+>','',m.group(1))).strip() if m else '')"; }

echo "== HTTP level tests =="

# --- 1. public page over HTTPS
echo "[1] public page"
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
has "page renders the offer form" "$body" "Fahrgemeinschaft anbieten"
has "page lists the published ride" "$body" "Berta"
has "page lists the ride by the first name of the member" "$body" "Suedstadt"
# The member number is looked up, never printed. A nonce value on the same page
# is random alphanumerics, and "0043" could in principle turn up inside one; the
# nonce values are taken out first so that this check can only fail because the
# number really is on the page.
ohne_nonce=$(printf '%s' "$body" | sed -E 's/(name="[^"]*nonce[^"]*"[^>]*value=")[^"]*"/\1"/g')
hasnt "page shows no member number" "$ohne_nonce" "0043"
hasnt "page hides the event address" "$body" "anton@angeln.example.org"
hasnt "page hides the creator address" "$body" "berta@angeln.example.org"
hasnt "page hides the event uuid" "$body" "$EVENT_UUID"
hasnt "page hides internal ids" "$body" "fg_fahrgemeinschaft="
hasnt "page hides internal meta" "$body" "_fg_"
hasnt "page hides tokens" "$body" "token="
has "the list says who sees an address" "$body" "Angezeigt wird nur der Vorname aus der Mitgliederverwaltung"

# --- 2. HTTPS enforcement
echo "[2] HTTPS enforcement"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' "http://localhost:8080/?page_id=$PAGE_ID")
if [ "$loc" = "https://localhost:8443/?page_id=$PAGE_ID" ]; then
	ok "http request is redirected to https with the same query"
else
	bad "http request is redirected to https with the same query" "$loc"
fi
loc=$(curl -s -o /dev/null -w '%{redirect_url}' "http://localhost:8080/?fg_ride_action=view&ride_ref=$FIRST_REF&intent=delete&token=$FIRST_TOKEN")
if printf '%s' "$loc" | grep -q "^https://localhost:8443/"; then
	ok "token page over http is redirected to https"
else
	bad "token page over http is redirected to https" "$loc"
fi
code=$(curl -sk -o /dev/null -w '%{http_code}' "$BASE/?page_id=999999")
if [ "$code" = "404" ] || [ "$code" = "200" ]; then ok "unrelated pages are untouched ($code)"; else bad "unrelated pages are untouched" "$code"; fi

# --- 3. token landing page
echo "[3] token landing page"
head=$(curl -sk -D - -o "$DIR/confirm.html" "$BASE/?fg_ride_action=view&ride_ref=$FIRST_REF&intent=delete&token=$FIRST_TOKEN")
html=$(cat "$DIR/confirm.html")
has "delete page renders" "$html" "Fahrgemeinschaft löschen"
has "delete page shows the first name of the member" "$html" "Anton"
has "delete page shows the member number to the member itself" "$html" "0042"
has "delete page names the entry that is meant" "$html" "Innenstadt"
hasnt "delete page hides the address" "$html" "anton@angeln.example.org"
has "delete page says what is about to happen" "$html" "sofort und ohne weitere Rückfrage"
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
html=$(curl -sk "$BASE/?fg_ride_action=view&ride_ref=$FIRST_REF&intent=delete&token=falsch")
has "invalid token is refused" "$html" "Link nicht gültig"
# The two intents of the state that is gone. A real token of this ride is sent
# with them, so the refusal cannot come from the token: only the intent is
# refused, and that is the check.
html=$(curl -sk "$BASE/?fg_ride_action=view&ride_ref=$FIRST_REF&intent=confirm&token=$FIRST_TOKEN")
has "the confirmation intent no longer exists" "$html" "Link nicht gültig"
html=$(curl -sk "$BASE/?fg_ride_action=view&ride_ref=$FIRST_REF&intent=discard&token=$FIRST_TOKEN")
has "the discard intent no longer exists" "$html" "Link nicht gültig"

echo "[3c] token page over GET does not change anything"
code=$(curl -sk -o /dev/null -w '%{http_code}' "$BASE/?fg_ride_action=view&ride_ref=$FIRST_REF&intent=delete&token=$FIRST_TOKEN")
vorher_stand=$(s statuses)
if [ "$vorher_stand" = "published,published," ]; then ok "GET never changes a status ($vorher_stand)"; else bad "GET never changes a status" "$vorher_stand"; fi

# --- 4. deletion over real HTTPS POST
echo "[4] deletion over POST"
# The form on the token page carries a nonce of its own. Without it the request
# is refused with the sentence about a stale form, not with the one about a dead
# link: a page that was opened a while ago is fixed by reloading it, and that is
# what the visitor is told. Both refusals happen while the entry is still there,
# so neither of them can come from the entry being gone already. The deletions
# themselves are in section 7, after the submission — a second entry is needed
# there, and a second entry is also what section 5c counts on.
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$FIRST_REF" \
	--data-urlencode "intent=delete" \
	--data-urlencode "token=$FIRST_TOKEN" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID")
if printf '%s' "$loc" | grep -q "fg_notice=form_expired"; then ok "deletion without the form nonce is refused"; else bad "deletion without the form nonce is refused" "$loc"; fi
if [ "$(s statuses)" = "published,published," ]; then ok "and deletes nothing"; else bad "and deletes nothing" "$(s statuses)"; fi
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$FIRST_REF" \
	--data-urlencode "intent=delete" \
	--data-urlencode "token=$FIRST_TOKEN" \
	--data-urlencode "token_nonce=falsch" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID")
if printf '%s' "$loc" | grep -q "fg_notice=form_expired"; then ok "a wrong form nonce is refused too"; else bad "a wrong form nonce is refused too" "$loc"; fi
if [ "$(s statuses)" = "published,published," ]; then ok "and deletes nothing there either"; else bad "and deletes nothing there either" "$(s statuses)"; fi

echo "[4b] the token of another entry"
# The link of one entry with the reference of another. The token is a real one
# and the verifier fits it, so the refusal can only come from the two not
# belonging together: a link is not a key that opens every entry.
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$FIRST_REF" \
	--data-urlencode "intent=delete" \
	--data-urlencode "token=$SECOND_TOKEN" \
	--data-urlencode "token_nonce=$SECOND_NONCE" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID")
if printf '%s' "$loc" | grep -q "fg_notice=invalid_token"; then ok "the token of another entry is refused ($loc)"; else bad "the token of another entry is refused" "$loc"; fi
if [ "$(s statuses)" = "published,published," ]; then ok "and no entry is gone for it"; else bad "and no entry is gone for it" "$(s statuses)"; fi

echo "[4c] mutations are POST only"
get_head=$(curl -sk -D - -o /dev/null "$BASE/wp-admin/admin-post.php?action=fg_process_ride_token&ride_ref=$PUBLISHED_REF&intent=delete&token=$SECOND_TOKEN")
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
NICHT_ANGEMELDET_NR=$(jq member_free_no)
FRAGE=$(jq member_taken_mail)
FRAGE_NR=$(jq member_taken_no)
# One counter per name. Reading two counters into one pair of variables and then
# comparing them with each other is a comparison of two different things, and it
# answers the wrong question in both directions at once.
ungueltig() { s stat contact_invalid_email; }
gueltig() { s stat contact_valid_email; }
vorher_u=$(ungueltig); vorher_g=$(gueltig)
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$PUBLISHED_REF" \
	--data-urlencode "fg_contact_member_no=$FRAGE_NR" \
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
	--data-urlencode "fg_contact_member_no=$ERSTELLER_NR" \
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
	--data-urlencode "fg_contact_member_no=$NICHT_ANGEMELDET_NR" \
	--data-urlencode "fg_contact_email=$NICHT_ANGEMELDET" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=$contact_nonce")
if printf '%s' "$loc" | grep -q "fg_notice=contact_received"; then ok "a member who did not sign up is answered identically"; else bad "a member who did not sign up is answered identically" "$loc"; fi
if [ "$(ungueltig)" -gt "$vorher_u" ]; then ok "and the server counted that one as refused ($vorher_u -> $(ungueltig))"; else bad "a member who did not sign up is refused" "$vorher_u -> $(ungueltig)"; fi
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$PUBLISHED_REF" \
	--data-urlencode "fg_contact_member_no=" \
	--data-urlencode "fg_contact_email=fremd@example.com" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=$contact_nonce")
if printf '%s' "$loc" | grep -q "fg_notice=contact_received"; then ok "an address without a number answered identically too"; else bad "an address without a number answered identically too" "$loc"; fi

# The form asks two values, and both have to belong to the same member. Three of
# the four cases above would look the same if only the address were checked, so
# the two halves are separated here: once with a right number and a wrong
# address, once with a wrong number and a right address. Both must be refused
# and counted, or the check above would be measuring the wrong thing.
for falsches_paar in "eine richtige Nummer mit fremder Adresse|$FRAGE_NR|fremd@example.com" "eine fremde Nummer mit richtiger Adresse|9999|$FRAGE" "zwei richtige Angaben, die zu keinem einen Mitglied gehoeren|$FRAGE_NR|$NICHT_ANGEMELDET"; do
	bezeichnung=${falsches_paar%%|*}
	praefix=${falsches_paar#*|}
	nummer=${praefix%%|*}
	adresse=${praefix#*|}
	vorher_u=$(ungueltig)
	loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
		--data-urlencode "action=fg_contact_ride" \
		--data-urlencode "ride_ref=$PUBLISHED_REF" \
		--data-urlencode "fg_contact_member_no=$nummer" \
		--data-urlencode "fg_contact_email=$adresse" \
		--data-urlencode "fg_website=" \
		--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
		--data-urlencode "fg_contact_nonce=$contact_nonce")
	if printf '%s' "$loc" | grep -q "fg_notice=contact_received"; then ok "$bezeichnung wird neutral beantwortet"; else bad "$bezeichnung wird neutral beantwortet" "$loc"; fi
	if [ "$(ungueltig)" -gt "$vorher_u" ]; then ok "und der Server zählt sie als abgewiesen ($vorher_u -> $(ungueltig))"; else bad "und der Server zählt sie als abgewiesen" "$vorher_u -> $(ungueltig)"; fi
done
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
	--data-urlencode "fg_contact_member_no=0044" \
	--data-urlencode "fg_contact_email=cem@angeln.example.org" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=abgelaufen")
if printf '%s' "$loc" | grep -q "fg_notice=form_expired#"; then ok "an expired form jumps to the message as well"; else bad "an expired form jumps to the message as well" "$loc"; fi
if [ -n "$anchor" ]; then has "the message for the error case carries the same place" "$(curl -sk "$BASE/?page_id=$PAGE_ID&fg_notice=form_expired")" "id=\"$anchor\""; fi
# a jump that ends under a header which stays in place is no jump
# The answer to a contact request talks about two values, because the form asks
# two. An answer that named only the address would send the reader looking for a
# mistake in the wrong field, and the old wording is what a form asking one
# value would have said.
has "the answer names the member number too" "$notice_page" "Mitgliedsnummer und E-Mail-Adresse zu einem Mitglied des Vereins passen"
hasnt "and the old wording is gone" "$notice_page" "sofern die angegebene Adresse zu einem Mitglied geh"
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
has "the contact form asks for the member number too" "$body" 'name="fg_contact_member_no"'
has "and says the two values have to fit one member" "$body" "Beide Angaben müssen zu einem Mitglied des Vereins passen, das sich für diesen Arbeitsdienst eingetragen hat"
has "and says that neither of the two is published" "$body" "Deine Mitgliedsnummer und deine E-Mail-Adresse stehen nirgends öffentlich"
has "and says that a mail follows, and where to look for it" "$body" "Du bekommst eine E-Mail als Bestätigung. Prüfe deinen Spam-Ordner, wenn du keine erhältst"
hasnt "the note does not promise the club enters a name any more" "$body" "Vorname und Nachname tragen wir für dich ein"
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
	struct "$style" "the label of a member field is kept on one line" "
import re, sys
css = re.sub(r'/\*.*?\*/', '', sys.stdin.read(), flags=re.S)
rules = re.findall(r'([^{}]+)\{([^}]*)\}', css)
ok = False
for sel, body in rules:
    s = sel.strip()
    if 'fg-member-row' in s and s.endswith('label') and 'white-space: nowrap' in body:
        ok = True
sys.exit(0 if ok else 1)
" "nothing keeps the label of a member field from wrapping"
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
# The two fields share one row, and the note and the button stand below it.
# The button used to be the third thing in that row; a note under a button says
# what the button does after the visitor has read what the button asks for, and
# a row that holds the two halves of one question should hold nothing else. The
# order is read out of the document and compared, because a check that only
# looked for the three parts would stay green when the button moved back up
# into the row.
struct "$body" "in every contact form the note stands between the fields and the button" "
import re, sys
h = sys.stdin.read()
forms = re.findall(r'<form class=\"fg-contact-form\".*?</form>', h, re.S)
def stelle(form, muster):
    m = re.search(muster, form)
    return m.start() if m else -1
def vollstaendig(form):
    n, h2, k = stelle(form, r'name=\"fg_contact_member_no\"'), stelle(form, r'<p class=\"fg-hint fg-hint-row\">'), stelle(form, r'<button class=\"fg-button\" type=\"submit\">')
    zeile = stelle(form, r'<div class=\"fg-member-row\">')
    ende = stelle(form, r'</div>\s*<p class=\"fg-hint')
    return -1 not in (n, h2, k, zeile) and zeile < n < h2 < k and ende > 0
sys.exit(0 if forms and all(vollstaendig(f) for f in forms) else 1)
" "a contact form has the note after the button, the button in the row of the fields, or the note without its own line"
# The note of the offer form stands on a line of its own, over the full width of
# the form, and not in the cell of the address field where it used to be: a note
# that hangs under one of the two fields reads as if it belonged to that field
# alone, and the two fields are one question. It is read out of the document and
# compared, and it is also compared with the two forms on the other page below.
struct "$body" "the note of the offer form is a line of its own, between the grid and the button" "
import re, sys
h = sys.stdin.read()
m = re.search(r'name=\"action\" value=\"fg_submit_ride\"', h)
form = h[h.rfind('<form', 0, m.start()):h.find('</form>', m.start())] if m else ''
gitter = form.find('</div>')
hinweis = re.search(r'<p class=\"fg-hint fg-hint-row\">.*?</p>', form, re.S)
knopf = form.find('<button class=\"fg-button\" type=\"submit\">')
zelle = re.search(r'<div class=\"fg-field\">\s*<label for=\"fg-ride-member-email\">.*?</div>', form, re.S)
ok = bool(hinweis) and -1 not in (gitter, knopf) and gitter < hinweis.start() < knopf and not (zelle and 'fg-hint' in zelle.group(0))
sys.exit(0 if ok else 1)
" "the note of the offer form is not a paragraph between the grid and the button, or it still hangs in the cell of the address field"
# The note is the same for every entry and is therefore stated once. The count
# only says something with more than one entry, so both belong in one check: a
# separate one would pass on a page that happens to have a single entry.
struct "$body" "the note about the pair is stated once for the whole list" "
import re, sys
h = sys.stdin.read()
entries = len(re.findall(r'<article class=\"fg-ride\">', h))
# The middle of the sentence, without the verb in front of it: the verb is
# "werden" since the note names two values, and a check that carried the whole
# sentence would have to be rewritten with every wording of it.
notes = h.count('nur an das Mitglied gesendet, das die Fahrgemeinschaft angeboten hat')
# The count alone proves nothing about the wording: it is the same sentence as
# before, with the pair in front of it. A note that named the address alone
# would pass a counting check, so the two values are looked for in it as well.
nennt_paar = ('Deine Mitgliedsnummer und deine E-Mail-Adresse' in h
              and 'sofern beide zu einem Mitglied gehören' in h)
sys.exit(0 if entries > 1 and notes == 1 and nennt_paar else 1)
" "not more than one entry, the note is not stated exactly once, or it names one of the two values alone"

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

# --- 5e. the member number is the key, the area is the only free text
# Since schema 1.4.0 a ride belongs to a member, so the form asks for a member
# number and an address instead of a name and an address, and the only thing a
# visitor types freely is the pickup area. The server counts what it treats as a
# contact detail. That counter is the only place where the distinction shows: a
# rejected entry and an entry that fails later look the same to the caller, both
# answer not_created.
echo "[5e] the member number is the key"
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
has "the form asks for the member number" "$body" 'name="fg_member_no"'
has "the form asks for the address" "$body" 'name="fg_member_email"'
hasnt "the form no longer asks for a name" "$body" 'name="fg_alias"'
hasnt "the wording about a nickname is gone" "$body" "Vorname oder Spitzname"
has "the hint names the source of the public name" "$body" "Dein Vorname steht in der Liste öffentlich"
has "the hint says both values must fit one member" "$body" "Beide Angaben müssen zu einem Mitglied des Vereins passen"
has "and says that a mail follows, and where to look for it" "$body" "Du bekommst eine E-Mail als Bestätigung. Prüfe deinen Spam-Ordner, wenn du keine erhältst"
has "the consent names the member list as the source of the name" "$body" "Dazu gehören mein Vorname aus der Mitgliederverwaltung"
has "the consent keeps the address out of the public" "$body" "Meine E-Mail-Adresse und meine Mitgliedsnummer werden dabei nicht öffentlich angezeigt"
hasnt "the ambiguous wording in the consent is gone" "$body" "persönlichen Kontaktdaten"
# The box stands above the two fields of the member, not below them. Its own last
# sentence is about the number and the address, and a reader who meets that
# sentence before the two fields knows what the form does with them. The order is
# read and compared and not merely looked for, because a check that found the
# three parts anywhere in the form would be green with the box back under them.
struct "$body" "the consent stands above the two member fields" "
import re, sys
h = sys.stdin.read()
m = re.search(r'name=\"action\" value=\"fg_submit_ride\"', h)
form = h[h.rfind('<form', 0, m.start()):h.find('</form>', m.start())] if m else ''
def stelle(muster):
    mm = re.search(muster, form)
    return mm.start() if mm else -1
bereich = stelle(r'name=\"fg_origin\"')
box = stelle(r'<input id=\"fg-consent\"')
nummer = stelle(r'name=\"fg_member_no\"')
adresse = stelle(r'name=\"fg_member_email\"')
sys.exit(0 if -1 not in (bereich, box, nummer, adresse) and bereich < box < nummer < adresse else 1)
" "the consent is not above the two member fields, or one of the three is missing from the offer form"
has "the area field carries its examples" "$body" "Abfahrtsort, Stadtteil, z. B. Langwasser, Nürnberg Nord, S-Bahnstation Ostring."
hasnt "no address field of the form invites more than the column holds" "$body" 'maxlength="254"'
has "the address of the form stops at the width of the column" "$body" 'name="fg_member_email" maxlength="190"'
has "the member number of the form stops at the width of the column" "$body" 'name="fg_member_no" maxlength="40"'
# The button has to name what the press does. Until version 1.15.0 it said
# "Eintragung vormerken" and told the visitor their entry was not in the list
# yet; since then it is in the list the moment the page comes back, and a button
# that promises a step in front of it is a wrong word on the only control of the
# form.
has "the button names what the press does" "$body" '<button class="fg-button" type="submit">Fahrgemeinschaft eintragen</button>'
hasnt "and no button promises a step in front of the entry" "$body" "vormerken"
# The layout: the pickup area takes a line of its own, and the two values that
# name a member share one. Both are read out of the class of the cell, because
# that is the only place the grid decides where a box stands.
struct "$body" "the area has a line of its own and the pair shares one" "
import re, sys
h = sys.stdin.read()
start = h.find('<div class=\"fg-form-grid\">')
g = h[start:h.find('</form>', start)] if start >= 0 else ''
def zelle(pfad):
    m = re.search(r'<div class=\"(fg-field[^\"]*)\">\s*' + pfad, g)
    return m.group(1) if m else None
area   = zelle(r'<label for=\"fg-origin\">') == 'fg-field fg-field-full'
nummer = zelle(r'<label for=\"fg-ride-member-no\">') == 'fg-field'
paar   = bool(re.search(r'name=\"fg_member_no\"[^>]*>\s*</div>\s*<div class=\"fg-field\">\s*<label[^>]*>[^<]*</label>\s*<input[^>]*name=\"fg_member_email\"', g))
sys.exit(0 if area and nummer and paar else 1)
" "the area does not span the row, or the two member fields do not share one"
# The same question in the two forms of this page has to carry the same two
# words, or a visitor who has typed the pair into one form reads a different
# question in the other. The words are compared instead of each being looked
# for on its own, so a new wording in one form alone turns the check red.
struct "$body" "both forms of the page ask for the pair in the same words" "
import re, sys
h = sys.stdin.read()
def beschriftungen(pfad):
    return [re.sub(r'\s+', ' ', w).strip() for w in re.findall(r'<label for=\"fg-' + pfad + r'[^\"]*\">([^<]*)</label>', h)]
# A list can carry several entries, and every one of them has the same two
# words, so the words are read once each. Without that, a page with two entries
# would hold four labels against two and the check would be red for the number
# of entries rather than for the wording.
def einmalig(woerter):
    raus = []
    for w in woerter:
        if w not in raus:
            raus.append(w)
    return raus
angebot = einmalig(beschriftungen('ride-member-no') + beschriftungen('ride-member-email'))
kontakt = einmalig(beschriftungen('contact-no') + beschriftungen('contact-email'))
sys.exit(0 if angebot and angebot == kontakt else 1)
" "the two forms do not ask for the same pair in the same words"

event_ref=$(printf '%s' "$body" | grep -o '<option value="[0-9a-f]\{32\}"' | head -1 | sed 's/.*value="//;s/"//')
offer_nonce=$(printf '%s' "$body" | grep -o 'name="fg_submit_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
# A member number and an address that do not belong together, so the probe is
# refused and leaves nothing behind. The counter is read before the refusal and
# raised before it, so the personal-data check still runs: that is the point of
# the counter, and section 7 is where a pair that does fit is tried.
probe() {
	curl -sk -o /dev/null -X POST "$BASE/wp-admin/admin-post.php" \
		--data-urlencode "action=fg_submit_ride" \
		--data-urlencode "fg_mode=offer" \
		--data-urlencode "fg_event_ref=$event_ref" \
		--data-urlencode "fg_member_no=0042" \
		--data-urlencode "fg_origin=$1" \
		--data-urlencode "fg_member_email=fremd@example.com" \
		--data-urlencode "fg_consent=1" \
		--data-urlencode "fg_website=" \
		--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
		--data-urlencode "fg_submit_nonce=$offer_nonce"
}
# Reads the counter before and after a list of probes and prints "start end".
# Every argument is one value for the pickup area, which is the only free text
# the form has left. One call can therefore assert on several values at once.
count_around() {
	mark=$(s stat publish_personal_data)
	for area in "$@"; do
		probe "$area"
	done
	end=$(s stat publish_personal_data)
	printf '%s %s' "$mark" "$end"
}
if [ -n "$event_ref" ] && [ -n "$offer_nonce" ]; then
	# The three examples under the area field must survive the filter, and so
	# must a plain area. A hint that offers values the server refuses is worse
	# than no hint: the entry is dropped with the same neutral message as a
	# wrong address.
	read -r before after <<< "$(count_around 'Suedstadt' 'Langwasser' 'Nürnberg Nord' 'S-Bahnstation Ostring')"
	if [ "$before" = "$after" ]; then ok "the three examples of the area hint are accepted ($after)"; else bad "the three examples of the area hint are accepted" "$before -> $after"; fi
	probe "0176 12345678"
	after_phone=$(s stat publish_personal_data)
	if [ "$after_phone" -gt "$after" ]; then ok "a phone number in the area is still refused ($after -> $after_phone)"; else bad "a phone number in the area is still refused" "$after -> $after_phone"; fi
	probe "Hauptstraße 12"
	after_street=$(s stat publish_personal_data)
	if [ "$after_street" -gt "$after_phone" ]; then ok "a street address in the area is still refused ($after_phone -> $after_street)"; else bad "a street address in the area is still refused" "$after_phone -> $after_street"; fi
else
	bad "the form offers a work duty to submit against" "no event reference on the page"
fi

# --- 6. plain submission over real POST
#
# This section stands before the deletion on purpose. A submission is published
# at once, so what the deletion removes next is an entry that came out of the
# real form, and the check that the other entry survived is then a check about
# two different origins rather than about two rows of the same fixture.
echo "[6] submission with a form nonce from the page"
submit_nonce=$(curl -sk "$BASE/?page_id=$PAGE_ID" | grep -o 'name="fg_submit_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
event_ref=$(python3 -c "import json;print(json.load(open('$F'))['event_ref'])")
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_submit_ride" \
	--data-urlencode "fg_event_ref=$event_ref" \
	--data-urlencode "fg_mode=search" \
	--data-urlencode "fg_member_no=$(jq member_taken_no)" \
	--data-urlencode "fg_origin=Weststadt" \
	--data-urlencode "fg_member_email=$(jq member_taken_mail)" \
	--data-urlencode "fg_consent=1" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_submit_nonce=$submit_nonce")
if printf '%s' "$loc" | grep -q "fg_notice=published"; then ok "a submission is published at once ($loc)"; else bad "a submission is published at once" "$loc"; fi

# A ride is offered for one duty, and it may only be offered by somebody who is
# in that duty. The pair of member number and address decides, and the member
# does not even have to know their own number: whoever signs up for a duty can
# then find a ride, and whoever is not in the duty cannot put their pair into
# the list of a duty they are not part of. Each refused case gets an area of its
# own, because the pickup area is the only free text a submission has and the
# only thing a check can look for afterwards.
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_submit_ride" \
	--data-urlencode "fg_event_ref=$event_ref" \
	--data-urlencode "fg_mode=search" \
	--data-urlencode "fg_member_no=$(jq member_free_no)" \
	--data-urlencode "fg_origin=Nichtangemeldet" \
	--data-urlencode "fg_member_email=$(jq member_free_mail)" \
	--data-urlencode "fg_consent=1" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_submit_nonce=$submit_nonce")
if printf '%s' "$loc" | grep -q "fg_notice=not_created"; then
	ok "a member who is not in the duty cannot offer a ride"
else
	bad "a member who is not in the duty cannot offer a ride" "$loc"
fi
# The other half of the same question: a number that belongs to a member and an
# address that belongs to somebody else. The pair is refused, and nothing in the
# answer says which of the two was wrong — the page is public, and a hint would
# tell a passer-by whether a guessed number exists.
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_submit_ride" \
	--data-urlencode "fg_event_ref=$event_ref" \
	--data-urlencode "fg_mode=search" \
	--data-urlencode "fg_member_no=$(jq member_taken_no)" \
	--data-urlencode "fg_origin=FalschesPaar" \
	--data-urlencode "fg_member_email=$(jq member_free_mail)" \
	--data-urlencode "fg_consent=1" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_submit_nonce=$submit_nonce")
if printf '%s' "$loc" | grep -q "fg_notice=not_created"; then
	ok "a number and an address of two different members are refused"
else
	bad "a number and an address of two different members are refused" "$loc"
fi
# The two refusals above are read by a member who wants to know what to type next.
# The form asks two values, so the message names two: the wording that told the
# visitor to use the address on file is gone, because it would send a reader with
# a correct number and a stale address looking for the mistake in the wrong
# field. The sentence is also the one of the signup form; section 9 puts the two
# next to each other, because a page may show one of them at a time.
refusal=$(curl -sk "$BASE/?page_id=$PAGE_ID&fg_notice=not_created")
refusal_text=$(printf '%s' "$refusal" | hinweis_text)
PAAR_SATZ="Mitgliedsnummer und E-Mail-Adresse müssen zu einem Mitglied des Vereins passen."
if [ "$refusal_text" = "Die Eintragung konnte nicht angelegt werden. $PAAR_SATZ" ]; then
	ok "the refusal of the offer form names the pair in one sentence ($refusal_text)"
else
	bad "the refusal of the offer form names the pair in one sentence" "$refusal_text"
fi
nach_den_refusals=$(curl -sk "$BASE/?page_id=$PAGE_ID")
hasnt "the ride of the member who is not in the duty is nowhere on the page" "$nach_den_refusals" "Nichtangemeldet"
hasnt "and neither is the one of the mismatched pair" "$nach_den_refusals" "FalschesPaar"
has "the accepted ride is in the list at once" "$nach_den_refusals" "Weststadt"
hasnt "and the list shows a first name next to the area, not both names" "$nach_den_refusals" "Anton Weststadt"
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_submit_ride" \
	--data-urlencode "fg_event_ref=$event_ref" \
	--data-urlencode "fg_mode=search" \
	--data-urlencode "fg_member_no=0044" \
	--data-urlencode "fg_origin=Manipuliert" \
	--data-urlencode "fg_member_email=cem@angeln.example.org" \
	--data-urlencode "fg_consent=1" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_submit_nonce=manipuliert")
if printf '%s' "$loc" | grep -q "fg_notice=form_expired"; then ok "manipulated nonce gets a reload hint ($loc)"; else bad "manipulated nonce gets a reload hint" "$loc"; fi

# --- 7. deletion over POST
#
# Two entries of the fixture and one that section 6 submitted through the real
# form. Each is deleted with its own link, and after every step the two others
# are looked at: a deletion link belongs to one entry, so the checks on the
# survivors are what tell a deletion from a wipe.
echo "[7] self service deletion"
# The counter is read before and after, because both fixture entries carry a
# token and a count of one would say nothing about which one was spent.
tokens_vorher=$(s count-delete-token)
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$FIRST_REF" \
	--data-urlencode "intent=delete" \
	--data-urlencode "token=$FIRST_TOKEN" \
	--data-urlencode "token_nonce=$FIRST_NONCE" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID")
if printf '%s' "$loc" | grep -q "fg_notice=deleted"; then ok "deletion succeeds"; else bad "deletion succeeds" "$loc"; fi
tokens_nachher=$(s count-delete-token)
if [ "$tokens_nachher" = "$((tokens_vorher - 1))" ]; then
	ok "the deletion token is consumed ($tokens_vorher -> $tokens_nachher)"
else
	bad "the deletion token is consumed" "$tokens_vorher -> $tokens_nachher"
fi
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
hasnt "deleted ride is gone" "$body" "Innenstadt"
has "the ride of the other fixture is untouched" "$body" "Suedstadt"
has "and the submitted one as well" "$body" "Weststadt"

echo "[7b] replay of the same link"
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$FIRST_REF" \
	--data-urlencode "intent=delete" \
	--data-urlencode "token=$FIRST_TOKEN" \
	--data-urlencode "token_nonce=$FIRST_NONCE" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID")
if printf '%s' "$loc" | grep -q "fg_notice=invalid_token"; then ok "replay is refused ($loc)"; else bad "replay is refused" "$loc"; fi
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
has "and the replay leaves the other entry of the fixture alone" "$body" "Suedstadt"

echo "[7c] the second deletion"
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$PUBLISHED_REF" \
	--data-urlencode "intent=delete" \
	--data-urlencode "token=$SECOND_TOKEN" \
	--data-urlencode "token_nonce=$SECOND_NONCE" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID")
if printf '%s' "$loc" | grep -q "fg_notice=deleted"; then ok "the second deletion succeeds too"; else bad "the second deletion succeeds too" "$loc"; fi
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
hasnt "deleted ride is gone" "$body" "Suedstadt"
has "the ride that is left is untouched" "$body" "Weststadt"

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
# The card carries no frame any more: the border and the radius are gone and the
# side padding went with them, so the card reads as a row of the list and not as a
# box. The check holds that state in both directions — a padding that is not the
# one that is there and a frame that comes back both turn it red, and the second
# half is what would have caught the change while it was still unversioned.
if not hat('.fg-event-card', 'padding: 1.25rem 0'):
    sys.exit(1)
if hat('.fg-event-card', 'border'):
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

	struct "$list_style" "only the name of a row is set in the weight" "
import re, sys
css = re.sub(r'/\*.*?\*/', '', sys.stdin.read(), flags=re.S)
regeln = re.findall(r'([^{}]+)\{([^}]*)\}', css)

# The name of a row is the frame and is set in the weight; the value next to it is
# what is being read and never is. Both halves are checked, because a claim about
# weight that only says 'the value is not bold' is satisfied by a stylesheet in
# which nothing is bold at all.
name_dick = False
for selektor, koerper in regeln:
    for teil in [t.strip() for t in selektor.split(',')]:
        # A rule that is only about the width of a cell may carry both classes in
        # one line; the cell at the end of the selector is the one it draws.
        letzte = teil.split()[-1] if teil.split() else ''
        if letzte == '.fg-places':
            if 'font-weight' in koerper:
                sys.exit(1)
            continue
        if letzte.endswith('td'):
            if 'font-weight' in koerper:
                sys.exit(1)
            continue
        if letzte.endswith('th') and '.fg-event-data' in teil and 'font-weight' in koerper:
            name_dick = True
if not name_dick:
    sys.exit(1)
sys.exit(0)
" "a value of the duty table is set in the weight, or the name of a row is not"

	struct "$list_style" "the stylesheet stacks the duty table on a narrow screen" "
import re, sys
css = sys.stdin.read()

# Only the block for narrow screens is read, and it is read as text with its
# braces counted. The rules of the same selectors outside the block are the
# desktop ones, and a check that cannot tell the two apart would be green for a
# stylesheet in which the table is still a two-column table on a phone.
anfang = css.find('@media')
if anfang < 0:
    sys.exit(1)
tief = 0
ende = anfang
for i, z in enumerate(css[anfang:], anfang):
    if z == '{':
        tief += 1
    elif z == '}':
        tief -= 1
        if tief == 0:
            ende = i
            break
block = re.sub(r'/\*.*?\*/', '', css[anfang:ende + 1], flags=re.S)
regeln = re.findall(r'([^{}]+)\{([^}]*)\}', block)
def hat(klassenname, eigenschaft):
    return any(klassenname in s and eigenschaft in b for s, b in regeln)

# The name of a row above its value: both cells become blocks, and the name is
# allowed to wrap. A table that only loses its width keeps two columns.
if not hat('.fg-event-data th', 'display: block'):
    sys.exit(1)
if not hat('.fg-event-data td', 'display: block'):
    sys.exit(1)
if not hat('.fg-event-data th', 'white-space: normal'):
    sys.exit(1)
# The line between two rows stands above the name of the second row and not
# between the name and the value, which would cut the value off from its name.
if not hat('.fg-event-data tr + tr th', 'border-top'):
    sys.exit(1)
if not hat('.fg-event-data tr + tr td', 'border-top: 0'):
    sys.exit(1)
# The grid of the rides may not be wider than the column it stands in: a fixed
# minimum of 380px pushes a phone sideways. This one is read from the whole
# stylesheet and not from the narrow-screen block, because the rule belongs to
# every width: the grid is min(380px, 100%) on the desktop and on the phone, and
# a rule inside the media query would only have fixed the second of the two.
if not any('.fg-rides' in s and 'min(380px, 100%)' in b for s, b in re.findall(r'([^{}]+)\{([^}]*)\}', re.sub(r'/\*.*?\*/', '', css, flags=re.S))):
    sys.exit(1)
# The button that ends the form takes the whole width of the phone.
if not hat('.fg-actions .fg-button', 'width: 100%'):
    sys.exit(1)
# What was removed: the cards, the sections and the rides no longer shrink their
# padding on a phone. A rule that is gone cannot be looked for, so the claim is
# the absence — and an absence is exactly what a check like this exists for.
if re.search(r'\.fg-(section|event-card|ride)\b[^{}]*\{[^{}]*padding:\s*1rem', block):
    sys.exit(1)
sys.exit(0)
" "on a narrow screen the name is not above the value, the line is in the wrong place, the rides grid is wider than the page, the button is not full width, or the removed padding rule is back"
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
# The third form of the plugin, and it stands on another page than the two
# others, so the three are put side by side before they are compared. The claim
# is about the three together: two forms on the offer page that match each other
# and a third one that says something else of its own accord are two rules, not
# one. The labels of a page are read once each, because a page with two entries
# carries the same pair twice and the comparison would be red for the number of
# entries rather than for the wording.
struct "$(printf '%s\n<!-- TRENNER -->\n%s' "$body" "$list")" "the signup form asks for the pair in the words the offer form uses" "
import re, sys
teile = sys.stdin.read().split('<!-- TRENNER -->')
if len(teile) != 2:
    sys.exit(1)
def worte(teil, praefixe):
    gefunden = []
    for praefix in praefixe:
        gefunden += re.findall(r'<label for=\"fg-' + praefix + r'[^\"]*\">([^<]*)</label>', teil)
    raus = []
    for w in gefunden:
        w = re.sub(r'\s+', ' ', w).strip()
        if w not in raus:
            raus.append(w)
    return raus
angebot  = worte(teile[0], ('ride-member-no', 'ride-member-email'))
anmelde  = worte(teile[1], ('member-no', 'member-email'))
sys.exit(0 if angebot and angebot == anmelde else 1)
" "the three forms do not ask for the same pair in the same words"
# The third form stands on this page, and it is the form whose note the club
# has rewritten. Its two fields share one row, the note stands between that row
# and the button, and the note says what the club asked for and nothing more.
# The text is compared as a whole and not looked for in pieces: the sentence
# about the club entering a name is gone, and a check that looked for the two
# sentences that are there would not notice an extra one.
struct "$list" "in the signup form the note stands between the fields and the button" "
import re, sys
h = sys.stdin.read()
form = re.search(r'<form class=\"fg-signup-form\".*?</form>', h, re.S)
form = form.group(0) if form else ''
def stelle(muster):
    m = re.search(muster, form)
    return m.start() if m else -1
zeile = stelle(r'<div class=\"fg-member-row\">')
nummer = stelle(r'name=\"fg_member_no\"')
adresse = stelle(r'name=\"fg_member_email\"')
hinweis = re.search(r'<p class=\"fg-hint fg-hint-row\">.*?</p>', form, re.S)
knopf = stelle(r'<button class=\"fg-button\" type=\"submit\">')
text = re.sub(r'\\s+', ' ', re.sub(r'<[^>]+>', ' ', hinweis.group(0))).strip() if hinweis else ''
erwartet = 'Beide Angaben müssen zu einem Mitglied des Vereins passen. Du bekommst eine E-Mail als Bestätigung. Prüfe deinen Spam-Ordner, wenn du keine erhältst.'
sys.exit(0 if hinweis and -1 not in (zeile, nummer, adresse, knopf) and zeile < nummer < adresse < hinweis.start() < knopf and text == erwartet else 1)
" "the note of the signup form is not the one sentence between the fields and the button"
# One sentence about the mailbox in all three forms, and one class for the
# place the note has in the form. The three forms are on two pages, so both
# pages go into one input with a marker between them and the three notes are
# put side by side before they are compared: two forms that match each other
# and a third with a wording of its own are two rules with an exception. All
# three texts are compared as a whole, each one exactly, which also catches a
# fourth wording that nobody asked for.
struct "$(printf '%s\n<!-- TRENNER -->\n%s' "$body" "$list")" "all three notes stand in the same place and say the same thing about the mailbox" "
import re, sys
teile = sys.stdin.read().split('<!-- TRENNER -->')
if len(teile) != 2:
    sys.exit(1)
notizen = re.findall(r'<p class=\"([^\"]*)\">(.*?)</p>', teile[0] + teile[1], re.S)
# Not every note on the two pages is one of the three: a form carries a second
# one with the link to the privacy statement, and the list carries a note of its
# own. The three are told apart by their text, and only those are then asked to
# carry the class.
texten = []
for klasse, inhalt in notizen:
    text = re.sub(r'\\s+', ' ', re.sub(r'<[^>]+>', ' ', inhalt)).strip()
    if not text.startswith('Beide Angaben müssen'):
        continue
    if klasse.strip() != 'fg-hint fg-hint-row':
        sys.exit(1)
    texten.append(text)
erwartet = {
    'Beide Angaben müssen zu einem Mitglied des Vereins passen. Du bekommst eine E-Mail als Bestätigung. Prüfe deinen Spam-Ordner, wenn du keine erhältst.',
    'Beide Angaben müssen zu einem Mitglied des Vereins passen, das sich für diesen Arbeitsdienst eingetragen hat. Dein Vorname steht in der Liste öffentlich; E-Mail-Adresse und Mitgliedsnummer nicht. Du bekommst eine E-Mail als Bestätigung. Prüfe deinen Spam-Ordner, wenn du keine erhältst.',
    'Beide Angaben müssen zu einem Mitglied des Vereins passen, das sich für diesen Arbeitsdienst eingetragen hat. Deine Mitgliedsnummer und deine E-Mail-Adresse stehen nirgends öffentlich. Du bekommst eine E-Mail als Bestätigung. Prüfe deinen Spam-Ordner, wenn du keine erhältst.',
}
sys.exit(0 if len(set(texten)) == 3 and set(texten) == erwartet else 1)
" "a note is not in the class of the other two, or one of the three texts is not the one of its form"

VORHER=$(s count-registrations "$DIENST")
PLATZE_VORHER=$(s free-places "$DIENST")
# The body of a 303 carries nothing, so it goes to /dev/null. An earlier version
# of this line wrote it to a file, and no check ever read that file: a leftover
# that could only be mistaken for evidence.
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
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
# The greeting names the member with both names, because every message of this
# plugin goes to a member of the club and a club mail that greets a member with
# a bare "Hallo" is one of the things a club complains about. The surname
# belongs to the greeting and to nothing else: the rest of the mail is the same
# text whoever it is addressed to, and a name that stands in a sentence twice is
# one piece of personal data too often. Until version 1.15.0 the surname did not
# go out at all; the greeting is the one place the club reads a name of a member
# as a whole, and the rest of the text still carries the first name alone.
has "the body greets the member with both names" "$MAIL_BODY" "Hallo Cem Cemu,"
# Two claims, because they are two things: the surname may not stand a second
# time anywhere in the mail, and the text block may not open with anything else.
# One check said both, and when it went red it did not say which of the two it was.
struct "$MAIL_BODY" "and the surname stands once in the mail" "
import sys
sys.exit(0 if sys.stdin.read().count('Cemu') == 1 else 1)
" "the surname stands a second time in the mail"
# The greeting is read with the helper that admin.sh uses as well, and not with a
# second rule written here. In the layout the first line of the file is
# "<!DOCTYPE html>" and the subject stands in the heading of the letterhead, so a
# check that reads the first line of the file reads something that is not the
# greeting, and would have gone red for a mail that greets its member correctly.
recorded_greeting=$(s mail-recorded-greeting)
if [ "$recorded_greeting" = "Hallo Cem Cemu," ]; then
	ok "and the text block opens with the greeting ($recorded_greeting)"
else
	bad "and the text block opens with the greeting" "$recorded_greeting"
fi
hasnt "and no record number of the member" "$MAIL_BODY" "member_id"
# The link stands in the html as an attribute of an anchor, so the search ends at
# the closing quote, and an ampersand in an address is `&#038;` there. The
# entity is turned back into the character afterwards, so that the address which
# is fetched below is the one a member would click.
ABMELDE_URL=$(printf '%s' "$MAIL_BODY" | grep -o "$BASE/?fg_duty_action=view[^\"]*" | head -1 | sed 's/&#038;/\&/g')
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
loc=$(curl -skL -o "$DIR/falsches-paar.html" -w '%{url_effective}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_register_member" \
	--data-urlencode "fg_register_nonce=$SIGN_NONCE" \
	--data-urlencode "fg_event_ref=$SIGN_REF" \
	--data-urlencode "fg_member_no=$FREMDE_NR" \
	--data-urlencode "fg_member_email=$MITGLIED_MAIL" \
	--data-urlencode "fg_website=" \
	--data-urlencode "form_started_at=$(($(date +%s) - 30))" \
	--data-urlencode "source_url=$BASE$LIST_PATH")
# The address the visitor ends on, not the address of the redirect: the redirect
# was followed above, and %redirect_url is empty once it has been.
has "a number with the address of somebody else is refused" "$loc" "fg_notice=not_registered"
if [ "$(s count-registrations "$DIENST")" = "$VORHER" ]; then
	ok "and writes nothing"
else
	bad "and writes nothing" "$(s count-registrations "$DIENST")"
fi
# The refusal is one sentence for both halves, and it does not say which of the
# two was wrong. The page is public: a hint would tell a passer-by whether a
# guessed number exists in the club at all, and the sentence is read here from
# the page the visitor really lands on.
#
# -L is not a convenience here, it is what the check needs: a refused form ends
# in a 303 whose body is empty, and the sentence lives on the page that the
# redirect points at. Without the flag the file below holds nothing, every check
# on it is green, and the sentence is never looked at.
hinweis=$(hinweis_text < "$DIR/falsches-paar.html")
if [ -n "$hinweis" ]; then
	ok "the refusal is read from the page the redirect leads to"
else
	bad "the refusal is read from the page the redirect leads to" "the page carries no message"
fi
# It does not say which of the two was wrong. A sentence that names one of the
# two without the other would do exactly that, so the two are looked at together:
# every sentence of the message that mentions a field has to mention the other
# one as well. The wording of the pair is compared further down.
struct "$hinweis" "no sentence of the refusal names one of the two without the other" "
import re, sys
t = sys.stdin.read().strip()
saetze = [s for s in re.split(r'(?<=[.])\s+', t) if s.strip()]
# Lower case, because the number sits in a compound word: 'Mitgliedsnummer' has
# a small n in it, and a check for 'Nummer' would not find the field at all.
sys.exit(0 if saetze and all(('nummer' in s.lower()) == ('adresse' in s.lower()) for s in saetze) else 1)
" "a sentence names one of the two fields on its own, so it points at the wrong one"
# The same rule, the same sentence. All three forms of this plugin ask for the
# same pair, so a member who is refused by one of them has to read the same
# sentence in the other: two messages about one rule that differ in wording read
# as two rules. The two notices differ in their first half on purpose — one form
# registers a member, the other publishes an entry — so the sentence behind it is
# what is compared, and it is compared as the visitor reads it.
if [ "$hinweis" = "Die Anmeldung ist nicht möglich. $PAAR_SATZ" ]; then
	ok "the signup form refuses in the words the offer form uses"
else
	bad "the signup form refuses in the words the offer form uses" "$hinweis"
fi

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
