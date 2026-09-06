<?php
/**
 * Access token storage.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts the GitHub token before it reaches the options table.
 *
 * This is obfuscation at rest, not a vault: anybody able to read wp-config.php
 * can also read the salts. It does mean that a leaked database dump alone does
 * not hand over a working repository token.
 */
final class Token_Storage {

	/**
	 * Marker for AES-encrypted values.
	 */
	const PREFIX_ENC = 'gthu:enc:';

	/**
	 * Marker for values stored as-is (no OpenSSL on the host).
	 */
	const PREFIX_RAW = 'gthu:raw:';

	/**
	 * Cipher used when OpenSSL is available.
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * Encrypts a token for storage.
	 *
	 * @param string $token Clear text token.
	 * @return string Storable representation.
	 */
	public static function encrypt( $token ) {
		$token = (string) $token;

		if ( '' === $token ) {
			return '';
		}

		if ( ! self::can_encrypt() ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Text-safe encoding of a stored credential, not obfuscation.
			return self::PREFIX_RAW . base64_encode( $token );
		}

		$iv     = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$cipher = openssl_encrypt( $token, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Text-safe encoding of a stored credential, not obfuscation.
			return self::PREFIX_RAW . base64_encode( $token );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext has to survive a text column.
		return self::PREFIX_ENC . base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypts a stored token.
	 *
	 * Values without a known prefix are returned untouched, which transparently
	 * migrates tokens saved by version 1.x of the plugin.
	 *
	 * @param string $stored Stored representation.
	 * @return string Clear text token.
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;

		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, self::PREFIX_RAW ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reads back what encrypt() wrote.
			return (string) base64_decode( substr( $stored, strlen( self::PREFIX_RAW ) ), true );
		}

		if ( 0 !== strpos( $stored, self::PREFIX_ENC ) ) {
			return $stored;
		}

		if ( ! self::can_encrypt() ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reads back what encrypt() wrote.
		$payload = base64_decode( substr( $stored, strlen( self::PREFIX_ENC ) ), true );

		if ( false === $payload ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );

		if ( strlen( $payload ) <= $iv_length ) {
			return '';
		}

		$plain = openssl_decrypt(
			substr( $payload, $iv_length ),
			self::CIPHER,
			self::key(),
			OPENSSL_RAW_DATA,
			substr( $payload, 0, $iv_length )
		);

		return false === $plain ? '' : $plain;
	}

	/**
	 * Whether a value is already in storage form rather than a clear-text token.
	 *
	 * @param string $value Value to inspect.
	 * @return bool
	 */
	public static function is_stored( $value ) {
		$value = (string) $value;

		return 0 === strpos( $value, self::PREFIX_ENC ) || 0 === strpos( $value, self::PREFIX_RAW );
	}

	/**
	 * Renders a token preview safe to show in the admin.
	 *
	 * @param string $token Clear text token.
	 * @return string
	 */
	public static function mask( $token ) {
		$token  = (string) $token;
		$length = strlen( $token );

		if ( $length < 8 ) {
			return str_repeat( '*', max( 0, $length ) );
		}

		return substr( $token, 0, 4 ) . str_repeat( '*', 8 ) . substr( $token, -4 );
	}

	/**
	 * Whether OpenSSL can be used.
	 *
	 * @return bool
	 */
	private static function can_encrypt() {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * Derives the encryption key from the WordPress salts.
	 *
	 * @return string
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|gthu', true );
	}
}
