<?php

declare(strict_types=1);

if ( ! function_exists( 'onumia_package_bootstrap' ) ) {
	/**
	 * Establish the packaged flavor and reject renamed side-by-side installs.
	 */
	function onumia_package_bootstrap( string $plugin_file ): bool {
		$root = dirname( $plugin_file );
		$manifest_path = $root . '/package-manifest.json';
		$manifest = null;
		if ( is_file( $manifest_path ) ) {
			$decoded = json_decode( (string) file_get_contents( $manifest_path ), true );
			$manifest = is_array( $decoded ) && ! array_is_list( $decoded ) ? $decoded : null;
		}

		$flavor = is_string( $manifest['flavor'] ?? null )
			? $manifest['flavor']
			: ( is_file( $root . '/src/Pro/Bootstrap.php' ) ? 'pro' : 'free' );
		$target = is_string( $manifest['target'] ?? null ) ? $manifest['target'] : 'onumia-source';

		$expected = is_string( $manifest['plugin']['basename'] ?? null ) ? $manifest['plugin']['basename'] : '';
		$actual = function_exists( 'plugin_basename' )
			? (string) plugin_basename( $plugin_file )
			: basename( $root ) . '/' . basename( $plugin_file );
		if ( '' === $expected || $actual === $expected ) {
			if ( ! defined( 'ONUMIA_PACKAGE_FLAVOR' ) ) {
				define( 'ONUMIA_PACKAGE_FLAVOR', $flavor );
			}
			if ( ! defined( 'ONUMIA_PACKAGE_TARGET' ) ) {
				define( 'ONUMIA_PACKAGE_TARGET', $target );
			}

			return true;
		}

		$message = "Onumia must be installed as {$expected}; {$actual} cannot run beside the canonical plugin.";
		if ( function_exists( 'register_activation_hook' ) ) {
			register_activation_hook(
				$plugin_file,
				static function () use ( $message ): void {
					throw new RuntimeException( $message );
				}
			);
		}
		if ( function_exists( 'add_action' ) ) {
			add_action(
				'admin_init',
				static function () use ( $actual ): void {
					if ( ! function_exists( 'deactivate_plugins' ) ) {
						return;
					}
					$network = function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $actual );
					deactivate_plugins( $actual, true, $network );
				}
			);
		}

		return false;
	}
}
