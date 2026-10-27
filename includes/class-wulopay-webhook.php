<?php
/**
 * Receives WuloPay webhooks at /wp-json/wulopay/v1/webhook.
 *
 * @package WuloPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every request must carry a valid WuloPay-Signature for the configured signing secret; anything else gets 400 and
 * changes nothing. The order is found from the session's metadata and must match the session id the plugin stored.
 */
final class WuloPay_Webhook {

	public const NAMESPACE = 'wulopay/v1';
	public const ROUTE     = '/webhook';

	public static function register(): void {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					self::NAMESPACE,
					self::ROUTE,
					array(
						'methods'             => 'POST',
						'callback'            => array( self::class, 'handle' ),
						'permission_callback' => '__return_true', // Authenticated by the signature, checked in handle().
					)
				);
			}
		);
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$settings = get_option( 'woocommerce_' . WuloPay_Gateway::ID . '_settings', array() );
		$secret   = trim( (string) ( $settings['webhook_secret'] ?? '' ) );
		try {
			$event = \WuloPay\Webhook::constructEvent( $request->get_body(), (string) $request->get_header( 'wulopay_signature' ), $secret );
		} catch ( \WuloPay\Exception\SignatureVerificationException $e ) {
			WuloPay_Gateway::log( 'Webhook refused: ' . $e->getMessage(), 'warning' );
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 400 );
		}

		$type        = (string) $event['type'];
		$data_object = $event['data']['object'] ?? array();
		if ( 'webhook.test' === $type ) {
			WuloPay_Gateway::log( 'Test webhook ' . $event['id'] . ' received.' );
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$order = self::find_order( is_array( $data_object ) ? $data_object : array() );
		if ( ! $order ) {
			// Not one of this store's orders (e.g. a session from another integration on the same merchant): acknowledge.
			WuloPay_Gateway::log( 'Webhook ' . $event['id'] . ' (' . $type . '): no matching order.' );
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}
		if ( ( $event['environment'] ?? '' ) !== '' && $order->get_meta( WuloPay_Gateway::META_ENV ) !== '' && $event['environment'] !== $order->get_meta( WuloPay_Gateway::META_ENV ) ) {
			WuloPay_Gateway::log( 'Webhook ' . $event['id'] . ': environment does not match order ' . $order->get_id() . '.', 'warning' );
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		// Deliveries can repeat: handle each event id once per order.
		$seen = (array) $order->get_meta( WuloPay_Gateway::META_EVENTS );
		if ( in_array( $event['id'], $seen, true ) ) {
			return new WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}

		switch ( $type ) {
			case 'checkout.session.completed':
				WuloPay_Gateway::apply_session( $order, $data_object, 'webhook' );
				break;
			case 'checkout.session.payment_failed':
				$order->add_order_note(
					sprintf(
						/* translators: %s: reason shown to the shopper */
						__( 'WuloPay payment attempt failed: %s The shopper can try again until the payment link expires.', 'wulopay-woocommerce' ),
						sanitize_text_field( (string) ( $data_object['lastPaymentError'] ?? '' ) )
					)
				);
				break;
			case 'checkout.session.expired':
				if ( $order->needs_payment() ) {
					$order->add_order_note( __( 'The WuloPay payment link expired without payment.', 'wulopay-woocommerce' ) );
				}
				break;
			case 'refund.succeeded':
			case 'refund.failed':
				$order->add_order_note(
					sprintf(
						/* translators: 1: event type, 2: amount */
						__( 'WuloPay %1$s: %2$s.', 'wulopay-woocommerce' ),
						'refund.succeeded' === $type ? __( 'refund succeeded', 'wulopay-woocommerce' ) : __( 'refund failed', 'wulopay-woocommerce' ),
						isset( $data_object['amount'] ) ? wc_price( (float) $data_object['amount'], array( 'currency' => $order->get_currency() ) ) : '-'
					)
				);
				break;
		}

		$seen[] = (string) $event['id'];
		$order->update_meta_data( WuloPay_Gateway::META_EVENTS, array_slice( $seen, -25 ) );
		$order->save();
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * The order a session belongs to: by the order id in its metadata, then the stored session id must match.
	 *
	 * @param array $data_object Session (or refund) object from the event.
	 */
	private static function find_order( array $data_object ): ?WC_Order {
		$session_id = (string) ( $data_object['id'] ?? $data_object['sessionId'] ?? '' );
		$order_id   = absint( $data_object['metadata']['wc_order_id'] ?? 0 );
		$order      = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order && '' !== $session_id ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'meta_key'   => WuloPay_Gateway::META_SESSION_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $session_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			$order  = $orders[0] ?? null;
		}
		if ( ! $order instanceof WC_Order || WuloPay_Gateway::ID !== $order->get_payment_method() ) {
			return null;
		}
		return hash_equals( (string) $order->get_meta( WuloPay_Gateway::META_SESSION_ID ), $session_id ) ? $order : null;
	}
}
