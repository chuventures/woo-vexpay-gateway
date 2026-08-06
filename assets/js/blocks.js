/**
 * WooCommerce Blocks payment method for VEXPay C2P.
 *
 * Vanilla ES compatible with WC Blocks registry (no build step required).
 */
( function () {
	'use strict';

	const { registerPaymentMethod } = wc.wcBlocksRegistry;
	const { createElement, useState, useEffect, useRef } = wp.element;
	const { decodeEntities } = wp.htmlEntities;
	const { __ } = wp.i18n;

	// WC 8.9+ exposes getPaymentMethodData; fall back for older installs.
	const settings =
		typeof wc.wcSettings.getPaymentMethodData === 'function'
			? wc.wcSettings.getPaymentMethodData( 'vexpay', {} )
			: wc.wcSettings.getSetting( 'vexpay_data', {} );
	const label = decodeEntities( settings.title || 'VEXPay' );
	const banks = Array.isArray( settings.banks ) ? settings.banks : [];

	const ID_TYPES = [ 'V', 'J', 'E' ];
	const PHONE_PREFIXES = [ '0412', '0422', '0414', '0424', '0416', '0426' ];

	const BankLogo = ( { bank, className } ) => {
		if ( bank && bank.logoUrl ) {
			return createElement( 'img', {
				className: className || 'vexpay-bank-logo',
				src: bank.logoUrl,
				alt: '',
				width: 32,
				height: 32,
				loading: 'lazy',
				decoding: 'async',
			} );
		}
		const fallback = bank && bank.code ? String( bank.code ).slice( -2 ) : '?';
		return createElement(
			'span',
			{ className: ( className || 'vexpay-bank-logo' ) + ' vexpay-bank-logo--fallback', 'aria-hidden': 'true' },
			fallback
		);
	};

	const BankPicker = ( { value, onChange } ) => {
		const [ open, setOpen ] = useState( false );
		const rootRef = useRef( null );
		const selected = banks.find( ( b ) => String( b.code ) === String( value ) ) || null;

		useEffect( () => {
			if ( ! open ) {
				return undefined;
			}
			const onDocClick = ( event ) => {
				if ( rootRef.current && ! rootRef.current.contains( event.target ) ) {
					setOpen( false );
				}
			};
			const onKey = ( event ) => {
				if ( event.key === 'Escape' ) {
					setOpen( false );
				}
			};
			document.addEventListener( 'mousedown', onDocClick );
			document.addEventListener( 'keydown', onKey );
			return () => {
				document.removeEventListener( 'mousedown', onDocClick );
				document.removeEventListener( 'keydown', onKey );
			};
		}, [ open ] );

		return createElement(
			'div',
			{ className: 'vexpay-bank-picker' + ( open ? ' is-open' : '' ), ref: rootRef },
			createElement(
				'button',
				{
					type: 'button',
					className: 'vexpay-bank-trigger',
					'aria-haspopup': 'listbox',
					'aria-expanded': open ? 'true' : 'false',
					onClick: () => setOpen( ( prev ) => ! prev ),
				},
				createElement(
					'span',
					{ className: 'vexpay-bank-trigger-content' },
					selected
						? createElement(
								'span',
								{ className: 'vexpay-bank-option-inner' },
								createElement( BankLogo, { bank: selected } ),
								createElement(
									'span',
									{ className: 'vexpay-bank-meta' },
									createElement( 'span', { className: 'vexpay-bank-name' }, selected.name ),
									' ',
									createElement( 'span', { className: 'vexpay-bank-code' }, '(' + selected.code + ')' )
								)
						  )
						: createElement(
								'span',
								{ className: 'vexpay-bank-placeholder' },
								__( 'Select your bank', 'woo-vexpay-gateway' )
						  )
				),
				createElement( 'span', { className: 'vexpay-bank-chevron', 'aria-hidden': 'true' } )
			),
			open
				? createElement(
						'ul',
						{ className: 'vexpay-bank-list', role: 'listbox' },
						banks.map( ( bank ) =>
							createElement(
								'li',
								{
									key: bank.code,
									role: 'option',
									className:
										'vexpay-bank-option' +
										( String( value ) === String( bank.code ) ? ' is-selected' : '' ),
									'aria-selected': String( value ) === String( bank.code ) ? 'true' : 'false',
									onClick: () => {
										onChange( bank.code );
										setOpen( false );
									},
								},
								createElement( BankLogo, { bank } ),
								createElement(
									'span',
									{ className: 'vexpay-bank-meta' },
									createElement( 'span', { className: 'vexpay-bank-name' }, bank.name ),
									' ',
									createElement( 'span', { className: 'vexpay-bank-code' }, '(' + bank.code + ')' )
								)
							)
						)
				  )
				: null
		);
	};

	const Content = ( props ) => {
		const { eventRegistration, emitResponse } = props;
		const { onPaymentSetup } = eventRegistration;
		const [ idType, setIdType ] = useState( 'V' );
		const [ idNumber, setIdNumber ] = useState( '' );
		const [ phonePrefix, setPhonePrefix ] = useState( '0412' );
		const [ phoneNumber, setPhoneNumber ] = useState( '' );
		const [ debtorBank, setDebtorBank ] = useState( '' );

		useEffect( () => {
			const unsubscribe = onPaymentSetup( () => {
				const digits = String( idNumber || '' ).replace( /\D+/g, '' );
				const id = String( idType || '' ).toUpperCase() + digits;
				const phone = String( phonePrefix || '' ) + String( phoneNumber || '' ).replace( /\D+/g, '' );
				const bank = String( debtorBank || '' ).trim();

				if ( ! digits || ! phoneNumber || ! bank ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __(
							'Enter cédula/RIF, phone, and bank for VEXPay C2P.',
							'woo-vexpay-gateway'
						),
					};
				}

				if ( ! /^[VJE]\d{6,9}$/i.test( id ) ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __(
							'Enter a valid cédula/RIF number (6–9 digits).',
							'woo-vexpay-gateway'
						),
					};
				}

				if ( ! /^(0412|0422|0414|0424|0416|0426)\d{7}$/.test( phone ) ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __(
							'Enter a valid mobile number (7 digits after the prefix).',
							'woo-vexpay-gateway'
						),
					};
				}

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							vexpay_debtor_id: id,
							vexpay_debtor_id_type: String( idType || 'V' ).toUpperCase(),
							vexpay_debtor_id_number: digits,
							vexpay_debtor_phone: phone,
							vexpay_debtor_phone_prefix: String( phonePrefix || '' ),
							vexpay_debtor_phone_number: String( phoneNumber || '' ).replace( /\D+/g, '' ),
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
			idType,
			idNumber,
			phonePrefix,
			phoneNumber,
			debtorBank,
		] );

		const idTypeOptions = ID_TYPES.map( ( t ) =>
			createElement( 'option', { key: t, value: t }, t )
		);

		const phonePrefixOptions = PHONE_PREFIXES.map( ( p ) =>
			createElement( 'option', { key: p, value: p }, p )
		);

		return createElement(
			'div',
			{ className: 'vexpay-blocks-fields' },
			settings.testmode
				? createElement(
						'p',
						{ className: 'vexpay-test-mode' },
						createElement( 'strong', null, __( 'SANDBOX', 'woo-vexpay-gateway' ) ),
						' — ',
						__(
							"it's giving demo energy. No real money moves here — practice all you want.",
							'woo-vexpay-gateway'
						)
				  )
				: null,
			settings.description
				? createElement( 'p', null, decodeEntities( settings.description ) )
				: null,
			createElement(
				'div',
				{ className: 'vexpay-field-group' },
				createElement(
					'span',
					{ className: 'vexpay-field-label' },
					__( 'Cédula / RIF', 'woo-vexpay-gateway' )
				),
				createElement(
					'div',
					{ className: 'vexpay-split-field' },
					createElement(
						'select',
						{
							className: 'vexpay-split-prefix',
							value: idType,
							onChange: ( e ) => setIdType( e.target.value ),
							'aria-label': __( 'Document type', 'woo-vexpay-gateway' ),
						},
						idTypeOptions
					),
					createElement( 'input', {
						type: 'text',
						className: 'vexpay-split-input',
						inputMode: 'numeric',
						value: idNumber,
						onChange: ( e ) =>
							setIdNumber( String( e.target.value || '' ).replace( /\D+/g, '' ).slice( 0, 9 ) ),
						placeholder: '12345678',
						autoComplete: 'off',
						'aria-label': __( 'Document number', 'woo-vexpay-gateway' ),
					} )
				)
			),
			createElement(
				'div',
				{ className: 'vexpay-field-group' },
				createElement(
					'span',
					{ className: 'vexpay-field-label' },
					__( 'Phone', 'woo-vexpay-gateway' )
				),
				createElement(
					'div',
					{ className: 'vexpay-split-field' },
					createElement(
						'select',
						{
							className: 'vexpay-split-prefix',
							value: phonePrefix,
							onChange: ( e ) => setPhonePrefix( e.target.value ),
							'aria-label': __( 'Phone prefix', 'woo-vexpay-gateway' ),
						},
						phonePrefixOptions
					),
					createElement( 'input', {
						type: 'tel',
						className: 'vexpay-split-input',
						inputMode: 'numeric',
						value: phoneNumber,
						onChange: ( e ) =>
							setPhoneNumber( String( e.target.value || '' ).replace( /\D+/g, '' ).slice( 0, 7 ) ),
						placeholder: '1234567',
						maxLength: 7,
						autoComplete: 'tel-national',
						'aria-label': __( 'Phone number', 'woo-vexpay-gateway' ),
					} )
				)
			),
			createElement(
				'div',
				{ className: 'vexpay-field-group' },
				createElement(
					'span',
					{ className: 'vexpay-field-label' },
					__( 'Bank', 'woo-vexpay-gateway' )
				),
				createElement( BankPicker, {
					value: debtorBank,
					onChange: setDebtorBank,
				} )
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
