<?php
/**
 * Minimal client for the Claude Messages API.
 *
 * @package Wonder_Alt_Text
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wonder_Alt_API_Client {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	const VERSION  = '2023-06-01';

	/**
	 * Send a Messages API request.
	 *
	 * @param array $body Request body (model, messages, etc.).
	 * @return array|WP_Error Decoded response body.
	 */
	public static function messages( array $body ) {
		$key = Wonder_Alt_Settings::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'wonder_alt_no_key', __( 'Add your Anthropic API key in Settings → Wonder Alt-Text.', 'wonder-alt-text' ) );
		}

		$args = array(
			'timeout' => 90,
			'headers' => array(
				'x-api-key'         => $key,
				'anthropic-version' => self::VERSION,
				'content-type'      => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		);

		$attempts = 0;
		do {
			++$attempts;
			$response = wp_remote_post( self::ENDPOINT, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			// Retry once on rate limits, overload and server errors.
			if ( $attempts < 3 && ( 429 === $code || 529 === $code || $code >= 500 ) ) {
				$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				sleep( max( 2, min( 20, $wait ? $wait : 2 * $attempts ) ) );
				continue;
			}
			break;
		} while ( true );

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : sprintf( 'HTTP %d', $code );
			return new WP_Error( 'wonder_alt_api_error', sprintf( /* translators: %s: API error message */ __( 'Claude API error: %s', 'wonder-alt-text' ), $msg ), array( 'status' => $code ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wonder_alt_bad_response', __( 'The Claude API returned an unreadable response.', 'wonder-alt-text' ) );
		}

		return $data;
	}
}
