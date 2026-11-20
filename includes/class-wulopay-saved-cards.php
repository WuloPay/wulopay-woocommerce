<?php
/**
 * Saved cards: logged-in customers save a card on WuloPay's payment page and pay with it later.
 *
 * @package WuloPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce keeps a payment token (WC_Payment_Token_CC) per saved card, so customers see their cards at checkout and
 * under My Account, Payment methods. The token holds WuloPay's card id; card numbers never reach the store.
 *
 * - Saving: the checkout session offers the shopper a box to save the card while paying (saveCard), and "Add payment
 *   method" opens a page that only saves a card (mode save_card).
 * - Paying with a saved card: the checkout session names the card (cardId); WuloPay's page shows it and the shopper
 *   confirms with 3-D Secure.
 * - Each WooCommerce customer is one WuloPay customer, "wc-<site>-<user id>". The store's tokens follow WuloPay's list
 *   (sync), so cards removed elsewhere (e.g. in the WuloPay merchant portal) disappear here too.
 * - Deleting a card or making it the default under My Account does the same at WuloPay.
 */
final class WuloPay_Saved_Cards {

	private const QUERY_FLAG = 'wulopay_card_saved';

	/** True while the store's tokens are being brought in line with WuloPay (no calls back to WuloPay then). */
	private static bool $syncing = false;

	public static function register(): void {
		add_action( 'woocommerce_payment_token_deleted', array( self::class, 'on_token_deleted' ), 10, 2 );
		add_action( 'woocommerce_payment_token_set_default', array( self::class, 'on_token_set_default' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'handle_saved_return' ) );
		add_filter( 'allowed_redirect_hosts', array( self::class, 'allow_payment_page' ) );
	}

	/** The merchant switched saved cards on (WooCommerce, Settings, Payments, WuloPay). */
	public static function enabled(): bool {
		$settings = get_option( 'woocommerce_wulopay_settings', array() );
		return 'yes' === ( $settings['saved_cards'] ?? 'no' );
	}

	/** WuloPay's id for a store customer: stable per site, so two stores on one WuloPay account never share cards. */
	public static function customer_reference( int $user_id ): string {
		return 'wc-' . self::site_tag() . '-' . $user_id;
	}

	/** The store customer a WuloPay customer reference belongs to, or 0 when it is not one of this store's. */
	public static function user_from_reference( string $reference ): int {
		$prefix = 'wc-' . self::site_tag() . '-';
		if ( 0 !== strpos( $reference, $prefix ) ) {
			return 0;
		}
		$id = substr( $reference, strlen( $prefix ) );
		return ctype_digit( $id ) ? (int) $id : 0;
	}

	private static function site_tag(): string {
		return substr( hash( 'sha256', home_url() ), 0, 10 );
	}

	/**
	 * Adds the saved-card fields to a payment session: pay with the chosen saved card, or offer to save the new one.
	 * Subscription sign-ups store their own card and are left alone.
	 *
	 * @param WC_Order $order  The order.
	 * @param array    $params Session parameters so far.
	 * @return array
	 */
	public static function session_params( WC_Order $order, array $params ): array {
		$user_id = (int) $order->get_customer_id();
		if ( ! self::enabled() || $user_id <= 0 ) {
			return $params;
		}
		$params['customerReference'] = self::customer_reference( $user_id );
		$token                       = self::selected_token( $user_id );
		if ( $token ) {
			$params['cardId'] = $token->get_token();
			$order->add_order_note(
				sprintf(
					/* translators: %s: card, e.g. "Visa ending 4242" */
					__( 'The customer chose their saved card (%s).', 'wulopay-woocommerce' ),
					$token->get_display_name()
				)
			);
		} else {
			$params['saveCard'] = true;
		}
		return $params;
	}

	/** The saved card the customer picked at checkout (classic or block checkout), if any and theirs. */
	private static function selected_token( int $user_id ): ?WC_Payment_Token_CC {
		$field = 'wc-' . WuloPay_Gateway::ID . '-payment-token';
		$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
		if ( '' === $value || 'new' === $value || ! ctype_digit( $value ) ) {
			return null;
		}
		$token = WC_Payment_Tokens::get( (int) $value );
		return $token instanceof WC_Payment_Token_CC && WuloPay_Gateway::ID === $token->get_gateway_id() && $token->get_user_id() === $user_id
			? $token : null;
	}

	/**
	 * Starts "Add payment method" (My Account): a WuloPay page that saves a card without charging.
	 *
	 * @return array WooCommerce's add_payment_method() result.
	 */
	public static function add_payment_method(): array {
		$user_id = get_current_user_id();
		$client  = WuloPay_Gateway::client();
		if ( $user_id <= 0 || null === $client || ! self::enabled() ) {
			wc_add_notice( __( 'Saving a card is not available right now.', 'wulopay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$user = get_userdata( $user_id );
		try {
			$session = $client->checkoutSessions->create( // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- library API.
				array_filter(
					array(
						'mode'              => 'save_card',
						'customerReference' => self::customer_reference( $user_id ),
						'reference'         => 'card-' . $user_id . '-' . time(),
						'returnUrl'         => add_query_arg( self::QUERY_FLAG, '1', wc_get_account_endpoint_url( 'payment-methods' ) ),
						'cancelUrl'         => wc_get_account_endpoint_url( 'payment-methods' ),
						'customer'          => $user && $user->user_email ? array( 'email' => $user->user_email ) : null,
						'metadata'          => array( 'wc_user_id' => (string) $user_id ),
					)
				),
				'wc-card-' . $user_id . '-' . wp_generate_uuid4()
			);
		} catch ( \WuloPay\Exception\WuloPayException $e ) {
			WuloPay_Gateway::log( 'Saving a card for customer ' . $user_id . ' could not start: ' . $e->getMessage(), 'error' );
			wc_add_notice( __( 'We could not open the card page. Please try again.', 'wulopay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		return array(
			'result'   => 'success',
			'redirect' => $session['url'],
		);
	}

	/** WooCommerce sends the shopper to WuloPay's page with wp_safe_redirect(): allow that host. */
	public static function allow_payment_page( array $hosts ): array {
		$hosts[] = 'pay.wulopay.com';
		$hosts[] = 'pay-sandbox.wulopay.com';
		return $hosts;
	}

	/** Back from the save-card page (My Account, Payment methods): check the session, then show the new card. */
	public static function handle_saved_return(): void {
		if ( empty( $_GET[ self::QUERY_FLAG ] ) || empty( $_GET['session_id'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$user_id = get_current_user_id();
		$client  = WuloPay_Gateway::client();
		if ( null === $client ) {
			return;
		}
		try {
			$session = $client->checkoutSessions->retrieve( sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} catch ( \WuloPay\Exception\WuloPayException $e ) {
			WuloPay_Gateway::log( 'Save-card return for customer ' . $user_id . ' could not be checked: ' . $e->getMessage(), 'warning' );
			return;
		}
		// Only this customer's own session counts.
		if ( ( $session['customerReference'] ?? '' ) !== self::customer_reference( $user_id ) ) {
			return;
		}
		if ( 'paid' === ( $session['status'] ?? '' ) && ! empty( $session['savedCard'] ) ) {
			self::sync( $user_id );
			wc_add_notice( __( 'Your card was saved.', 'wulopay-woocommerce' ) );
		} else {
			wc_add_notice( __( 'The card was not saved.', 'wulopay-woocommerce' ), 'notice' );
		}
		wp_safe_redirect( remove_query_arg( array( self::QUERY_FLAG, 'session_id', 'status' ) ) );
		exit;
	}

	/** A paid order's session saved a card: show it under the customer's payment methods. */
	public static function after_payment( WC_Order $order, array $session ): void {
		$user_id = (int) $order->get_customer_id();
		if ( $user_id > 0 && ! empty( $session['savedCard'] ) && self::user_from_reference( (string) ( $session['customerReference'] ?? '' ) ) === $user_id ) {
			self::sync( $user_id );
		}
	}

	/** card.* webhooks: bring that customer's cards in line. */
	public static function webhook( array $data_object ): void {
		$user_id = self::user_from_reference( (string) ( $data_object['customerReference'] ?? '' ) );
		if ( $user_id > 0 ) {
			self::sync( $user_id );
		}
	}

	/**
	 * Makes the customer's WooCommerce tokens match their cards at WuloPay: adds new ones, removes removed ones, keeps
	 * expiry and default in step. Quietly does nothing when the API key lacks the saved-cards scopes.
	 */
	public static function sync( int $user_id ): void {
		$client = WuloPay_Gateway::client();
		if ( null === $client || ! property_exists( $client, 'wallet' ) ) {
			return;
		}
		try {
			$cards = $client->wallet->listCards( self::customer_reference( $user_id ) );
		} catch ( \WuloPay\Exception\NotFoundException $e ) {
			$cards = array();
		} catch ( \WuloPay\Exception\WuloPayException $e ) {
			WuloPay_Gateway::log( 'Saved cards of customer ' . $user_id . ' could not be read: ' . $e->getMessage(), 'warning' );
			return;
		}
		$by_id = array();
		foreach ( $cards as $card ) {
			if ( ! empty( $card['id'] ) && 'removed' !== ( $card['status'] ?? '' ) ) {
				$by_id[ (string) $card['id'] ] = $card;
			}
		}
		self::$syncing = true;
		try {
			$existing = array();
			foreach ( WC_Payment_Tokens::get_customer_tokens( $user_id, WuloPay_Gateway::ID ) as $token ) {
				if ( ! isset( $by_id[ $token->get_token() ] ) ) {
					WC_Payment_Tokens::delete( $token->get_id() );
					continue;
				}
				$existing[ $token->get_token() ] = $token;
			}
			foreach ( $by_id as $id => $card ) {
				$token = $existing[ $id ] ?? new WC_Payment_Token_CC();
				$token->set_token( $id );
				$token->set_gateway_id( WuloPay_Gateway::ID );
				$token->set_user_id( $user_id );
				$token->set_card_type( strtolower( (string) ( $card['brand'] ?? 'card' ) ) );
				$token->set_last4( (string) ( $card['last4'] ?? '0000' ) );
				$token->set_expiry_month( str_pad( (string) ( $card['expiryMonth'] ?? 12 ), 2, '0', STR_PAD_LEFT ) );
				$token->set_expiry_year( (string) ( $card['expiryYear'] ?? gmdate( 'Y' ) ) );
				$token->set_default( ! empty( $card['isDefault'] ) );
				$token->save();
			}
		} finally {
			self::$syncing = false;
		}
	}

	/** My Account, Payment methods, Delete: remove the card at WuloPay too. */
	public static function on_token_deleted( $token_id, $token ): void {
		if ( self::$syncing || ! $token instanceof WC_Payment_Token || WuloPay_Gateway::ID !== $token->get_gateway_id() ) {
			return;
		}
		$client = WuloPay_Gateway::client();
		if ( null === $client || ! property_exists( $client, 'wallet' ) ) {
			return;
		}
		try {
			$client->wallet->removeCard( self::customer_reference( (int) $token->get_user_id() ), $token->get_token() );
		} catch ( \WuloPay\Exception\NotFoundException $e ) {
			return; // Already gone at WuloPay.
		} catch ( \WuloPay\Exception\WuloPayException $e ) {
			WuloPay_Gateway::log( 'Removing saved card ' . $token->get_token() . ' at WuloPay failed: ' . $e->getMessage(), 'error' );
		}
	}

	/** My Account, Payment methods, Make default: the same card becomes the default at WuloPay. */
	public static function on_token_set_default( $token_id, $token ): void {
		if ( self::$syncing || ! $token instanceof WC_Payment_Token || WuloPay_Gateway::ID !== $token->get_gateway_id() ) {
			return;
		}
		$client = WuloPay_Gateway::client();
		if ( null === $client || ! property_exists( $client, 'wallet' ) ) {
			return;
		}
		try {
			$client->wallet->updateCard( self::customer_reference( (int) $token->get_user_id() ), $token->get_token(), array( 'isDefault' => true ) );
		} catch ( \WuloPay\Exception\WuloPayException $e ) {
			WuloPay_Gateway::log( 'Making saved card ' . $token->get_token() . ' the default at WuloPay failed: ' . $e->getMessage(), 'warning' );
		}
	}
}
