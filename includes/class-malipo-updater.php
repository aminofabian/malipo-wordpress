<?php
/**
 * Self-updates the plugin from kioskpay.co.ke.
 *
 * WordPress only knows how to update plugins in the wordpress.org directory.
 * This class teaches it where to look for ours: a small JSON manifest hosted
 * next to the release zips, e.g.
 *
 *   https://kioskpay.co.ke/malipo-payments.json
 *   {
 *     "version": "0.1.3",
 *     "package": "https://kioskpay.co.ke/malipo-payments-0.1.3.zip",
 *     "requires": "6.0",
 *     "tested": "6.7",
 *     "requires_php": "7.4",
 *     "changelog": "Optional HTML."
 *   }
 *
 * When the manifest advertises a higher version, the shop owner sees the normal
 * "update now" row in Plugins — the same experience as a wordpress.org plugin.
 *
 * Change the manifest location with the `MALIPO_UPDATE_MANIFEST` constant or the
 * `malipo_update_manifest_url` filter.
 *
 * @package Malipo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin self-updater backed by a JSON manifest on kioskpay.co.ke.
 */
class Malipo_Updater {

	/**
	 * Cache key for the fetched manifest.
	 */
	const TRANSIENT = 'malipo_update_manifest';

	/**
	 * Where the manifest lives by default.
	 */
	const DEFAULT_MANIFEST = 'https://kioskpay.co.ke/malipo-payments.json';

	/**
	 * Slug used by the update UI and the details modal.
	 */
	const SLUG = 'malipo-payments';

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 2 );
	}

	/**
	 * The manifest URL, overridable by constant or filter.
	 *
	 * @return string
	 */
	public static function manifest_url() {
		if ( defined( 'MALIPO_UPDATE_MANIFEST' ) && MALIPO_UPDATE_MANIFEST ) {
			return (string) MALIPO_UPDATE_MANIFEST;
		}

		return (string) apply_filters( 'malipo_update_manifest_url', self::DEFAULT_MANIFEST );
	}

	/**
	 * Our plugin file, relative to the plugins directory.
	 *
	 * @return string
	 */
	public static function plugin_file() {
		return plugin_basename( MALIPO_FILE );
	}

	/**
	 * Fetch (and cache) the release manifest.
	 *
	 * @param bool $force Skip the cache.
	 * @return array|null Manifest, or null when unavailable.
	 */
	public static function manifest( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			if ( 'none' === $cached ) {
				return null;
			}
		}

		$response = wp_remote_get(
			self::manifest_url(),
			array(
				'timeout' => 15,
				'headers' => array( 'accept' => 'application/json' ),
			)
		);

		$data = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$json = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $json ) && ! empty( $json['version'] ) && ! empty( $json['package'] ) ) {
				$data = $json;
			}
		}

		// Cache a success for 6 hours, a miss for 1 — so a transient outage does
		// not hide updates for long, and a working manifest is not re-fetched.
		set_transient( self::TRANSIENT, $data ? $data : 'none', $data ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Tell WordPress an update is available when the manifest advertises one.
	 *
	 * @param object $transient The update_plugins transient.
	 * @return object
	 */
	public static function check( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$manifest = self::manifest();
		if ( ! $manifest ) {
			return $transient;
		}

		$plugin  = self::plugin_file();
		$remote  = (string) $manifest['version'];
		$payload = (object) array(
			'slug'         => self::SLUG,
			'plugin'       => $plugin,
			'new_version'  => $remote,
			'url'          => 'https://kioskpay.co.ke/wordpress/',
			'package'      => (string) $manifest['package'],
			'icons'        => array(),
			'banners'      => array(),
			'requires'     => isset( $manifest['requires'] ) ? (string) $manifest['requires'] : '',
			'tested'       => isset( $manifest['tested'] ) ? (string) $manifest['tested'] : '',
			'requires_php' => isset( $manifest['requires_php'] ) ? (string) $manifest['requires_php'] : '',
		);

		if ( version_compare( $remote, MALIPO_VERSION, '>' ) ) {
			$transient->response[ $plugin ] = $payload;
		} else {
			unset( $transient->response[ $plugin ] );
			$payload->package                    = '';
			$transient->no_update[ $plugin ] = $payload;
		}

		return $transient;
	}

	/**
	 * Populate the "View version details" modal.
	 *
	 * @param mixed  $result The default result.
	 * @param string $action The API action.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$manifest = self::manifest();
		if ( ! $manifest ) {
			return $result;
		}

		$sections = array();
		if ( ! empty( $manifest['description'] ) ) {
			$sections['description'] = wp_kses_post( $manifest['description'] );
		}
		if ( ! empty( $manifest['changelog'] ) ) {
			$sections['changelog'] = wp_kses_post( $manifest['changelog'] );
		}

		return (object) array(
			'name'          => 'KioskPay — M-Pesa Payments',
			'slug'          => self::SLUG,
			'version'       => (string) $manifest['version'],
			'author'        => '<a href="https://kioskpay.co.ke">KioskPay</a>',
			'homepage'      => 'https://kioskpay.co.ke/wordpress/',
			'requires'      => isset( $manifest['requires'] ) ? (string) $manifest['requires'] : '',
			'tested'        => isset( $manifest['tested'] ) ? (string) $manifest['tested'] : '',
			'requires_php'  => isset( $manifest['requires_php'] ) ? (string) $manifest['requires_php'] : '',
			'download_link' => (string) $manifest['package'],
			'sections'      => $sections,
		);
	}

	/**
	 * Drop the cached manifest after an upgrade so the next check is fresh.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Upgrade context.
	 */
	public static function clear_cache( $upgrader, $options ) {
		if ( ! is_array( $options ) || 'update' !== ( $options['action'] ?? '' ) ) {
			return;
		}
		if ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
			delete_transient( self::TRANSIENT );
		}
	}
}
