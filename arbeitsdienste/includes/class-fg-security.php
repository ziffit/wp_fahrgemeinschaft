<?php
/**
 * Security helpers.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Token, redirect and standalone-page helpers.
 */
final class FG_Security {
	/**
	 * Create a cryptographically secure URL-safe token.
	 *
	 * @return string
	 */
	public static function create_token() {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Hash a token for database storage.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function hash_token( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Compare a raw token with a stored hash in constant time.
	 *
	 * @param string $stored_hash Stored SHA-256 hash.
	 * @param string $raw_token Raw token from a request.
	 * @return bool
	 */
	public static function token_valid( $stored_hash, $raw_token ) {
		return is_string( $stored_hash )
			&& '' !== $stored_hash
			&& is_string( $raw_token )
			&& '' !== $raw_token
			&& hash_equals( $stored_hash, self::hash_token( $raw_token ) );
	}

	/**
	 * Check whether the site is served over HTTPS at all.
	 *
	 * The plugin requires an HTTPS installation, but a redirect can only be
	 * built when the site itself is configured for HTTPS. On a plain HTTP
	 * installation a forced redirect would point to a port that cannot speak
	 * TLS, which breaks the page instead of protecting it.
	 *
	 * @return bool
	 */
	public static function site_uses_https() {
		if ( is_ssl() ) {
			return true;
		}

		$home = home_url( '/' );

		return is_string( $home ) && 0 === stripos( $home, 'https://' );
	}

	/**
	 * Validate a source URL before storing it in a ride.
	 *
	 * @param mixed $candidate Candidate URL.
	 * @return string
	 */
	public static function safe_source_url( $candidate ) {
		$fallback = home_url( '/' );
		if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) {
			return $fallback;
		}

		$candidate = esc_url_raw( trim( $candidate ) );
		$home_scheme = wp_parse_url( $fallback, PHP_URL_SCHEME );
		if ( 'https' === $home_scheme ) {
			$candidate = set_url_scheme( $candidate, 'https' );
		}
		$validated = wp_validate_redirect( $candidate, $fallback );

		return $validated ? $validated : $fallback;
	}

	/**
	 * Redirect to a safe local URL and add a public notice code.
	 *
	 * The target carries the notice anchor as a fragment, so the browser brings
	 * the message into view instead of leaving the visitor at the spot where
	 * they pressed the button. The form stands at the bottom of the page, so
	 * without the fragment the message would be somewhere above the window.
	 *
	 * @param string $notice Notice key.
	 * @param string $source_url Optional source URL.
	 * @return void
	 */
	public static function redirect_with_notice( $notice, $source_url = '' ) {
		if ( '' === $source_url ) {
			$source_url = wp_get_referer();
		}

		$source_url = self::safe_source_url( $source_url );
		$target     = remove_query_arg( array( 'fg_notice', 'fg_message' ), $source_url );
		// Any fragment of the source is taken off, so the one added here is the
		// only one and cannot end up behind a second '#'.
		$target     = add_query_arg( 'fg_notice', sanitize_key( $notice ), explode( '#', $target )[0] );
		$target    .= '#' . FG_NOTICE_ANCHOR;

		wp_safe_redirect( $target, 303 );
		exit;
	}

	/**
	 * Render a self-contained, non-cacheable action page.
	 *
	 * No theme scripts, styles, images or third-party resources are loaded.
	 *
	 * @param string $title Page title.
	 * @param string $body Trusted HTML built by the plugin.
	 * @return void
	 */
	public static function render_standalone_page( $title, $body ) {
		if ( ! headers_sent() ) {
			status_header( 200 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
			header( 'X-Content-Type-Options: nosniff' );
			header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'" );
		}

		echo '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', get_locale() ) ) . '">';
		echo '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '<style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:760px;margin:3rem auto;padding:0 1rem;line-height:1.55;color:#1f2933}main{padding:1.5rem;border:1px solid #d7dce2;border-radius:6px}h1{line-height:1.2}.warning{padding:1rem;border-left:4px solid #a56a00;background:#fff8e5}.actions{display:flex;flex-wrap:wrap;gap:.75rem;margin-top:1.5rem}button{padding:.45rem .75rem;border:1px solid #004488;border-radius:2px;background:#004488;color:#fff;font:inherit;font-weight:600;cursor:pointer}button.secondary{background:#fff;color:#00366d}dl{display:grid;grid-template-columns:max-content 1fr;gap:.45rem 1rem}dt{font-weight:700}dd{margin:0}@media(max-width:560px){dl{grid-template-columns:1fr}dd{margin-bottom:.6rem}}</style>';
		echo '</head><body><main>' . wp_kses( $body, self::allowed_html() ) . '</main></body></html>';
		exit;
	}

	/**
	 * HTML allowed on the standalone token page.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function allowed_html() {
		return array(
			'h1'       => array(),
			'h2'       => array(),
			'p'        => array(),
			'strong'   => array(),
			'em'       => array(),
			'div'      => array( 'class' => true ),
			'dl'       => array(),
			'dt'       => array(),
			'dd'       => array(),
			'form'     => array( 'action' => true, 'method' => true ),
			'label'    => array( 'for' => true ),
			'input'    => array( 'type' => true, 'name' => true, 'value' => true ),
			'button'   => array( 'type' => true, 'class' => true ),
			'code'     => array(),
			'small'    => array(),
		);
	}
}
