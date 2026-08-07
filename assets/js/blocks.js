/**
 * WooCommerce Blocks payment method for VEXPay Débito inmediato.
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

	const formatDebtorIdDisplay = ( type, number ) => {
		const t = String( type || '' ).toUpperCase();
		const digits = String( number || '' ).replace( /\D+/g, '' );
		if ( ! t && ! digits ) {
			return '';
		}
		if ( ! digits ) {
			return t;
		}
		let grouped = '';
		let rest = digits;
		while ( rest.length > 3 ) {
			grouped = '.' + rest.slice( -3 ) + grouped;
			rest = rest.slice( 0, -3 );
		}
		grouped = rest + grouped;
		return t + '-' + grouped;
	};

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
		const normBank = ( code ) =>
			String( code || '' )
				.replace( /\D+/g, '' )
				.padStart( 4, '0' )
				.slice( -4 );
		const selected =
			banks.find( ( b ) => normBank( b.code ) === normBank( value ) ) || null;

		const openList = ( startIndex ) => {
			const idx =
				typeof startIndex === 'number'
					? startIndex
					: Math.max(
							0,
							banks.findIndex( ( b ) => normBank( b.code ) === normBank( value ) )
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
		const initialAccounts = Array.isArray( settings.savedAccounts )
			? settings.savedAccounts
			: [];
		const fingerprint = ( profile ) =>
			(
				String( profile.id_type || '' ).toUpperCase() +
				String( profile.id_number || '' ) +
				'|' +
				String( profile.phone_prefix || '' ) +
				String( profile.phone_number || '' ) +
				'|' +
				String( profile.bank || '' )
			).toLowerCase();

		const [ accounts, setAccounts ] = useState( initialAccounts );
		const [ removingId, setRemovingId ] = useState( null );
		const hasSavedAccounts = accounts.length > 0;
		const startedWithAccounts = initialAccounts.length > 0;
		const [ idType, setIdType ] = useState(
			! startedWithAccounts && ID_TYPES.includes( String( saved.id_type || '' ).toUpperCase() )
				? String( saved.id_type ).toUpperCase()
				: 'V'
		);
		const [ idNumber, setIdNumber ] = useState(
			! startedWithAccounts
				? String( saved.id_number || '' ).replace( /\D+/g, '' ).slice( 0, 9 )
				: ''
		);
		const [ phonePrefix, setPhonePrefix ] = useState(
			! startedWithAccounts && PHONE_PREFIXES.includes( String( saved.phone_prefix || '' ) )
				? String( saved.phone_prefix )
				: '0412'
		);
		const [ phoneNumber, setPhoneNumber ] = useState(
			! startedWithAccounts
				? String( saved.phone_number || '' ).replace( /\D+/g, '' ).slice( 0, 7 )
				: ''
		);
		const [ debtorBank, setDebtorBank ] = useState(
			! startedWithAccounts ? String( saved.bank || '' ) : ''
		);
		// Pick a chip (or “Use another”) before the detail fields appear.
		const [ activeAccount, setActiveAccount ] = useState(
			startedWithAccounts ? null : 'new'
		);
		const [ showDetails, setShowDetails ] = useState( ! startedWithAccounts );

		const applyAccount = ( account ) => {
			if ( ! account ) {
				setIdType( 'V' );
				setIdNumber( '' );
				setPhonePrefix( '0412' );
				setPhoneNumber( '' );
				setDebtorBank( '' );
				setActiveAccount( 'new' );
				setShowDetails( true );
				return;
			}
			const id = fingerprint( account );
			setIdType(
				ID_TYPES.includes( String( account.id_type || '' ).toUpperCase() )
					? String( account.id_type ).toUpperCase()
					: 'V'
			);
			setIdNumber( String( account.id_number || '' ).replace( /\D+/g, '' ).slice( 0, 9 ) );
			setPhonePrefix(
				PHONE_PREFIXES.includes( String( account.phone_prefix || '' ) )
					? String( account.phone_prefix )
					: '0412'
			);
			setPhoneNumber( String( account.phone_number || '' ).replace( /\D+/g, '' ).slice( 0, 7 ) );
			setDebtorBank( String( account.bank || '' ) );
			setActiveAccount( id );
			// Saved chip selected → collapse the form (Use another expands it again).
			setShowDetails( false );
		};

		const markCustom = () => {
			if ( activeAccount !== 'new' ) {
				setActiveAccount( 'new' );
			}
			setShowDetails( true );
		};

		const removeAccount = ( account ) => {
			const id = fingerprint( account );
			const deleteCfg = settings.deleteAccount || {};
			const ajaxUrl = settings.ajaxUrl || '';
			if ( ! ajaxUrl || ! deleteCfg.action || ! deleteCfg.nonce || removingId ) {
				return;
			}

			const wasActive = activeAccount === id;
			setRemovingId( id );
			const body = new window.URLSearchParams();
			body.set( 'action', deleteCfg.action );
			body.set( 'nonce', deleteCfg.nonce );
			body.set( 'id_type', String( account.id_type || '' ) );
			body.set( 'id_number', String( account.id_number || '' ) );
			body.set( 'phone_prefix', String( account.phone_prefix || '' ) );
			body.set( 'phone_number', String( account.phone_number || '' ) );
			body.set( 'bank', String( account.bank || '' ) );

			window
				.fetch( ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
					},
					body: body.toString(),
				} )
				.then( ( res ) => res.json() )
				.then( ( data ) => {
					if ( ! data || ! data.success ) {
						throw new Error( 'delete failed' );
					}
					let shouldClear = wasActive;
					setAccounts( ( prev ) => {
						const next = prev.filter( ( row ) => fingerprint( row ) !== id );
						if ( next.length === 0 ) {
							shouldClear = true;
						}
						return next;
					} );
					if ( shouldClear ) {
						applyAccount( null );
					}
				} )
				.catch( () => {
					window.alert(
						( settings.i18n && settings.i18n.removeFailed ) ||
							__( 'Could not remove saved account.', 'woo-vexpay-gateway' )
					);
				} )
				.finally( () => {
					setRemovingId( null );
				} );
		};

		useEffect( () => {
			const unsubscribe = onPaymentSetup( () => {
				if ( hasSavedAccounts && ! activeAccount ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __(
							'Pick a saved account or tap + Use another.',
							'woo-vexpay-gateway'
						),
					};
				}

				const digits = String( idNumber || '' ).replace( /\D+/g, '' );
				const id = String( idType || '' ).toUpperCase() + digits;
				const phone = String( phonePrefix || '' ) + String( phoneNumber || '' ).replace( /\D+/g, '' );
				const bank = String( debtorBank || '' ).trim();

				if ( ! digits || ! phoneNumber || ! bank ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __(
							'Enter cédula/RIF, phone, and bank for VEXPay.',
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
			hasSavedAccounts,
			showDetails,
			activeAccount,
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
			{ className: 'vexpay-blocks-fields vexpay-flow vexpay-checkout-panel' },
			createElement(
				'div',
				{ className: 'vexpay-checkout-brand' },
				settings.icon
					? createElement( 'img', {
							className: 'vexpay-checkout-brand__logo',
							src: settings.icon,
							alt: 'VEXPay',
							width: 72,
							height: 38,
							decoding: 'async',
					  } )
					: null,
				createElement(
					'span',
					{ className: 'vexpay-checkout-brand__tag' },
					__( 'Débito inmediato', 'woo-vexpay-gateway' )
				)
			),
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
					__( 'Your details', 'woo-vexpay-gateway' )
				),
				createElement(
					'p',
					{ className: 'vexpay-step-copy' },
					hasSavedAccounts && ! showDetails && ! activeAccount
						? __(
								'Pick a saved account to pay — or tap + Use another to enter new details.',
								'woo-vexpay-gateway'
						  )
						: hasSavedAccounts && ! showDetails
						  ? __(
									'Paying with this account. Tap + Use another if you need different details.',
									'woo-vexpay-gateway'
						    )
						  : __(
									'Who’s paying? Drop your cédula, phone, and bank — next you’ll send the OTP from your bank.',
									'woo-vexpay-gateway'
						    )
				),
				settings.quote && settings.quote.vesAmount
					? createElement(
							'div',
							{ className: 'vexpay-fx-strip' },
							createElement(
								'span',
								{ className: 'vexpay-fx-strip__ves' },
								__( 'Bs.', 'woo-vexpay-gateway' ) +
									' ' +
									Number( settings.quote.vesAmount ).toLocaleString( undefined, {
										minimumFractionDigits: 2,
										maximumFractionDigits: 2,
									} )
							),
							createElement(
								'span',
								{ className: 'vexpay-fx-strip__rate' },
								__( 'BCV', 'woo-vexpay-gateway' ) +
									' ' +
									Number( settings.quote.bcvRate ).toLocaleString( undefined, {
										minimumFractionDigits: 4,
										maximumFractionDigits: 4,
									} )
							)
					  )
					: null,
				accounts.length
					? createElement(
							'div',
							{ className: 'vexpay-accounts' },
							createElement(
								'div',
								{ className: 'vexpay-accounts__head' },
								createElement(
									'span',
									{ className: 'vexpay-accounts__label' },
									__( 'Saved accounts', 'woo-vexpay-gateway' )
								),
								createElement(
									'button',
									{
										type: 'button',
										className:
											'vexpay-accounts__new' +
											( activeAccount === 'new' ? ' is-active' : '' ),
										onClick: () => applyAccount( null ),
									},
									__( '+ Use another', 'woo-vexpay-gateway' )
								)
							),
							createElement(
								'div',
								{
									className: 'vexpay-accounts__list',
									role: 'listbox',
									'aria-label': __( 'Saved payer accounts', 'woo-vexpay-gateway' ),
								},
								accounts.map( ( account ) => {
									const id = fingerprint( account );
									const isActive = activeAccount === id;
									const digits = String( account.phone_number || '' );
									const phoneBit =
										String( account.phone_prefix || '' ) +
										( digits.length >= 4
											? '•••' + digits.slice( -4 )
											: digits );
									const meta = [ phoneBit, account.bankName || account.bank ]
										.filter( Boolean )
										.join( ' · ' );
									return createElement(
										'div',
										{
											key: id,
											role: 'option',
											'aria-selected': isActive ? 'true' : 'false',
											className:
												'vexpay-account-chip' + ( isActive ? ' is-active' : '' ),
										},
										createElement(
											'button',
											{
												type: 'button',
												className: 'vexpay-account-chip__body',
												onClick: () => applyAccount( account ),
											},
											account.bankLogo
												? createElement( 'img', {
														className: 'vexpay-account-chip__logo',
														src: account.bankLogo,
														alt: '',
														width: 28,
														height: 28,
														loading: 'lazy',
														decoding: 'async',
												  } )
												: createElement(
														'span',
														{
															className:
																'vexpay-account-chip__logo vexpay-account-chip__logo--fallback',
															'aria-hidden': 'true',
														},
														String( account.bank || '??' ).slice( -2 )
												  ),
											createElement(
												'span',
												{ className: 'vexpay-account-chip__text' },
												createElement(
													'span',
													{ className: 'vexpay-account-chip__title' },
													formatDebtorIdDisplay(
														account.id_type,
														account.id_number
													)
												),
												createElement(
													'span',
													{ className: 'vexpay-account-chip__meta' },
													meta
												)
											)
										),
										createElement(
											'button',
											{
												type: 'button',
												className: 'vexpay-account-chip__remove',
												'aria-label':
													( settings.i18n && settings.i18n.removeAccount ) ||
													__( 'Remove saved account', 'woo-vexpay-gateway' ),
												disabled: removingId === id,
												onClick: ( event ) => {
													event.preventDefault();
													event.stopPropagation();
													removeAccount( account );
												},
											},
											'×'
										)
									);
								} )
							)
					  )
					: null,
				showDetails
					? createElement(
							'div',
							{ className: 'vexpay-account-details' },
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
											onChange: ( e ) => {
												setIdType( e.target.value );
												markCustom();
											},
											'aria-label': __( 'Document type', 'woo-vexpay-gateway' ),
										},
										idTypeOptions
									),
									createElement( 'input', {
										type: 'text',
										className: 'vexpay-split-input',
										inputMode: 'numeric',
										value: idNumber,
										onChange: ( e ) => {
											setIdNumber(
												String( e.target.value || '' )
													.replace( /\D+/g, '' )
													.slice( 0, 9 )
											);
											markCustom();
										},
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
											onChange: ( e ) => {
												setPhonePrefix( e.target.value );
												markCustom();
											},
											'aria-label': __( 'Phone prefix', 'woo-vexpay-gateway' ),
										},
										phonePrefixOptions
									),
									createElement( 'input', {
										type: 'tel',
										className: 'vexpay-split-input',
										inputMode: 'numeric',
										value: phoneNumber,
										onChange: ( e ) => {
											setPhoneNumber(
												String( e.target.value || '' )
													.replace( /\D+/g, '' )
													.slice( 0, 7 )
											);
											markCustom();
										},
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
									onChange: ( code ) => {
										setDebtorBank( code );
										markCustom();
									},
								} )
							)
					  )
					: null,
				createElement(
					'p',
					{ className: 'vexpay-checkout-secure' },
					createElement( 'span', {
						className: 'vexpay-checkout-secure__lock',
						'aria-hidden': 'true',
					} ),
					__( 'Secured by VEXPay', 'woo-vexpay-gateway' )
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
						alt: '',
						className: 'vexpay-blocks-icon',
				  } )
				: null,
			createElement(
				'span',
				{ className: 'vexpay-blocks-label__text' },
				createElement( 'span', { className: 'vexpay-blocks-label__title' }, label ),
				createElement(
					'span',
					{ className: 'vexpay-blocks-label__hint' },
					__( 'VEXPay · Débito inmediato', 'woo-vexpay-gateway' )
				)
			)
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
