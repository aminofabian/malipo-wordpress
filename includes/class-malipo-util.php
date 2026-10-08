<?php
/**
 * Small shared helpers.
 *
 * @package Malipo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Phone and formatting helpers.
 */
class Malipo_Util {

	/**
	 * Normalise a Kenyan number to the 2547XXXXXXXX / 2541XXXXXXXX form.
	 *
	 * Accepts 0712345678, 712345678, +254712345678 and 254712345678.
	 *
	 * @param string $raw Raw phone input.
	 * @return string Digits in 254… form, or '' when empty.
	 */
	public static function normalize_phone( $raw ) {
		$digits = preg_replace( '/\D+/', '', (string) $raw );
		if ( '' === $digits ) {
			return '';
		}

		if ( 0 === strpos( $digits, '254' ) ) {
			// Already country-coded.
			return $digits;
		}
		if ( 0 === strpos( $digits, '0' ) ) {
			return '254' . substr( $digits, 1 );
		}
		if ( 0 === strpos( $digits, '7' ) || 0 === strpos( $digits, '1' ) ) {
			return '254' . $digits;
		}

		return $digits;
	}

	/**
	 * True when the number is a valid Kenyan mobile MSISDN.
	 *
	 * @param string $phone Normalised phone.
	 * @return bool
	 */
	public static function is_kenyan_msisdn( $phone ) {
		return (bool) preg_match( '/^254(7|1)\d{8}$/', (string) $phone );
	}

	/**
	 * Format a KES amount for display.
	 *
	 * @param string|float $amount Amount.
	 * @param string       $currency Currency code.
	 * @return string
	 */
	public static function format_amount( $amount, $currency = 'KES' ) {
		return $currency . ' ' . number_format_i18n( (float) $amount, 2 );
	}
}
