<?php

/**
 * Onumia module REST routes.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Rest;

use Onumia\Component\ComponentRegistry;
use Onumia\Modules\ModuleActionDispatcher;
use Onumia\Modules\ModuleBooter;
use Onumia\Modules\ModuleDataSourceDispatcher;
use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleFileRepository;
use Onumia\Modules\ModuleFileValidationException;
use Onumia\Modules\ModuleHistoryRepository;
use Onumia\Modules\ModuleRegistry;
use Onumia\Modules\ModuleRemixer;
use Onumia\Modules\ModuleSettingsRepository;
use Onumia\Structure\StructureDataSourceResolver;
use Onumia\Support\AccessPolicy;
use Onumia\Support\CustomEntityName;

final class ModuleRoutes {

	private const NAMESPACE = 'onumia/v1';

	public static function register( ModuleRegistry $registry, ModuleSettingsRepository $settings_repository, ModuleDataSourceDispatcher $data_source_dispatcher, StructureDataSourceResolver $data_source_resolver = new StructureDataSourceResolver(), ?ModuleActionDispatcher $action_dispatcher = null, ?ModuleRemixer $remixer = null, ?ModuleHistoryRepository $history_repository = null, ?ModuleFileRepository $file_repository = null, ?ComponentRegistry $component_registry = null ): void {
		$action_dispatcher  ??= new ModuleActionDispatcher( new ModuleBooter( $settings_repository ) );
		$remixer            ??= new ModuleRemixer();
		$history_repository ??= new ModuleHistoryRepository();
		$file_repository    ??= new ModuleFileRepository();
		$component_registry ??= new ComponentRegistry();

		\register_rest_route(
			self::NAMESPACE,
			'/modules',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response => self::list_modules( $registry, $settings_repository, $request, $component_registry ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::create_module( $registry, $settings_repository, $history_repository, $file_repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/modules/(?P<module>.+)/actions/(?P<action>[A-Za-z0-9_.-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::run_module_action( $registry, $action_dispatcher, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/modules/(?P<module>.+)/data-sources',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::resolve_module_data_sources( $registry, $data_source_dispatcher, $data_source_resolver, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/modules/(?P<module>.+)/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::update_module_settings( $registry, $settings_repository, $history_repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/modules/(?P<module>.+)/check-files',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::check_module_files( $registry, $file_repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/modules/(?P<module>.+)/files',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::update_module_files( $registry, $settings_repository, $history_repository, $file_repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/modules/(?P<module>.+)/remix',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::remix_module( $registry, $settings_repository, $remixer, $history_repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/modules/(?P<module>.+)/history',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::list_module_history( $registry, $settings_repository, $history_repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/modules/(?P<module>.+)/history/(?P<revision>[A-Za-z0-9_.-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::get_module_history_snapshot( $registry, $settings_repository, $history_repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/modules/(?P<module>.+)/history/(?P<revision>[A-Za-z0-9_.-]+)/revert',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::revert_module_history( $registry, $settings_repository, $history_repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);
	}

	public static function can_manage_onumia(): bool {
		return \current_user_can( 'manage_options' );
	}

	public static function list_modules( ModuleRegistry $registry, ModuleSettingsRepository $settings_repository, \WP_REST_Request $request, ?ComponentRegistry $component_registry = null ): \WP_REST_Response {
		$component_registry ??= new ComponentRegistry();
		$modules = array_map(
			static fn( ModuleDefinition $module ): array => self::prepare_module_for_response( $module, $settings_repository, component_registry: $component_registry ),
			array_filter(
				$registry->all(),
				static fn( ModuleDefinition $module ): bool => self::module_is_visible_for_request( $module, $request )
			)
		);

		\usort(
			$modules,
			static fn( array $left, array $right ): int => array( $left['category'], $left['label'], $left['name'] ) <=> array( $right['category'], $right['label'], $right['name'] )
		);

		return new \WP_REST_Response( $modules, 200 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_module( ModuleRegistry $registry, ModuleSettingsRepository $settings_repository, ModuleHistoryRepository $history_repository, ModuleFileRepository $file_repository, \WP_REST_Request $request ) {
		$name = self::request_custom_name( $request->get_param( 'name' ) );
		if ( null === $name ) {
			return new \WP_Error( 'onumia_invalid_module_name', 'Custom module name is required.', array( 'status' => 400 ) );
		}
		if ( null !== $registry->get( $name ) ) {
			return new \WP_Error( 'onumia_module_exists', 'Module already exists.', array( 'status' => 409 ) );
		}

		$files = self::request_file_map( $request->get_param( 'files' ) );
		if ( null === $files ) {
			return new \WP_Error( 'onumia_invalid_module_files', 'Module files payload must be a string map.', array( 'status' => 400 ) );
		}

		$settings = self::request_settings( $request->get_param( 'settings' ) ?? array() );
		if ( null === $settings ) {
			return new \WP_Error( 'onumia_invalid_settings', 'Settings payload must be an object.', array( 'status' => 400 ) );
		}

		try {
			$result = $file_repository->create_files( $name, $files, $settings, $settings_repository, $history_repository );
			$registry->register( $result['module'] );

			return new \WP_REST_Response(
				self::prepare_module_for_response( $result['module'], $settings_repository, $result['settings'] ),
				201
			);
		} catch ( ModuleFileValidationException $exception ) {
			return new \WP_Error(
				'onumia_module_file_validation_failed',
				$exception->getMessage(),
				array(
					'status'      => 400,
					'diagnostics' => self::findings_for_response( $exception->findings(), '' ),
				)
			);
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_module_create_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function resolve_module_data_sources( ModuleRegistry $registry, ModuleDataSourceDispatcher $dispatcher, StructureDataSourceResolver $resolver, \WP_REST_Request $request ) {
		$module_name = $request->get_param( 'module' );
		if ( ! is_string( $module_name ) || '' === $module_name ) {
			return new \WP_Error( 'onumia_missing_module', 'Module name is required.', array( 'status' => 400 ) );
		}

		$module = $registry->get( $module_name );
		if ( null === $module ) {
			return new \WP_Error( 'onumia_unknown_module', 'Module was not found.', array( 'status' => 404 ) );
		}
		if ( ! self::module_is_visible_for_request( $module, $request ) ) {
			return new \WP_Error( 'onumia_unknown_module', 'Module was not found.', array( 'status' => 404 ) );
		}

		$sources = self::request_sources( $request->get_param( 'sources' ) );
		if ( null === $sources ) {
			return new \WP_Error( 'onumia_invalid_sources', 'Sources payload must be a list of data source objects.', array( 'status' => 400 ) );
		}

		$resolved = array();
		foreach ( $sources as $source ) {
			try {
				$data        = $resolver->resolve( $module, $registry, $dispatcher, $source['source'], $source['params'] );
				$data_source = str_starts_with( $source['source'], 'module.' )
					? $module->contract()->data_source( substr( $source['source'], strlen( 'module.' ) ) )
					: null;
				$is_option   = null === $data_source || 'options' === $data_source->shape;
				$resolved[]  = array(
					'source'  => $source['source'],
					'params'  => $source['params'],
					'key'     => $source['key'],
					'options' => $is_option && is_array( $data ) ? $data : array(),
					'data'    => $data,
				);
			} catch ( \Throwable $throwable ) {
				return new \WP_Error( 'onumia_data_source_failed', $throwable->getMessage(), array( 'status' => 400 ) );
			}
		}

		return new \WP_REST_Response( array( 'sources' => $resolved ), 200 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function run_module_action( ModuleRegistry $registry, ModuleActionDispatcher $dispatcher, \WP_REST_Request $request ) {
		$module_name = $request->get_param( 'module' );
		if ( ! is_string( $module_name ) || '' === $module_name ) {
			return new \WP_Error( 'onumia_missing_module', 'Module name is required.', array( 'status' => 400 ) );
		}

		$action = $request->get_param( 'action' );
		if ( ! is_string( $action ) || '' === $action ) {
			return new \WP_Error( 'onumia_missing_action', 'Action name is required.', array( 'status' => 400 ) );
		}

		$module = $registry->get( $module_name );
		if ( null === $module ) {
			return new \WP_Error( 'onumia_unknown_module', 'Module was not found.', array( 'status' => 404 ) );
		}
		if ( ! self::module_is_visible_for_request( $module, $request ) ) {
			return new \WP_Error( 'onumia_unknown_module', 'Module was not found.', array( 'status' => 404 ) );
		}

		$input = self::request_input( $request->get_param( 'input' ) );
		if ( null === $input ) {
			return new \WP_Error( 'onumia_invalid_input', 'Action input must be an object.', array( 'status' => 400 ) );
		}

		try {
			$result = $dispatcher->dispatch( $module, $action, $input );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_action_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response(
			array(
				'action' => $action,
				'result' => $result,
			),
			200
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_module_settings( ModuleRegistry $registry, ModuleSettingsRepository $settings_repository, ModuleHistoryRepository $history_repository, \WP_REST_Request $request ) {
		$module_name = $request->get_param( 'module' );
		if ( ! is_string( $module_name ) || '' === $module_name ) {
			return new \WP_Error( 'onumia_missing_module', 'Module name is required.', array( 'status' => 400 ) );
		}

		$module = $registry->get( $module_name );
		if ( null === $module ) {
			return new \WP_Error( 'onumia_unknown_module', 'Module was not found.', array( 'status' => 404 ) );
		}
		if ( ! self::module_is_visible_for_request( $module, $request ) ) {
			return new \WP_Error( 'onumia_unknown_module', 'Module was not found.', array( 'status' => 404 ) );
		}

		$settings = self::request_settings( $request->get_param( 'settings' ) );
		if ( null === $settings ) {
			return new \WP_Error( 'onumia_invalid_settings', 'Settings payload must be an object.', array( 'status' => 400 ) );
		}

		try {
			if ( self::module_is_custom( $module ) ) {
				( new ModuleFileRepository() )->assert_current_valid( $module );
			}
			$settings_repository->update_settings( $module, $settings );
			self::notify_module_settings_updated( $module, $settings_repository );
			if ( self::module_is_custom( $module ) ) {
				$history_repository->commit_current( $module, $settings_repository->settings( $module ), 'Update module settings' );
			}
		} catch ( ModuleFileValidationException $exception ) {
			return new \WP_Error(
				'onumia_module_file_validation_failed',
				$exception->getMessage(),
				array(
					'status'      => 400,
					'diagnostics' => self::findings_for_response( $exception->findings(), $module->directory() ),
				)
			);
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_invalid_settings', $throwable->getMessage(), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response( self::prepare_module_for_response( $module, $settings_repository ), 200 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function check_module_files( ModuleRegistry $registry, ModuleFileRepository $file_repository, \WP_REST_Request $request ) {
		$module = self::module_from_request( $registry, $request );
		if ( $module instanceof \WP_Error ) {
			return $module;
		}
		if ( ! self::module_is_custom( $module ) ) {
			return new \WP_Error( 'onumia_module_files_unavailable', 'Module files can only be checked for custom modules.', array( 'status' => 400 ) );
		}

		$files = self::request_file_map( $request->get_param( 'files' ) );
		if ( null === $files ) {
			return new \WP_Error( 'onumia_invalid_module_files', 'Module files payload must be a string map.', array( 'status' => 400 ) );
		}

		try {
			$diagnostics = $file_repository->check_files( $module, $files );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_module_file_check_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response(
			array(
				'ok'          => ! self::has_error_diagnostics( $diagnostics ),
				'diagnostics' => $diagnostics,
			),
			200
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_module_files( ModuleRegistry $registry, ModuleSettingsRepository $settings_repository, ModuleHistoryRepository $history_repository, ModuleFileRepository $file_repository, \WP_REST_Request $request ) {
		$module = self::module_from_request( $registry, $request );
		if ( $module instanceof \WP_Error ) {
			return $module;
		}
		if ( ! self::module_is_custom( $module ) ) {
			return new \WP_Error( 'onumia_module_files_unavailable', 'Module files can only be updated for custom modules.', array( 'status' => 400 ) );
		}

		$files = self::request_file_map( $request->get_param( 'files' ) );
		if ( null === $files ) {
			return new \WP_Error( 'onumia_invalid_module_files', 'Module files payload must be a string map.', array( 'status' => 400 ) );
		}

		$settings = self::request_settings( $request->get_param( 'settings' ) ?? $settings_repository->settings( $module ) );
		if ( null === $settings ) {
			return new \WP_Error( 'onumia_invalid_settings', 'Settings payload must be an object.', array( 'status' => 400 ) );
		}

		try {
			$result = $file_repository->update_files( $module, $files, $settings, $settings_repository, $history_repository );
			self::notify_module_settings_updated( $result['module'], $settings_repository );

			return new \WP_REST_Response(
				self::prepare_module_for_response( $result['module'], $settings_repository, $result['settings'] ),
				200
			);
		} catch ( ModuleFileValidationException $exception ) {
			return new \WP_Error(
				'onumia_module_file_validation_failed',
				$exception->getMessage(),
				array(
					'status'      => 400,
					'diagnostics' => self::findings_for_response( $exception->findings(), $module->directory() ),
				)
			);
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_module_files_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function remix_module( ModuleRegistry $registry, ModuleSettingsRepository $settings_repository, ModuleRemixer $remixer, ModuleHistoryRepository $history_repository, \WP_REST_Request $request ) {
		$module_name = $request->get_param( 'module' );
		if ( ! is_string( $module_name ) || '' === $module_name ) {
			return new \WP_Error( 'onumia_missing_module', 'Module name is required.', array( 'status' => 400 ) );
		}

		$module = $registry->get( $module_name );
		if ( null === $module ) {
			return new \WP_Error( 'onumia_unknown_module', 'Module was not found.', array( 'status' => 404 ) );
		}
		if ( ! self::module_is_visible_for_request( $module, $request ) ) {
			return new \WP_Error( 'onumia_unknown_module', 'Module was not found.', array( 'status' => 404 ) );
		}

		$settings = self::request_settings( $request->get_param( 'settings' ) ?? $settings_repository->settings( $module ) );
		if ( null === $settings ) {
			return new \WP_Error( 'onumia_invalid_settings', 'Settings payload must be an object.', array( 'status' => 400 ) );
		}

		try {
			$settings_repository->validate_settings( $module, $settings );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_invalid_settings', $throwable->getMessage(), array( 'status' => 400 ) );
		}

		try {
			$remixed = $remixer->remix( $module, $registry );
			$registry->register( $remixed );
			$settings_repository->update_settings( $remixed, $settings );
			$history_repository->commit_current( $remixed, $settings_repository->settings( $remixed ), "Create remix from {$module->name()}" );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_remix_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response( self::prepare_module_for_response( $remixed, $settings_repository ), 201 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function list_module_history( ModuleRegistry $registry, ModuleSettingsRepository $settings_repository, ModuleHistoryRepository $history_repository, \WP_REST_Request $request ) {
		$module = self::module_from_request( $registry, $request );
		if ( $module instanceof \WP_Error ) {
			return $module;
		}
		if ( ! self::module_is_custom( $module ) ) {
			return new \WP_Error( 'onumia_history_unavailable', 'Module history is only available for custom modules.', array( 'status' => 400 ) );
		}

		$limit = is_numeric( $request->get_param( 'limit' ) ) ? (int) $request->get_param( 'limit' ) : 50;

		try {
			$commits   = $history_repository->history( $module, $limit );
			$snapshots = array();
			foreach ( $commits as $commit ) {
				$snapshot    = $history_repository->snapshot( $module, $settings_repository, $commit['id'] );
				$snapshots[] = array(
					'commit' => $snapshot['commit'],
					'module' => self::prepare_module_for_response( $snapshot['module'], $settings_repository, $snapshot['settings'] ),
				);
			}

			return new \WP_REST_Response(
				array(
					'commits'   => $commits,
					'snapshots' => $snapshots,
				),
				200
			);
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_history_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_module_history_snapshot( ModuleRegistry $registry, ModuleSettingsRepository $settings_repository, ModuleHistoryRepository $history_repository, \WP_REST_Request $request ) {
		$module = self::module_from_request( $registry, $request );
		if ( $module instanceof \WP_Error ) {
			return $module;
		}
		if ( ! self::module_is_custom( $module ) ) {
			return new \WP_Error( 'onumia_history_unavailable', 'Module history is only available for custom modules.', array( 'status' => 400 ) );
		}

		$revision = $request->get_param( 'revision' );
		if ( ! is_string( $revision ) || '' === $revision ) {
			return new \WP_Error( 'onumia_missing_revision', 'Git revision is required.', array( 'status' => 400 ) );
		}

		try {
			$snapshot = $history_repository->snapshot( $module, $settings_repository, $revision );

			return new \WP_REST_Response(
				array(
					'commit' => $snapshot['commit'],
					'module' => self::prepare_module_for_response( $snapshot['module'], $settings_repository, $snapshot['settings'] ),
				),
				200
			);
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_history_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function revert_module_history( ModuleRegistry $registry, ModuleSettingsRepository $settings_repository, ModuleHistoryRepository $history_repository, \WP_REST_Request $request ) {
		$module = self::module_from_request( $registry, $request );
		if ( $module instanceof \WP_Error ) {
			return $module;
		}
		if ( ! self::module_is_custom( $module ) ) {
			return new \WP_Error( 'onumia_history_unavailable', 'Module history is only available for custom modules.', array( 'status' => 400 ) );
		}

		$revision = $request->get_param( 'revision' );
		if ( ! is_string( $revision ) || '' === $revision ) {
			return new \WP_Error( 'onumia_missing_revision', 'Git revision is required.', array( 'status' => 400 ) );
		}

		try {
			$result = $history_repository->revert( $module, $settings_repository, $revision );

			return new \WP_REST_Response(
				self::prepare_module_for_response( $result['module'], $settings_repository, $result['settings'] ),
				200
			);
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_history_revert_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	private static function module_is_visible_for_request( ModuleDefinition $module, \WP_REST_Request $request ): bool {
		if ( $module->dev_only() && ! self::dev_mode_requested( $request ) ) {
			return false;
		}

		if ( ! $module->release_enabled() && ! self::disabled_modules_requested( $request ) ) {
			return false;
		}

		if ( ! $module->feature_enabled() ) {
			return false;
		}

		return ( new AccessPolicy() )->allows( $module->access() );
	}

	private static function notify_module_settings_updated( ModuleDefinition $module, ModuleSettingsRepository $settings_repository ): void {
		( new ModuleBooter( $settings_repository ) )->instance( $module )->settings_updated();
	}

	private static function disabled_modules_requested( \WP_REST_Request $request ): bool {
		$include_disabled = $request->get_param( 'includeDisabled' );
		if ( null === $include_disabled ) {
			return false;
		}

		if ( is_bool( $include_disabled ) ) {
			return $include_disabled;
		}

		if ( is_scalar( $include_disabled ) ) {
			$value = strtolower( trim( (string) $include_disabled ) );
			return '' === $value || ! in_array( $value, array( '0', 'false', 'no', 'off' ), true );
		}

		return false;
	}

	private static function dev_mode_requested( \WP_REST_Request $request ): bool {
		$dev = $request->get_param( 'dev' );
		if ( null === $dev ) {
			return false;
		}

		if ( is_bool( $dev ) ) {
			return $dev;
		}

		if ( is_scalar( $dev ) ) {
			$value = strtolower( trim( (string) $dev ) );
			return '' === $value || ! in_array( $value, array( '0', 'false', 'no', 'off' ), true );
		}

		return false;
	}

	private static function module_from_request( ModuleRegistry $registry, \WP_REST_Request $request ): ModuleDefinition|\WP_Error {
		$module_name = $request->get_param( 'module' );
		if ( ! is_string( $module_name ) || '' === $module_name ) {
			return new \WP_Error( 'onumia_missing_module', 'Module name is required.', array( 'status' => 400 ) );
		}

		$module = $registry->get( $module_name );
		if ( null === $module ) {
			return new \WP_Error( 'onumia_unknown_module', 'Module was not found.', array( 'status' => 404 ) );
		}
		if ( ! self::module_is_visible_for_request( $module, $request ) ) {
			return new \WP_Error( 'onumia_unknown_module', 'Module was not found.', array( 'status' => 404 ) );
		}

		return $module;
	}

		/**
		 * @return list<array{source:string,params:array<string,mixed>,key:?string}>|null
		 */
	private static function request_sources( mixed $sources ): ?array {
		if ( ! is_array( $sources ) || ! array_is_list( $sources ) ) {
			return null;
		}

		$normalized = array();
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) || ! is_string( $source['source'] ?? null ) || '' === $source['source'] ) {
				return null;
			}

			$params = $source['params'] ?? array();
			if ( ! is_array( $params ) || ( array() !== $params && array_is_list( $params ) ) ) {
				return null;
			}

			$normalized[] = array(
				'source' => $source['source'],
				'params' => self::string_keyed_array( $params ),
				'key'    => is_string( $source['key'] ?? null ) && '' !== $source['key'] ? $source['key'] : null,
			);
		}

		return $normalized;
	}

	/**
	 * @param  array<string,mixed>|null $settings Settings override.
	 * @return array{name:string,label:string,description:string,category:string,tags:list<string>,version:string,devOnly:bool,releaseEnabled:bool,releaseReason:string,custom:bool,enabled:bool,defaultEnabled:bool,capability:string,access:array<string,mixed>|\stdClass,settings:array<string,mixed>,settingDefinitions:array<string,array<string,mixed>>,entryDefinitions:array<string,array<string,mixed>>,structure:array<string,mixed>,messages:array<string,string>,files?:array<string,string>}
	 */
	private static function prepare_module_for_response( ModuleDefinition $module, ModuleSettingsRepository $settings_repository, ?array $settings = null, ?ComponentRegistry $component_registry = null ): array {
		$component_registry ??= new ComponentRegistry();
		$response = array(
			'name'               => $module->name(),
			'label'              => $module->label(),
			'description'        => $module->description(),
			'category'           => $module->category(),
			'tags'               => array_values( $module->tags() ),
			'version'            => $module->version(),
			'devOnly'            => $module->dev_only(),
			'releaseEnabled'     => $module->release_enabled(),
			'releaseReason'      => $module->release_reason(),
			'custom'             => self::module_is_custom( $module ),
			'enabled'            => $settings_repository->has_active_settings( $module ),
			'defaultEnabled'     => $module->contract()->default_enabled(),
			'capability'         => $module->contract()->capability(),
			'access'             => self::access_for_response( $module->access() ),
			'settings'           => $settings ?? $settings_repository->settings( $module ),
			'settingDefinitions' => $module->contract()->settings(),
			'entryDefinitions'   => self::entry_definitions_for_response( $module ),
			'structure'          => self::structure_for_response( $module, $component_registry ),
			'messages'           => $module->messages()->messages(),
		);

		if ( self::module_is_custom( $module ) ) {
			$response['files'] = ( new ModuleFileRepository() )->files( $module );
		}

		return $response;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function structure_for_response( ModuleDefinition $module, ComponentRegistry $component_registry ): array {
		$structure         = $module->structure()->data();
		$global_components = self::global_components_for_response( $module, $component_registry );

		if ( array() === $global_components ) {
			return $structure;
		}

		if ( ! is_array( $structure['components'] ?? null ) || array_is_list( $structure['components'] ) ) {
			$structure['components'] = array();
		}

		foreach ( $global_components as $name => $component ) {
			if ( ! isset( $structure['components'][ $name ] ) ) {
				$structure['components'][ $name ] = array( 'component' => $component );
			}
		}

		return $structure;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function global_components_for_response( ModuleDefinition $module, ComponentRegistry $component_registry ): array {
		$local      = array_fill_keys( $module->structure()->component_names(), true );
		$queue      = $module->structure()->component_refs();
		$components = array();

		while ( array() !== $queue ) {
			$component_ref = array_shift( $queue );
			if ( isset( $local[ $component_ref ] ) || isset( $components[ $component_ref ] ) ) {
				continue;
			}

			$definition = $component_registry->get( $component_ref );
			if ( null === $definition ) {
				continue;
			}

			$component                    = $definition->component();
			$components[ $component_ref ] = $component;
			array_push( $queue, ...self::component_refs_in_value( $component ) );
		}

		return $components;
	}

	/**
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private static function component_refs_in_value( mixed $value ): array {
		if ( is_array( $value ) ) {
			$refs = array();
			if ( is_string( $value['componentRef'] ?? null ) ) {
				$refs[] = $value['componentRef'];
			}

			foreach ( $value as $child ) {
				array_push( $refs, ...self::component_refs_in_value( $child ) );
			}

			return array_values( array_unique( $refs ) );
		}

		return array();
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function entry_definitions_for_response( ModuleDefinition $module ): array {
		$entries = array();
		foreach ( $module->contract()->entries() as $name => $entry ) {
			$data = $entry->to_array();
			if ( null !== $entry->source ) {
				$data_source = $module->contract()->data_source( $entry->source );
				if ( null !== $data_source ) {
					$data['sourcePagination'] = $data_source->pagination;
				}
			}

			$entries[ $name ] = $data;
		}

		return $entries;
	}

	/**
	 * @param  array<string,mixed> $access Access policy.
	 * @return array<string,mixed>|\stdClass
	 */
	private static function access_for_response( array $access ): array|\stdClass {
		return array() === $access ? new \stdClass() : $access;
	}

	private static function module_is_custom( ModuleDefinition $module ): bool {
		return str_starts_with( $module->name(), 'custom/' );
	}

	private static function request_custom_name( mixed $name ): ?string {
		return CustomEntityName::normalize( $name );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function request_input( mixed $input ): ?array {
		return self::request_record( $input ?? array() );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function request_settings( mixed $settings ): ?array {
		return self::request_record( $settings );
	}

	/**
	 * @return array<string,string>|null
	 */
	private static function request_file_map( mixed $files ): ?array {
		if ( ! is_array( $files ) || array_is_list( $files ) ) {
			return null;
		}

		$normalized = array();
		foreach ( $files as $path => $content ) {
			if ( ! is_string( $path ) || ! is_string( $content ) ) {
				return null;
			}
			$normalized[ $path ] = $content;
		}

		return $normalized;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function request_record( mixed $settings ): ?array {
		if ( ! is_array( $settings ) ) {
			return null;
		}

		$normalized = array();
		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) ) {
				return null;
			}

			$normalized[ $key ] = $value;
		}

		return $normalized;
	}

	/**
	 * @param  array<array-key,mixed> $value Value.
	 * @return array<string,mixed>
	 */
	private static function string_keyed_array( array $value ): array {
		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}

		return $normalized;
	}

	/**
	 * @param  \Onumia\Check\Finding[] $findings Findings.
	 * @return array<int,array{message:string,identifier:string,file:string,line:int,severity:string}>
	 */
	private static function findings_for_response( array $findings, string $root ): array {
		$root = rtrim( $root, '/\\' );

		return array_values(
			array_map(
				static function ( \Onumia\Check\Finding $finding ) use ( $root ): array {
					$file = str_starts_with( $finding->file, $root )
						? str_replace( '\\', '/', ltrim( substr( $finding->file, strlen( $root ) ), '/\\' ) )
						: $finding->file;

					return array(
						'message'    => $finding->message,
						'identifier' => $finding->identifier,
						'file'       => $file,
						'line'       => $finding->line,
						'severity'   => $finding->severity,
					);
				},
				$findings
			)
		);
	}

	/**
	 * @param array<int,array{message:string,identifier:string,file:string,line:int,severity:string}> $diagnostics Diagnostics.
	 */
	private static function has_error_diagnostics( array $diagnostics ): bool {
		foreach ( $diagnostics as $diagnostic ) {
			if ( 'error' === $diagnostic['severity'] ) {
				return true;
			}
		}

		return false;
	}
}
