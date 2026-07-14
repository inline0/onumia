<?php

/**
 * Module secret persistence.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleSecretRepository {
	private const OPTION = 'onumia_module_secrets';
	private ModuleExternalSecretStore $external;

	public function __construct( ?ModuleExternalSecretStore $external = null ) {
		$this->external = $external ?? new ModuleExternalSecretStore();
	}

	public function set( ModuleDefinition $module, string $name, string $value ): void {
		$external_status = $this->external->status();
		if ( $external_status['configured'] ) {
			$this->require_valid_external_store( $external_status );
			$external = $this->external->get( $module->name(), $name );
			if ( null === $external ) {
				throw new \RuntimeException( 'Externally managed module secrets cannot be created through WordPress.' );
			}
			if ( ! hash_equals( $external, $value ) ) {
				throw new \RuntimeException( 'Externally managed module secrets cannot be replaced through WordPress.' );
			}
			$this->remove_stored( $module, $name );
			return;
		}

		$all                             = $this->all();
		$all[ $module->name() ][ $name ] = $value;
		\update_option( self::OPTION, $all, false );
	}

	public function get( ModuleDefinition $module, string $name ): ?string {
		$external_status = $this->external->status();
		if ( $external_status['configured'] ) {
			$this->require_valid_external_store( $external_status );

			return $this->external->get( $module->name(), $name );
		}

		$secret = $module->advanced()->secrets()[ $name ] ?? null;
		if ( null !== $secret && null !== $secret->constant && defined( $secret->constant ) ) {
			$value = constant( $secret->constant );
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		$value = $this->all()[ $module->name() ][ $name ] ?? null;
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	public function source( ModuleDefinition $module, string $name ): string {
		$external_status = $this->external->status();
		if ( $external_status['configured'] ) {
			if ( ! $external_status['valid'] ) {
				return 'external-invalid';
			}

			return null === $this->external->get( $module->name(), $name ) ? 'missing' : 'external';
		}

		$secret = $module->advanced()->secrets()[ $name ] ?? null;
		if ( null !== $secret && null !== $secret->constant && defined( $secret->constant ) ) {
			$value = constant( $secret->constant );
			if ( is_string( $value ) && '' !== $value ) {
				return 'constant';
			}
		}

		$value = $this->all()[ $module->name() ][ $name ] ?? null;

		return is_string( $value ) && '' !== $value ? 'option' : 'missing';
	}

	public function fingerprint( ModuleDefinition $module, string $name ): ?string {
		$value = $this->get( $module, $name );

		return null === $value ? null : substr( hash( 'sha256', $value ), 0, 12 );
	}

	public function stored( ModuleDefinition $module, string $name ): bool {
		$value = $this->all()[ $module->name() ][ $name ] ?? null;

		return is_string( $value ) && '' !== $value;
	}

	public function remove_stored( ModuleDefinition $module, string $name ): void {
		$all = $this->all();
		unset( $all[ $module->name() ][ $name ] );
		if ( array() === ( $all[ $module->name() ] ?? array() ) ) {
			unset( $all[ $module->name() ] );
		}
		\update_option( self::OPTION, $all, false );
	}

	/**
	 * @return array{configured:bool,valid:bool,siteId:int,path:?string,error:?string}
	 */
	public function external_status(): array {
		return $this->external->status();
	}

	/**
	 * @return array<string,array{present:bool,label:string,required:bool,source:string}>
	 */
	public function status( ModuleDefinition $module ): array {
		$status = array();
		foreach ( $module->advanced()->secrets() as $secret ) {
			$status[ $secret->name ] = array(
				'present'  => null !== $this->get( $module, $secret->name ),
				'label'    => $secret->label,
				'required' => $secret->required,
				'source'   => $this->source( $module, $secret->name ),
			);
		}

		return $status;
	}

	/**
	 * @return array<string,array<string,string>>
	 */
	private function all(): array {
		$value = \get_option( self::OPTION, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$all = array();
		foreach ( $value as $module => $secrets ) {
			if ( ! is_string( $module ) || ! is_array( $secrets ) ) {
				continue;
			}

			foreach ( $secrets as $name => $secret ) {
				if ( is_string( $name ) && is_string( $secret ) ) {
					$all[ $module ][ $name ] = $secret;
				}
			}
		}

		return $all;
	}

	/**
	 * @param array{configured:bool,valid:bool,siteId:int,path:?string,error:?string} $status External store status.
	 */
	private function require_valid_external_store( array $status ): void {
		if ( ! $status['valid'] ) {
			throw new \RuntimeException( 'The configured external module secret store is invalid.' );
		}
	}
}
