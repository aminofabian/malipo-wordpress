<?php
/**
 * The [malipo_pay] shortcode — charge a phone from any page or post.
 *
 * @package Malipo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the shortcode form and registers its assets.
 */
class Malipo_Shortcode {

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_shortcode( 'malipo_pay', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register (but do not enqueue) the front-end assets.
	 */
	public static function register_assets() {
		wp_register_style( 'malipo', MALIPO_URL . 'assets/css/malipo.css', array(), MALIPO_VERSION );
		wp_register_script( 'malipo-pay', MALIPO_URL . 'assets/js/malipo-pay.js', array(), MALIPO_VERSION, true );
		wp_localize_script(
			'malipo-pay',
			'MALIPO',
			array(
				'payUrl'    => rest_url( 'malipo/v1/pay' ),
				'statusUrl' => rest_url( 'malipo/v1/status/' ),
				'strings'   => array(
					'waiting' => __( 'Check your phone and enter your M-Pesa PIN…', 'malipo-payments' ),
					'paid'    => __( 'Payment received.', 'malipo-payments' ),
					'failed'  => __( 'The payment did not complete.', 'malipo-payments' ),
					'error'   => __( 'Something went wrong. Please try again.', 'malipo-payments' ),
				),
			)
		);
	}

	/**
	 * Render the form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'amount'    => '',
				'reference' => '',
				'title'     => __( 'Pay with M-Pesa', 'malipo-payments' ),
				'button'    => __( 'Send payment request', 'malipo-payments' ),
			),
			$atts,
			'malipo_pay'
		);

		if ( '' === trim( (string) $atts['amount'] ) || ! is_numeric( $atts['amount'] ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="malipo-notice">' . esc_html__( '[malipo_pay] needs a numeric amount, for example [malipo_pay amount="100"].', 'malipo-payments' ) . '</p>';
			}
			return '';
		}

		wp_enqueue_style( 'malipo' );
		wp_enqueue_script( 'malipo-pay' );

		ob_start();
		?>
		<form class="malipo-form" data-malipo-form>
			<h3 class="malipo-form__title"><?php echo esc_html( $atts['title'] ); ?></h3>
			<p class="malipo-form__amount"><?php echo esc_html( Malipo_Util::format_amount( $atts['amount'] ) ); ?></p>

			<label class="malipo-field">
				<span><?php esc_html_e( 'M-Pesa phone number', 'malipo-payments' ); ?></span>
				<input type="tel" name="phone" inputmode="tel" autocomplete="tel" placeholder="0712 345 678" required />
			</label>

			<input type="hidden" name="amount" value="<?php echo esc_attr( $atts['amount'] ); ?>" />
			<input type="hidden" name="reference" value="<?php echo esc_attr( $atts['reference'] ); ?>" />

			<button type="submit" class="malipo-btn"><?php echo esc_html( $atts['button'] ); ?></button>

			<div class="malipo-status" data-malipo-status role="status" aria-live="polite"></div>
		</form>
		<?php
		return ob_get_clean();
	}
}
