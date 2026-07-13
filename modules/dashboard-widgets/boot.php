<?php
/**
 * Dashboard Widgets module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\DashboardWidgets;

use Onumia\Core\Errors;
use Onumia\Modules\Attributes\Action;
use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Entries;
use Onumia\Modules\Attributes\EntryField;
use Onumia\Modules\Attributes\EntrySection;
use Onumia\Modules\Attributes\Input;
use Onumia\Modules\Attributes\ModuleContract;
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
#[Setting( 'custom', SettingType::Array, default: array() )]
final class DashboardWidgets extends Module {
	private const DEFAULT_WIDGETS = array(
		'dashboard_activity'    => 'Activity',
		'dashboard_primary'     => 'WordPress Events and News',
		'dashboard_quick_press' => 'Quick Draft',
		'dashboard_right_now'   => 'At a Glance',
		'dashboard_site_health' => 'Site Health Status',
	);

	private const DASHBOARD_CONTEXTS = array( 'normal', 'side', 'column3', 'column4', 'advanced' );

	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'roles', shape: DataSourceShape::Collection, pagination: PaginationMode::Client )]
	#[Entries( name: 'roleVisibility', singular: 'Role', plural: 'Roles', key: 'role', storage: EntryStorage::Manual, source: 'roles', update_action: 'saveRoleVisibility' )]
	#[EntrySection( name: 'widgets', label: 'Widgets', description: 'Widgets hidden for this role.', order: 10, layout: 'tabs' )]
	#[EntryField( name: 'label', type: SettingType::String, label: 'Role', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true, section: 'widgets', order: 10 )]
	#[EntryField( name: 'role', type: SettingType::String, label: 'Role slug', primary: true, required: true, list: false, create: false, update: false, read_only: true, section: 'widgets', order: 20 )]
	#[EntryField( name: 'hidden', type: SettingType::Array, label: 'Hidden widgets', default: array(), optionsSource: array( 'source' => 'module.dashboardWidgets' ), section: 'widgets', order: 30, props: array( 'helpText' => 'Hidden widget IDs are removed from the dashboard for users with this role.' ) )]
	#[EntryField( name: 'visibleCount', type: SettingType::Integer, label: 'Visible', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true, section: 'widgets', order: 40 )]
	#[EntryField( name: 'hiddenCount', type: SettingType::Integer, label: 'Hidden', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true, section: 'widgets', order: 50 )]
	public function role_rows(): array {
		$widgets = $this->dashboard_widget_options();
		$total   = count( $widgets );
		$roles   = $this->roles();
		$rows    = array();

		foreach ( $roles as $role => $label ) {
			$hidden = $this->hidden_widgets_for_role( $role );
			$rows[] = array(
				'role'         => $role,
				'label'        => $label,
				'hidden'       => $hidden,
				'visibleCount' => max( 0, $total - count( $hidden ) ),
				'hiddenCount'  => count( $hidden ),
			);
		}

		usort( $rows, static fn( array $left, array $right ): int => ( $left['label'] ?? '' ) <=> ( $right['label'] ?? '' ) );
		return $rows;
	}

	/**
	 * @return list<array{value:string,label:string}>
	 */
	#[DataSource( 'dashboardWidgets', shape: DataSourceShape::Options, pagination: PaginationMode::Client )]
	public function dashboard_widget_options(): array {
		$widgets = $this->registered_dashboard_widgets();
		foreach ( self::DEFAULT_WIDGETS as $id => $label ) {
			$widgets[ $id ] ??= $label;
		}

		asort( $widgets );

		$options = array();
		foreach ( $widgets as $id => $label ) {
			$options[] = array(
				'value' => $id,
				'label' => $label,
			);
		}

		return $options;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'customWidgets', shape: DataSourceShape::Collection, pagination: PaginationMode::Client )]
	#[Entries( name: 'customWidgets', singular: 'Custom widget', plural: 'Custom widgets', key: 'id', storage: EntryStorage::Manual, source: 'customWidgets', create_action: 'saveCustomWidget', update_action: 'saveCustomWidget', delete_action: 'deleteCustomWidgets' )]
	#[EntrySection( name: 'identity', label: 'Identity', description: 'Dashboard widget identity.', order: 10, layout: 'tabs' )]
	#[EntrySection( name: 'content', label: 'Content', description: 'Raw HTML content shown in the widget.', order: 20, layout: 'tabs' )]
	#[EntrySection( name: 'audience', label: 'Audience', description: 'Roles allowed to see this widget.', order: 30, layout: 'tabs' )]
	#[EntryField( name: 'title', type: SettingType::String, label: 'Title', required: true, list: true, filter: true, filter_type: 'text', section: 'identity', order: 10 )]
	#[EntryField(
		name: 'id',
		type: SettingType::String,
		label: 'ID',
		primary: true,
		required: true,
		list: false,
		filter: true,
		filter_type: 'text',
		section: 'identity',
		order: 20,
		props: array(
			'autoSuggest' => array(
				'from'     => 'title',
				'strategy' => 'slug',
			),
		)
	)]
	#[EntryField( name: 'enabled', type: SettingType::Boolean, label: 'Enabled', default: true, section: 'identity', order: 30 )]
	#[EntryField( name: 'enabledLabel', type: SettingType::String, label: 'Enabled', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'identity', order: 40 )]
	#[EntryField( name: 'order', type: SettingType::Integer, label: 'Order', default: 10, list: true, filter: true, filter_type: 'number', section: 'identity', order: 50 )]
	#[EntryField(
		name: 'html',
		type: SettingType::String,
		label: 'HTML',
		default: '',
		section: 'content',
		order: 10,
		props: array(
			'multiline' => true,
			'rows'      => 10,
			'helpText'  => 'Raw HTML is rendered as-is. Only users with manage_options can edit this content.',
		)
	)]
	#[EntryField( name: 'visibleToRoles', type: SettingType::Array, label: 'Visible to roles', default: array(), optionsSource: array( 'source' => 'wp.user.roles' ), section: 'audience', order: 10, props: array( 'helpText' => 'Leave empty to show the widget to every role.' ) )]
	#[EntryField( name: 'rolesLabel', type: SettingType::String, label: 'Audience', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true, section: 'audience', order: 20 )]
	public function custom_widget_rows(): array {
		$rows = array_map( array( $this, 'normalize_custom_widget' ), $this->custom_settings() );
		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$order = (int) ( $left['order'] ?? 0 ) <=> (int) ( $right['order'] ?? 0 );
				return 0 !== $order ? $order : ( (string) ( $left['title'] ?? '' ) <=> (string) ( $right['title'] ?? '' ) );
			}
		);
		return array_values( $rows );
	}

	/**
	 * @param array{role:string,hidden:array<mixed>} $input Input.
	 * @return array{ok:bool,role:string,hidden:list<string>}
	 */
	#[Action( 'saveRoleVisibility' )]
	#[Input( 'role', SettingType::String, required: true )]
	#[Input( 'hidden', SettingType::Array, default: array() )]
	public function save_role_visibility( array $input ): array {
		$role = $this->sanitize_key( (string) ( $input['role'] ?? '' ) );
		if ( '' === $role || ! array_key_exists( $role, $this->roles() ) ) {
			throw Errors::invariant( "Unknown role {$role}." );
		}

		$hidden   = $this->sanitize_key_list( $input['hidden'] ?? array() );
		$per_role = $this->per_role_settings();
		if ( array() === $hidden ) {
			unset( $per_role[ $role ] );
		} else {
			$per_role[ $role ] = array( 'hidden' => $hidden );
		}

		$this->persist(
			array(
				'perRole' => $per_role,
				'custom'  => $this->custom_settings(),
			)
		);

		return array(
			'ok'     => true,
			'role'   => $role,
			'hidden' => $hidden,
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{ok:bool,id:string}
	 */
	#[Action( 'saveCustomWidget' )]
	#[Input( 'id', SettingType::String, required: true )]
	#[Input( 'title', SettingType::String, required: true )]
	#[Input( 'enabled', SettingType::Boolean, default: true )]
	#[Input( 'order', SettingType::Integer, default: 10 )]
	#[Input( 'html', SettingType::String, default: '' )]
	#[Input( 'visibleToRoles', SettingType::Array, default: array() )]
	public function save_custom_widget( array $input ): array {
		$row  = $this->custom_widget_from_input( $input );
		$rows = $this->custom_widgets_by_id();

		$rows[ $row['id'] ] = $row;
		$this->persist(
			array(
				'perRole' => $this->per_role_settings(),
				'custom'  => array_values( $rows ),
			)
		);

		return array(
			'ok' => true,
			'id' => $row['id'],
		);
	}

	/**
	 * @param array{ids:array<mixed>} $input Input.
	 * @return array{ok:bool,deleted:list<string>}
	 */
	#[Action( 'deleteCustomWidgets' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function delete_custom_widgets( array $input ): array {
		$ids     = $this->sanitize_key_list( $input['ids'] ?? array() );
		$rows    = $this->custom_widgets_by_id();
		$deleted = array();

		foreach ( $ids as $id ) {
			if ( ! array_key_exists( $id, $rows ) ) {
				continue;
			}

			unset( $rows[ $id ] );
			$deleted[] = $id;
		}

		$this->persist(
			array(
				'perRole' => $this->per_role_settings(),
				'custom'  => array_values( $rows ),
			)
		);

		return array(
			'ok'      => true,
			'deleted' => $deleted,
		);
	}

	#[WpAction( 'wp_dashboard_setup', priority: 999, accepted_args: 0 )]
	public function apply_dashboard_widgets(): void {
		foreach ( $this->hidden_widgets_for_current_user() as $widget_id ) {
			foreach ( self::DASHBOARD_CONTEXTS as $context ) {
				if ( function_exists( 'remove_meta_box' ) ) {
					\remove_meta_box( $widget_id, 'dashboard', $context );
				}
			}
		}

		foreach ( $this->custom_widget_rows() as $row ) {
			if ( ! (bool) ( $row['enabled'] ?? false ) || ! $this->current_user_can_see_custom_widget( $row ) ) {
				continue;
			}

			if ( function_exists( 'wp_add_dashboard_widget' ) ) {
				$html = (string) ( $row['html'] ?? '' );
				\wp_add_dashboard_widget(
					'onumia_' . (string) $row['id'],
					(string) $row['title'],
					static function () use ( $html ): void {
						echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw HTML is a deliberate manage_options-only module feature.
					}
				);
			}
		}
	}

	/**
	 * @return array<string,array{hidden:list<string>}>
	 */
	private function per_role_settings(): array {
		$value = $this->setting( 'perRole' );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$settings = array();
		foreach ( $value as $role => $record ) {
			if ( ! is_string( $role ) || ! is_array( $record ) ) {
				continue;
			}

			$hidden = $this->sanitize_key_list( $record['hidden'] ?? array() );
			if ( array() === $hidden ) {
				continue;
			}

			$settings[ $this->sanitize_key( $role ) ] = array( 'hidden' => $hidden );
		}

		return $settings;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function custom_settings(): array {
		return array_map( array( $this, 'normalize_custom_widget' ), $this->array_setting( 'custom' ) );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function custom_widgets_by_id(): array {
		$rows = array();
		foreach ( $this->custom_settings() as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( '' !== $id ) {
				$rows[ $id ] = $row;
			}
		}

		return $rows;
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
	 * @return array<string,string>
	 */
	private function registered_dashboard_widgets(): array {
		$wp_meta_boxes = $GLOBALS['wp_meta_boxes']['dashboard'] ?? null;
		if ( ! is_array( $wp_meta_boxes ) ) {
			return array();
		}

		$widgets = array();
		foreach ( $wp_meta_boxes as $context_boxes ) {
			if ( ! is_array( $context_boxes ) ) {
				continue;
			}

			foreach ( $context_boxes as $priority_boxes ) {
				if ( ! is_array( $priority_boxes ) ) {
					continue;
				}

				foreach ( $priority_boxes as $id => $box ) {
					if ( ! is_string( $id ) || '' === $id ) {
						continue;
					}

					$widgets[ $id ] = is_array( $box ) && is_string( $box['title'] ?? null ) && '' !== $box['title'] ? wp_strip_all_tags( $box['title'] ) : $this->human_label( $id );
				}
			}
		}

		return $widgets;
	}

	/**
	 * @return list<string>
	 */
	private function hidden_widgets_for_role( string $role ): array {
		return $this->per_role_settings()[ $role ]['hidden'] ?? array();
	}

	/**
	 * @return list<string>
	 */
	private function hidden_widgets_for_current_user(): array {
		$hidden = array();
		foreach ( $this->current_user_roles() as $role ) {
			$hidden = array_merge( $hidden, $this->hidden_widgets_for_role( $role ) );
		}

		return array_values( array_unique( $hidden ) );
	}

	/**
	 * @param array<string,mixed> $row Row.
	 */
	private function current_user_can_see_custom_widget( array $row ): bool {
		$allowed = $this->sanitize_key_list( $row['visibleToRoles'] ?? array() );
		if ( array() === $allowed ) {
			return true;
		}

		return array() !== array_intersect( $allowed, $this->current_user_roles() );
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
	 * @param array<string,mixed> $input Input.
	 * @return array{id:string,title:string,enabled:bool,enabledLabel:string,order:int,html:string,visibleToRoles:list<string>,rolesLabel:string}
	 */
	private function custom_widget_from_input( array $input ): array {
		$title = $this->string_value( $input['title'] ?? '' );
		$id    = $this->sanitize_key( $this->string_value( $input['id'] ?? '' ) );
		if ( '' === $id ) {
			$id = $this->sanitize_key( $title );
		}

		if ( '' === $id || '' === $title ) {
			throw Errors::invariant( 'Custom dashboard widgets require an ID and title.' );
		}

		return $this->normalize_custom_widget(
			array(
				'id'             => $id,
				'title'          => $title,
				'enabled'        => (bool) ( $input['enabled'] ?? true ),
				'order'          => (int) ( $input['order'] ?? 10 ),
				'html'           => $this->string_value( $input['html'] ?? '' ),
				'visibleToRoles' => $this->sanitize_key_list( $input['visibleToRoles'] ?? array() ),
			)
		);
	}

	/**
	 * @param mixed $row Row.
	 * @return array{id:string,title:string,enabled:bool,enabledLabel:string,order:int,html:string,visibleToRoles:list<string>,rolesLabel:string}
	 */
	private function normalize_custom_widget( mixed $row ): array {
		$row   = is_array( $row ) ? $row : array();
		$id    = $this->sanitize_key( $this->string_value( $row['id'] ?? '' ) );
		$title = $this->string_value( $row['title'] ?? '' );
		$roles = $this->sanitize_key_list( $row['visibleToRoles'] ?? array() );

		return array(
			'id'             => '' === $id ? 'dashboard-widget' : $id,
			'title'          => '' === $title ? 'Dashboard widget' : $title,
			'enabled'        => (bool) ( $row['enabled'] ?? true ),
			'enabledLabel'   => false === (bool) ( $row['enabled'] ?? true ) ? 'No' : 'Yes',
			'order'          => (int) ( $row['order'] ?? 10 ),
			'html'           => $this->string_value( $row['html'] ?? '' ),
			'visibleToRoles' => $roles,
			'rolesLabel'     => array() === $roles ? 'All roles' : implode( ', ', array_map( fn( string $role ): string => $this->roles()[ $role ] ?? $this->human_label( $role ), $roles ) ),
		);
	}

	/**
	 * @param array<string,mixed> $settings Settings.
	 */
	private function persist( array $settings ): void {
		( new ModuleSettingsRepository() )->update_settings( $this->definition(), $settings );
	}

	/**
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	private function string_value( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( (string) $value );
	}

	private function sanitize_key( string $value ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return \sanitize_key( $value );
		}

		return strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '-', $value ) ?? '' );
	}

	/**
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	private function sanitize_key_list( mixed $value ): array {
		return $this->string_list( $value, fn( string $item ): string => $this->sanitize_key( $item ) );
	}

	private function human_label( string $value ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $value ) );
	}
}
