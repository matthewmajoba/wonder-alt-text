<?php
/**
 * Admin screens: backfill dashboard, review queue, media modal button, AJAX.
 *
 * @package Wonder_Alt_Text
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wonder_Alt_Admin {

	const PAGE  = 'wonder-alt-text';
	const NONCE = 'wonder_alt';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'attachment_field' ), 10, 2 );
		add_filter( 'media_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( WONDER_ALT_FILE ), array( __CLASS__, 'plugin_links' ) );

		foreach ( array( 'batch', 'generate', 'review', 'approve_all', 'clear_errors' ) as $action ) {
			add_action( 'wp_ajax_wonder_alt_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	public static function menu() {
		add_media_page(
			__( 'Wonder Alt-Text', 'wonder-alt-text' ),
			__( 'Wonder Alt-Text', 'wonder-alt-text' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function plugin_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'upload.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Backfill', 'wonder-alt-text' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'options-general.php?page=' . Wonder_Alt_Settings::PAGE ) ) . '">' . esc_html__( 'Settings', 'wonder-alt-text' ) . '</a>'
		);
		return $links;
	}

	public static function assets( $hook ) {
		$media_screens = array( 'upload.php', 'post.php', 'post-new.php', 'media_page_' . self::PAGE );
		if ( ! in_array( $hook, $media_screens, true ) ) {
			return;
		}
		wp_enqueue_style( 'wonder-alt', WONDER_ALT_URL . 'assets/admin.css', array(), WONDER_ALT_VERSION );
		wp_enqueue_script( 'wonder-alt', WONDER_ALT_URL . 'assets/admin.js', array( 'jquery' ), WONDER_ALT_VERSION, true );
		wp_localize_script(
			'wonder-alt',
			'WonderAlt',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'batchSize' => (int) Wonder_Alt_Settings::get( 'batch_size' ),
				'i18n'      => array(
					'working'   => __( 'Writing…', 'wonder-alt-text' ),
					'generate'  => __( 'Write alt-text', 'wonder-alt-text' ),
					'done'      => __( 'Done. Every image that needed alt-text has been processed.', 'wonder-alt-text' ),
					'stopped'   => __( 'Stopped.', 'wonder-alt-text' ),
					'remaining' => __( 'remaining', 'wonder-alt-text' ),
					'failed'    => __( 'Failed', 'wonder-alt-text' ),
					'queued'    => __( 'Waiting for review', 'wonder-alt-text' ),
					'applied'   => __( 'Saved', 'wonder-alt-text' ),
					'confirm'   => __( 'Approve every suggestion in the review queue?', 'wonder-alt-text' ),
					'review'    => __( 'Review it, then save.', 'wonder-alt-text' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------- *
	 * Dashboard page
	 * ---------------------------------------------------------------- */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$stats   = Wonder_Alt_Library::stats();
		$has_key = '' !== Wonder_Alt_Settings::api_key();
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$queue   = Wonder_Alt_Library::queued( $page );
		$review  = (bool) Wonder_Alt_Settings::get( 'review_mode' );
		?>
		<div class="wrap wonder-alt">
			<h1><?php esc_html_e( 'Wonder Alt-Text', 'wonder-alt-text' ); ?></h1>
			<p class="wonder-alt-lede"><?php esc_html_e( 'Find every image missing alt-text and fill it in: plain and accurate for products and icons, vivid and true for the scenes people come for.', 'wonder-alt-text' ); ?></p>

			<?php if ( ! $has_key ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: %s: settings link */
						esc_html__( 'Add your Anthropic API key in %s to get started.', 'wonder-alt-text' ),
						'<a href="' . esc_url( admin_url( 'options-general.php?page=' . Wonder_Alt_Settings::PAGE ) ) . '">' . esc_html__( 'Settings → Wonder Alt-Text', 'wonder-alt-text' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<ul class="wonder-alt-stats">
				<li><strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong><span><?php esc_html_e( 'images', 'wonder-alt-text' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( $stats['missing'] ) ); ?></strong><span><?php esc_html_e( 'missing alt-text', 'wonder-alt-text' ); ?></span></li>
				<li><strong id="wonder-alt-todo"><?php echo esc_html( number_format_i18n( $stats['todo'] ) ); ?></strong><span><?php esc_html_e( 'ready to write', 'wonder-alt-text' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( $stats['queued'] ) ); ?></strong><span><?php esc_html_e( 'waiting for review', 'wonder-alt-text' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( $stats['written'] ) ); ?></strong><span><?php esc_html_e( 'written by Wonder', 'wonder-alt-text' ); ?></span></li>
			</ul>

			<h2><?php esc_html_e( 'Backfill', 'wonder-alt-text' ); ?></h2>
			<p>
				<?php
				echo $review
					? esc_html__( 'Review mode is on: new alt-text goes to the review queue below before it is saved.', 'wonder-alt-text' )
					: esc_html__( 'Review mode is off: new alt-text is saved straight to your images.', 'wonder-alt-text' );
				?>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . Wonder_Alt_Settings::PAGE ) ); ?>"><?php esc_html_e( 'Change settings', 'wonder-alt-text' ); ?></a>
			</p>
			<p>
				<button type="button" class="button button-primary" id="wonder-alt-start" <?php disabled( ! $has_key || 0 === $stats['todo'] ); ?>><?php esc_html_e( 'Start backfill', 'wonder-alt-text' ); ?></button>
				<button type="button" class="button" id="wonder-alt-stop" hidden><?php esc_html_e( 'Stop', 'wonder-alt-text' ); ?></button>
				<?php if ( $stats['errors'] ) : ?>
					<button type="button" class="button-link" id="wonder-alt-clear-errors">
						<?php
						/* translators: %s: number of images */
						printf( esc_html__( 'Retry %s failed or rejected image(s)', 'wonder-alt-text' ), esc_html( number_format_i18n( $stats['errors'] ) ) );
						?>
					</button>
				<?php endif; ?>
			</p>
			<div id="wonder-alt-progress" class="wonder-alt-progress" aria-live="polite"></div>
			<ol id="wonder-alt-log" class="wonder-alt-log"></ol>

			<h2>
				<?php esc_html_e( 'Review queue', 'wonder-alt-text' ); ?>
				<span class="count">(<?php echo esc_html( number_format_i18n( $queue['total'] ) ); ?>)</span>
			</h2>
			<?php if ( ! $queue['total'] ) : ?>
				<p><?php esc_html_e( 'Nothing waiting for review.', 'wonder-alt-text' ); ?></p>
			<?php else : ?>
				<p><button type="button" class="button" id="wonder-alt-approve-all"><?php esc_html_e( 'Approve all', 'wonder-alt-text' ); ?></button></p>
				<table class="widefat striped wonder-alt-queue">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Image', 'wonder-alt-text' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Suggested alt-text', 'wonder-alt-text' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'wonder-alt-text' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $queue['ids'] as $id ) : ?>
						<?php self::queue_row( $id ); ?>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php
				$pages = (int) ceil( $queue['total'] / 30 );
				if ( $pages > 1 ) {
					echo '<div class="tablenav"><div class="tablenav-pages">';
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $page,
								'total'   => $pages,
							)
						)
					);
					echo '</div></div>';
				}
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One review-queue row.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function queue_row( $id ) {
		$s = get_post_meta( $id, Wonder_Alt_Library::META_SUGGESTION, true );
		if ( ! is_array( $s ) ) {
			return;
		}
		$voice = Wonder_Alt_Personas::names( $s['personas'] ?? array() );
		?>
		<tr data-id="<?php echo esc_attr( $id ); ?>">
			<td class="wonder-alt-thumb">
				<a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo wp_get_attachment_image( $id, 'thumbnail', false, array( 'alt' => '' ) ); ?></a>
			</td>
			<td>
				<label class="screen-reader-text" for="wonder-alt-text-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Suggested alt-text', 'wonder-alt-text' ); ?></label>
				<textarea id="wonder-alt-text-<?php echo esc_attr( $id ); ?>" rows="3" class="large-text"><?php echo esc_textarea( $s['alt'] ); ?></textarea>
				<p class="description">
					<?php echo esc_html( ucfirst( $s['mode'] ) ); ?>
					<?php if ( $voice ) : ?>
						· <?php echo esc_html( $voice ); ?>
					<?php endif; ?>
					· <?php echo esc_html( get_the_title( $id ) ); ?>
				</p>
			</td>
			<td class="wonder-alt-actions">
				<button type="button" class="button button-primary" data-review="approve"><?php esc_html_e( 'Approve', 'wonder-alt-text' ); ?></button>
				<button type="button" class="button" data-review="regenerate"><?php esc_html_e( 'Rewrite', 'wonder-alt-text' ); ?></button>
				<button type="button" class="button-link button-link-delete" data-review="reject"><?php esc_html_e( 'Reject', 'wonder-alt-text' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/* ---------------------------------------------------------------- *
	 * Media modal / edit screen
	 * ---------------------------------------------------------------- */

	public static function attachment_field( $fields, $post ) {
		if ( ! in_array( get_post_mime_type( $post ), Wonder_Alt_Generator::SUPPORTED, true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}
		$options = '<option value="auto">' . esc_html__( 'Auto narrator', 'wonder-alt-text' ) . '</option>';
		foreach ( Wonder_Alt_Personas::all() as $slug => $p ) {
			$options .= '<option value="' . esc_attr( $slug ) . '">' . esc_html( $p['name'] ) . '</option>';
		}
		$html  = '<div class="wonder-alt-field" id="wonder-alt" data-id="' . esc_attr( $post->ID ) . '">';
		$html .= '<select class="wonder-alt-mode" aria-label="' . esc_attr__( 'Style', 'wonder-alt-text' ) . '"><option value="auto">' . esc_html__( 'Auto style', 'wonder-alt-text' ) . '</option><option value="plain">' . esc_html__( 'Plain', 'wonder-alt-text' ) . '</option><option value="story">' . esc_html__( 'Story', 'wonder-alt-text' ) . '</option></select> ';
		$html .= '<select class="wonder-alt-persona" aria-label="' . esc_attr__( 'Narrator', 'wonder-alt-text' ) . '">' . $options . '</select> ';
		$html .= '<button type="button" class="button wonder-alt-generate">' . esc_html__( 'Write alt-text', 'wonder-alt-text' ) . '</button>';
		$html .= '<p class="wonder-alt-status" aria-live="polite"></p></div>';

		$fields['wonder_alt'] = array(
			'label' => __( 'Wonder Alt-Text', 'wonder-alt-text' ),
			'input' => 'html',
			'html'  => $html,
		);
		return $fields;
	}

	public static function row_action( $actions, $post ) {
		if ( in_array( get_post_mime_type( $post ), Wonder_Alt_Generator::SUPPORTED, true ) && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['wonder_alt'] = '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '#wonder-alt">' . esc_html__( 'Write alt-text', 'wonder-alt-text' ) . '</a>';
		}
		return $actions;
	}

	/* ---------------------------------------------------------------- *
	 * AJAX
	 * ---------------------------------------------------------------- */

	private static function check( $cap = 'manage_options', $post_id = 0 ) {
		check_ajax_referer( self::NONCE, 'nonce' );
		$ok = $post_id ? current_user_can( $cap, $post_id ) : current_user_can( $cap );
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'wonder-alt-text' ) ), 403 );
		}
	}

	private static function result_payload( $id, $result ) {
		if ( is_wp_error( $result ) ) {
			return array(
				'id'     => $id,
				'title'  => get_the_title( $id ),
				'error'  => $result->get_error_message(),
				'status' => 'failed',
			);
		}
		return array(
			'id'       => $id,
			'title'    => get_the_title( $id ),
			'thumb'    => wp_get_attachment_image_url( $id, 'thumbnail' ),
			'alt'      => $result['alt'],
			'mode'     => $result['mode'],
			'personas' => Wonder_Alt_Personas::names( $result['personas'] ),
			'status'   => $result['status'] ?? 'applied',
		);
	}

	/** Process the next few images. Called in a loop by the dashboard. */
	public static function ajax_batch() {
		self::check();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$overwrite = (bool) Wonder_Alt_Settings::get( 'overwrite' );
		$ids       = Wonder_Alt_Library::needs_work( (int) Wonder_Alt_Settings::get( 'batch_size' ), $overwrite );
		$results   = array();
		foreach ( $ids as $id ) {
			$result = Wonder_Alt_Library::process( $id );
			if ( is_wp_error( $result ) && Wonder_Alt_Library::is_fatal( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$results[] = self::result_payload( $id, $result );
		}
		$stats = Wonder_Alt_Library::stats();
		wp_send_json_success(
			array(
				'results'   => $results,
				'remaining' => $stats['todo'],
				'done'      => 0 === $stats['todo'] || empty( $ids ),
			)
		);
	}

	/** Generate for one image from the media modal. Returns text for the user to review; does not save. */
	public static function ajax_generate() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		self::check( 'edit_post', $id );
		$overrides = array(
			'mode'    => isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'auto',
			'persona' => isset( $_POST['persona'] ) ? sanitize_key( wp_unslash( $_POST['persona'] ) ) : 'auto',
		);
		$result = Wonder_Alt_Generator::generate( $id, $overrides );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$result['status'] = 'draft';
		wp_send_json_success( self::result_payload( $id, $result ) );
	}

	/** Approve, reject or rewrite a queued suggestion. */
	public static function ajax_review() {
		self::check();
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		if ( 'approve' === $task ) {
			$alt = isset( $_POST['alt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['alt'] ) ) : null;
			Wonder_Alt_Library::approve( $id, $alt ) ? wp_send_json_success() : wp_send_json_error( array( 'message' => __( 'Nothing to approve.', 'wonder-alt-text' ) ) );
		} elseif ( 'reject' === $task ) {
			Wonder_Alt_Library::reject( $id );
			wp_send_json_success();
		} elseif ( 'regenerate' === $task ) {
			delete_post_meta( $id, Wonder_Alt_Library::META_SUGGESTION );
			$result = Wonder_Alt_Library::process( $id );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			wp_send_json_success( self::result_payload( $id, $result ) );
		}
		wp_send_json_error( array( 'message' => 'Unknown task' ), 400 );
	}

	public static function ajax_approve_all() {
		self::check();
		$count = 0;
		do {
			$batch = Wonder_Alt_Library::queued( 1, 100 );
			foreach ( $batch['ids'] as $id ) {
				if ( Wonder_Alt_Library::approve( $id ) ) {
					++$count;
				} else {
					delete_post_meta( $id, Wonder_Alt_Library::META_SUGGESTION ); // Corrupt entry; drop it so the loop ends.
				}
			}
		} while ( ! empty( $batch['ids'] ) );
		wp_send_json_success( array( 'approved' => $count ) );
	}

	public static function ajax_clear_errors() {
		self::check();
		Wonder_Alt_Library::clear_errors();
		wp_send_json_success();
	}
}
