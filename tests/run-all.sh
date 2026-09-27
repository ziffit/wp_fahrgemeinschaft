#!/usr/bin/env bash
# Full verification cycle.
#
# Brings the environment into a defined state (database, TLS, plugin
# activation, mail log) and then runs the four suites:
#   1. CLI suite inside the container
#   2. public HTTP suite (curl against Apache over TLS)
#   3. mail layer suite (messages handed to wp_mail())
#   4. admin HTTP suite (real login, real post.php round trips)
#
# Usage: bash tests/run-all.sh
#
# Environment variables:
#   SETUP   path to the environment script (default $WPDEV/setup.sh)
#   WPDEV   directory holding the compose stack of the test site, used as the
#           default for SETUP
#   BASE    public url of the test site (default https://localhost:8443)
set -u
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DIR"

SETUP="${SETUP:-${WPDEV:-}/setup.sh}"

if [ ! -f "$SETUP" ]; then
	echo "setup script not found: $SETUP" >&2
	echo "set WPDEV to the directory holding the compose stack, or SETUP to the script" >&2
	exit 2
fi

echo '### 0/5 environment'
bash "$SETUP" 2>&1 | grep -Ev '^(Container|Volume|Network| Creating| Created| Starting| Started| Recreated| +[a-z0-9_]+ +)$' | tail -16

echo
echo '### 1/5 CLI suite (php in the container)'
docker exec wpdev-wordpress-1 php /tmp/fgtests/smoke.php all 2>&1 | tail -3

echo
echo '### 2/5 public HTTP suite'
docker exec wpdev-wordpress-1 php /tmp/fgtests/http_setup.php > fixture.json 2>/dev/null
bash http.sh 2>&1 | tail -2

echo
echo '### 3/5 mail layer suite'
docker exec wpdev-wordpress-1 php /tmp/fgtests/http_setup.php > fixture.json 2>/dev/null
bash mail.sh 2>&1 | tail -3

echo
echo '### 4/5 admin HTTP suite'
# No fixture is built for this one any more. The suite brings its own work duty
# and finds the public page itself, so it does not depend on another suite
# having run before it.
bash admin.sh 2>&1 | tail -2

# The suites above need the mail recorder: it short-circuits wp_mail() and
# records the messages so the assertions can inspect them. SureMails also
# honours that filter and would never see a mail. The instance is therefore
# handed back with the recorder switched off, which is the state the manual
# tests with SureMails need. Set KEEP_RECORDER=1 to keep it active.
echo
echo '### 5/5 handover'
if [ "${KEEP_RECORDER:-0}" = "1" ]; then
	echo 'mail recorder left active (KEEP_RECORDER=1)'
else
	docker exec wpdev-wordpress-1 php -r '
	require "/var/www/html/wp-load.php";
	delete_option( "fg_test_mail_enabled" );
	delete_option( "fg_test_mail_fail" );
	printf(
		"mail recorder: %s\n",
		"1" === (string) get_option( "fg_test_mail_enabled", "0" ) ? "still active" : "off, wp_mail reaches SureMails"
	);'
fi
