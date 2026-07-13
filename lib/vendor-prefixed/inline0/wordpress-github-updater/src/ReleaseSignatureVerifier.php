<?php

declare(strict_types=1);

namespace Onumia\Lib\Inline0\WordPressGitHubUpdater;

/**
 * Verifies the exact selected release before WordPress installs it.
 */
final class ReleaseSignatureVerifier {
	private const MANIFEST_ASSET  = 'SHA256SUMS';
	private const SIGNATURE_ASSET = 'SHA256SUMS.sig';
	private const REQUEST_TIMEOUT = 15;
	private const VERSION_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/';

	private bool $registered = false;

	public function __construct(
		private readonly UpdaterConfig $config,
		private readonly GitHubReleaseUpdater $updater,
	) {}

	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		add_filter( 'upgrader_pre_download', array( $this, 'verify_pre_download' ), 20, 4 );
		$this->registered = true;
	}

	public function reset_for_tests(): void {
		$this->registered = false;
	}

	/**
	 * @param array<string,mixed>|mixed $hook_extra Upgrader hook context.
	 */
	public function verify_pre_download( mixed $reply, string $package = '', mixed $upgrader = null, mixed $hook_extra = null ): mixed {
		unset( $upgrader );
		if ( false !== $reply || ! $this->is_target_update( $hook_extra ) || '' === trim( $package ) ) {
			return $reply;
		}

		return $this->verify_and_stage( $package );
	}

	private function is_target_update( mixed $hook_extra ): bool {
		$plugin   = is_array( $hook_extra ) && is_string( $hook_extra['plugin'] ?? null ) ? $hook_extra['plugin'] : '';
		$basename = $this->updater->plugin_basename();

		return '' !== $plugin && null !== $basename && $plugin === $basename;
	}

	private function verify_and_stage( string $package ): mixed {
		$repository = $this->repository();
		if ( null === $repository ) {
			return $this->abort( "{$this->config->product_name} could not determine its release repository, so the update was not installed." );
		}

		$candidate = $this->candidate_for_package( $package );
		if ( null === $candidate ) {
			return $this->abort( "{$this->config->product_name} could not bind the package to the selected WordPress update candidate, so the update was not installed." );
		}

		$release = $this->fetch_release( $repository, $candidate['tag'] );
		if ( null === $release ) {
			return $this->abort( "{$this->config->product_name} could not load the exact signed release selected by WordPress, so the update was not installed." );
		}

		$assets = $this->index_assets( $release, $repository );
		if ( null === $assets ) {
			return $this->abort( "The selected {$this->config->product_name} release contains ambiguous or invalid assets, so the update was not installed." );
		}

		$package_asset = $this->package_asset( $assets, $candidate );
		if ( null === $package_asset ) {
			return $this->abort( "{$this->config->product_name} could not match the selected WordPress package to the exact signed release, so the update was not installed." );
		}

		$manifest_url  = $this->asset_download_url( $assets, self::MANIFEST_ASSET );
		$signature_url = $this->asset_download_url( $assets, self::SIGNATURE_ASSET );
		if ( null === $manifest_url || null === $signature_url ) {
			return $this->abort( "This {$this->config->product_name} release is not signed (SHA256SUMS / SHA256SUMS.sig are missing), so the update was not installed." );
		}

		$manifest  = $this->fetch_bytes( $manifest_url, $repository );
		$signature = $this->fetch_bytes( $signature_url, $repository );
		if ( null === $manifest || null === $signature ) {
			return $this->abort( "{$this->config->product_name} could not download the release signature, so the update was not installed." );
		}
		if ( ! $this->signature_is_valid( $manifest, $signature ) ) {
			return $this->abort( "The {$this->config->product_name} release signature did not verify against the trusted key, so the update was not installed." );
		}

		$package_bytes = $this->fetch_bytes( $package_asset['download'], $repository );
		if ( null === $package_bytes ) {
			return $this->abort( "{$this->config->product_name} could not download the release package for verification, so the update was not installed." );
		}
		if ( ! $this->checksum_matches( $manifest, $package_asset['name'], $package_bytes ) ) {
			return $this->abort( "The downloaded {$this->config->product_name} package does not match its signed checksum, so the update was not installed." );
		}

		$staged = $this->stage_package( $package_bytes );

		return null !== $staged ? $staged : $this->abort( "{$this->config->product_name} could not stage the verified release package, so the update was not installed." );
	}

	private function signature_is_valid( string $manifest, string $signature_base64 ): bool {
		$public_key = $this->decode_base64( $this->config->resolved_public_key(), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES );
		$signature  = $this->decode_base64( trim( $signature_base64 ), SODIUM_CRYPTO_SIGN_BYTES );
		if ( null === $public_key || null === $signature ) {
			return false;
		}

		return sodium_crypto_sign_verify_detached( $signature, $manifest, $public_key );
	}

	private function checksum_matches( string $manifest, string $asset_name, string $package_bytes ): bool {
		$expected = $this->manifest_hash_for( $manifest, $asset_name );

		return null !== $expected && hash_equals( $expected, hash( 'sha256', $package_bytes ) );
	}

	private function manifest_hash_for( string $manifest, string $asset_name ): ?string {
		$lines = preg_split( '/\R/', $manifest );
		if ( ! is_array( $lines ) ) {
			return null;
		}

		$match = null;
		foreach ( $lines as $line ) {
			$parts = preg_split( '/\s+/', trim( $line ) );
			if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
				continue;
			}
			$hash = strtolower( (string) $parts[0] );
			$name = ltrim( (string) $parts[ count( $parts ) - 1 ], '*' );
			if ( $name === $asset_name && 1 === preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
				if ( null !== $match ) {
					return null;
				}
				$match = $hash;
			}
		}

		return $match;
	}

	private function decode_base64( string $value, int $expected_length ): ?string {
		$decoded = base64_decode( $value, true );

		return false !== $decoded && '' !== $decoded && strlen( $decoded ) === $expected_length ? $decoded : null;
	}

	/**
	 * @return array{owner:string,name:string}|null
	 */
	private function repository(): ?array {
		$parts = wp_parse_url( $this->updater->repository_url() );
		$path  = is_array( $parts ) && is_string( $parts['path'] ?? null ) ? trim( $parts['path'], '/' ) : '';
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || 'github.com' !== ( $parts['host'] ?? '' ) || 1 !== preg_match( '#^(?<owner>[A-Za-z0-9-]+)/(?<name>[A-Za-z0-9_.-]+)$#', $path, $matches ) ) {
			return null;
		}

		return array( 'owner' => $matches['owner'], 'name' => $matches['name'] );
	}

	/**
	 * @param array{owner:string,name:string} $repository Repository identity.
	 * @return array<string,string>
	 */
	private function auth_headers( array $repository ): array {
		$headers = array( 'User-Agent' => str_replace( ' ', '-', $this->config->product_name ) . '-Update-Verifier' );
		$token   = $this->updater->token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $repository['owner'] . ':' . $token );
		}

		return $headers;
	}

	/**
	 * @return array{package:string,version:string,tag:string}|null
	 */
	private function candidate_for_package( string $package ): ?array {
		$basename = $this->updater->plugin_basename();
		$updates  = get_site_transient( 'update_plugins' );
		if ( null === $basename || ! is_object( $updates ) || ! is_array( $updates->response ?? null ) || ! isset( $updates->response[ $basename ] ) ) {
			return null;
		}

		$candidate          = $updates->response[ $basename ];
		$candidate_package  = $this->candidate_field( $candidate, 'package' );
		$candidate_version  = $this->candidate_field( $candidate, 'new_version' );
		$is_newer_candidate = 1 === preg_match( self::VERSION_PATTERN, $candidate_version )
			&& version_compare( $candidate_version, $this->config->current_version, '>' );
		if ( $candidate_package !== $package || ! $is_newer_candidate ) {
			return null;
		}

		return array(
			'package' => $candidate_package,
			'version' => $candidate_version,
			'tag'     => 'v' . $candidate_version,
		);
	}

	private function candidate_field( mixed $candidate, string $field ): string {
		$value = is_object( $candidate ) && isset( $candidate->{$field} )
			? $candidate->{$field}
			: ( is_array( $candidate ) && isset( $candidate[ $field ] ) ? $candidate[ $field ] : '' );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * @param array{owner:string,name:string} $repository Repository identity.
	 * @return array{assets:array<mixed>}|null
	 */
	private function fetch_release( array $repository, string $tag ): ?array {
		$url = sprintf( 'https://api.github.com/repos/%s/%s/releases/tags/%s', $repository['owner'], $repository['name'], rawurlencode( $tag ) );
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array_merge( $this->auth_headers( $repository ), array( 'Accept' => 'application/vnd.github+json' ) ),
				'timeout' => self::REQUEST_TIMEOUT,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || ( $decoded['tag_name'] ?? null ) !== $tag || false !== ( $decoded['draft'] ?? null ) || false !== ( $decoded['prerelease'] ?? null ) || ! is_array( $decoded['assets'] ?? null ) ) {
			return null;
		}

		return array( 'assets' => $decoded['assets'] );
	}

	/**
	 * @param array{assets:array<mixed>}       $release    Release record.
	 * @param array{owner:string,name:string} $repository Repository identity.
	 * @return array<string,array{download:string,urls:list<string>}>|null
	 */
	private function index_assets( array $release, array $repository ): ?array {
		$assets = array();
		foreach ( $release['assets'] as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$name    = is_string( $asset['name'] ?? null ) ? $asset['name'] : '';
			$api     = is_string( $asset['url'] ?? null ) ? $asset['url'] : '';
			$browser = is_string( $asset['browser_download_url'] ?? null ) ? $asset['browser_download_url'] : '';
			if ( '' === $name ) {
				continue;
			}
			if ( isset( $assets[ $name ] ) || ! $this->is_repository_asset_api_url( $api, $repository ) ) {
				return null;
			}
			$assets[ $name ] = array(
				'download' => $api,
				'urls'     => array_values( array_filter( array( $api, $browser ) ) ),
			);
		}

		return $assets;
	}

	/**
	 * @param array<string,array{download:string,urls:list<string>}> $assets Assets.
	 */
	private function asset_download_url( array $assets, string $name ): ?string {
		$download = $assets[ $name ]['download'] ?? '';

		return '' !== $download ? $download : null;
	}

	/**
	 * @param array<string,array{download:string,urls:list<string>}> $assets    Assets.
	 * @param array{package:string,version:string,tag:string}        $candidate Candidate.
	 * @return array{name:string,download:string,urls:list<string>}|null
	 */
	private function package_asset( array $assets, array $candidate ): ?array {
		$matches = array_filter(
			$assets,
			static fn ( array $asset ): bool => in_array( $candidate['package'], $asset['urls'], true )
		);
		if ( 1 !== count( $matches ) ) {
			return null;
		}

		$name  = (string) array_key_first( $matches );
		$asset = $matches[ $name ];
		if ( 1 !== preg_match( $this->updater->asset_regex(), $name ) || 1 !== preg_match( $this->config->asset_version_pattern, $name, $version_match ) || $candidate['version'] !== ( $version_match['version'] ?? null ) ) {
			return null;
		}

		return array( 'name' => $name, 'download' => $asset['download'], 'urls' => $asset['urls'] );
	}

	/**
	 * @param array{owner:string,name:string} $repository Repository identity.
	 */
	private function is_repository_asset_api_url( string $url, array $repository ): bool {
		$parts = wp_parse_url( $url );
		$path  = is_array( $parts ) && is_string( $parts['path'] ?? null ) ? $parts['path'] : '';

		return is_array( $parts )
			&& 'https' === ( $parts['scheme'] ?? '' )
			&& 'api.github.com' === ( $parts['host'] ?? '' )
			&& 1 === preg_match( '#^/repos/' . preg_quote( $repository['owner'], '#' ) . '/' . preg_quote( $repository['name'], '#' ) . '/releases/assets/[0-9]+$#', $path );
	}

	/**
	 * @param array{owner:string,name:string} $repository Repository identity.
	 */
	private function fetch_bytes( string $url, array $repository ): ?string {
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array_merge( $this->auth_headers( $repository ), array( 'Accept' => 'application/octet-stream' ) ),
				'timeout' => self::REQUEST_TIMEOUT,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	private function stage_package( string $bytes ): ?string {
		$path = tempnam( sys_get_temp_dir(), $this->config->temp_prefix );
		if ( false === $path || false === file_put_contents( $path, $bytes ) ) {
			return null;
		}

		return $path;
	}

	private function abort( string $message ): \WP_Error {
		return new \WP_Error( $this->config->error_code, $message );
	}
}
