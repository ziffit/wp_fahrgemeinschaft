#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Stoerung einer Gegenprobe — Fassungen 1.25.5 bis 1.25.9.

Aufruf:  python3 stoerung.py <nummer> <datei>

Jede Stoerung aendert genau eine Stelle und sagt danach, dass es genau eine war:
Passt der Text nicht, bricht sie ab, statt eine zweite Aenderung zu raten. Sonst
ist der Lauf gruen und hat nichts geprueft — und genau das ist der Zustand, den
diese Gegenproben aufspueren sollen.

Alle Stoerungen stehen in EINER Datei. Sie standen vorher in zwoelf und wurden
zweimal mit einem geleerten /tmp verloren.

Zwei Formen sind moeglich und beide werden gebraucht:

  alt, neu, datei          eine Stelle, und der alte Text muss genau einmal
                           vorkommen — sonst hat die Stoerung an einer Stelle
                           gegriffen, die nicht die gemeinte ist.
  text, None, datei        der fertige Text, für Störungen mit zwei Stellen,
                           von denen eine erst durch die andere erreichbar ist.
"""

import io
import sys


def stoerung_01(s):
    alt = "'group_name', 'description', 'demand', 'duration_hours', 'meeting_point', 'meeting_point_url'"
    neu = "'group_name', 'description', 'demand', 'duration_hours', 'meeting_point_url'"
    return alt, neu, 'class-fg-repository.php'


def stoerung_02(s):
    alt = "\t\tif ( isset( $getragen['meeting_point'] ) ) {"
    neu = "\t\tif ( true ) {"
    alt2 = "\t\tif ( isset( $getragen['meeting_point_url'] ) ) {"
    neu2 = "\t\tif ( true ) {"
    if s.count(alt) != 1 or s.count(alt2) != 1:
        raise SystemExit('die Stoerung 02 passt nicht')
    return [(alt, neu), (alt2, neu2)], None, 'class-fg-admin-events.php'


def stoerung_03(s):
    alt = 'Beginn: {{Uhrzeit}}\\n\\n{{Treffpunkt}}\\n\\n{{Beschreibung}}'
    neu = 'Beginn: {{Uhrzeit}}\\nTreffpunkt: {{Treffpunkt}}\\n\\n{{Beschreibung}}'
    alt2 = 'entfernt.\\n\\n{{Treffpunkt}}\\n\\n{{Beschreibung}}'
    neu2 = 'entfernt.\\n\\nTreffpunkt: {{Treffpunkt}}\\n\\n{{Beschreibung}}'
    if s.count(alt) != 1 or s.count(alt2) != 1:
        raise SystemExit('die Stoerung 03 passt nicht')
    return [(alt, neu), (alt2, neu2)], None, 'class-fg-mail-texts.php'


def stoerung_04(s):
    alt = "\t\tif ( '' !== $event->meeting_point ) {\n\t\t\t$rows[] = array(\n\t\t\t\t'label' => __( 'Treffpunkt', 'arbeitsdienste' ),"
    neu = "\t\tif ( '' !== $event->meeting_point || '' !== $event->meeting_point_url ) {\n\t\t\t$rows[] = array(\n\t\t\t\t'label' => __( 'Treffpunkt', 'arbeitsdienste' ),"
    return alt, neu, 'class-fg-public-events.php'


def stoerung_05(s):
    alt = "'Treffpunkt'           => $treffpunkt,"
    neu = "'Treffpunkt'           => 'Treffpunkt',"
    return alt, neu, 'class-fg-mailer.php'


def stoerung_06(s):
    alt = "\t\t\tif ( is_array( $form ) ) {"
    neu = "\t\t\tif ( false ) {"
    return alt, neu, 'class-fg-mail-texts.php'


def stoerung_07(s):
    alt = "'limit' => FG_Schema::MEETING_POINT_MAX,"
    neu = "'limit' => 100000,"
    return alt, neu, 'class-fg-admin-events.php'


def stoerung_08(s):
    alt = ' target="_blank" rel="noopener noreferrer">'
    neu = '>'
    return alt, neu, 'class-fg-public-events.php'


def stoerung_09(s):
    alt = """$attr = $neuer_tab ? ' target="_blank" rel="noopener noreferrer"' : '';"""
    neu = """$attr = '';"""
    return alt, neu, 'class-fg-mail-templates.php'


def stoerung_10(s):
    alt = """$attr = $neuer_tab ? ' target="_blank" rel="noopener noreferrer"' : '';"""
    neu = """$attr = ' target="_blank" rel="noopener noreferrer"';"""
    return alt, neu, 'class-fg-mail-templates.php'


def stoerung_11(s):
    alt = '<input type="time" id="fg-time" name="fg_event_time" value="<?php echo esc_attr( $time ); ?>" required>'
    neu = '<input type="time" id="fg-time" name="fg_event_time" value="<?php echo esc_attr( $time ); ?>">'
    return alt, neu, 'class-fg-admin-events.php'


def stoerung_12(s):
    alt = "\t\tif ( '' === $raw_time ) {\n\t\t\tFG_Admin::store_notice( __( 'Bitte eine Startzeit eingeben."
    neu = "\t\tif ( false ) {\n\t\t\tFG_Admin::store_notice( __( 'Bitte eine Startzeit eingeben."
    return alt, neu, 'class-fg-admin-events.php'


def stoerung_13(s):
    alt = '''\t\t$sonderzeichen = array( self::TRENNER, '"', "\\r", "\\n" );'''
    neu = "\t\t$sonderzeichen = array();"
    return alt, neu, 'class-fg-csv.php'


def stoerung_14(s):
    alt = "if ( '' !== $wert && in_array( $wert[0], array( '=', '+', '-', '@' ), true ) ) {"
    neu = "if ( false ) {"
    return alt, neu, 'class-fg-csv.php'


def stoerung_15(s):
    alt2 = "\t\t\t\t\t(string) $row['registration']->source_url,\n"
    neu2 = (
        "\t\t\t\t\t(string) $row['registration']->source_url,\n"
        "\t\t\t\t\t(string) $wpdb->get_var( $wpdb->prepare(\n"
        "\t\t\t\t\t\t'SELECT token_hash FROM ' . FG_Schema::event_member_tokens_table() . ' WHERE registration_id = %d',\n"
        "\t\t\t\t\t\t(int) $row['registration']->id\n"
        "\t\t\t\t\t) ),\n"
    )
    if s.count(alt2) != 1:
        raise SystemExit('die Stoerung 15 passt nicht (%d Treffer)' % s.count(alt2))
    s = s.replace(alt2, neu2, 1)

    alt3 = "\tpublic static function registrations( FG_Event $event, array $registrations ) {\n"
    if s.count(alt3) != 1:
        raise SystemExit('der Anfang von registrations() wurde nicht gefunden')
    return s.replace(alt3, alt3 + "\t\tglobal $wpdb;\n", 1), None, 'class-fg-csv.php'


def stoerung_16(s):
    alt = "\t\tif ( ! current_user_can( 'edit_posts' ) ) {\n\t\t\t// With 403"
    neu = "\t\tif ( false ) {\n\t\t\t// With 403"
    return alt, neu, 'class-fg-admin-events.php'


def stoerung_17(s):
    # Erst speichern, dann schicken — die Reihenfolge, die entsteht, wenn man das
    # Speichern zuerst baut und den Versand danach dazunimmt.
    alt = "\t\t$nachricht = FG_Mail_Texts::test_message( $key, $subject, $body );"
    neu = (
        "\t\t$ergebnis_zuerst = FG_Mail_Texts::save( $key, $subject, $body );\n"
        "\t\tif ( true !== $ergebnis_zuerst ) {\n"
        "\t\t\t$this->redirect_back( $key );\n"
        "\t\t}\n"
        + alt
    )
    if s.count(alt) != 1:
        raise SystemExit('die Stoerung 17 passt nicht (%d Treffer)' % s.count(alt))
    return s.replace(alt, neu, 1), None, 'class-fg-admin-mails.php'


def stoerung_18(s):
    # Der Testversand geht aus dem GESPEICHERTEN Wortlaut. Der Platzhalter wird
    # auch dort gefuellt und die Anrede auch: Die Mail sieht richtig aus und zeigt
    # dem Verein den Brief, den er schon hatte.
    alt = "\t\t$nachricht = FG_Mail_Texts::test_message( $key, $subject, $body );"
    neu = "\t\t$nachricht = FG_Mail_Texts::test_message( $key, (string) FG_Mail_Texts::stored( $key, 'subject' ), (string) FG_Mail_Texts::stored( $key, 'body' ) );"
    if s.count(alt) != 1:
        raise SystemExit('die Stoerung 18 passt nicht (%d Treffer)' % s.count(alt))
    return s.replace(alt, neu, 1), None, 'class-fg-admin-mails.php'


def stoerung_19(s):
    # Der Testversand geht an eine Adresse, die nicht dem eingeloggten Konto
    # gehoert — im echten Leben eine aus einer Vorlage, hier die eines Mitglieds.
    alt = "\t\tif ( ! $mailer->send_test( $empfaenger, $nachricht ) ) {"
    neu = "\t\tif ( ! $mailer->send_test( 'anton@angeln.example.org', $nachricht ) ) {"
    if s.count(alt) != 1:
        raise SystemExit('die Stoerung 19 passt nicht (%d Treffer)' % s.count(alt))
    return s.replace(alt, neu, 1), None, 'class-fg-admin-mails.php'


def stoerung_20(s):
    # Die Angabe der Stellenzahl fehlt in EINEM der drei Formulare — hier im
    # Anmeldeformular des Arbeitsdienstes, waehrend die beiden anderen sie tragen.
    # Das ist der Fehler, den man beim Umbau macht, wenn man nur an die eine Seite
    # denkt: Zwei Formulare sagen "4-stellig", das dritte nicht, und niemand
    # vergleicht sie. Die Behauptung zaehlt alle drei und nennt das fehlende.
    alt = "<?php esc_html_e( 'Mitgliedsnummer (4-stellig)', 'arbeitsdienste' ); ?>"
    neu = "<?php esc_html_e( 'Mitgliedsnummer', 'arbeitsdienste' ); ?>"
    if s.count(alt) != 1:
        raise SystemExit('die Stoerung 20 passt nicht (%d Treffer)' % s.count(alt))
    return alt, neu, 'class-fg-public-events.php'

STOERUNGEN = {
    '1': stoerung_01, '2': stoerung_02, '3': stoerung_03, '4': stoerung_04,
    '5': stoerung_05, '6': stoerung_06, '7': stoerung_07, '8': stoerung_08,
    '9': stoerung_09, '10': stoerung_10, '11': stoerung_11, '12': stoerung_12,
    '13': stoerung_13, '14': stoerung_14, '15': stoerung_15, '16': stoerung_16,
    '17': stoerung_17, '18': stoerung_18, '19': stoerung_19,
    '20': stoerung_20,
}

if __name__ == '__main__':
    nummer = sys.argv[1]
    pfad = sys.argv[2]
    s = io.open(pfad, encoding='utf-8').read()

    if nummer not in STOERUNGEN:
        raise SystemExit('unbekannte Stoerung: %s' % nummer)

    ergebnis = STOERUNGEN[nummer](s)

    if ergebnis[0] is None:
        raise SystemExit('die Stoerung %s hat nichts gefunden' % nummer)

    if isinstance(ergebnis[0], list):
        for alt, neu in ergebnis[0]:
            if s.count(alt) != 1:
                raise SystemExit('die Stoerung %s passt nicht auf %s (%d Treffer)' % (nummer, pfad, s.count(alt)))
            s = s.replace(alt, neu, 1)
    elif ergebnis[1] is None:
        if ergebnis[0] == s:
            raise SystemExit('die Stoerung %s aendert nichts an %s' % (nummer, pfad))
        s = ergebnis[0]
    else:
        alt, neu, _ = ergebnis
        if s.count(alt) != 1:
            raise SystemExit('die Stoerung %s passt nicht auf %s (%d Treffer)' % (nummer, pfad, s.count(alt)))
        s = s.replace(alt, neu, 1)

    io.open(pfad, 'w', encoding='utf-8').write(s)
