<?php
/**
 * Error Log module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\ErrorLog;

use Onumia\Modules\Attributes\Action;
use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Entries;
use Onumia\Modules\Attributes\EntryField;
use Onumia\Modules\Attributes\Input;
use Onumia\Modules\Attributes\ModuleContract;
use Onumia\Modules\Attributes\ObjectShape;
use Onumia\Modules\Attributes\Setting;
use Onumia\Modules\Contracts\DataSourceShape;
use Onumia\Modules\Contracts\EntryStorage;
use Onumia\Modules\Contracts\PaginationMode;
use Onumia\Modules\Contracts\SettingType;
use Onumia\Modules\Module;

#[ModuleContract( capability: 'manage_options' )]
#[Setting( 'enabled', SettingType::Boolean, default: false )]
#[Setting( 'severityThreshold', SettingType::String, default: 'warning', allowed: array( 'notice', 'warning', 'error' ) )]
#[Setting( 'captureDeprecated', SettingType::Boolean, default: false )]
#[Setting( 'retentionDays', SettingType::Integer, default: 30, min: 1, max: 365 )]
#[Setting( 'ignoredPatterns', SettingType::Array, default: array() )]
final class ErrorLog extends Module {
	private const SEVERITY_ORDER = array(
		'notice'     => 10,
		'deprecated' => 10,
		'warning'    => 20,
		'error'      => 30,
		'fatal'      => 40,
	);

	/** @var callable|null */
	private $previous_exception_handler = null;

	public function boot(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		set_error_handler( array( $this, 'handle_error' ) );
		$this->previous_exception_handler = set_exception_handler( array( $this, 'handle_exception' ) );
		register_shutdown_function( array( $this, 'handle_shutdown' ) );

		$this->add_action( 'doing_it_wrong_run', 'capture_doing_it_wrong', 10, 3 );
		$this->add_action( 'deprecated_function_run', 'capture_deprecated_function', 10, 3 );
		$this->add_action( 'deprecated_argument_run', 'capture_deprecated_argument', 10, 3 );
		$this->add_action( 'deprecated_file_included', 'capture_deprecated_file', 10, 4 );
		$this->add_action( 'deprecated_hook_run', 'capture_deprecated_hook', 10, 4 );
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'errors', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'errors', singular: 'Error', plural: 'Errors', key: 'id', storage: EntryStorage::Table, source: 'errors', table: 'errors' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'lastSeenAt', type: SettingType::Integer, label: 'Last seen timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'lastSeenLabel', type: SettingType::String, label: 'Last seen', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'firstSeenAt', type: SettingType::Integer, label: 'First seen timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'firstSeenLabel', type: SettingType::String, label: 'First seen', create: false, update: false, read_only: true )]
	#[EntryField( name: 'severity', type: SettingType::String, label: 'Severity', filter: true, filter_type: 'option', allowed: array( 'notice', 'warning', 'error', 'fatal', 'deprecated' ), create: false, update: false, read_only: true )]
	#[EntryField( name: 'severityLabel', type: SettingType::String, label: 'Severity', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'messagePreview', type: SettingType::String, label: 'Message', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'message', type: SettingType::String, label: 'Full message', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	#[EntryField( name: 'location', type: SettingType::String, label: 'Location', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'file', type: SettingType::String, label: 'File', create: false, update: false, read_only: true )]
	#[EntryField( name: 'line', type: SettingType::Integer, label: 'Line', create: false, update: false, read_only: true )]
	#[EntryField( name: 'count', type: SettingType::Integer, label: 'Count', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'requestUrl', type: SettingType::String, label: 'Request URL', create: false, update: false, read_only: true )]
	#[EntryField( name: 'userId', type: SettingType::Integer, label: 'User ID', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'stack', type: SettingType::String, label: 'Stack trace', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	public function errors( array $params ): array {
		$rows = array_reverse( $this->table( 'errors' )->export_rows() );
		return $this->paginated_rows( array_map( array( $this, 'error_for_display' ), $rows ), $params );
	}

	/**
	 * @return array{handled:bool}
	 */
	#[Action( 'triggerTestError' )]
	#[Input( 'message', SettingType::String, default: 'Onumia test warning' )]
	#[Input( 'severity', SettingType::String, default: 'warning', allowed: array( 'notice', 'warning', 'error', 'fatal', 'deprecated' ) )]
	public function trigger_test_error( array $input ): array {
		$this->capture(
			$this->allowed_string( $input['severity'] ?? 'warning', array( 'notice', 'warning', 'error', 'fatal', 'deprecated' ), 'warning' ),
			$this->string_value( $input['message'] ?? 'Onumia test warning' ),
			__FILE__,
			__LINE__,
			$this->stack_from_backtrace( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) )
		);

		return array( 'handled' => true );
	}

	public function handle_error( int $errno, string $errstr, string $errfile = '', int $errline = 0 ): bool {
		$this->safe_capture(
			$this->severity_from_error_number( $errno ),
			$errstr,
			$errfile,
			$errline,
			$this->stack_from_backtrace( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) )
		);

		return false;
	}

	public function handle_exception( \Throwable $throwable ): void {
		$this->safe_capture(
			'error',
			$throwable->getMessage(),
			$throwable->getFile(),
			$throwable->getLine(),
			$throwable->getTraceAsString()
		);

		if ( is_callable( $this->previous_exception_handler ) ) {
			( $this->previous_exception_handler )( $throwable );
		}
	}

	public function handle_shutdown(): void {
		$error = error_get_last();
		$this->capture_shutdown_error( is_array( $error ) ? $error : array() );
	}

	/**
	 * @param array<string,mixed> $error Error payload.
	 */
	public function capture_shutdown_error( array $error ): void {
		if ( ! isset( $error['type'], $error['message'] ) ) {
			return;
		}

		if ( ! in_array( (int) $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return;
		}

		$this->safe_capture(
			'fatal',
			(string) $error['message'],
			is_string( $error['file'] ?? null ) ? $error['file'] : '',
			is_numeric( $error['line'] ?? null ) ? (int) $error['line'] : 0,
			null
		);
	}

	public function capture_doing_it_wrong( string $function_name, string $message, string $version ): void {
		$this->safe_capture_deprecated( "_doing_it_wrong {$function_name} since {$version}: {$message}" );
	}

	public function capture_deprecated_function( string $function_name, ?string $replacement, string $version ): void {
		$replacement = null === $replacement || '' === $replacement ? 'no replacement' : $replacement;
		$this->safe_capture_deprecated( "Deprecated function {$function_name} since {$version}; use {$replacement}." );
	}

	public function capture_deprecated_argument( string $function_name, string $message, string $version ): void {
		$this->safe_capture_deprecated( "Deprecated argument in {$function_name} since {$version}: {$message}" );
	}

	public function capture_deprecated_file( string $file, ?string $replacement, string $version, string $message = '' ): void {
		$replacement = null === $replacement || '' === $replacement ? 'no replacement' : $replacement;
		$this->safe_capture_deprecated( "Deprecated file {$file} since {$version}; use {$replacement}. {$message}", $file );
	}

	public function capture_deprecated_hook( string $hook, ?string $replacement, string $version, string $message = '' ): void {
		$replacement = null === $replacement || '' === $replacement ? 'no replacement' : $replacement;
		$this->safe_capture_deprecated( "Deprecated hook {$hook} since {$version}; use {$replacement}. {$message}" );
	}

	private function safe_capture_deprecated( string $message, string $file = '', int $line = 0 ): void {
		if ( true !== $this->setting( 'captureDeprecated' ) ) {
			return;
		}

		$this->safe_capture( 'deprecated', $message, $file, $line, $this->stack_from_backtrace( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) ) );
	}

	private function safe_capture( string $severity, string $message, string $file = '', int $line = 0, ?string $stack = null ): void {
		try {
			$this->capture( $severity, $message, $file, $line, $stack );
		} catch ( \Throwable ) {
			return;
		}
	}

	private function capture( string $severity, string $message, string $file = '', int $line = 0, ?string $stack = null ): void {
		$severity = $this->allowed_string( $severity, array( 'notice', 'warning', 'error', 'fatal', 'deprecated' ), 'warning' );
		$message  = trim( $message );
		if ( '' === $message || ! $this->enabled() || ! $this->passes_threshold( $severity ) || $this->is_ignored( $message ) ) {
			return;
		}

		$table = $this->table( 'errors' );
		$table->purge( $this->retention_days() );

		$now         = $this->now();
		$fingerprint = $this->fingerprint( $severity, $message, $file, $line );
		$existing    = $table->recent( 1, null, array( 'fingerprint' => $fingerprint ) );
		if ( array() !== $existing ) {
			$row = $existing[0];
			if ( isset( $row['id'] ) && is_numeric( $row['id'] ) ) {
				$table->update(
					(int) $row['id'],
					array(
						'last_seen_at' => $now,
						'count'        => max( 1, (int) ( $row['count'] ?? 1 ) ) + 1,
						'stack'        => $stack ?? ( is_string( $row['stack'] ?? null ) ? $row['stack'] : '' ),
						'request_url'  => $this->request_url(),
						'user_id'      => $this->current_user_id(),
					)
				);
			}
			return;
		}

		$table->insert(
			array(
				'first_seen_at' => $now,
				'last_seen_at'  => $now,
				'count'         => 1,
				'fingerprint'   => $fingerprint,
				'severity'      => $severity,
				'message'       => $message,
				'file'          => substr( $file, 0, 255 ),
				'line'          => max( 0, $line ),
				'stack'         => $stack ?? '',
				'request_url'   => $this->request_url(),
				'user_id'       => $this->current_user_id(),
			)
		);
	}

	private function passes_threshold( string $severity ): bool {
		if ( 'deprecated' === $severity ) {
			return true;
		}

		$threshold = $this->allowed_string( $this->setting( 'severityThreshold' ), array( 'notice', 'warning', 'error' ), 'warning' );
		return ( self::SEVERITY_ORDER[ $severity ] ?? 20 ) >= ( self::SEVERITY_ORDER[ $threshold ] ?? 20 );
	}

	private function is_ignored( string $message ): bool {
		foreach ( $this->ignored_patterns() as $pattern ) {
			if ( 1 === @preg_match( $pattern, $message ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<string>
	 */
	private function ignored_patterns(): array {
		$raw = $this->setting( 'ignoredPatterns' );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$patterns = array();
		foreach ( $raw as $item ) {
			$value = is_array( $item ) ? ( $item['value'] ?? '' ) : $item;
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$patterns[] = trim( $value );
			}
		}

		return $patterns;
	}

	private function severity_from_error_number( int $errno ): string {
		return match ( $errno ) {
			E_NOTICE, E_USER_NOTICE => 'notice',
			E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning',
			E_DEPRECATED, E_USER_DEPRECATED => 'deprecated',
			default => 'error',
		};
	}

	private function fingerprint( string $severity, string $message, string $file, int $line ): string {
		return sha1( implode( '|', array( $severity, $file, (string) $line, $this->message_template( $message ) ) ) );
	}

	private function message_template( string $message ): string {
		$message = preg_replace( '/\b\d+\b/', '{n}', $message ) ?? $message;
		return preg_replace( '/0x[0-9a-f]+/i', '{hex}', $message ) ?? $message;
	}

	private function request_url(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_scalar( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		return substr( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 0, 512 );
	}

	private function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * @param list<array<string,mixed>>|false $trace Trace.
	 */
	private function stack_from_backtrace( array|false $trace ): string {
		if ( ! is_array( $trace ) ) {
			return '';
		}

		$lines = array();
		foreach ( array_slice( $trace, 0, 12 ) as $frame ) {
			if ( ! is_array( $frame ) ) {
				continue;
			}
			$function = is_string( $frame['function'] ?? null ) ? $frame['function'] : 'unknown';
			$file     = is_string( $frame['file'] ?? null ) ? $frame['file'] : '';
			$line     = is_numeric( $frame['line'] ?? null ) ? (string) $frame['line'] : '0';
			$lines[]  = '' === $file ? $function : "{$function} {$file}:{$line}";
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function error_for_display( array $row ): array {
		$first   = isset( $row['first_seen_at'] ) && is_numeric( $row['first_seen_at'] ) ? (int) $row['first_seen_at'] : 0;
		$last    = isset( $row['last_seen_at'] ) && is_numeric( $row['last_seen_at'] ) ? (int) $row['last_seen_at'] : 0;
		$file    = (string) ( $row['file'] ?? '' );
		$line    = isset( $row['line'] ) && is_numeric( $row['line'] ) ? (int) $row['line'] : 0;
		$message = (string) ( $row['message'] ?? '' );

		return array(
			'id'             => (string) ( $row['id'] ?? '' ),
			'firstSeenAt'    => $first,
			'firstSeenLabel' => $this->time_label( $first, 'M j, Y H:i' ),
			'lastSeenAt'     => $last,
			'lastSeenLabel'  => $this->time_label( $last, 'M j, Y H:i' ),
			'count'          => max( 1, (int) ( $row['count'] ?? 1 ) ),
			'fingerprint'    => (string) ( $row['fingerprint'] ?? '' ),
			'severity'       => (string) ( $row['severity'] ?? '' ),
			'severityLabel'  => ucfirst( (string) ( $row['severity'] ?? '' ) ),
			'message'        => $message,
			'messagePreview' => mb_strlen( $message ) > 120 ? mb_substr( $message, 0, 117 ) . '...' : $message,
			'file'           => $file,
			'line'           => $line,
			'location'       => '' === $file ? '' : basename( $file ) . ':' . $line,
			'stack'          => (string) ( $row['stack'] ?? '' ),
			'requestUrl'     => (string) ( $row['request_url'] ?? '' ),
			'userId'         => isset( $row['user_id'] ) && is_numeric( $row['user_id'] ) ? (int) $row['user_id'] : 0,
		);
	}


	/**
	 * @param mixed              $value Value.
	 * @param list<string>       $allowed Allowed values.
	 */
	private function allowed_string( mixed $value, array $allowed, string $default ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $default;
	}

	private function string_value( mixed $value, string $default = '' ): string {
		return is_scalar( $value ) ? (string) $value : $default;
	}
}
