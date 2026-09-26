#!/usr/bin/env bash
# Mail layer checks: the messages really handed to wp_mail() by a browser
# request, with the dictated wording and as plain text.
set -u
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE="https://localhost:8443"
F="$DIR/fixture.json"
PAGE_ID=$(python3 -c "import json;print(json.load(open('$F'))['page_id'])")
EVENT_REF=$(python3 -c "import json;print(json.load(open('$F'))['event_ref'])")

pass=0; fail=0
ok()  { printf '  ok   %s\n' "$1"; pass=$((pass+1)); }
bad() { printf '  FAIL %s :: %s\n' "$1" "$2"; fail=$((fail+1)); }
has()  { if printf '%s' "$2" | grep -qF -- "$3"; then ok "$1"; else bad "$1" "missing: $3"; fi; }
hasnt(){ if printf '%s' "$2" | grep -qF -- "$3"; then bad "$1" "found: $3"; else ok "$1"; fi; }

# Print the newest mail of the plugin. The first four lines are recipient,
# subject, effective content type and the headers wp_mail() received; the body
# follows the BODY marker.
mails() { docker exec wpdev-wordpress-1 php -r "
require '/var/www/html/wp-load.php';
global \$wpdb;
\$table = \$wpdb->prefix . 'fg_test_mail_log';
\$row = \$wpdb->get_row( \$wpdb->prepare( 'SELECT mail_to, mail_subject, mail_headers, mail_content_type, mail_body FROM ' . \$table . ' ORDER BY id DESC LIMIT %d', 1 ), ARRAY_A );
if ( ! \$row ) { exit( 1 ); }
echo \$row['mail_to'], PHP_EOL, \$row['mail_subject'], PHP_EOL, \$row['mail_content_type'], PHP_EOL, \$row['mail_headers'], PHP_EOL, '###BODY###', PHP_EOL, \$row['mail_body'];
"; }
count_mails() { docker exec wpdev-wordpress-1 php -r "
require '/var/www/html/wp-load.php';
global \$wpdb;
echo (int) \$wpdb->get_var( 'SELECT COUNT(*) FROM ' . \$wpdb->prefix . 'fg_test_mail_log' );
"; }
mail_to()       { mails | sed -n 1p; }
mail_subject()  { mails | sed -n 2p; }
mail_type()     { mails | sed -n 3p; }
mail_headers()  { mails | sed -n 4p; }
mail_body()     { mails | sed -n '/^###BODY###$/,$p' | tail -n +2; }

# This suite needs the recorder: it short-circuits wp_mail() and stores the
# messages so the assertions below can read them. The recorder is off by
# default, because an active SureMails instance also honours pre_wp_mail and
# would never receive anything. So a standalone run switches it on here and
# run-all.sh switches it off again afterwards.
docker exec wpdev-wordpress-1 php -r "
require '/var/www/html/wp-load.php';
if ( '1' !== (string) get_option( 'fg_test_mail_enabled', '0' ) ) {
	update_option( 'fg_test_mail_enabled', '1' );
	echo 'mail recorder switched on for this suite', PHP_EOL;
}"

echo "== Mail level tests =="

# --- 1. submission produces exactly one confirmation mail
echo "[1] confirmation mail for a new entry"
before=$(count_mails)
submit_nonce=$(curl -sk "$BASE/?page_id=$PAGE_ID" | grep -o 'name="fg_submit_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_submit_ride" \
	--data-urlencode "fg_event_ref=$EVENT_REF" \
	--data-urlencode "fg_mode=offer" \
	--data-urlencode "fg_alias=Mailtest Trupp" \
	--data-urlencode "fg_origin=Oststadt" \
	--data-urlencode "fg_contact_email=anton@angeln.example.org" \
	--data-urlencode "fg_consent=1" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_submit_nonce=$submit_nonce")
has "submission is accepted" "$loc" "fg_notice=pending"
after=$(count_mails)
if [ "$((after - before))" = "1" ]; then ok "exactly one mail was sent"; else bad "exactly one mail was sent" "$((after - before))"; fi

MAIL_TO=$(mail_to)
MAIL_SUBJECT=$(mail_subject)
MAIL_TYPE=$(mail_type)
MAIL_HEADERS=$(mail_headers)
MAIL_BODY=$(mail_body)
if [ "$MAIL_TO" = "anton@angeln.example.org" ]; then ok "mail goes to the entered address"; else bad "mail goes to the entered address" "$MAIL_TO"; fi
has "subject names the work duty" "$MAIL_SUBJECT" "Fahrgemeinschaft bestätigen – Arbeitsdienst Laber"
has "body announces the pre-registration" "$MAIL_BODY" "noch nicht veröffentlicht"
has "body warns about private data" "$MAIL_BODY" "Bitte prüfe die Angaben sorgfältig"
if [ "$MAIL_TYPE" = "text/plain" ]; then ok "mail is plain text ($MAIL_TYPE)"; else bad "mail is plain text" "$MAIL_TYPE"; fi
hasnt "plugin asks for no html content type" "$MAIL_HEADERS" "text/html"
hasnt "mail has no html" "$MAIL_BODY" "<html"
hasnt "mail has no html links" "$MAIL_BODY" "<a href"
has "confirm link uses the https site" "$MAIL_BODY" "$BASE/?fg_ride_action=view&ride_ref="
hasnt "confirm link carries no post id" "$MAIL_BODY" "fg_fahrgemeinschaft="
hasnt "confirm link has no uuid" "$MAIL_BODY" "$(python3 -c "import json;print(json.load(open('$F'))['event_uuid'])")"

echo "[1b] the link in the mail is the only way in"
confirm_url=$(printf '%s' "$MAIL_BODY" | grep -o "$BASE/?fg_ride_action=view&ride_ref=[^ ]*" | head -1)
if [ -n "$confirm_url" ]; then ok "confirm link extracted"; else bad "confirm link extracted" "none"; fi
html=$(curl -sk "$confirm_url")
has "link opens the confirmation page" "$html" "Veröffentlichung bestätigen"
hasnt "confirmation page shows no other entry" "$html" "Amsel-Gruppe"

# --- 2. confirming sends the publication mail with the dictated sentence
echo "[2] publication mail"
ride_ref=$(printf '%s' "$html" | grep -o 'name="ride_ref" value="[^"]*"' | sed 's/.*value="//;s/"//')
token=$(printf '%s' "$html" | grep -o 'name="token" value="[^"]*"' | sed 's/.*value="//;s/"//')
token_nonce=$(printf '%s' "$html" | grep -o 'name="token_nonce" value="[^"]*"' | sed 's/.*value="//;s/"//')
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$ride_ref" \
	--data-urlencode "intent=confirm" \
	--data-urlencode "token=$token" \
	--data-urlencode "token_nonce=$token_nonce")
has "confirmation succeeds" "$loc" "fg_notice=published"
MAIL_TO=$(mail_to)
MAIL_BODY=$(mail_body)
if [ "$MAIL_TO" = "anton@angeln.example.org" ]; then ok "publication mail to the same address"; else bad "publication mail to the same address" "$MAIL_TO"; fi
has "dictated sentence about the end of the work duty" "$MAIL_BODY" "Ist der Arbeitsdienst vorbei, wird dein Eintrag automatisch aus der öffentlichen Anzeige entfernt."
has "publication mail offers the delete link" "$MAIL_BODY" "Du kannst deine Eintragung löschen, wenn du diesen Link aufrufst:"
has "publication mail explains the deletion" "$MAIL_BODY" "Achtung: Beim endgültigen Löschen erfolgt keine weitere Rückfrage."

# --- 3. deletion through the mail link
echo "[3] deletion through the mail link"
delete_url=$(printf '%s' "$MAIL_BODY" | grep -o "$BASE/?fg_ride_action=view&ride_ref=[^ ]*intent=delete[^ ]*" | head -1)
html=$(curl -sk "$delete_url")
has "delete page renders" "$html" "Veröffentlichte Fahrgemeinschaft löschen"
ride_ref=$(printf '%s' "$html" | grep -o 'name="ride_ref" value="[^"]*"' | sed 's/.*value="//;s/"//')
token=$(printf '%s' "$html" | grep -o 'name="token" value="[^"]*"' | sed 's/.*value="//;s/"//')
token_nonce=$(printf '%s' "$html" | grep -o 'name="token_nonce" value="[^"]*"' | sed 's/.*value="//;s/"//')
before=$(count_mails)
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$ride_ref" \
	--data-urlencode "intent=delete" \
	--data-urlencode "token=$token" \
	--data-urlencode "token_nonce=$token_nonce")
has "deletion succeeds" "$loc" "fg_notice=deleted"
after=$(count_mails)
if [ "$after" = "$before" ]; then ok "deletion does not send a mail"; else bad "deletion does not send a mail" "$((after - before))"; fi
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
hasnt "deleted entry is gone" "$body" "Mailtest Trupp"

# --- 4. contact request
echo "[4] contact request between participants"
contact_nonce=$(curl -sk "$BASE/?page_id=$PAGE_ID&ride_ref=$(python3 -c "import json;print(json.load(open('$F'))['published_ref'])")" | grep -o 'name="fg_contact_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
before=$(count_mails)
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$(python3 -c "import json;print(json.load(open('$F'))['published_ref'])")" \
	--data-urlencode "fg_contact_email=cem@angeln.example.org" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=$contact_nonce")
has "contact answered neutrally" "$loc" "fg_notice=contact_received"
after=$(count_mails)
if [ "$((after - before))" = "2" ]; then ok "two mails for a matching request ($((after - before)))"; else bad "two mails for a matching request" "$((after - before))"; fi

newest=$(docker exec wpdev-wordpress-1 php -r "
require '/var/www/html/wp-load.php';
global \$wpdb;
foreach ( \$wpdb->get_results( 'SELECT mail_to, mail_subject, mail_body FROM ' . \$wpdb->prefix . 'fg_test_mail_log ORDER BY id DESC LIMIT 2', ARRAY_A ) as \$row ) {
	echo \$row['mail_to'], '|', \$row['mail_subject'], '|', str_replace( \"\n\", ' ', \$row['mail_body'] ), PHP_EOL;
}")
requester=$(printf '%s' "$newest" | head -1)
creator=$(printf '%s' "$newest" | tail -1)
has "requester receives the dictated answer" "$requester" "Wir haben den Ersteller der Fahrgemeinschaft benachrichtigt."
hasnt "requester mail has no link" "$requester" "http"
hasnt "requester mail has no html" "$requester" "<a href"
has "creator mail mentions the sender" "$creator" "du hast einen Interessenten für deine Fahrgemeinschaft."
has "creator mail carries the requester address" "$creator" "cem@angeln.example.org"
has "creator mail names the entry" "$creator" "Amsel-Gruppe"

echo "[4c] contact request to own entry"
before=$(count_mails)
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$(python3 -c "import json;print(json.load(open('$F'))['published_ref'])")" \
	--data-urlencode "fg_contact_email=berta@angeln.example.org" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=$contact_nonce")
has "own entry answered neutrally" "$loc" "fg_notice=contact_received"
after=$(count_mails)
if [ "$after" = "$before" ]; then ok "own entry causes no mail"; else bad "own entry causes no mail" "$((after - before))"; fi

echo "[4b] contact request from a foreign address"
before=$(count_mails)
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$(python3 -c "import json;print(json.load(open('$F'))['published_ref'])")" \
	--data-urlencode "fg_contact_email=fremd@example.com" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=$contact_nonce")
has "foreign address answered identically" "$loc" "fg_notice=contact_received"
after=$(count_mails)
if [ "$after" = "$before" ]; then ok "foreign address causes no mail"; else bad "foreign address causes no mail" "$((after - before))"; fi

# --- 5. behaviour when the transport refuses the message
echo "[5] delivery failure"
mail_fail() { docker exec wpdev-wordpress-1 php -r "
require '/var/www/html/wp-load.php';
\$value = '$1';
delete_option( 'fg_test_mail_fail' );
if ( '1' === \$value ) { update_option( 'fg_test_mail_fail', '1' ); }
"; }
submit_nonce=$(curl -sk "$BASE/?page_id=$PAGE_ID" | grep -o 'name="fg_submit_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')

mail_fail 1
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_submit_ride" \
	--data-urlencode "fg_event_ref=$EVENT_REF" \
	--data-urlencode "fg_mode=offer" \
	--data-urlencode "fg_alias=Mailtest Ausfall" \
	--data-urlencode "fg_origin=Oststadt" \
	--data-urlencode "fg_contact_email=anton@angeln.example.org" \
	--data-urlencode "fg_consent=1" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_submit_nonce=$submit_nonce")
has "submission reports the failed delivery" "$loc" "fg_notice=email_failed"
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
hasnt "undelivered entry is gone again" "$body" "Mailtest Ausfall"
left=$(docker exec wpdev-wordpress-1 php /tmp/fgtests/state.php count-alias "Mailtest Ausfall")
if [ "$left" = "0" ]; then ok "no record is left in the database"; else bad "no record is left in the database" "$left"; fi

mail_fail 0
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_submit_ride" \
	--data-urlencode "fg_event_ref=$EVENT_REF" \
	--data-urlencode "fg_mode=offer" \
	--data-urlencode "fg_alias=Mailtest Ruecknahme" \
	--data-urlencode "fg_origin=Oststadt" \
	--data-urlencode "fg_contact_email=anton@angeln.example.org" \
	--data-urlencode "fg_consent=1" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_submit_nonce=$submit_nonce")
has "second submission is pending" "$loc" "fg_notice=pending"
confirm_url=$(mail_body | grep -o "$BASE/?fg_ride_action=view&ride_ref=[^ ]*" | head -1)
html=$(curl -sk "$confirm_url")
ride_ref=$(printf '%s' "$html" | grep -o 'name="ride_ref" value="[^"]*"' | sed 's/.*value="//;s/"//')
token=$(printf '%s' "$html" | grep -o 'name="token" value="[^"]*"' | sed 's/.*value="//;s/"//')
token_nonce=$(printf '%s' "$html" | grep -o 'name="token_nonce" value="[^"]*"' | sed 's/.*value="//;s/"//')

mail_fail 1
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$ride_ref" \
	--data-urlencode "intent=confirm" \
	--data-urlencode "token=$token" \
	--data-urlencode "token_nonce=$token_nonce")
has "confirmation reports the failed delivery" "$loc" "fg_notice=publish_failed"
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
hasnt "unpublished entry stays out of the list" "$body" "Mailtest Ruecknahme"

mail_fail 0
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$ride_ref" \
	--data-urlencode "intent=confirm" \
	--data-urlencode "token=$token" \
	--data-urlencode "token_nonce=$token_nonce")
has "the same link works again after the failure" "$loc" "fg_notice=published"
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
has "entry is public after the retry" "$body" "Mailtest Ruecknahme"

# clean up: remove the test entry again through its own link
delete_url=$(mail_body | grep -o "$BASE/?fg_ride_action=view&ride_ref=[^ ]*intent=delete[^ ]*" | head -1)
html=$(curl -sk "$delete_url")
ride_ref=$(printf '%s' "$html" | grep -o 'name="ride_ref" value="[^"]*"' | sed 's/.*value="//;s/"//')
token=$(printf '%s' "$html" | grep -o 'name="token" value="[^"]*"' | sed 's/.*value="//;s/"//')
token_nonce=$(printf '%s' "$html" | grep -o 'name="token_nonce" value="[^"]*"' | sed 's/.*value="//;s/"//')
curl -sk -o /dev/null -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_process_ride_token" \
	--data-urlencode "ride_ref=$ride_ref" \
	--data-urlencode "intent=delete" \
	--data-urlencode "token=$token" \
	--data-urlencode "token_nonce=$token_nonce"
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
hasnt "cleanup removed the test entry" "$body" "Mailtest Ruecknahme"

echo
echo "== $pass passed, $fail failed =="
[ "$fail" -eq 0 ]
