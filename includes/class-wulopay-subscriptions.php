<?php
/**
 * WooCommerce Subscriptions support.
 *
 * WooCommerce Subscriptions keeps the schedule (renewal dates, trials, date and status changes); WuloPay keeps the card.
 * At sign-up the shopper agrees to the subscription's terms on WuloPay's payment page and the card is stored there
 * (merchant billing: WuloPay never charges on its own). Each renewal WooCommerce Subscriptions creates is charged through
 * the WuloPay API with an idempotency key, so a renewal is never charged twice.
 *
 * @package WuloPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks and helpers for WooCommerce Subscriptions (loaded only when that extension is active).
 */
final class WuloPay_Subscriptions {

	/** WuloPay subscription id, stored on the WooCommerce subscription (and its parent order). */
	public const META_SUBSCRIPTION_ID = '_wulopay_subscription_id';
	/** Renewal orders: attempt counter for the charge idempotency key (a decline lets the retry use a new key). */
	public const META_CHARGE_ATTEMPT = '_wulopay_charge_attempt';
	/** Plans created for the store's subscription terms, by a hash of the terms. */
	private const OPTION_PLANS = 'wulopay_wc_plans';

	/**
	 * Features declared to WooCommerce Subscriptions. Not declared: amount changes (charges are capped at the amount the
	 * shopper agreed to) and multiple subscriptions per order (one stored card and agreement per subscription).
	 */
	public const SUPPORTS = array(
		'subscriptions',
		'subscription_cancellation',
		'subscription_suspension',
		'subscription_reactivation',
		'subscription_date_changes',
		'subscription_payment_method_change',
		'subscription_payment_method_change_customer',
	);

	/** True when WooCommerce Subscriptions is active. */
	public static function active(): bool {
		return function_exists( 'wcs_order_contains_subscription' ) && function_exists( 'wcs_get_subscriptions_for_order' );
	}

	public static function register(): void {
		if ( ! self::active() ) {
			return;
		}
		add_action( 'woocommerce_scheduled_subscription_payment_' . WuloPay_Gateway::ID, array( __CLASS__, 'scheduled_payment' ), 10, 2 );
		add_action( 'woocommerce_subscription_status_cancelled', array( __CLASS__, 'ended' ) );
		add_action( 'woocommerce_subscription_status_expired', array( __CLASS__, 'ended' ) );
	}

	/**
	 * A sign-up order: a parent order that creates a subscription.
	 *
	 * @param WC_Order $order The order.
	 */
	public static function is_signup( WC_Order $order ): bool {
		return self::active() && wcs_order_contains_subscription( $order, array( 'parent' ) );
	}

	/**
	 * The shopper is changing the payment method of an existing subscription (process_payment gets the subscription).
	 *
	 * @param WC_Order $order The order or subscription.
	 */
	public static function is_method_change( WC_Order $order ): bool {
		return self::active() && function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order );
	}

	/** The bundled or installed library supports subscriptions (an older copy loaded by another plugin might not). */
	public static function library_ok( \WuloPay\Client $client ): bool {
		return property_exists( $client, 'subscriptions' ) && property_exists( $client, 'plans' );
	}

	/**
	 * Checkout session parameters for a sign-up order: the subscription's recurring terms become a WuloPay plan, the
	 * order total is what the shopper pays today (0 for a free trial: the card is only checked and stored).
	 *
	 * @param WC_Order        $order  Parent order.
	 * @param array           $params Base session parameters (amount, reference, URLs, customer, metadata).
	 * @param \WuloPay\Client $client API client.
	 * @return array Session parameters.
	 * @throws RuntimeException When the order can't be paid as a WuloPay subscription (message is shown to the shopper).
	 */
	public static function signup_params( WC_Order $order, array $params, \WuloPay\Client $client ): array {
		$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
		if ( 1 !== count( $subscriptions ) ) {
			throw new RuntimeException( esc_html__( 'Please check out one subscription at a time with card payments.', 'wulopay-woocommerce' ) );
		}
		if ( empty( $params['customer']['email'] ) ) {
			throw new RuntimeException( esc_html__( 'An email address is needed for a subscription.', 'wulopay-woocommerce' ) );
		}
		$subscription = reset( $subscriptions );
		$plan_id      = self::plan_id( $subscription, $client );

		$recurring                                = round( (float) $subscription->get_total(), 2 );
		$params['amount']                         = round( (float) $order->get_total(), 2 );
		$params['mode']                           = 'subscription';
		$params['subscription']                   = array(
			'planId'      => $plan_id,
			'billingMode' => 'merchant',
			'maxAmount'   => $recurring,
			'firstAmount' => $params['amount'],
		);
		$params['metadata']['wc_subscription_id'] = (string) $subscription->get_id();
		return $params;
	}

	/**
	 * The WuloPay plan for a subscription's recurring terms, created once per distinct set of terms.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 * @param \WuloPay\Client $client       API client.
	 */
	private static function plan_id( $subscription, \WuloPay\Client $client ): string {
		$period   = (string) $subscription->get_billing_period();
		$interval = max( 1, (int) $subscription->get_billing_interval() );
		if ( ! in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) ) {
			throw new RuntimeException( esc_html__( 'This subscription schedule is not supported for card payments.', 'wulopay-woocommerce' ) );
		}
		$names = array();
		foreach ( $subscription->get_items() as $item ) {
			$names[] = $item->get_name();
		}
		$name   = substr( '' !== implode( ', ', $names ) ? implode( ', ', $names ) : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 0, 120 );
		$amount = round( (float) $subscription->get_total(), 2 );
		$cycles = self::cycles( $subscription, $interval );
		$terms  = array(
			'name'          => $name,
			'amount'        => $amount,
			'currency'      => $subscription->get_currency(),
			'interval'      => $period,
			'intervalCount' => $interval,
			'cycles'        => $cycles,
		);
		$key    = WuloPay_Gateway::environment() . ':' . md5( (string) wp_json_encode( $terms ) );
		$plans  = (array) get_option( self::OPTION_PLANS, array() );
		if ( ! empty( $plans[ $key ] ) ) {
			return (string) $plans[ $key ];
		}
		$plan          = $client->plans->create( $terms );
		$plans[ $key ] = (string) $plan['id'];
		update_option( self::OPTION_PLANS, array_slice( $plans, -500, null, true ), false );
		WuloPay_Gateway::log( 'Created WuloPay plan ' . $plan['id'] . ' for "' . $name . '".' );
		return (string) $plan['id'];
	}

	/**
	 * Number of payments for a fixed-length subscription (null = until cancelled), from the product's length.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 * @param int             $interval     Billing interval.
	 */
	private static function cycles( $subscription, int $interval ): ?int {
		if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
			return null;
		}
		foreach ( $subscription->get_items() as $item ) {
			$length = (int) WC_Subscriptions_Product::get_length( $item->get_product() );
			if ( $length > 0 ) {
				return max( 1, intdiv( $length, $interval ) );
			}
		}
		return null;
	}

	/**
	 * Changing the payment method of a subscription: a card-update page when WuloPay already holds a card for it, else a
	 * sign-up that only checks and stores the card (switching from another payment method).
	 *
	 * @param WC_Subscription $subscription The subscription.
	 * @return array process_payment result.
	 */
	public static function change_payment_method( $subscription ): array {
		$client = WuloPay_Gateway::client();
		if ( null === $client || ! self::library_ok( $client ) ) {
			wc_add_notice( __( 'Card payments are not available right now. Please choose another payment method.', 'wulopay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$wulopay_id = self::wulopay_id( $subscription );
		try {
			if ( '' !== $wulopay_id ) {
				$update = $client->subscriptions->createCardUpdateSession( $wulopay_id, $subscription->get_view_order_url() );
				$subscription->add_order_note( __( 'Customer is saving a new card on WuloPay\'s payment page.', 'wulopay-woocommerce' ) );
				self::allow_redirect( $update['url'] );
				return array(
					'result'   => 'success',
					'redirect' => $update['url'],
				);
			}
			$name   = trim( $subscription->get_billing_first_name() . ' ' . $subscription->get_billing_last_name() );
			$params = array(
				'reference' => substr( (string) $subscription->get_order_number(), 0, 64 ),
				'returnUrl' => $subscription->get_view_order_url(),
				'customer'  => array_filter(
					array(
						'email' => $subscription->get_billing_email(),
						'name'  => '' !== $name ? substr( $name, 0, 120 ) : null,
					)
				),
				'metadata'  => array(
					'wc_order_id'        => (string) $subscription->get_id(),
					'wc_subscription_id' => (string) $subscription->get_id(),
					'site'               => substr( home_url(), 0, 500 ),
				),
			);
			if ( empty( $params['customer']['email'] ) ) {
				throw new RuntimeException( esc_html__( 'An email address is needed for a subscription.', 'wulopay-woocommerce' ) );
			}
			$params['mode']         = 'subscription';
			$params['amount']       = 0;
			$params['subscription'] = array(
				'planId'      => self::plan_id( $subscription, $client ),
				'billingMode' => 'merchant',
				'maxAmount'   => round( (float) $subscription->get_total(), 2 ),
				'firstAmount' => 0,
			);
			$session                = $client->checkoutSessions->create( $params, 'wc-method-' . $subscription->get_id() . '-' . wp_generate_uuid4() ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- library API.
		} catch ( RuntimeException $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return array( 'result' => 'failure' );
		} catch ( \WuloPay\Exception\WuloPayException $e ) {
			WuloPay_Gateway::log( 'Payment method change for subscription ' . $subscription->get_id() . ' failed: ' . $e->getMessage(), 'error' );
			wc_add_notice( __( 'We could not open the card form. Please try again.', 'wulopay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$subscription->update_meta_data( WuloPay_Gateway::META_SESSION_ID, $session['id'] );
		$subscription->update_meta_data( WuloPay_Gateway::META_ENV, WuloPay_Gateway::environment() );
		$subscription->save();
		self::allow_redirect( $session['url'] );
		return array(
			'result'   => 'success',
			'redirect' => $session['url'],
		);
	}

	/**
	 * WooCommerce Subscriptions sends the shopper on with wp_safe_redirect(), which only follows the store's own host:
	 * allow WuloPay's payment page for this request.
	 *
	 * @param string $url The payment page URL.
	 */
	private static function allow_redirect( string $url ): void {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return;
		}
		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) use ( $host ) {
				$hosts[] = $host;
				return $hosts;
			}
		);
	}

	/**
	 * A paid (or card-stored) sign-up session: remember the WuloPay subscription on the order and its subscriptions.
	 *
	 * @param WC_Order $order   Parent order, or the subscription itself (payment method change).
	 * @param array    $session Checkout session.
	 */
	public static function remember( WC_Order $order, array $session ): void {
		$wulopay_id = (string) ( $session['subscription']['subscriptionId'] ?? '' );
		if ( '' === $wulopay_id || ! self::active() ) {
			return;
		}
		$targets = function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order )
			? array( $order )
			: wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
		foreach ( $targets as $target ) {
			if ( $target->get_meta( self::META_SUBSCRIPTION_ID ) === $wulopay_id ) {
				continue;
			}
			$target->update_meta_data( self::META_SUBSCRIPTION_ID, $wulopay_id );
			$target->update_meta_data( WuloPay_Gateway::META_ENV, (string) $order->get_meta( WuloPay_Gateway::META_ENV ) );
			$target->add_order_note(
				sprintf(
					/* translators: %s: WuloPay subscription id */
					__( 'Card stored with WuloPay for renewals (subscription %s).', 'wulopay-woocommerce' ),
					$wulopay_id
				)
			);
			$target->save();
		}
		if ( $order->get_meta( self::META_SUBSCRIPTION_ID ) !== $wulopay_id ) {
			$order->update_meta_data( self::META_SUBSCRIPTION_ID, $wulopay_id );
		}
	}

	/**
	 * A sign-up with nothing to pay today (free trial): WooCommerce doesn't consider a zero total as needing payment, but
	 * the order still waits for the card to be stored.
	 *
	 * @param WC_Order $order The order.
	 */
	public static function awaiting_card( WC_Order $order ): bool {
		return self::is_signup( $order ) && (float) $order->get_total() <= 0 && $order->has_status( array( 'pending', 'failed' ) );
	}

	/**
	 * The WuloPay subscription id for a WooCommerce subscription; looked up from its sign-up session when the webhook
	 * hasn't stored it yet.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 */
	public static function wulopay_id( $subscription ): string {
		$id = (string) $subscription->get_meta( self::META_SUBSCRIPTION_ID );
		if ( '' !== $id ) {
			return $id;
		}
		$parent     = $subscription->get_parent();
		$session_id = (string) $subscription->get_meta( WuloPay_Gateway::META_SESSION_ID );
		$env        = (string) $subscription->get_meta( WuloPay_Gateway::META_ENV );
		if ( '' === $session_id && $parent ) {
			$session_id = (string) $parent->get_meta( WuloPay_Gateway::META_SESSION_ID );
			$env        = (string) $parent->get_meta( WuloPay_Gateway::META_ENV );
		}
		$client = '' !== $session_id ? WuloPay_Gateway::client( '' !== $env ? $env : null ) : null;
		if ( null === $client ) {
			return '';
		}
		try {
			$session = $client->checkoutSessions->retrieve( $session_id ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- library API.
		} catch ( \WuloPay\Exception\WuloPayException $e ) {
			WuloPay_Gateway::log( 'Looking up the WuloPay subscription for ' . $subscription->get_id() . ' failed: ' . $e->getMessage(), 'warning' );
			return '';
		}
		$id = (string) ( $session['subscription']['subscriptionId'] ?? '' );
		if ( '' !== $id ) {
			$subscription->update_meta_data( self::META_SUBSCRIPTION_ID, $id );
			$subscription->save();
		}
		return $id;
	}

	/**
	 * Renewal due (WooCommerce Subscriptions schedule): charge the stored card. A decline fails the renewal order so
	 * WooCommerce Subscriptions' retry rules apply; each retry uses a new idempotency key, while a network error keeps the
	 * key so a retry can never charge twice.
	 *
	 * @param float    $amount        Amount to charge.
	 * @param WC_Order $renewal_order Renewal order.
	 */
	public static function scheduled_payment( $amount, $renewal_order ): void {
		$subscriptions = function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ? wcs_get_subscriptions_for_renewal_order( $renewal_order ) : array();
		$subscription  = reset( $subscriptions );
		$wulopay_id    = $subscription ? self::wulopay_id( $subscription ) : '';
		$env           = $subscription ? (string) $subscription->get_meta( WuloPay_Gateway::META_ENV ) : '';
		$client        = WuloPay_Gateway::client( '' !== $env ? $env : null );
		if ( '' === $wulopay_id || null === $client || ! self::library_ok( $client ) ) {
			$renewal_order->update_status( 'failed', __( 'WuloPay: no stored card for this subscription. Ask the customer to update the payment method.', 'wulopay-woocommerce' ) );
			return;
		}

		$attempt = max( 1, (int) $renewal_order->get_meta( self::META_CHARGE_ATTEMPT ) );
		$key     = 'wc-renewal-' . $renewal_order->get_id() . '-' . $attempt;
		try {
			$charge = $client->subscriptions->charge( $wulopay_id, $key, round( (float) $amount, 2 ) );
		} catch ( \WuloPay\Exception\ApiConnectionException $e ) {
			// Unknown outcome: keep the key, so the retry returns this charge instead of charging again.
			$renewal_order->update_status( 'failed', __( 'WuloPay could not be reached for this renewal; it will be retried.', 'wulopay-woocommerce' ) );
			WuloPay_Gateway::log( 'Renewal ' . $renewal_order->get_id() . ': ' . $e->getMessage(), 'error' );
			return;
		} catch ( \WuloPay\Exception\WuloPayException $e ) {
			$renewal_order->update_meta_data( self::META_CHARGE_ATTEMPT, $attempt + 1 );
			$renewal_order->update_status(
				'failed',
				sprintf(
					/* translators: %s: reason from WuloPay */
					__( 'WuloPay refused the renewal charge: %s', 'wulopay-woocommerce' ),
					$e->getMessage()
				)
			);
			WuloPay_Gateway::log( 'Renewal ' . $renewal_order->get_id() . ' refused: ' . $e->getMessage(), 'error' );
			return;
		}

		if ( 'succeeded' === ( $charge['status'] ?? '' ) ) {
			$renewal_order->update_meta_data( WuloPay_Gateway::META_ENV, $env );
			$renewal_order->payment_complete( (string) $charge['id'] );
			$renewal_order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: WuloPay charge id */
					__( 'WuloPay renewal payment of %1$s received (charge %2$s).', 'wulopay-woocommerce' ),
					wc_price( (float) $charge['amount'], array( 'currency' => $renewal_order->get_currency() ) ),
					$charge['id']
				)
			);
			return;
		}

		$renewal_order->update_meta_data( self::META_CHARGE_ATTEMPT, $attempt + 1 );
		$message = sanitize_text_field( (string) ( $charge['failureMessage'] ?? __( 'The card was declined.', 'wulopay-woocommerce' ) ) );
		if ( false === ( $charge['retryAllowed'] ?? null ) ) {
			$message .= ' ' . __( 'The card must not be charged again: ask the customer to update the payment method.', 'wulopay-woocommerce' );
		}
		$renewal_order->update_status(
			'failed',
			sprintf(
				/* translators: %s: reason */
				__( 'WuloPay renewal payment failed: %s', 'wulopay-woocommerce' ),
				$message
			)
		);
	}

	/**
	 * subscription.* webhooks: a note on the WooCommerce subscription when the shopper saved a new card. (Charges are
	 * confirmed synchronously at renewal; other events need no action in merchant billing.)
	 *
	 * @param string $type        Event type.
	 * @param string $event_id    Event id (handled once).
	 * @param array  $data_object Subscription (or charge) object.
	 */
	public static function webhook( string $type, string $event_id, array $data_object ): void {
		if ( 'subscription.card_updated' !== $type || ! self::active() || empty( $data_object['id'] ) ) {
			return;
		}
		$found = wcs_get_subscriptions(
			array(
				'subscriptions_per_page' => 5,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_SUBSCRIPTION_ID,
						'value' => (string) $data_object['id'],
					),
				),
			)
		);
		foreach ( $found as $subscription ) {
			$seen = (array) $subscription->get_meta( WuloPay_Gateway::META_EVENTS );
			if ( in_array( $event_id, $seen, true ) ) {
				continue;
			}
			$card = trim( ( $data_object['cardBrand'] ?? '' ) . ( ! empty( $data_object['last4'] ) ? ' ending ' . $data_object['last4'] : '' ) );
			$subscription->add_order_note(
				sprintf(
					/* translators: %s: card brand and last digits */
					__( 'Customer saved a new card with WuloPay for renewals (%s).', 'wulopay-woocommerce' ),
					'' !== $card ? $card : '-'
				)
			);
			$seen[] = $event_id;
			$subscription->update_meta_data( WuloPay_Gateway::META_EVENTS, array_slice( $seen, -25 ) );
			$subscription->save();
		}
	}

	/**
	 * A WooCommerce subscription was cancelled or expired: stop the WuloPay subscription too, so the stored card can't be
	 * charged for it again.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 */
	public static function ended( $subscription ): void {
		if ( WuloPay_Gateway::ID !== $subscription->get_payment_method() ) {
			return;
		}
		$wulopay_id = (string) $subscription->get_meta( self::META_SUBSCRIPTION_ID );
		$env        = (string) $subscription->get_meta( WuloPay_Gateway::META_ENV );
		$client     = WuloPay_Gateway::client( '' !== $env ? $env : null );
		if ( '' === $wulopay_id || null === $client || ! self::library_ok( $client ) ) {
			return;
		}
		try {
			$client->subscriptions->cancel( $wulopay_id );
			$subscription->add_order_note( __( 'WuloPay subscription cancelled: the stored card will not be charged again.', 'wulopay-woocommerce' ) );
		} catch ( \WuloPay\Exception\WuloPayException $e ) {
			WuloPay_Gateway::log( 'Cancelling WuloPay subscription ' . $wulopay_id . ' failed: ' . $e->getMessage(), 'error' );
		}
	}
}
