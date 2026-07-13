<?php

/**
 * Normalizes Onumia UI state.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Rest;

final class UiStateNormalizer {

	/**
	 * Default custom module UI state.
	 *
	 * @return array{active_chat_id:string|null,sidebar_open:bool,sidebar_tab:string}
	 */
	public static function default_custom_module_state(): array {
		return array(
			'active_chat_id' => null,
			'sidebar_open'   => true,
			'sidebar_tab'    => 'chat',
		);
	}

	/**
	 * Default module archive UI filters.
	 *
	 * @return array{category:string,settings:string,view:string}
	 */
	public static function default_module_archive_state(): array {
		return array(
			'category' => '__all__',
			'settings' => 'all',
			'view'     => 'cards',
		);
	}

	/**
	 * Default module detail UI state.
	 *
	 * @return array{list_sidebar_open:bool,full_width:bool}
	 */
	public static function default_module_detail_state(): array {
		return array(
			'list_sidebar_open' => false,
			'full_width'        => false,
		);
	}

	/**
	 * Normalize raw UI state.
	 *
	 * @param  array<string,mixed> $state Raw UI state.
	 * @return array{custom_modules:array<string,array{active_chat_id:string|null,sidebar_open:bool,sidebar_tab:string}>,module_archive:array{category:string,settings:string,view:string},module_detail:array{list_sidebar_open:bool,full_width:bool}}
	 */
	public static function normalize_state( array $state ): array {
		return array(
			'custom_modules' => self::normalize_custom_modules( $state['custom_modules'] ?? array() ),
			'module_archive' => self::normalize_module_archive( $state['module_archive'] ?? array() ),
			'module_detail'  => self::normalize_module_detail( $state['module_detail'] ?? array() ),
		);
	}

	/**
	 * Prepare UI state for REST output.
	 *
	 * @param  array{custom_modules:array<string,array{active_chat_id:string|null,sidebar_open:bool,sidebar_tab:string}>,module_archive:array{category:string,settings:string,view:string},module_detail?:array{list_sidebar_open:bool,full_width:bool}} $state Normalized UI state.
	 * @return array{custom_modules:array<string,array{active_chat_id:string|null,sidebar_open:bool,sidebar_tab:string}>,module_archive:array{category:string,settings:string,view:string},module_detail:array{list_sidebar_open:bool,full_width:bool}}
	 */
	public static function prepare_state_for_response( array $state ): array {
		return array(
			'custom_modules' => $state['custom_modules'],
			'module_archive' => $state['module_archive'],
			'module_detail'  => $state['module_detail'] ?? self::default_module_detail_state(),
		);
	}

	/**
	 * Normalize custom module state map.
	 *
	 * @param  mixed $value Raw custom module state map.
	 * @return array<string,array{active_chat_id:string|null,sidebar_open:bool,sidebar_tab:string}>
	 */
	private static function normalize_custom_modules( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$custom_modules = array();

		foreach ( $value as $module_name => $module_state ) {
			if ( ! is_string( $module_name ) || ! is_array( $module_state ) ) {
				continue;
			}

			$custom_modules[ $module_name ] = self::normalize_custom_module_state( $module_state );
		}

		return $custom_modules;
	}

	/**
	 * Normalize module archive filter state.
	 *
	 * @param  mixed $value Raw module archive filter state.
	 * @return array{category:string,settings:string,view:string}
	 */
	private static function normalize_module_archive( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return self::default_module_archive_state();
		}

		$category = $value['category'] ?? self::default_module_archive_state()['category'];
		$settings = $value['settings'] ?? self::default_module_archive_state()['settings'];
		$view     = $value['view'] ?? self::default_module_archive_state()['view'];

		$category = is_string( $category ) && '' !== trim( $category ) ? trim( $category ) : self::default_module_archive_state()['category'];
		$settings = is_string( $settings ) && in_array( $settings, array( 'all', 'edited', 'default' ), true ) ? $settings : self::default_module_archive_state()['settings'];
		$view     = is_string( $view ) && in_array( $view, array( 'cards', 'list' ), true ) ? $view : self::default_module_archive_state()['view'];

		return array(
			'category' => $category,
			'settings' => $settings,
			'view'     => $view,
		);
	}

	/**
	 * Normalize module detail UI state.
	 *
	 * @param  mixed $value Raw module detail UI state.
	 * @return array{list_sidebar_open:bool,full_width:bool}
	 */
	private static function normalize_module_detail( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return self::default_module_detail_state();
		}

		return array(
			'list_sidebar_open' => (bool) ( $value['list_sidebar_open'] ?? self::default_module_detail_state()['list_sidebar_open'] ),
			'full_width'        => (bool) ( $value['full_width'] ?? self::default_module_detail_state()['full_width'] ),
		);
	}

	/**
	 * Normalize one custom module state object.
	 *
	 * @param  array<mixed,mixed> $state Raw custom module state.
	 * @return array{active_chat_id:string|null,sidebar_open:bool,sidebar_tab:string}
	 */
	private static function normalize_custom_module_state( array $state ): array {
		$tab = $state['sidebar_tab'] ?? self::default_custom_module_state()['sidebar_tab'];
		$tab = is_string( $tab ) && in_array( $tab, array( 'chat', 'chats', 'history' ), true ) ? $tab : 'chat';

		$active_chat_id = $state['active_chat_id'] ?? null;

		return array(
			'active_chat_id' => is_string( $active_chat_id ) && '' !== trim( $active_chat_id ) ? trim( $active_chat_id ) : null,
			'sidebar_open'   => (bool) ( $state['sidebar_open'] ?? self::default_custom_module_state()['sidebar_open'] ),
			'sidebar_tab'    => $tab,
		);
	}
}
