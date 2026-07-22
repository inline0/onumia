<?php

/**
 * Onumia GitHub Releases updater.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Updates;

use Onumia\Lib\Inline0\WordPressGitHubUpdater\GitHubReleaseUpdater as SharedUpdater;
use Onumia\Lib\Inline0\WordPressGitHubUpdater\ReleaseSignatureVerifier;
use Onumia\Lib\Inline0\WordPressGitHubUpdater\UpdaterConfig;
use Onumia\PublicApi\Filters;

/**
 * Configures the shared signed GitHub updater for Onumia.
 */
final class GitHubReleaseUpdater {
	public const REPOSITORY_URL = 'https://github.com/inline0/onumia/';
	public const PLUGIN_SLUG    = 'onumia';
	public const ASSET_REGEX    = '/^onumia-v?[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?\.zip$/';

	private const ASSET_VERSION_PATTERN = '/^onumia-v?(?<version>[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?)\.zip$/';

	private ?SharedUpdater $updater = null;

	public function __construct(
		private readonly string $plugin_file,
		private readonly string $version,
	) {}

	public function register(): ?object {
		return $this->updater()->register();
	}

	public function registered_checker(): ?object {
		return null === $this->updater ? null : $this->updater->registered_checker();
	}

	public function repository_url(): string {
		return $this->updater()->repository_url();
	}

	public function token(): string {
		return $this->updater()->token();
	}

	public function asset_regex(): string {
		return $this->updater()->asset_regex();
	}

	public function plugin_basename(): ?string {
		return $this->updater()->plugin_basename();
	}

	public function verifier(): ReleaseSignatureVerifier {
		return $this->updater()->verifier();
	}

	/**
	 * @internal Test-only seam for checker contract failures.
	 *
	 * @param callable(string,string,string):object|null $factory Checker factory.
	 */
	public function set_checker_factory_for_tests( ?callable $factory ): void {
		$this->updater()->set_checker_factory_for_tests( $factory );
	}

	/**
	 * @internal Test-only helper for isolated updater assertions.
	 */
	public function reset_for_tests(): void {
		if ( null !== $this->updater ) {
			$this->updater->reset_for_tests();
		}
		$this->updater = null;
	}

	private function updater(): SharedUpdater {
		if ( null !== $this->updater ) {
			return $this->updater;
		}

		$this->updater = new SharedUpdater(
			new UpdaterConfig(
				product_name: 'Onumia',
				plugin_slug: self::PLUGIN_SLUG,
				plugin_file: $this->plugin_file,
				current_version: $this->normalized_version(),
				repository_url: self::REPOSITORY_URL,
				asset_regex: self::ASSET_REGEX,
				asset_version_pattern: self::ASSET_VERSION_PATTERN,
				public_key: $this->bundled_public_key(),
				error_code: 'onumia_update_signature',
				temp_prefix: 'onumia-update-',
				repository_url_provider: static fn( string $value ): mixed => Filters::github_updater_repository_url( $value ),
				asset_regex_provider: static fn( string $value ): mixed => Filters::github_updater_asset_regex( $value ),
				token_provider: static fn(): mixed => Filters::github_updater_token( self::constant_string( 'ONUMIA_GITHUB_UPDATER_TOKEN' ) ),
				disabled_provider: static fn(): bool => self::is_disabled(),
				public_key_provider: static fn( string $value ): mixed => Filters::github_updater_public_key( $value ),
				icon_url_provider: fn( string $file ): ?string => $this->icon_url( $file ),
				puc_loader: function (): void {
					$this->load_scoped_plugin_update_checker();
				},
				plugin_basename_provider: fn( string $file ): string => $this->resolve_plugin_basename( $file ),
			)
		);

		return $this->updater;
	}

	private static function is_disabled(): bool {
		$disabled = self::constant_bool( 'ONUMIA_GITHUB_UPDATER_DISABLED' );

		return (bool) Filters::github_updater_disabled( $disabled );
	}

	private function normalized_version(): string {
		return 1 === preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', $this->version ) ? $this->version : '0.0.0';
	}

	private function bundled_public_key(): string {
		$contents = file_get_contents( __DIR__ . '/onumia-release.ed25519.pub' );

		return is_string( $contents ) ? trim( $contents ) : '';
	}

	private function resolve_plugin_basename( string $file ): string {
		if ( function_exists( 'plugin_basename' ) ) {
			return plugin_basename( $file );
		}

		$normalized = str_replace( '\\', '/', $file );
		$marker = '/wp-content/plugins/';
		$position = strpos( $normalized, $marker );
		if ( false !== $position ) {
			return ltrim( substr( $normalized, $position + strlen( $marker ) ), '/' );
		}

		return basename( dirname( $normalized ) ) . '/' . basename( $normalized );
	}

	private function load_scoped_plugin_update_checker(): void {
		$loader = dirname( __DIR__, 2 ) . '/lib/vendor-prefixed/yahnis-elsts/plugin-update-checker/load-v5p7.php';
		if ( is_readable( $loader ) ) {
			include_once $loader;
		}

		$directory = dirname( __DIR__, 2 ) . '/lib/vendor-prefixed/yahnis-elsts/plugin-update-checker/vendor';
		$dependencies = array(
			'Parsedown.php'       => array( '\\Parsedown', '\\Onumia\\Lib\\Parsedown' ),
			'PucReadmeParser.php' => array( '\\PucReadmeParser', '\\Onumia\\Lib\\PucReadmeParser' ),
		);
		foreach ( $dependencies as $file => $classes ) {
			$loaded = false;
			foreach ( $classes as $class ) {
				if ( class_exists( $class, false ) ) {
					$loaded = true;
					break;
				}
			}
			$path = $directory . '/' . $file;
			if ( ! $loaded && is_readable( $path ) ) {
				include_once $path;
			}
		}
	}

	private function icon_url( string $file ): ?string {
		$relative_path = 'assets/brand/' . ltrim( $file, '/\\' );
		$url = plugins_url( $relative_path, $this->plugin_file );

		return is_string( $url ) && '' !== $url ? $url : null;
	}

	private static function constant_string( string $name ): string {
		if ( ! defined( $name ) ) {
			return '';
		}

		$value = constant( $name );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private static function constant_bool( string $name ): bool {
		return defined( $name ) && (bool) constant( $name );
	}
}
