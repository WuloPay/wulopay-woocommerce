<?php
/**
 * Shares WuloPay access tokens between requests through WordPress transients.
 *
 * @package WuloPay
 */

defined( 'ABSPATH' ) || exit;

/** Token cache backed by transients (the token endpoint is rate limited, so tokens are reused). */
final class WuloPay_Token_Cache implements \WuloPay\TokenCacheInterface {

	public function get( string $key ): ?string {
		$value = get_transient( $key );
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	public function set( string $key, string $token, int $ttl_seconds ): void {
		set_transient( $key, $token, $ttl_seconds );
	}

	public function delete( string $key ): void {
		delete_transient( $key );
	}
}
