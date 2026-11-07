<?php
/**
 * Turns one image into alt-text.
 *
 * @package Wonder_Alt_Text
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wonder_Alt_Generator {

	const MAX_EDGE  = 1568;
	const MAX_BYTES = 4500000;
	const SUPPORTED = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * Generate alt-text for an attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $overrides     Optional 'mode' (auto|plain|story) and 'persona' (auto|slug).
	 * @return array|WP_Error { alt, mode, personas, image_type, model }
	 */
	public static function generate( $attachment_id, array $overrides = array() ) {
		$attachment_id = absint( $attachment_id );
		$post          = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'wonder_alt_not_found', __( 'Attachment not found.', 'wonder-alt-text' ) );
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::SUPPORTED, true ) ) {
			return new WP_Error( 'wonder_alt_unsupported', sprintf( /* translators: %s: mime type */ __( 'Unsupported image type: %s', 'wonder-alt-text' ), $mime ) );
		}

		$image = self::image_block( $attachment_id, $mime );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		$mode    = isset( $overrides['mode'] ) ? $overrides['mode'] : Wonder_Alt_Settings::get( 'mode' );
		$persona = isset( $overrides['persona'] ) ? $overrides['persona'] : Wonder_Alt_Settings::get( 'persona' );
		$mode    = in_array( $mode, array( 'auto', 'plain', 'story' ), true ) ? $mode : 'auto';
		$persona = ( 'auto' === $persona || in_array( $persona, Wonder_Alt_Personas::slugs(), true ) ) ? $persona : 'auto';

		$model = Wonder_Alt_Settings::model();
		$body  = array(
			'model'       => $model,
			'max_tokens'  => 1024,
			'system'      => self::system_prompt( $mode, $persona ),
			'tools'       => array( self::tool() ),
			'tool_choice' => array( 'type' => 'auto' ),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						$image,
						array(
							'type' => 'text',
							'text' => self::context_text( $post ),
						),
					),
				),
			),
		);

		/**
		 * Filter the API request body before it is sent.
		 *
		 * @param array $body          Request body.
		 * @param int   $attachment_id Attachment ID.
		 */
		$body = apply_filters( 'wonder_alt_request_body', $body, $attachment_id );

		$response = Wonder_Alt_API_Client::messages( $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = self::parse( $response, $mode, $persona );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['model'] = $model;

		/**
		 * Filter the final result.
		 *
		 * @param array $result        { alt, mode, personas, image_type, model }.
		 * @param int   $attachment_id Attachment ID.
		 */
		return apply_filters( 'wonder_alt_result', $result, $attachment_id );
	}

	/**
	 * Tool definition that forces a structured answer.
	 *
	 * @return array
	 */
	public static function tool() {
		$tool = Wonder_Alt_Personas::spec()['tool'];
		$tool['input_schema']['properties']['personas']['items']['enum'] = Wonder_Alt_Personas::slugs();
		return $tool;
	}

	/**
	 * Build the system prompt.
	 *
	 * @param string $mode    auto|plain|story.
	 * @param string $persona auto|slug.
	 * @return string
	 */
	public static function system_prompt( $mode, $persona ) {
		$spec  = Wonder_Alt_Personas::spec();
		$parts = $spec['parts'];
		$all   = Wonder_Alt_Personas::all();

		$mode    = isset( $parts['mode_instruction'][ $mode ] ) ? $mode : 'auto';
		$length  = (string) Wonder_Alt_Settings::get( 'story_length' );
		$length  = isset( $parts['story_length'][ $length ] ) ? $length : 'medium';
		$persona = isset( $all[ $persona ] ) ? $persona : 'auto';

		$lines = array();
		foreach ( $all as $p ) {
			$lines[] = self::fill( $parts['narrator_line'], $p );
		}

		$narrator = 'auto' === $persona
			? $parts['narrator_instruction']['auto']
			: self::fill( $parts['narrator_instruction']['fixed'], array( 'persona' => $persona ) );

		$locale   = (string) get_locale();
		$language = ( '' === $locale || 0 === strpos( $locale, 'en' ) ) ? '' : self::fill( $parts['language_instruction'], array( 'language' => $locale ) );

		$prompt = self::fill(
			$spec['template'],
			array(
				'story_length'         => $parts['story_length'][ $length ],
				'mode_instruction'     => $parts['mode_instruction'][ $mode ],
				'narrator_list'        => implode( "\n", $lines ),
				'narrator_instruction' => $narrator,
				'language_instruction' => $language,
			)
		);
		$prompt = trim( preg_replace( "/\n{3,}/", "\n\n", $prompt ) );

		return apply_filters( 'wonder_alt_system_prompt', $prompt, $mode, $persona );
	}

	/**
	 * Replace {{key}} placeholders.
	 *
	 * @param string $text   Template.
	 * @param array  $values Values.
	 * @return string
	 */
	private static function fill( $text, array $values ) {
		foreach ( $values as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$text = str_replace( '{{' . $key . '}}', (string) $value, $text );
			}
		}
		return $text;
	}

	/**
	 * Page context sent alongside the image.
	 *
	 * @param WP_Post $post Attachment post.
	 * @return string
	 */
	public static function context_text( $post ) {
		$lines   = array( Wonder_Alt_Personas::spec()['parts']['user_message_header'] );
		$lines[] = '- Website: ' . get_bloginfo( 'name' );

		$site = trim( (string) Wonder_Alt_Settings::get( 'site_context' ) );
		if ( '' !== $site ) {
			$lines[] = '- About the site: ' . $site;
		}

		$file = get_attached_file( $post->ID );
		if ( $file ) {
			$lines[] = '- File name: ' . wp_basename( $file );
		}
		if ( $post->post_title ) {
			$lines[] = '- Media title: ' . $post->post_title;
		}
		if ( $post->post_excerpt ) {
			$lines[] = '- Caption: ' . wp_strip_all_tags( $post->post_excerpt );
		}
		if ( $post->post_content ) {
			$lines[] = '- Description: ' . wp_trim_words( wp_strip_all_tags( $post->post_content ), 60 );
		}
		if ( $post->post_parent ) {
			$parent = get_post( $post->post_parent );
			if ( $parent ) {
				$lines[] = '- Used on: ' . get_the_title( $parent ) . ' (' . $parent->post_type . ')';
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build the image content block, resizing large files.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $mime          Mime type.
	 * @return array|WP_Error
	 */
	public static function image_block( $attachment_id, $mime ) {
		$path = get_attached_file( $attachment_id );

		// Offloaded media (e.g. S3/CDN) with no local file: send the public URL instead.
		if ( ! $path || ! file_exists( $path ) ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'large' );
			if ( ! $url ) {
				$url = wp_get_attachment_url( $attachment_id );
			}
			if ( ! $url ) {
				return new WP_Error( 'wonder_alt_no_file', __( 'Image file could not be found.', 'wonder-alt-text' ) );
			}
			return array(
				'type'   => 'image',
				'source' => array(
					'type' => 'url',
					'url'  => $url,
				),
			);
		}

		$send_path = $path;
		$send_mime = $mime;
		$tmp       = '';

		$size = getimagesize( $path );
		$big  = $size && ( $size[0] > self::MAX_EDGE || $size[1] > self::MAX_EDGE );
		if ( $big || filesize( $path ) > self::MAX_BYTES ) {
			$editor = wp_get_image_editor( $path );
			if ( ! is_wp_error( $editor ) ) {
				$editor->resize( self::MAX_EDGE, self::MAX_EDGE, false );
				$out_mime = ( 'image/gif' === $mime ) ? 'image/png' : $mime;
				$ext      = array(
					'image/jpeg' => 'jpg',
					'image/png'  => 'png',
					'image/webp' => 'webp',
				);
				$tmp      = wp_tempnam( 'wonder-alt' ) . '.' . $ext[ $out_mime ];
				$saved    = $editor->save( $tmp, $out_mime );
				if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ) {
					$send_path = $saved['path'];
					$send_mime = $out_mime;
				}
			}
		}

		$bytes = file_get_contents( $send_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $tmp ) {
			wp_delete_file( $tmp );
			if ( $send_path !== $tmp ) {
				wp_delete_file( $send_path );
			}
		}
		if ( false === $bytes ) {
			return new WP_Error( 'wonder_alt_read_failed', __( 'Could not read the image file.', 'wonder-alt-text' ) );
		}

		return array(
			'type'   => 'image',
			'source' => array(
				'type'       => 'base64',
				'media_type' => $send_mime,
				'data'       => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
		);
	}

	/**
	 * Pull the tool call out of the API response and clean it up.
	 *
	 * @param array  $response Decoded API response.
	 * @param string $mode     Requested mode.
	 * @param string $persona  Requested persona.
	 * @return array|WP_Error
	 */
	public static function parse( array $response, $mode = 'auto', $persona = 'auto' ) {
		$input = null;
		foreach ( (array) ( $response['content'] ?? array() ) as $block ) {
			if ( isset( $block['type'], $block['name'] ) && 'tool_use' === $block['type'] && 'save_alt_text' === $block['name'] ) {
				$input = (array) $block['input'];
				break;
			}
		}

		if ( ! $input || empty( $input['alt_text'] ) ) {
			return new WP_Error( 'wonder_alt_empty', __( 'The model did not return any alt-text.', 'wonder-alt-text' ) );
		}

		$alt = self::clean( (string) $input['alt_text'] );
		if ( '' === $alt ) {
			return new WP_Error( 'wonder_alt_empty', __( 'The model did not return any alt-text.', 'wonder-alt-text' ) );
		}

		$out_mode = ( isset( $input['mode'] ) && 'story' === $input['mode'] ) ? 'story' : 'plain';
		if ( 'plain' === $mode ) {
			$out_mode = 'plain';
		}

		$personas = array();
		if ( 'story' === $out_mode ) {
			$valid    = Wonder_Alt_Personas::slugs();
			$personas = array_values( array_unique( array_intersect( (array) ( $input['personas'] ?? array() ), $valid ) ) );
			$personas = array_slice( $personas, 0, 2 );
			if ( 'auto' !== $persona && in_array( $persona, $valid, true ) ) {
				$personas = array( $persona );
			}
		}

		$type = ( isset( $input['image_type'] ) && in_array( $input['image_type'], (array) Wonder_Alt_Personas::spec()['parts']['image_types'], true ) ) ? $input['image_type'] : 'other';

		return array(
			'alt'        => $alt,
			'mode'       => $out_mode,
			'personas'   => $personas,
			'image_type' => $type,
		);
	}

	/**
	 * Normalise alt-text.
	 *
	 * @param string $alt Raw text.
	 * @return string
	 */
	public static function clean( $alt ) {
		$alt = html_entity_decode( wp_strip_all_tags( $alt ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$alt = preg_replace( '/\s+/u', ' ', $alt );
		$alt   = trim( $alt );
		$pairs = array(
			'"' => '"',
			"'" => "'",
			'“' => '”',
			'‘' => '’',
		);
		// Remove quotes only when they wrap the whole text, so "Sign reading 'OPEN'" stays intact.
		while ( mb_strlen( $alt ) >= 2 && isset( $pairs[ mb_substr( $alt, 0, 1 ) ] ) && $pairs[ mb_substr( $alt, 0, 1 ) ] === mb_substr( $alt, -1 ) ) {
			$alt = trim( mb_substr( $alt, 1, -1 ) );
		}
		$alt = preg_replace( '/^(an?\s+)?(image|picture|photo|photograph)\s+(of|showing)\s+/iu', '', $alt );
		if ( function_exists( 'mb_strtoupper' ) && '' !== $alt ) {
			$alt = mb_strtoupper( mb_substr( $alt, 0, 1 ) ) . mb_substr( $alt, 1 );
		}
		return trim( $alt );
	}
}
