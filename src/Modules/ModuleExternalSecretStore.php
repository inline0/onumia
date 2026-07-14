<?php

/**
 * Site-scoped external module secret storage.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleExternalSecretStore {
	public const FILE_CONSTANT = 'ONUMIA_MODULE_SITE_SECRETS_FILE';

	private const MAX_FILE_BYTES = 1048576;

	private bool $loaded = false;

	/** @var array<string,array<string,array<string,string>>> */
	private array $sites = array();

	/** @var array{configured:bool,valid:bool,siteId:int,path:?string,error:?string} */
	private array $status = array(
		'configured' => false,
		'valid'      => false,
		'siteId'     => 0,
		'path'       => null,
		'error'      => null,
	);

	public function __construct(
		private readonly ?string $path_override = null,
		private readonly ?int $site_id_override = null,
	) {}

	public function get( string $module, string $name ): ?string {
		$this->load();
		$site_id = (string) $this->status['siteId'];
		$value   = $this->sites[ $site_id ][ $module ][ $name ] ?? null;

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * @return array{configured:bool,valid:bool,siteId:int,path:?string,error:?string}
	 */
	public function status(): array {
		$this->load();

		return $this->status;
	}

	private function load(): void {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded           = true;
		$this->status['siteId'] = $this->site_id();

		$path = $this->path();
		if ( null === $path ) {
			return;
		}
		$this->status['configured'] = true;
		$this->status['path']       = $path;
		clearstatcache( true, $path );

		if ( 0 >= $this->status['siteId'] ) {
			$this->status['error'] = 'site_unavailable';
			return;
		}
		if ( ! str_starts_with( $path, DIRECTORY_SEPARATOR ) ) {
			$this->status['error'] = 'path_not_absolute';
			return;
		}
		if ( is_link( $path ) ) {
			$this->status['error'] = 'symlink_refused';
			return;
		}
		$resolved_path = realpath( $path );
		if ( false === $resolved_path || ! is_file( $resolved_path ) || ! is_readable( $resolved_path ) ) {
			$this->status['error'] = 'file_unreadable';
			return;
		}

		$web_root_path = defined( 'ABSPATH' ) ? constant( 'ABSPATH' ) : null;
		$web_root      = is_string( $web_root_path ) ? realpath( $web_root_path ) : false;
		if ( is_string( $web_root ) && ( $resolved_path === $web_root || str_starts_with( $resolved_path, rtrim( $web_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) ) {
			$this->status['error'] = 'path_inside_web_root';
			return;
		}

		$size = filesize( $resolved_path );
		if ( false === $size || 0 >= $size || self::MAX_FILE_BYTES < $size ) {
			$this->status['error'] = 'file_size_invalid';
			return;
		}

		$permissions = fileperms( $resolved_path );
		if ( false === $permissions || 0 !== ( $permissions & 0o077 ) ) {
			$this->status['error'] = 'file_permissions_unsafe';
			return;
		}

		$contents = (string) file_get_contents( $resolved_path );

		try {
			$data = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			$this->status['error'] = 'json_invalid';
			return;
		}

		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			$this->status['error'] = 'schema_invalid';
			return;
		}
		$sites = $data['sites'] ?? null;
		if ( 1 !== ( $data['schemaVersion'] ?? null ) || ! is_array( $sites ) || array_is_list( $sites ) ) {
			$this->status['error'] = 'schema_invalid';
			return;
		}

		$normalized = $this->normalize_sites( $sites );
		if ( null === $normalized ) {
			$this->status['error'] = 'secrets_invalid';
			return;
		}

		$this->sites           = $normalized;
		$this->status['valid'] = true;
	}

	private function path(): ?string {
		$value = $this->path_override;
		if ( null === $value && defined( self::FILE_CONSTANT ) ) {
			$constant = constant( self::FILE_CONSTANT );
			$value    = is_string( $constant ) ? $constant : null;
		}

		$value = is_string( $value ) ? trim( $value ) : '';

		return '' === $value ? null : $value;
	}

	private function site_id(): int {
		if ( null !== $this->site_id_override ) {
			return $this->site_id_override;
		}

		return function_exists( 'get_current_blog_id' ) ? (int) \get_current_blog_id() : 0;
	}

	/**
	 * @param  array<mixed> $sites Sites.
	 * @return array<string,array<string,array<string,string>>>|null
	 */
	private function normalize_sites( array $sites ): ?array {
		$normalized = array();
		foreach ( $sites as $site_id => $modules ) {
			$site_key = is_int( $site_id ) && 0 < $site_id ? (string) $site_id : $site_id;
			if ( ! is_string( $site_key ) || 1 !== preg_match( '/^[1-9][0-9]*$/', $site_key ) || ! is_array( $modules ) || array_is_list( $modules ) ) {
				return null;
			}

			foreach ( $modules as $module => $secrets ) {
				if ( ! is_string( $module ) || '' === trim( $module ) || ! is_array( $secrets ) || array_is_list( $secrets ) ) {
					return null;
				}

				foreach ( $secrets as $name => $value ) {
					if ( ! is_string( $name ) || '' === trim( $name ) || ! is_string( $value ) || '' === trim( $value ) ) {
						return null;
					}
					$normalized[ $site_key ][ trim( $module ) ][ trim( $name ) ] = $value;
				}
			}
		}

		return $normalized;
	}
}
