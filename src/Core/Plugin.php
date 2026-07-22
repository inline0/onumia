<?php

/**
 * Onumia WordPress plugin runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Core;

use Onumia\Admin\AppPage;
use Onumia\Chat\ChatRepository;
use Onumia\Chat\ChatSchema;
use Onumia\Cli\DoctorCommand;
use Onumia\Cli\ModuleAuditCommand;
use Onumia\Component\ComponentRegistry;
use Onumia\Data\ModuleTableCleanup;
use Onumia\Data\ModuleTablePrivacy;
use Onumia\Data\ModuleTableUninstaller;
use Onumia\Data\SqliteSupport;
use Onumia\Dev\SeedContentTypes;
use Onumia\Dev\UiLabAccess;
use Onumia\Modules\ModuleActionDispatcher;
use Onumia\Modules\ModuleBooter;
use Onumia\Modules\ModuleDataSourceDispatcher;
use Onumia\Modules\ModuleJobRegistrar;
use Onumia\Modules\ModuleLoader;
use Onumia\Modules\ModuleRegistry;
use Onumia\Modules\ModuleSettingsRepository;
use Onumia\Pages\PagePostType;
use Onumia\PublicApi\Actions;
use Onumia\PublicApi\Filters;
use Onumia\Rest\ChatRoutes;
use Onumia\Rest\DevTestSupportRoutes;
use Onumia\Rest\ModulePublicRoutes;
use Onumia\Rest\ModuleRoutes;
use Onumia\Rest\ModuleTableRoutes;
use Onumia\Rest\PageRoutes;
use Onumia\Rest\UiStateRoutes;
use Onumia\Updates\GitHubReleaseUpdater;

final class Plugin {

	private const COMPONENT_ROOT_DIRECTORY = 'components';
	private const UI_LAB_DIRECTORY         = 'modules/development/ui-lab';
	private const MODULE_JOB_HOOK_PREFIX   = 'onumia_module_job_';
	private const UI_STATE_META_KEY        = 'onumia_ui_state';
	private const UNINSTALL_OPTIONS        = array(
		'onumia_module_secrets',
		'onumia_module_site_settings',
		'onumia_tables_ip_hash_secret',
		'onumia_chat_schema_version',
		'onumia_module_table_schema_checksum',
		'onumia_tables_cleanup_version',
		'onumia_dev_seed_content_types',
	);

	private static ?self $current = null;
	private bool $booted = false;
	private readonly ModuleLoader $loader;
	private readonly SqliteSupport $sqlite_support;
	private readonly ModuleSettingsRepository $settings_repository;
	private readonly ComponentRegistry $component_registry;
	private readonly ModuleBooter $booter;
	private readonly ModuleActionDispatcher $action_dispatcher;
	private readonly ModuleDataSourceDispatcher $data_source_dispatcher;
	/**
	 * @var string[]|null
	 */
	private readonly ?array $module_roots;
	/**
	 * @var string[]|null
	 */
	private readonly ?array $component_roots;
	private ModuleRegistry $registry;

	/**
	 * @param string[]|null                                   $module_roots       Explicit module roots.
	 * @param string[]|null                                   $component_roots    Explicit component roots.
	 * @param callable(string, callable, int, int): void|null $hook_adder         Hook adder.
	 * @param callable(string): bool|null                     $capability_checker Capability checker.
	 */
	public function __construct(
		private readonly string $file,
		private readonly string $version,
		?ModuleLoader $loader = null,
		?ModuleSettingsRepository $settings_repository = null,
		?array $module_roots = null,
		?callable $hook_adder = null,
		?callable $capability_checker = null,
		?array $component_roots = null,
		?ComponentRegistry $component_registry = null,
	) {
		$this->component_roots        = $component_roots;
		$this->sqlite_support         = new SqliteSupport();
		$this->component_registry     = $component_registry ?? ComponentRegistry::from_roots( $this->component_roots() );
		$this->loader                 = $loader ?? new ModuleLoader( component_registry: $this->component_registry );
		$this->settings_repository    = $settings_repository ?? new ModuleSettingsRepository();
		$this->booter                 = new ModuleBooter( $this->settings_repository, $hook_adder );
		$this->action_dispatcher      = new ModuleActionDispatcher( $this->booter, $capability_checker );
		$this->data_source_dispatcher = new ModuleDataSourceDispatcher( $this->booter, $capability_checker );
		$this->module_roots           = $module_roots;
		$this->registry               = new ModuleRegistry();
	}

	/**
	 * Cache parsed module definitions between requests unless disabled.
	 */
	private function maybe_enable_module_definition_cache(): void {
		if ( '0' === getenv( 'ONUMIA_MODULE_DEFINITION_CACHE' ) || ( defined( 'ONUMIA_MODULE_DEFINITION_CACHE' ) && false === ONUMIA_MODULE_DEFINITION_CACHE ) ) {
			return;
		}

		$uploads = wp_upload_dir( null, false );
		if ( is_array( $uploads ) && isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) && '' !== trim( $uploads['basedir'] ) ) {
			$this->loader->enable_definition_cache( rtrim( $uploads['basedir'], '/' ) . '/onumia/module-definition-cache' );
		}
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;
		self::$current = $this;
		PagePostType::register();
		SeedContentTypes::register();
		$this->sqlite_support->register_debug_information();
		$this->maybe_enable_module_definition_cache();
		$this->registry = new ModuleRegistry( $this->loader->load_roots( $this->module_roots() ) );
		$this->register_cli_commands();

		foreach ( $this->registry->all() as $module ) {
			if ( ! $module->release_enabled() || ! $module->feature_enabled() ) {
				continue;
			}

			if ( $this->settings_repository->has_active_settings( $module ) ) {
				$this->booter->boot( $module );
			}
		}

		AppPage::register();
		( new GitHubReleaseUpdater( $this->file, $this->version ) )->register();
		( new ModuleTableCleanup() )->register( $this->registry, $this->version );
		( new ModuleTablePrivacy( $this->registry ) )->register();
		( new ModuleJobRegistrar( $this->booter ) )->register( $this->registry );
		( new ChatSchema() )->maybe_install();
		\add_action(
			'rest_api_init',
			function (): void {
				ChatRoutes::register( new ChatRepository() );
				DevTestSupportRoutes::register_if_enabled();
				ModuleRoutes::register( $this->registry, $this->settings_repository, $this->data_source_dispatcher, action_dispatcher: $this->action_dispatcher, component_registry: $this->component_registry );
				ModuleTableRoutes::register( $this->registry );
				( new ModulePublicRoutes( $this->registry, $this->booter, settings_repository: $this->settings_repository ) )->register();
				PageRoutes::register();
				UiStateRoutes::register();
			}
		);

		Actions::loaded( $this );
	}

	public function is_booted(): bool {
		return $this->booted;
	}

	/**
	 * Returns the currently booted Onumia plugin instance when available.
	 *
	 * This accessor exists for integrations that load after the initial
	 * `onumia/runtime/loaded` action has already fired.
	 *
	 * @api
	 */
	public static function current(): ?self {
		return self::$current;
	}

	public function activate(): void {
		PagePostType::register_post_type();
		SeedContentTypes::register();
		$registry = new ModuleRegistry( $this->loader->load_roots( $this->module_roots() ) );
		( new ModuleTableCleanup() )->register( $registry, $this->version );
	}

	public static function uninstall(): void {
		ModuleTableUninstaller::drop_all();
		self::delete_uninstall_options();
		self::delete_ui_state_meta();
		self::clear_scheduled_events();
	}

	public function version(): string {
		return $this->version;
	}

	public function file(): string {
		return $this->file;
	}

	public function directory(): string {
		return rtrim( dirname( $this->file ), '/\\' ) . DIRECTORY_SEPARATOR;
	}

	public function registry(): ModuleRegistry {
		return $this->registry;
	}

	private static function delete_uninstall_options(): void {
		if ( ! function_exists( 'delete_option' ) ) {
			// @codeCoverageIgnoreStart
			return;
			// @codeCoverageIgnoreEnd
		}

		foreach ( self::UNINSTALL_OPTIONS as $option ) {
			\delete_option( $option );
		}
	}

	private static function delete_ui_state_meta(): void {
		if ( function_exists( 'delete_metadata' ) ) {
			\delete_metadata( 'user', 0, self::UI_STATE_META_KEY, '', true );
			return;
		}

		// @codeCoverageIgnoreStart
		if ( ! function_exists( 'get_users' ) || ! function_exists( 'delete_user_meta' ) ) {
			return;
		}

		$user_ids = \get_users(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		if ( ! is_array( $user_ids ) ) {
			return;
		}

		foreach ( $user_ids as $user_id ) {
			if ( is_numeric( $user_id ) ) {
				\delete_user_meta( (int) $user_id, self::UI_STATE_META_KEY );
			}
		}
		// @codeCoverageIgnoreEnd
	}

	private static function clear_scheduled_events(): void {
		self::clear_scheduled_hook( ModuleTableCleanup::HOOK );

		foreach ( self::scheduled_hooks() as $hook ) {
			if ( str_starts_with( $hook, self::MODULE_JOB_HOOK_PREFIX ) ) {
				self::clear_scheduled_hook( $hook );
			}
		}
	}

	private static function clear_scheduled_hook( string $hook ): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			// @codeCoverageIgnoreStart
			\wp_clear_scheduled_hook( $hook );
			return;
			// @codeCoverageIgnoreEnd
		}

		if ( ! function_exists( 'wp_unschedule_event' ) ) {
			// @codeCoverageIgnoreStart
			return;
			// @codeCoverageIgnoreEnd
		}

		foreach ( self::scheduled_events_for_hook( $hook ) as $event ) {
			\wp_unschedule_event( $event['timestamp'], $hook, $event['args'] );
		}
	}

	/**
	 * @return list<string>
	 */
	private static function scheduled_hooks(): array {
		if ( ! function_exists( '_get_cron_array' ) ) {
			// @codeCoverageIgnoreStart
			return array();
			// @codeCoverageIgnoreEnd
		}

		$hooks = array();
		foreach ( \_get_cron_array() as $timestamp => $events ) {
			unset( $timestamp );
			if ( ! is_array( $events ) ) {
				continue;
			}

			foreach ( $events as $hook => $instances ) {
				if ( is_string( $hook ) && is_array( $instances ) ) {
					$hooks[] = $hook;
				}
			}
		}

		return array_values( array_unique( $hooks ) );
	}

	/**
	 * @return list<array{timestamp:int,args:list<mixed>}>
	 */
	private static function scheduled_events_for_hook( string $hook ): array {
		if ( ! function_exists( '_get_cron_array' ) ) {
			// @codeCoverageIgnoreStart
			return array();
			// @codeCoverageIgnoreEnd
		}

		$events = array();
		foreach ( \_get_cron_array() as $timestamp => $hooks ) {
			if ( ! is_int( $timestamp ) && ! ctype_digit( (string) $timestamp ) ) {
				continue;
			}
			if ( ! is_array( $hooks ) || ! is_array( $hooks[ $hook ] ?? null ) ) {
				continue;
			}

			foreach ( $hooks[ $hook ] as $event ) {
				$args = array();
				if ( is_array( $event ) && is_array( $event['args'] ?? null ) ) {
					foreach ( $event['args'] as $arg ) {
						$args[] = $arg;
					}
				}
				$events[] = array(
					'timestamp' => (int) $timestamp,
					'args'      => $args,
				);
			}
		}

		return $events;
	}

	public function settings_repository(): ModuleSettingsRepository {
		return $this->settings_repository;
	}

	public function action_dispatcher(): ModuleActionDispatcher {
		return $this->action_dispatcher;
	}

	public function data_source_dispatcher(): ModuleDataSourceDispatcher {
		return $this->data_source_dispatcher;
	}

	private function register_cli_commands(): void {
		// @codeCoverageIgnoreStart
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'onumia modules audit', new ModuleAuditCommand( $this->registry ) );
			\WP_CLI::add_command( 'onumia doctor', new DoctorCommand( $this ) );
		}
		// @codeCoverageIgnoreEnd
	}

	/**
	 * @return string[]
	 */
	public function module_roots(): array {
		if ( null !== $this->module_roots ) {
			return $this->module_roots;
		}

		$roots = array();
		if ( UiLabAccess::requested_for_current_request() ) {
			$roots[] = $this->directory() . self::UI_LAB_DIRECTORY;
		}
		$roots = array_values( array_filter( Filters::module_roots( $roots ), 'is_string' ) );

		return array_values( array_unique( array_map( static fn( string $root ): string => rtrim( $root, '/\\' ), $roots ) ) );
	}

	/**
	 * @return string[]
	 */
	public function component_roots(): array {
		if ( null !== $this->component_roots ) {
			return $this->component_roots;
		}

		$roots = array( $this->directory() . self::COMPONENT_ROOT_DIRECTORY );
		foreach ( $this->theme_directories() as $theme_directory ) {
			$roots[] = $theme_directory . DIRECTORY_SEPARATOR . 'onumia' . DIRECTORY_SEPARATOR . self::COMPONENT_ROOT_DIRECTORY;
		}

		$roots = array_values( array_filter( Filters::component_roots( $roots ), 'is_string' ) );

		return array_values( array_unique( array_map( static fn( string $root ): string => rtrim( $root, '/\\' ), $roots ) ) );
	}

	/**
	 * @return string[]
	 */
	private function theme_directories(): array {
		$directories = array();
		foreach ( array( \get_stylesheet_directory(), \get_template_directory() ) as $directory ) {
			$directory = (string) $directory;
			if ( '' !== $directory ) {
				$directories[] = rtrim( $directory, '/\\' );
			}
		}

		return array_values( array_unique( $directories ) );
	}
}
