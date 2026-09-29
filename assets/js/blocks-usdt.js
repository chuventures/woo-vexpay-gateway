/**
 * WooCommerce Blocks payment method for VEXPay USDT.
 *
 * No fields: placing the order redirects the buyer to VEXPay's hosted USDT checkout.
 * Vanilla ES compatible with WC Blocks registry (no build step required).
 */
( function () {
	'use strict';

	const { registerPaymentMethod } = wc.wcBlocksRegistry;
	const { createElement } = wp.element;
	const { decodeEntities } = wp.htmlEntities;
	const { __ } = wp.i18n;

	const settings =
		typeof wc.wcSettings.getPaymentMethodData === 'function'
			? wc.wcSettings.getPaymentMethodData( 'vexpay_usdt', {} )
			: wc.wcSettings.getSetting( 'vexpay_usdt_data', {} );

	const label = decodeEntities( settings.title || __( 'USDT (VEXPay)', 'vexpay-gateway-for-woocommerce' ) );

	const Content = () =>
		createElement(
			'div',
			{ className: 'vexpay-flow vexpay-checkout-panel' },
			settings.testmode
				? createElement(
						'p',
						{ className: 'vexpay-test-mode' },
						createElement( 'strong', null, __( 'SANDBOX', 'vexpay-gateway-for-woocommerce' ) ),
						' — ',
						__( 'No real funds move here.', 'vexpay-gateway-for-woocommerce' )
				  )
				: null,
			settings.description ? createElement( 'p', null, decodeEntities( settings.description ) ) : null,
			createElement(
				'p',
				{ className: 'vexpay-checkout-secure' },
				createElement( 'span', { className: 'vexpay-checkout-secure__lock', 'aria-hidden': 'true' } ),
				__( 'Secured by VEXPay', 'vexpay-gateway-for-woocommerce' )
			)
		);

	const Label = () =>
		createElement(
			'span',
			{ className: 'vexpay-blocks-label' },
			settings.icon
				? createElement( 'img', { src: settings.icon, alt: '', className: 'vexpay-blocks-icon' } )
				: null,
			createElement(
				'span',
				{ className: 'vexpay-blocks-label__text' },
				createElement( 'span', { className: 'vexpay-blocks-label__title' }, label ),
				createElement(
					'span',
					{ className: 'vexpay-blocks-label__hint' },
					__( 'VEXPay · USDT', 'vexpay-gateway-for-woocommerce' )
				)
			)
		);

	registerPaymentMethod( {
		name: 'vexpay_usdt',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: () => true,
		ariaLabel: label,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
