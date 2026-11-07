<?php
/**
 * Remove plugin settings and bookkeeping on uninstall. Alt-text already saved to images is kept.
 *
 * @package Wonder_Alt_Text
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wonder_alt_settings' );
foreach ( array( '_wonder_alt_suggestion', '_wonder_alt_info', '_wonder_alt_error' ) as $key ) {
	delete_post_meta_by_key( $key );
}
wp_clear_scheduled_hook( 'wonder_alt_generate_event' );
