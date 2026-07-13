<?php
/**
 * Database Optimizer module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\DatabaseOptimizer;

use Onumia\Modules\Attributes\Action;
use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Entries;
use Onumia\Modules\Attributes\EntryField;
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

#[ModuleContract( capability: 'manage_options' )]
#[Setting(
	'schedule',
	SettingType::Object,
	default: array(
		'enabled'   => false,
		'frequency' => 'weekly',
		'timeOfDay' => '03:00',
	)
)]
#[Setting(
	'tasks',
	SettingType::Object,
	default: array(
		'optimizeTables'    => false,
		'expiredTransients' => false,
		'orphanPostMeta'    => false,
		'orphanUserMeta'    => false,
		'orphanTermMeta'    => false,
		'spamComments'      => false,
		'trashComments'     => false,
		'trashPosts'        => false,
		'autodraftPosts'    => false,
	)
)]
#[Setting(
	'thresholds',
	SettingType::Object,
	default: array(
		'spamCommentsDays'   => 30,
		'trashCommentsDays'  => 30,
		'trashPostsDays'     => 30,
		'autodraftPostsDays' => 7,
	)
)]
final class DatabaseOptimizer extends Module {
	private const HOOK          = 'onumia_database_optimizer_run';
	private const RUN_LOCK      = 'onumia_database_optimizer_run_lock';
	private const DELETE_LIMIT  = 5000;
	private const STATUS_OK     = 'ok';
	private const STATUS_FAILED = 'failed';

	/**
	 * @return array<string,array{setting:string,slug:string,label:string,method:string}>
	 */
	private function task_definitions(): array {
		return array(
			'optimizeTables'    => array(
				'setting' => 'optimizeTables',
				'slug'    => 'optimize_tables',
				'label'   => 'Optimize MyISAM tables',
				'method'  => 'cleanup_optimize_tables',
			),
			'expiredTransients' => array(
				'setting' => 'expiredTransients',
				'slug'    => 'expired_transients',
				'label'   => 'Expired transients',
				'method'  => 'cleanup_expired_transients',
			),
			'orphanPostMeta'    => array(
				'setting' => 'orphanPostMeta',
				'slug'    => 'orphan_post_meta',
				'label'   => 'Orphan post meta',
				'method'  => 'cleanup_orphan_post_meta',
			),
			'orphanUserMeta'    => array(
				'setting' => 'orphanUserMeta',
				'slug'    => 'orphan_user_meta',
				'label'   => 'Orphan user meta',
				'method'  => 'cleanup_orphan_user_meta',
			),
			'orphanTermMeta'    => array(
				'setting' => 'orphanTermMeta',
				'slug'    => 'orphan_term_meta',
				'label'   => 'Orphan term meta',
				'method'  => 'cleanup_orphan_term_meta',
			),
			'spamComments'      => array(
				'setting' => 'spamComments',
				'slug'    => 'spam_comments',
				'label'   => 'Spam comments',
				'method'  => 'cleanup_spam_comments',
			),
			'trashComments'     => array(
				'setting' => 'trashComments',
				'slug'    => 'trash_comments',
				'label'   => 'Trash comments',
				'method'  => 'cleanup_trash_comments',
			),
			'trashPosts'        => array(
				'setting' => 'trashPosts',
				'slug'    => 'trash_posts',
				'label'   => 'Trash posts',
				'method'  => 'cleanup_trash_posts',
			),
			'autodraftPosts'    => array(
				'setting' => 'autodraftPosts',
				'slug'    => 'autodraft_posts',
				'label'   => 'Auto-draft posts',
				'method'  => 'cleanup_autodraft_posts',
			),
		);
	}

	public function boot(): void {
		$this->add_filter( 'cron_schedules', 'cron_schedules', 10, 1 );
		$this->sync_schedule( false );
	}

	public function settings_updated(): void {
		$this->sync_schedule( true );
	}

	/**
	 * @param array<string,mixed> $schedules Existing schedules.
	 * @return array<string,mixed>
	 */
	public function cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * $this->day_in_seconds(),
				'display'  => 'Once monthly',
			);
		}

		return $schedules;
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'runSummary', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'runSummary', singular: 'Task result', plural: 'Task results', key: 'id', storage: EntryStorage::Table, source: 'runSummary', table: 'runs' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'runId', type: SettingType::String, label: 'Run ID', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'startedAt', type: SettingType::Integer, label: 'Started timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'startedLabel', type: SettingType::String, label: 'Started', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'finishedAt', type: SettingType::Integer, label: 'Finished timestamp', create: false, update: false, read_only: true )]
	#[EntryField( name: 'task', type: SettingType::String, label: 'Task slug', filter: true, filter_type: 'option', create: false, update: false, read_only: true )]
	#[EntryField( name: 'taskLabel', type: SettingType::String, label: 'Task', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'itemsProcessed', type: SettingType::Integer, label: 'Processed', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'itemsRemoved', type: SettingType::Integer, label: 'Removed', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'durationMs', type: SettingType::Integer, label: 'Duration ms', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'durationLabel', type: SettingType::String, label: 'Duration', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'status', type: SettingType::String, label: 'Status value', filter: true, filter_type: 'option', allowed: array( 'ok', 'failed' ), create: false, update: false, read_only: true )]
	#[EntryField( name: 'statusLabel', type: SettingType::String, label: 'Status', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'error', type: SettingType::String, label: 'Error', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	public function run_summary( array $params ): array {
		$rows   = array_reverse( $this->table( 'runs' )->export_rows() );
		$latest = null;
		foreach ( $rows as $row ) {
			if ( isset( $row['run_id'] ) && is_string( $row['run_id'] ) && '' !== $row['run_id'] ) {
				$latest = $row['run_id'];
				break;
			}
		}

		if ( null === $latest ) {
			return $this->paginated_rows( array(), $params );
		}

		$rows = array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => isset( $row['run_id'] ) && $row['run_id'] === $latest
			)
		);

		return $this->paginated_rows( array_map( array( $this, 'run_row_for_display' ), $rows ), $params );
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'runs', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'runs', singular: 'Run result', plural: 'Run results', key: 'id', storage: EntryStorage::Table, source: 'runs', table: 'runs' )]
	public function runs( array $params ): array {
		$rows = array_reverse( $this->table( 'runs' )->export_rows() );
		return $this->paginated_rows( array_map( array( $this, 'run_row_for_display' ), $rows ), $params );
	}

	/**
	 * @return array{status:string,runId:string,tasks:int,failed:int,removed:int}
	 */
	#[Action( 'runNow' )]
	public function run_now(): array {
		if ( function_exists( 'get_transient' ) && false !== \get_transient( self::RUN_LOCK ) ) {
			return array(
				'status'  => 'rate_limited',
				'runId'   => '',
				'tasks'   => 0,
				'failed'  => 0,
				'removed' => 0,
			);
		}

		if ( function_exists( 'set_transient' ) ) {
			\set_transient( self::RUN_LOCK, '1', 60 );
		}

		try {
			return $this->run_enabled_tasks();
		} finally {
			if ( function_exists( 'delete_transient' ) ) {
				\delete_transient( self::RUN_LOCK );
			}
		}
	}

	#[WpAction( 'onumia_database_optimizer_run', accepted_args: 0 )]
	public function scheduled_run(): void {
		if ( ! $this->schedule_enabled() ) {
			return;
		}

		$this->run_enabled_tasks();
	}

	/**
	 * @return array{status:string,runId:string,tasks:int,failed:int,removed:int}
	 */
	public function run_enabled_tasks(): array {
		$run_id  = $this->new_run_id();
		$tasks   = 0;
		$failed  = 0;
		$removed = 0;

		foreach ( $this->task_definitions() as $definition ) {
			if ( ! $this->task_enabled( $definition['setting'] ) ) {
				continue;
			}

			++$tasks;
			$result   = $this->run_task( $run_id, $definition );
			$removed += $result['removed'];
			if ( self::STATUS_FAILED === $result['status'] ) {
				++$failed;
			}
		}

		return array(
			'status'  => $failed > 0 ? 'failed' : 'ok',
			'runId'   => $run_id,
			'tasks'   => $tasks,
			'failed'  => $failed,
			'removed' => $removed,
		);
	}

	/**
	 * @param array{setting:string,slug:string,label:string,method:string} $definition Task definition.
	 * @return array{status:string,processed:int,removed:int}
	 */
	private function run_task( string $run_id, array $definition ): array {
		$started  = $this->now();
		$start_ms = microtime( true );
		$status   = self::STATUS_OK;
		$error    = null;

		try {
			$this->maybe_throw_task_error( $definition['slug'] );
			$method = $definition['method'];
			$result = is_callable( array( $this, $method ) )
				? $this->{$method}()
				: array(
					'processed' => 0,
					'removed'   => 0,
				);
		} catch ( \Throwable $throwable ) {
			$status = self::STATUS_FAILED;
			$error  = substr( $throwable->getMessage(), 0, 512 );
			$result = array(
				'processed' => 0,
				'removed'   => 0,
			);
		}

		$finished = $this->now();
		$duration = max( 0, (int) round( ( microtime( true ) - $start_ms ) * 1000 ) );
		$this->table( 'runs' )->insert(
			array(
				'run_id'          => $run_id,
				'started_at'      => $started,
				'finished_at'     => $finished,
				'task'            => $definition['slug'],
				'items_processed' => $result['processed'],
				'items_removed'   => $result['removed'],
				'duration_ms'     => $duration,
				'status'          => $status,
				'error'           => $error,
			)
		);

		return array(
			'status'    => $status,
			'processed' => $result['processed'],
			'removed'   => $result['removed'],
		);
	}

	/**
	 * @return array{processed:int,removed:int}
	 */
	private function cleanup_optimize_tables(): array {
		$database = $this->wpdb();
		if ( null === $database || ! is_callable( array( $database, 'get_results' ) ) || ! is_callable( array( $database, 'query' ) ) ) {
			return array(
				'processed' => 0,
				'removed'   => 0,
			);
		}

		$raw_tables = $this->db_call( 'get_results', 'SHOW TABLE STATUS', ARRAY_A );
		$tables     = is_array( $raw_tables ) ? $raw_tables : array();
		$processed  = 0;
		$removed    = 0;
		$prefix     = $this->wpdb_prefix();

		foreach ( $tables as $table ) {
			if ( ! is_array( $table ) ) {
				continue;
			}

			$name   = is_string( $table['Name'] ?? null ) ? $table['Name'] : '';
			$engine = is_string( $table['Engine'] ?? null ) ? strtolower( $table['Engine'] ) : '';
			if ( '' === $name || ! str_starts_with( $name, $prefix ) ) {
				continue;
			}

			++$processed;
			if ( 'myisam' !== $engine ) {
				continue;
			}

			$this->db_call( 'query', 'OPTIMIZE TABLE `' . str_replace( '`', '``', $name ) . '`' );
			++$removed;
		}

		return array(
			'processed' => $processed,
			'removed'   => $removed,
		);
	}

	/**
	 * @return array{processed:int,removed:int}
	 */
	private function cleanup_expired_transients(): array {
		$database = $this->wpdb();
		if ( null === $database || ! is_callable( array( $database, 'get_col' ) ) || ! is_callable( array( $database, 'query' ) ) ) {
			return array(
				'processed' => 0,
				'removed'   => 0,
			);
		}

		$options_table = $this->wpdb_table( 'options' );
		$names         = $this->db_call(
			'get_col',
			$this->prepare_sql(
				"SELECT option_name FROM {$options_table} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT %d",
				'_transient_timeout_%',
				$this->now(),
				self::DELETE_LIMIT
			)
		);

		$processed = 0;
		$removed   = 0;
		foreach ( is_array( $names ) ? $names : array() as $name ) {
			if ( ! is_string( $name ) || ! str_starts_with( $name, '_transient_timeout_' ) ) {
				continue;
			}

			++$processed;
			$key      = substr( $name, strlen( '_transient_timeout_' ) );
			$removed += $this->delete_option_row( '_transient_timeout_' . $key );
			$removed += $this->delete_option_row( '_transient_' . $key );
		}

		return array(
			'processed' => $processed,
			'removed'   => $removed,
		);
	}

	/**
	 * @return array{processed:int,removed:int}
	 */
	private function cleanup_orphan_post_meta(): array {
		return $this->cleanup_orphan_meta( 'postmeta', 'posts', 'post_id', 'ID', 'onumia_database_optimizer_post_meta', 'onumia_posts' );
	}

	/**
	 * @return array{processed:int,removed:int}
	 */
	private function cleanup_orphan_user_meta(): array {
		return $this->cleanup_orphan_meta( 'usermeta', 'users', 'user_id', 'ID', 'onumia_database_optimizer_user_meta', 'onumia_database_optimizer_users' );
	}

	/**
	 * @return array{processed:int,removed:int}
	 */
	private function cleanup_orphan_term_meta(): array {
		return $this->cleanup_orphan_meta( 'termmeta', 'terms', 'term_id', 'term_id', 'onumia_database_optimizer_term_meta', 'onumia_database_optimizer_terms' );
	}

	/**
	 * @return array{processed:int,removed:int}
	 */
	private function cleanup_spam_comments(): array {
		return $this->cleanup_comments( 'spam', $this->threshold_days( 'spamCommentsDays' ) );
	}

	/**
	 * @return array{processed:int,removed:int}
	 */
	private function cleanup_trash_comments(): array {
		return $this->cleanup_comments( 'trash', $this->threshold_days( 'trashCommentsDays' ) );
	}

	/**
	 * @return array{processed:int,removed:int}
	 */
	private function cleanup_trash_posts(): array {
		return $this->cleanup_posts( 'trash', $this->threshold_days( 'trashPostsDays' ) );
	}

	/**
	 * @return array{processed:int,removed:int}
	 */
	private function cleanup_autodraft_posts(): array {
		return $this->cleanup_posts( 'auto-draft', $this->threshold_days( 'autodraftPostsDays' ) );
	}

	/**
	 * @return array{processed:int,removed:int}
	 */
	private function cleanup_orphan_meta( string $meta_table_key, string $owner_table_key, string $owner_column, string $owner_id_column, string $fallback_meta_key, string $fallback_owner_key ): array {
		$database = $this->wpdb();
		unset( $fallback_meta_key, $fallback_owner_key );
		if ( null === $database || ! is_callable( array( $database, 'get_col' ) ) || ! is_callable( array( $database, 'query' ) ) ) {
			return array(
				'processed' => 0,
				'removed'   => 0,
			);
		}

		$meta_table  = $this->wpdb_table( $meta_table_key );
		$owner_table = $this->wpdb_table( $owner_table_key );
		$ids         = $this->db_call(
			'get_col',
			$this->prepare_sql(
				"SELECT meta.meta_id FROM {$meta_table} meta LEFT JOIN {$owner_table} owner ON owner.{$owner_id_column} = meta.{$owner_column} WHERE owner.{$owner_id_column} IS NULL LIMIT %d",
				self::DELETE_LIMIT
			)
		);

		$processed = 0;
		$removed   = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( ! is_numeric( $id ) ) {
				continue;
			}

			++$processed;
			$removed += $this->db_deleted_rows(
				"DELETE FROM {$meta_table} WHERE meta_id = %d LIMIT 1",
				(int) $id
			);
		}

		return array(
			'processed' => $processed,
			'removed'   => $removed,
		);
	}

	/**
	 * @return array{processed:int,removed:int}
	 */
	private function cleanup_comments( string $status, int $days ): array {
		$database = $this->wpdb();
		if ( null === $database || ! is_callable( array( $database, 'get_col' ) ) || ! is_callable( array( $database, 'query' ) ) ) {
			return array(
				'processed' => 0,
				'removed'   => 0,
			);
		}

		$comments_table = $this->wpdb_table( 'comments' );
		$cutoff         = $this->mysql_cutoff( $days );
		$ids            = $this->db_call(
			'get_col',
			$this->prepare_sql(
				"SELECT comment_ID FROM {$comments_table} WHERE comment_approved = %s AND comment_date_gmt < %s LIMIT %d",
				$status,
				$cutoff,
				self::DELETE_LIMIT
			)
		);

		$processed = 0;
		$removed   = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( ! is_numeric( $id ) ) {
				continue;
			}

			++$processed;
			if ( function_exists( 'wp_delete_comment' ) ) {
				$result   = \wp_delete_comment( (int) $id, true );
				$removed += false === $result ? 0 : 1;
				continue;
			}

			$removed += $this->db_deleted_rows(
				"DELETE FROM {$comments_table} WHERE comment_ID = %d LIMIT 1",
				(int) $id
			);
		}

		return array(
			'processed' => $processed,
			'removed'   => $removed,
		);
	}

	/**
	 * @return array{processed:int,removed:int}
	 */
	private function cleanup_posts( string $status, int $days ): array {
		$database = $this->wpdb();
		if ( null === $database || ! is_callable( array( $database, 'get_col' ) ) ) {
			return array(
				'processed' => 0,
				'removed'   => 0,
			);
		}

		$posts_table = $this->wpdb_table( 'posts' );
		$cutoff      = $this->mysql_cutoff( $days );
		$ids         = $this->db_call(
			'get_col',
			$this->prepare_sql(
				"SELECT ID FROM {$posts_table} WHERE post_status = %s AND post_modified_gmt < %s LIMIT %d",
				$status,
				$cutoff,
				self::DELETE_LIMIT
			)
		);

		$processed = 0;
		$removed   = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( ! is_numeric( $id ) ) {
				continue;
			}

			++$processed;
			if ( function_exists( 'wp_delete_post' ) ) {
				$result   = \wp_delete_post( (int) $id, true );
				$removed += false === $result ? 0 : 1;
				continue;
			}

			$removed += $this->db_deleted_rows(
				"DELETE FROM {$posts_table} WHERE ID = %d LIMIT 1",
				(int) $id
			);
		}

		return array(
			'processed' => $processed,
			'removed'   => $removed,
		);
	}

	private function maybe_throw_task_error( string $task ): void {
		if ( function_exists( 'apply_filters' ) ) {
			$error = \apply_filters( 'onumia_database_optimizer_task_error', null, $task );
			if ( $error instanceof \Throwable ) {
				throw $error;
			}
			if ( is_string( $error ) && '' !== $error ) {
				throw new \RuntimeException( $error );
			}
		}
	}

	private function task_enabled( string $setting ): bool {
		$tasks = $this->setting( 'tasks' );
		return is_array( $tasks ) && true === ( $tasks[ $setting ] ?? false );
	}

	private function sync_schedule( bool $force ): void {
		if ( ! $this->schedule_enabled() ) {
			$this->clear_schedule();
			return;
		}

		if ( $force ) {
			$this->clear_schedule();
		} elseif ( false !== $this->next_scheduled( self::HOOK ) ) {
			return;
		}

		if ( function_exists( 'wp_schedule_event' ) ) {
			\wp_schedule_event( $this->next_run_timestamp(), $this->schedule_frequency(), self::HOOK );
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

	private function schedule_enabled(): bool {
		$schedule = $this->setting( 'schedule' );
		return is_array( $schedule ) && true === ( $schedule['enabled'] ?? false );
	}

	private function schedule_frequency(): string {
		$schedule = $this->setting( 'schedule' );
		$value    = is_array( $schedule ) && is_string( $schedule['frequency'] ?? null ) ? $schedule['frequency'] : 'weekly';
		return in_array( $value, array( 'daily', 'weekly', 'monthly' ), true ) ? $value : 'weekly';
	}

	private function next_run_timestamp(): int {
		$schedule = $this->setting( 'schedule' );
		$time     = is_array( $schedule ) && is_string( $schedule['timeOfDay'] ?? null ) ? $schedule['timeOfDay'] : '03:00';
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches ) ) {
			$time = '03:00';
		}

		$now       = $this->now();
		$candidate = strtotime( gmdate( 'Y-m-d', $now ) . ' ' . $time . ' UTC' );
		if ( false === $candidate || $candidate <= $now ) {
			$candidate = strtotime( '+1 day', false === $candidate ? $now : $candidate );
		}

		return false === $candidate ? $now + $this->day_in_seconds() : $candidate;
	}

	private function threshold_days( string $key ): int {
		$thresholds = $this->setting( 'thresholds' );
		$value      = is_array( $thresholds ) ? ( $thresholds[ $key ] ?? null ) : null;
		return is_numeric( $value ) ? max( 1, min( 365, (int) $value ) ) : 30;
	}

	private function new_run_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			$uuid = \wp_generate_uuid4();
			if ( is_string( $uuid ) && '' !== $uuid ) {
				return substr( $uuid, 0, 40 );
			}
		}

		return substr( sha1( uniqid( 'onumia-database-optimizer-', true ) ), 0, 40 );
	}

	private function delete_option_row( string $option ): int {
		if ( function_exists( 'delete_option' ) ) {
			return \delete_option( $option ) ? 1 : 0;
		}

		$options_table = $this->wpdb_table( 'options' );
		return $this->db_deleted_rows(
			"DELETE FROM {$options_table} WHERE option_name = %s LIMIT 1",
			$option
		);
	}

	private function db_deleted_rows( string $query, mixed ...$args ): int {
		$result = $this->db_call( 'query', $this->prepare_sql( $query, ...$args ) );
		return is_int( $result ) ? max( 0, $result ) : 0;
	}

	private function prepare_sql( string $query, mixed ...$args ): string {
		$prepared = $this->db_call( 'prepare', $query, ...$args );
		if ( is_string( $prepared ) ) {
			return $prepared;
		}

		return $query;
	}

	private function db_call( string $method, mixed ...$args ): mixed {
		$database = $this->wpdb();
		if ( null === $database || ! is_callable( array( $database, $method ) ) ) {
			return null;
		}

		return call_user_func_array( array( $database, $method ), $args );
	}

	private function wpdb(): ?object {
		global $wpdb;
		return is_object( $wpdb ) ? $wpdb : null;
	}

	private function wpdb_prefix(): string {
		$database = $this->wpdb();
		$prefix   = null === $database ? null : ( $database->prefix ?? null );
		return is_string( $prefix ) && '' !== $prefix ? $prefix : 'wp_';
	}

	private function wpdb_table( string $property ): string {
		$database = $this->wpdb();
		$value    = null === $database ? null : ( $database->{$property} ?? null );
		return is_string( $value ) && '' !== $value ? $value : $this->wpdb_prefix() . $property;
	}

	private function mysql_cutoff( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', $this->now() - ( $days * $this->day_in_seconds() ) );
	}

	private function day_in_seconds(): int {
		return defined( 'DAY_IN_SECONDS' ) && is_numeric( DAY_IN_SECONDS ) ? (int) DAY_IN_SECONDS : 86400;
	}

	private function task_label( string $task ): string {
		foreach ( $this->task_definitions() as $definition ) {
			if ( $definition['slug'] === $task ) {
				return $definition['label'];
			}
		}

		return ucwords( str_replace( '_', ' ', $task ) );
	}

	private function status_label( string $status ): string {
		return self::STATUS_FAILED === $status ? 'Failed' : 'OK';
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function run_row_for_display( array $row ): array {
		$started  = isset( $row['started_at'] ) && is_numeric( $row['started_at'] ) ? (int) $row['started_at'] : 0;
		$finished = isset( $row['finished_at'] ) && is_numeric( $row['finished_at'] ) ? (int) $row['finished_at'] : 0;
		$duration = isset( $row['duration_ms'] ) && is_numeric( $row['duration_ms'] ) ? (int) $row['duration_ms'] : 0;
		$task     = is_string( $row['task'] ?? null ) ? $row['task'] : '';
		$status   = is_string( $row['status'] ?? null ) ? $row['status'] : self::STATUS_OK;

		return array(
			'id'             => (string) ( $row['id'] ?? '' ),
			'runId'          => (string) ( $row['run_id'] ?? '' ),
			'startedAt'      => $started,
			'startedLabel'   => $this->time_label( $started, 'M j, Y H:i' ),
			'finishedAt'     => $finished,
			'finishedLabel'  => $this->time_label( $finished, 'M j, Y H:i' ),
			'task'           => $task,
			'taskLabel'      => $this->task_label( $task ),
			'itemsProcessed' => isset( $row['items_processed'] ) && is_numeric( $row['items_processed'] ) ? (int) $row['items_processed'] : 0,
			'itemsRemoved'   => isset( $row['items_removed'] ) && is_numeric( $row['items_removed'] ) ? (int) $row['items_removed'] : 0,
			'durationMs'     => $duration,
			'durationLabel'  => (string) $duration . 'ms',
			'status'         => $status,
			'statusLabel'    => $this->status_label( $status ),
			'error'          => (string) ( $row['error'] ?? '' ),
		);
	}
}
