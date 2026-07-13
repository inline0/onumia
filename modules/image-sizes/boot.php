<?php
/**
 * Image Sizes module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\ImageSizes;

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
#[Setting( 'sizes', SettingType::Array, default: array() )]
#[Setting( 'overrides', SettingType::Object, default: array() )]
#[Setting( 'regenerate', SettingType::Object, default: array() )]
final class ImageSizes extends Module {
	private const CRON_HOOK      = 'onumia_image_sizes_regen_tick';
	private const STATUS_RUNNING = 'running';
	private const STATUS_DONE    = 'done';
	private const STATUS_IDLE    = 'idle';

	/**
	 * @var array<string,array{width:string,height:string,crop?:string}>
	 */
	private const CORE_OPTION_MAP = array(
		'thumbnail'    => array(
			'width'  => 'thumbnail_size_w',
			'height' => 'thumbnail_size_h',
			'crop'   => 'thumbnail_crop',
		),
		'medium'       => array(
			'width'  => 'medium_size_w',
			'height' => 'medium_size_h',
		),
		'medium_large' => array(
			'width'  => 'medium_large_size_w',
			'height' => 'medium_large_size_h',
		),
		'large'        => array(
			'width'  => 'large_size_w',
			'height' => 'large_size_h',
		),
	);

	/**
	 * @var list<string>
	 */
	private const CROP_POSITIONS = array( 'center', 'top', 'bottom', 'left', 'right', 'top-left', 'top-right', 'bottom-left', 'bottom-right' );

	/**
	 * @var list<string>
	 */
	private const CORE_NAMES = array( 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' );

	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'imageSizes', shape: DataSourceShape::Collection, pagination: PaginationMode::Client )]
	#[Entries( name: 'sizes', singular: 'Image size', plural: 'Image sizes', key: 'name', storage: EntryStorage::Manual, source: 'imageSizes', create_action: 'saveImageSize', update_action: 'saveImageSize', delete_action: 'deleteImageSizes' )]
	#[EntrySection( name: 'definition', label: 'Definition', description: 'Image size dimensions and crop behavior.', order: 10, layout: 'tabs' )]
	#[EntryField(
		name: 'name',
		type: SettingType::String,
		label: 'Name',
		primary: true,
		required: true,
		list: true,
		filter: true,
		filter_type: 'text',
		section: 'definition',
		order: 10,
		props: array(
			'autoSuggest'     => array(
				'from'     => 'label',
				'strategy' => 'slug',
			),
			'lockedHelpText'  => 'Built-in image size names are owned by WordPress and cannot be renamed here.',
			'lockedOrigins'   => array( 'builtin', 'builtin-override' ),
			'mutablePrimary'  => true,
			'originalInput'   => 'originalName',
		)
	)]
	#[EntryField( name: 'label', type: SettingType::String, label: 'Label', section: 'definition', order: 15 )]
	#[EntryField( name: 'origin', type: SettingType::String, label: 'Origin', default: 'custom', allowed: array( 'builtin', 'builtin-override', 'custom' ), create: false, update: true, read_only: true, section: 'definition', order: 18 )]
	#[EntryField( name: 'originLabel', type: SettingType::String, label: 'Origin', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'definition', order: 20 )]
	#[EntryField( name: 'width', type: SettingType::Integer, label: 'Width', required: true, min: 0, list: true, filter: true, filter_type: 'number', section: 'definition', order: 30 )]
	#[EntryField( name: 'height', type: SettingType::Integer, label: 'Height', required: true, min: 0, list: true, filter: true, filter_type: 'number', section: 'definition', order: 40 )]
	#[EntryField(
		name: 'cropMode',
		type: SettingType::String,
		label: 'Crop mode',
		default: 'none',
		allowed: array( 'none', 'crop' ),
		options: array(
			array(
				'value' => 'none',
				'label' => 'Proportional',
			),
			array(
				'value' => 'crop',
				'label' => 'Crop',
			),
		),
		section: 'definition',
		order: 50
	)]
	#[EntryField( name: 'cropModeLabel', type: SettingType::String, label: 'Crop', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'definition', order: 55 )]
	#[EntryField(
		name: 'cropPosition',
		type: SettingType::String,
		label: 'Crop position',
		default: 'center',
		allowed: array( 'center', 'top', 'bottom', 'left', 'right', 'top-left', 'top-right', 'bottom-left', 'bottom-right' ),
		options: array(
			array(
				'value' => 'center',
				'label' => 'Center',
			),
			array(
				'value' => 'top',
				'label' => 'Top',
			),
			array(
				'value' => 'bottom',
				'label' => 'Bottom',
			),
			array(
				'value' => 'left',
				'label' => 'Left',
			),
			array(
				'value' => 'right',
				'label' => 'Right',
			),
			array(
				'value' => 'top-left',
				'label' => 'Top left',
			),
			array(
				'value' => 'top-right',
				'label' => 'Top right',
			),
			array(
				'value' => 'bottom-left',
				'label' => 'Bottom left',
			),
			array(
				'value' => 'bottom-right',
				'label' => 'Bottom right',
			),
		),
		section: 'definition',
		order: 60,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.cropMode' ),
			'right' => 'crop',
		)
	)]
	public function image_sizes(): array {
		return $this->size_rows();
	}

	/**
	 * @return list<array{value:string,label:string}>
	 */
	#[DataSource( 'sizeOptions', shape: DataSourceShape::Options )]
	public function size_options(): array {
		return array_map(
			static fn( array $row ): array => array(
				'value' => (string) $row['name'],
				'label' => (string) $row['label'],
			),
			$this->size_rows()
		);
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'regenLog', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'regenLog', singular: 'Log row', plural: 'Log rows', key: 'id', storage: EntryStorage::Table, source: 'regenLog', table: 'regen_log' )]
	#[EntryField( name: 'id', type: SettingType::Integer, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'startedAt', type: SettingType::Integer, label: 'Started timestamp', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'startedAtLabel', type: SettingType::String, label: 'Started', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'finishedAt', type: SettingType::Integer, label: 'Finished timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'finishedAtLabel', type: SettingType::String, label: 'Finished', create: false, update: false, read_only: true )]
	#[EntryField( name: 'attachmentId', type: SettingType::Integer, label: 'Attachment ID', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'sizeName', type: SettingType::String, label: 'Size', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true )]
	#[EntryField( name: 'status', type: SettingType::String, label: 'Status value', allowed: array( 'ok', 'skipped', 'failed' ), filter: true, filter_type: 'option', create: false, update: false, read_only: true )]
	#[EntryField( name: 'statusLabel', type: SettingType::String, label: 'Status', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'error', type: SettingType::String, label: 'Error', filter: true, filter_type: 'text', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	#[EntryField( name: 'errorPreview', type: SettingType::String, label: 'Error', list: true, create: false, update: false, read_only: true )]
	public function regen_log( array $params ): array {
		$rows = array_reverse( $this->table( 'regen_log' )->export_rows() );
		return $this->paginated_rows( array_map( array( $this, 'log_row_for_display' ), $rows ), $params );
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{ok:bool,name:string,origin:string}
	 */
	#[Action( 'saveImageSize' )]
	#[Input( 'name', SettingType::String, required: true )]
	#[Input( 'originalName', SettingType::String, default: '' )]
	#[Input( 'label', SettingType::String, default: '' )]
	#[Input( 'origin', SettingType::String, default: 'custom' )]
	#[Input( 'width', SettingType::Integer, default: 0 )]
	#[Input( 'height', SettingType::Integer, default: 0 )]
	#[Input( 'cropMode', SettingType::String, default: 'none', allowed: array( 'none', 'crop' ) )]
	#[Input( 'cropPosition', SettingType::String, default: 'center', allowed: array( 'center', 'top', 'bottom', 'left', 'right', 'top-left', 'top-right', 'bottom-left', 'bottom-right' ) )]
	public function save_image_size( array $input ): array {
		$row           = $this->stored_row_from_input( $input );
		$original_name = $this->sanitize_name( (string) ( $input['originalName'] ?? '' ) );

		$this->persist_definitions(
			function ( array $sizes, array $overrides ) use ( $row, $original_name ): array {
				$this->assert_name_can_be_saved( $row['name'], $original_name, $sizes );

				if ( '' !== $original_name && $original_name !== $row['name'] ) {
					unset( $sizes[ $original_name ], $overrides[ $original_name ] );
				}

				if ( 'custom' === $row['origin'] ) {
					$sizes[ $row['name'] ] = $this->settings_row( $row );
					unset( $overrides[ $row['name'] ] );
				} else {
					$overrides[ $row['name'] ] = $this->settings_row( $row );
					unset( $sizes[ $row['name'] ] );
				}

				return array(
					'sizes'     => $sizes,
					'overrides' => $overrides,
				);
			}
		);
		$this->apply_core_overrides();

		return array(
			'ok'     => true,
			'name'   => $row['name'],
			'origin' => $row['origin'],
		);
	}

	/**
	 * @param array{ids:array<mixed>} $input Input.
	 * @return array{ok:bool,deleted:list<string>}
	 */
	#[Action( 'deleteImageSizes' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function delete_image_sizes( array $input ): array {
		$ids     = $this->string_list( $input['ids'] ?? array() );
		$deleted = array();

		$this->persist_definitions(
			function ( array $sizes, array $overrides ) use ( $ids, &$deleted ): array {
				foreach ( $ids as $id ) {
					if ( isset( $sizes[ $id ] ) ) {
						unset( $sizes[ $id ] );
						$deleted[] = $id;
						continue;
					}
					if ( isset( $overrides[ $id ] ) ) {
						unset( $overrides[ $id ] );
						$deleted[] = $id;
					}
				}

				return array(
					'sizes'     => $sizes,
					'overrides' => $overrides,
				);
			}
		);
		$this->apply_core_overrides();

		return array(
			'ok'      => true,
			'deleted' => $deleted,
		);
	}

	/**
	 * @return array{ok:bool,status:string,queued:int,processed:int,errors:int}
	 */
	#[Action( 'startRegeneration' )]
	public function start_regeneration(): array {
		$config         = $this->regenerate_config();
		$size_names     = $config['sizeNames'];
		$available      = array_column( $this->size_rows(), 'name' );
		$available      = array_values( array_filter( $available, 'is_string' ) );
		$size_names     = array_values( array_intersect( array() === $size_names ? $available : $size_names, $available ) );
		$attachment_ids = $this->attachment_ids( $config['postTypes'] );
		$started_at     = $this->now();

		$this->persist_regenerate(
			array_merge(
				$config,
				array(
					'job' => array(
						'status'        => self::STATUS_RUNNING,
						'startedAt'     => $started_at,
						'startedLabel'  => $this->time_label( $started_at ),
						'finishedAt'    => 0,
						'finishedLabel' => '',
						'attachmentIds' => $attachment_ids,
						'sizeNames'     => $size_names,
						'cursor'        => 0,
						'processed'     => 0,
						'errors'        => 0,
					),
				)
			)
		);

		$result = $this->process_regen_tick();
		if ( self::STATUS_RUNNING === $result['status'] ) {
			$this->schedule_tick();
		}

		return array(
			'ok'        => true,
			'status'    => $result['status'],
			'queued'    => count( $attachment_ids ),
			'processed' => $result['processed'],
			'errors'    => $result['errors'],
		);
	}

	#[WpAction( 'after_setup_theme', priority: 10, accepted_args: 0 )]
	public function register_image_sizes(): void {
		$this->apply_core_overrides();

		foreach ( $this->stored_custom_sizes() as $row ) {
			if ( function_exists( 'add_image_size' ) ) {
				\add_image_size( $row['name'], $row['width'], $row['height'], $this->crop_arg( $row['cropMode'] ) );
			}
		}
	}

	/**
	 * @return array{status:string,processed:int,errors:int}
	 */
	#[WpAction( 'onumia_image_sizes_regen_tick', accepted_args: 0 )]
	public function process_regen_tick(): array {
		$config = $this->regenerate_config();
		$job    = is_array( $config['job'] ?? null ) ? $config['job'] : array();
		if ( self::STATUS_RUNNING !== ( $job['status'] ?? '' ) ) {
			return array(
				'status'    => self::STATUS_IDLE,
				'processed' => 0,
				'errors'    => 0,
			);
		}

		$attachment_ids = $this->integer_list( $job['attachmentIds'] ?? array() );
		$size_names     = $this->string_list( $job['sizeNames'] ?? array() );
		$cursor         = isset( $job['cursor'] ) && is_numeric( $job['cursor'] ) ? max( 0, (int) $job['cursor'] ) : 0;
		$batch_size     = $this->batch_size( $config );
		$batch          = array_slice( $attachment_ids, $cursor, $batch_size );
		$processed      = isset( $job['processed'] ) && is_numeric( $job['processed'] ) ? max( 0, (int) $job['processed'] ) : 0;
		$errors         = isset( $job['errors'] ) && is_numeric( $job['errors'] ) ? max( 0, (int) $job['errors'] ) : 0;

		foreach ( $batch as $attachment_id ) {
			$result    = $this->regenerate_attachment( $attachment_id, $size_names, true === $config['skipExisting'] );
			$processed += $result['processed'];
			$errors    += $result['errors'];
		}

		$cursor += count( $batch );
		$status  = $cursor >= count( $attachment_ids ) ? self::STATUS_DONE : self::STATUS_RUNNING;
		$finished_at = self::STATUS_DONE === $status ? $this->now() : 0;

		$this->persist_regenerate(
			array_merge(
				$config,
				array(
					'job' => array(
						'status'        => $status,
						'startedAt'     => isset( $job['startedAt'] ) && is_numeric( $job['startedAt'] ) ? (int) $job['startedAt'] : $this->now(),
						'startedLabel'  => is_string( $job['startedLabel'] ?? null ) ? $job['startedLabel'] : '',
						'finishedAt'    => $finished_at,
						'finishedLabel' => 0 < $finished_at ? $this->time_label( $finished_at ) : '',
						'attachmentIds' => $attachment_ids,
						'sizeNames'     => $size_names,
						'cursor'        => $cursor,
						'processed'     => $processed,
						'errors'        => $errors,
					),
				)
			)
		);

		if ( self::STATUS_RUNNING === $status ) {
			$this->schedule_tick();
		} else {
			$this->clear_scheduled_tick();
		}

		return array(
			'status'    => $status,
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	#[WpAction( 'onumia_tables_cleanup', priority: 10, accepted_args: 0 )]
	public function prune_runtime_tables(): void {
		$this->table( 'regen_log' )->purge( 30 );
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function size_rows(): array {
		$registered = $this->registered_subsizes();
		$custom     = $this->stored_custom_sizes_by_name();
		$overrides  = $this->stored_overrides_by_name();
		$rows       = array();

		foreach ( $registered as $name => $definition ) {
			$row = $this->row_from_registered( $name, $definition );
			if ( isset( $overrides[ $name ] ) ) {
				$row           = array_merge( $row, $overrides[ $name ] );
				$row['origin'] = 'builtin-override';
			}
			$rows[ $name ] = $this->decorate_row( $row );
		}

		foreach ( $custom as $name => $row ) {
			$rows[ $name ] = $this->decorate_row( $row );
		}

		usort( $rows, static fn( array $left, array $right ): int => (string) $left['name'] <=> (string) $right['name'] );
		return array_values( $rows );
	}

	public function apply_core_overrides(): void {
		foreach ( $this->stored_overrides_by_name() as $name => $row ) {
			$options = self::CORE_OPTION_MAP[ $name ] ?? null;
			if ( null === $options ) {
				continue;
			}

			if ( function_exists( 'update_option' ) ) {
				\update_option( $options['width'], $row['width'], false );
				\update_option( $options['height'], $row['height'], false );
				if ( isset( $options['crop'] ) ) {
					\update_option( $options['crop'], 'none' === $row['cropMode'] ? 0 : 1, false );
				}
			}
		}
	}

	/**
	 * @return list<array{name:string,label:string,origin:string,width:int,height:int,cropMode:string}>
	 */
	public function stored_custom_sizes(): array {
		return array_values( $this->stored_custom_sizes_by_name() );
	}

	/**
	 * @return array{name:string,label:string,origin:string,width:int,height:int,cropMode:string}
	 */
	private function stored_row_from_input( array $input ): array {
		$name = $this->sanitize_name( (string) ( $input['name'] ?? '' ) );
		if ( '' === $name ) {
			throw Errors::invariant( 'Image size name is required.' );
		}
		if ( ! preg_match( '/^[a-z0-9_-]{1,60}$/', $name ) ) {
			throw Errors::invariant( 'Image size name must use 1-60 lowercase letters, numbers, dashes, or underscores.' );
		}

		$origin = in_array( $input['origin'] ?? 'custom', array( 'builtin', 'builtin-override' ), true ) ? 'builtin-override' : 'custom';
		if ( 'custom' === $origin && isset( $this->registered_subsizes()[ $name ] ) && ! isset( $this->stored_custom_sizes_by_name()[ $name ] ) ) {
			$origin = 'builtin-override';
		}

		$crop_mode = 'crop' === ( $input['cropMode'] ?? 'none' )
			? 'crop:' . $this->crop_position( $input['cropPosition'] ?? 'center' )
			: 'none';
		$label     = trim( (string) ( $input['label'] ?? '' ) );

		return array(
			'name'     => $name,
			'label'    => '' === $label ? $this->human_label( $name ) : $label,
			'origin'   => $origin,
			'width'    => max( 0, min( 10000, (int) ( $input['width'] ?? 0 ) ) ),
			'height'   => max( 0, min( 10000, (int) ( $input['height'] ?? 0 ) ) ),
			'cropMode' => $crop_mode,
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $sizes Stored custom sizes keyed by name.
	 */
	private function assert_name_can_be_saved( string $name, string $original_name, array $sizes ): void {
		if ( '' === $original_name ) {
			if ( isset( $sizes[ $name ] ) || isset( $this->registered_subsizes()[ $name ] ) ) {
				throw Errors::invariant( "Image size {$name} already exists." );
			}

			return;
		}

		if ( $original_name === $name ) {
			return;
		}

		if ( ! isset( $sizes[ $original_name ] ) ) {
			throw Errors::invariant( "Image size {$original_name} is not owned by Onumia and cannot change name." );
		}

		if ( isset( $sizes[ $name ] ) || isset( $this->registered_subsizes()[ $name ] ) ) {
			throw Errors::invariant( "Image size {$name} already exists." );
		}
	}

	/**
	 * @param array{name:string,label:string,origin:string,width:int,height:int,cropMode:string} $row Row.
	 * @return array{name:string,label:string,origin:string,width:int,height:int,cropMode:string}
	 */
	private function settings_row( array $row ): array {
		return array(
			'name'     => $row['name'],
			'label'    => $row['label'],
			'origin'   => $row['origin'],
			'width'    => $row['width'],
			'height'   => $row['height'],
			'cropMode' => $row['cropMode'],
		);
	}

	/**
	 * @param callable(array<string,array<string,mixed>>,array<string,array<string,mixed>>):array{sizes:array<string,array<string,mixed>>,overrides:array<string,array<string,mixed>>} $updater Updater.
	 */
	private function persist_definitions( callable $updater ): void {
		( new ModuleSettingsRepository() )->update_settings_with(
			$this->definition(),
			function ( array $settings ) use ( $updater ): array {
				$current = $updater(
					$this->stored_custom_sizes_by_name_from( $settings['sizes'] ?? array() ),
					$this->stored_overrides_by_name_from( $settings['overrides'] ?? array() )
				);

				return array(
					'sizes'     => array_values( $current['sizes'] ),
					'overrides' => $current['overrides'],
				);
			}
		);
	}

	/**
	 * @param array<string,mixed> $config Config.
	 */
	private function persist_regenerate( array $config ): void {
		( new ModuleSettingsRepository() )->update_settings_with(
			$this->definition(),
			static fn( array $settings ): array => array_merge( $settings, array( 'regenerate' => $config ) )
		);
	}

	private function schedule_tick(): void {
		if ( function_exists( 'wp_next_scheduled' ) && false !== \wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			\wp_schedule_single_event( $this->now() + 60, self::CRON_HOOK );
			return;
		}

		if ( function_exists( 'wp_schedule_event' ) ) {
			\wp_schedule_event( $this->now() + 60, 'hourly', self::CRON_HOOK );
		}
	}

	private function clear_scheduled_tick(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_unschedule_event' ) ) {
			return;
		}

		while ( false !== ( $timestamp = \wp_next_scheduled( self::CRON_HOOK ) ) ) {
			if ( ! is_numeric( $timestamp ) || ! \wp_unschedule_event( (int) $timestamp, self::CRON_HOOK ) ) {
				return;
			}
		}
	}

	/**
	 * @param list<string> $size_names Size names.
	 * @return array{processed:int,errors:int}
	 */
	private function regenerate_attachment( int $attachment_id, array $size_names, bool $skip_existing ): array {
		$started_at = $this->now();
		$metadata   = $this->attachment_metadata( $attachment_id );
		$missing    = array();
		foreach ( $size_names as $size_name ) {
			if ( $skip_existing && $this->metadata_has_size( $metadata, $size_name ) ) {
				$this->insert_log( $started_at, $this->now(), $attachment_id, $size_name, 'skipped', null );
				continue;
			}
			$missing[] = $size_name;
		}

		if ( array() !== $missing ) {
			$this->generate_attachment_sizes( $attachment_id, $missing );
			$metadata = $this->attachment_metadata( $attachment_id );
		}

		$processed = 0;
		$errors    = 0;
		foreach ( $missing as $size_name ) {
			$ok = $this->metadata_has_size( $metadata, $size_name );
			$this->insert_log(
				$started_at,
				$this->now(),
				$attachment_id,
				$size_name,
				$ok ? 'ok' : 'failed',
				$ok ? null : 'Image size was not generated.'
			);
			++$processed;
			if ( ! $ok ) {
				++$errors;
			}
		}

		return array(
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	/**
	 * @param list<string> $size_names Size names.
	 */
	private function generate_attachment_sizes( int $attachment_id, array $size_names ): void {
		$file = function_exists( 'get_attached_file' ) ? \get_attached_file( $attachment_id ) : false;
		if ( is_string( $file ) && '' !== $file ) {
			$this->load_image_admin_functions();
			if ( function_exists( 'wp_update_image_subsizes' ) ) {
				\wp_update_image_subsizes( $attachment_id );
				return;
			}
			if ( function_exists( 'wp_generate_attachment_metadata' ) && function_exists( 'wp_update_attachment_metadata' ) ) {
				$metadata = \wp_generate_attachment_metadata( $attachment_id, $file );
				if ( is_array( $metadata ) ) {
					\wp_update_attachment_metadata( $attachment_id, $metadata );
					return;
				}
			}
		}
	}

	private function load_image_admin_functions(): void {
		if ( function_exists( 'wp_update_image_subsizes' ) || ! defined( 'ABSPATH' ) ) {
			return;
		}

		$file = ABSPATH . 'wp-admin/includes/image.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}

	private function insert_log( int $started_at, int $finished_at, int $attachment_id, string $size_name, string $status, ?string $error ): void {
		$this->table( 'regen_log' )->insert(
			array(
				'started_at'    => $started_at,
				'finished_at'   => $finished_at,
				'attachment_id' => $attachment_id,
				'size_name'     => substr( $size_name, 0, 60 ),
				'status'        => substr( $status, 0, 16 ),
				'error'         => null === $error ? null : substr( $error, 0, 255 ),
			)
		);
	}

	/**
	 * @param list<string> $post_types Post types.
	 * @return list<int>
	 */
	private function attachment_ids( array $post_types ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$attachments = \get_posts(
			array(
				'fields'           => 'ids',
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			)
		);
		$ids = array_values( array_filter( $attachments, 'is_int' ) );
		if ( array() === $post_types ) {
			return $ids;
		}

		return array_values(
			array_filter(
				$ids,
				function ( int $attachment_id ) use ( $post_types ): bool {
					$parent_id = $this->attachment_parent_id( $attachment_id );
					if ( $parent_id <= 0 || ! function_exists( 'get_post_type' ) ) {
						return false;
					}

					$type = \get_post_type( $parent_id );
					return is_string( $type ) && in_array( $type, $post_types, true );
				}
			)
		);
	}

	private function attachment_parent_id( int $attachment_id ): int {
		if ( function_exists( 'wp_get_post_parent_id' ) ) {
			return (int) \wp_get_post_parent_id( $attachment_id );
		}

		return 0;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function attachment_metadata( int $attachment_id ): array {
		$metadata = function_exists( 'wp_get_attachment_metadata' ) ? \wp_get_attachment_metadata( $attachment_id ) : false;
		return is_array( $metadata ) ? $metadata : array();
	}

	/**
	 * @param array<string,mixed> $metadata Metadata.
	 */
	private function metadata_has_size( array $metadata, string $size_name ): bool {
		$sizes = $metadata['sizes'] ?? null;
		return is_array( $sizes ) && is_array( $sizes[ $size_name ] ?? null );
	}

	/**
	 * @param array<string,mixed> $config Config.
	 */
	private function batch_size( array $config ): int {
		$value = $config['batchSize'] ?? 10;
		return is_numeric( $value ) ? max( 1, min( 100, (int) $value ) ) : 10;
	}

	/**
	 * @return array{batchSize:int,skipExisting:bool,sizeNames:list<string>,postTypes:list<string>,job:array<string,mixed>}
	 */
	private function regenerate_config(): array {
		$value = $this->setting( 'regenerate' );
		$data  = is_array( $value ) ? $value : array();
		$job   = is_array( $data['job'] ?? null ) ? $data['job'] : array();

		return array(
			'batchSize'    => isset( $data['batchSize'] ) && is_numeric( $data['batchSize'] ) ? max( 1, min( 100, (int) $data['batchSize'] ) ) : 10,
			'skipExisting' => false !== ( $data['skipExisting'] ?? true ),
			'sizeNames'    => $this->string_list( $data['sizeNames'] ?? array() ),
			'postTypes'    => $this->string_list( $data['postTypes'] ?? array() ),
			'job'          => $job,
		);
	}

	/**
	 * @return array<string,array{name:string,label:string,origin:string,width:int,height:int,cropMode:string}>
	 */
	private function stored_custom_sizes_by_name(): array {
		return $this->stored_custom_sizes_by_name_from( $this->setting( 'sizes' ) );
	}

	/**
	 * @param mixed $value Value.
	 * @return array<string,array{name:string,label:string,origin:string,width:int,height:int,cropMode:string}>
	 */
	private function stored_custom_sizes_by_name_from( mixed $value ): array {
		$rows = array();
		if ( ! is_array( $value ) ) {
			return $rows;
		}

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$normalized = $this->normalize_row( array_merge( $row, array( 'origin' => 'custom' ) ) );
			if ( '' !== $normalized['name'] ) {
				$rows[ $normalized['name'] ] = $normalized;
			}
		}

		return $rows;
	}

	/**
	 * @return array<string,array{name:string,label:string,origin:string,width:int,height:int,cropMode:string}>
	 */
	private function stored_overrides_by_name(): array {
		return $this->stored_overrides_by_name_from( $this->setting( 'overrides' ) );
	}

	/**
	 * @param mixed $value Value.
	 * @return array<string,array{name:string,label:string,origin:string,width:int,height:int,cropMode:string}>
	 */
	private function stored_overrides_by_name_from( mixed $value ): array {
		$rows = array();
		if ( ! is_array( $value ) ) {
			return $rows;
		}

		foreach ( $value as $name => $row ) {
			if ( ! is_string( $name ) || ! is_array( $row ) ) {
				continue;
			}
			$normalized = $this->normalize_row(
				array_merge(
					$row,
					array(
						'name'   => $name,
						'origin' => 'builtin-override',
					)
				)
			);
			if ( '' !== $normalized['name'] ) {
				$rows[ $normalized['name'] ] = $normalized;
			}
		}

		return $rows;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function registered_subsizes(): array {
		if ( function_exists( 'wp_get_registered_image_subsizes' ) ) {
			$sizes = \wp_get_registered_image_subsizes();
			if ( is_array( $sizes ) && array() !== $sizes ) {
				return $this->string_keyed_rows( $sizes );
			}
		}

		return array(
			'thumbnail' => array(
				'width'  => 150,
				'height' => 150,
				'crop'   => true,
			),
			'medium'    => array(
				'width'  => 300,
				'height' => 300,
				'crop'   => false,
			),
			'large'     => array(
				'width'  => 1024,
				'height' => 1024,
				'crop'   => false,
			),
		);
	}

	/**
	 * @param array<mixed> $rows Rows.
	 * @return array<string,array<string,mixed>>
	 */
	private function string_keyed_rows( array $rows ): array {
		$result = array();
		foreach ( $rows as $name => $row ) {
			if ( is_string( $name ) && is_array( $row ) ) {
				$result[ $name ] = $row;
			}
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $definition Definition.
	 * @return array{name:string,label:string,origin:string,width:int,height:int,cropMode:string}
	 */
	private function row_from_registered( string $name, array $definition ): array {
		return $this->normalize_row(
			array(
				'name'     => $name,
				'label'    => $this->human_label( $name ),
				'origin'   => in_array( $name, self::CORE_NAMES, true ) ? 'builtin' : 'builtin',
				'width'    => $definition['width'] ?? 0,
				'height'   => $definition['height'] ?? 0,
				'cropMode' => $this->crop_mode_from_value( $definition['crop'] ?? false ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array{name:string,label:string,origin:string,width:int,height:int,cropMode:string}
	 */
	private function normalize_row( array $row ): array {
		$name      = $this->sanitize_name( (string) ( $row['name'] ?? '' ) );
		$label     = trim( (string) ( $row['label'] ?? '' ) );
		$origin    = in_array( $row['origin'] ?? 'custom', array( 'builtin', 'builtin-override', 'custom' ), true ) ? (string) $row['origin'] : 'custom';
		$crop_mode = $this->stored_crop_mode( $row['cropMode'] ?? ( isset( $row['crop'] ) ? $this->crop_mode_from_value( $row['crop'] ) : 'none' ) );

		return array(
			'name'     => $name,
			'label'    => '' === $label ? $this->human_label( $name ) : $label,
			'origin'   => $origin,
			'width'    => max( 0, min( 10000, (int) ( $row['width'] ?? 0 ) ) ),
			'height'   => max( 0, min( 10000, (int) ( $row['height'] ?? 0 ) ) ),
			'cropMode' => $crop_mode,
		);
	}

	/**
	 * @param array{name:string,label:string,origin:string,width:int,height:int,cropMode:string} $row Row.
	 * @return array<string,mixed>
	 */
	private function decorate_row( array $row ): array {
		$crop_base = str_starts_with( $row['cropMode'], 'crop' ) ? 'crop' : 'none';
		$position  = str_starts_with( $row['cropMode'], 'crop:' ) ? substr( $row['cropMode'], strlen( 'crop:' ) ) : 'center';
		$position  = $this->crop_position( $position );

		return array_merge(
			$row,
			array(
				'cropMode'      => $crop_base,
				'cropPosition'  => $position,
				'cropModeLabel' => 'crop' === $crop_base ? 'Crop ' . $this->human_label( $position ) : 'Proportional',
				'originLabel'   => 'custom' === $row['origin'] ? 'Custom' : ( 'builtin-override' === $row['origin'] ? 'Built-in override' : 'Built-in' ),
				'canDelete'     => 'custom' === $row['origin'] || 'builtin-override' === $row['origin'],
			)
		);
	}

	private function crop_mode_from_value( mixed $crop ): string {
		if ( true === $crop ) {
			return 'crop:center';
		}

		if ( is_array( $crop ) ) {
			$horizontal = is_string( $crop[0] ?? null ) ? $crop[0] : '';
			$vertical   = is_string( $crop[1] ?? null ) ? $crop[1] : '';
			$position   = trim( implode( '-', array_filter( array( $vertical, $horizontal ), static fn( string $part ): bool => '' !== $part ) ), '-' );
			return 'crop:' . $this->crop_position( $position );
		}

		return 'none';
	}

	private function stored_crop_mode( mixed $value ): string {
		if ( true === $value ) {
			return 'crop:center';
		}
		if ( false === $value ) {
			return 'none';
		}

		$mode = is_scalar( $value ) ? trim( (string) $value ) : 'none';
		if ( 'crop' === $mode ) {
			return 'crop:center';
		}
		if ( str_starts_with( $mode, 'crop:' ) ) {
			return 'crop:' . $this->crop_position( substr( $mode, strlen( 'crop:' ) ) );
		}

		return 'none';
	}

	/**
	 * @return false|array{0:string,1:string}
	 */
	private function crop_arg( string $mode ): bool|array {
		if ( ! str_starts_with( $mode, 'crop' ) ) {
			return false;
		}

		$position = str_starts_with( $mode, 'crop:' ) ? substr( $mode, strlen( 'crop:' ) ) : 'center';
		if ( 'center' === $position ) {
			return true;
		}

		$parts      = explode( '-', $this->crop_position( $position ) );
		$vertical   = $parts[0] ?? 'center';
		$horizontal = $parts[1] ?? $parts[0] ?? 'center';
		if ( in_array( $vertical, array( 'left', 'right' ), true ) ) {
			$horizontal = $vertical;
			$vertical   = 'center';
		}

		return array( $horizontal, $vertical );
	}

	private function crop_position( mixed $position ): string {
		$value = is_scalar( $position ) ? trim( (string) $position ) : 'center';
		return in_array( $value, self::CROP_POSITIONS, true ) ? $value : 'center';
	}

	private function sanitize_name( string $name ): string {
		return function_exists( 'sanitize_key' ) ? \sanitize_key( $name ) : strtolower( preg_replace( '/[^a-z0-9_-]+/', '', $name ) ?? '' );
	}

	private function human_label( string $value ): string {
		$label = trim( str_replace( array( '-', '_' ), ' ', $value ) );
		return '' === $label ? 'Image size' : ucwords( $label );
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function log_row_for_display( array $row ): array {
		$started  = isset( $row['started_at'] ) && is_numeric( $row['started_at'] ) ? (int) $row['started_at'] : 0;
		$finished = isset( $row['finished_at'] ) && is_numeric( $row['finished_at'] ) ? (int) $row['finished_at'] : 0;
		$error    = is_string( $row['error'] ?? null ) ? $row['error'] : '';
		$status   = is_string( $row['status'] ?? null ) ? $row['status'] : '';

		return array(
			'id'              => (int) ( $row['id'] ?? 0 ),
			'startedAt'       => $started,
			'startedAtLabel'  => $this->time_label( $started ),
			'finishedAt'      => $finished,
			'finishedAtLabel' => $this->time_label( $finished ),
			'attachmentId'    => (int) ( $row['attachment_id'] ?? 0 ),
			'sizeName'        => (string) ( $row['size_name'] ?? '' ),
			'status'          => $status,
			'statusLabel'     => ucfirst( $status ),
			'error'           => $error,
			'errorPreview'    => strlen( $error ) > 80 ? substr( $error, 0, 77 ) . '...' : $error,
		);
	}

	/**
	 * @param mixed $value Value.
	 * @return list<int>
	 */
	private function integer_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( is_numeric( $item ) ) {
				$items[] = (int) $item;
			}
		}

		return array_values( array_unique( array_filter( $items, static fn( int $item ): bool => $item > 0 ) ) );
	}
}
