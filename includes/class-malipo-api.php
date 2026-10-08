<?php
/**
 * Malipo public payments API client.
 *
 * @package Malipo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over `POST /v1/payments` and `GET /v1/payments/{id}`.
 */
class Malipo_API {

	/**
	 * Base URL, without a trailing slash.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Client ID (pk_live_…). Public, paired with the secret for Basic auth.
	 *
	 * @var string
	 */
	private $client_id;

	/**
	 * Secret key (sk_live_…).
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string $base      API base URL.
	 * @param string $key       Secret key.
	 * @param string $client_id Optional client ID.
	 */
	public function __construct( $base, $key, $client_id = '' ) {
		$this->base      = untrailingslashit( trim( (string) $base ) );
		$this->key       = trim( (string) $key );
		$this->client_id = trim( (string) $client_id );
	}

	/**
	 * Build a client from saved settings.
	 *
	 * @return self
	 */
	public static function from_settings() {
		return new self(
			Malipo_Settings::get( 'api_base', 'https://backend.kioskpay.co.ke' ),
			Malipo_Settings::get( 'secret_key', '' ),
			Malipo_Settings::get( 'client_id', '' )
		);
	}

	/**
	 * The `Authorization` header value for KioskPay.
	 *
	 * KioskPay accepts a client ID + secret pair as HTTP Basic, or the secret
	 * alone as a bearer token. We prefer Basic whenever a client ID is set so the
	 * pair travels together; without one we fall back to bearer.
	 *
	 * @return string
	 */
	private function authorization() {
		if ( '' !== $this->client_id ) {
			return 'Basic ' . base64_encode( $this->client_id . ':' . $this->key );
		}
		return 'Bearer ' . $this->key;
	}

	/**
	 * Whether a base URL and secret key are present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->base && '' !== $this->key;
	}

	/**
	 * Quick check that the payment key is accepted by Malipo.
	 *
	 * Reads a payment id that will never exist: a 404 means the key was accepted
	 * (we are authenticated; the id simply isn’t ours), a 401 means it was not.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'malipo_not_configured',
				__( 'Add your payment key first.', 'malipo-payments' )
			);
		}

		$response = wp_remote_get(
			$this->base . '/v1/payments/00000000-0000-0000-0000-000000000000',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => $this->authorization(),
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code ) {
			return new WP_Error(
				'malipo_unauthorized',
				__( 'KioskPay did not accept that payment key.', 'malipo-payments' )
			);
		}

		if ( 200 === $code ) {
			return true;
		}

		if ( 404 === $code ) {
			$json = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $json ) && isset( $json['error'] ) && 'not_found' === $json['error'] ) {
				return true;
			}
			return new WP_Error(
				'malipo_unexpected',
				__( 'Reached a server, but it did not look like KioskPay. Check the API address.', 'malipo-payments' )
			);
		}

		return new WP_Error(
			'malipo_http_' . $code,
			/* translators: %d: HTTP status code. */
			sprintf( __( 'KioskPay replied with HTTP %d.', 'malipo-payments' ), $code )
		);
	}

	/**
	 * Create a payment — prompts the customer's phone.
	 *
	 * @param array $args {
	 *     @type string $amount          Decimal string, greater than zero.
	 *     @type string $phone           Kenyan MSISDN.
	 *     @type string $idempotency_key Unique per attempt (8–191 chars).
	 *     @type string $reference       Optional order id.
	 *     @type string $callback_url    Optional https URL.
	 *     @type string $currency        Optional, defaults to KES.
	 * }
	 * @return array|WP_Error
	 */
	public function create_payment( array $args ) {
		$body = array(
			'amount'          => (string) $args['amount'],
			'customer_phone'  => (string) $args['phone'],
			'idempotency_key' => (string) $args['idempotency_key'],
		);

		foreach ( array( 'reference', 'callback_url', 'currency' ) as $field ) {
			if ( ! empty( $args[ $field ] ) ) {
				$body[ $field ] = (string) $args[ $field ];
			}
		}

		return $this->request( 'POST', '/v1/payments', $body );
	}

	/**
	 * Fetch a payment. Treat it as paid only when status is `settled`.
	 *
	 * @param string $id Payment id.
	 * @return array|WP_Error
	 */
	public function get_payment( $id ) {
		return $this->request( 'GET', '/v1/payments/' . rawurlencode( (string) $id ) );
	}

	/**
	 * Perform a signed request against the Malipo API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   API path, e.g. /v1/payments.
	 * @param array|null $body   Optional JSON body.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $body = null ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'malipo_not_configured',
				__( 'KioskPay is not configured. Add your payment key under Settings → KioskPay.', 'malipo-payments' )
			);
		}

		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Authorization' => $this->authorization(),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$error   = ( is_array( $json ) && ! empty( $json['error'] ) ) ? $json['error'] : 'http_' . $code;
			$message = ( is_array( $json ) && ! empty( $json['message'] ) )
				? $json['message']
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'KioskPay request failed (HTTP %d).', 'malipo-payments' ), $code );

			return new WP_Error(
				'malipo_' . $error,
				$message,
				array(
					'status' => $code,
					'body'   => $json,
				)
			);
		}

		return is_array( $json ) ? $json : array();
	}
}
