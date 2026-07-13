<?php

declare(strict_types=1);

namespace Onumia\Lib\Inline0\WordPressGitHubUpdater;

use Closure;
use InvalidArgumentException;

/**
 * Immutable product contract for the shared updater.
 */
final class UpdaterConfig {
	private const VERSION_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/';

	private readonly Closure $repository_url_provider;
	private readonly Closure $asset_regex_provider;
	private readonly Closure $token_provider;
	private readonly Closure $disabled_provider;
	private readonly Closure $public_key_provider;
	private readonly Closure $icon_url_provider;
	private readonly Closure $puc_loader;
	private readonly Closure $plugin_basename_provider;

	/**
	 * @param callable(string):mixed $repository_url_provider Repository filter.
	 * @param callable(string):mixed $asset_regex_provider    Asset pattern filter.
	 * @param callable():mixed       $token_provider          GitHub token provider.
	 * @param callable():mixed       $disabled_provider       Disabled-state provider.
	 * @param callable(string):mixed $public_key_provider     Trusted-key filter.
	 * @param callable(string):mixed $icon_url_provider       Plugin icon resolver.
	 * @param callable():void        $puc_loader              Scoped PUC loader.
	 * @param callable(string):mixed $plugin_basename_provider Plugin basename resolver.
	 */
	public function __construct(
		public readonly string $product_name,
		public readonly string $plugin_slug,
		public readonly string $plugin_file,
		public readonly string $current_version,
		public readonly string $repository_url,
		public readonly string $asset_regex,
		public readonly string $asset_version_pattern,
		public readonly string $public_key,
		public readonly string $error_code,
		public readonly string $temp_prefix,
		callable $repository_url_provider,
		callable $asset_regex_provider,
		callable $token_provider,
		callable $disabled_provider,
		callable $public_key_provider,
		callable $icon_url_provider,
		callable $puc_loader,
		callable $plugin_basename_provider,
	) {
		$this->assert_non_empty( $product_name, 'product_name' );
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]*$/', $plugin_slug ) ) {
			throw new InvalidArgumentException( 'plugin_slug must be a canonical WordPress slug.' );
		}
		if ( 1 !== preg_match( self::VERSION_PATTERN, $current_version ) ) {
			throw new InvalidArgumentException( 'current_version must look like X.Y.Z.' );
		}
		$this->assert_non_empty( $repository_url, 'repository_url' );
		$this->assert_pattern( $asset_regex, 'asset_regex' );
		$this->assert_pattern( $asset_version_pattern, 'asset_version_pattern' );
		$this->assert_non_empty( $public_key, 'public_key' );
		$this->assert_non_empty( $error_code, 'error_code' );
		$this->assert_non_empty( $temp_prefix, 'temp_prefix' );

		$this->repository_url_provider = Closure::fromCallable( $repository_url_provider );
		$this->asset_regex_provider    = Closure::fromCallable( $asset_regex_provider );
		$this->token_provider          = Closure::fromCallable( $token_provider );
		$this->disabled_provider       = Closure::fromCallable( $disabled_provider );
		$this->public_key_provider     = Closure::fromCallable( $public_key_provider );
		$this->icon_url_provider       = Closure::fromCallable( $icon_url_provider );
		$this->puc_loader              = Closure::fromCallable( $puc_loader );
		$this->plugin_basename_provider = Closure::fromCallable( $plugin_basename_provider );
	}

	public function resolved_repository_url(): string {
		$value = ( $this->repository_url_provider )( $this->repository_url );

		return is_scalar( $value ) && '' !== trim( (string) $value ) ? trim( (string) $value ) : $this->repository_url;
	}

	public function resolved_asset_regex(): string {
		$value = ( $this->asset_regex_provider )( $this->asset_regex );
		$value = is_scalar( $value ) ? (string) $value : $this->asset_regex;

		return false !== @preg_match( $value, '' ) ? $value : $this->asset_regex;
	}

	public function token(): string {
		$value = ( $this->token_provider )();

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	public function disabled(): bool {
		return (bool) ( $this->disabled_provider )();
	}

	public function resolved_public_key(): string {
		$value = ( $this->public_key_provider )( $this->public_key );

		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : $this->public_key;
	}

	public function icon_url( string $file ): ?string {
		$value = ( $this->icon_url_provider )( $file );

		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
	}

	public function load_puc(): void {
		( $this->puc_loader )();
	}

	public function plugin_basename(): ?string {
		$value = ( $this->plugin_basename_provider )( $this->plugin_file );

		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
	}

	public function puc_class( string $suffix ): string {
		return ltrim( $suffix, '\\' );
	}

	private function assert_non_empty( string $value, string $field ): void {
		if ( '' === trim( $value ) ) {
			throw new InvalidArgumentException( "{$field} must not be empty." );
		}
	}

	private function assert_pattern( string $pattern, string $field ): void {
		if ( '' === $pattern || false === @preg_match( $pattern, '' ) ) {
			throw new InvalidArgumentException( "{$field} must be a valid regular expression." );
		}
	}
}
