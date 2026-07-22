<?php

/**
 * Normalizes Onumia UI state.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Rest;

final class UiStateNormalizer {
	public static function default_theme_mode(): string {
		return 'system';
	}

	/**
	 * @return array{active_page_id:?string,open_page_ids:list<string>,top_level_page_ids:array<string,string>}
	 */
	public static function default_page_tabs_state(): array {
		return array(
			'active_page_id'     => null,
			'open_page_ids'      => array(),
			'top_level_page_ids' => array(),
		);
	}

	/**
	 * @return array{expanded_page_ids:list<string>,mobile_sidebar_open:bool,navigation_groups:list<array{emoji:string|null,id:string,item_ids:list<string>,label:string,open:bool}>,module_group_open:bool,sidebar_open:bool}
	 */
	public static function default_page_sidebar_state(): array {
		return array(
			'expanded_page_ids'   => array(),
			'mobile_sidebar_open' => false,
			'navigation_groups'   => array(
				array(
					'emoji'    => null,
					'id'       => 'general',
					'item_ids' => array(),
					'label'    => 'General',
					'open'     => true,
				),
			),
			'module_group_open'      => true,
			'sidebar_open'        => true,
		);
	}

	/**
	 * @param  array<string,mixed> $state Raw UI state.
	 * @return array{theme_mode:string,page_sidebar:array{expanded_page_ids:list<string>,mobile_sidebar_open:bool,navigation_groups:list<array{emoji:string|null,id:string,item_ids:list<string>,label:string,open:bool}>,module_group_open:bool,sidebar_open:bool},page_tabs:array{active_page_id:?string,open_page_ids:list<string>,top_level_page_ids:array<string,string>}}
	 */
	public static function normalize_state( array $state ): array {
		return array(
			'theme_mode'   => self::normalize_theme_mode( $state['theme_mode'] ?? null ),
			'page_sidebar' => self::normalize_page_sidebar( $state['page_sidebar'] ?? null ),
			'page_tabs'    => self::normalize_page_tabs( $state['page_tabs'] ?? null ),
		);
	}

	/**
	 * @param  array<string,mixed> $state UI state.
	 * @return array{theme_mode:string,page_sidebar:array{expanded_page_ids:list<string>,mobile_sidebar_open:bool,navigation_groups:list<array{emoji:string|null,id:string,item_ids:list<string>,label:string,open:bool}>,module_group_open:bool,sidebar_open:bool},page_tabs:array{active_page_id:?string,open_page_ids:list<string>,top_level_page_ids:array<string,string>}}
	 */
	public static function prepare_state_for_response( array $state ): array {
		return self::normalize_state( $state );
	}

	private static function normalize_theme_mode( mixed $value ): string {
		return is_string( $value ) && in_array( $value, array( 'system', 'light', 'dark' ), true )
			? $value
			: self::default_theme_mode();
	}

	/**
	 * @return array{active_page_id:?string,open_page_ids:list<string>,top_level_page_ids:array<string,string>}
	 */
	private static function normalize_page_tabs( mixed $value ): array {
		$defaults = self::default_page_tabs_state();
		if ( ! is_array( $value ) ) {
			return $defaults;
		}

		$open_page_ids = $value['open_page_ids'] ?? array();
		if ( ! is_array( $open_page_ids ) ) {
			$open_page_ids = array();
		}

		$normalized_open_page_ids = array();
		foreach ( $open_page_ids as $page_id ) {
			if ( ! is_string( $page_id ) || ! self::is_valid_page_id( $page_id ) || in_array( $page_id, $normalized_open_page_ids, true ) ) {
				continue;
			}

			$normalized_open_page_ids[] = $page_id;
		}
		$open_page_ids  = $normalized_open_page_ids;
		$active_page_id = $value['active_page_id'] ?? null;
		if ( ! is_string( $active_page_id ) || ! self::is_valid_page_id( $active_page_id ) || ! in_array( $active_page_id, $open_page_ids, true ) ) {
			$active_page_id = null;
		}
		$top_level_page_ids = $value['top_level_page_ids'] ?? array();
		if ( ! is_array( $top_level_page_ids ) ) {
			$top_level_page_ids = array();
		}

		$normalized_top_level_page_ids = array();
		foreach ( $top_level_page_ids as $tab_page_id => $top_level_page_id ) {
			if ( ! is_string( $tab_page_id ) || ! is_string( $top_level_page_id ) || ! self::is_valid_page_id( $tab_page_id ) || ! self::is_valid_page_id( $top_level_page_id ) || ! in_array( $tab_page_id, $open_page_ids, true ) ) {
				continue;
			}

			$normalized_top_level_page_ids[ $tab_page_id ] = $top_level_page_id;
		}

		return array(
			'active_page_id'     => $active_page_id,
			'open_page_ids'      => $open_page_ids,
			'top_level_page_ids' => $normalized_top_level_page_ids,
		);
	}

	/**
	 * @return array{expanded_page_ids:list<string>,mobile_sidebar_open:bool,navigation_groups:list<array{emoji:string|null,id:string,item_ids:list<string>,label:string,open:bool}>,module_group_open:bool,sidebar_open:bool}
	 */
	private static function normalize_page_sidebar( mixed $value ): array {
		$defaults = self::default_page_sidebar_state();
		if ( ! is_array( $value ) ) {
			return $defaults;
		}

		$expanded_page_ids = $value['expanded_page_ids'] ?? array();
		if ( ! is_array( $expanded_page_ids ) ) {
			$expanded_page_ids = array();
		}

		$normalized_expanded_page_ids = array();
		foreach ( $expanded_page_ids as $page_id ) {
			if ( ! is_string( $page_id ) || ! self::is_valid_page_id( $page_id ) || in_array( $page_id, $normalized_expanded_page_ids, true ) ) {
				continue;
			}

			$normalized_expanded_page_ids[] = $page_id;
		}
		$expanded_page_ids = $normalized_expanded_page_ids;

		return array(
			'expanded_page_ids'   => $expanded_page_ids,
			'mobile_sidebar_open' => (bool) ( $value['mobile_sidebar_open'] ?? $defaults['mobile_sidebar_open'] ),
			'navigation_groups'   => self::normalize_navigation_groups( $value['navigation_groups'] ?? null ),
			'module_group_open'      => (bool) ( $value['module_group_open'] ?? $defaults['module_group_open'] ),
			'sidebar_open'        => (bool) ( $value['sidebar_open'] ?? $defaults['sidebar_open'] ),
		);
	}

	/**
	 * @return list<array{emoji:string|null,id:string,item_ids:list<string>,label:string,open:bool}>
	 */
	private static function normalize_navigation_groups( mixed $value ): array {
		$groups           = array();
		$group_ids        = array();
		$claimed_page_ids = array();

		if ( is_array( $value ) ) {
			foreach ( $value as $raw_group ) {
				if ( ! is_array( $raw_group ) ) {
					continue;
				}

				$id    = is_string( $raw_group['id'] ?? null ) ? trim( $raw_group['id'] ) : '';
				$label = is_string( $raw_group['label'] ?? null ) ? trim( $raw_group['label'] ) : '';
				if ( '' === $id || isset( $group_ids[ $id ] ) || strlen( $id ) > 80 ) {
					continue;
				}
				if ( 'general' === $id ) {
					$label = 'General';
				}
				if ( '' === $label || strlen( $label ) > 80 ) {
					continue;
				}

				$item_ids     = array();
				$raw_item_ids = $raw_group['item_ids'] ?? array();
				if ( is_array( $raw_item_ids ) ) {
					foreach ( $raw_item_ids as $page_id ) {
						if ( ! is_string( $page_id ) || ! self::is_valid_page_id( $page_id ) || isset( $claimed_page_ids[ $page_id ] ) ) {
							continue;
						}

						$claimed_page_ids[ $page_id ] = true;
						$item_ids[]                   = $page_id;
					}
				}

				$group_ids[ $id ] = true;
				$groups[]         = array(
					'emoji'    => self::normalize_navigation_group_emoji( $raw_group['emoji'] ?? null ),
					'id'       => $id,
					'item_ids' => $item_ids,
					'label'    => $label,
					'open'     => (bool) ( $raw_group['open'] ?? true ),
				);
			}
		}

		if ( ! isset( $group_ids['general'] ) ) {
			array_unshift(
				$groups,
				array(
					'emoji'    => null,
					'id'       => 'general',
					'item_ids' => array(),
					'label'    => 'General',
					'open'     => true,
				)
			);
		}

		return $groups;
	}

	private static function normalize_navigation_group_emoji( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );
		return '' !== $value && strlen( $value ) <= 128 ? $value : null;
	}

	private static function is_valid_page_id( string $page_id ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $page_id );
	}
}
