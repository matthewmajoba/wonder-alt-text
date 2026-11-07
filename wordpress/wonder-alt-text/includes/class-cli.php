<?php
/**
 * WP-CLI commands. Handy for very large libraries, where a terminal beats a browser tab.
 *
 * @package Wonder_Alt_Text
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write alt-text for your media library.
 */
class Wonder_Alt_CLI {

	/**
	 * Show how many images need alt-text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wonder-alt status
	 */
	public function status() {
		$s = Wonder_Alt_Library::stats();
		WP_CLI::log( sprintf( 'Images:              %d', $s['total'] ) );
		WP_CLI::log( sprintf( 'Missing alt-text:    %d', $s['missing'] ) );
		WP_CLI::log( sprintf( 'Ready to write:      %d', $s['todo'] ) );
		WP_CLI::log( sprintf( 'Waiting for review:  %d', $s['queued'] ) );
		WP_CLI::log( sprintf( 'Failed or rejected:  %d', $s['errors'] ) );
		WP_CLI::log( sprintf( 'Written by Wonder:   %d', $s['written'] ) );
	}

	/**
	 * Write alt-text for every image that needs it.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Stop after this many images.
	 *
	 * [--mode=<mode>]
	 * : auto, plain or story. Defaults to the site setting.
	 *
	 * [--persona=<slug>]
	 * : auto or a narrator slug. Defaults to the site setting.
	 *
	 * [--apply]
	 * : Save straight to the images, skipping the review queue.
	 *
	 * [--overwrite]
	 * : Also rewrite images that already have alt-text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wonder-alt backfill --limit=20
	 *     wp wonder-alt backfill --apply
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function backfill( $args, $assoc_args ) {
		$limit     = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;
		$apply     = ! empty( $assoc_args['apply'] );
		$overwrite = ! empty( $assoc_args['overwrite'] ) || Wonder_Alt_Settings::get( 'overwrite' );
		$overrides = $this->overrides( $assoc_args );

		$done   = 0;
		$failed = 0;
		while ( ! $limit || $done + $failed < $limit ) {
			$want = $limit ? min( 20, $limit - $done - $failed ) : 20;
			$ids  = Wonder_Alt_Library::needs_work( $want, $overwrite );
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				$result = Wonder_Alt_Library::process( $id, $overrides, $apply );
				if ( is_wp_error( $result ) ) {
					if ( Wonder_Alt_Library::is_fatal( $result ) ) {
						WP_CLI::error( $result->get_error_message() );
					}
					++$failed;
					WP_CLI::warning( sprintf( '#%d %s', $id, $result->get_error_message() ) );
					continue;
				}
				++$done;
				$voice = $result['personas'] ? ' [' . Wonder_Alt_Personas::names( $result['personas'] ) . ']' : '';
				WP_CLI::log( sprintf( '#%d %s%s (%s): %s', $id, $result['mode'], $voice, $result['status'], $result['alt'] ) );
			}
		}
		WP_CLI::success( sprintf( '%d written, %d failed.', $done, $failed ) );
	}

	/**
	 * Write alt-text for specific attachments.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Attachment IDs.
	 *
	 * [--mode=<mode>]
	 * : auto, plain or story.
	 *
	 * [--persona=<slug>]
	 * : auto or a narrator slug.
	 *
	 * [--apply]
	 * : Save straight to the image, skipping the review queue.
	 *
	 * @param array $args       IDs.
	 * @param array $assoc_args Options.
	 */
	public function generate( $args, $assoc_args ) {
		$overrides = $this->overrides( $assoc_args );
		foreach ( $args as $id ) {
			$result = Wonder_Alt_Library::process( absint( $id ), $overrides, ! empty( $assoc_args['apply'] ) );
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( sprintf( '#%d %s', $id, $result->get_error_message() ) );
				continue;
			}
			WP_CLI::log( sprintf( '#%d %s (%s): %s', $id, $result['mode'], $result['status'], $result['alt'] ) );
		}
	}

	/**
	 * Approve everything in the review queue.
	 *
	 * @subcommand approve-all
	 */
	public function approve_all() {
		$count = 0;
		do {
			$batch = Wonder_Alt_Library::queued( 1, 100 );
			foreach ( $batch['ids'] as $id ) {
				if ( Wonder_Alt_Library::approve( $id ) ) {
					++$count;
				} else {
					delete_post_meta( $id, Wonder_Alt_Library::META_SUGGESTION );
				}
			}
		} while ( $batch['ids'] );
		WP_CLI::success( sprintf( 'Approved %d.', $count ) );
	}

	/**
	 * Export images to a CSV the standalone wonder-alt tool can read.
	 *
	 * Use this to run the batch tool (half the cost) on huge libraries, then bring results back with `import`.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : CSV file to write.
	 *
	 * [--all]
	 * : Include images that already have alt-text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wonder-alt export images.csv
	 *     wonder-alt run images.csv --out results.csv --batch
	 *     wp wonder-alt import results.csv
	 *
	 * @param array $args       File.
	 * @param array $assoc_args Options.
	 */
	public function export( $args, $assoc_args ) {
		$fh = fopen( $args[0], 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			WP_CLI::error( 'Could not open ' . $args[0] );
		}
		fputcsv( $fh, array( 'id', 'url', 'alt', 'title', 'caption', 'description', 'used_on' ) );
		$paged = 1;
		$rows  = 0;
		do {
			$q = new WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => Wonder_Alt_Generator::SUPPORTED,
					'posts_per_page' => 500,
					'paged'          => $paged++,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			foreach ( $q->posts as $post ) {
				$alt = (string) get_post_meta( $post->ID, Wonder_Alt_Library::META_ALT, true );
				if ( '' !== trim( $alt ) && empty( $assoc_args['all'] ) ) {
					continue;
				}
				$parent = $post->post_parent ? get_post( $post->post_parent ) : null;
				fputcsv(
					$fh,
					array(
						$post->ID,
						wp_get_attachment_url( $post->ID ),
						$alt,
						$post->post_title,
						wp_strip_all_tags( $post->post_excerpt ),
						wp_trim_words( wp_strip_all_tags( $post->post_content ), 60 ),
						$parent ? get_the_title( $parent ) : '',
					)
				);
				++$rows;
			}
		} while ( $q->max_num_pages >= $paged );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		WP_CLI::success( sprintf( 'Wrote %d image(s) to %s', $rows, $args[0] ) );
	}

	/**
	 * Import results from the standalone wonder-alt tool.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Results CSV with id and alt_text columns.
	 *
	 * [--apply]
	 * : Save straight to the images, skipping the review queue.
	 *
	 * @param array $args       File.
	 * @param array $assoc_args Options.
	 */
	public function import( $args, $assoc_args ) {
		$fh = fopen( $args[0], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			WP_CLI::error( 'Could not open ' . $args[0] );
		}
		$head  = array_map( 'strtolower', (array) fgetcsv( $fh ) );
		$apply = ! empty( $assoc_args['apply'] ) || ! Wonder_Alt_Settings::get( 'review_mode' );
		$n     = 0;
		while ( ( $line = fgetcsv( $fh ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( count( $line ) !== count( $head ) ) {
				continue;
			}
			$row = array_combine( $head, $line );
			$id  = absint( $row['id'] ?? 0 );
			if ( ! $id || empty( $row['alt_text'] ) || ! empty( $row['error'] ) || 'attachment' !== get_post_type( $id ) ) {
				continue;
			}
			$result = array(
				'alt'        => Wonder_Alt_Generator::clean( $row['alt_text'] ),
				'mode'       => ( $row['mode'] ?? '' ) === 'story' ? 'story' : 'plain',
				'personas'   => array_values( array_intersect( explode( '+', (string) ( $row['personas'] ?? '' ) ), Wonder_Alt_Personas::slugs() ) ),
				'image_type' => $row['image_type'] ?? 'other',
				'model'      => $row['model'] ?? '',
				'time'       => time(),
			);
			if ( $apply ) {
				Wonder_Alt_Library::apply( $id, $result );
			} else {
				update_post_meta( $id, Wonder_Alt_Library::META_SUGGESTION, wp_slash( $result ) );
			}
			++$n;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		WP_CLI::success( sprintf( '%s %d image(s).', $apply ? 'Saved' : 'Queued for review', $n ) );
	}

	private function overrides( $assoc_args ) {
		$o = array();
		if ( isset( $assoc_args['mode'] ) ) {
			$o['mode'] = $assoc_args['mode'];
		}
		if ( isset( $assoc_args['persona'] ) ) {
			$o['persona'] = $assoc_args['persona'];
		}
		return $o;
	}
}
