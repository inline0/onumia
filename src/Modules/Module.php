<?php

/**
 * Base module class.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Core\Errors;
use Onumia\Data\ModuleTableStore;
use Onumia\Data\TableHandle;
use Onumia\PublicApi\Filters;

/**
 * Provides the base runtime API for Onumia module implementations.
 *
 * Extend this class from a module `boot.php` file when implementing bundled,
 * Pro, or custom module behavior. It gives module code typed access to saved
 * settings, module-owned tables, privacy helpers, and WordPress hook
 * registration without exposing internal loader services.
 *
 * A module instance is created by the Onumia runtime after its PHP contract
 * has been parsed. Module code should keep persistent state in settings or
 * module-owned tables instead of storing request-specific state on the object.
 *
 * @api
 * @since 0.1.0
 * @category Modules
 * @order 10
 */
abstract class Module {
	private readonly ModuleDefinition $definition;
	private readonly ModuleSettingsRepository $settings_repository;
	private readonly HookRegistrar $hook_registrar;
	private ?ModuleTableStore $table_store = null;
	/**
	 * @var array<string,TableHandle>
	 */
	private array $table_handles = array();

	public function __construct(
		ModuleDefinition $definition,
		ModuleSettingsRepository $settings_repository,
		?HookRegistrar $hook_registrar = null,
	) {
		$this->definition          = $definition;
		$this->settings_repository = $settings_repository;
		$this->hook_registrar      = $hook_registrar ?? new HookRegistrar();
	}

	public function boot(): void {}

	public function settings_updated(): void {}

	protected function definition(): ModuleDefinition {
		return $this->definition;
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function settings(): array {
		return $this->settings_repository->settings( $this->definition );
	}

	protected function setting( string $key ): mixed {
		return $this->settings()[ $key ] ?? null;
	}

	protected function bool_setting( string $key ): bool {
		$value = $this->setting( $key );
		if ( ! is_bool( $value ) ) {
			throw Errors::invariant( "Setting {$key} must be boolean." );
		}

		return $value;
	}

	protected function string_setting( string $key ): string {
		$value = $this->setting( $key );
		if ( ! is_string( $value ) ) {
			throw Errors::invariant( "Setting {$key} must be string." );
		}

		return $value;
	}

	protected function int_setting( string $key ): int {
		$value = $this->setting( $key );
		if ( ! is_int( $value ) ) {
			throw Errors::invariant( "Setting {$key} must be integer." );
		}

		return $value;
	}

	protected function float_setting( string $key ): float {
		$value = $this->setting( $key );
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			throw Errors::invariant( "Setting {$key} must be number." );
		}

		return (float) $value;
	}

	protected function enabled( string $setting = 'enabled' ): bool {
		return true === $this->setting( $setting );
	}

	protected function now(): int {
		if ( function_exists( 'current_time' ) ) {
			$now = \current_time( 'timestamp' );
			if ( is_numeric( $now ) ) {
				return (int) $now;
			}
		}

		// @codeCoverageIgnoreStart
		return time();
		// @codeCoverageIgnoreEnd
	}

	protected function retention_days( string $setting = 'retentionDays', int $fallback = 30 ): int {
		$definition = $this->definition->contract()->settings()[ $setting ] ?? array();
		$default    = $this->setting_int_definition_value( $definition, 'default', $fallback );
		$min        = $this->setting_int_definition_value( $definition, 'min', 1 );
		$max        = $this->setting_int_definition_value( $definition, 'max', 365 );
		$value      = $this->setting( $setting );
		$days       = is_int( $value ) ? $value : $default;

		return max( $min, min( $max, $days ) );
	}

	/**
	 * @param callable(string):mixed|null $normalizer Optional item normalizer.
	 * @return list<string>
	 */
	protected function string_list( mixed $value, ?callable $normalizer = null, bool $unique = true, bool $allow_scalar = false, bool $trim = true ): array {
		if ( ! is_array( $value ) ) {
			if ( ! $allow_scalar ) {
				return array();
			}

			$value = array( $value );
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = (string) $item;
			if ( $trim ) {
				$item = trim( $item );
			}

			$normalized = null === $normalizer ? $item : $normalizer( $item );
			if ( ! is_scalar( $normalized ) ) {
				continue;
			}

			$item = (string) $normalized;
			if ( $trim ) {
				$item = trim( $item );
			}

			if ( '' !== $item ) {
				$items[] = $item;
			}
		}

		return $unique ? array_values( array_unique( $items ) ) : $items;
	}

	protected function table( string $name ): TableHandle {
		$this->table_store ??= new ModuleTableStore();
		$this->table_handles[ $name ] ??= $this->table_store->table( $this->definition, $name );

		return $this->table_handles[ $name ];
	}

	/**
	 * @param array<string,mixed> $definition Setting definition.
	 */
	private function setting_int_definition_value( array $definition, string $key, int $fallback ): int {
		$value = $definition[ $key ] ?? null;
		return is_int( $value ) ? $value : $fallback;
	}

	protected function ip_hash( ?string $ip = null ): string {
		if ( null === $ip ) {
			$remote_addr = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_UNSAFE_RAW );
			$ip          = is_string( $remote_addr ) ? $remote_addr : '';
		}

		$handling = Filters::table_ip_handling( 'hash', $ip, $this->definition );
		if ( 'raw' === $handling ) {
			return $ip;
		}
		if ( 'redact' === $handling || '' === $ip ) {
			return '';
		}

		$secret = function_exists( 'get_option' ) ? \get_option( 'onumia_tables_ip_hash_secret', '' ) : '';
		if ( ! is_string( $secret ) || '' === $secret ) {
			$secret = bin2hex( random_bytes( 16 ) );
			if ( function_exists( 'update_option' ) ) {
				\update_option( 'onumia_tables_ip_hash_secret', $secret, false );
			}
		}

		$salt = function_exists( 'wp_salt' ) ? \wp_salt( 'auth' ) : '';
		return hash( 'sha256', $secret . '|' . $salt . '|' . $ip );
	}

	/**
	 * @return array<mixed>
	 */
	protected function array_setting( string $key ): array {
		$value = $this->setting( $key );
		if ( ! is_array( $value ) ) {
			throw Errors::invariant( "Setting {$key} must be array." );
		}

		return $value;
	}

	/**
	 * Format timestamps stored by bundled modules.
	 *
	 * Module timestamps are written with WordPress' local `current_time( 'timestamp' )`
	 * convention, so rendering through `wp_date()` would apply the site timezone a
	 * second time.
	 */
	protected function time_label( int $timestamp, string $format = 'Y-m-d H:i' ): string {
		return $timestamp > 0 ? gmdate( $format, $timestamp ) : '';
	}

	/**
	 * @param list<array<string,mixed>> $rows Rows.
	 * @param array<string,mixed>       $params Data-source params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	protected function paginated_rows( array $rows, array $params ): array {
		$query   = is_array( $params['query'] ?? null ) ? $params['query'] : array();
		$search  = is_string( $query['search'] ?? null ) ? strtolower( $query['search'] ) : '';
		$filters = is_array( $query['filters'] ?? null ) ? $query['filters'] : array();

		if ( '' !== $search ) {
			$rows = array_values(
				array_filter(
					$rows,
					fn( array $row ): bool => str_contains( strtolower( implode( ' ', $this->scalar_row_values( $row ) ) ), $search )
				)
			);
		}

		foreach ( $filters as $filter ) {
			$filter = $this->normalized_table_filter( $filter );
			if ( null === $filter ) {
				continue;
			}

			$rows = array_values(
				array_filter(
					$rows,
					fn( array $row ): bool => $this->row_matches_table_filter( $row, $filter )
				)
			);
		}

		$sorting = is_array( $query['sorting'] ?? null ) ? $query['sorting'] : array();
		foreach ( array_reverse( $sorting ) as $sort ) {
			if ( ! is_array( $sort ) || ! is_string( $sort['columnId'] ?? null ) ) {
				continue;
			}

			$column    = $sort['columnId'];
			$direction = is_string( $sort['direction'] ?? null ) ? $sort['direction'] : 'asc';
			usort(
				$rows,
				function ( array $left, array $right ) use ( $column, $direction ): int {
					$left_value  = $this->row_value( $left, $column );
					$right_value = $this->row_value( $right, $column );
					$result      = is_numeric( $left_value ) && is_numeric( $right_value )
						? (float) $left_value <=> (float) $right_value
						: strnatcmp( $this->table_filter_string( $left_value ), $this->table_filter_string( $right_value ) );

					return 'desc' === $direction ? -$result : $result;
				}
			);
		}

		$total = count( $rows );
		$page  = is_array( $query['page'] ?? null ) ? $query['page'] : array();
		$index = isset( $page['index'] ) && is_numeric( $page['index'] )
			? max( 0, (int) $page['index'] )
			: ( isset( $params['page'] ) && is_numeric( $params['page'] ) ? max( 0, (int) $params['page'] ) : 0 );
		$size  = isset( $page['size'] ) && is_numeric( $page['size'] )
			? max( 1, min( 100, (int) $page['size'] ) )
			: ( isset( $params['pageSize'] ) && is_numeric( $params['pageSize'] ) ? max( 1, min( 100, (int) $params['pageSize'] ) ) : 10 );

		return array(
			'items'    => array_slice( $rows, $index * $size, $size ),
			'total'    => $total,
			'page'     => $index,
			'pageSize' => $size,
		);
	}

	/**
	 * @return array{columnId:string,operator:string,type:string,values:list<mixed>}|null
	 */
	private function normalized_table_filter( mixed $filter ): ?array {
		if ( ! is_array( $filter ) || ! is_string( $filter['columnId'] ?? null ) ) {
			return null;
		}

		$operator = is_string( $filter['operator'] ?? null ) ? $filter['operator'] : 'contains';
		$type     = is_string( $filter['type'] ?? null ) ? $filter['type'] : $this->filter_type_for_operator( $operator );

		return array(
			'columnId' => $filter['columnId'],
			'operator' => $operator,
			'type'     => $type,
			'values'   => is_array( $filter['values'] ?? null ) ? array_values( $filter['values'] ) : array(),
		);
	}

	/**
	 * @param array<string,mixed>                                            $row Row.
	 * @param array{columnId:string,operator:string,type:string,values:list<mixed>} $filter Filter.
	 */
	private function row_matches_table_filter( array $row, array $filter ): bool {
		$value    = $this->row_value( $row, $filter['columnId'] );
		$operator = $filter['operator'];
		$type     = $filter['type'];
		$values   = $filter['values'];

		return match ( $type ) {
			'boolean' => 'isTrue' === $operator ? true === $value : false === $value,
			'number' => $this->matches_number_filter( $value, $operator, $values ),
			'option' => $this->matches_option_filter( $value, $operator, $values ),
			'multiOption' => $this->matches_multi_option_filter( $value, $operator, $values ),
			default => $this->matches_text_filter( $value, $operator, $values ),
		};
	}

	private function filter_type_for_operator( string $operator ): string {
		return match ( $operator ) {
			'isTrue', 'isFalse' => 'boolean',
			'equals', 'notEquals', 'greaterThan', 'greaterThanOrEqual', 'lessThan', 'lessThanOrEqual', 'between' => 'number',
			'includesAnyOf', 'includesAllOf', 'excludesAnyOf', 'excludesAllOf' => 'multiOption',
			'isAnyOf', 'isNoneOf' => 'option',
			default => 'text',
		};
	}

	/**
	 * @param mixed       $value Value.
	 * @param list<mixed> $values Values.
	 */
	private function matches_text_filter( mixed $value, string $operator, array $values ): bool {
		$text     = strtolower( $this->table_filter_string( $value ) );
		$expected = strtolower( $this->table_filter_string( $values[0] ?? null ) );
		if ( '' === $expected ) {
			return true;
		}

		return match ( $operator ) {
			'notContains' => ! str_contains( $text, $expected ),
			'is' => $text === $expected,
			'isNot' => $text !== $expected,
			'startsWith' => str_starts_with( $text, $expected ),
			'endsWith' => str_ends_with( $text, $expected ),
			default => str_contains( $text, $expected ),
		};
	}

	/**
	 * @param mixed       $value Value.
	 * @param list<mixed> $values Values.
	 */
	private function matches_number_filter( mixed $value, string $operator, array $values ): bool {
		if ( ! is_numeric( $value ) ) {
			return false;
		}

		$actual = (float) $value;
		$first  = is_numeric( $values[0] ?? null ) ? (float) $values[0] : null;
		$second = is_numeric( $values[1] ?? null ) ? (float) $values[1] : null;

		if ( 'between' === $operator ) {
			if ( null === $first || null === $second ) {
				return true;
			}

			return $actual >= min( $first, $second ) && $actual <= max( $first, $second );
		}

		if ( null === $first ) {
			return true;
		}

		return match ( $operator ) {
			'notEquals' => $actual !== $first,
			'greaterThan' => $actual > $first,
			'greaterThanOrEqual' => $actual >= $first,
			'lessThan' => $actual < $first,
			'lessThanOrEqual' => $actual <= $first,
			default => $actual === $first,
		};
	}

	/**
	 * @param mixed       $value Value.
	 * @param list<mixed> $values Values.
	 */
	private function matches_option_filter( mixed $value, string $operator, array $values ): bool {
		$has_value = null !== $value && '' !== $value;
		if ( 'isEmpty' === $operator ) {
			return ! $has_value;
		}
		if ( 'isNotEmpty' === $operator ) {
			return $has_value;
		}

		$expected = $this->table_filter_strings( $values );
		if ( array() === $expected ) {
			return true;
		}

		$actual = $this->table_filter_string( $value );
		if ( 'isNot' === $operator || 'isNoneOf' === $operator ) {
			return ! in_array( $actual, $expected, true );
		}

		return in_array( $actual, $expected, true );
	}

	/**
	 * @param mixed       $value Value.
	 * @param list<mixed> $values Values.
	 */
	private function matches_multi_option_filter( mixed $value, string $operator, array $values ): bool {
		$actual = is_array( $value )
			? $this->table_filter_strings( array_values( $value ) )
			: array_values( array_filter( array_map( 'trim', explode( ',', $this->table_filter_string( $value ) ) ) ) );

		if ( 'isEmpty' === $operator ) {
			return array() === $actual;
		}
		if ( 'isNotEmpty' === $operator ) {
			return array() !== $actual;
		}

		$expected = $this->table_filter_strings( $values );
		if ( array() === $expected ) {
			return true;
		}

		return match ( $operator ) {
			'includesAllOf' => array() === array_diff( $expected, $actual ),
			'excludesAnyOf' => array() === array_intersect( $expected, $actual ),
			'excludesAllOf' => array() !== array_diff( $expected, $actual ),
			default => array() !== array_intersect( $expected, $actual ),
		};
	}

	private function table_filter_string( mixed $value ): string {
		if ( null === $value ) {
			return '';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param list<mixed> $values Values.
	 * @return list<string>
	 */
	private function table_filter_strings( array $values ): array {
		return array_map( array( $this, 'table_filter_string' ), $values );
	}

	/**
	 * @param array<string,mixed> $row Row.
	 */
	private function row_value( array $row, string $path ): mixed {
		if ( array_key_exists( $path, $row ) ) {
			return $row[ $path ];
		}

		$value = $row;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return list<string>
	 */
	private function scalar_row_values( array $row ): array {
		$values = array();
		foreach ( $row as $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$values[] = (string) $value;
			}
		}

		return $values;
	}

	protected function add_action( string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$this->hook_registrar->add_action( $hook, $this->normalize_callback( $callback ), $priority, $accepted_args );
	}

	protected function add_filter( string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$this->hook_registrar->add_filter( $hook, $this->normalize_callback( $callback ), $priority, $accepted_args );
	}

	private function normalize_callback( callable|string $callback ): callable {
		if ( is_string( $callback ) && method_exists( $this, $callback ) ) {
			$callback = array( $this, $callback );
		}

		if ( is_callable( $callback ) ) {
			return $callback;
		}

		throw new \InvalidArgumentException( 'Module hook callback must be callable.' );
	}
}
