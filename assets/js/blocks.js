/* WuloPay payment method for the WooCommerce block checkout (no build step needed). */
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const settings = getSetting( 'wulopay_data', {} );
	const title = decodeEntities( settings.title || 'Card (Visa, Mastercard)' );
	const Content = () => createElement( 'p', null, decodeEntities( settings.description || '' ) );
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
