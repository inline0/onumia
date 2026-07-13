<?php
/**
 * User Roles module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\UserRoles;

use Onumia\Core\Errors;
use Onumia\Modules\Attributes\Action;
use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Entries;
use Onumia\Modules\Attributes\EntryField;
use Onumia\Modules\Attributes\EntrySection;
use Onumia\Modules\Attributes\Input;
use Onumia\Modules\Attributes\ModuleContract;
use Onumia\Modules\Attributes\ObjectShape;
use Onumia\Modules\Attributes\Setting;
use Onumia\Modules\Attributes\WpAction;
use Onumia\Modules\Contracts\DataSourceShape;
use Onumia\Modules\Contracts\EntryStorage;
use Onumia\Modules\Contracts\PaginationMode;
use Onumia\Modules\Contracts\SettingType;
use Onumia\Modules\Module;
use Onumia\Modules\ModuleSettingsRepository;

#[ModuleContract( capability: 'manage_options' )]
#[Setting( 'roles', SettingType::Array, default: array() )]
#[Setting( 'overrides', SettingType::Object, default: array() )]
final class UserRoles extends Module {
	private const CORE_ROLE_SLUGS = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
	private const CORE_CAPABILITIES = array(
		'activate_plugins',
		'create_users',
		'delete_others_pages',
		'delete_others_posts',
		'delete_pages',
		'delete_plugins',
		'delete_posts',
		'delete_private_pages',
		'delete_private_posts',
		'delete_published_pages',
		'delete_published_posts',
		'delete_themes',
		'delete_users',
		'edit_dashboard',
		'edit_files',
		'edit_others_pages',
		'edit_others_posts',
		'edit_pages',
		'edit_plugins',
		'edit_posts',
		'edit_private_pages',
		'edit_private_posts',
		'edit_published_pages',
		'edit_published_posts',
		'edit_theme_options',
		'edit_themes',
		'edit_users',
		'export',
		'import',
		'install_plugins',
		'install_themes',
		'list_users',
		'manage_categories',
		'manage_links',
		'manage_options',
		'moderate_comments',
		'promote_users',
		'publish_pages',
		'publish_posts',
		'read',
		'read_private_pages',
		'read_private_posts',
		'remove_users',
		'switch_themes',
		'unfiltered_html',
		'unfiltered_upload',
		'update_core',
		'update_plugins',
		'update_themes',
		'upload_files',
	);

	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'roles', shape: DataSourceShape::Collection, pagination: PaginationMode::Client )]
	#[Entries( name: 'roles', singular: 'Role', plural: 'Roles', key: 'slug', storage: EntryStorage::Manual, source: 'roles', create_action: 'saveRole', update_action: 'saveRole', delete_action: 'deleteRoles' )]
	#[EntrySection( name: 'identity', label: 'Identity', description: 'Role identity and ownership.', order: 10, layout: 'tabs' )]
	#[EntrySection( name: 'capabilities', label: 'Capabilities', description: 'Boolean capability grants for this role.', order: 20, layout: 'tabs' )]
	#[EntryField( name: 'label', type: SettingType::String, label: 'Label', required: true, list: true, filter: true, filter_type: 'text', section: 'identity', order: 10 )]
	#[EntryField(
		name: 'slug',
		type: SettingType::String,
		label: 'Slug',
		primary: true,
		required: true,
		list: true,
		filter: true,
		filter_type: 'text',
		section: 'identity',
		order: 20,
		props: array(
			'autoSuggest'              => array(
				'from'     => 'label',
				'strategy' => 'slug',
			),
			'confirmChangeDescription' => 'Renaming a role can orphan user assignments. Confirm only after users have been migrated.',
			'confirmChangeLabel'       => 'Rename slug',
			'confirmChangeTitle'       => 'Rename role slug?',
			'confirmOnChange'          => true,
			'lockedHelpText'           => 'Registered role slugs are owned by WordPress or another plugin and cannot be changed here.',
			'lockedOrigins'            => array( 'builtin', 'external', 'override' ),
			'mutablePrimary'           => true,
			'originalInput'            => 'originalSlug',
		)
	)]
	#[EntryField( name: 'origin', type: SettingType::String, label: 'Origin', default: 'custom', allowed: array( 'builtin', 'external', 'override', 'custom' ), create: false, update: true, read_only: true, section: 'identity', order: 30 )]
	#[EntryField( name: 'originLabel', type: SettingType::String, label: 'Origin', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'identity', order: 40 )]
	#[EntryField( name: 'userCount', type: SettingType::Integer, label: 'Users', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true, section: 'identity', order: 50 )]
	#[EntryField( name: 'capabilityCount', type: SettingType::Integer, label: 'Capabilities', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true, section: 'identity', order: 60 )]
	#[EntryField(
		name: 'capabilities',
		type: SettingType::Object,
		label: 'Capabilities',
		default: array(),
		optionsSource: array( 'source' => 'capabilities' ),
		section: 'capabilities',
		order: 10,
		props: array(
			'editor'    => 'keyValue',
			'valueType' => 'boolean',
		)
	)]
	public function role_rows(): array {
		$registered = $this->registered_roles();
		$custom     = $this->custom_roles_by_slug();
		$overrides  = $this->overrides_by_slug();
		$user_counts = $this->user_counts_by_role();
		$rows       = array();

		foreach ( $registered as $slug => $role ) {
			$row = $this->row_from_registered( $slug, $role, $user_counts[ $slug ] ?? 0 );
			if ( isset( $overrides[ $slug ] ) ) {
				$row = $this->merge_role_row( $row, $overrides[ $slug ] );
				$row['origin'] = 'override';
			}

			$rows[ $slug ] = $this->decorate_row( $row );
		}

		foreach ( $custom as $slug => $row ) {
			$merged = isset( $registered[ $slug ] )
				? $this->merge_role_row( $this->row_from_registered( $slug, $registered[ $slug ], $user_counts[ $slug ] ?? 0 ), $row )
				: $row;
			$merged['origin']    = 'custom';
			$merged['userCount'] = $user_counts[ $slug ] ?? (int) ( $merged['userCount'] ?? 0 );
			$rows[ $slug ]      = $this->decorate_row( $merged );
		}

		usort( $rows, static fn( array $left, array $right ): int => ( (string) ( $left['label'] ?? $left['slug'] ?? '' ) ) <=> ( (string) ( $right['label'] ?? $right['slug'] ?? '' ) ) );
		return array_values( $rows );
	}

	/**
	 * @return list<array{value:string,label:string}>
	 */
	#[DataSource( 'capabilities', shape: DataSourceShape::Options )]
	public function capability_options(): array {
		$options = array();
		foreach ( $this->capability_allowlist() as $capability ) {
			$options[] = array(
				'value' => $capability,
				'label' => $this->human_label( $capability ),
			);
		}

		return $options;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{ok:bool,slug:string,origin:string}
	 */
	#[Action( 'saveRole' )]
	#[Input( 'slug', SettingType::String, required: true )]
	#[Input( 'originalSlug', SettingType::String, default: '' )]
	#[Input( 'origin', SettingType::String, default: 'custom', allowed: array( 'builtin', 'external', 'override', 'custom' ) )]
	#[Input( 'label', SettingType::String, required: true )]
	#[Input( 'capabilities', SettingType::Object, default: array() )]
	#[ObjectShape( name: 'capabilities', fields: array( '*' => 'boolean' ) )]
	public function save_role( array $input ): array {
		$row           = $this->stored_row_from_input( $input );
		$original_slug = $this->sanitize_slug( (string) ( $input['originalSlug'] ?? '' ) );
		$removed_slug  = null;

		( new ModuleSettingsRepository() )->update_settings_with(
			$this->definition(),
			function ( array $current ) use ( $row, $original_slug, &$removed_slug ): array {
				$roles     = $this->custom_roles_by_slug_from( $current['roles'] ?? array() );
				$overrides = $this->overrides_by_slug_from( $current['overrides'] ?? array() );

				$this->assert_slug_can_be_saved( $row['slug'], $original_slug, $roles );

				if ( 'custom' === $row['origin'] ) {
					if ( '' !== $original_slug && $original_slug !== $row['slug'] ) {
						unset( $roles[ $original_slug ] );
						$removed_slug = $original_slug;
					}

					$roles[ $row['slug'] ] = $row;
					unset( $overrides[ $row['slug'] ] );
				} else {
					$overrides[ $row['slug'] ] = array(
						'label'        => $row['label'],
						'capabilities' => $row['capabilities'],
					);
				}

				return array(
					'roles'     => array_values( $roles ),
					'overrides' => $overrides,
				);
			}
		);

		if ( null !== $removed_slug ) {
			$this->remove_role( $removed_slug );
		}

		$this->apply_role_row( $row );

		return array(
			'ok'     => true,
			'slug'   => $row['slug'],
			'origin' => $row['origin'],
		);
	}

	/**
	 * @param array{ids:array<mixed>} $input Input.
	 * @return array{ok:bool,deleted:list<string>}
	 */
	#[Action( 'deleteRoles' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function delete_roles( array $input ): array {
		$ids     = $this->string_list( $input['ids'] ?? array() );
		$deleted = array();
		$removed = array();

		( new ModuleSettingsRepository() )->update_settings_with(
			$this->definition(),
			function ( array $current ) use ( $ids, &$deleted, &$removed ): array {
				$roles     = $this->custom_roles_by_slug_from( $current['roles'] ?? array() );
				$overrides = $this->overrides_by_slug_from( $current['overrides'] ?? array() );

				foreach ( $ids as $slug ) {
					if ( isset( $roles[ $slug ] ) ) {
						unset( $roles[ $slug ] );
						$removed[] = $slug;
						$deleted[] = $slug;
						continue;
					}

					if ( isset( $overrides[ $slug ] ) ) {
						unset( $overrides[ $slug ] );
						$deleted[] = $slug;
						continue;
					}

					if ( isset( $this->registered_roles()[ $slug ] ) ) {
						throw Errors::invariant( "Role {$slug} is not owned by Onumia and cannot be deleted." );
					}
				}

				return array(
					'roles'     => array_values( $roles ),
					'overrides' => $overrides,
				);
			}
		);

		foreach ( $removed as $slug ) {
			$this->remove_role( $slug );
		}

		return array(
			'ok'      => true,
			'deleted' => $deleted,
		);
	}

	#[WpAction( 'init', priority: 9 )]
	public function apply_roles(): void {
		foreach ( $this->custom_roles_by_slug() as $row ) {
			$this->apply_role_row( $row );
		}

		foreach ( $this->overrides_by_slug() as $slug => $override ) {
			$registered = $this->registered_roles()[ $slug ] ?? null;
			if ( ! is_array( $registered ) ) {
				continue;
			}

			$row = $this->merge_role_row( $this->row_from_registered( $slug, $registered, 0 ), $override );
			$row['origin'] = 'override';
			$this->apply_role_row( $row );
		}
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function registered_roles(): array {
		if ( function_exists( 'get_editable_roles' ) ) {
			return $this->normalize_registered_roles( \get_editable_roles() );
		}

		if ( function_exists( 'wp_roles' ) ) {
			$wp_roles = \wp_roles();
			return $this->normalize_registered_roles( $wp_roles->roles ?? array() );
		}

		if ( isset( $GLOBALS['wp_roles'] ) && is_object( $GLOBALS['wp_roles'] ) ) {
			return $this->normalize_registered_roles( $GLOBALS['wp_roles']->roles ?? array() );
		}

		return array(
			'administrator' => array(
				'label'        => 'Administrator',
				'origin'       => 'builtin',
				'capabilities' => array(
					'read'           => true,
					'manage_options' => true,
					'edit_posts'     => true,
				),
			),
			'editor'        => array(
				'label'        => 'Editor',
				'origin'       => 'builtin',
				'capabilities' => array(
					'read'              => true,
					'edit_posts'        => true,
					'manage_categories' => true,
				),
			),
			'subscriber'    => array(
				'label'        => 'Subscriber',
				'origin'       => 'builtin',
				'capabilities' => array( 'read' => true ),
			),
		);
	}

	/**
	 * @param mixed $roles Roles.
	 * @return array<string,array<string,mixed>>
	 */
	private function normalize_registered_roles( mixed $roles ): array {
		if ( ! is_array( $roles ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $roles as $slug => $role ) {
			if ( ! is_string( $slug ) || ! is_array( $role ) ) {
				continue;
			}

			$normalized[ $slug ] = array(
				'label'        => is_string( $role['name'] ?? null ) && '' !== $role['name'] ? $role['name'] : $this->human_label( $slug ),
				'origin'       => in_array( $slug, self::CORE_ROLE_SLUGS, true ) ? 'builtin' : 'external',
				'capabilities' => $this->bool_map( $role['capabilities'] ?? array() ),
			);
		}

		return $normalized;
	}

	/**
	 * @return array<string,int>
	 */
	private function user_counts_by_role(): array {
		if ( function_exists( 'count_users' ) ) {
			$counts = \count_users();
			if ( is_array( $counts ) && is_array( $counts['avail_roles'] ?? null ) ) {
				return array_map( 'intval', $counts['avail_roles'] );
			}
		}

		return array();
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function custom_roles_by_slug(): array {
		return $this->custom_roles_by_slug_from( $this->array_setting( 'roles' ) );
	}

	/**
	 * @param mixed $value Stored custom role rows.
	 * @return array<string,array<string,mixed>>
	 */
	private function custom_roles_by_slug_from( mixed $value ): array {
		$rows = array();
		if ( ! is_array( $value ) ) {
			return $rows;
		}

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['slug'] ?? null ) ) {
				continue;
			}

			$normalized = $this->normalize_stored_row( $row, 'custom' );
			if ( '' !== $normalized['slug'] ) {
				$rows[ $normalized['slug'] ] = $normalized;
			}
		}

		return $rows;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function overrides_by_slug(): array {
		return $this->overrides_by_slug_from( $this->setting( 'overrides' ) );
	}

	/**
	 * @param mixed $value Stored role overrides.
	 * @return array<string,array<string,mixed>>
	 */
	private function overrides_by_slug_from( mixed $value ): array {
		$rows = array();
		if ( ! is_array( $value ) ) {
			return $rows;
		}

		foreach ( $value as $slug => $row ) {
			if ( ! is_string( $slug ) || ! is_array( $row ) ) {
				continue;
			}

			$normalized = $this->normalize_stored_row( array_merge( $row, array( 'slug' => $slug ) ), 'override' );
			if ( '' !== $normalized['slug'] ) {
				$rows[ $normalized['slug'] ] = $normalized;
			}
		}

		return $rows;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	private function stored_row_from_input( array $input ): array {
		$raw_slug = (string) ( $input['slug'] ?? '' );
		$slug     = $this->sanitize_slug( $raw_slug );
		if ( '' === $slug ) {
			throw Errors::invariant( 'Role slug is required.' );
		}

		if ( $raw_slug !== $slug || ! preg_match( '/^[a-z0-9_]{1,64}$/', $slug ) ) {
			throw Errors::invariant( 'Role slug must use 1-64 lowercase letters, numbers, or underscores.' );
		}

		$origin = $this->origin_value( $input['origin'] ?? 'custom' );
		if ( 'builtin' === $origin || 'external' === $origin ) {
			$origin = 'override';
		}

		if ( 'override' !== $origin && isset( $this->registered_roles()[ $slug ] ) && ! isset( $this->custom_roles_by_slug()[ $slug ] ) ) {
			$origin = 'override';
		}

		return $this->normalize_stored_row(
			array(
				'slug'         => $slug,
				'label'        => trim( (string) ( $input['label'] ?? $slug ) ),
				'origin'       => $origin,
				'capabilities' => $input['capabilities'] ?? array(),
			),
			$origin
		);
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function normalize_stored_row( array $row, string $default_origin ): array {
		$slug  = $this->sanitize_slug( (string) ( $row['slug'] ?? '' ) );
		$label = trim( (string) ( $row['label'] ?? $slug ) );

		return array(
			'slug'         => $slug,
			'label'        => '' === $label ? $this->human_label( $slug ) : $label,
			'origin'       => $this->origin_value( $row['origin'] ?? $default_origin ),
			'capabilities' => $this->allowed_bool_map( $row['capabilities'] ?? array() ),
		);
	}

	/**
	 * @param array<string,mixed> $base Base role.
	 * @param array<string,mixed> $override Override role.
	 * @return array<string,mixed>
	 */
	private function merge_role_row( array $base, array $override ): array {
		$base_capabilities     = $this->bool_map( $base['capabilities'] ?? array() );
		$override_capabilities = $this->allowed_bool_map( $override['capabilities'] ?? array() );

		return array_merge(
			$base,
			array(
				'label'        => is_string( $override['label'] ?? null ) && '' !== trim( $override['label'] ) ? trim( $override['label'] ) : (string) ( $base['label'] ?? '' ),
				'capabilities' => array_merge( $base_capabilities, $override_capabilities ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $role Registered role row.
	 * @return array<string,mixed>
	 */
	private function row_from_registered( string $slug, array $role, int $user_count ): array {
		return array(
			'slug'         => $slug,
			'label'        => is_string( $role['label'] ?? null ) && '' !== $role['label'] ? $role['label'] : $this->human_label( $slug ),
			'origin'       => $this->origin_value( $role['origin'] ?? ( in_array( $slug, self::CORE_ROLE_SLUGS, true ) ? 'builtin' : 'external' ) ),
			'userCount'    => $user_count,
			'capabilities' => $this->bool_map( $role['capabilities'] ?? array() ),
		);
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function decorate_row( array $row ): array {
		$capabilities = $this->bool_map( $row['capabilities'] ?? array() );
		$origin       = $this->origin_value( $row['origin'] ?? 'custom' );

		ksort( $capabilities );
		return array_merge(
			$row,
			array(
				'origin'          => $origin,
				'originLabel'     => $this->origin_label( $origin ),
				'userCount'       => (int) ( $row['userCount'] ?? 0 ),
				'capabilityCount' => count( array_filter( $capabilities ) ),
				'capabilities'    => $capabilities,
				'canDelete'       => 'custom' === $origin,
			)
		);
	}

	/**
	 * @param array<string,mixed> $roles Custom roles keyed by slug.
	 */
	private function assert_slug_can_be_saved( string $slug, string $original_slug, array $roles ): void {
		if ( '' === $original_slug ) {
			if ( isset( $roles[ $slug ] ) || isset( $this->registered_roles()[ $slug ] ) ) {
				throw Errors::invariant( "Role slug {$slug} already exists." );
			}

			return;
		}

		if ( $original_slug === $slug ) {
			return;
		}

		$original_origin = isset( $roles[ $original_slug ] ) ? 'custom' : ( isset( $this->registered_roles()[ $original_slug ] ) ? 'registered' : 'missing' );
		if ( 'custom' !== $original_origin ) {
			throw Errors::invariant( "Role {$original_slug} is not owned by Onumia and cannot change slug." );
		}

		if ( isset( $roles[ $slug ] ) || isset( $this->registered_roles()[ $slug ] ) ) {
			throw Errors::invariant( "Role slug {$slug} already exists." );
		}
	}

	/**
	 * @param array<string,mixed> $row Row.
	 */
	private function apply_role_row( array $row ): void {
		$slug         = (string) ( $row['slug'] ?? '' );
		$label        = (string) ( $row['label'] ?? $this->human_label( $slug ) );
		$capabilities = $this->allowed_bool_map( $row['capabilities'] ?? array() );
		if ( '' === $slug ) {
			return;
		}

		if ( 'custom' === ( $row['origin'] ?? '' ) ) {
			$this->add_role( $slug, $label, $capabilities );
		} else {
			$this->set_role_label( $slug, $label );
		}

		$role = $this->get_role( $slug );
		if ( null === $role ) {
			return;
		}

		foreach ( $capabilities as $capability => $enabled ) {
			if ( $enabled ) {
				$this->add_cap( $role, $slug, $capability );
			} else {
				$this->remove_cap( $role, $slug, $capability );
			}
		}
	}

	/**
	 * @param array<string,bool> $capabilities Capabilities.
	 */
	private function add_role( string $slug, string $label, array $capabilities ): void {
		if ( function_exists( 'add_role' ) ) {
			\add_role( $slug, $label, $capabilities );
			$this->set_role_label( $slug, $label );
		}
	}

	private function remove_role( string $slug ): void {
		if ( function_exists( 'remove_role' ) ) {
			\remove_role( $slug );
		}
	}

	private function get_role( string $slug ): ?object {
		if ( function_exists( 'get_role' ) ) {
			$role = \get_role( $slug );
			return is_object( $role ) ? $role : null;
		}

		return null;
	}

	private function set_role_label( string $slug, string $label ): void {
		if ( function_exists( 'wp_roles' ) ) {
			$wp_roles = \wp_roles();
			if ( is_object( $wp_roles ) ) {
				if ( is_array( $wp_roles->roles ?? null ) && isset( $wp_roles->roles[ $slug ] ) && is_array( $wp_roles->roles[ $slug ] ) ) {
					$wp_roles->roles[ $slug ]['name'] = $label;
				}
				if ( is_array( $wp_roles->role_names ?? null ) ) {
					$wp_roles->role_names[ $slug ] = $label;
				}
			}
		}

		$role = $this->get_role( $slug );
		if ( is_object( $role ) ) {
			$role->name = $label;
		}
	}

	private function add_cap( object $role, string $slug, string $capability ): void {
		if ( is_callable( array( $role, 'add_cap' ) ) ) {
			$role->add_cap( $capability );
		} else {
			$role->capabilities[ $capability ] = true;
			$role->caps[ $capability ]         = true;
		}
	}

	private function remove_cap( object $role, string $slug, string $capability ): void {
		if ( is_callable( array( $role, 'remove_cap' ) ) ) {
			$role->remove_cap( $capability );
		} else {
			$role->capabilities[ $capability ] = false;
			$role->caps[ $capability ]         = false;
		}
	}

	/**
	 * @return list<string>
	 */
	private function capability_allowlist(): array {
		$capabilities = array_fill_keys( self::CORE_CAPABILITIES, true );

		foreach ( $this->registered_roles() as $role ) {
			foreach ( $this->bool_map( $role['capabilities'] ?? array() ) as $capability => $_enabled ) {
				$capabilities[ $capability ] = true;
			}
		}

		foreach ( $this->registered_post_type_capabilities() as $capability ) {
			$capabilities[ $capability ] = true;
		}

		foreach ( $this->registered_taxonomy_capabilities() as $capability ) {
			$capabilities[ $capability ] = true;
		}

		$keys = array_keys( $capabilities );
		sort( $keys );
		return $keys;
	}

	/**
	 * @return list<string>
	 */
	private function registered_post_type_capabilities(): array {
		$capabilities = array();
		if ( function_exists( 'get_post_types' ) ) {
			$post_types = \get_post_types( array(), 'objects' );
			foreach ( $post_types as $post_type ) {
				if ( is_object( $post_type->cap ?? null ) ) {
					$capabilities = array_merge( $capabilities, $this->object_string_values( $post_type->cap ) );
				}
			}
		}

		return $this->unique_strings( $capabilities );
	}

	/**
	 * @return list<string>
	 */
	private function registered_taxonomy_capabilities(): array {
		$capabilities = array();
		if ( function_exists( 'get_taxonomies' ) ) {
			$taxonomies = \get_taxonomies( array(), 'objects' );
			foreach ( $taxonomies as $taxonomy ) {
				if ( is_object( $taxonomy->cap ?? null ) ) {
					$capabilities = array_merge( $capabilities, $this->object_string_values( $taxonomy->cap ) );
				}
			}
		}

		return $this->unique_strings( $capabilities );
	}

	/**
	 * @return list<string>
	 */
	private function object_string_values( object $object ): array {
		return $this->string_list( get_object_vars( $object ) );
	}

	/**
	 * @param mixed $value Value.
	 * @return array<string,bool>
	 */
	private function allowed_bool_map( mixed $value ): array {
		$allowed = array_fill_keys( $this->capability_allowlist(), true );
		return array_intersect_key( $this->bool_map( $value ), $allowed );
	}

	/**
	 * @param mixed $value Value.
	 * @return array<string,bool>
	 */
	private function bool_map( mixed $value ): array {
		$map = array();
		if ( ! is_array( $value ) ) {
			return $map;
		}

		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && '' !== $key ) {
				$map[ $key ] = true === $item || 1 === $item || '1' === $item || 'true' === $item;
			}
		}

		return $map;
	}

	private function sanitize_slug( string $value ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return \sanitize_key( $value );
		}

		return preg_replace( '/[^a-z0-9_]/', '', strtolower( $value ) ) ?? '';
	}

	private function origin_value( mixed $value ): string {
		return is_string( $value ) && in_array( $value, array( 'builtin', 'external', 'override', 'custom' ), true ) ? $value : 'custom';
	}

	private function origin_label( string $origin ): string {
		return match ( $origin ) {
			'builtin' => 'Built-in',
			'external' => 'External',
			'override' => 'Override',
			default => 'Custom',
		};
	}

	/**
	 * @param list<string> $value Value.
	 * @return list<string>
	 */
	private function unique_strings( array $value ): array {
		$items = array_values( array_unique( array_filter( $value, 'is_string' ) ) );
		sort( $items );
		return $items;
	}

	private function human_label( string $value ): string {
		$label = ucwords( str_replace( array( '_', '-' ), ' ', $value ) );
		return '' === $label ? $value : $label;
	}
}
