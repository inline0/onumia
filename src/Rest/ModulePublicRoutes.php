<?php

/**
 * Public module REST routes.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Rest;

use Onumia\Core\Errors;
use Onumia\Modules\ModuleBooter;
use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModulePublicRouteDefinition;
use Onumia\Modules\ModuleRegistry;
use Onumia\Modules\ModuleSettingsRepository;
use Onumia\Modules\ModuleValueValidator;

final class ModulePublicRoutes {

	private const NAMESPACE = 'onumia/v1';

	public function __construct(
		private readonly ModuleRegistry $registry,
		private readonly ModuleBooter $booter,
		private readonly ModuleValueValidator $validator = new ModuleValueValidator(),
		private readonly ModuleSettingsRepository $settings_repository = new ModuleSettingsRepository(),
	) {}

	public function register(): void {
		foreach ( $this->registry->all() as $module ) {
			if ( ! $module->release_enabled() || ! $module->feature_enabled() ) {
				continue;
			}

			foreach ( $module->advanced()->public_routes() as $route ) {
				$this->register_route( $module, $route );
			}
		}
	}

	private function register_route( ModuleDefinition $module, ModulePublicRouteDefinition $route ): void {
		\register_rest_route(
			self::NAMESPACE,
			'/public/modules/' . $this->public_module_slug( $module ) . $route->path,
			array(
				array(
					'methods'             => $route->method,
					'callback'            => fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => $this->dispatch( $module, $route, $request ),
					'permission_callback' => fn( \WP_REST_Request $request ): bool => $this->can_access( $module, $route, $request ),
				),
			)
		);
	}

	private function public_module_slug( ModuleDefinition $module ): string {
		$parts = array_values( array_filter( explode( '/', $module->name() ), static fn( string $part ): bool => '' !== $part ) );
		$part  = end( $parts );
		$slug  = strtolower( (string) preg_replace( '/[^a-z0-9_-]+/', '-', false === $part ? $module->name() : $part ) );
		$slug  = trim( $slug, '-' );

		return '' === $slug ? 'module' : $slug;
	}

	private function can_access( ModuleDefinition $module, ModulePublicRouteDefinition $route, \WP_REST_Request $request ): bool {
		if ( ! $module->feature_enabled() ) {
			return false;
		}

		if ( 'wordpress_user' === $route->auth && ! \current_user_can( $module->contract()->capability() ) ) {
			return false;
		}

		if ( in_array( $route->auth, array( 'signature', 'webhook_signature' ), true ) && ! $this->valid_signature( $request ) ) {
			return false;
		}

		if ( 'license_key' === $route->auth && ! $this->has_request_secret( $request, 'licenseKey' ) ) {
			return false;
		}

		if ( 'download_token' === $route->auth && ! $this->has_request_secret( $request, 'token', true ) ) {
			return false;
		}

		return ! $this->rate_limited( $module, $route );
	}

	private function dispatch( ModuleDefinition $module, ModulePublicRouteDefinition $route, \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = $this->input_from_request( $request, array_keys( $route->inputs ) );

		try {
			$input    = $this->validator->normalize_input( $route->inputs, $input, "Public route {$module->name()} {$route->path}" );
			$instance = $this->booter->instance( $module );
			if ( ! is_callable( array( $instance, $route->handler ) ) ) {
				throw Errors::invariant( "Module {$module->name()} public route {$route->path} handler is not callable." );
			}

			$result = 0 === $route->total_parameters ? $instance->{$route->handler}() : $instance->{$route->handler}( $input );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_public_route_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}

		if ( $result instanceof \WP_REST_Response || $result instanceof \WP_Error ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * @param string[] $input_keys Input keys.
	 * @return array<string,mixed>
	 */
	private function input_from_request( \WP_REST_Request $request, array $input_keys ): array {
		$json = $request->get_json_params();
		if ( is_array( $json ) && ! array_is_list( $json ) ) {
			/** @var array<string,mixed> $json */
			return $json;
		}

		$input = $request->get_param( 'input' );
		if ( is_array( $input ) && ! array_is_list( $input ) ) {
			/** @var array<string,mixed> $input */
			return $input;
		}

		$params = array();
		foreach ( $input_keys as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$params[ $key ] = $value;
			}
		}

		return $params;
	}

	private function has_request_secret( \WP_REST_Request $request, string $key, bool $allow_query = false ): bool {
		$json = $request->get_json_params();
		if ( is_array( $json ) && ! array_is_list( $json ) ) {
			$value = $json[ $key ] ?? null;
			return is_string( $value ) && '' !== trim( $value );
		}

		$input = $request->get_param( 'input' );
		if ( is_array( $input ) && ! array_is_list( $input ) ) {
			$value = $input[ $key ] ?? null;
			return is_string( $value ) && '' !== trim( $value );
		}

		$value = $allow_query ? $request->get_param( $key ) : null;
		return is_string( $value ) && '' !== trim( $value );
	}

	private function valid_signature( \WP_REST_Request $request ): bool {
		$signature = $request->get_param( 'signature' );
		$payload   = $request->get_param( 'payload' );
		if ( ! is_string( $signature ) || ! is_string( $payload ) ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha256', $payload, \wp_salt( 'auth' ) ), $signature );
	}

	private function rate_limited( ModuleDefinition $module, ModulePublicRouteDefinition $route ): bool {
		$limit = $this->route_rate_limit( $module, $route );
		if ( $limit <= 0 || ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return false;
		}

		$key   = 'onumia_route_' . sha1( $module->name() . $route->path . ':' . $this->client_key() );
		$count = \get_transient( $key );
		$count = is_int( $count ) ? $count : 0;
		if ( $count >= $limit ) {
			return true;
		}

		\set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
	}

	private function route_rate_limit( ModuleDefinition $module, ModulePublicRouteDefinition $route ): int {
		$settings = $this->settings_repository->settings( $module );
		$limits   = $settings['publicRouteRateLimits'] ?? array();
		$override = is_array( $limits ) ? ( $limits[ $route->path ] ?? null ) : null;

		return is_int( $override ) && $override >= 0 ? $override : $route->rate_limit;
	}

	private function client_key(): string {
		$remote_addr = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_UNSAFE_RAW );
		if ( ! is_string( $remote_addr ) ) {
			return 'local';
		}

		// @codeCoverageIgnoreStart
		$remote_addr = \sanitize_text_field( $remote_addr );
		return '' !== $remote_addr ? $remote_addr : 'local';
		// @codeCoverageIgnoreEnd
	}
}
