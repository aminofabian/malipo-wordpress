<?php
/**
 * REST routes: signature-verified webhook, shortcode create, and status polling.
 *
 * @package Malipo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers `malipo/v1` routes.
 */
class Malipo_REST {

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register the routes.
	 */
	public static function routes() {
		register_rest_route(
			'malipo/v1',
			'/pay',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'pay' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'malipo/v1',
			'/status/(?P<token>[a-f0-9]{32})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			'malipo/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Create a payment from the [malipo_pay] form.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function pay( WP_REST_Request $request ) {
		$nonce = (string) $request->get_param( 'nonce' );
		if ( ! wp_verify_nonce( $nonce, 'malipo_pay' ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'bad_nonce',
					'message' => __( 'Your session expired. Reload the page and try again.', 'malipo-payments' ),
				),
				403
			);
		}

		$result = Malipo_Payments::create(
			array(
				'amount'    => (string) $request->get_param( 'amount' ),
				'phone'     => (string) $request->get_param( 'phone' ),
				'reference' => (string) $request->get_param( 'reference' ),
				'source'    => 'shortcode',
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'error'   => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'id'     => $result['id'],
				'token'  => $result['token'],
				'status' => $result['status'],
			),
			201
		);
	}

	/**
	 * Poll a payment's status by its token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function status( WP_REST_Request $request ) {
		$post_id = Malipo_Payments::find_by_token( (string) $request->get_param( 'token' ) );
		if ( ! $post_id ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$record = Malipo_Payments::refresh( $post_id );
		if ( is_wp_error( $record ) ) {
			return new WP_REST_Response(
				array(
					'error'   => $record->get_error_code(),
					'message' => $record->get_error_message(),
				),
				502
			);
		}

		return new WP_REST_Response(
			array(
				'status'          => $record['status'],
				'receipt'         => $record['receipt'],
				'failure_kind'    => $record['failure_kind'],
				'failure_message' => $record['failure_message'],
			),
			200
		);
	}

	/**
	 * Receive a Malipo callback: verify the signature, then confirm via GET.
	 *
	 * The callback body is never trusted on its own — the record is refreshed
	 * with `GET /v1/payments/{id}` and only marked paid when that says settled.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function webhook( WP_REST_Request $request ) {
		$secret = (string) Malipo_Settings::get( 'webhook_secret', '' );
		if ( '' === $secret ) {
			return new WP_REST_Response( array( 'error' => 'webhook_secret_missing' ), 500 );
		}

		$raw       = (string) $request->get_body();
		$header    = (string) $request->get_header( 'x-malipo-signature' );
		$signature = strtolower( (string) preg_replace( '/^sha256=/', '', $header ) );
		$expected  = hash_hmac( 'sha256', $raw, $secret );

		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_body' ), 400 );
		}

		$intent_id = isset( $payload['data']['id'] ) ? (string) $payload['data']['id'] : '';
		if ( '' === $intent_id ) {
			// Nothing to reconcile — acknowledge so Malipo stops retrying.
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => true ), 200 );
		}

		$post_id = Malipo_Payments::find_by_intent( $intent_id );
		if ( $post_id ) {
			Malipo_Payments::refresh( $post_id );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
