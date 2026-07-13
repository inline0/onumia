<?php
/**
 * Post Statuses module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\PostStatuses;

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
use Onumia\Modules\Attributes\WpFilter;
use Onumia\Modules\Contracts\DataSourceShape;
use Onumia\Modules\Contracts\EntryStorage;
use Onumia\Modules\Contracts\PaginationMode;
use Onumia\Modules\Contracts\SettingType;
use Onumia\Modules\Module;
use Onumia\Modules\ModuleSettingsRepository;

#[ModuleContract( capability: 'manage_options' )]
#[Setting( 'statuses', SettingType::Array, default: array() )]
#[Setting( 'overrides', SettingType::Object, default: array() )]
final class PostStatuses extends Module {
	private const BUILTIN_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit', 'request-pending', 'request-confirmed', 'request-failed', 'request-completed' );
	/**
	 * @var array<string,string>
	 */
	private array $last_transition_error = array();

	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'postStatuses', shape: DataSourceShape::Collection, pagination: PaginationMode::Client )]
	#[Entries( name: 'statuses', singular: 'Post status', plural: 'Post statuses', key: 'slug', storage: EntryStorage::Manual, source: 'postStatuses', create_action: 'savePostStatus', update_action: 'savePostStatus', delete_action: 'deletePostStatuses' )]
	#[EntrySection( name: 'identity', label: 'Identity', description: 'Core post status identity.', order: 10, layout: 'tabs' )]
	#[EntrySection( name: 'visibility', label: 'Visibility', description: 'Public and admin-list visibility flags.', order: 20, layout: 'tabs' )]
	#[EntrySection( name: 'behavior', label: 'Behavior', description: 'WordPress behavior flags for protected and internal statuses.', order: 30, layout: 'tabs' )]
	#[EntrySection( name: 'applies', label: 'Applies to', description: 'Post types this workflow status applies to.', order: 40, layout: 'tabs' )]
	#[EntrySection( name: 'transitions', label: 'Transitions', description: 'Statuses that can transition into this status.', order: 50, layout: 'tabs' )]
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
			'confirmChangeDescription' => 'Renaming a registered post status can orphan workflow rules and existing content using the old slug.',
			'confirmChangeLabel'       => 'Rename slug',
			'confirmChangeTitle'       => 'Rename post status slug?',
			'confirmOnChange'          => true,
			'lockedHelpText'           => 'Built-in and external post status slugs are owned by WordPress or another plugin and cannot be changed here.',
			'lockedOrigins'            => array( 'builtin', 'builtin-override', 'external', 'external-override' ),
			'mutablePrimary'           => true,
			'originalInput'            => 'originalSlug',
		)
	)]
	#[EntryField( name: 'origin', type: SettingType::String, label: 'Origin', default: 'custom', allowed: array( 'builtin', 'builtin-override', 'external', 'external-override', 'custom' ), create: false, update: true, read_only: true, section: 'identity', order: 25 )]
	#[EntryField( name: 'originLabel', type: SettingType::String, label: 'Origin', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'identity', order: 30 )]
	#[EntryField( name: 'description', type: SettingType::String, label: 'Description', section: 'identity', order: 40, props: array( 'multiline' => true ) )]
	#[EntryField( name: 'labelCountSingular', type: SettingType::String, label: 'Count singular', section: 'identity', order: 50 )]
	#[EntryField( name: 'labelCountPlural', type: SettingType::String, label: 'Count plural', section: 'identity', order: 60 )]
	#[EntryField( name: 'public', type: SettingType::Boolean, label: 'Public', default: false, list: true, filter: true, filter_type: 'boolean', section: 'visibility', order: 10 )]
	#[EntryField( name: 'publicLabel', type: SettingType::String, label: 'Public', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'visibility', order: 20 )]
	#[EntryField( name: 'publiclyQueryable', type: SettingType::Boolean, label: 'Publicly queryable', default: false, section: 'visibility', order: 30 )]
	#[EntryField( name: 'excludeFromSearch', type: SettingType::Boolean, label: 'Exclude from search', default: true, list: true, filter: true, filter_type: 'boolean', section: 'visibility', order: 40 )]
	#[EntryField( name: 'excludeFromSearchLabel', type: SettingType::String, label: 'Search', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'visibility', order: 50 )]
	#[EntryField( name: 'showInAdminAllList', type: SettingType::Boolean, label: 'Show in all list', default: true, section: 'visibility', order: 60 )]
	#[EntryField( name: 'showInAdminStatusList', type: SettingType::Boolean, label: 'Show in status list', default: true, section: 'visibility', order: 70 )]
	#[EntryField( name: 'internal', type: SettingType::Boolean, label: 'Internal', default: false, section: 'behavior', order: 10 )]
	#[EntryField( name: 'protected', type: SettingType::Boolean, label: 'Protected', default: true, section: 'behavior', order: 20 )]
	#[EntryField( name: 'private', type: SettingType::Boolean, label: 'Private', default: false, section: 'behavior', order: 30 )]
	#[EntryField( name: 'dateFloating', type: SettingType::Boolean, label: 'Floating date', default: false, section: 'behavior', order: 40 )]
	#[EntryField( name: 'postTypes', type: SettingType::Array, label: 'Post types', default: array(), optionsSource: array( 'source' => 'postTypes' ), section: 'applies', order: 10 )]
	#[EntryField( name: 'postTypesLabel', type: SettingType::String, label: 'Applies to', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true, section: 'applies', order: 20 )]
	#[EntryField( name: 'allowedFromStatuses', type: SettingType::Array, label: 'Allowed from', default: array(), optionsSource: array( 'source' => 'statusOptions' ), section: 'transitions', order: 10, props: array( 'helpText' => 'Leave empty to allow any source status.' ) )]
	public function post_statuses(): array {
		return $this->post_status_rows();
	}

	/**
	 * @return list<array{value:string,label:string}>
	 */
	#[DataSource( 'postTypes', shape: DataSourceShape::Options )]
	public function post_types(): array {
		if ( ! function_exists( 'get_post_types' ) ) {
			return array();
		}

		$post_types = \get_post_types( array(), 'objects' );
		$options    = array();
		foreach ( $post_types as $name => $post_type ) {
			$key = is_string( $name ) ? $name : (string) ( $post_type->name ?? '' );
			if ( '' === $key ) {
				continue;
			}

			$options[] = array(
				'value' => $key,
				'label' => is_string( $post_type->label ?? null ) ? $post_type->label : $key,
			);
		}

		usort( $options, static fn( array $a, array $b ): int => $a['label'] <=> $b['label'] );
		return $options;
	}

	/**
	 * @return list<array{value:string,label:string}>
	 */
	#[DataSource( 'statusOptions', shape: DataSourceShape::Options )]
	public function status_options(): array {
		$options = array();
		foreach ( $this->post_status_rows() as $row ) {
			$options[] = array(
				'value' => (string) $row['slug'],
				'label' => (string) $row['label'],
			);
		}

		usort( $options, static fn( array $a, array $b ): int => $a['label'] <=> $b['label'] );
		return $options;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{ok:bool,slug:string,origin:string}
	 */
	#[Action( 'savePostStatus' )]
	#[Input( 'slug', SettingType::String, required: true )]
	#[Input( 'originalSlug', SettingType::String, default: '' )]
	#[Input( 'origin', SettingType::String, default: 'custom' )]
	#[Input( 'label', SettingType::String, required: true )]
	#[Input( 'description', SettingType::String, default: '' )]
	#[Input( 'labelCount', SettingType::Array, default: array() )]
	#[Input( 'labelCountSingular', SettingType::String, default: '' )]
	#[Input( 'labelCountPlural', SettingType::String, default: '' )]
	#[Input( 'public', SettingType::Boolean, default: false )]
	#[Input( 'publiclyQueryable', SettingType::Boolean, default: false )]
	#[Input( 'excludeFromSearch', SettingType::Boolean, default: true )]
	#[Input( 'showInAdminAllList', SettingType::Boolean, default: true )]
	#[Input( 'showInAdminStatusList', SettingType::Boolean, default: true )]
	#[Input( 'internal', SettingType::Boolean, default: false )]
	#[Input( 'protected', SettingType::Boolean, default: true )]
	#[Input( 'private', SettingType::Boolean, default: false )]
	#[Input( 'dateFloating', SettingType::Boolean, default: false )]
	#[Input( 'postTypes', SettingType::Array, default: array() )]
	#[Input( 'allowedFromStatuses', SettingType::Array, default: array() )]
	public function save_post_status( array $input ): array {
		$row           = $this->stored_row_from_input( $input );
		$original_slug = $this->sanitize_slug( (string) ( $input['originalSlug'] ?? '' ) );

		$this->persist_rows(
			function ( array $rows ) use ( $row, $original_slug ): array {
				$this->assert_slug_can_be_saved( $row['slug'], $original_slug, $rows );

				if ( '' !== $original_slug && $original_slug !== $row['slug'] ) {
					unset( $rows[ $original_slug ] );
				}

				$rows[ $row['slug'] ] = $row;
				return $rows;
			}
		);

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
	#[Action( 'deletePostStatuses' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function delete_post_statuses( array $input ): array {
		$ids     = $this->string_list( $input['ids'] ?? array() );
		$deleted = array();

		$this->persist_rows(
			function ( array $rows ) use ( $ids, &$deleted ): array {
				foreach ( $ids as $slug ) {
					$row = $rows[ $slug ] ?? null;
					if ( ! is_array( $row ) ) {
						continue;
					}

					if ( 'custom' !== ( $row['origin'] ?? '' ) ) {
						throw Errors::invariant( "Post status {$slug} is not owned by Onumia and cannot be deleted." );
					}

					unset( $rows[ $slug ] );
					$deleted[] = $slug;
				}

				return $rows;
			}
		);

		return array(
			'ok'      => true,
			'deleted' => $deleted,
		);
	}

	#[WpAction( 'init', priority: 9 )]
	public function register_configured_post_statuses(): void {
		if ( ! function_exists( 'register_post_status' ) ) {
			return;
		}

		foreach ( $this->stored_rows() as $row ) {
			$slug = is_string( $row['slug'] ?? null ) ? $row['slug'] : '';
			if ( '' === $slug ) {
				continue;
			}

			\register_post_status( $slug, $this->args_for( $row ) );
		}
	}

	/**
	 * @param array<string,mixed> $data Post data.
	 * @param array<string,mixed> $postarr Raw post data.
	 * @return array<string,mixed>
	 */
	#[WpFilter( 'wp_insert_post_data', priority: 10, accepted_args: 4 )]
	public function guard_status_transition( array $data, array $postarr = array(), array $unsanitized_postarr = array(), bool $update = false ): array {
		unset( $unsanitized_postarr );

		$target = is_string( $data['post_status'] ?? null ) ? $data['post_status'] : '';
		if ( '' === $target ) {
			return $data;
		}

		$row = $this->stored_rows_by_slug()[ $target ] ?? null;
		if ( ! is_array( $row ) ) {
			return $data;
		}

		$post_type = is_string( $data['post_type'] ?? null ) ? $data['post_type'] : ( is_string( $postarr['post_type'] ?? null ) ? $postarr['post_type'] : '' );
		if ( ! $this->status_applies_to_post_type( $row, $post_type ) ) {
			return $data;
		}

		$allowed_from = $this->string_list( $row['allowedFromStatuses'] ?? array() );
		if ( array() === $allowed_from ) {
			return $data;
		}

		$from = $this->previous_status_from_postarr( $postarr );
		if ( '' === $from && ! $update ) {
			$from = is_string( $postarr['original_post_status'] ?? null ) ? $postarr['original_post_status'] : '';
		}

		if ( in_array( $from, $allowed_from, true ) || $from === $target ) {
			return $data;
		}

		$this->last_transition_error = array(
			'from'   => $from,
			'target' => $target,
			'postType' => $post_type,
		);

		if ( '' !== $from ) {
			$data['post_status'] = $from;
		}

		return $data;
	}

	/**
	 * @return array<string,string>
	 */
	public function last_transition_error(): array {
		return $this->last_transition_error;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function post_status_rows(): array {
		$stored    = $this->stored_rows_by_slug();
		$registered = $this->registered_post_statuses();
		$rows      = array();

		foreach ( $registered as $slug => $object ) {
			$row        = $this->row_from_registered( $slug, $object );
			$stored_row = $stored[ $slug ] ?? null;
			if ( is_array( $stored_row ) ) {
				$row = array_merge( $row, $stored_row );
				if ( 'custom' !== ( $stored_row['origin'] ?? '' ) ) {
					$row['origin'] = in_array( $slug, self::BUILTIN_STATUSES, true ) ? 'builtin-override' : 'external-override';
				}
			}
			$rows[ $slug ] = $this->decorate_row( $row );
		}

		foreach ( $stored as $slug => $stored_row ) {
			if ( isset( $rows[ $slug ] ) || ! is_array( $stored_row ) ) {
				continue;
			}

			$rows[ $slug ] = $this->decorate_row( $stored_row );
		}

		usort( $rows, static fn( array $a, array $b ): int => ( $a['label'] ?? $a['slug'] ?? '' ) <=> ( $b['label'] ?? $b['slug'] ?? '' ) );
		return array_values( $rows );
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function stored_rows(): array {
		return array_values( $this->stored_rows_by_slug() );
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @param array<string,mixed> $base Base args.
	 * @return array<string,mixed>
	 */
	public function args_for( array $row, array $base = array() ): array {
		$row = $this->normalize_stored_row( array_merge( $base, $row ) );

		return array(
			'label'                     => $row['label'],
			'label_count'               => $row['labelCount'],
			'description'               => $row['description'],
			'public'                    => $row['public'],
			'publicly_queryable'        => $row['publiclyQueryable'],
			'exclude_from_search'       => $row['excludeFromSearch'],
			'show_in_admin_all_list'    => $row['showInAdminAllList'],
			'show_in_admin_status_list' => $row['showInAdminStatusList'],
			'internal'                  => $row['internal'],
			'protected'                 => $row['protected'],
			'private'                   => $row['private'],
			'date_floating'             => $row['dateFloating'],
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_rows_by_slug(): array {
		return $this->stored_rows_by_slug_from( $this->array_setting( 'statuses' ) );
	}

	/**
	 * @param mixed $value Stored row list.
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_rows_by_slug_from( mixed $value ): array {
		$rows = array();
		if ( ! is_array( $value ) ) {
			return $rows;
		}

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['slug'] ?? null ) ) {
				continue;
			}

			$slug = $this->sanitize_slug( $row['slug'] );
			if ( '' === $slug ) {
				continue;
			}

			$row['slug']   = $slug;
			$rows[ $slug ] = $this->normalize_stored_row( $row );
		}

		return $rows;
	}

	/**
	 * @return array<string,object>
	 */
	private function registered_post_statuses(): array {
		if ( ! function_exists( 'get_post_stati' ) ) {
			return array(
				'publish' => (object) array(
					'name'              => 'publish',
					'label'             => 'Published',
					'public'            => true,
					'publicly_queryable' => true,
					'exclude_from_search' => false,
					'show_in_admin_all_list' => true,
					'show_in_admin_status_list' => true,
				),
				'draft'   => (object) array(
					'name'      => 'draft',
					'label'     => 'Draft',
					'protected' => true,
				),
				'pending' => (object) array(
					'name'      => 'pending',
					'label'     => 'Pending',
					'protected' => true,
				),
			);
		}

		$objects = \get_post_stati( array(), 'objects' );
		$rows    = array();
		foreach ( $objects as $name => $object ) {
			$slug = is_string( $name ) ? $name : (string) ( $object->name ?? '' );
			if ( '' !== $slug && is_object( $object ) ) {
				$rows[ $slug ] = $object;
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
			throw Errors::invariant( 'Post status slug is required.' );
		}

		if ( $raw_slug !== $slug || ! preg_match( '/^[a-z0-9_-]{1,20}$/', $slug ) ) {
			throw Errors::invariant( 'Post status slug must use 1-20 lowercase letters, numbers, dashes, or underscores.' );
		}

		$origin = is_string( $input['origin'] ?? null ) ? $input['origin'] : 'custom';
		if ( 'builtin' === $origin ) {
			$origin = 'builtin-override';
		} elseif ( 'external' === $origin ) {
			$origin = 'external-override';
		}

		if ( ! in_array( $origin, array( 'custom', 'builtin-override', 'external-override' ), true ) ) {
			$origin = $this->registered_origin_for_slug( $slug );
		}

		$label = trim( (string) ( $input['label'] ?? $slug ) );
		return $this->normalize_stored_row(
			array(
				'slug'                  => $slug,
				'origin'                => $origin,
				'label'                 => '' === $label ? $slug : $label,
				'description'           => trim( (string) ( $input['description'] ?? '' ) ),
				'labelCount'            => $this->label_count_from_input( $input, $label ),
				'public'                => true === ( $input['public'] ?? false ),
				'publiclyQueryable'     => true === ( $input['publiclyQueryable'] ?? false ),
				'excludeFromSearch'     => true === ( $input['excludeFromSearch'] ?? true ),
				'showInAdminAllList'    => true === ( $input['showInAdminAllList'] ?? true ),
				'showInAdminStatusList' => true === ( $input['showInAdminStatusList'] ?? true ),
				'internal'              => true === ( $input['internal'] ?? false ),
				'protected'             => true === ( $input['protected'] ?? true ),
				'private'               => true === ( $input['private'] ?? false ),
				'dateFloating'          => true === ( $input['dateFloating'] ?? false ),
				'postTypes'             => $this->string_list( $input['postTypes'] ?? array() ),
				'allowedFromStatuses'   => $this->string_list( $input['allowedFromStatuses'] ?? array() ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function decorate_row( array $row ): array {
		$row    = $this->normalize_stored_row( $row );
		$origin = $row['origin'];

		return array_merge(
			$row,
			array(
				'labelCountSingular'     => $row['labelCount'][0],
				'labelCountPlural'       => $row['labelCount'][1],
				'originLabel'            => $this->origin_label( $origin ),
				'publicLabel'            => $row['public'] ? 'Yes' : 'No',
				'excludeFromSearchLabel' => $row['excludeFromSearch'] ? 'Excluded' : 'Included',
				'postTypesLabel'         => array() === $row['postTypes'] ? 'All post types' : implode( ', ', $row['postTypes'] ),
				'canDelete'              => 'custom' === $origin,
			)
		);
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function normalize_stored_row( array $row ): array {
		$slug        = $this->sanitize_slug( (string) ( $row['slug'] ?? '' ) );
		$label       = trim( (string) ( $row['label'] ?? $slug ) );
		$label_count = $this->label_count_value( $row['labelCount'] ?? array(), '' === $label ? $slug : $label );

		return array(
			'slug'                  => $slug,
			'origin'                => $this->origin_value( $row['origin'] ?? 'custom' ),
			'label'                 => '' === $label ? $slug : $label,
			'description'           => trim( (string) ( $row['description'] ?? '' ) ),
			'labelCount'            => $label_count,
			'public'                => true === ( $row['public'] ?? false ),
			'publiclyQueryable'     => true === ( $row['publiclyQueryable'] ?? false ),
			'excludeFromSearch'     => true === ( $row['excludeFromSearch'] ?? true ),
			'showInAdminAllList'    => true === ( $row['showInAdminAllList'] ?? true ),
			'showInAdminStatusList' => true === ( $row['showInAdminStatusList'] ?? true ),
			'internal'              => true === ( $row['internal'] ?? false ),
			'protected'             => true === ( $row['protected'] ?? true ),
			'private'               => true === ( $row['private'] ?? false ),
			'dateFloating'          => true === ( $row['dateFloating'] ?? false ),
			'postTypes'             => $this->string_list( $row['postTypes'] ?? array() ),
			'allowedFromStatuses'   => $this->string_list( $row['allowedFromStatuses'] ?? array() ),
		);
	}

	/**
	 * @param array<string,mixed> $rows Stored rows keyed by slug.
	 */
	private function assert_slug_can_be_saved( string $slug, string $original_slug, array $rows ): void {
		if ( '' === $original_slug ) {
			if ( isset( $rows[ $slug ] ) || isset( $this->registered_post_statuses()[ $slug ] ) ) {
				throw Errors::invariant( "Post status slug {$slug} already exists." );
			}

			return;
		}

		if ( $original_slug === $slug ) {
			return;
		}

		$original_row    = $rows[ $original_slug ] ?? null;
		$original_origin = is_array( $original_row ) ? (string) ( $original_row['origin'] ?? 'custom' ) : $this->registered_origin_for_slug( $original_slug );
		if ( 'custom' !== $original_origin ) {
			throw Errors::invariant( "Post status {$original_slug} is not owned by Onumia and cannot change slug." );
		}

		if ( isset( $rows[ $slug ] ) || isset( $this->registered_post_statuses()[ $slug ] ) ) {
			throw Errors::invariant( "Post status slug {$slug} already exists." );
		}
	}

	private function registered_origin_for_slug( string $slug ): string {
		$registered = $this->registered_post_statuses()[ $slug ] ?? null;
		if ( null === $registered ) {
			return 'custom';
		}

		return in_array( $slug, self::BUILTIN_STATUSES, true ) ? 'builtin-override' : 'external-override';
	}

	/**
	 * @param object $object Registered status.
	 * @return array<string,mixed>
	 */
	private function row_from_registered( string $slug, object $object ): array {
		$label_count = is_array( $object->label_count ?? null ) ? $object->label_count : array();

		return array(
			'slug'                  => $slug,
			'origin'                => in_array( $slug, self::BUILTIN_STATUSES, true ) ? 'builtin' : 'external',
			'label'                 => $this->string_or_default( $object->label ?? null, $slug ),
			'description'           => $this->string_or_default( $object->description ?? null, '' ),
			'labelCount'            => $this->label_count_value( $label_count, $this->string_or_default( $object->label ?? null, $slug ) ),
			'public'                => true === ( $object->public ?? false ),
			'publiclyQueryable'     => true === ( $object->publicly_queryable ?? false ),
			'excludeFromSearch'     => true === ( $object->exclude_from_search ?? true ),
			'showInAdminAllList'    => true === ( $object->show_in_admin_all_list ?? false ),
			'showInAdminStatusList' => true === ( $object->show_in_admin_status_list ?? false ),
			'internal'              => true === ( $object->internal ?? false ),
			'protected'             => true === ( $object->protected ?? false ),
			'private'               => true === ( $object->private ?? false ),
			'dateFloating'          => true === ( $object->date_floating ?? false ),
			'postTypes'             => array(),
			'allowedFromStatuses'   => array(),
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{0:string,1:string}
	 */
	private function label_count_from_input( array $input, string $label ): array {
		$singular = trim( (string) ( $input['labelCountSingular'] ?? '' ) );
		$plural   = trim( (string) ( $input['labelCountPlural'] ?? '' ) );
		if ( '' !== $singular || '' !== $plural ) {
			return array( '' === $singular ? "{$label} (%s)" : $singular, '' === $plural ? "{$label} (%s)" : $plural );
		}

		return $this->label_count_value( $input['labelCount'] ?? array(), $label );
	}

	/**
	 * @return array{0:string,1:string}
	 */
	private function label_count_value( mixed $value, string $label ): array {
		if ( is_array( $value ) ) {
			$singular = $value[0] ?? $value['singular'] ?? '';
			$plural   = $value[1] ?? $value['plural'] ?? '';
			$singular = is_scalar( $singular ) ? trim( (string) $singular ) : '';
			$plural   = is_scalar( $plural ) ? trim( (string) $plural ) : '';
			if ( '' !== $singular || '' !== $plural ) {
				return array( '' === $singular ? "{$label} (%s)" : $singular, '' === $plural ? "{$label} (%s)" : $plural );
			}
		}

		return array( "{$label} (%s)", "{$label} (%s)" );
	}

	/**
	 * @param array<string,mixed> $postarr Post array.
	 */
	private function previous_status_from_postarr( array $postarr ): string {
		$post_id = $postarr['ID'] ?? $postarr['id'] ?? 0;
		$post_id = is_int( $post_id ) ? $post_id : ( is_numeric( $post_id ) ? (int) $post_id : 0 );
		if ( $post_id <= 0 || ! function_exists( 'get_post' ) ) {
			return '';
		}

		$post = \get_post( $post_id );
		return is_object( $post ) && is_string( $post->post_status ?? null ) ? $post->post_status : '';
	}

	/**
	 * @param array<string,mixed> $row Row.
	 */
	private function status_applies_to_post_type( array $row, string $post_type ): bool {
		$post_types = $this->string_list( $row['postTypes'] ?? array() );
		return array() === $post_types || '' === $post_type || in_array( $post_type, $post_types, true );
	}

	private function persist_rows( callable $updater ): void {
		( new ModuleSettingsRepository() )->update_settings_with(
			$this->definition(),
			function ( array $settings ) use ( $updater ): array {
				$rows = $this->stored_rows_by_slug_from( $settings['statuses'] ?? array() );

				return array( 'statuses' => array_values( $updater( $rows ) ) );
			}
		);
	}

	private function sanitize_slug( string $slug ): string {
		return function_exists( 'sanitize_key' ) ? \sanitize_key( $slug ) : strtolower( preg_replace( '/[^a-z0-9_-]+/', '', $slug ) ?? '' );
	}

	private function origin_value( mixed $origin ): string {
		return in_array( $origin, array( 'builtin', 'builtin-override', 'external', 'external-override', 'custom' ), true ) ? (string) $origin : 'custom';
	}

	private function origin_label( string $origin ): string {
		return match ( $origin ) {
			'builtin' => 'Built-in',
			'builtin-override' => 'Built-in override',
			'external' => 'External',
			'external-override' => 'External override',
			default => 'Custom',
		};
	}

	private function string_or_default( mixed $value, string $default ): string {
		return is_scalar( $value ) && '' !== trim( (string) $value ) ? trim( (string) $value ) : $default;
	}
}
