<?php
/**
 * Post Revisions module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\PostRevisions;

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
use Onumia\Modules\Attributes\WpFilter;
use Onumia\Modules\Contracts\DataSourceShape;
use Onumia\Modules\Contracts\EntryStorage;
use Onumia\Modules\Contracts\PaginationMode;
use Onumia\Modules\Contracts\SettingType;
use Onumia\Modules\Module;
use Onumia\Modules\ModuleSettingsRepository;

#[ModuleContract( capability: 'manage_options' )]
#[Setting( 'enabled', SettingType::Boolean, default: false )]
#[Setting( 'rules', SettingType::Array, default: array() )]
final class PostRevisions extends Module {
	private const HOOK = 'onumia_post_revisions_prune';

	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'rules', shape: DataSourceShape::Collection, pagination: PaginationMode::Client )]
	#[Entries( name: 'rules', singular: 'Retention rule', plural: 'Retention rules', key: 'postType', storage: EntryStorage::Manual, source: 'rules', update_action: 'saveRule' )]
	#[EntrySection( name: 'retention', label: 'Retention', description: 'Revision limits for this post type.', order: 10, layout: 'tabs' )]
	#[EntryField( name: 'label', type: SettingType::String, label: 'Post type', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true, section: 'retention', order: 10 )]
	#[EntryField( name: 'postType', type: SettingType::String, label: 'Slug', primary: true, required: true, list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true, section: 'retention', order: 20 )]
	#[EntryField( name: 'enabled', type: SettingType::Boolean, label: 'Enable pruning', default: false, list: true, filter: true, filter_type: 'option', section: 'retention', order: 30 )]
	#[EntryField( name: 'enabledLabel', type: SettingType::String, label: 'Enabled', list: true, create: false, update: false, read_only: true, section: 'retention', order: 40 )]
	#[EntryField( name: 'maxRevisions', type: SettingType::Integer, label: 'Max revisions', default: 0, min: 0, list: true, filter: true, filter_type: 'number', section: 'retention', order: 50 )]
	#[EntryField( name: 'maxRevisionsLabel', type: SettingType::String, label: 'Max revisions', list: true, create: false, update: false, read_only: true, section: 'retention', order: 60 )]
	#[EntryField( name: 'maxAgeDays', type: SettingType::Integer, label: 'Max age days', default: 0, min: 0, list: true, filter: true, filter_type: 'number', section: 'retention', order: 70 )]
	#[EntryField( name: 'maxAgeDaysLabel', type: SettingType::String, label: 'Max age', list: true, create: false, update: false, read_only: true, section: 'retention', order: 80 )]
	#[EntryField( name: 'currentRevisionCount', type: SettingType::Integer, label: 'Current revisions', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true, section: 'retention', order: 90 )]
	public function rules(): array {
		$stored = $this->rules_by_post_type();
		$rows   = array();

		foreach ( $this->registered_post_types() as $post_type => $object ) {
			$rule                = $stored[ $post_type ] ?? array();
			$rule['postType']    = $post_type;
			$rule['label']       = $this->post_type_label( $post_type, $object );
			$rule['enabled']     = true === ( $rule['enabled'] ?? false );
			$rule['maxRevisions'] = max( 0, (int) ( $rule['maxRevisions'] ?? 0 ) );
			$rule['maxAgeDays']  = max( 0, (int) ( $rule['maxAgeDays'] ?? 0 ) );
			$rule['currentRevisionCount'] = $this->revision_count_for_post_type( $post_type );
			$rows[]              = $this->decorate_rule( $rule );
		}

		usort( $rows, static fn( array $left, array $right ): int => (string) $left['label'] <=> (string) $right['label'] );

		return $rows;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{ok:bool,postType:string}
	 */
	#[Action( 'saveRule' )]
	#[Input( 'postType', SettingType::String, default: '' )]
	#[Input( 'enabled', SettingType::Boolean, default: false )]
	#[Input( 'maxRevisions', SettingType::Integer, default: 0 )]
	#[Input( 'maxAgeDays', SettingType::Integer, default: 0 )]
	public function save_rule( array $input ): array {
		$post_type = $this->sanitize_post_type( (string) ( $input['postType'] ?? '' ) );
		if ( '' === $post_type || ! isset( $this->registered_post_types()[ $post_type ] ) ) {
			throw Errors::invariant( 'Post type is required.' );
		}

		$rules               = $this->rules_by_post_type();
		$rules[ $post_type ] = $this->normalize_rule(
			array(
				'postType'     => $post_type,
				'enabled'      => true === ( $input['enabled'] ?? false ),
				'maxRevisions' => max( 0, (int) ( $input['maxRevisions'] ?? 0 ) ),
				'maxAgeDays'   => max( 0, (int) ( $input['maxAgeDays'] ?? 0 ) ),
			)
		);

		$this->persist_rules( array_values( $rules ) );

		return array(
			'ok'       => true,
			'postType' => $post_type,
		);
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'pruneLog', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
	#[Input( 'query', SettingType::Object, default: array() )]
	#[Input( 'page', SettingType::Integer, default: 0 )]
	#[Input( 'pageSize', SettingType::Integer, default: 10 )]
	#[ObjectShape(
		'query',
		array(
			'search'  => 'string',
			'filters' => 'array',
			'sorting' => 'array',
			'page'    => 'array',
		)
	)]
	#[Entries( name: 'pruneLog', singular: 'Prune event', plural: 'Prune log', key: 'id', storage: EntryStorage::Table, source: 'pruneLog', table: 'prune_log' )]
	#[EntryField( name: 'id', type: SettingType::Integer, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'prunedAt', type: SettingType::Integer, label: 'Pruned timestamp', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'prunedAtLabel', type: SettingType::String, label: 'Pruned', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'postType', type: SettingType::String, label: 'Post type', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true )]
	#[EntryField( name: 'postId', type: SettingType::Integer, label: 'Post ID', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'reason', type: SettingType::String, label: 'Reason value', allowed: array( 'cap_exceeded', 'age_exceeded', 'both' ), list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true )]
	#[EntryField( name: 'reasonLabel', type: SettingType::String, label: 'Reason', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'revisionsRemoved', type: SettingType::Integer, label: 'Removed', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	public function prune_log( array $params ): array {
		$rows = array_reverse( $this->table( 'prune_log' )->export_rows() );
		return $this->paginated_rows( array_map( array( $this, 'prune_log_row_for_display' ), $rows ), $params );
	}

	public function boot(): void {
		$this->sync_schedule();
	}

	public function settings_updated(): void {
		$this->sync_schedule();
	}

	#[WpFilter( 'wp_revisions_to_keep', priority: 10, accepted_args: 2 )]
	public function revisions_to_keep( int $revisions, mixed $post ): int {
		if ( ! $this->enabled() ) {
			return $revisions;
		}

		$post_type = $this->post_type_from_post( $post );
		if ( '' === $post_type ) {
			return $revisions;
		}

		$rule = $this->enabled_rule_for( $post_type );
		if ( null === $rule ) {
			return $revisions;
		}

		return 0 === $rule['maxRevisions'] ? -1 : $rule['maxRevisions'];
	}

	/**
	 * @return array{posts:int,revisionsRemoved:int}
	 */
	#[Action( 'runPrune' )]
	#[WpAction( 'onumia_post_revisions_prune' )]
	public function prune_revisions(): array {
		if ( ! $this->enabled() ) {
			return array(
				'posts'            => 0,
				'revisionsRemoved' => 0,
			);
		}

		$posts            = 0;
		$revisions_removed = 0;
		foreach ( $this->rules_by_post_type() as $post_type => $rule ) {
			if ( null === $this->active_rule_from_rule( $rule ) ) {
				continue;
			}

			foreach ( $this->post_ids_for_type( $post_type ) as $post_id ) {
				$result = $this->prune_post_revisions( $post_id, $rule );
				if ( 0 >= $result['removed'] ) {
					continue;
				}

				++$posts;
				$revisions_removed += $result['removed'];
				$this->table( 'prune_log' )->insert(
					array(
						'pruned_at'         => $this->now(),
						'post_type'         => $post_type,
						'post_id'           => $post_id,
						'reason'            => $result['reason'],
						'revisions_removed' => $result['removed'],
					)
				);
			}
		}

		return array(
			'posts'            => $posts,
			'revisionsRemoved' => $revisions_removed,
		);
	}

	private function sync_schedule(): void {
		if ( ! $this->enabled() ) {
			$this->clear_schedule();
			return;
		}

		if ( false !== $this->next_scheduled( self::HOOK ) ) {
			return;
		}

		if ( function_exists( 'wp_schedule_event' ) ) {
			\wp_schedule_event( $this->now() + 3600, 'daily', self::HOOK );
		}
	}

	private function clear_schedule(): void {
		if ( ! function_exists( 'wp_unschedule_event' ) ) {
			return;
		}

		while ( false !== ( $timestamp = $this->next_scheduled( self::HOOK ) ) ) {
			if ( ! \wp_unschedule_event( $timestamp, self::HOOK ) ) {
				return;
			}
		}
	}

	private function next_scheduled( string $hook ): int|false {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return false;
		}

		$timestamp = \wp_next_scheduled( $hook );
		return is_numeric( $timestamp ) ? (int) $timestamp : false;
	}

	/**
	 * @return array<string,array{postType:string,enabled:bool,maxRevisions:int,maxAgeDays:int}>
	 */
	private function rules_by_post_type(): array {
		$rules = array();
		foreach ( $this->array_setting( 'rules' ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$normalized = $this->normalize_rule( $rule );
			if ( '' === $normalized['postType'] ) {
				continue;
			}

			$rules[ $normalized['postType'] ] = $normalized;
		}

		return $rules;
	}

	/**
	 * @param array<string,mixed> $rule Rule.
	 * @return array{postType:string,enabled:bool,maxRevisions:int,maxAgeDays:int}
	 */
	private function normalize_rule( array $rule ): array {
		return array(
			'postType'     => $this->sanitize_post_type( (string) ( $rule['postType'] ?? '' ) ),
			'enabled'      => true === ( $rule['enabled'] ?? false ),
			'maxRevisions' => max( 0, (int) ( $rule['maxRevisions'] ?? 0 ) ),
			'maxAgeDays'   => max( 0, (int) ( $rule['maxAgeDays'] ?? 0 ) ),
		);
	}

	/**
	 * @param array<string,mixed> $rule Rule.
	 * @return array<string,mixed>
	 */
	private function decorate_rule( array $rule ): array {
		$rule['enabledLabel']      = true === $rule['enabled'] ? 'Yes' : 'No';
		$rule['maxRevisionsLabel'] = 0 === $rule['maxRevisions'] ? 'Unlimited' : (string) $rule['maxRevisions'];
		$rule['maxAgeDaysLabel']   = 0 === $rule['maxAgeDays'] ? 'No age limit' : (string) $rule['maxAgeDays'] . ' days';
		return $rule;
	}

	/**
	 * @param list<array<string,mixed>> $rules Rules.
	 */
	private function persist_rules( array $rules ): void {
		( new ModuleSettingsRepository() )->update_settings( $this->definition(), array( 'rules' => array_values( $rules ) ) );
	}

	/**
	 * @return array<string,object>
	 */
	private function registered_post_types(): array {
		if ( ! function_exists( 'get_post_types' ) ) {
			return array(
				'post' => (object) array(
					'name'     => 'post',
					'label'    => 'Posts',
					'_builtin' => true,
				),
				'page' => (object) array(
					'name'     => 'page',
					'label'    => 'Pages',
					'_builtin' => true,
				),
			);
		}

		$objects = \get_post_types( array(), 'objects' );
		$rows    = array();
		foreach ( $objects as $name => $object ) {
			$slug = is_string( $name ) ? $name : (string) ( $object->name ?? '' );
			if ( '' !== $slug && is_object( $object ) ) {
				$rows[ $slug ] = $object;
			}
		}

		return $rows;
	}

	private function post_type_label( string $post_type, object $object ): string {
		$label = $object->label ?? null;
		return is_string( $label ) && '' !== $label ? $label : $post_type;
	}

	private function revision_count_for_post_type( string $post_type ): int {
		$count = 0;
		foreach ( $this->post_ids_for_type( $post_type ) as $post_id ) {
			$count += count( $this->revisions_for_post( $post_id ) );
		}

		return $count;
	}

	/**
	 * @return list<int>
	 */
	private function post_ids_for_type( string $post_type ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		return array_values(
			array_filter(
				\get_posts(
					array(
						'post_type'      => $post_type,
						'post_status'    => 'any',
						'fields'         => 'ids',
						'numberposts'    => -1,
						'posts_per_page' => -1,
					)
				),
				'is_int'
			)
		);
	}

	/**
	 * @param array{postType:string,enabled:bool,maxRevisions:int,maxAgeDays:int} $rule Rule.
	 * @return array{removed:int,reason:string}
	 */
	private function prune_post_revisions( int $post_id, array $rule ): array {
		$revisions = $this->revisions_for_post( $post_id );
		if ( array() === $revisions ) {
			return array(
				'removed' => 0,
				'reason'  => 'cap_exceeded',
			);
		}

		$cap_ids = array();
		if ( 0 < $rule['maxRevisions'] && count( $revisions ) > $rule['maxRevisions'] ) {
			$cap_ids = array_map(
				static fn( object $revision ): int => (int) $revision->ID,
				array_slice( $revisions, $rule['maxRevisions'] )
			);
		}

		$age_ids = array();
		if ( 0 < $rule['maxAgeDays'] ) {
			$cutoff  = $this->now() - ( $rule['maxAgeDays'] * $this->day_in_seconds() );
			$age_ids = array_map(
				static fn( object $revision ): int => (int) $revision->ID,
				array_values(
					array_filter(
						$revisions,
						fn( object $revision ): bool => $this->revision_timestamp( $revision ) < $cutoff
					)
				)
			);
		}

		$candidates = array_flip( array_unique( array_merge( $cap_ids, $age_ids ) ) );
		$delete_ids = array();
		foreach ( $revisions as $revision ) {
			$revision_id = (int) $revision->ID;
			if ( isset( $candidates[ $revision_id ] ) ) {
				$delete_ids[] = $revision_id;
			}
		}
		foreach ( $delete_ids as $revision_id ) {
			$this->delete_revision( $revision_id );
		}

		return array(
			'removed' => count( $delete_ids ),
			'reason'  => array() !== $cap_ids && array() !== $age_ids ? 'both' : ( array() !== $cap_ids ? 'cap_exceeded' : 'age_exceeded' ),
		);
	}

	/**
	 * @return list<object>
	 */
	private function revisions_for_post( int $post_id ): array {
		if ( function_exists( 'wp_get_post_revisions' ) ) {
			$revisions = \wp_get_post_revisions(
				$post_id,
				array(
					'posts_per_page' => -1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
			if ( is_array( $revisions ) ) {
				$items = array_values( array_filter( $revisions, 'is_object' ) );
				usort( $items, fn( object $left, object $right ): int => $this->revision_timestamp( $right ) <=> $this->revision_timestamp( $left ) );
				return $items;
			}
		}

		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$items = array_values(
			array_filter(
				\get_posts(
					array(
						'post_type'      => 'revision',
						'post_status'    => 'inherit',
						'post_parent'    => $post_id,
						'numberposts'    => -1,
						'posts_per_page' => -1,
					)
				),
				'is_object'
			)
		);
		usort( $items, fn( object $left, object $right ): int => $this->revision_timestamp( $right ) <=> $this->revision_timestamp( $left ) );
		return $items;
	}

	private function delete_revision( int $revision_id ): void {
		if ( function_exists( 'wp_delete_post_revision' ) ) {
			\wp_delete_post_revision( $revision_id );
			return;
		}

		if ( function_exists( 'wp_delete_post' ) ) {
			\wp_delete_post( $revision_id, true );
		}
	}

	private function revision_timestamp( object $revision ): int {
		foreach ( array( 'post_modified_gmt', 'post_date_gmt', 'post_modified', 'post_date' ) as $key ) {
			$value = $revision->$key ?? null;
			if ( is_string( $value ) && '' !== $value ) {
				$timestamp = strtotime( $value );
				if ( false !== $timestamp ) {
					return $timestamp;
				}
			}
		}

		return (int) ( $revision->ID ?? 0 );
	}

	/**
	 * @return array{postType:string,enabled:bool,maxRevisions:int,maxAgeDays:int}|null
	 */
	private function enabled_rule_for( string $post_type ): ?array {
		$rule = $this->rules_by_post_type()[ $post_type ] ?? null;
		return is_array( $rule ) && true === $rule['enabled'] ? $rule : null;
	}

	/**
	 * @param array{postType:string,enabled:bool,maxRevisions:int,maxAgeDays:int} $rule Rule.
	 * @return array{postType:string,enabled:bool,maxRevisions:int,maxAgeDays:int}|null
	 */
	private function active_rule_from_rule( array $rule ): ?array {
		if ( true !== $rule['enabled'] ) {
			return null;
		}

		if ( 0 === $rule['maxRevisions'] && 0 === $rule['maxAgeDays'] ) {
			return null;
		}

		return $rule;
	}

	private function post_type_from_post( mixed $post ): string {
		if ( is_object( $post ) && is_string( $post->post_type ?? null ) ) {
			return $post->post_type;
		}

		if ( is_array( $post ) && is_string( $post['post_type'] ?? null ) ) {
			return $post['post_type'];
		}

		if ( is_int( $post ) && function_exists( 'get_post_type' ) ) {
			$post_type = \get_post_type( $post );
			return is_string( $post_type ) ? $post_type : '';
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function prune_log_row_for_display( array $row ): array {
		$pruned_at = isset( $row['pruned_at'] ) && is_numeric( $row['pruned_at'] ) ? (int) $row['pruned_at'] : 0;
		$reason    = is_string( $row['reason'] ?? null ) ? $row['reason'] : 'cap_exceeded';

		return array(
			'id'               => isset( $row['id'] ) && is_numeric( $row['id'] ) ? (int) $row['id'] : 0,
			'prunedAt'         => $pruned_at,
			'prunedAtLabel'    => 0 < $pruned_at ? gmdate( 'Y-m-d H:i', $pruned_at ) : '',
			'postType'         => (string) ( $row['post_type'] ?? '' ),
			'postId'           => isset( $row['post_id'] ) && is_numeric( $row['post_id'] ) ? (int) $row['post_id'] : 0,
			'reason'           => $reason,
			'reasonLabel'      => $this->reason_label( $reason ),
			'revisionsRemoved' => isset( $row['revisions_removed'] ) && is_numeric( $row['revisions_removed'] ) ? (int) $row['revisions_removed'] : 0,
		);
	}

	private function reason_label( string $reason ): string {
		return match ( $reason ) {
			'age_exceeded' => 'Age exceeded',
			'both' => 'Cap and age exceeded',
			default => 'Cap exceeded',
		};
	}

	private function sanitize_post_type( string $post_type ): string {
		return function_exists( 'sanitize_key' ) ? \sanitize_key( $post_type ) : strtolower( preg_replace( '/[^a-z0-9_-]+/', '', $post_type ) ?? '' );
	}

	private function day_in_seconds(): int {
		return defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
	}
}
