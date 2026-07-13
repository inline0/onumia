<?php

/**
 * Dev-only REST helpers for browser tests.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Rest;

final class DevTestSupportRoutes {

	private const NAMESPACE     = 'onumia/v1';
	private const SETTINGS_FILE = 'onumia.settings.json';

	public static function register_if_enabled(): void {
		if ( ! self::enabled() ) {
			return;
		}

		\register_rest_route(
			self::NAMESPACE,
			'/dev/preset-settings',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'preset_settings' ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/dev/reset-settings',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'reset_settings' ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);
	}

	public static function enabled(): bool {
		$constant_enabled = defined( 'ONUMIA_E2E' ) && self::truthy( ONUMIA_E2E );
		$filtered         = \apply_filters( 'onumia_dev_test_routes_enabled', $constant_enabled );

		return true === $filtered;
	}

	private static function truthy( mixed $value ): bool {
		if ( true === $value || 1 === $value ) {
			return true;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return false;
	}

	public static function can_manage_onumia(): bool {
		return \current_user_can( 'manage_options' );
	}

	public static function preset_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_json_params();
		if ( ! is_array( $params['settings'] ?? null ) ) {
			return new \WP_Error( 'onumia_invalid_settings', 'Expected a settings object.', array( 'status' => 400 ) );
		}

		$file = self::settings_file();
		if ( null === $file ) {
			return new \WP_Error( 'onumia_settings_file_unavailable', 'Onumia settings file is unavailable.', array( 'status' => 500 ) );
		}

		$result = self::merge_settings_file( $file, self::normalize_module_settings( $params['settings'] ) );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'modules' => $result,
			),
			200
		);
	}

	public static function reset_settings(): \WP_REST_Response|\WP_Error {
		$file = self::settings_file();
		if ( null === $file ) {
			return new \WP_Error( 'onumia_settings_file_unavailable', 'Onumia settings file is unavailable.', array( 'status' => 500 ) );
		}

		if ( is_file( $file ) && ! @unlink( $file ) ) {
			return new \WP_Error( 'onumia_settings_reset_failed', "Could not delete Onumia settings file {$file}.", array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'reset' => true ), 200 );
	}

	/**
	 * @param  array<mixed,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	private static function normalize_module_settings( array $settings ): array {
		$normalized = array();
		foreach ( $settings as $module => $module_settings ) {
			if ( is_string( $module ) ) {
				$normalized[ $module ] = $module_settings;
			}
		}

		return $normalized;
	}

	/**
	 * @param array<string,mixed> $incoming Incoming settings.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function merge_settings_file( string $file, array $incoming ): array|\WP_Error {
		$directory = dirname( $file );
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			return new \WP_Error( 'onumia_settings_directory_failed', "Could not create Onumia settings directory {$directory}.", array( 'status' => 500 ) );
		}

		$handle = @fopen( $file, 'c+' );
		if ( ! is_resource( $handle ) ) {
			return new \WP_Error( 'onumia_settings_file_failed', "Could not open Onumia settings file {$file}.", array( 'status' => 500 ) );
		}

		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				// @codeCoverageIgnoreStart
				return new \WP_Error( 'onumia_settings_lock_failed', "Could not lock Onumia settings file {$file}.", array( 'status' => 500 ) );
				// @codeCoverageIgnoreEnd
			}

			rewind( $handle );
			$contents = stream_get_contents( $handle );
			$existing = self::module_settings_from_json( is_string( $contents ) ? $contents : '' );
			$settings = array_replace( $existing, $incoming );
			$json     = self::settings_json( $settings );
			if ( $json instanceof \WP_Error ) {
				return $json;
			}

			rewind( $handle );
			if ( ! ftruncate( $handle, 0 ) || false === fwrite( $handle, $json . "\n" ) || ! fflush( $handle ) ) {
				// @codeCoverageIgnoreStart
				return new \WP_Error( 'onumia_settings_write_failed', "Could not write Onumia settings file {$file}.", array( 'status' => 500 ) );
				// @codeCoverageIgnoreEnd
			}

			return $settings;
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function module_settings_from_json( string $contents ): array {
		if ( '' === trim( $contents ) ) {
			return array();
		}

		$decoded = json_decode( $contents, true );
		if ( ! is_array( $decoded ) || ! is_array( $decoded['modules'] ?? null ) ) {
			return array();
		}

		return self::normalize_module_settings( $decoded['modules'] );
	}

	/**
	 * @param array<string,mixed> $settings Settings.
	 */
	private static function settings_json( array $settings ): string|\WP_Error {
		$json = json_encode(
			array( 'modules' => $settings ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $json ) ) {
			return new \WP_Error( 'onumia_settings_encode_failed', 'Could not encode Onumia settings.', array( 'status' => 500 ) );
		}

		return $json;
	}

	private static function settings_file(): ?string {
		$directory = \get_stylesheet_directory();
		if ( '' === $directory ) {
			return null;
		}

		$file = rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . self::SETTINGS_FILE;
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = \apply_filters( 'onumia_settings_file', $file );
			$file     = is_string( $filtered ) ? $filtered : $file;
		}

		return '' === trim( $file ) ? null : $file;
	}
}
