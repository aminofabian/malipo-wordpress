<?php
/**
 * Payment records: a lightweight post type that tracks each KioskPay intent.
 *
 * @package Malipo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores one record per payment attempt and keeps its status in sync with KioskPay.
 */
class Malipo_Payments {

	const POST_TYPE = 'malipo_payment';

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	/**
	 * Register the (private, admin-visible) payment record type.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'KioskPay Payments', 'malipo-payments' ),
					'singular_name' => __( 'KioskPay Payment', 'malipo-payments' ),
					'menu_name'     => __( 'KioskPay', 'malipo-payments' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-money-alt',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'supports'            => array( 'title' ),
				'exclude_from_search' => true,
			)
		);
	}

	/**
	 * Create a payment: insert the record, then prompt the phone through KioskPay.
	 *
	 * @param array $args {
	 *     @type string $amount    Decimal amount.
	 *     @type string $phone     Customer phone.
	 *     @type string $reference Optional order reference.
	 *     @type int    $order_id  Optional WooCommerce order id.
	 *     @type string $source    'shortcode' or 'woocommerce'.
	 *     @type string $idempotency_key Optional explicit key.
	 * }
	 * @return array|WP_Error Record on success.
	 */
	public static function create( array $args ) {
		$amount = isset( $args['amount'] ) ? (string) $args['amount'] : '';
		$phone  = Malipo_Util::normalize_phone( isset( $args['phone'] ) ? $args['phone'] : '' );

		if ( ! Malipo_Util::is_kenyan_msisdn( $phone ) ) {
			return new WP_Error(
				'malipo_invalid_phone',
				__( 'Enter a valid Kenyan phone number, for example 0712 345 678.', 'malipo-payments' )
			);
		}
		if ( ! is_numeric( $amount ) || (float) $amount <= 0 ) {
			return new WP_Error(
				'malipo_invalid_amount',
				__( 'Enter an amount greater than zero.', 'malipo-payments' )
			);
		}

		$reference = isset( $args['reference'] ) ? (string) $args['reference'] : '';
		$order_id  = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
		$source    = isset( $args['source'] ) ? (string) $args['source'] : 'shortcode';
		$currency  = self::currency();

		$idempotency = isset( $args['idempotency_key'] ) && '' !== $args['idempotency_key']
			? (string) $args['idempotency_key']
			: self::generate_idempotency_key( $source, $order_id );
		$idempotency = substr( $idempotency, 0, 191 );

		$title = '' !== $reference
			/* translators: 1: reference, 2: formatted amount. */
			? sprintf( __( '%1$s — %2$s', 'malipo-payments' ), $reference, Malipo_Util::format_amount( $amount, $currency ) )
			/* translators: %s: formatted amount. */
			: sprintf( __( 'Payment %s', 'malipo-payments' ), Malipo_Util::format_amount( $amount, $currency ) );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$token = bin2hex( random_bytes( 16 ) );

		self::save_meta(
			$post_id,
			array(
				'amount'     => $amount,
				'currency'   => $currency,
				'phone'      => $phone,
				'reference'  => $reference,
				'order_id'   => $order_id,
				'source'     => $source,
				'status'     => 'pending',
				'token'      => $token,
			)
		);

		$api = Malipo_API::from_settings();
		$res = $api->create_payment(
			array(
				'amount'          => $amount,
				'phone'           => $phone,
				'idempotency_key' => $idempotency,
				'reference'       => $reference,
				'callback_url'    => rest_url( 'malipo/v1/webhook' ),
				'currency'        => $currency,
			)
		);

		if ( is_wp_error( $res ) ) {
			self::save_meta(
				$post_id,
				array(
					'status'          => 'failed',
					'failure_kind'    => $res->get_error_code(),
					'failure_message' => $res->get_error_message(),
				)
			);
			return $res;
		}

		self::save_meta(
			$post_id,
			array(
				'intent_id'       => isset( $res['id'] ) ? (string) $res['id'] : '',
				'status'          => self::public_status( isset( $res['status'] ) ? $res['status'] : 'pending' ),
				'receipt'         => isset( $res['receipt'] ) ? (string) $res['receipt'] : '',
				'failure_kind'    => isset( $res['failure_kind'] ) ? (string) $res['failure_kind'] : '',
				'failure_message' => isset( $res['failure_message'] ) ? (string) $res['failure_message'] : '',
				'idempotency_key' => $idempotency,
			)
		);

		return self::record( $post_id );
	}

	/**
	 * Re-read the payment from KioskPay and update the record.
	 *
	 * Only a `settled` GET marks the payment (and its WooCommerce order) paid.
	 *
	 * @param int $post_id Record id.
	 * @return array|WP_Error Record on success.
	 */
	public static function refresh( $post_id ) {
		$post_id = absint( $post_id );
		$intent  = self::get_meta( $post_id, 'intent_id' );

		if ( '' === $intent ) {
			return new WP_Error( 'malipo_missing_intent', __( 'This payment has no KioskPay id yet.', 'malipo-payments' ) );
		}

		$api = Malipo_API::from_settings();
		$res = $api->get_payment( $intent );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$status = self::public_status( isset( $res['status'] ) ? $res['status'] : 'pending' );

		self::save_meta(
			$post_id,
			array(
				'status'          => $status,
				'receipt'         => isset( $res['receipt'] ) ? (string) $res['receipt'] : '',
				'failure_kind'    => isset( $res['failure_kind'] ) ? (string) $res['failure_kind'] : '',
				'failure_message' => isset( $res['failure_message'] ) ? (string) $res['failure_message'] : '',
			)
		);

		$record = self::record( $post_id );

		if ( 'settled' === $status ) {
			/**
			 * Fires when a payment is confirmed settled. Mapped to 2547/2541 MSISDNs as paid.
			 *
			 * @param int   $post_id Record id.
			 * @param array $record  Record data.
			 */
			do_action( 'malipo_payment_settled', $post_id, $record );
		} elseif ( 'failed' === $status ) {
			/**
			 * Fires when a payment fails.
			 *
			 * @param int   $post_id Record id.
			 * @param array $record  Record data.
			 */
			do_action( 'malipo_payment_failed', $post_id, $record );
		}

		return $record;
	}

	/**
	 * Assemble a record for callers.
	 *
	 * @param int $post_id Record id.
	 * @return array
	 */
	public static function record( $post_id ) {
		$post_id = absint( $post_id );
		return array(
			'id'              => $post_id,
			'token'           => self::get_meta( $post_id, 'token' ),
			'intent_id'       => self::get_meta( $post_id, 'intent_id' ),
			'amount'          => self::get_meta( $post_id, 'amount' ),
			'currency'        => self::get_meta( $post_id, 'currency' ),
			'phone'           => self::get_meta( $post_id, 'phone' ),
			'reference'       => self::get_meta( $post_id, 'reference' ),
			'order_id'        => absint( self::get_meta( $post_id, 'order_id' ) ),
			'status'          => self::get_meta( $post_id, 'status' ),
			'receipt'         => self::get_meta( $post_id, 'receipt' ),
			'failure_kind'    => self::get_meta( $post_id, 'failure_kind' ),
			'failure_message' => self::get_meta( $post_id, 'failure_message' ),
		);
	}

	/**
	 * Find a record by its KioskPay intent id.
	 *
	 * @param string $intent_id Intent id.
	 * @return int Record id or 0.
	 */
	public static function find_by_intent( $intent_id ) {
		return self::find_by_meta( 'intent_id', $intent_id );
	}

	/**
	 * Find a record by its public polling token.
	 *
	 * @param string $token Token.
	 * @return int Record id or 0.
	 */
	public static function find_by_token( $token ) {
		return self::find_by_meta( 'token', $token );
	}

	/**
	 * Currency for new payments.
	 *
	 * @return string
	 */
	public static function currency() {
		return 'KES';
	}

	/**
	 * Normalise the API status to pending/settled/failed.
	 *
	 * @param string $status API status.
	 * @return string
	 */
	public static function public_status( $status ) {
		$status = strtolower( (string) $status );
		if ( 'settled' === $status ) {
			return 'settled';
		}
		if ( in_array( $status, array( 'failed', 'expired', 'cancelled' ), true ) ) {
			return 'failed';
		}
		return 'pending';
	}

	/**
	 * Build a unique, retry-safe idempotency key (8–191 chars).
	 *
	 * @param string $source   Payment source.
	 * @param int    $order_id Optional order id.
	 * @return string
	 */
	private static function generate_idempotency_key( $source, $order_id = 0 ) {
		$suffix = substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
		$prefix = $order_id ? 'wp-' . $source . '-' . $order_id : 'wp-' . $source;
		return $prefix . '-' . $suffix;
	}

	/**
	 * Query a record by a meta key/value.
	 *
	 * @param string $key   Meta key without prefix.
	 * @param string $value Meta value.
	 * @return int
	 */
	private static function find_by_meta( $key, $value ) {
		if ( '' === (string) $value ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'       => '_malipo_' . $key,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'     => $value,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Read a prefixed record meta value.
	 *
	 * @param int    $post_id Record id.
	 * @param string $key     Meta key without prefix.
	 * @return string
	 */
	public static function get_meta( $post_id, $key ) {
		return (string) get_post_meta( absint( $post_id ), '_malipo_' . $key, true );
	}

	/**
	 * Write prefixed record meta values.
	 *
	 * @param int   $post_id Record id.
	 * @param array $values  Key/value pairs.
	 */
	private static function save_meta( $post_id, array $values ) {
		foreach ( $values as $key => $value ) {
			update_post_meta( absint( $post_id ), '_malipo_' . $key, $value );
		}
	}
}
