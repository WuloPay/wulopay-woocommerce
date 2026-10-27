/* WuloPay payment method for the WooCommerce block checkout (no build step needed). */
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const settings = getSetting( 'wulopay_data', {} );
	const title = decodeEntities( settings.title || 'Card (Visa, Mastercard)' );
	const Content = () => createElement( 'p', null, decodeEntities( settings.description || '' ) );

	registerPaymentMethod( {
		name: 'wulopay',
		label: createElement( 'span', null, title ),
		ariaLabel: title,
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: () => true,
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )();
