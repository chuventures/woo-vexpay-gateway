/**
 * WooCommerce Blocks payment method for VEXPay Tarjeta (VPOS).
 *
 * Vanilla ES compatible with WC Blocks registry (no build step required).
 */
( function () {
	'use strict';

	const { registerPaymentMethod } = wc.wcBlocksRegistry;
	const { createElement, useState } = wp.element;
	const { decodeEntities } = wp.htmlEntities;
	const { __ } = wp.i18n;

	const settings =
		typeof wc.wcSettings.getPaymentMethodData === 'function'
			? wc.wcSettings.getPaymentMethodData( 'vexpay_vpos', {} )
			: wc.wcSettings.getSetting( 'vexpay_vpos_data', {} );

	const label = decodeEntities( settings.title || __( 'Tarjeta (VEXPay)', 'vexpay-gateway-for-woocommerce' ) );
	const idTypes = Array.isArray( settings.idTypes ) ? settings.idTypes : [ 'V', 'J', 'E' ];
	const cardBrands = settings.cardBrands && typeof settings.cardBrands === 'object' ? settings.cardBrands : { 1: 'Visa', 2: 'Mastercard', 3: 'Maestro' };
	const accountTypes =
		settings.accountTypes && typeof settings.accountTypes === 'object'
			? settings.accountTypes
			: { 0: 'Crédito', 10: 'Débito · Ahorro', 20: 'Débito · Corriente' };

	const formatCardNumber = ( raw ) =>
		String( raw || '' )
			.replace( /\D+/g, '' )
			.slice( 0, 19 )
			.replace( /(.{4})/g, '$1 ' )
			.trim();

	const formatExpiry = ( raw ) => {
		const digits = String( raw || '' ).replace( /\D+/g, '' ).slice( 0, 4 );
		return digits.length > 2 ? digits.slice( 0, 2 ) + '/' + digits.slice( 2 ) : digits;
	};

	const parseExpiry = ( raw ) => {
		const digits = String( raw || '' ).replace( /\D+/g, '' );
		if ( digits.length < 3 ) {
			return null;
		}
		const month = parseInt( digits.slice( 0, 2 ), 10 );
		const year = 2000 + parseInt( digits.slice( 2, 4 ), 10 );
		if ( ! Number.isInteger( month ) || month < 1 || month > 12 ) {
			return null;
		}
		if ( ! Number.isInteger( year ) || year < 2020 || year > 2099 ) {
			return null;
		}
		return { month: month, year: year };
	};

	const Content = ( props ) => {
		const { eventRegistration, emitResponse } = props;
		const { onPaymentSetup } = eventRegistration;

		const [ cardNumber, setCardNumber ] = useState( '' );
		const [ expiry, setExpiry ] = useState( '' );
		const [ cvv, setCvv ] = useState( '' );
		const [ brand, setBrand ] = useState( '' );
		const [ accountType, setAccountType ] = useState( '0' );
		const [ holderName, setHolderName ] = useState( '' );
		const [ idType, setIdType ] = useState( idTypes[ 0 ] || 'V' );
		const [ idNumber, setIdNumber ] = useState( '' );

		wp.element.useEffect( () => {
			const unsubscribe = onPaymentSetup( () => {
				const digits = cardNumber.replace( /\D+/g, '' );
				if ( digits.length < 13 || digits.length > 19 ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __( 'Enter a valid card number.', 'vexpay-gateway-for-woocommerce' ),
					};
				}
				if ( ! parseExpiry( expiry ) ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __( 'Enter a valid expiry date (MM/YY).', 'vexpay-gateway-for-woocommerce' ),
					};
				}
				if ( ! /^\d{3,4}$/.test( cvv ) ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __( 'Enter a valid CVV.', 'vexpay-gateway-for-woocommerce' ),
					};
				}
				if ( ! brand ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __( 'Select the card brand.', 'vexpay-gateway-for-woocommerce' ),
					};
				}
				if ( holderName.trim().length < 2 ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __( 'Enter the cardholder name.', 'vexpay-gateway-for-woocommerce' ),
					};
				}
				const idDigits = idNumber.replace( /\D+/g, '' );
				if ( ! /^\d{6,9}$/.test( idDigits ) ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __( 'Enter a valid cédula/RIF number (6–9 digits).', 'vexpay-gateway-for-woocommerce' ),
					};
				}

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							vexpay_vpos_card_number: digits,
							vexpay_vpos_expiry: expiry,
							vexpay_vpos_cvv: cvv,
							vexpay_vpos_brand: brand,
							vexpay_vpos_account_type: accountType,
							vexpay_vpos_holder_name: holderName.trim(),
							vexpay_vpos_holder_id_type: idType,
							vexpay_vpos_holder_id_number: idDigits,
						},
					},
				};
			} );
			return unsubscribe;
		}, [ onPaymentSetup, emitResponse.responseTypes.ERROR, emitResponse.responseTypes.SUCCESS, cardNumber, expiry, cvv, brand, accountType, holderName, idType, idNumber ] );

		return createElement(
			'div',
			{ className: 'vexpay-flow vexpay-blocks-panel' },
			settings.testmode
				? createElement(
						'p',
						{ className: 'vexpay-test-mode' },
						createElement( 'strong', null, __( 'SANDBOX', 'vexpay-gateway-for-woocommerce' ) ),
						' — ' + __( 'No real money moves here. Use a VEXPay sandbox test card.', 'vexpay-gateway-for-woocommerce' )
				  )
				: null,
			settings.quote
				? createElement(
						'div',
						{ className: 'vexpay-fx-strip' },
						createElement(
							'span',
							{ className: 'vexpay-fx-strip__ves' },
							'Bs. ' + Number( settings.quote.vesAmount ).toFixed( 2 )
						),
						createElement(
							'span',
							{ className: 'vexpay-fx-strip__rate' },
							'BCV ' + Number( settings.quote.bcvRate ).toFixed( 4 )
						)
				  )
				: null,
			createElement(
				'fieldset',
				{ className: 'wc-payment-form vexpay-fields vexpay-card-details' },
				createElement(
					'div',
					{ className: 'vexpay-field-group' },
					createElement( 'label', { htmlFor: 'vexpay-vpos-card-number' }, __( 'Card number', 'vexpay-gateway-for-woocommerce' ) ),
					createElement( 'input', {
						id: 'vexpay-vpos-card-number',
						type: 'text',
						inputMode: 'numeric',
						autoComplete: 'cc-number',
						placeholder: '4111 1111 1111 1111',
						maxLength: 24,
						value: cardNumber,
						onChange: ( e ) => setCardNumber( formatCardNumber( e.target.value ) ),
					} )
				),
				createElement(
					'div',
					{ className: 'vexpay-field-group' },
					createElement( 'label', { htmlFor: 'vexpay-vpos-expiry' }, __( 'Expiry (MM/YY)', 'vexpay-gateway-for-woocommerce' ) ),
					createElement( 'input', {
						id: 'vexpay-vpos-expiry',
						type: 'text',
						inputMode: 'numeric',
						autoComplete: 'cc-exp',
						placeholder: 'MM/AA',
						maxLength: 5,
						value: expiry,
						onChange: ( e ) => setExpiry( formatExpiry( e.target.value ) ),
					} ),
					createElement( 'label', { htmlFor: 'vexpay-vpos-cvv' }, __( 'CVV', 'vexpay-gateway-for-woocommerce' ) ),
					createElement( 'input', {
						id: 'vexpay-vpos-cvv',
						type: 'text',
						inputMode: 'numeric',
						autoComplete: 'cc-csc',
						placeholder: '123',
						maxLength: 4,
						value: cvv,
						onChange: ( e ) => setCvv( e.target.value.replace( /\D+/g, '' ).slice( 0, 4 ) ),
					} )
				),
				createElement(
					'div',
					{ className: 'vexpay-field-group' },
					createElement( 'label', { htmlFor: 'vexpay-vpos-brand' }, __( 'Card brand', 'vexpay-gateway-for-woocommerce' ) ),
					createElement(
						'select',
						{
							id: 'vexpay-vpos-brand',
							value: brand,
							onChange: ( e ) => setBrand( e.target.value ),
						},
						createElement( 'option', { value: '' }, __( 'Select…', 'vexpay-gateway-for-woocommerce' ) ),
						Object.keys( cardBrands ).map( ( code ) =>
							createElement( 'option', { key: code, value: code }, cardBrands[ code ] )
						)
					),
					createElement( 'label', { htmlFor: 'vexpay-vpos-account-type' }, __( 'Account type', 'vexpay-gateway-for-woocommerce' ) ),
					createElement(
						'select',
						{
							id: 'vexpay-vpos-account-type',
							value: accountType,
							onChange: ( e ) => setAccountType( e.target.value ),
						},
						Object.keys( accountTypes ).map( ( code ) =>
							createElement( 'option', { key: code, value: code }, accountTypes[ code ] )
						)
					)
				),
				createElement(
					'div',
					{ className: 'vexpay-field-group' },
					createElement( 'label', { htmlFor: 'vexpay-vpos-holder-name' }, __( 'Cardholder name', 'vexpay-gateway-for-woocommerce' ) ),
					createElement( 'input', {
						id: 'vexpay-vpos-holder-name',
						type: 'text',
						autoComplete: 'cc-name',
						value: holderName,
						onChange: ( e ) => setHolderName( e.target.value ),
					} )
				),
				createElement(
					'div',
					{ className: 'vexpay-field-group' },
					createElement( 'label', { htmlFor: 'vexpay-vpos-id-number' }, __( 'Cardholder cédula / RIF', 'vexpay-gateway-for-woocommerce' ) ),
					createElement(
						'span',
						{ className: 'vexpay-split-field' },
						createElement(
							'select',
							{
								className: 'vexpay-split-prefix',
								'aria-label': __( 'Document type', 'vexpay-gateway-for-woocommerce' ),
								value: idType,
								onChange: ( e ) => setIdType( e.target.value ),
							},
							idTypes.map( ( t ) => createElement( 'option', { key: t, value: t }, t ) )
						),
						createElement( 'input', {
							id: 'vexpay-vpos-id-number',
							className: 'vexpay-split-input',
							type: 'text',
							inputMode: 'numeric',
							autoComplete: 'off',
							placeholder: '12345678',
							maxLength: 9,
							'aria-label': __( 'Document number', 'vexpay-gateway-for-woocommerce' ),
							value: idNumber,
							onChange: ( e ) => setIdNumber( e.target.value.replace( /\D+/g, '' ).slice( 0, 9 ) ),
						} )
					)
				)
			),
			createElement(
				'p',
				{ className: 'vexpay-checkout-secure' },
				createElement( 'span', { className: 'vexpay-checkout-secure__lock', 'aria-hidden': 'true' } ),
				__( 'Secured by VEXPay', 'vexpay-gateway-for-woocommerce' )
			)
		);
	};

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
					__( 'VEXPay · Tarjeta', 'vexpay-gateway-for-woocommerce' )
				)
			)
		);

	registerPaymentMethod( {
		name: 'vexpay_vpos',
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
