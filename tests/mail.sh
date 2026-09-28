#!/usr/bin/env bash
# Mail layer checks: the messages really handed to wp_mail() by a browser
# request, with the dictated wording and as plain text, plus the MIME structure
# of the finished message (mail-mime.php).
set -u
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE="https://localhost:8443"
F="$DIR/fixture.json"
PAGE_ID=$(python3 -c "import json;print(json.load(open('$F'))['page_id'])")
EVENT_REF=$(python3 -c "import json;print(json.load(open('$F'))['event_ref'])")
EVENT_ID=$(python3 -c "import json;print(json.load(open('$F'))['event_id'])")

# Read or change plugin state from the table layer, the same way admin.sh does.
s() { docker exec wpdev-wordpress-1 php /tmp/fgtests/state.php "$@"; }

# A contact request is only answered if the requesting address belongs to a
# member who is signed up for that duty. The fixture signs up one member, and
# the published ride belongs to a second one who is not signed up at all — so
# without help this suite can neither ask about somebody else's entry nor test
# the own-entry rule, and both requests would be refused for a reason that has
# nothing to do with what they are about. The suite therefore signs up the two
# other fixture members itself and takes them off again at the end.
ANFRAGER_NR=$(python3 -c "import json;print(json.load(open('$F'))['member_free_no'])")
ANFRAGER_MAIL=$(python3 -c "import json;print(json.load(open('$F'))['member_free_mail'])")
ERSTELLER_MAIL=berta@angeln.example.org
ERSTELLER_NR=0043
# The one who offers a ride. The form takes a member number and the address that
# belongs to it, so a submission needs both, and the number comes from the
# fixture next to the address rather than being written out a second time.
EINREICHER_NR=$(python3 -c "import json;print(json.load(open('$F'))['member_taken_no'])")
EINREICHER_MAIL=$(python3 -c "import json;print(json.load(open('$F'))['member_taken_mail'])")
for paar in "$ANFRAGER_NR $ANFRAGER_MAIL" "$ERSTELLER_NR $ERSTELLER_MAIL"; do
	# shellcheck disable=SC2086
	set -- $paar
	s drop-registration "$EVENT_ID" "$1" > /dev/null 2>&1
	s register "$EVENT_ID" "$1" "$2" > /dev/null
done
trap 's drop-registration "$EVENT_ID" "$ANFRAGER_NR" > /dev/null 2>&1; s drop-registration "$EVENT_ID" "$ERSTELLER_NR" > /dev/null 2>&1' EXIT

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

# --- 1. a submission produces exactly one mail, and the entry is public
echo "[1] the mail for a new entry"
before=$(count_mails)
submit_nonce=$(curl -sk "$BASE/?page_id=$PAGE_ID" | grep -o 'name="fg_submit_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_submit_ride" \
	--data-urlencode "fg_event_ref=$EVENT_REF" \
	--data-urlencode "fg_mode=offer" \
	--data-urlencode "fg_member_no=$EINREICHER_NR" \
	--data-urlencode "fg_origin=Oststadt" \
	--data-urlencode "fg_member_email=$EINREICHER_MAIL" \
	--data-urlencode "fg_consent=1" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_submit_nonce=$submit_nonce")
has "submission is accepted" "$loc" "fg_notice=published"
after=$(count_mails)
if [ "$((after - before))" = "1" ]; then ok "exactly one mail was sent"; else bad "exactly one mail was sent" "$((after - before))"; fi

MAIL_TO=$(mail_to)
MAIL_SUBJECT=$(mail_subject)
MAIL_TYPE=$(mail_type)
MAIL_HEADERS=$(mail_headers)
MAIL_BODY=$(mail_body)
if [ "$MAIL_TO" = "$EINREICHER_MAIL" ]; then ok "mail goes to the address of the member number"; else bad "mail goes to the address of the member number" "$MAIL_TO"; fi
has "subject names the work duty" "$MAIL_SUBJECT" "Deine Fahrgemeinschaft ist eingetragen – Arbeitsdienst Laber"
has "body says the entry is in the list" "$MAIL_BODY" "deine Fahrgemeinschaft steht in der Liste"
has "body says nothing is left to confirm" "$MAIL_BODY" "es ist nichts mehr zu bestätigen"
has "body names the area the member gave" "$MAIL_BODY" "Abfahrtsbereich: Oststadt"
has "body names the first name from the member list" "$MAIL_BODY" "Vorname: Anton"
has "body says where the name comes from" "$MAIL_BODY" "Er stammt aus der Mitgliederverwaltung"
has "dictated sentence about the end of the work duty" "$MAIL_BODY" "Ist der Arbeitsdienst vorbei, wird dein Eintrag automatisch aus der öffentlichen Anzeige entfernt."
has "the mail offers the delete link" "$MAIL_BODY" "Du kannst deine Eintragung löschen, wenn du diesen Link aufrufst:"
has "the mail explains the deletion" "$MAIL_BODY" "Achtung: Beim endgültigen Löschen erfolgt keine weitere Rückfrage."
# What the recorder sees is what a mail plugin sees, and that is the point of
# the recorder: it hangs on pre_wp_mail, which is where every mail plugin takes
# the message over. Until version 1.21.0 the plugin handed over the plain text
# and built the layout behind phpmailer_init, an event a mail plugin never fires
# — so on a site with a mail plugin every message of this plugin arrived as plain
# text, without logo, without background and without a single link, while the
# preview in the admin showed all of it. The checks below are the ones that
# would have turned red then.
# The recorder stores the headers as a JSON array, and a slash in it is stored
# escaped. The comparison is made on the header line and not on the whole array,
# because an array of two entries would carry the string either way and the check
# would pass on the Reply-To alone.
if [ "${MAIL_TYPE%%;*}" = "text/html" ]; then ok "the message is declared as html ($MAIL_TYPE)"; else bad "the message is declared as html" "$MAIL_TYPE"; fi
has "and the header says so too" "${MAIL_HEADERS//\\/}" "Content-Type: text/html"
has "the body is the layout of the plugin" "$MAIL_BODY" "<!DOCTYPE html>"
has "and it carries the layout's own background" "$MAIL_BODY" 'bgcolor="#f1f1f1"'
has "and the logo by its address" "$MAIL_BODY" '<img src="https://'
has "on the club's own site" "$MAIL_BODY" "$BASE/wp-content/uploads/"
has "the link is a link in the html" "$MAIL_BODY" '<a style='
has "and it uses the https site" "$MAIL_BODY" "$BASE/?fg_ride_action=view&#038;ride_ref="
hasnt "the link carries no post id" "$MAIL_BODY" "fg_fahrgemeinschaft="
hasnt "the link has no uuid" "$MAIL_BODY" "$(python3 -c "import json;print(json.load(open('$F'))['event_uuid'])")"

echo "[1b] the link in the mail, and the entry without it"
# The mail is the receipt and the way out, not the thing that publishes the
# entry. So the entry is in the list before the link is opened, and the link
# leads to a page that removes it.
has "the entry is in the list without the link being opened" "$(curl -sk "$BASE/?page_id=$PAGE_ID")" "Oststadt"
hasnt "and the mail never calls it a pre-registration" "$MAIL_BODY" "vorgemerkt"
hasnt "the mail carries no confirmation intent" "$MAIL_BODY" "intent=confirm"
hasnt "the mail carries no discard intent" "$MAIL_BODY" "intent=discard"
# The link stands in the html as an anchor, and an ampersand in an address is
# `&#038;` there. The extraction reads the html form and turns the entity back
# into the character, so that the address that is fetched below is the one a
# member would click.
delete_url=$(printf '%s' "$MAIL_BODY" | grep -o "$BASE/?fg_ride_action=view&#038;ride_ref=[^\"]*intent=delete[^\"]*" | head -1 | sed 's/&#038;/\&/g')
if [ -n "$delete_url" ]; then ok "delete link extracted"; else bad "delete link extracted" "none"; fi
html=$(curl -sk "$delete_url")
has "link opens the deletion page" "$html" "Fahrgemeinschaft löschen"
has "the deletion page names the first name of the member" "$html" "Anton"
hasnt "the deletion page shows no other entry" "$html" "Berta"

# --- 3. deletion through the mail link
echo "[3] deletion through the mail link"
delete_url=$(printf '%s' "$MAIL_BODY" | grep -o "$BASE/?fg_ride_action=view&#038;ride_ref=[^\"]*intent=delete[^\"]*" | head -1 | sed 's/&#038;/\&/g')
html=$(curl -sk "$delete_url")
has "delete page renders" "$html" "Fahrgemeinschaft löschen"
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
hasnt "deleted entry is gone" "$body" "Oststadt"

# --- 4. contact request
echo "[4] contact request between participants"
contact_nonce=$(curl -sk "$BASE/?page_id=$PAGE_ID&ride_ref=$(python3 -c "import json;print(json.load(open('$F'))['published_ref'])")" | grep -o 'name="fg_contact_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
before=$(count_mails)
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$(python3 -c "import json;print(json.load(open('$F'))['published_ref'])")" \
	--data-urlencode "fg_contact_member_no=$ANFRAGER_NR" \
	--data-urlencode "fg_contact_email=$ANFRAGER_MAIL" \
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
has "requester receives the dictated answer" "$requester" "Wir haben das Mitglied benachrichtigt, das die Fahrgemeinschaft angeboten hat."
# The greeting names the asking member with both names. Every message of this
# plugin goes to a member of the club, and both mails of this section are the
# reason a club reads a name in a mail at all.
has "requester mail greets the asking member with both names" "$requester" "Hallo Cem Cemu,"
hasnt "requester mail carries no link of its own" "$requester" "fg_ride_action=view"
# This mail has no link of its own — there is nothing to confirm and nothing to
# delete — and it used to be plain text for the same reason as every other mail.
# What it does have is the layout, and that is what this check is about now.
has "requester mail is the layout as well" "$requester" "<!DOCTYPE html>"
has "creator mail carries the requester address" "$creator" "cem@angeln.example.org"
# The sentence names the entry without a name of its own, because a ride has no
# name of its own any more: it belongs to a member. The sentence alone would go
# to every member who ever offered a ride, so the greeting beside it is what
# tells two creators apart, and both halves are checked.
has "creator mail greets the member who offered the ride" "$creator" "Hallo Berta Beispiel,"
has "creator mail says who wrote in" "$creator" "du hast einen Interessenten für deine Eintragung."

echo "[4c] contact request to own entry"
before=$(count_mails)
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$(python3 -c "import json;print(json.load(open('$F'))['published_ref'])")" \
	--data-urlencode "fg_contact_member_no=$ERSTELLER_NR" \
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
	--data-urlencode "fg_contact_member_no=" \
	--data-urlencode "fg_contact_email=fremd@example.com" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=$contact_nonce")
has "foreign address answered identically" "$loc" "fg_notice=contact_received"
after=$(count_mails)
if [ "$after" = "$before" ]; then ok "foreign address causes no mail"; else bad "foreign address causes no mail" "$((after - before))"; fi

# The form asks two values, and one of them being right is not enough. A right
# number with an address of no member is refused like the address of nobody, and
# the mail that this section is about must not go out in either case.
echo "[4d] contact request with a right number and a foreign address"
before=$(count_mails)
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_contact_ride" \
	--data-urlencode "ride_ref=$(python3 -c "import json;print(json.load(open('$F'))['published_ref'])")" \
	--data-urlencode "fg_contact_member_no=$ANFRAGER_NR" \
	--data-urlencode "fg_contact_email=fremd@example.com" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_contact_nonce=$contact_nonce")
has "a right number does not carry a foreign address" "$loc" "fg_notice=contact_received"
after=$(count_mails)
if [ "$after" = "$before" ]; then ok "and that one causes no mail either"; else bad "and that one causes no mail either" "$((after - before))"; fi

# --- 5. behaviour when the transport refuses the message
echo "[5] delivery failure"
mail_fail() { docker exec wpdev-wordpress-1 php -r "
require '/var/www/html/wp-load.php';
\$value = '$1';
delete_option( 'fg_test_mail_fail' );
if ( '1' === \$value ) { update_option( 'fg_test_mail_fail', '1' ); }
"; }
submit_nonce=$(curl -sk "$BASE/?page_id=$PAGE_ID" | grep -o 'name="fg_submit_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')

# The counters are read before the failure and after it. The entry is written
# first and taken back out again when the mail does not go out, so the question
# the counters answer is whether the failure is counted as what it is: a failed
# mail, and not a publication. Both are read as a difference, so the check
# cannot pass on a number that was already there.
veroeffentlicht_vorher=$(s stat publish_published)
fehlgeschlagen_vorher=$(s stat mail_send_failed)
mail_fail 1
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_submit_ride" \
	--data-urlencode "fg_event_ref=$EVENT_REF" \
	--data-urlencode "fg_mode=offer" \
	--data-urlencode "fg_member_no=$EINREICHER_NR" \
	--data-urlencode "fg_origin=Oststadt-Ausfall" \
	--data-urlencode "fg_member_email=$EINREICHER_MAIL" \
	--data-urlencode "fg_consent=1" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_submit_nonce=$submit_nonce")
has "submission reports the failed delivery" "$loc" "fg_notice=email_failed"
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
hasnt "undelivered entry is gone again" "$body" "Oststadt-Ausfall"
left=$(s count-origin "Oststadt-Ausfall")
if [ "$left" = "0" ]; then ok "no record is left in the database"; else bad "no record is left in the database" "$left"; fi
fehlgeschlagen_nachher=$(s stat mail_send_failed)
if [ "$fehlgeschlagen_nachher" -gt "$fehlgeschlagen_vorher" ]; then ok "and the failed mail is counted ($fehlgeschlagen_vorher -> $fehlgeschlagen_nachher)"; else bad "the failed mail is counted" "$fehlgeschlagen_vorher -> $fehlgeschlagen_nachher"; fi
if [ "$(s stat publish_published)" = "$veroeffentlicht_vorher" ]; then ok "while no publication is counted ($(s stat publish_published))"; else bad "a failed mail counts as no publication" "$veroeffentlicht_vorher -> $(s stat publish_published)"; fi

# A ride is published when the form is sent, so an undeliverable mail leaves one
# way out and no other: the entry is taken back and the visitor is told to try
# again. What the previous code did instead — undo a publication when its mail
# failed — is gone with the second mail that made it necessary, and what replaces
# it as the case worth a check is that the member can simply submit again.
mail_fail 0
loc=$(curl -sk -o /dev/null -w '%{redirect_url}' -X POST "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=fg_submit_ride" \
	--data-urlencode "fg_event_ref=$EVENT_REF" \
	--data-urlencode "fg_mode=offer" \
	--data-urlencode "fg_member_no=$EINREICHER_NR" \
	--data-urlencode "fg_origin=Oststadt-Ruecknahme" \
	--data-urlencode "fg_member_email=$EINREICHER_MAIL" \
	--data-urlencode "fg_consent=1" \
	--data-urlencode "fg_website=" \
	--data-urlencode "source_url=$BASE/?page_id=$PAGE_ID" \
	--data-urlencode "fg_submit_nonce=$submit_nonce")
has "a second attempt after the failure is published" "$loc" "fg_notice=published"
body=$(curl -sk "$BASE/?page_id=$PAGE_ID")
has "and its entry is public at once" "$body" "Oststadt-Ruecknahme"
if [ "$(s stat publish_published)" -gt "$veroeffentlicht_vorher" ]; then ok "and now a publication is counted ($(s stat publish_published))"; else bad "the retry counts as a publication" "$veroeffentlicht_vorher -> $(s stat publish_published)"; fi
# The retry is only a way out of the failure if it comes with a working link of
# its own, and not with the one of the attempt that was taken back.
MAIL_BODY=$(mail_body)
has "and the mail of the retry carries its own delete link" "$MAIL_BODY" "Du kannst deine Eintragung löschen, wenn du diesen Link aufrufst:"
hasnt "and the mail of the failed attempt is still the newest" "$MAIL_BODY" "Oststadt-Ausfall"

# clean up: remove the test entry again through its own link
delete_url=$(mail_body | grep -o "$BASE/?fg_ride_action=view&#038;ride_ref=[^\"]*intent=delete[^\"]*" | head -1 | sed 's/&#038;/\&/g')
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
hasnt "cleanup removed the test entry" "$body" "Oststadt-Ruecknahme"

# --- 6. the structure of the finished message
# Who sends the messages of this site. The recorder is a pre_wp_mail handler
# itself, and it is not a mail plugin a club has — so the diagnostic on the admin
# screens must not name it, and a real handler must be named well enough to be
# recognised. Six shapes, because a handler can be a function name, a method of
# an object, or a closure, and two of those three are not a name at all until
# they are looked at.
echo "[5b] who sends the messages of this site"
if [ -z "$(s mail-handler nichts)" ]; then
	ok "a site without a mail handler reports none"
else
	bad "a site without a mail handler reports none" "$(s mail-handler nichts)"
fi
if [ -z "$(s mail-handler funktion fg_test_mail_filter)" ]; then
	ok "the recorder of the test environment is not named as a mail plugin"
else
	bad "the recorder of the test environment is not named as a mail plugin" "$(s mail-handler funktion fg_test_mail_filter)"
fi
if [ "$(s mail-handler funktion smtp_versand)" = "smtp_versand" ]; then
	ok "a function handler is named"
else
	bad "a function handler is named" "$(s mail-handler funktion smtp_versand)"
fi
if [ "$(s mail-handler closure)" = "eine anonyme Funktion" ]; then
	ok "a closure without a class says so instead of saying nothing"
else
	bad "a closure without a class says so instead of saying nothing" "$(s mail-handler closure)"
fi
if [ "$(s mail-handler closure-klasse)" = "FG_Mail_Templates (anonyme Funktion)" ]; then
	ok "a closure in a class names the class"
else
	bad "a closure in a class names the class" "$(s mail-handler closure-klasse)"
fi
if [ "$(s mail-handler objekt)" = "eine anonyme Klasse::handle" ]; then
	ok "an object handler names the method and not the file and the line"
else
	bad "an object handler names the method and not the file and the line" "$(s mail-handler objekt)"
fi

# The recorder above only sees what the plugin hands to wp_mail(), and that is
# the finished message: since version 1.21.0 the layout itself, and not the plain
# text, is what goes in. The structure of the message PHPMailer then writes — the
# order of both parts — is checked in the other suite, which builds the message
# the way wp_mail() does.
echo "[6] mime structure of the message"
mime=$(docker exec wpdev-wordpress-1 php /tmp/fgtests/mail-mime.php 2>&1)
printf '%s\n' "$mime"
pass=$((pass + $(printf '%s\n' "$mime" | grep -c '^  ok   ')))
fail=$((fail + $(printf '%s\n' "$mime" | grep -c '^  FAIL ')))

echo
echo "== $pass passed, $fail failed =="
[ "$fail" -eq 0 ]
