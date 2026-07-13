<?php

/**
 * Evaluates Onumia entity access policies.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Support;

final class AccessPolicy {
	/**
	 * @param array<string,mixed> $policy Access policy.
	 */
	public function allows( array $policy, ?int $user_id = null ): bool {
		$roles        = $this->string_list( $policy['roles'] ?? array() );
		$user_ids     = $this->int_list( $policy['userIds'] ?? array() );
		$capabilities = $this->string_list( $policy['capabilities'] ?? array() );

		if ( array() === $roles && array() === $user_ids && array() === $capabilities ) {
			return true;
		}

		$user_id ??= function_exists( 'get_current_user_id' ) ? \get_current_user_id() : 0;
		if ( $user_id > 0 && in_array( $user_id, $user_ids, true ) ) {
			return true;
		}

		foreach ( $capabilities as $capability ) {
			if ( function_exists( 'current_user_can' ) && \current_user_can( $capability ) ) {
				return true;
			}
		}

		$current_roles = $this->current_user_roles();
		foreach ( $roles as $role ) {
			if ( in_array( $role, $current_roles, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return string[]
	 */
	private function current_user_roles(): array {
		// @codeCoverageIgnoreStart
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return array();
		}
		// @codeCoverageIgnoreEnd

		$user  = \wp_get_current_user();
		$roles = $user->roles;

		return $this->string_list( $roles );
	}

	/**
	 * @return string[]
	 */
	private function string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$value,
				static fn( mixed $item ): bool => is_string( $item ) && '' !== trim( $item )
			)
		);
	}

	/**
	 * @return int[]
	 */
	private function int_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( is_int( $item ) || is_numeric( $item ) ) {
				$items[] = (int) $item;
			}
		}

		return array_values( array_unique( array_filter( $items, static fn( int $item ): bool => $item > 0 ) ) );
	}
}
