/* WuloPay payment method for the WooCommerce block checkout (no build step needed). */
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const settings = getSetting( 'wulopay_data', {} );
	const title = decodeEntities( settings.title || 'Card (Visa, Mastercard)' );
	const marks = Array.isArray( settings.acceptMarks ) ? settings.acceptMarks : [];

	// "We accept" and the logo of each way to pay (none when the setting is off or the filter returns no marks).
	const AcceptMarks = () =>
		marks.length
			? createElement(
					'div',
					{ className: 'wulopay-accept', style: { display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '8px', marginTop: '8px' } },
					createElement( 'span', { className: 'wulopay-accept-label' }, decodeEntities( settings.acceptLabel || 'We accept' ) ),
					...marks.map( ( mark ) =>
						createElement( 'img', {
							key: mark.brand || mark.label,
							src: mark.src,
							alt: mark.label,
							title: mark.label,
							width: 52,
							height: 34,
							className: 'wulopay-accept-mark',
							style: { display: 'inline-block', width: '52px', height: '34px', margin: 0 },
						} )
					)
			  )
			: null;

	const Content = () =>
		createElement(
			'div',
			null,
			settings.description ? createElement( 'p', null, decodeEntities( settings.description ) ) : null,
			createElement( AcceptMarks )
		);

	// Title on the left, WuloPay logo on the right (no logo when the wulopay_wc_checkout_logo filter returns '').
	const Label = () =>
		createElement(
			'span',
			{ style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', width: '100%' } },
			createElement( 'span', null, title ),
			settings.logo
				? createElement( 'img', { src: settings.logo, alt: 'WuloPay', className: 'wulopay-logo' } )
				: null
		);

	registerPaymentMethod( {
		name: 'wulopay',
		label: createElement( Label ),
		ariaLabel: title,
		content: createElement( Content ),
		edit: createElement( Content ),
		// Listed in the editor whenever enabled; offered to shoppers only when the store is set up (currency, API key).
		canMakePayment: () => settings.ready !== false,
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )();
