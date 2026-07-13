<?php
/**
 * Cron Events module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\CronEvents;

use Onumia\Modules\Attributes\Action;
use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Entries;
use Onumia\Modules\Attributes\EntryField;
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

#[ModuleContract( capability: 'manage_options' )]
#[Setting( 'enabled', SettingType::Boolean, default: false )]
#[Setting( 'stuckThresholdSeconds', SettingType::Integer, default: 60, min: 1, max: 3600 )]
#[Setting( 'retentionDays', SettingType::Integer, default: 14, min: 1, max: 365 )]
#[Setting( 'allowUnschedule', SettingType::Boolean, default: false )]
#[Setting( 'unscheduleAllowlist', SettingType::Array, default: array() )]
final class CronEvents extends Module {
	private const STATUS_OK      = 'ok';
	private const STATUS_TIMEOUT = 'timeout';
	private const STATUS_ERROR   = 'error';

	/**
	 * @var array<int,array{started_at:int,hook:string}>
	 */
	private array $active_runs = array();

	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'scheduledEvents', shape: DataSourceShape::Collection, pagination: PaginationMode::Client )]
	public function scheduled_events(): array {
		$events    = $this->cron_events();
		$schedules = $this->cron_schedules();
		$rows      = array();

		foreach ( $events as $event ) {
			$schedule = $event['schedule'];
			$args     = $event['args'];
			$rows[]   = array(
				'id'              => $event['id'],
				'hook'            => $event['hook'],
				'nextRun'         => $event['timestamp'],
				'nextRunLabel'    => $this->timestamp_label( $event['timestamp'] ),
				'recurrence'      => $schedule,
				'recurrenceLabel' => $this->recurrence_label( $schedule, $schedules, $event['interval'] ),
				'args'            => $args,
				'argsPreview'     => $this->args_preview( $args ),
				'unscheduleAllowed' => $this->unschedule_allowed( (string) $event['hook'] ),
			);
		}

		usort( $rows, static fn( array $left, array $right ): int => (int) $left['nextRun'] <=> (int) $right['nextRun'] );
		return $rows;
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'runLog', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'runLog', singular: 'Run', plural: 'Runs', key: 'id', storage: EntryStorage::Table, source: 'runLog', table: 'runs' )]
	#[EntryField( name: 'id', type: SettingType::Integer, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'startedAt', type: SettingType::Integer, label: 'Started timestamp', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'startedAtLabel', type: SettingType::String, label: 'Started', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'finishedAt', type: SettingType::Integer, label: 'Finished timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'finishedAtLabel', type: SettingType::String, label: 'Finished', create: false, update: false, read_only: true )]
	#[EntryField( name: 'hook', type: SettingType::String, label: 'Hook', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'durationMs', type: SettingType::Integer, label: 'Duration milliseconds', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'durationLabel', type: SettingType::String, label: 'Duration', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'status', type: SettingType::String, label: 'Status value', allowed: array( 'ok', 'timeout', 'error' ), filter: true, filter_type: 'option', create: false, update: false, read_only: true )]
	#[EntryField( name: 'statusLabel', type: SettingType::String, label: 'Status', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'error', type: SettingType::String, label: 'Error', filter: true, filter_type: 'text', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	#[EntryField( name: 'errorPreview', type: SettingType::String, label: 'Error', list: true, create: false, update: false, read_only: true )]
	public function run_log( array $params ): array {
		$rows = array_reverse( $this->table( 'runs' )->export_rows() );
		return $this->paginated_rows( array_map( array( $this, 'run_for_display' ), $rows ), $params );
	}

	public function boot(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		register_shutdown_function( array( $this, 'mark_unfinished_runs_as_timeout' ) );
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{ok:bool,hook:string,status:string}
	 */
	#[Action( 'runNow' )]
	#[Input( 'hook', SettingType::String, default: '' )]
	#[Input( 'args', SettingType::Array, default: array() )]
	public function run_now( array $input ): array {
		$hook = is_string( $input['hook'] ?? null ) ? trim( $input['hook'] ) : '';
		$args = is_array( $input['args'] ?? null ) ? array_values( $input['args'] ) : array();

		if ( '' === $hook ) {
			$event = $this->next_event();
			$hook  = null === $event ? 'wp_version_check' : $event['hook'];
			$args  = null === $event ? array() : $event['args'];
		}

		$result = $this->record_cron_run(
			$hook,
			function () use ( $hook, $args ): void {
				if ( function_exists( 'do_action_ref_array' ) ) {
					\do_action_ref_array( $hook, $args );
					return;
				}

				if ( function_exists( 'do_action' ) ) {
					\do_action( $hook, ...$args );
				}
			}
		);

		return array(
			'ok'     => self::STATUS_OK === $result['status'],
			'hook'   => $hook,
			'status' => $result['status'],
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{ok:bool,hook:string}
	 */
	#[Action( 'unscheduleEvent' )]
	#[Input( 'hook', SettingType::String, default: '' )]
	#[Input( 'timestamp', SettingType::Integer, default: 0 )]
	#[Input( 'args', SettingType::Array, default: array() )]
	public function unschedule_event( array $input ): array {
		$hook      = is_string( $input['hook'] ?? null ) ? trim( $input['hook'] ) : '';
		$timestamp = isset( $input['timestamp'] ) && is_numeric( $input['timestamp'] ) ? (int) $input['timestamp'] : 0;
		$args      = is_array( $input['args'] ?? null ) ? array_values( $input['args'] ) : array();

		if ( '' === $hook || 0 >= $timestamp || false === $this->unschedule_allowed( $hook ) ) {
			return array(
				'ok'   => false,
				'hook' => $hook,
			);
		}

		if ( function_exists( 'wp_unschedule_event' ) ) {
			\wp_unschedule_event( $timestamp, $hook, $args );
		}

		return array(
			'ok'   => true,
			'hook' => $hook,
		);
	}

	#[WpFilter( 'pre_unschedule_event', priority: 999, accepted_args: 5 )]
	public function guard_unschedule( mixed $pre, int $timestamp, string $hook, array $args = array(), bool $wp_error = false ): mixed {
		unset( $timestamp, $args );
		if ( null !== $pre ) {
			return $pre;
		}

		if ( $this->unschedule_allowed( $hook ) ) {
			return null;
		}

		if ( $wp_error && class_exists( '\WP_Error' ) ) {
			return new \WP_Error( 'onumia_cron_unschedule_blocked', 'This cron event is not in the Onumia unschedule allowlist.' );
		}

		return false;
	}

	#[WpAction( 'onumia_tables_cleanup', priority: 10, accepted_args: 0 )]
	public function prune_runtime_tables(): void {
		$this->table( 'runs' )->purge( $this->retention_days() );
	}

	/**
	 * @param callable():void $callback Callback.
	 * @return array{id:int,started_at:int,finished_at:int|null,hook:string,duration_ms:int|null,status:string,error:string|null}
	 */
	public function record_cron_run( string $hook, callable $callback, ?int $forced_duration_ms = null ): array {
		$hook       = '' === trim( $hook ) ? '<unknown>' : substr( trim( $hook ), 0, 128 );
		$started_at = $this->now();
		$id         = $this->table( 'runs' )->insert(
			array(
				'started_at'  => $started_at,
				'finished_at' => null,
				'hook'        => $hook,
				'duration_ms' => null,
				'status'      => self::STATUS_TIMEOUT,
				'error'       => null,
			)
		);

		$this->active_runs[ $id ] = array(
			'started_at' => $started_at,
			'hook'       => $hook,
		);
		$start = microtime( true );

		try {
			$callback();
			$duration = null === $forced_duration_ms ? max( 0, (int) round( ( microtime( true ) - $start ) * 1000 ) ) : max( 0, $forced_duration_ms );
			$status   = $duration >= ( $this->stuck_threshold_seconds() * 1000 ) ? self::STATUS_TIMEOUT : self::STATUS_OK;
			$error    = self::STATUS_TIMEOUT === $status ? 'Exceeded stuck threshold.' : null;
		} catch ( \Throwable $throwable ) {
			$duration = null === $forced_duration_ms ? max( 0, (int) round( ( microtime( true ) - $start ) * 1000 ) ) : max( 0, $forced_duration_ms );
			$status   = self::STATUS_ERROR;
			$error    = substr( $throwable->getMessage(), 0, 512 );
		}

		$finished_at = $this->now();
		$row         = array(
			'started_at'  => $started_at,
			'finished_at' => $finished_at,
			'hook'        => $hook,
			'duration_ms' => $duration,
			'status'      => $status,
			'error'       => $error,
		);
		$this->table( 'runs' )->update( $id, $row );
		unset( $this->active_runs[ $id ] );

		return array( 'id' => $id ) + $row;
	}

	public function mark_unfinished_runs_as_timeout(): void {
		if ( array() === $this->active_runs ) {
			return;
		}

		$now = $this->now();
		foreach ( $this->active_runs as $id => $run ) {
			$duration = max( 0, ( $now - $run['started_at'] ) * 1000 );
			if ( $duration < $this->stuck_threshold_seconds() * 1000 ) {
				continue;
			}

			$this->table( 'runs' )->update(
				$id,
				array(
					'finished_at' => null,
					'duration_ms' => $duration,
					'status'      => self::STATUS_TIMEOUT,
					'error'       => 'Cron run did not finish before shutdown.',
				)
			);
		}
		$this->active_runs = array();
	}

	private function stuck_threshold_seconds(): int {
		$value = $this->setting( 'stuckThresholdSeconds' );
		return is_int( $value ) ? max( 1, min( 3600, $value ) ) : 60;
	}

	private function unschedule_allowed( string $hook ): bool {
		if ( true !== $this->setting( 'allowUnschedule' ) ) {
			return false;
		}

		foreach ( $this->unschedule_allowlist() as $pattern ) {
			if ( $hook === $pattern || fnmatch( $pattern, $hook ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<string>
	 */
	private function unschedule_allowlist(): array {
		$patterns = array();
		foreach ( $this->array_setting( 'unscheduleAllowlist' ) as $item ) {
			$value = is_array( $item ) ? ( $item['value'] ?? '' ) : $item;
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$patterns[] = trim( (string) $value );
			}
		}

		return array_values( array_unique( $patterns ) );
	}

	/**
	 * @return array{id:string,timestamp:int,hook:string,schedule:string,args:list<mixed>,interval:int|null}|null
	 */
	private function next_event(): ?array {
		$events = $this->cron_events();
		if ( array() === $events ) {
			return null;
		}

		usort( $events, static fn( array $left, array $right ): int => $left['timestamp'] <=> $right['timestamp'] );
		return $events[0];
	}

	/**
	 * @return list<array{id:string,timestamp:int,hook:string,schedule:string,args:list<mixed>,interval:int|null}>
	 */
	private function cron_events(): array {
		$cron = function_exists( '_get_cron_array' ) ? \_get_cron_array() : array();
		if ( ! is_array( $cron ) ) {
			return array();
		}

		$events = array();
		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_numeric( $timestamp ) || ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $instances ) {
				if ( ! is_string( $hook ) || ! is_array( $instances ) ) {
					continue;
				}
				foreach ( $instances as $instance_key => $event ) {
					if ( ! is_array( $event ) ) {
						continue;
					}

					$args     = is_array( $event['args'] ?? null ) ? array_values( $event['args'] ) : array();
					$schedule = is_string( $event['schedule'] ?? null ) ? $event['schedule'] : '';
					$interval = isset( $event['interval'] ) && is_numeric( $event['interval'] ) ? (int) $event['interval'] : null;
					$events[] = array(
						'id'        => sha1( (string) $timestamp . '|' . $hook . '|' . (string) $instance_key ),
						'timestamp' => (int) $timestamp,
						'hook'      => $hook,
						'schedule'  => $schedule,
						'args'      => $args,
						'interval'  => $interval,
					);
				}
			}
		}

		return $events;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function cron_schedules(): array {
		if ( function_exists( 'wp_get_schedules' ) ) {
			$schedules = \wp_get_schedules();
			if ( is_array( $schedules ) ) {
				return $schedules;
			}
		}

		return array(
			'hourly'     => array(
				'display'  => 'Hourly',
				'interval' => 3600,
			),
			'twicedaily' => array(
				'display'  => 'Twice daily',
				'interval' => 12 * 3600,
			),
			'daily'      => array(
				'display'  => 'Daily',
				'interval' => DAY_IN_SECONDS,
			),
			'weekly'     => array(
				'display'  => 'Weekly',
				'interval' => 7 * DAY_IN_SECONDS,
			),
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 */
	private function recurrence_label( string $schedule, array $schedules, ?int $interval ): string {
		if ( '' === $schedule ) {
			return 'Single';
		}

		$label = $schedules[ $schedule ]['display'] ?? null;
		if ( is_scalar( $label ) && '' !== (string) $label ) {
			return (string) $label;
		}

		return null === $interval ? $schedule : "{$schedule} ({$interval}s)";
	}

	/**
	 * @param list<mixed> $args Args.
	 */
	private function args_preview( array $args ): string {
		if ( array() === $args ) {
			return 'None';
		}

		$json = function_exists( 'wp_json_encode' ) ? \wp_json_encode( $args ) : json_encode( $args );
		$json = is_string( $json ) ? $json : json_encode( $args );
		$json = is_string( $json ) ? $json : '';
		return strlen( $json ) > 80 ? substr( $json, 0, 77 ) . '...' : $json;
	}

	private function timestamp_label( int $timestamp ): string {
		$absolute = gmdate( 'Y-m-d H:i', $timestamp );
		$diff     = $timestamp - $this->now();
		$relative = 0 <= $diff ? 'in ' . $this->duration_words( $diff ) : $this->duration_words( abs( $diff ) ) . ' ago';
		return "{$relative} ({$absolute})";
	}

	private function duration_words( int $seconds ): string {
		if ( $seconds < 60 ) {
			return "{$seconds}s";
		}
		if ( $seconds < 3600 ) {
			return (string) (int) floor( $seconds / 60 ) . 'm';
		}
		if ( $seconds < DAY_IN_SECONDS ) {
			return (string) (int) floor( $seconds / 3600 ) . 'h';
		}

		return (string) (int) floor( $seconds / DAY_IN_SECONDS ) . 'd';
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function run_for_display( array $row ): array {
		$started  = isset( $row['started_at'] ) && is_numeric( $row['started_at'] ) ? (int) $row['started_at'] : 0;
		$finished = isset( $row['finished_at'] ) && is_numeric( $row['finished_at'] ) ? (int) $row['finished_at'] : 0;
		$duration = isset( $row['duration_ms'] ) && is_numeric( $row['duration_ms'] ) ? (int) $row['duration_ms'] : 0;
		$status   = (string) ( $row['status'] ?? '' );
		$error    = (string) ( $row['error'] ?? '' );

		return array(
			'id'              => (int) ( $row['id'] ?? 0 ),
			'startedAt'       => $started,
			'startedAtLabel'  => $this->time_label( $started ),
			'finishedAt'      => $finished,
			'finishedAtLabel' => 0 < $finished ? $this->time_label( $finished ) : '',
			'hook'            => (string) ( $row['hook'] ?? '' ),
			'durationMs'      => $duration,
			'durationLabel'   => 0 < $duration ? "{$duration}ms" : '',
			'status'          => $status,
			'statusLabel'     => $this->status_label( $status ),
			'error'           => $error,
			'errorPreview'    => strlen( $error ) > 80 ? substr( $error, 0, 77 ) . '...' : $error,
		);
	}

	private function status_label( string $status ): string {
		return match ( $status ) {
			self::STATUS_OK => 'OK',
			self::STATUS_TIMEOUT => 'Timeout',
			self::STATUS_ERROR => 'Error',
			default => ucfirst( $status ),
		};
	}
}
