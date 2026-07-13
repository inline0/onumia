<?php
/**
 * Admin Menu module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\AdminMenu;

use Onumia\Core\Errors;
use Onumia\Modules\Attributes\Action;
use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Entries;
use Onumia\Modules\Attributes\EntryField;
use Onumia\Modules\Attributes\EntrySection;
use Onumia\Modules\Attributes\Input;
use Onumia\Modules\Attributes\ModuleContract;
use Onumia\Modules\Attributes\RelatedEntries;
use Onumia\Modules\Attributes\Setting;
use Onumia\Modules\Attributes\WpAction;
use Onumia\Modules\Contracts\DataSourceShape;
use Onumia\Modules\Contracts\EntryStorage;
use Onumia\Modules\Contracts\PaginationMode;
use Onumia\Modules\Contracts\SettingType;
use Onumia\Modules\Module;
use Onumia\Modules\ModuleSettingsRepository;

#[ModuleContract( capability: 'manage_options' )]
#[Setting( 'perRole', SettingType::Object, default: array() )]
final class AdminMenu extends Module {
	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'roles', shape: DataSourceShape::Collection, pagination: PaginationMode::Client )]
	#[Entries( name: 'roles', singular: 'Role', plural: 'Roles', key: 'role', storage: EntryStorage::Manual, source: 'roles' )]
	#[EntrySection( name: 'summary', label: 'Summary', description: 'Role-specific top-level admin menu changes.', order: 10, layout: 'tabs' )]
	#[EntryField( name: 'role', type: SettingType::String, label: 'Role slug', primary: true, list: false, create: false, update: false, read_only: true, section: 'summary', order: 10 )]
	#[EntryField( name: 'label', type: SettingType::String, label: 'Role', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true, section: 'summary', order: 20 )]
	#[EntryField( name: 'hiddenCount', type: SettingType::Integer, label: 'Hidden', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true, section: 'summary', order: 30 )]
	#[EntryField( name: 'reorderedCount', type: SettingType::Integer, label: 'Reordered', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true, section: 'summary', order: 40 )]
	#[EntryField( name: 'lastEditedLabel', type: SettingType::String, label: 'Last edited', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true, section: 'summary', order: 50 )]
	#[RelatedEntries( name: 'menuItems', entry: 'menuItems', local_key: 'role', foreign_key: 'role', label: 'Menu items', mode: 'manage', order: 20 )]
	public function role_rows(): array {
		$rows = array();

		foreach ( $this->roles() as $role => $label ) {
			$config = $this->role_config( $role );
			$items  = $config['items'];

			$hidden_count    = 0;
			$reordered_count = 0;
			foreach ( $items as $item ) {
				if ( true === ( $item['hidden'] ?? false ) ) {
					++$hidden_count;
				}

				if ( null !== ( $item['order'] ?? null ) ) {
					++$reordered_count;
				}
			}

			$rows[] = array(
				'role'            => $role,
				'label'           => $label,
				'hiddenCount'     => $hidden_count,
				'reorderedCount'  => $reordered_count,
				'lastEditedLabel' => $this->timestamp_label( $config['lastEdited'] ),
			);
		}

		usort( $rows, static fn( array $left, array $right ): int => ( (string) ( $left['label'] ?? '' ) ) <=> ( (string) ( $right['label'] ?? '' ) ) );
		return $rows;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'menuItems', shape: DataSourceShape::Collection, pagination: PaginationMode::Client )]
	#[Entries( name: 'menuItems', singular: 'Menu item', plural: 'Menu items', key: 'id', storage: EntryStorage::Manual, source: 'menuItems', update_action: 'saveMenuItem' )]
	#[EntrySection( name: 'item', label: 'Menu item', description: 'Top-level menu item behavior for this role.', order: 10, layout: 'tabs' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, list: false, create: false, update: false, read_only: true, section: 'item', order: 10 )]
	#[EntryField( name: 'role', type: SettingType::String, label: 'Role', list: false, create: false, update: false, read_only: true, section: 'item', order: 20 )]
	#[EntryField( name: 'menuSlug', type: SettingType::String, label: 'Menu slug', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true, section: 'item', order: 30 )]
	#[EntryField( name: 'label', type: SettingType::String, label: 'Default label', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true, section: 'item', order: 40 )]
	#[EntryField( name: 'hidden', type: SettingType::Boolean, label: 'Hidden', default: false, section: 'item', order: 50 )]
	#[EntryField( name: 'hiddenLabel', type: SettingType::String, label: 'Hidden', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'item', order: 60 )]
	#[EntryField( name: 'order', type: SettingType::Integer, label: 'Order', default: 0, min: 0, section: 'item', order: 70, props: array( 'helpText' => 'Use 0 to keep the original WordPress menu position.' ) )]
	#[EntryField( name: 'orderLabel', type: SettingType::String, label: 'Order', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true, section: 'item', order: 80 )]
	#[EntryField( name: 'labelOverride', type: SettingType::String, label: 'Label override', default: '', list: true, filter: true, filter_type: 'text', section: 'item', order: 90 )]
	public function menu_item_rows(): array {
		$menu_items = $this->top_level_menu_items();
		$rows       = array();

		foreach ( $this->roles() as $role => $role_label ) {
			$config = $this->role_items_by_slug( $role );
			foreach ( $menu_items as $menu_item ) {
				$menu_slug = $menu_item['menuSlug'];
				$item      = $config[ $menu_slug ] ?? array(
					'menuSlug'      => $menu_slug,
					'hidden'        => false,
					'order'         => null,
					'labelOverride' => '',
				);

				$order = $item['order'] ?? null;
				$rows[] = array(
					'id'            => $this->menu_item_id( $role, $menu_slug ),
					'role'          => $role,
					'roleLabel'     => $role_label,
					'menuSlug'      => $menu_slug,
					'label'         => $menu_item['label'],
					'hidden'        => true === ( $item['hidden'] ?? false ),
					'hiddenLabel'   => true === ( $item['hidden'] ?? false ) ? 'Yes' : 'No',
					'order'         => is_int( $order ) ? $order : 0,
					'orderLabel'    => is_int( $order ) ? (string) $order : 'Default',
					'labelOverride' => is_string( $item['labelOverride'] ?? null ) ? $item['labelOverride'] : '',
					'position'      => $menu_item['position'],
				);
			}
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$role_order = (string) ( $left['roleLabel'] ?? '' ) <=> (string) ( $right['roleLabel'] ?? '' );
				return 0 !== $role_order ? $role_order : ( (int) ( $left['position'] ?? 0 ) <=> (int) ( $right['position'] ?? 0 ) );
			}
		);

		return $rows;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{ok:bool,id:string,role:string,menuSlug:string}
	 */
	#[Action( 'saveMenuItem' )]
	#[Input( 'id', SettingType::String, required: true )]
	#[Input( 'role', SettingType::String, required: false )]
	#[Input( 'hidden', SettingType::Boolean, default: false )]
	#[Input( 'order', SettingType::Integer, default: 0 )]
	#[Input( 'labelOverride', SettingType::String, default: '' )]
	public function save_menu_item( array $input ): array {
		$parsed = $this->parse_menu_item_id( (string) ( $input['id'] ?? '' ) );
		$role   = $parsed['role'];
		$slug   = $parsed['menuSlug'];

		if ( ! array_key_exists( $role, $this->roles() ) ) {
			throw Errors::invariant( "Unknown role {$role}." );
		}

		if ( ! array_key_exists( $slug, $this->top_level_menu_items_by_slug() ) ) {
			throw Errors::invariant( "Unknown admin menu item {$slug}." );
		}

		$hidden         = (bool) ( $input['hidden'] ?? false );
		$order          = max( 0, (int) ( $input['order'] ?? 0 ) );
		$label_override = $this->string_value( $input['labelOverride'] ?? '' );

		( new ModuleSettingsRepository() )->update_settings_with(
			$this->definition(),
			function ( array $current ) use ( $role, $slug, $hidden, $order, $label_override ): array {
				$per_role = $this->normalize_per_role( $current['perRole'] ?? array() );
				$config   = $per_role[ $role ] ?? array(
					'items'      => array(),
					'lastEdited' => null,
				);

				$items            = $this->items_by_slug( $config['items'] );
				$normalized_order = 0 < $order ? $order : null;

				if ( ! $hidden && null === $normalized_order && '' === $label_override ) {
					unset( $items[ $slug ] );
				} else {
					$items[ $slug ] = array(
						'menuSlug'      => $slug,
						'hidden'        => $hidden,
						'order'         => $normalized_order,
						'labelOverride' => $label_override,
					);
				}

				if ( array() === $items ) {
					unset( $per_role[ $role ] );
				} else {
					$per_role[ $role ] = array(
						'items'      => array_values( $items ),
						'lastEdited' => $this->current_timestamp(),
					);
				}

				return array( 'perRole' => $per_role );
			}
		);

		return array(
			'ok'       => true,
			'id'       => $this->menu_item_id( $role, $slug ),
			'role'     => $role,
			'menuSlug' => $slug,
		);
	}

	#[WpAction( 'admin_menu', priority: 999, accepted_args: 0 )]
	public function apply_admin_menu(): void {
		$role = $this->active_role();
		if ( null === $role ) {
			return;
		}

		$config = $this->role_items_by_slug( $role );
		if ( array() === $config ) {
			return;
		}

		$menu = is_array( $GLOBALS['menu'] ?? null ) ? $GLOBALS['menu'] : array();
		if ( array() === $menu ) {
			return;
		}

		$rows = array();
		$index = 0;
		foreach ( $menu as $position => $item ) {
			if ( ! is_array( $item ) || ! is_string( $item[2] ?? null ) || '' === $item[2] ) {
				++$index;
				continue;
			}

			$slug      = $item[2];
			$item_conf = $config[ $slug ] ?? array();
			if ( true === ( $item_conf['hidden'] ?? false ) ) {
				++$index;
				continue;
			}

			$label_override = is_string( $item_conf['labelOverride'] ?? null ) ? trim( $item_conf['labelOverride'] ) : '';
			if ( '' !== $label_override ) {
				$item[0] = $label_override;
			}

			$original_position = is_int( $position ) || is_float( $position ) ? (float) $position : (float) $index;
			$explicit_order    = $item_conf['order'] ?? null;
			$sort_order        = is_int( $explicit_order ) ? (float) $explicit_order : $original_position;

			$rows[] = array(
				'item'             => $item,
				'originalPosition' => $original_position,
				'sortOrder'        => $sort_order,
				'index'            => $index,
			);
			++$index;
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$sort_order = (float) $left['sortOrder'] <=> (float) $right['sortOrder'];
				if ( 0 !== $sort_order ) {
					return $sort_order;
				}

				$original_position_order = (float) $left['originalPosition'] <=> (float) $right['originalPosition'];
				return 0 !== $original_position_order ? $original_position_order : ( (int) $left['index'] <=> (int) $right['index'] );
			}
		);

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This module intentionally rewrites the top-level admin menu global after WordPress and plugins register it.
		$GLOBALS['menu'] = array_values( array_map( static fn( array $row ): array => $row['item'], $rows ) );
	}

	/**
	 * @return array<string,array{items:list<array{menuSlug:string,hidden:bool,order:int|null,labelOverride:string}>,lastEdited:int|null}>
	 */
	private function per_role_settings(): array {
		return $this->normalize_per_role( $this->setting( 'perRole' ) );
	}

	/**
	 * @param mixed $value Value.
	 * @return array<string,array{items:list<array{menuSlug:string,hidden:bool,order:int|null,labelOverride:string}>,lastEdited:int|null}>
	 */
	private function normalize_per_role( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$settings = array();
		foreach ( $value as $role => $record ) {
			if ( ! is_string( $role ) || ! is_array( $record ) ) {
				continue;
			}

			$items = array_values( $this->items_by_slug( $record['items'] ?? array() ) );
			if ( array() === $items ) {
				continue;
			}

			$settings[ $this->sanitize_key( $role ) ] = array(
				'items'      => $items,
				'lastEdited' => $this->timestamp_value( $record['lastEdited'] ?? null ),
			);
		}

		return $settings;
	}

	/**
	 * @return array{items:list<array{menuSlug:string,hidden:bool,order:int|null,labelOverride:string}>,lastEdited:int|null}
	 */
	private function role_config( string $role ): array {
		return $this->per_role_settings()[ $role ] ?? array(
			'items'      => array(),
			'lastEdited' => null,
		);
	}

	/**
	 * @return array<string,array{menuSlug:string,hidden:bool,order:int|null,labelOverride:string}>
	 */
	private function role_items_by_slug( string $role ): array {
		return $this->items_by_slug( $this->role_config( $role )['items'] );
	}

	/**
	 * @param mixed $items Items.
	 * @return array<string,array{menuSlug:string,hidden:bool,order:int|null,labelOverride:string}>
	 */
	private function items_by_slug( mixed $items ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$slug = $this->string_value( $item['menuSlug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}

			$order = $this->nullable_integer( $item['order'] ?? null );
			$normalized[ $slug ] = array(
				'menuSlug'      => $slug,
				'hidden'        => true === ( $item['hidden'] ?? false ),
				'order'         => $order,
				'labelOverride' => $this->string_value( $item['labelOverride'] ?? '' ),
			);
		}

		return $normalized;
	}

	/**
	 * @return array<string,string>
	 */
	private function roles(): array {
		$roles = array();
		if ( function_exists( 'get_editable_roles' ) ) {
			$roles = $this->normalize_roles( \get_editable_roles() );
		} elseif ( function_exists( 'wp_roles' ) ) {
			$wp_roles = \wp_roles();
			$roles    = $this->normalize_roles( $wp_roles->roles ?? array() );
		} elseif ( isset( $GLOBALS['wp_roles'] ) && is_object( $GLOBALS['wp_roles'] ) ) {
			$roles = $this->normalize_roles( $GLOBALS['wp_roles']->roles ?? array() );
		}

		if ( array() !== $roles ) {
			return $roles;
		}

		return array(
			'administrator' => 'Administrator',
			'author'        => 'Author',
			'contributor'   => 'Contributor',
			'editor'        => 'Editor',
			'subscriber'    => 'Subscriber',
		);
	}

	/**
	 * @param mixed $roles Roles.
	 * @return array<string,string>
	 */
	private function normalize_roles( mixed $roles ): array {
		if ( ! is_array( $roles ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $roles as $slug => $role ) {
			if ( ! is_string( $slug ) || ! is_array( $role ) ) {
				continue;
			}

			$name                  = is_string( $role['name'] ?? null ) && '' !== $role['name'] ? $role['name'] : $this->human_label( $slug );
			$normalized[ $slug ] = $name;
		}

		ksort( $normalized );
		return $normalized;
	}

	/**
	 * @return list<array{menuSlug:string,label:string,position:int}>
	 */
	private function top_level_menu_items(): array {
		$rows = array_values( $this->top_level_menu_items_by_slug() );
		usort( $rows, static fn( array $left, array $right ): int => $left['position'] <=> $right['position'] );
		return $rows;
	}

	/**
	 * @return array<string,array{menuSlug:string,label:string,position:int}>
	 */
	private function top_level_menu_items_by_slug(): array {
		$menu = is_array( $GLOBALS['menu'] ?? null ) ? $GLOBALS['menu'] : array();
		if ( array() === $menu ) {
			$menu = $this->default_menu_items();
		}

		$rows  = array();
		$index = 0;
		foreach ( $menu as $position => $item ) {
			if ( ! is_array( $item ) || ! is_string( $item[2] ?? null ) || '' === $item[2] ) {
				++$index;
				continue;
			}

			$slug = $item[2];
			$rows[ $slug ] = array(
				'menuSlug' => $slug,
				'label'    => $this->menu_label( $item ),
				'position' => is_int( $position ) ? $position : $index,
			);
			++$index;
		}

		return $rows;
	}

	/**
	 * @return list<array<int,mixed>>
	 */
	private function default_menu_items(): array {
		return array(
			2  => array( 'Dashboard', 'read', 'index.php' ),
			5  => array( 'Posts', 'edit_posts', 'edit.php' ),
			10 => array( 'Media', 'upload_files', 'upload.php' ),
			20 => array( 'Pages', 'edit_pages', 'edit.php?post_type=page' ),
			25 => array( 'Comments', 'moderate_comments', 'edit-comments.php' ),
			60 => array( 'Appearance', 'switch_themes', 'themes.php' ),
			65 => array( 'Plugins', 'activate_plugins', 'plugins.php' ),
			70 => array( 'Users', 'list_users', 'users.php' ),
			75 => array( 'Tools', 'edit_posts', 'tools.php' ),
			80 => array( 'Settings', 'manage_options', 'options-general.php' ),
		);
	}

	/**
	 * @param array<int,mixed> $item Menu item.
	 */
	private function menu_label( array $item ): string {
		$label = is_string( $item[0] ?? null ) ? $this->strip_tags( $item[0] ) : '';
		return '' === $label && is_string( $item[2] ?? null ) ? $item[2] : $label;
	}

	private function active_role(): ?string {
		$configured = array_keys( $this->per_role_settings() );
		if ( array() === $configured ) {
			return null;
		}

		foreach ( $this->current_user_roles() as $role ) {
			if ( in_array( $role, $configured, true ) ) {
				return $role;
			}
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	private function current_user_roles(): array {
		if ( function_exists( 'wp_get_current_user' ) ) {
			$user  = \wp_get_current_user();
			$roles = is_object( $user ) && is_array( $user->roles ?? null ) ? $user->roles : array();
			return $this->sanitize_key_list( $roles );
		}

		return array();
	}

	/**
	 * @return list<string>
	 */
	private function menu_item_id( string $role, string $slug ): string {
		return rawurlencode( $role ) . '|' . rawurlencode( $slug );
	}

	/**
	 * @return array{role:string,menuSlug:string}
	 */
	private function parse_menu_item_id( string $id ): array {
		$parts = explode( '|', $id, 2 );
		if ( 2 !== count( $parts ) ) {
			throw Errors::invariant( 'Menu item ID is invalid.' );
		}

		$role = $this->sanitize_key( rawurldecode( $parts[0] ) );
		$slug = $this->string_value( rawurldecode( $parts[1] ) );
		if ( '' === $role || '' === $slug ) {
			throw Errors::invariant( 'Menu item ID is invalid.' );
		}

		return array(
			'role'     => $role,
			'menuSlug' => $slug,
		);
	}

	private function nullable_integer( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_int( $value ) ) {
			return 0 < $value ? $value : null;
		}

		if ( is_numeric( $value ) ) {
			$number = (int) $value;
			return 0 < $number ? $number : null;
		}

		return null;
	}

	private function timestamp_value( mixed $value ): ?int {
		if ( is_int( $value ) && 0 < $value ) {
			return $value;
		}

		if ( is_string( $value ) && is_numeric( $value ) ) {
			$timestamp = (int) $value;
			return 0 < $timestamp ? $timestamp : null;
		}

		return null;
	}

	private function timestamp_label( ?int $timestamp ): string {
		if ( null === $timestamp ) {
			return 'Never';
		}

		return gmdate( 'Y-m-d H:i', $timestamp );
	}

	private function current_timestamp(): int {
		if ( function_exists( 'current_time' ) ) {
			return (int) \current_time( 'timestamp' );
		}

		return time();
	}

	private function sanitize_key( string $value ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return \sanitize_key( $value );
		}

		return strtolower( preg_replace( '/[^a-zA-Z0-9_\\-]/', '', $value ) ?? '' );
	}

	/**
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	private function sanitize_key_list( mixed $value ): array {
		return $this->string_list( $value, fn( string $item ): string => $this->sanitize_key( $item ) );
	}

	private function strip_tags( string $value ): string {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return trim( \wp_strip_all_tags( $value ) );
		}

		return trim( strip_tags( $value ) );
	}

	private function human_label( string $value ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $value ) );
	}

	private function string_value( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
