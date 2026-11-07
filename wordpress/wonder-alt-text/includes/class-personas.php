<?php
/**
 * Narrator personas and shared prompt text, loaded from spec/.
 *
 * Each persona is an original character defined by how it sees and speaks,
 * not by any real person. See SPEC.md in the repository.
 *
 * @package Wonder_Alt_Text
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wonder_Alt_Personas {

	/** @var array|null */
	private static $spec = null;

	/**
	 * Load the spec files once.
	 *
	 * @return array{personas:array, parts:array, tool:array, template:string}
	 */
	public static function spec() {
		if ( null === self::$spec ) {
			$dir        = WONDER_ALT_DIR . 'spec/';
			self::$spec = array(
				'personas' => json_decode( (string) file_get_contents( $dir . 'personas.json' ), true ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				'parts'    => json_decode( (string) file_get_contents( $dir . 'prompt-parts.json' ), true ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				'tool'     => json_decode( (string) file_get_contents( $dir . 'tool.json' ), true ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				'template' => (string) file_get_contents( $dir . 'system-prompt.md' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			);
		}
		return self::$spec;
	}

	/**
	 * All personas, keyed by slug.
	 *
	 * @return array<string, array{slug:string, name:string, tagline:string, voice:string}>
	 */
	public static function all() {
		$personas = array();
		foreach ( (array) self::spec()['personas'] as $p ) {
			$personas[ $p['slug'] ] = $p;
		}

		/**
		 * Filter the persona list. Developers can add, remove or rewrite personas.
		 *
		 * @param array $personas Personas keyed by slug.
		 */
		return apply_filters( 'wonder_alt_personas', $personas );
	}

	/**
	 * Valid persona slugs.
	 *
	 * @return string[]
	 */
	public static function slugs() {
		return array_keys( self::all() );
	}

	/**
	 * Human-readable names for a set of slugs, joined with " + ".
	 *
	 * @param string[] $slugs Persona slugs.
	 * @return string
	 */
	public static function names( $slugs ) {
		$all   = self::all();
		$names = array();
		foreach ( (array) $slugs as $slug ) {
			if ( isset( $all[ $slug ] ) ) {
				$names[] = $all[ $slug ]['name'];
			}
		}
		return implode( ' + ', $names );
	}
}
