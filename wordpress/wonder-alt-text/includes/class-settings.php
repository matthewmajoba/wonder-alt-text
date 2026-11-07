<?php
/**
 * Settings page and option access.
 *
 * @package Wonder_Alt_Text
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wonder_Alt_Settings {

	const OPTION = 'wonder_alt_settings';
	const PAGE   = 'wonder-alt-settings';

	/**
	 * Models offered in the dropdown. Any model ID can also be typed in.
	 *
	 * @return array<string,string>
	 */
	public static function models() {
		return apply_filters(
			'wonder_alt_models',
			array(
				'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (recommended balance of quality and cost)',
				'claude-haiku-5-5'  => 'Claude Haiku 5.5 (cheapest, fastest)',
				'claude-opus-5-5'   => 'Claude Opus 5.5 (highest quality)',
			)
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key'        => '',
			'model'          => 'claude-sonnet-5-5',
			'custom_model'   => '',
			'mode'           => 'auto',
			'persona'        => 'auto',
			'story_length'   => 'medium',
			'site_context'   => '',
			'review_mode'    => 1,
			'overwrite'      => 0,
			'auto_on_upload' => 0,
			'batch_size'     => 3,
		);
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Get one setting, or all settings.
	 *
	 * @param string|null $key Setting key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$opts = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( null === $key ) {
			return $opts;
		}
		return isset( $opts[ $key ] ) ? $opts[ $key ] : null;
	}

	/**
	 * API key. A constant in wp-config.php wins over the saved option.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'WONDER_ALT_API_KEY' ) && WONDER_ALT_API_KEY ) {
			return (string) WONDER_ALT_API_KEY;
		}
		return (string) self::get( 'api_key' );
	}

	public static function key_from_constant() {
		return defined( 'WONDER_ALT_API_KEY' ) && WONDER_ALT_API_KEY;
	}

	/**
	 * Model ID in use.
	 *
	 * @return string
	 */
	public static function model() {
		$custom = trim( (string) self::get( 'custom_model' ) );
		return '' !== $custom ? $custom : (string) self::get( 'model' );
	}

	public static function menu() {
		add_options_page(
			__( 'Wonder Alt-Text', 'wonder-alt-text' ),
			__( 'Wonder Alt-Text', 'wonder-alt-text' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting(
			'wonder_alt',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = (array) $input;
		$old   = self::get();
		$out   = self::defaults();

		// Keep the existing key if the field was left blank (it is never echoed back).
		$key            = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
		$out['api_key'] = '' !== $key ? $key : $old['api_key'];
		if ( ! empty( $input['clear_api_key'] ) ) {
			$out['api_key'] = '';
		}

		$out['model']        = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : $out['model'];
		$out['custom_model'] = isset( $input['custom_model'] ) ? sanitize_text_field( $input['custom_model'] ) : '';

		$mode        = isset( $input['mode'] ) ? $input['mode'] : 'auto';
		$out['mode'] = in_array( $mode, array( 'auto', 'plain', 'story' ), true ) ? $mode : 'auto';

		$persona        = isset( $input['persona'] ) ? $input['persona'] : 'auto';
		$out['persona'] = ( 'auto' === $persona || in_array( $persona, Wonder_Alt_Personas::slugs(), true ) ) ? $persona : 'auto';

		$len                 = isset( $input['story_length'] ) ? $input['story_length'] : 'medium';
		$out['story_length'] = in_array( $len, array( 'short', 'medium', 'long' ), true ) ? $len : 'medium';

		$out['site_context']   = isset( $input['site_context'] ) ? sanitize_textarea_field( $input['site_context'] ) : '';
		$out['review_mode']    = empty( $input['review_mode'] ) ? 0 : 1;
		$out['overwrite']      = empty( $input['overwrite'] ) ? 0 : 1;
		$out['auto_on_upload'] = empty( $input['auto_on_upload'] ) ? 0 : 1;
		$out['batch_size']     = isset( $input['batch_size'] ) ? max( 1, min( 10, absint( $input['batch_size'] ) ) ) : 3;

		return $out;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o       = self::get();
		$name    = self::OPTION;
		$has_key = '' !== self::api_key();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Wonder Alt-Text Settings', 'wonder-alt-text' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: link to the backfill page */
					esc_html__( 'Ready to fill in missing alt-text? Go to %s.', 'wonder-alt-text' ),
					'<a href="' . esc_url( admin_url( 'upload.php?page=' . Wonder_Alt_Admin::PAGE ) ) . '">' . esc_html__( 'Media → Wonder Alt-Text', 'wonder-alt-text' ) . '</a>'
				);
				?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'wonder_alt' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wat-api-key"><?php esc_html_e( 'Anthropic API key', 'wonder-alt-text' ); ?></label></th>
						<td>
							<?php if ( self::key_from_constant() ) : ?>
								<p><?php esc_html_e( 'Using the key defined as WONDER_ALT_API_KEY in wp-config.php.', 'wonder-alt-text' ); ?></p>
							<?php else : ?>
								<input type="password" id="wat-api-key" name="<?php echo esc_attr( $name ); ?>[api_key]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $has_key ? esc_attr__( 'Saved. Leave blank to keep it.', 'wonder-alt-text' ) : 'sk-ant-...'; ?>">
								<?php if ( $has_key ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_api_key]" value="1"> <?php esc_html_e( 'Remove saved key', 'wonder-alt-text' ); ?></label>
								<?php endif; ?>
								<p class="description">
									<?php esc_html_e( 'Get a key at console.anthropic.com. For better security, add define( \'WONDER_ALT_API_KEY\', \'your-key\' ); to wp-config.php instead.', 'wonder-alt-text' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wat-model"><?php esc_html_e( 'Model', 'wonder-alt-text' ); ?></label></th>
						<td>
							<select id="wat-model" name="<?php echo esc_attr( $name ); ?>[model]">
								<?php foreach ( self::models() as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $o['model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p><input type="text" name="<?php echo esc_attr( $name ); ?>[custom_model]" value="<?php echo esc_attr( $o['custom_model'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Optional: any other model ID', 'wonder-alt-text' ); ?>"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default style', 'wonder-alt-text' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="auto" <?php checked( $o['mode'], 'auto' ); ?>> <?php esc_html_e( 'Auto: plain for products, logos, icons and screenshots; story for scenic and emotional images', 'wonder-alt-text' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="plain" <?php checked( $o['mode'], 'plain' ); ?>> <?php esc_html_e( 'Always plain', 'wonder-alt-text' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="story" <?php checked( $o['mode'], 'story' ); ?>> <?php esc_html_e( 'Always story', 'wonder-alt-text' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wat-persona"><?php esc_html_e( 'Narrator', 'wonder-alt-text' ); ?></label></th>
						<td>
							<select id="wat-persona" name="<?php echo esc_attr( $name ); ?>[persona]">
								<option value="auto" <?php selected( $o['persona'], 'auto' ); ?>><?php esc_html_e( 'Auto: pick or blend the best fit for each image', 'wonder-alt-text' ); ?></option>
								<?php foreach ( Wonder_Alt_Personas::all() as $slug => $p ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $o['persona'], $slug ); ?>><?php echo esc_html( $p['name'] . ': ' . $p['tagline'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wat-length"><?php esc_html_e( 'Story length', 'wonder-alt-text' ); ?></label></th>
						<td>
							<select id="wat-length" name="<?php echo esc_attr( $name ); ?>[story_length]">
								<option value="short" <?php selected( $o['story_length'], 'short' ); ?>><?php esc_html_e( 'Short (1 sentence)', 'wonder-alt-text' ); ?></option>
								<option value="medium" <?php selected( $o['story_length'], 'medium' ); ?>><?php esc_html_e( 'Medium (2 sentences)', 'wonder-alt-text' ); ?></option>
								<option value="long" <?php selected( $o['story_length'], 'long' ); ?>><?php esc_html_e( 'Long (3 sentences)', 'wonder-alt-text' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wat-context"><?php esc_html_e( 'About this site', 'wonder-alt-text' ); ?></label></th>
						<td>
							<textarea id="wat-context" name="<?php echo esc_attr( $name ); ?>[site_context]" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'e.g. A family lodge on Mount Hood with ski runs, hiking trails and a historic stone lobby.', 'wonder-alt-text' ); ?>"><?php echo esc_textarea( $o['site_context'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional. Helps the AI name places and products correctly. It never invents details that are not in the image.', 'wonder-alt-text' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Workflow', 'wonder-alt-text' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[review_mode]" value="1" <?php checked( $o['review_mode'], 1 ); ?>> <?php esc_html_e( 'Review before saving (recommended). New alt-text waits in a review queue until you approve it.', 'wonder-alt-text' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[overwrite]" value="1" <?php checked( $o['overwrite'], 1 ); ?>> <?php esc_html_e( 'Also rewrite images that already have alt-text during backfill', 'wonder-alt-text' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auto_on_upload]" value="1" <?php checked( $o['auto_on_upload'], 1 ); ?>> <?php esc_html_e( 'Generate alt-text automatically when new images are uploaded', 'wonder-alt-text' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wat-batch"><?php esc_html_e( 'Images per batch', 'wonder-alt-text' ); ?></label></th>
						<td>
							<input type="number" id="wat-batch" min="1" max="10" name="<?php echo esc_attr( $name ); ?>[batch_size]" value="<?php echo esc_attr( $o['batch_size'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Lower this if your host times out during backfill.', 'wonder-alt-text' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
