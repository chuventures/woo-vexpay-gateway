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
		const [ activeIndex, setActiveIndex ] = useState( -1 );
		const rootRef = useRef( null );
		const listRef = useRef( null );
		const selected = banks.find( ( b ) => String( b.code ) === String( value ) ) || null;

		const openList = ( startIndex ) => {
			const idx =
				typeof startIndex === 'number'
					? startIndex
					: Math.max(
							0,
							banks.findIndex( ( b ) => String( b.code ) === String( value ) )
					  );
			setActiveIndex( idx >= 0 ? idx : 0 );
			setOpen( true );
		};

		const closeList = () => {
			setOpen( false );
			setActiveIndex( -1 );
		};

		const selectIndex = ( index ) => {
			const bank = banks[ index ];
			if ( ! bank ) {
				return;
			}
			onChange( bank.code );
			closeList();
		};

		useEffect( () => {
			if ( ! open || ! listRef.current || activeIndex < 0 ) {
				return;
			}
			const option = listRef.current.querySelector(
				'[data-bank-index="' + activeIndex + '"]'
			);
			if ( option && typeof option.scrollIntoView === 'function' ) {
				option.scrollIntoView( { block: 'nearest' } );
			}
		}, [ open, activeIndex ] );

		useEffect( () => {
			if ( ! open ) {
				return undefined;
			}
			const onDocClick = ( event ) => {
				if ( rootRef.current && ! rootRef.current.contains( event.target ) ) {
					closeList();
				}
			};
			document.addEventListener( 'mousedown', onDocClick );
			return () => {
				document.removeEventListener( 'mousedown', onDocClick );
			};
		}, [ open ] );

		const onTriggerKeyDown = ( event ) => {
			const key = event.key;
			if ( key === 'ArrowDown' || key === 'ArrowUp' ) {
				event.preventDefault();
				if ( ! open ) {
					openList( key === 'ArrowUp' ? banks.length - 1 : 0 );
					return;
				}
				setActiveIndex( ( prev ) => {
					const current = prev < 0 ? 0 : prev;
					if ( key === 'ArrowDown' ) {
						return current >= banks.length - 1 ? 0 : current + 1;
					}
					return current <= 0 ? banks.length - 1 : current - 1;
				} );
				return;
			}
			if ( key === 'Home' && open ) {
				event.preventDefault();
				setActiveIndex( 0 );
				return;
			}
			if ( key === 'End' && open ) {
				event.preventDefault();
				setActiveIndex( banks.length - 1 );
				return;
			}
			if ( ( key === 'Enter' || key === ' ' ) && open && activeIndex >= 0 ) {
				event.preventDefault();
				selectIndex( activeIndex );
				return;
			}
			if ( key === 'Enter' || key === ' ' ) {
				event.preventDefault();
				if ( open ) {
					closeList();
				} else {
					openList();
				}
				return;
			}
			if ( key === 'Escape' && open ) {
				event.preventDefault();
				closeList();
			}
		};

		const activeId =
			open && activeIndex >= 0 && banks[ activeIndex ]
				? 'vexpay-bank-option-' + banks[ activeIndex ].code
				: undefined;

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
					'aria-controls': 'vexpay-bank-listbox',
					'aria-activedescendant': activeId,
					onClick: () => {
						if ( open ) {
							closeList();
						} else {
							openList();
						}
					},
					onKeyDown: onTriggerKeyDown,
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
						{
							id: 'vexpay-bank-listbox',
							className: 'vexpay-bank-list',
							role: 'listbox',
							tabIndex: -1,
							ref: listRef,
						},
						banks.map( ( bank, index ) =>
							createElement(
								'li',
								{
									id: 'vexpay-bank-option-' + bank.code,
									key: bank.code,
									role: 'option',
									'data-bank-index': index,
									className:
										'vexpay-bank-option' +
										( String( value ) === String( bank.code ) ? ' is-selected' : '' ) +
										( index === activeIndex ? ' is-active' : '' ),
									'aria-selected': String( value ) === String( bank.code ) ? 'true' : 'false',
									onMouseEnter: () => setActiveIndex( index ),
									onClick: () => selectIndex( index ),
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
		const saved = settings.savedProfile || {};
		const [ idType, setIdType ] = useState(
			ID_TYPES.includes( String( saved.id_type || '' ).toUpperCase() )
				? String( saved.id_type ).toUpperCase()
				: 'V'
		);
		const [ idNumber, setIdNumber ] = useState(
			String( saved.id_number || '' ).replace( /\D+/g, '' ).slice( 0, 9 )
		);
		const [ phonePrefix, setPhonePrefix ] = useState(
			PHONE_PREFIXES.includes( String( saved.phone_prefix || '' ) )
				? String( saved.phone_prefix )
				: '0412'
		);
		const [ phoneNumber, setPhoneNumber ] = useState(
			String( saved.phone_number || '' ).replace( /\D+/g, '' ).slice( 0, 7 )
		);
		const [ debtorBank, setDebtorBank ] = useState( String( saved.bank || '' ) );

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
			{ className: 'vexpay-blocks-fields vexpay-flow' },
			createElement(
				'ol',
				{ className: 'vexpay-steps', 'aria-label': __( 'Payment steps', 'woo-vexpay-gateway' ) },
				createElement(
					'li',
					{ className: 'vexpay-step is-active' },
					createElement( 'span', { className: 'vexpay-step-num' }, '1' ),
					createElement(
						'span',
						{ className: 'vexpay-step-label' },
						__( 'Your details', 'woo-vexpay-gateway' )
					)
				),
				createElement(
					'li',
					{ className: 'vexpay-step' },
					createElement( 'span', { className: 'vexpay-step-num' }, '2' ),
					createElement(
						'span',
						{ className: 'vexpay-step-label' },
						__( 'OTP code', 'woo-vexpay-gateway' )
					)
				)
			),
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
			createElement(
				'div',
				{ className: 'vexpay-step-panel' },
				createElement(
					'h3',
					{ className: 'vexpay-step-title' },
					__( 'Step 1 — Your details', 'woo-vexpay-gateway' )
				),
				createElement(
					'p',
					{ className: 'vexpay-step-copy' },
					__(
						'Tell us who is paying. Place the order to request the C2P from your bank — then enter the OTP in step 2.',
						'woo-vexpay-gateway'
					)
				),
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
