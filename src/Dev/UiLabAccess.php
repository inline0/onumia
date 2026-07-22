<?php

/**
 * Request-local access policy for the UI Lab diagnostic module.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Dev;

final class UiLabAccess {
	public const QUERY_PARAMETER = 'onumia-dev';

	private const CAPABILITY = 'manage_options';

	public static function requested_for_current_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This read-only flag only requests a diagnostic surface; authorization is checked separately.
		$value = isset( $_GET[ self::QUERY_PARAMETER ] ) && is_string( $_GET[ self::QUERY_PARAMETER ] )
			? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_PARAMETER ] ) )
			: null;

		return self::requested( $value );
	}

	public static function enabled_for_current_request(): bool {
		return self::requested_for_current_request() && self::authorized();
	}

	public static function enabled_for_rest_request( \WP_REST_Request $request ): bool {
		return self::requested( $request->get_param( self::QUERY_PARAMETER ) ) && self::authorized();
	}

	private static function requested( mixed $value ): bool {
		return is_scalar( $value ) && '1' === trim( (string) $value );
	}

	private static function authorized(): bool {
		return function_exists( 'is_user_logged_in' )
			&& \is_user_logged_in()
			&& function_exists( 'current_user_can' )
			&& \current_user_can( self::CAPABILITY );
	}
}
