<?php
/**
 * Settings screen (Settings → KioskPay), written for shop owners.
 *
 * @package Malipo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the option, the settings page, its fields and the connection test.
 */
class Malipo_Settings {

	const OPTION = 'malipo_settings';

	/** Where a shop owner creates a KioskPay account. */
	const SIGNUP_URL = 'https://kioskpay.co.ke';

	/** The WordPress setup guide for KioskPay. */
	const GUIDE_URL = 'https://kioskpay.co.ke/wordpress/';

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_malipo_test', array( __CLASS__, 'handle_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MALIPO_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_base'       => 'https://backend.kioskpay.co.ke',
			'client_id'      => '',
			'secret_key'     => '',
			'webhook_secret' => '',
		);
	}

	/**
	 * All settings, merged over the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$all = wp_parse_args( $saved, self::defaults() );

		// Self-heal installs that still hold the old default host.
		if ( isset( $all['api_base'] ) && 'https://api.kiosk.ke' === untrailingslashit( (string) $all['api_base'] ) ) {
			$defaults        = self::defaults();
			$all['api_base'] = $defaults['api_base'];
		}

		return $all;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	/**
	 * Add the options page.
	 */
	public static function menu() {
		add_options_page(
			__( 'KioskPay', 'malipo-payments' ),
			__( 'KioskPay', 'malipo-payments' ),
			'manage_options',
			'malipo',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Add a “Settings” link on the Plugins list row, next to Deactivate.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$settings = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'options-general.php?page=malipo' ) ),
			esc_html__( 'Settings', 'malipo-payments' )
		);

		array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Register the option and its fields.
	 */
	public static function register() {
		register_setting(
			'malipo',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'malipo_keys',
			__( 'Connect your shop', 'malipo-payments' ),
			array( __CLASS__, 'section_keys' ),
			'malipo'
		);

		add_settings_field( 'malipo_client_id', __( 'Client ID', 'malipo-payments' ), array( __CLASS__, 'field_client_id' ), 'malipo', 'malipo_keys' );
		add_settings_field( 'malipo_secret_key', __( 'Payment key', 'malipo-payments' ), array( __CLASS__, 'field_secret_key' ), 'malipo', 'malipo_keys' );
		add_settings_field( 'malipo_webhook_secret', __( 'Signing code', 'malipo-payments' ), array( __CLASS__, 'field_webhook_secret' ), 'malipo', 'malipo_keys' );
		add_settings_field( 'malipo_api_base', __( 'API address', 'malipo-payments' ), array( __CLASS__, 'field_api_base' ), 'malipo', 'malipo_keys' );
	}

	/**
	 * Sanitise the option array.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$out = self::defaults();
		if ( ! is_array( $input ) ) {
			return $out;
		}

		if ( isset( $input['api_base'] ) ) {
			$base            = esc_url_raw( trim( (string) $input['api_base'] ) );
			$out['api_base'] = '' !== $base ? $base : $out['api_base'];
		}
		if ( isset( $input['client_id'] ) ) {
			$out['client_id'] = sanitize_text_field( trim( (string) $input['client_id'] ) );
		}
		if ( isset( $input['secret_key'] ) ) {
			$out['secret_key'] = sanitize_text_field( trim( (string) $input['secret_key'] ) );
		}
		if ( isset( $input['webhook_secret'] ) ) {
			$out['webhook_secret'] = sanitize_text_field( trim( (string) $input['webhook_secret'] ) );
		}

		return $out;
	}

	/**
	 * Section intro copy.
	 */
	public static function section_keys() {
		echo '<p>' . esc_html__( 'Sign up at KioskPay, add where money lands, then copy the three codes it shows you and paste them below. You do this only once.', 'malipo-payments' ) . '</p>';
		printf(
			'<p class="description">%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a> %4$s</p>',
			esc_html__( 'Find your codes in your KioskPay account under API keys — each one has a copy button. Sign in at', 'malipo-payments' ),
			esc_url( self::SIGNUP_URL ),
			esc_html( self::SIGNUP_URL ),
			esc_html__( 'to see them.', 'malipo-payments' )
		);
	}

	/**
	 * Client ID field.
	 */
	public static function field_client_id() {
		printf(
			'<input type="text" class="regular-text" autocomplete="off" autocapitalize="off" spellcheck="false" name="%1$s[client_id]" value="%2$s" placeholder="pk_live_…" />',
			esc_attr( self::OPTION ),
			esc_attr( self::get( 'client_id', '' ) )
		);
		echo '<p class="description">' . esc_html__( 'Paste the Client ID from your KioskPay account. It starts with pk_live_. It is not a secret.', 'malipo-payments' ) . '</p>';
	}

	/**
	 * Payment key field.
	 */
	public static function field_secret_key() {
		printf(
			'<input type="password" class="regular-text" autocomplete="off" autocapitalize="off" spellcheck="false" name="%1$s[secret_key]" value="%2$s" placeholder="sk_live_…" />',
			esc_attr( self::OPTION ),
			esc_attr( self::get( 'secret_key', '' ) )
		);
		echo '<p class="description">' . esc_html__( 'Paste the Payment key from your KioskPay account. It starts with sk_live_, and is only shown once.', 'malipo-payments' ) . '</p>';
	}

	/**
	 * Signing code field.
	 */
	public static function field_webhook_secret() {
		printf(
			'<input type="password" class="regular-text" autocomplete="off" autocapitalize="off" spellcheck="false" name="%1$s[webhook_secret]" value="%2$s" placeholder="whsec_…" />',
			esc_attr( self::OPTION ),
			esc_attr( self::get( 'webhook_secret', '' ) )
		);
		echo '<p class="description">' . esc_html__( 'Paste the Signing code from your KioskPay account. It starts with whsec_. This keeps payment confirmations secure.', 'malipo-payments' ) . '</p>';
	}

	/**
	 * API address field.
	 */
	public static function field_api_base() {
		printf(
			'<input type="url" class="regular-text" name="%1$s[api_base]" value="%2$s" placeholder="https://backend.kioskpay.co.ke" />',
			esc_attr( self::OPTION ),
			esc_attr( self::get( 'api_base', 'https://backend.kioskpay.co.ke' ) )
		);
		echo '<p class="description">' . esc_html__( 'Leave this as it is unless KioskPay tells you otherwise.', 'malipo-payments' ) . '</p>';
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$webhook   = rest_url( 'malipo/v1/webhook' );
		$result    = isset( $_GET['malipo_test'] ) ? sanitize_key( wp_unslash( $_GET['malipo_test'] ) ) : '';
		$connected = '' !== trim( (string) self::get( 'secret_key', '' ) )
			&& '' !== trim( (string) self::get( 'client_id', '' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'KioskPay — M-Pesa payments', 'malipo-payments' ); ?></h1>
			<p style="max-width:50rem;font-size:14px">
				<?php esc_html_e( 'Accept M-Pesa payments on your WordPress shop. Set this up once by following the three steps below — it takes about five minutes.', 'malipo-payments' ); ?>
			</p>

			<?php self::render_status( $connected ); ?>
			<?php self::render_notice( $result ); ?>

			<div style="max-width:50rem;background:#fff;border:1px solid #dcdcde;border-left:4px solid #0f6b68;padding:16px 20px;margin:16px 0">
				<h2 style="margin-top:0"><?php esc_html_e( 'Get set up in 3 steps', 'malipo-payments' ); ?></h2>
				<ol style="margin:0;padding-left:1.25rem;line-height:1.7">
					<li>
						<strong><?php esc_html_e( 'Create your KioskPay account.', 'malipo-payments' ); ?></strong>
						<?php esc_html_e( 'Go to', 'malipo-payments' ); ?>
						<a href="<?php echo esc_url( self::SIGNUP_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( self::SIGNUP_URL ); ?></a>
						<?php esc_html_e( 'and sign up. It is free. Add where your money should land — a till, paybill, or bank account.', 'malipo-payments' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Copy your three codes.', 'malipo-payments' ); ?></strong>
						<?php esc_html_e( 'KioskPay shows a Client ID, a Payment key, and a Signing code. Copy all three.', 'malipo-payments' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Paste them below and save.', 'malipo-payments' ); ?></strong>
						<?php esc_html_e( 'Paste each code into the matching box, press Save changes, then press Test connection.', 'malipo-payments' ); ?>
					</li>
				</ol>
			</div>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'malipo' );
				do_settings_sections( 'malipo' );
				submit_button( __( 'Save changes', 'malipo-payments' ) );
				?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:0.5rem">
				<input type="hidden" name="action" value="malipo_test" />
				<?php wp_nonce_field( 'malipo_test' ); ?>
				<?php submit_button( __( 'Test connection', 'malipo-payments' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Sends a quick check to KioskPay to confirm your payment key works.', 'malipo-payments' ); ?></p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Callback address', 'malipo-payments' ); ?></h2>
			<p>
				<?php esc_html_e( 'Already set up for you. This is where KioskPay reports each payment, and the plugin checks it before trusting it.', 'malipo-payments' ); ?>
			</p>
			<p><code><?php echo esc_html( $webhook ); ?></code></p>

			<p>
				<?php esc_html_e( 'Paste this address into KioskPay if it asks for a callback URL. Not sure where to find your codes? See the', 'malipo-payments' ); ?>
				<a href="<?php echo esc_url( self::GUIDE_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'setup guide', 'malipo-payments' ); ?></a>,
				<?php esc_html_e( 'or contact KioskPay support and we will walk you through it.', 'malipo-payments' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * A plain-language banner showing whether the shop is connected yet.
	 *
	 * @param bool $connected Whether a Client ID and Payment key are saved.
	 */
	private static function render_status( $connected ) {
		if ( $connected ) {
			printf(
				'<div class="notice notice-success inline"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Almost there.', 'malipo-payments' ),
				esc_html__( 'Your KioskPay details are filled in. Press “Test connection” below to be sure they work.', 'malipo-payments' )
			);
			return;
		}

		printf(
			'<div class="notice notice-warning inline"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Not set up yet.', 'malipo-payments' ),
			esc_html__( 'Follow the three steps below to start taking M-Pesa payments.', 'malipo-payments' )
		);
	}

	/**
	 * Print the result of the last connection test.
	 *
	 * @param string $result Result flag.
	 */
	private static function render_notice( $result ) {
		if ( '' === $result ) {
			return;
		}

		$map = array(
			'ok'          => array( 'notice-success', __( 'Connection works — your payment key was accepted.', 'malipo-payments' ) ),
			'bad'         => array( 'notice-error', __( 'That payment key was not accepted. Copy it again from your KioskPay account.', 'malipo-payments' ) ),
			'missing'     => array( 'notice-warning', __( 'Add your payment key first, then test again.', 'malipo-payments' ) ),
			'unreachable' => array( 'notice-error', __( 'Could not reach KioskPay. Check the API address and try again.', 'malipo-payments' ) ),
		);

		if ( ! isset( $map[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $result ][0] ),
			esc_html( $map[ $result ][1] )
		);
	}

	/**
	 * Handle the "Test connection" button.
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'malipo-payments' ) );
		}
		check_admin_referer( 'malipo_test' );

		$result = Malipo_API::from_settings()->test_connection();

		if ( true === $result ) {
			$flag = 'ok';
		} elseif ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( 'malipo_not_configured' === $code ) {
				$flag = 'missing';
			} elseif ( 'malipo_unauthorized' === $code ) {
				$flag = 'bad';
			} else {
				$flag = 'unreachable';
			}
		} else {
			$flag = 'unreachable';
		}

		wp_safe_redirect(
			add_query_arg(
				'malipo_test',
				$flag,
				admin_url( 'options-general.php?page=malipo' )
			)
		);
		exit;
	}
}
