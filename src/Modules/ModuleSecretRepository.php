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

	public function set( ModuleDefinition $module, string $name, string $value ): void {
		$all                             = $this->all();
		$all[ $module->name() ][ $name ] = $value;
		\update_option( self::OPTION, $all, false );
	}

	public function get( ModuleDefinition $module, string $name ): ?string {
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

	/**
	 * @return array<string,array{present:bool,label:string,required:bool}>
	 */
	public function status( ModuleDefinition $module ): array {
		$status = array();
		foreach ( $module->advanced()->secrets() as $secret ) {
			$status[ $secret->name ] = array(
				'present'  => null !== $this->get( $module, $secret->name ),
				'label'    => $secret->label,
				'required' => $secret->required,
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
}
