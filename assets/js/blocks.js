/**
 * WooCommerce Blocks payment method for VEXPay C2P.
 *
 * Vanilla ES compatible with WC Blocks registry (no build step required).
 */
( function () {
	'use strict';

	const { registerPaymentMethod } = wc.wcBlocksRegistry;
	const { createElement, useState, useEffect } = wp.element;
	const { decodeEntities } = wp.htmlEntities;
	const { __ } = wp.i18n;

	// WC 8.9+ exposes getPaymentMethodData; fall back for older installs.
	const settings =
		typeof wc.wcSettings.getPaymentMethodData === 'function'
			? wc.wcSettings.getPaymentMethodData( 'vexpay', {} )
			: wc.wcSettings.getSetting( 'vexpay_data', {} );
	const label = decodeEntities( settings.title || 'VEXPay' );
	const banks = Array.isArray( settings.banks ) ? settings.banks : [];

	const Content = ( props ) => {
		const { eventRegistration, emitResponse } = props;
		const { onPaymentSetup } = eventRegistration;
		const [ debtorId, setDebtorId ] = useState( '' );
		const [ debtorPhone, setDebtorPhone ] = useState( '' );
		const [ debtorBank, setDebtorBank ] = useState( '' );

		useEffect( () => {
			const unsubscribe = onPaymentSetup( () => {
				const id = String( debtorId || '' ).trim();
				const phone = String( debtorPhone || '' ).trim();
				const bank = String( debtorBank || '' ).trim();

				if ( ! id || ! phone || ! bank ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __(
							'Enter cédula/RIF, phone, and bank for VEXPay C2P.',
							'woo-vexpay-gateway'
						),
					};
				}

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							vexpay_debtor_id: id,
							vexpay_debtor_phone: phone,
							vexpay_debtor_bank: bank,
						},
					},
				};
			} );
			return unsubscribe;
		}, [
			onPaymentSetup,
			emitResponse.responseTypes.ERROR,
			emitResponse.responseTypes.SUCCESS,
			debtorId,
			debtorPhone,
			debtorBank,
		] );

		const bankOptions = [
			createElement(
				'option',
				{ key: '', value: '' },
				__( 'Select your bank', 'woo-vexpay-gateway' )
			),
			...banks.map( ( bank ) =>
				createElement(
					'option',
					{ key: bank.code, value: bank.code },
					bank.code + ' — ' + bank.name
				)
			),
		];

		return createElement(
			'div',
			{ className: 'vexpay-blocks-fields' },
			settings.testmode
				? createElement(
						'p',
						{ className: 'vexpay-test-mode' },
						createElement( 'strong', null, __( 'SANDBOX', 'woo-vexpay-gateway' ) )
				  )
				: null,
			settings.description
				? createElement( 'p', null, decodeEntities( settings.description ) )
				: null,
			createElement(
				'label',
				null,
				__( 'Cédula / RIF', 'woo-vexpay-gateway' ),
				createElement( 'input', {
					type: 'text',
					value: debtorId,
					onChange: ( e ) => setDebtorId( e.target.value ),
					placeholder: 'V12345678',
					autoComplete: 'off',
				} )
			),
			createElement(
				'label',
				null,
				__( 'Phone', 'woo-vexpay-gateway' ),
				createElement( 'input', {
					type: 'tel',
					value: debtorPhone,
					onChange: ( e ) => setDebtorPhone( e.target.value ),
					placeholder: '04121234567',
					autoComplete: 'tel',
				} )
			),
			createElement(
				'label',
				null,
				__( 'Bank', 'woo-vexpay-gateway' ),
				createElement(
					'select',
					{
						value: debtorBank,
						onChange: ( e ) => setDebtorBank( e.target.value ),
					},
					bankOptions
				)
			)
		);
	};

	const Label = () =>
		createElement(
			'span',
			{ className: 'vexpay-blocks-label' },
			settings.icon
				? createElement( 'img', {
						src: settings.icon,
						alt: label,
						className: 'vexpay-blocks-icon',
						style: { height: '24px', width: 'auto', marginRight: '0.5em', verticalAlign: 'middle' },
				  } )
				: null,
			label
		);

	registerPaymentMethod( {
		name: 'vexpay',
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
