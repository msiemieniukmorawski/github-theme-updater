<?php
/**
 * Protected path matching.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * The list of theme paths that an update must never touch.
 *
 * A rule is a path relative to the theme root. It may name a file
 * (`.env`), a directory (`languages` — the whole subtree is kept), or use
 * shell wildcards (`*.log`, `assets/custom-*.css`).
 */
final class Path_Rules {

	/**
	 * Normalised rules.
	 *
	 * @var string[]
	 */
	private $rules;

	/**
	 * Constructor.
	 *
	 * @param string[] $rules Normalised rules.
	 */
	private function __construct( array $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Builds the rule set from the textarea content, one rule per line.
	 *
	 * @param string $text Raw textarea value.
	 * @return Path_Rules
	 */
	public static function from_text( $text ) {
		$lines = preg_split( '/\R/', (string) $text );
		$rules = array();

		foreach ( (array) $lines as $line ) {
			$rule = self::normalize( $line );

			if ( '' !== $rule && ! in_array( $rule, $rules, true ) ) {
				$rules[] = $rule;
			}
		}

		return new self( $rules );
	}

	/**
	 * Builds the rule set from an array of rules.
	 *
	 * @param string[] $rules Rules.
	 * @return Path_Rules
	 */
	public static function from_array( array $rules ) {
		return self::from_text( implode( "\n", $rules ) );
	}

	/**
	 * Normalises a single rule.
	 *
	 * Strips comments, leading slashes, `./` prefixes and any `..` segment, so
	 * a rule can never reach outside the theme directory.
	 *
	 * @param string $line Raw line.
	 * @return string Normalised rule, or an empty string when unusable.
	 */
	private static function normalize( $line ) {
		$line = trim( (string) $line );

		if ( '' === $line || 0 === strpos( $line, '#' ) ) {
			return '';
		}

		$line = str_replace( '\\', '/', $line );
		$line = preg_replace( '#/+#', '/', $line );
		$line = trim( $line, '/' );

		if ( 0 === strpos( $line, './' ) ) {
			$line = substr( $line, 2 );
		}

		$segments = array_filter(
			explode( '/', $line ),
			static function ( $segment ) {
				return '' !== $segment && '.' !== $segment && '..' !== $segment;
			}
		);

		return implode( '/', $segments );
	}

	/**
	 * Whether a theme-relative path is protected.
	 *
	 * A path is protected when it matches a rule, and also when it lives inside
	 * a protected directory.
	 *
	 * @param string $relative_path Path relative to the theme root.
	 * @return bool
	 */
	public function matches( $relative_path ) {
		$path = self::normalize( $relative_path );

		if ( '' === $path ) {
			return false;
		}

		foreach ( $this->rules as $rule ) {
			if ( 0 === strcasecmp( $rule, $path ) ) {
				return true;
			}

			// Anything below a protected directory is protected as well.
			if ( 0 === strncasecmp( $rule . '/', $path, strlen( $rule ) + 1 ) ) {
				return true;
			}

			if ( self::glob_match( $rule, $path ) ) {
				return true;
			}

			// A wildcard rule without a slash matches file names at any depth.
			if ( false === strpos( $rule, '/' ) && self::glob_match( $rule, wp_basename( $path ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the given directory may hold a protected path.
	 *
	 * Used to decide whether a directory may be deleted wholesale. A wildcard
	 * rule can match at any depth, so its mere presence forces the caller to
	 * walk into the directory and check every entry individually.
	 *
	 * @param string $relative_dir Directory relative to the theme root.
	 * @return bool
	 */
	public function has_match_inside( $relative_dir ) {
		$dir = self::normalize( $relative_dir );

		if ( '' === $dir ) {
			return ! empty( $this->rules );
		}

		foreach ( $this->rules as $rule ) {
			if ( 0 === strncasecmp( $dir . '/', $rule, strlen( $dir ) + 1 ) ) {
				return true;
			}

			if ( false !== strpbrk( $rule, '*?[' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Glob matcher that behaves the same way with or without ext/fnmatch.
	 *
	 * @param string $pattern Shell style pattern.
	 * @param string $subject Subject.
	 * @return bool
	 */
	public static function glob_match( $pattern, $subject ) {
		$pattern = (string) $pattern;
		$subject = (string) $subject;

		if ( '' === $pattern ) {
			return false;
		}

		if ( false === strpbrk( $pattern, '*?[' ) ) {
			return 0 === strcasecmp( $pattern, $subject );
		}

		if ( function_exists( 'fnmatch' ) ) {
			return fnmatch( $pattern, $subject, FNM_CASEFOLD );
		}

		$regex = '#^' . str_replace(
			array( '\*', '\?' ),
			array( '.*', '.' ),
			preg_quote( $pattern, '#' )
		) . '$#i';

		return 1 === preg_match( $regex, $subject );
	}

	/**
	 * All rules.
	 *
	 * @return string[]
	 */
	public function all() {
		return $this->rules;
	}

	/**
	 * Whether the rule set is empty.
	 *
	 * @return bool
	 */
	public function is_empty() {
		return empty( $this->rules );
	}

	/**
	 * Textarea representation.
	 *
	 * @return string
	 */
	public function to_text() {
		return implode( "\n", $this->rules );
	}
}
