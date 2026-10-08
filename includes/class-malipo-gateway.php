<?php
/**
 * WooCommerce payment gateway: M-Pesa (KioskPay).
 *
 * @package Malipo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds KioskPay as a WooCommerce payment method and reconciles orders on settle.
 */
class Malipo_Gateway extends WC_Payment_Gateway {

	/**
	 * Hook into WooCommerce.
	 */
	public static function init() {
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateway' ) );
		add_action( 'malipo_payment_settled', array( __CLASS__, 'on_settled' ), 10, 2 );
		add_action( 'malipo_payment_failed', array( __CLASS__, 'on_failed' ), 10, 2 );
		add_action( 'woocommerce_thankyou_malipo', array( __CLASS__, 'thankyou' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_on_thankyou' ), 20 );
	}

	/**
	 * Register the gateway with WooCommerce.
	 *
	 * @param array $methods Gateways.
	 * @return array
	 */
	public static function add_gateway( $methods ) {
		$methods[] = 'Malipo_Gateway';
		return $methods;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'malipo';
		$this->method_title       = __( 'M-Pesa (KioskPay)', 'malipo-payments' );
		$this->method_description = __( 'Prompt the customer\'s phone with an M-Pesa STK push through KioskPay.', 'malipo-payments' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Admin settings for the gateway.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'malipo-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable M-Pesa (KioskPay)', 'malipo-payments' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'malipo-payments' ),
				'type'        => 'text',
				'description' => __( 'Shown to the customer at checkout.', 'malipo-payments' ),
				'default'     => __( 'M-Pesa', 'malipo-payments' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'   => __( 'Description', 'malipo-payments' ),
				'type'    => 'textarea',
				'default' => __( 'Pay from your phone. You will get an M-Pesa prompt to enter your PIN.', 'malipo-payments' ),
			),
		);
	}

	/**
	 * Show the gateway only when it is enabled, configured and pricing in KES.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( ! Malipo_API::from_settings()->is_configured() ) {
			return false;
		}
		if ( function_exists( 'get_woocommerce_currency' ) && 'KES' !== get_woocommerce_currency() ) {
			return false;
		}
		return parent::is_available();
	}

	/**
	 * Phone field on checkout.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( $this->description ) );
		}

		$phone = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$phone = (string) WC()->customer->get_billing_phone();
		}

		echo '<p class="form-row form-row-wide">';
		echo '<label for="malipo_phone">' . esc_html__( 'M-Pesa phone number', 'malipo-payments' ) . ' <span class="required">*</span></label>';
		echo '<input type="tel" id="malipo_phone" name="malipo_phone" inputmode="tel" autocomplete="tel" placeholder="0712 345 678" value="' . esc_attr( $phone ) . '" />';
		echo '</p>';
	}

	/**
	 * Validate the phone field.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$phone = isset( $_POST['malipo_phone'] ) ? Malipo_Util::normalize_phone( sanitize_text_field( wp_unslash( $_POST['malipo_phone'] ) ) ) : '';
		if ( ! Malipo_Util::is_kenyan_msisdn( $phone ) ) {
			wc_add_notice( __( 'Enter a valid M-Pesa phone number, for example 0712 345 678.', 'malipo-payments' ), 'error' );
			return false;
		}
		return true;
	}

	/**
	 * Create the KioskPay payment and put the order on hold.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'We could not load your order.', 'malipo-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$phone = isset( $_POST['malipo_phone'] ) ? Malipo_Util::normalize_phone( sanitize_text_field( wp_unslash( $_POST['malipo_phone'] ) ) ) : '';

		// A fresh attempt key each time, so a retry after a failure starts clean.
		$attempt = (int) $order->get_meta( '_malipo_attempt' ) + 1;
		$order->update_meta_data( '_malipo_attempt', $attempt );
		$order->save();

		$result = Malipo_Payments::create(
			array(
				'amount'          => (string) $order->get_total(),
				'phone'           => $phone,
				'reference'       => (string) $order->get_order_number(),
				'order_id'        => $order_id,
				'source'          => 'woocommerce',
				'idempotency_key' => sprintf( 'wc-%d-%d', $order_id, $attempt ),
			)
		);

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_malipo_payment_id', (int) $result['id'] );
		$order->update_meta_data( '_malipo_token', (string) $result['token'] );
		$order->update_meta_data( '_malipo_intent_id', (string) $result['intent_id'] );
		$order->add_order_note(
			sprintf(
				/* translators: %s: customer phone number. */
				__( 'M-Pesa prompt sent to %s via KioskPay.', 'malipo-payments' ),
				$phone
			)
		);

		if ( ! $order->is_paid() ) {
			$order->update_status( 'on-hold', __( 'Awaiting M-Pesa confirmation.', 'malipo-payments' ) );
		}
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_order_received_url(),
		);
	}

	/**
	 * Mark the WooCommerce order paid when the linked payment settles.
	 *
	 * @param int   $post_id Record id.
	 * @param array $record  Record.
	 */
	public static function on_settled( $post_id, $record ) {
		$order = self::order_from_record( $record );
		if ( ! $order || $order->is_paid() ) {
			return;
		}

		$receipt = ! empty( $record['receipt'] ) ? (string) $record['receipt'] : '';
		$order->payment_complete( $receipt );
		$order->add_order_note(
			sprintf(
				/* translators: %s: M-Pesa receipt code. */
				__( 'M-Pesa payment confirmed. Receipt %s.', 'malipo-payments' ),
				$receipt
			)
		);
	}

	/**
	 * Mark the WooCommerce order failed when the linked payment fails.
	 *
	 * @param int   $post_id Record id.
	 * @param array $record  Record.
	 */
	public static function on_failed( $post_id, $record ) {
		$order = self::order_from_record( $record );
		if ( ! $order || $order->is_paid() ) {
			return;
		}

		$message = ! empty( $record['failure_message'] )
			? (string) $record['failure_message']
			: __( 'The M-Pesa payment did not complete.', 'malipo-payments' );

		$order->update_status( 'failed', $message );
	}

	/**
	 * Poll strip on the order-received page.
	 *
	 * @param int $order_id Order id.
	 */
	public static function thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$token = (string) $order->get_meta( '_malipo_token' );
		if ( '' === $token ) {
			return;
		}

		$status_url = rest_url( 'malipo/v1/status/' . $token );
		echo '<div class="malipo-poll" data-malipo-poll data-status-url="' . esc_url( $status_url ) . '" role="status" aria-live="polite">';
		echo esc_html__( 'Waiting for M-Pesa confirmation…', 'malipo-payments' );
		echo '</div>';
	}

	/**
	 * Enqueue the poller on the order-received page.
	 */
	public static function enqueue_on_thankyou() {
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			wp_enqueue_style( 'malipo' );
			wp_enqueue_script( 'malipo-pay' );
		}
	}

	/**
	 * Resolve the WooCommerce order behind a payment record.
	 *
	 * @param array $record Record.
	 * @return WC_Order|false
	 */
	private static function order_from_record( $record ) {
		$order_id = isset( $record['order_id'] ) ? absint( $record['order_id'] ) : 0;
		if ( ! $order_id ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		return $order ? $order : false;
	}
}
