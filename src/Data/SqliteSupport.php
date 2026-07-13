<?php

/**
 * SQLite runtime support checks.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

use Onumia\PublicApi\Filters;

final class SqliteSupport {
	public function available(): bool {
		return $this->pdo_sqlite_available() || $this->sqlite3_available();
	}

	public function pdo_sqlite_available(): bool {
		$available = class_exists( 'PDO' ) && extension_loaded( 'pdo_sqlite' );

		return Filters::sqlite_available( $available );
	}

	public function sqlite3_available(): bool {
		$available = class_exists( 'SQLite3' );

		return Filters::sqlite_available( $available );
	}

	public function register_debug_information(): void {
		if ( function_exists( 'add_filter' ) ) {
			\add_filter( 'debug_information', array( $this, 'debug_information' ) );
		}
	}

	/**
	 * @param array<string,mixed> $debug_info Debug info.
	 * @return array<string,mixed>
	 */
	public function debug_information( array $debug_info ): array {
		$paths      = new SqlitePathResolver();
		$resolver   = new ModuleStorageResolver( $paths, $this );
		$resolution = $resolver->resolve();
		$marker     = $resolver->marker();

		$debug_info['onumia_storage'] = array(
			'label'  => 'Onumia Storage',
			'fields' => array(
				'engine'           => array(
					'label' => 'Active engine',
					'value' => $resolution->engine,
				),
				'reason'           => array(
					'label' => 'Resolution reason',
					'value' => $resolution->reason,
				),
				'data_directory'   => array(
					'label' => 'Data directory',
					'value' => $paths->base_directory(),
				),
				'marker_timestamp' => array(
					'label' => 'Marker timestamp',
					'value' => is_scalar( $marker['resolved_at'] ?? null ) ? (string) $marker['resolved_at'] : '',
				),
				'forced_override'  => array(
					'label' => 'Forced override',
					'value' => $resolution->forced ? 'yes' : 'no',
				),
			),
		);

		return $debug_info;
	}
}
