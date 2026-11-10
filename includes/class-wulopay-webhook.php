<?php
/**
 * Receives WuloPay webhooks at /wp-json/wulopay/v1/webhook.
 *
 * @package WuloPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every request must carry a valid WuloPay-Signature for one of the configured signing secrets (sandbox, live, or the
 * single secret of versions before 1.3.0); anything else gets 400 and changes nothing. A secret set for one environment
 * only accepts that environment's events. The order is found from the session's metadata and must match the session id
 * the plugin stored.
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
		$event = self::verify( $request->get_body(), (string) $request->get_header( 'wulopay_signature' ) );
		if ( null === $event ) {
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 400 );
		}

		$type        = (string) $event['type'];
		$data_object = $event['data']['object'] ?? array();
		if ( 'webhook.test' === $type ) {
			WuloPay_Gateway::log( 'Test webhook ' . $event['id'] . ' received.' );
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		if ( 0 === strpos( $type, 'subscription.' ) ) {
			WuloPay_Subscriptions::webhook( $type, (string) $event['id'], is_array( $data_object ) ? $data_object : array() );
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$found = self::find_order( is_array( $data_object ) ? $data_object : array() );
		$order = $found['order'] ?? null;
		if ( $order && ! $found['current'] ) {
			// An earlier session of this order, replaced when the shopper placed it again. Cancelling it should make
			// paying it impossible; if a payment on it is reported anyway, the merchant must know.
			$seen = (array) $order->get_meta( WuloPay_Gateway::META_EVENTS );
			if ( 'checkout.session.completed' === $type && 'paid' === ( $data_object['status'] ?? '' ) && ! in_array( $event['id'], $seen, true ) ) {
				$seen[] = (string) $event['id'];
				$order->update_meta_data( WuloPay_Gateway::META_EVENTS, array_slice( $seen, -25 ) );
				WuloPay_Gateway::log( 'Order ' . $order->get_id() . ': payment reported on replaced session ' . $data_object['id'] . '.', 'error' );
				$order->add_order_note(
					sprintf(
						/* translators: 1: amount, 2: WuloPay checkout session id */
						__( 'WuloPay reports a payment of %1$s on an earlier checkout of this order (session %2$s) that had been replaced. If the shopper paid twice, refund one payment in the WuloPay merchant portal.', 'wulopay-woocommerce' ),
						wc_price( (float) ( $data_object['amount'] ?? 0 ), array( 'currency' => $order->get_currency() ) ),
						sanitize_text_field( (string) $data_object['id'] )
					)
				);
				$order->save();
			}
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}
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
				self::sync_refund( $order, $data_object );
				break;
			case 'refund.failed':
				$order->add_order_note(
					sprintf(
						/* translators: 1: amount, 2: reason */
						__( 'WuloPay refund of %1$s failed: %2$s', 'wulopay-woocommerce' ),
						isset( $data_object['amount'] ) ? wc_price( (float) $data_object['amount'], array( 'currency' => $order->get_currency() ) ) : '-',
						sanitize_text_field( (string) ( $data_object['error'] ?? '' ) )
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
	 * The verified event, or null. Tries each configured secret; one set for an environment only accepts that
	 * environment's events.
	 *
	 * @param string $payload   Raw request body.
	 * @param string $signature WuloPay-Signature header.
	 */
	private static function verify( string $payload, string $signature ): ?array {
		$settings = get_option( 'woocommerce_' . WuloPay_Gateway::ID . '_settings', array() );
		$secrets  = array(
			'sandbox' => trim( (string) ( $settings['sandbox_webhook_secret'] ?? '' ) ),
			'live'    => trim( (string) ( $settings['live_webhook_secret'] ?? '' ) ),
			''        => trim( (string) ( $settings['webhook_secret'] ?? '' ) ), // Before 1.3.0: one secret for both.
		);
		$error    = 'no signing secret configured';
		foreach ( $secrets as $environment => $secret ) {
			if ( '' === $secret ) {
				continue;
			}
			try {
				$event = \WuloPay\Webhook::constructEvent( $payload, $signature, $secret );
			} catch ( \WuloPay\Exception\SignatureVerificationException $e ) {
				$error = $e->getMessage();
				continue;
			}
			if ( '' !== $environment && '' !== (string) ( $event['environment'] ?? '' ) && $environment !== $event['environment'] ) {
				$error = 'event for ' . $event['environment'] . ' signed with the ' . $environment . ' secret';
				continue;
			}
			return $event;
		}
		WuloPay_Gateway::log( 'Webhook refused: ' . $error, 'warning' );
		return null;
	}

	/**
	 * A refund confirmed by WuloPay. Refunds made from the WooCommerce order screen are already recorded; one made in
	 * the WuloPay merchant portal is added to the order here, so WooCommerce's totals and stock reports agree. The
	 * gap between WuloPay's total refunded and the order's is what gets recorded, so repeats change nothing.
	 *
	 * @param WC_Order $order       The order.
	 * @param array    $data_object Refund event object (sessionId, amount, totalRefunded, remainingRefundable, reason).
	 */
	private static function sync_refund( WC_Order $order, array $data_object ): void {
		if ( ! isset( $data_object['totalRefunded'] ) || ! is_numeric( $data_object['totalRefunded'] ) ) {
			return;
		}
		$missing = round( (float) $data_object['totalRefunded'] - (float) $order->get_total_refunded(), 2 );
		if ( $missing < 0.01 ) {
			return; // Already on the order (refunded from WooCommerce, or a repeat).
		}
		$missing = min( $missing, round( (float) $order->get_total() - (float) $order->get_total_refunded(), 2 ) );
		$refund  = $missing >= 0.01 ? wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $missing,
				'reason'         => __( 'Refunded in the WuloPay merchant portal', 'wulopay-woocommerce' ),
				'refund_payment' => false,
				'restock_items'  => false,
			)
		) : null;
		if ( $refund instanceof WP_Error ) {
			WuloPay_Gateway::log( 'Order ' . $order->get_id() . ': could not record the portal refund: ' . $refund->get_error_message(), 'error' );
			$order->add_order_note(
				sprintf(
					/* translators: %s: amount */
					__( 'WuloPay refunded %s in the merchant portal, but WooCommerce could not record it. Add the refund to this order by hand (without refunding again).', 'wulopay-woocommerce' ),
					wc_price( $missing, array( 'currency' => $order->get_currency() ) )
				)
			);
			return;
		}
		if ( $refund ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: amount */
					__( 'WuloPay refunded %s in the merchant portal; the refund was added to this order. Stock was not changed.', 'wulopay-woocommerce' ),
					wc_price( $missing, array( 'currency' => $order->get_currency() ) )
				)
			);
		}
	}

	/**
	 * The order a session belongs to: by the order id in its metadata, then the session must be the one the plugin
	 * stored ('current') or one it replaced when the shopper placed the order again.
	 *
	 * @param array $data_object Session (or refund) object from the event.
	 * @return array{order: WC_Order, current: bool}|null
	 */
	private static function find_order( array $data_object ): ?array {
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
		if ( ! $order instanceof WC_Order || WuloPay_Gateway::ID !== $order->get_payment_method() || '' === $session_id ) {
			return null;
		}
		if ( hash_equals( (string) $order->get_meta( WuloPay_Gateway::META_SESSION_ID ), $session_id ) ) {
			return array(
				'order'   => $order,
				'current' => true,
			);
		}
		return in_array( $session_id, (array) $order->get_meta( WuloPay_Gateway::META_PREVIOUS ), true ) ? array(
			'order'   => $order,
			'current' => false,
		) : null;
	}
}
