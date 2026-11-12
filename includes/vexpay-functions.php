<?php
/**
 * Public helper functions.
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'vexpay_upsert_merchant' ) ) {
	/**
	 * Create or update a VEX Pay comercio (merchant) from your store's own comercio record.
	 *
	 * Keyed by your comercio id (`external_ref`): calling it again with the same id updates
	 * the same VEX Pay merchant. Uses the API key configured in the VEX Pay gateway settings.
	 *
	 * Keys:
	 * - external_ref (string, required): your comercio id.
	 * - name, identification (cédula V/E + 8 digits or RIF J/G + 9 digits), contact_email, contact_phone:
	 *   required to create the merchant.
	 * - bank_code (e.g. "0134") + phone (e.g. "04145555555"): Pago Móvil payout account. A new or
	 *   changed account must be verified with the two micro-deposit amounts before payouts.
	 * - application_fee_percent (float|null): your commission on this comercio's shares (null = tenant default).
	 * - auto_payout (bool): pay the comercio's available balance automatically.
	 * - is_active (bool): optional soft on/off switch.
	 *
	 * @param array $comercio Comercio record.
	 * @return string|WP_Error VEX Pay merchant id, to store on your comercio record.
	 */
	function vexpay_upsert_merchant( array $comercio ) {
		return VEXPay_Marketplace::upsert_merchant( $comercio );
	}
}
