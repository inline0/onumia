<?php
/**
 * 404 Log module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\FourOhFourLog;

use Onumia\Core\Errors;
use Onumia\Core\Plugin;
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
use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleLoader;
use Onumia\Modules\ModuleSettingsRepository;

#[ModuleContract( capability: 'manage_options' )]
#[Setting( 'enabled', SettingType::Boolean, default: false )]
#[Setting( 'retentionDays', SettingType::Integer, default: 30, min: 1, max: 365 )]
#[Setting( 'ignoredPatterns', SettingType::Array, default: array( array( 'value' => '/\\.well-known/' ), array( 'value' => '/wp-content/uploads/.*\\.(jpg|png|gif|webp)$/i' ) ) )]
final class FourOhFourLog extends Module {
	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'fourOhFourHits', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'fourOhFourLogHits', singular: 'Broken link', plural: 'Broken links', key: 'id', storage: EntryStorage::Table, source: 'fourOhFourHits', table: 'hits' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'urlFingerprint', type: SettingType::String, label: 'URL fingerprint', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'url', type: SettingType::String, label: 'URL', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'urlPreview', type: SettingType::String, label: 'URL', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'count', type: SettingType::Integer, label: 'Count', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'firstSeenAt', type: SettingType::Integer, label: 'First seen timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'firstSeenLabel', type: SettingType::String, label: 'First seen', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'lastSeenAt', type: SettingType::Integer, label: 'Last seen timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'lastSeenLabel', type: SettingType::String, label: 'Last seen', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'lastReferrer', type: SettingType::String, label: 'Last referrer', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'lastReferrerPreview', type: SettingType::String, label: 'Last referrer', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'lastUserAgent', type: SettingType::String, label: 'Last user agent', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	public function hits( array $params ): array {
		$rows = array_reverse( $this->table( 'hits' )->export_rows() );
		return $this->paginated_rows( array_map( array( $this, 'hit_for_display' ), $rows ), $params );
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{created:int,redirects:list<array{id:string,fromPattern:string,status:int}>}
	 */
	#[Action( 'createRedirectFromSelected' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function create_redirect_from_selected( array $input ): array {
		$ids       = is_array( $input['ids'] ?? null ) ? $input['ids'] : array();
		$redirects = array();

		foreach ( $ids as $id ) {
			if ( ! is_numeric( $id ) ) {
				continue;
			}

			$row = $this->table( 'hits' )->find( (int) $id );
			if ( null === $row ) {
				continue;
			}

			$url = is_string( $row['url'] ?? null ) ? $row['url'] : '';
			if ( '' !== $url ) {
				$from_pattern = $this->redirect_from_pattern( $url );
				$redirects[] = array(
					'id'          => $this->redirect_rule_id( $from_pattern ),
					'fromPattern' => $from_pattern,
					'status'      => 301,
				);
			}
		}

		if ( array() !== $redirects ) {
			$this->create_redirect_drafts( $redirects );
		}

		return array(
			'created'   => count( $redirects ),
			'redirects' => $redirects,
		);
	}

	#[WpAction( 'template_redirect', priority: 999, accepted_args: 0 )]
	public function record_template_redirect(): void {
		if ( ! $this->enabled() || ! function_exists( 'is_404' ) || ! \is_404() ) {
			return;
		}

		$this->record_current_request();
	}

	#[WpAction( 'onumia_tables_cleanup', priority: 10, accepted_args: 0 )]
	public function prune_runtime_tables(): void {
		$this->table( 'hits' )->purge( $this->retention_days() );
	}

	public function record_current_request(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$url = $this->current_url();
		if ( '' === $url || $this->is_ignored( $url ) ) {
			return;
		}

		$table = $this->table( 'hits' );
		$table->purge( $this->retention_days() );

		$now         = $this->now();
		$fingerprint = sha1( $this->normalized_url( $url ) );
		$existing    = $table->recent( 1, null, array( 'url_fingerprint' => $fingerprint ) );
		if ( array() !== $existing ) {
			$row = $existing[0];
			if ( isset( $row['id'] ) && is_numeric( $row['id'] ) ) {
				$table->update(
					(int) $row['id'],
					array(
						'count'           => max( 1, (int) ( $row['count'] ?? 1 ) ) + 1,
						'occurred_at'     => $now,
						'last_seen_at'    => $now,
						'last_referrer'   => $this->current_referrer(),
						'last_user_agent' => $this->current_user_agent(),
					)
				);
			}
			return;
		}

		$table->insert(
			array(
				'url_fingerprint' => $fingerprint,
				'url'             => substr( $url, 0, 1024 ),
				'count'           => 1,
				'occurred_at'     => $now,
				'first_seen_at'   => $now,
				'last_seen_at'    => $now,
				'last_referrer'   => $this->current_referrer(),
				'last_user_agent' => $this->current_user_agent(),
			)
		);
	}

	/**
	 * @param list<array{id:string,fromPattern:string,status:int}> $drafts Drafts.
	 */
	private function create_redirect_drafts( array $drafts ): void {
		$redirects = $this->redirects_module();
		( new ModuleSettingsRepository() )->update_settings_with(
			$redirects,
			function ( array $settings ) use ( $drafts ): array {
				$rules = is_array( $settings['rules'] ?? null ) ? $settings['rules'] : array();
				$by_id = array();
				foreach ( $rules as $rule ) {
					if ( ! is_array( $rule ) || ! is_string( $rule['id'] ?? null ) || '' === $rule['id'] ) {
						continue;
					}

					$by_id[ $rule['id'] ] = $rule;
				}

				foreach ( $drafts as $draft ) {
					$by_id[ $draft['id'] ] = array(
						'id'                  => $draft['id'],
						'label'               => 'Draft redirect for ' . $draft['fromPattern'],
						'enabled'             => false,
						'fromPattern'         => $draft['fromPattern'],
						'matchMode'           => 'exact',
						'toUrl'               => '/',
						'statusCode'          => $draft['status'],
						'preserveQueryString' => true,
						'notes'               => 'Created from a Onumia 404 Log hit. Review the destination before enabling.',
					);
				}

				return array( 'rules' => array_values( $by_id ) );
			}
		);
	}

	private function redirects_module(): ModuleDefinition {
		$plugin = Plugin::current();
		if ( $plugin instanceof Plugin ) {
			$module = $plugin->registry()->get( 'onumia/redirects' );
			if ( $module instanceof ModuleDefinition ) {
				return $module;
			}
		}

		foreach ( $this->redirects_module_directories() as $directory ) {
			if ( is_dir( $directory ) ) {
				return ( new ModuleLoader() )->load_directory( $directory );
			}
		}

		throw Errors::invariant( 'Onumia Redirects module could not be found.' );
	}

	/**
	 * @return list<string>
	 */
	private function redirects_module_directories(): array {
		$directories = array( dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'redirects' );

		$plugin_file = defined( 'ONUMIA_PLUGIN_FILE' ) ? constant( 'ONUMIA_PLUGIN_FILE' ) : null;
		if ( is_string( $plugin_file ) && '' !== $plugin_file ) {
			$directories[] = dirname( $plugin_file ) . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'redirects';
		}

		$plugin = Plugin::current();
		if ( $plugin instanceof Plugin ) {
			$directories[] = $plugin->directory() . 'modules' . DIRECTORY_SEPARATOR . 'redirects';
		}

		return array_values( array_unique( $directories ) );
	}

	private function redirect_from_pattern( string $url ): string {
		$parts = function_exists( 'wp_parse_url' ) ? \wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '/' . ltrim( $url, '/' );
		}

		$path = is_string( $parts['path'] ?? null ) ? $parts['path'] : '/';
		$path = '' === $path ? '/' : $path;
		return '/' === $path ? '/' : rtrim( $path, '/' );
	}

	private function redirect_rule_id( string $from_pattern ): string {
		$base = 'from-' . trim( $from_pattern, '/' );
		if ( function_exists( 'sanitize_title' ) ) {
			$id = \sanitize_title( $base );
		} else {
			$id = strtolower( preg_replace( '/[^a-z0-9]+/', '-', $base ) ?? '' );
		}

		$id = trim( substr( $id, 0, 64 ), '-' );
		return '' === $id ? 'from-' . substr( sha1( $from_pattern ), 0, 12 ) : $id;
	}

	private function current_url(): string {
		$host = $this->server_string( 'HTTP_HOST' );
		if ( '' === $host ) {
			$host = $this->server_string( 'SERVER_NAME' );
		}

		$request_uri = $this->server_string( 'REQUEST_URI' );
		if ( '' === $request_uri ) {
			return '';
		}

		$scheme = $this->is_ssl_request() ? 'https' : 'http';
		if ( '' === $host ) {
			return substr( $request_uri, 0, 1024 );
		}

		return substr( "{$scheme}://{$host}{$request_uri}", 0, 1024 );
	}

	private function is_ssl_request(): bool {
		if ( function_exists( 'is_ssl' ) ) {
			return \is_ssl();
		}

		$https = strtolower( $this->server_string( 'HTTPS' ) );
		return 'on' === $https || '1' === $https;
	}

	private function current_referrer(): string {
		return substr( $this->server_string( 'HTTP_REFERER' ), 0, 1024 );
	}

	private function current_user_agent(): string {
		return substr( $this->server_string( 'HTTP_USER_AGENT' ), 0, 255 );
	}

	private function server_string( string $key ): string {
		$value = match ( $key ) {
			'HTTP_HOST' => \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ),
			'SERVER_NAME' => \sanitize_text_field( \wp_unslash( $_SERVER['SERVER_NAME'] ?? '' ) ),
			'REQUEST_URI' => \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
			'HTTPS' => \sanitize_text_field( \wp_unslash( $_SERVER['HTTPS'] ?? '' ) ),
			'HTTP_REFERER' => \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ),
			'HTTP_USER_AGENT' => \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
			default => '',
		};
		return trim( $value );
	}

	private function is_ignored( string $url ): bool {
		foreach ( $this->ignored_patterns() as $pattern ) {
			if ( 1 === @preg_match( $pattern, $url ) ) {
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

	private function normalized_url( string $url ): string {
		$parts = function_exists( 'wp_parse_url' ) ? \wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return rtrim( $url, '/' );
		}

		$scheme = strtolower( is_string( $parts['scheme'] ?? null ) ? $parts['scheme'] : '' );
		$host   = strtolower( is_string( $parts['host'] ?? null ) ? $parts['host'] : '' );
		$port   = isset( $parts['port'] ) && is_numeric( $parts['port'] ) ? ':' . (string) $parts['port'] : '';
		$path   = is_string( $parts['path'] ?? null ) ? $parts['path'] : '';
		$path   = '' === $path ? '/' : $path;
		$path   = '/' === $path ? '/' : rtrim( $path, '/' );

		$query = '';
		if ( is_string( $parts['query'] ?? null ) && '' !== $parts['query'] ) {
			parse_str( $parts['query'], $query_args );
			$query_args = $this->sort_query_args( $query_args );
			$query      = http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );
		}

		$authority = '' === $host ? '' : ( '' === $scheme ? $host . $port : "{$scheme}://{$host}{$port}" );
		$normalized = "{$authority}{$path}";
		return '' === $query ? $normalized : "{$normalized}?{$query}";
	}

	/**
	 * @param array<mixed> $args Query args.
	 * @return array<mixed>
	 */
	private function sort_query_args( array $args ): array {
		ksort( $args );
		foreach ( $args as $key => $value ) {
			if ( is_array( $value ) ) {
				$args[ $key ] = $this->sort_query_args( $value );
			}
		}

		return $args;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function hit_for_display( array $row ): array {
		$first         = isset( $row['first_seen_at'] ) && is_numeric( $row['first_seen_at'] ) ? (int) $row['first_seen_at'] : 0;
		$last          = isset( $row['last_seen_at'] ) && is_numeric( $row['last_seen_at'] ) ? (int) $row['last_seen_at'] : 0;
		$url           = (string) ( $row['url'] ?? '' );
		$last_referrer = (string) ( $row['last_referrer'] ?? '' );

		return array(
			'id'                  => (string) ( $row['id'] ?? '' ),
			'urlFingerprint'      => (string) ( $row['url_fingerprint'] ?? '' ),
			'url'                 => $url,
			'urlPreview'          => $this->preview( $url, 96 ),
			'count'               => max( 1, (int) ( $row['count'] ?? 1 ) ),
			'firstSeenAt'         => $first,
			'firstSeenLabel'      => $this->time_label( $first, 'M j, Y H:i' ),
			'lastSeenAt'          => $last,
			'lastSeenLabel'       => $this->time_label( $last, 'M j, Y H:i' ),
			'lastReferrer'        => $last_referrer,
			'lastReferrerPreview' => $this->preview( $last_referrer, 80 ),
			'lastUserAgent'       => (string) ( $row['last_user_agent'] ?? '' ),
		);
	}

	private function preview( string $value, int $length ): string {
		return mb_strlen( $value ) > $length ? mb_substr( $value, 0, max( 0, $length - 3 ) ) . '...' : $value;
	}
}
