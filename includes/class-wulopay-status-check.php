<?php
/**
 * Background status check for orders waiting for a WuloPay payment.
 *
 * @package WuloPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Webhooks and the shopper's return confirm almost every payment. This covers the rest: the webhook isn't set up or
 * didn't arrive, and the shopper closed the browser after paying. Ten minutes after a session is created, then every
 * fifteen minutes, the plugin asks WuloPay for the session's status (Action Scheduler, bundled with WooCommerce). It
 * stops when the order is paid, the session has expired or been cancelled, or after MAX_CHECKS checks.
 */
final class WuloPay_Status_Check {

	public const HOOK        = 'wulopay_wc_check_session';
	public const GROUP       = 'wulopay';
	private const META_COUNT = '_wulopay_status_checks';
	private const FIRST      = 600;
	private const EVERY      = 900;
	private const MAX_CHECKS = 8;

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Starts the checks for an order's new session (one queued check per order at a time).
	 *
	 * @param int $order_id Order id.
	 */
	public static function schedule( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( self::META_COUNT, 0 );
			$order->save();
		}
		self::queue( $order_id, self::FIRST );
	}

	/**
	 * One check.
	 *
	 * @param int $order_id Order id.
	 */
	public static function run( $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order || WuloPay_Gateway::ID !== $order->get_payment_method() || ( ! $order->needs_payment() && ! WuloPay_Subscriptions::awaiting_card( $order ) ) ) {
			return;
		}
		$session_id = (string) $order->get_meta( WuloPay_Gateway::META_SESSION_ID );
		$client     = '' !== $session_id ? WuloPay_Gateway::client( (string) $order->get_meta( WuloPay_Gateway::META_ENV ) ) : null;
		if ( null === $client ) {
			return;
		}
		$count = (int) $order->get_meta( self::META_COUNT ) + 1;
		$order->update_meta_data( self::META_COUNT, $count );
		$order->save();
		try {
			$session = $client->checkoutSessions->retrieve( $session_id ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- library API.
		} catch ( \WuloPay\Exception\WuloPayException $e ) {
			WuloPay_Gateway::log( 'Status check for order ' . $order->get_id() . ' failed: ' . $e->getMessage(), 'warning' );
			$session = array( 'status' => 'unknown' );
		}
		$status = (string) ( $session['status'] ?? '' );
		if ( 'paid' === $status ) {
			WuloPay_Gateway::apply_session( $order, $session, 'background check' );
			return;
		}
		if ( in_array( $status, array( 'open', 'processing', 'unknown' ), true ) && $count < self::MAX_CHECKS ) {
			self::queue( $order->get_id(), self::EVERY );
		}
	}

	/**
	 * Queues a check unless one is already queued for the order.
	 *
	 * @param int $order_id Order id.
	 * @param int $delay    Seconds from now.
	 */
	private static function queue( int $order_id, int $delay ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || as_has_scheduled_action( self::HOOK, array( $order_id ), self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( time() + $delay, self::HOOK, array( $order_id ), self::GROUP );
	}
}
