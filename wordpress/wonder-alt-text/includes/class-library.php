<?php
/**
 * Media library integration: queries, saving, review queue, upload hook.
 *
 * @package Wonder_Alt_Text
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wonder_Alt_Library {

	const META_ALT        = '_wp_attachment_image_alt';
	const META_SUGGESTION = '_wonder_alt_suggestion';
	const META_INFO       = '_wonder_alt_info';
	const META_ERROR      = '_wonder_alt_error';
	const CRON_HOOK       = 'wonder_alt_generate_event';

	public static function init() {
		add_action( 'add_attachment', array( __CLASS__, 'on_upload' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_generate' ) );
	}

	/**
	 * Generate alt-text for one attachment and save it (or queue it for review).
	 *
	 * @param int   $id        Attachment ID.
	 * @param array $overrides mode / persona overrides.
	 * @param bool  $apply     Save directly even if review mode is on.
	 * @return array|WP_Error Result plus 'status' => applied|queued.
	 */
	public static function process( $id, array $overrides = array(), $apply = false ) {
		$result = Wonder_Alt_Generator::generate( $id, $overrides );
		if ( is_wp_error( $result ) ) {
			if ( self::is_fatal( $result ) ) {
				return $result; // A key, billing or network problem: not this image's fault, so do not mark it failed.
			}
			update_post_meta(
				$id,
				self::META_ERROR,
				array(
					'message' => $result->get_error_message(),
					'time'    => time(),
				)
			);
			return $result;
		}

		delete_post_meta( $id, self::META_ERROR );
		$result['time'] = time();

		if ( $apply || ! Wonder_Alt_Settings::get( 'review_mode' ) ) {
			self::apply( $id, $result );
			$result['status'] = 'applied';
		} else {
			update_post_meta( $id, self::META_SUGGESTION, wp_slash( $result ) );
			$result['status'] = 'queued';
		}
		return $result;
	}

	/**
	 * Errors that would hit every image (bad key, no credit, rate limit, network).
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	public static function is_fatal( WP_Error $error ) {
		$code = $error->get_error_code();
		if ( in_array( $code, array( 'wonder_alt_no_key', 'http_request_failed' ), true ) ) {
			return true;
		}
		if ( 'wonder_alt_api_error' === $code ) {
			$data   = $error->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			return 400 !== $status && 413 !== $status;
		}
		return false;
	}

	/**
	 * Write alt-text to the attachment.
	 *
	 * @param int   $id     Attachment ID.
	 * @param array $result Result with at least 'alt'.
	 */
	public static function apply( $id, array $result ) {
		update_post_meta( $id, self::META_ALT, wp_slash( sanitize_text_field( $result['alt'] ) ) );
		unset( $result['status'] );
		$result['applied'] = time();
		update_post_meta( $id, self::META_INFO, wp_slash( $result ) );
		delete_post_meta( $id, self::META_SUGGESTION );
		delete_post_meta( $id, self::META_ERROR );
	}

	/**
	 * Approve a queued suggestion, optionally with edited text.
	 *
	 * @param int         $id  Attachment ID.
	 * @param string|null $alt Edited alt-text.
	 * @return bool
	 */
	public static function approve( $id, $alt = null ) {
		$suggestion = get_post_meta( $id, self::META_SUGGESTION, true );
		if ( ! is_array( $suggestion ) ) {
			return false;
		}
		if ( null !== $alt && '' !== trim( $alt ) ) {
			if ( trim( $alt ) !== $suggestion['alt'] ) {
				$suggestion['edited'] = true;
			}
			$suggestion['alt'] = trim( $alt );
		}
		self::apply( $id, $suggestion );
		return true;
	}

	public static function reject( $id ) {
		delete_post_meta( $id, self::META_SUGGESTION );
		// Remember the rejection so backfill does not immediately regenerate it.
		update_post_meta(
			$id,
			self::META_ERROR,
			array(
				'message' => __( 'Suggestion rejected. Clear to retry.', 'wonder-alt-text' ),
				'time'    => time(),
			)
		);
	}

	/**
	 * Base query args for supported images.
	 *
	 * @return array
	 */
	private static function base_args() {
		return array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => Wonder_Alt_Generator::SUPPORTED,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
			'orderby'                => 'ID',
			'order'                  => 'DESC',
		);
	}

	/**
	 * Meta query for "missing alt-text".
	 *
	 * @return array
	 */
	private static function missing_alt_clause() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => self::META_ALT,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => self::META_ALT,
				'value' => '',
			),
		);
	}

	/**
	 * IDs that still need work (not queued, not failed).
	 *
	 * @param int  $limit     Max IDs.
	 * @param bool $overwrite Include images that already have alt-text (but were never written by this plugin).
	 * @return int[]
	 */
	public static function needs_work( $limit = 10, $overwrite = false ) {
		$meta = array(
			'relation' => 'AND',
			array(
				'key'     => self::META_SUGGESTION,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::META_ERROR,
				'compare' => 'NOT EXISTS',
			),
		);
		if ( $overwrite ) {
			$meta[] = array(
				'key'     => self::META_INFO,
				'compare' => 'NOT EXISTS',
			);
		} else {
			$meta[] = self::missing_alt_clause();
		}

		$q = new WP_Query(
			array_merge(
				self::base_args(),
				array(
					'posts_per_page' => max( 1, (int) $limit ),
					'no_found_rows'  => true,
					'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			)
		);
		return array_map( 'intval', $q->posts );
	}

	/**
	 * Count images matching a meta query.
	 *
	 * @param array|null $meta Meta query.
	 * @return int
	 */
	private static function count( $meta = null ) {
		$args = array_merge( self::base_args(), array( 'posts_per_page' => 1 ) );
		if ( $meta ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		$q = new WP_Query( $args );
		return (int) $q->found_posts;
	}

	/**
	 * Library stats for the dashboard.
	 *
	 * @return array{total:int, missing:int, todo:int, queued:int, errors:int, written:int}
	 */
	public static function stats() {
		$overwrite = (bool) Wonder_Alt_Settings::get( 'overwrite' );
		$todo_meta = array(
			'relation' => 'AND',
			array(
				'key'     => self::META_SUGGESTION,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::META_ERROR,
				'compare' => 'NOT EXISTS',
			),
			$overwrite ? array(
				'key'     => self::META_INFO,
				'compare' => 'NOT EXISTS',
			) : self::missing_alt_clause(),
		);
		return array(
			'total'   => self::count(),
			'missing' => self::count( self::missing_alt_clause() ),
			'todo'    => self::count( $todo_meta ),
			'queued'  => self::count(
				array(
					array(
						'key'     => self::META_SUGGESTION,
						'compare' => 'EXISTS',
					),
				)
			),
			'errors'  => self::count(
				array(
					array(
						'key'     => self::META_ERROR,
						'compare' => 'EXISTS',
					),
				)
			),
			'written' => self::count(
				array(
					array(
						'key'     => self::META_INFO,
						'compare' => 'EXISTS',
					),
				)
			),
		);
	}

	/**
	 * Attachments waiting for review.
	 *
	 * @param int $page     Page number.
	 * @param int $per_page Per page.
	 * @return array{ids:int[], total:int}
	 */
	public static function queued( $page = 1, $per_page = 30 ) {
		$q = new WP_Query(
			array_merge(
				self::base_args(),
				array(
					'posts_per_page' => $per_page,
					'paged'          => max( 1, (int) $page ),
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => self::META_SUGGESTION,
							'compare' => 'EXISTS',
						),
					),
				)
			)
		);
		return array(
			'ids'   => array_map( 'intval', $q->posts ),
			'total' => (int) $q->found_posts,
		);
	}

	/**
	 * Attachments that failed.
	 *
	 * @param int $limit Max.
	 * @return int[]
	 */
	public static function failed( $limit = 50 ) {
		$q = new WP_Query(
			array_merge(
				self::base_args(),
				array(
					'posts_per_page' => $limit,
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => self::META_ERROR,
							'compare' => 'EXISTS',
						),
					),
				)
			)
		);
		return array_map( 'intval', $q->posts );
	}

	public static function clear_errors() {
		delete_post_meta_by_key( self::META_ERROR );
	}

	/**
	 * Queue generation for new uploads.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function on_upload( $id ) {
		if ( ! Wonder_Alt_Settings::get( 'auto_on_upload' ) || '' === Wonder_Alt_Settings::api_key() ) {
			return;
		}
		if ( ! in_array( get_post_mime_type( $id ), Wonder_Alt_Generator::SUPPORTED, true ) ) {
			return;
		}
		wp_schedule_single_event( time() + 15, self::CRON_HOOK, array( (int) $id ) );
	}

	/**
	 * Cron callback for uploads.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function cron_generate( $id ) {
		$alt = get_post_meta( $id, self::META_ALT, true );
		if ( '' !== trim( (string) $alt ) ) {
			return; // Someone already wrote it.
		}
		self::process( (int) $id );
	}
}
