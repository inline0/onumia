<?php

/**
 * Production preflight checks for a Onumia install.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Support;

use Onumia\Core\Plugin;
use Onumia\Data\ModuleStorageResolver;
use Onumia\Data\SqlitePathResolver;
use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleRegistry;
use Onumia\Modules\ModuleSecretRepository;
use Onumia\Modules\ModuleSettingsRepository;
use Onumia\Modules\ModuleTableDefinition;
use Onumia\Modules\ModuleTableName;
use Onumia\Pro\Modules\SoftwareLicensing\LicensingService;
use Onumia\Pro\Modules\SoftwareLicensing\LicensingStore;
use Onumia\Pro\Modules\SoftwareLicensing\Stripe\StripeConfig;

final class PreflightDoctor {
	private const STATUS_HEALTHY   = 'healthy';
	private const STATUS_WARNING   = 'warning';
	private const STATUS_CRITICAL  = 'critical';
	private const MODULE_LICENSING = 'onumia/software-licensing';

	/** @var list<array{name:string,status:string,message:string,data:array<string,mixed>}> */
	private array $checks = array();

	public function __construct(
		private readonly Plugin $plugin,
		private readonly ModuleSettingsRepository $settings_repository,
		private readonly ModuleSecretRepository $secret_repository = new ModuleSecretRepository(),
		private readonly ModuleTableName $table_names = new ModuleTableName(),
		private readonly ModuleStorageResolver $storage_resolver = new ModuleStorageResolver(),
		private readonly SqlitePathResolver $sqlite_paths = new SqlitePathResolver(),
	) {}

	/**
	 * @return array<string,mixed>
	 */
	public function report(): array {
		$this->checks = array();

		$this->check_plugin_runtime();
		$this->check_theme_paths();
		$this->check_storage_driver();
		$this->check_table_registry();
		$this->check_cron_events();
		$this->check_rest_routes();
		$this->check_external_secret_config();
		$this->check_stripe_config();
		$this->check_github_config();
		$this->check_updater_readiness();
		$this->check_asset_manifests();

		$summary = $this->summary();

		return array(
			'status'      => $summary['critical'] > 0 ? self::STATUS_CRITICAL : ( $summary['warning'] > 0 ? self::STATUS_WARNING : self::STATUS_HEALTHY ),
			'generatedAt' => $this->now(),
			'plugin'      => array(
				'version' => $this->plugin->version(),
				'file'    => $this->plugin_basename(),
			),
			'summary'     => $summary,
			'checks'      => $this->checks,
		);
	}

	private function check_external_secret_config(): void {
		$module = $this->licensing_module();
		if ( null === $module ) {
			$this->add_check( 'secrets.external', self::STATUS_WARNING, 'Software Licensing module is not available.', array( 'available' => false ) );
			return;
		}

		$external = $this->secret_repository->external_status();
		$secrets  = array();
		$ready    = $external['configured'] && $external['valid'];
		foreach ( array(
			'githubReleaseCredential' => 'githubToken',
			'licensingSigner'         => 'licensingSigningKey',
		) as $label => $name ) {
			$source      = $this->secret_repository->source( $module, $name );
			$fingerprint = null;
			try {
				$fingerprint = $this->secret_repository->fingerprint( $module, $name );
			} catch ( \Throwable ) {
				$fingerprint = null;
			}
			$stored            = $this->secret_repository->stored( $module, $name );
			$secrets[ $label ] = array(
				'present'        => null !== $fingerprint,
				'source'         => $source,
				'fingerprint'    => $fingerprint,
				'storedFallback' => $stored,
			);
			$ready             = $ready && 'external' === $source && null !== $fingerprint && ! $stored;
		}

		$status = $external['configured'] && ! $external['valid']
			? self::STATUS_CRITICAL
			: ( $ready ? self::STATUS_HEALTHY : self::STATUS_WARNING );
		$this->add_check(
			'secrets.external',
			$status,
			self::STATUS_HEALTHY === $status ? 'Required licensing secrets are externally managed.' : 'Required licensing secrets are not fully externalized.',
			array(
				'configured' => $external['configured'],
				'valid'      => $external['valid'],
				'siteId'     => $external['siteId'],
				'error'      => $external['error'],
				'secrets'    => $secrets,
			)
		);
	}

	private function check_plugin_runtime(): void {
		$pro_available = class_exists( '\Onumia\Pro\Bootstrap' ) && true === \Onumia\Pro\Bootstrap::available();

		$this->add_check(
			'plugin.runtime',
			$this->plugin->is_booted() ? self::STATUS_HEALTHY : self::STATUS_CRITICAL,
			$this->plugin->is_booted() ? 'Onumia runtime is booted.' : 'Onumia runtime is not booted.',
			array(
				'version'      => $this->plugin->version(),
				'proAvailable' => $pro_available,
				'modules'      => count( $this->plugin->registry()->all() ),
			)
		);
	}

	private function check_theme_paths(): void {
		$stylesheet = $this->theme_directory( 'get_stylesheet_directory' );
		$template   = $this->theme_directory( 'get_template_directory' );
		$targets    = array(
			'themeSettings' => $stylesheet . DIRECTORY_SEPARATOR . 'onumia.settings.json',
			'customModules' => $stylesheet . DIRECTORY_SEPARATOR . 'onumia' . DIRECTORY_SEPARATOR . 'modules',
			'customApps'    => $stylesheet . DIRECTORY_SEPARATOR . 'onumia' . DIRECTORY_SEPARATOR . 'apps',
		);
		$results    = array();
		$writable   = true;

		foreach ( $targets as $key => $path ) {
			$target_writable = $this->path_writable( $path );
			$results[ $key ] = array(
				'path'     => $this->relative_or_basename( $path ),
				'writable' => $target_writable,
			);
			$writable        = $writable && $target_writable;
		}

		$this->add_check(
			'paths.theme',
			$writable ? self::STATUS_HEALTHY : self::STATUS_WARNING,
			$writable ? 'Theme settings and custom Onumia directories are writable.' : 'One or more theme Onumia paths are not writable.',
			array(
				'stylesheetTheme' => $this->relative_or_basename( $stylesheet ),
				'templateTheme'   => $this->relative_or_basename( $template ),
				'targets'         => $results,
			)
		);
	}

	private function check_storage_driver(): void {
		try {
			$resolution = $this->storage_resolver->resolve();
		} catch ( \Throwable $throwable ) {
			$this->add_check(
				'storage.driver',
				self::STATUS_CRITICAL,
				'Module data storage could not be resolved.',
				array( 'failure' => $throwable->getMessage() )
			);
			return;
		}

		$this->add_check(
			'storage.driver',
			self::STATUS_HEALTHY,
			$resolution->reason,
			array(
				'engine'        => $resolution->engine,
				'forced'        => $resolution->forced,
				'markerEngine'  => $resolution->marker_engine,
				'dataDirectory' => $this->relative_or_basename( $this->sqlite_paths->base_directory() ),
			)
		);
	}

	private function check_table_registry(): void {
		$expected = $this->expected_mysql_tables();
		if ( array() === $expected ) {
			$this->add_check( 'tables.registry', self::STATUS_HEALTHY, 'No MySQL module tables are declared.', array( 'expected' => array() ) );
			return;
		}

		$installed = $this->installed_schema_records();
		if ( null === $installed ) {
			$this->add_check(
				'tables.registry',
				self::STATUS_HEALTHY,
				'Module table schema registry has not been created yet.',
				array( 'expected' => $this->table_summary( $expected ) )
			);
			return;
		}

		$stale   = array();
		foreach ( $installed as $key => $record ) {
			$table = $expected[ $key ] ?? null;
			if ( null === $table ) {
				continue;
			}

			if ( (int) $record['version'] !== $table['version'] ) {
				$stale[] = $key;
			}
		}

		$status = array() === $stale ? self::STATUS_HEALTHY : self::STATUS_CRITICAL;
		$this->add_check(
			'tables.registry',
			$status,
			self::STATUS_HEALTHY === $status ? 'Installed module table schema records match the loaded module contract.' : 'Installed module table schema records are stale.',
			array(
				'expected'       => $this->table_summary( $expected ),
				'installedCount' => count( $installed ),
				'stale'          => $stale,
			)
		);
	}

	private function check_cron_events(): void {
		$expected = array( 'onumia_tables_cleanup' => 'Module table cleanup' );
		foreach ( $this->plugin->registry()->all() as $module ) {
			if ( ! $module->release_enabled() || ! $module->feature_enabled() ) {
				continue;
			}

			foreach ( $module->advanced()->jobs() as $job ) {
				if ( ! $job->enabled ) {
					continue;
				}
				$expected[ $this->job_hook( $module->name(), $job->name ) ] = $module->label() . ': ' . $job->name;
			}
		}

		$missing = array();
		foreach ( $expected as $hook => $label ) {
			if ( ! function_exists( 'wp_next_scheduled' ) || false === \wp_next_scheduled( $hook ) ) {
				$missing[] = array(
					'hook'  => $hook,
					'label' => $label,
				);
			}
		}

		$this->add_check(
			'cron.schedules',
			array() === $missing ? self::STATUS_HEALTHY : self::STATUS_WARNING,
			array() === $missing ? 'Expected Onumia cron events are scheduled.' : 'One or more expected Onumia cron events are not scheduled.',
			array(
				'expected' => array_values( $expected ),
				'missing'  => $missing,
			)
		);
	}

	private function check_rest_routes(): void {
		$registered = $this->registered_rest_routes();
		$expected   = $this->expected_public_routes();
		$missing    = array_values( array_diff( $expected, $registered ) );

		$this->add_check(
			'rest.publicRoutes',
			array() === $missing ? self::STATUS_HEALTHY : self::STATUS_CRITICAL,
			array() === $missing ? 'Expected public REST routes are registered.' : 'One or more expected public REST routes are missing.',
			array(
				'namespace'       => 'onumia/v1',
				'expectedRoutes'  => $expected,
				'missingRoutes'   => $missing,
				'registeredCount' => count( $registered ),
			)
		);
	}

	private function check_stripe_config(): void {
		$module = $this->licensing_module();
		if ( null === $module ) {
			$this->add_check( 'stripe.config', self::STATUS_WARNING, 'Software Licensing module is not available.', array( 'available' => false ) );
			return;
		}

		$this->require_licensing_runtime( $module );
		try {
			$config = StripeConfig::from_module( $module, $this->settings_repository->settings( $module ), $this->secret_repository );
		} catch ( \Throwable ) {
			$this->add_check(
				'stripe.config',
				self::STATUS_CRITICAL,
				'Stripe configuration could not resolve the external secret store.',
				array( 'secretResolution' => 'failed' )
			);
			return;
		}
		$safe   = $config->safe_status();
		$status = true !== $safe['stripeEnabled']
			? self::STATUS_HEALTHY
			: ( true === $safe['stripeConfigured'] && true === $safe['stripeCheckoutApiConfigured'] && true === $safe['stripeWebhookConfigured'] ? self::STATUS_HEALTHY : self::STATUS_WARNING );

		$this->add_check(
			'stripe.config',
			$status,
			self::STATUS_HEALTHY === $status ? 'Stripe configuration is consistent.' : 'Stripe is enabled but one or more Stripe secrets are missing.',
			$safe
		);
	}

	private function check_github_config(): void {
		$module = $this->licensing_module();
		if ( null === $module ) {
			$this->add_check( 'github.config', self::STATUS_WARNING, 'Software Licensing module is not available.', array( 'available' => false ) );
			return;
		}

		$settings          = $this->settings_repository->settings( $module );
		$repository        = is_string( $settings['githubRepository'] ?? null ) ? trim( $settings['githubRepository'] ) : '';
		$product           = is_string( $settings['githubProductSlug'] ?? null ) ? trim( $settings['githubProductSlug'] ) : '';
		$token_source      = $this->secret_repository->source( $module, 'githubToken' );
		$token_fingerprint = null;
		try {
			$token_fingerprint = $this->secret_repository->fingerprint( $module, 'githubToken' );
		} catch ( \Throwable ) {
			$token_fingerprint = null;
		}
		$token       = null !== $token_fingerprint;
		$release_row = $this->private_release_count();

		$status = 'external-invalid' === $token_source
			? self::STATUS_CRITICAL
			: ( $release_row > 0 && ! $token ? self::STATUS_WARNING : self::STATUS_HEALTHY );
		$this->add_check(
			'github.config',
			$status,
			self::STATUS_HEALTHY === $status ? 'GitHub release sync configuration is safe to report.' : 'Private GitHub releases exist but no GitHub token is configured.',
			array(
				'repositoryConfigured'  => '' !== $repository,
				'productSlug'           => '' === $product ? 'onumia-pro' : $product,
				'credentialPresent'     => $token,
				'credentialSource'      => $token_source,
				'credentialFingerprint' => $token_fingerprint,
				'privateReleaseCount'   => $release_row,
			)
		);
	}

	private function check_updater_readiness(): void {
		$module = $this->licensing_module();
		if ( null === $module ) {
			$this->add_check( 'updater.readiness', self::STATUS_WARNING, 'Software Licensing module is not available.', array( 'products' => array() ) );
			return;
		}

		try {
			$this->require_licensing_runtime( $module );
			$service  = new LicensingService( new LicensingStore( $module ) );
			$products = $service->products();
			$releases = $service->releases();
		} catch ( \Throwable $throwable ) {
			$this->add_check(
				'updater.readiness',
				self::STATUS_WARNING,
				'Updater readiness could not be read from licensing tables.',
				array( 'failure' => $throwable->getMessage() )
			);
			return;
		}

		$published_by_product = array();
		foreach ( $releases as $release ) {
			if ( 'published' !== ( $release['status'] ?? '' ) ) {
				continue;
			}
			$product_slug = is_string( $release['productSlug'] ?? null ) ? $release['productSlug'] : '';
			if ( '' !== $product_slug ) {
				$published_by_product[ $product_slug ] = ( $published_by_product[ $product_slug ] ?? 0 ) + 1;
			}
		}

		$readiness = array();
		foreach ( $products as $product ) {
			$slug = is_string( $product['slug'] ?? null ) ? $product['slug'] : '';
			if ( '' === $slug || ! isset( $published_by_product[ $slug ] ) ) {
				continue;
			}

			$readiness[] = array(
				'productSlug'       => $slug,
				'publishedReleases' => $published_by_product[ $slug ],
				'updaterCodeReady'  => is_string( $product['updaterCode'] ?? null ) && '' !== trim( $product['updaterCode'] ),
			);
		}

		$not_ready = array_values(
			array_filter(
				$readiness,
				static fn( array $product ): bool => true !== $product['updaterCodeReady']
			)
		);
		$status    = array() === $readiness ? self::STATUS_WARNING : ( array() === $not_ready ? self::STATUS_HEALTHY : self::STATUS_WARNING );

		$this->add_check(
			'updater.readiness',
			$status,
			self::STATUS_HEALTHY === $status ? 'Products with published releases have generated updater code.' : 'No ready updater products were found, or some products are missing generated updater code.',
			array( 'products' => $readiness )
		);
	}

	private function check_asset_manifests(): void {
		$manifests = array(
			'app'    => $this->plugin->directory() . 'assets/app/manifest.json',
			'appPro' => $this->plugin->directory() . 'assets/app-pro/manifest.json',
		);
		$results   = array();
		$missing   = false;

		foreach ( $manifests as $name => $manifest ) {
			$result = $this->asset_manifest_result( $manifest );
			if ( false === $result['present'] || false === $result['valid'] || array() !== $result['missingFiles'] ) {
				$missing = true;
			}
			$results[ $name ] = $result;
		}

		$this->add_check(
			'assets.manifests',
			$missing ? self::STATUS_CRITICAL : self::STATUS_HEALTHY,
			$missing ? 'One or more built asset manifests are missing or reference absent files.' : 'Built asset manifests exist and reference present files.',
			$results
		);
	}

	/**
	 * @return array<string,array{module:string,table:string,version:int}>
	 */
	private function expected_mysql_tables(): array {
		$tables = array();
		foreach ( $this->plugin->registry()->all() as $module ) {
			if ( ! $module->release_enabled() || ! $module->feature_enabled() ) {
				continue;
			}

			foreach ( $module->advanced()->tables() as $table ) {
				if ( 'sqlite' === $table->driver ) {
					continue;
				}
				$key            = $this->schema_key( $module->name(), $table );
				$tables[ $key ] = array(
					'module'  => $module->name(),
					'table'   => $table->name,
					'version' => $table->version,
				);
			}
		}

		return $tables;
	}

	/**
	 * @return array<string,array{version:int}>|null
	 */
	private function installed_schema_records(): ?array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$query = $wpdb->prepare( 'SELECT module, object_type, object_name, version FROM %i WHERE object_type = %s', $this->table_names->schema_table(), 'table' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above when WordPress returns SQL.
		$rows = is_string( $query ) ? $wpdb->get_results( $query, \ARRAY_A ) : null;
		if ( ! is_array( $rows ) ) {
			return null;
		}

		$records = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$module = is_string( $row['module'] ?? null ) ? $row['module'] : '';
			$name   = is_string( $row['object_name'] ?? null ) ? $row['object_name'] : '';
			if ( '' === $module || '' === $name || ! is_numeric( $row['version'] ?? null ) ) {
				continue;
			}
			$records[ $module . ':' . $name ] = array( 'version' => (int) $row['version'] );
		}

		return $records;
	}

	/**
	 * @param array<string,array{module:string,table:string,version:int}> $tables Tables.
	 * @return list<array{module:string,table:string,version:int}>
	 */
	private function table_summary( array $tables ): array {
		return array_values( $tables );
	}

	private function schema_key( string $module, ModuleTableDefinition $table ): string {
		return $module . ':' . $table->name;
	}

	/**
	 * @return list<string>
	 */
	private function expected_public_routes(): array {
		$routes = array();
		foreach ( $this->plugin->registry()->all() as $module ) {
			if ( ! $module->release_enabled() || ! $module->feature_enabled() ) {
				continue;
			}
			foreach ( $module->advanced()->public_routes() as $route ) {
				$routes[] = '/public/modules/' . $this->public_module_slug( $module ) . $route->path;
			}
		}

		if ( null !== $this->licensing_module() ) {
			$routes = array_merge(
				$routes,
				array(
					'/public/modules/software-licensing/stripe/checkout/sessions',
					'/public/modules/software-licensing/stripe/checkout/sessions/status',
					'/public/modules/software-licensing/stripe/billing-portal/sessions',
					'/public/modules/software-licensing/stripe/invoices/url',
					'/public/modules/software-licensing/stripe/webhook',
					'/public/modules/software-licensing/customers/portfolio',
					'/public/modules/software-licensing/activations/deactivate',
					'/public/modules/software-licensing/releases/latest',
					'/public/modules/software-licensing/downloads/token',
				)
			);
		}

		sort( $routes );
		return array_values( array_unique( $routes ) );
	}

	/**
	 * @return list<string>
	 */
	private function registered_rest_routes(): array {
		// @codeCoverageIgnoreStart
		// Covered by WordPress at runtime; unit tests exercise the deterministic route-registry fallback below.
		if ( function_exists( 'rest_get_server' ) ) {
			$server = \rest_get_server();
			if ( is_object( $server ) && method_exists( $server, 'get_routes' ) ) {
				$routes = array_map( array( $this, 'normalize_rest_route' ), array_keys( $server->get_routes() ) );
				sort( $routes );
				return array_values( array_filter( $routes, 'is_string' ) );
			}
		}
		// @codeCoverageIgnoreEnd

		$routes = array();
		foreach ( $this->test_global_array( 'onumia_rest_routes' ) as $route ) {
			if ( ! is_array( $route ) ) {
				continue;
			}
			$name = $route['route'] ?? null;
			if ( is_string( $name ) ) {
				$routes[] = $this->normalize_rest_route( $name );
			}
		}

		sort( $routes );
		return array_values( array_unique( $routes ) );
	}

	private function normalize_rest_route( string $route ): string {
		if ( str_starts_with( $route, '/onumia/v1/' ) ) {
			return substr( $route, strlen( '/onumia/v1' ) );
		}

		return $route;
	}

	private function public_module_slug( ModuleDefinition $module ): string {
		$parts = array_values( array_filter( explode( '/', $module->name() ), static fn( string $part ): bool => '' !== $part ) );
		$part  = end( $parts );
		$slug  = strtolower( (string) preg_replace( '/[^a-z0-9_-]+/', '-', false === $part ? $module->name() : $part ) );
		$slug  = trim( $slug, '-' );

		return '' === $slug ? 'module' : $slug;
	}

	private function licensing_module(): ?ModuleDefinition {
		$module = $this->plugin->registry()->get( self::MODULE_LICENSING );
		if ( null === $module || ! $module->release_enabled() || ! $module->feature_enabled() ) {
			return null;
		}

		return $module;
	}

	private function private_release_count(): int {
		$module = $this->licensing_module();
		if ( null === $module ) {
			return 0;
		}

		try {
			$this->require_licensing_runtime( $module );
			$count = 0;
			foreach ( ( new LicensingService( new LicensingStore( $module ) ) )->releases() as $release ) {
				$metadata = $release['metadata'] ?? null;
				if ( is_array( $metadata ) && true === ( $metadata['githubRepositoryPrivate'] ?? false ) ) {
					++$count;
				}
			}
			return $count;
		} catch ( \Throwable ) {
			return 0;
		}
	}

	/**
	 * @return array{present:bool,valid:bool,entries:int,missingFiles:list<string>}
	 */
	private function asset_manifest_result( string $manifest ): array {
		if ( ! is_file( $manifest ) ) {
			return array(
				'present'      => false,
				'valid'        => false,
				'entries'      => 0,
				'missingFiles' => array(),
			);
		}

		$decoded = json_decode( (string) file_get_contents( $manifest ), true );
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) {
			return array(
				'present'      => true,
				'valid'        => false,
				'entries'      => 0,
				'missingFiles' => array(),
			);
		}

		$directory = dirname( $manifest );
		$missing   = array();
		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			foreach ( $this->manifest_files( $entry ) as $file ) {
				if ( ! is_file( $directory . DIRECTORY_SEPARATOR . $file ) ) {
					$missing[] = $file;
				}
			}
		}

		return array(
			'present'      => true,
			'valid'        => true,
			'entries'      => count( $decoded ),
			'missingFiles' => array_values( array_unique( $missing ) ),
		);
	}

	/**
	 * @param array<array-key,mixed> $entry Manifest entry.
	 * @return list<string>
	 */
	private function manifest_files( array $entry ): array {
		$files = array();
		if ( is_string( $entry['file'] ?? null ) ) {
			$files[] = $entry['file'];
		}
		foreach ( array( 'css', 'assets' ) as $key ) {
			if ( ! is_array( $entry[ $key ] ?? null ) ) {
				continue;
			}
			foreach ( $entry[ $key ] as $file ) {
				if ( is_string( $file ) ) {
					$files[] = $file;
				}
			}
		}

		return $files;
	}

	private function job_hook( string $module, string $job ): string {
		return 'onumia_module_job_' . substr( sha1( $module . ':' . $job ), 0, 24 );
	}

	private function require_licensing_runtime( ModuleDefinition $module ): void {
		foreach (
			array(
				'src/GitHubReleaseProvider.php',
				'src/WordPressGitHubReleaseProvider.php',
				'src/ReleaseManifestVerifier.php',
				'src/ReleasePackageVerifier.php',
				'src/LicensingSigningSecret.php',
				'src/LicenseKeyService.php',
				'src/LicensingRecordMapper.php',
				'src/LicensingStore.php',
				'src/LicensingService.php',
				'src/Stripe/StripeConfig.php',
			) as $relative
		) {
			$file = $module->directory() . DIRECTORY_SEPARATOR . $relative;
			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private function add_check( string $name, string $status, string $message, array $data = array() ): void {
		$this->checks[] = array(
			'name'    => $name,
			'status'  => $status,
			'message' => $message,
			'data'    => $this->redact( $data ),
		);
	}

	/**
	 * @return array{healthy:int,warning:int,critical:int,total:int}
	 */
	private function summary(): array {
		$summary = array(
			'healthy'  => 0,
			'warning'  => 0,
			'critical' => 0,
			'total'    => count( $this->checks ),
		);

		foreach ( $this->checks as $check ) {
			$status = $check['status'];
			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			}
		}

		return $summary;
	}

	/**
	 * @param array<string,mixed> $value Value.
	 * @return array<string,mixed>
	 */
	private function redact( array $value ): array {
		$redacted = array();
		foreach ( $value as $key => $item ) {
			if ( $this->should_redact_key( $key ) ) {
				$redacted[ $key ] = is_bool( $item ) ? $item : '[redacted]';
				continue;
			}
			$redacted[ $key ] = is_array( $item ) ? $this->redact_array( $item ) : $item;
		}

		return $redacted;
	}

	/**
	 * @param array<array-key,mixed> $value Value.
	 * @return array<array-key,mixed>
	 */
	private function redact_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && $this->should_redact_key( $key ) ) {
				$result[ $key ] = is_bool( $item ) ? $item : '[redacted]';
				continue;
			}
			$result[ $key ] = is_array( $item ) ? $this->redact_array( $item ) : $item;
		}

		return $result;
	}

	private function should_redact_key( string $key ): bool {
		return 1 === preg_match( '/\A(?:name|customerName)\z|(?:secret|token|licenseKey|authorization|password|customerEmail|email)/i', $key );
	}

	private function path_writable( string $path ): bool {
		if ( is_file( $path ) || is_dir( $path ) ) {
			return is_writable( $path );
		}

		$directory = dirname( $path );
		while ( '' !== $directory && '.' !== $directory && ! is_dir( $directory ) && dirname( $directory ) !== $directory ) {
			$directory = dirname( $directory );
		}

		return is_dir( $directory ) && is_writable( $directory );
	}

	private function theme_directory( string $function ): string {
		if ( function_exists( $function ) ) {
			$value = $function();
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return rtrim( $value, '/\\' );
			}
		}

		return '';
	}

	private function relative_or_basename( string $path ): string {
		$plugin_dir = rtrim( $this->plugin->directory(), '/\\' ) . DIRECTORY_SEPARATOR;
		if ( str_starts_with( $path, $plugin_dir ) ) {
			return ltrim( substr( $path, strlen( $plugin_dir ) ), '/\\' );
		}

		return $path;
	}

	private function plugin_basename(): string {
		// @codeCoverageIgnoreStart
		// Covered by WordPress at runtime; unit tests exercise the deterministic basename fallback below.
		if ( function_exists( 'plugin_basename' ) ) {
			return \plugin_basename( $this->plugin->file() );
		}
		// @codeCoverageIgnoreEnd

		return basename( dirname( $this->plugin->file() ) ) . '/' . basename( $this->plugin->file() );
	}

	/**
	 * @return list<mixed>
	 */
	private function test_global_array( string $key ): array {
		$value = $GLOBALS[ $key ] ?? array();
		return is_array( $value ) ? array_values( $value ) : array();
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? (string) \current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
