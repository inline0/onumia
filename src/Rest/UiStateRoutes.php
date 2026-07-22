<?php

/**
 * Onumia UI state REST routes.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Rest;

final class UiStateRoutes {

	private const NAMESPACE = 'onumia/v1';
	private const META_KEY  = 'onumia_ui_state';

	public static function register(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/ui-state',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_state' ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'update_state' ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);
	}

	public static function can_manage_onumia(): bool {
		return \current_user_can( 'manage_options' );
	}

	public static function get_state( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( UiStateNormalizer::prepare_state_for_response( self::current_state() ), 200 );
	}

	public static function update_state( \WP_REST_Request $request ): \WP_REST_Response {
		$payload = $request->get_json_params();

		$state   = UiStateNormalizer::normalize_state( self::string_keyed_array( $payload ) );
		$user_id = \get_current_user_id();

		\update_user_meta( $user_id, self::META_KEY, $state );

		return new \WP_REST_Response( UiStateNormalizer::prepare_state_for_response( $state ), 200 );
	}

	/**
	 * @return array{theme_mode:string,page_sidebar:array{expanded_page_ids:list<string>,mobile_sidebar_open:bool,navigation_groups:list<array{emoji:string|null,id:string,item_ids:list<string>,label:string,open:bool}>,module_group_open:bool,sidebar_open:bool},page_tabs:array{active_page_id:?string,open_page_ids:list<string>,top_level_page_ids:array<string,string>}}
	 */
	public static function current_state(): array {
		$user_id = \get_current_user_id();
		$raw     = \get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		return UiStateNormalizer::normalize_state( self::string_keyed_array( $raw ) );
	}

	/**
	 * @param  array<mixed,mixed> $value Raw array.
	 * @return array<string,mixed>
	 */
	private static function string_keyed_array( array $value ): array {
		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}

		return $normalized;
	}
}
