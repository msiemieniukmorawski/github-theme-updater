<?php
/**
 * PHPUnit bootstrap.
 *
 * The classes under test are deliberately free of WordPress dependencies, so
 * the suite runs without a WordPress installation. The handful of WordPress
 * helpers they do call are stubbed here with the same behaviour as core.
 *
 * @package MSM\GitHubThemeUpdater
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( '__' ) ) {
	/**
	 * Passthrough translation stub.
	 *
	 * @param string      $text   Text to translate.
	 * @param string|null $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = null ) { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'wp_basename' ) ) {
	/**
	 * Mirrors core: basename() that also understands Windows separators.
	 *
	 * @param string $path   Path.
	 * @param string $suffix Suffix to strip.
	 * @return string
	 */
	function wp_basename( $path, $suffix = '' ) {
		return basename( str_replace( '\\', '/', $path ), $suffix );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Mirrors core.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Mirrors core.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function trailingslashit( $value ) {
		return untrailingslashit( $value ) . '/';
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Deterministic salt so encryption round trips are reproducible.
	 *
	 * @param string $scheme Salt scheme.
	 * @return string
	 */
	function wp_salt( $scheme = 'auth' ) {
		return 'phpunit-salt-' . $scheme . '-0123456789abcdef';
	}
}

$gthu_includes = dirname( __DIR__ ) . '/includes/';

require_once $gthu_includes . 'class-repository.php';
require_once $gthu_includes . 'class-path-rules.php';
require_once $gthu_includes . 'class-release.php';
require_once $gthu_includes . 'class-token-storage.php';
require_once $gthu_includes . 'class-settings.php';

// -----------------------------------------------------------------------------
// The file-deleting code is exercised against the real WP_Filesystem_Direct,
// vendored from core into tests/fixtures/wp/. It needs a few more helpers.
// -----------------------------------------------------------------------------

if ( ! defined( 'FS_CHMOD_DIR' ) ) {
	define( 'FS_CHMOD_DIR', 0755 );
}

if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	define( 'FS_CHMOD_FILE', 0644 );
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/**
	 * Mirrors core closely enough: creates missing parents.
	 *
	 * @param string $target Directory path.
	 * @return bool
	 */
	function wp_mkdir_p( $target ) {
		return is_dir( $target ) || mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'mbstring_binary_safe_encoding' ) ) {
	/**
	 * No-op: the tests never touch multibyte file names.
	 *
	 * @return void
	 */
	function mbstring_binary_safe_encoding() {}
}

if ( ! function_exists( 'reset_mbstring_encoding' ) ) {
	/**
	 * No-op counterpart of the above.
	 *
	 * @return void
	 */
	function reset_mbstring_encoding() {}
}

if ( ! function_exists( '_deprecated_function' ) ) {
	/**
	 * No-op: only reachable through methods the plugin does not call.
	 *
	 * @return void
	 */
	function _deprecated_function() {}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Mirrors core.
	 *
	 * @param mixed $thing Value to test.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stand-in for core's WP_Error.
	 */
	class WP_Error { // phpcs:ignore
		/**
		 * Error code.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}

		/**
		 * Error code accessor.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Error message accessor.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

require_once __DIR__ . '/fixtures/wp/class-wp-filesystem-base.php';
require_once __DIR__ . '/fixtures/wp/class-wp-filesystem-direct.php';
require_once $gthu_includes . 'class-filesystem.php';
require_once $gthu_includes . 'class-backup-manager.php';
