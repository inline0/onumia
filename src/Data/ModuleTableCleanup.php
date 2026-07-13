<?php

/**
 * Scheduled cleanup for bounded module tables.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleRegistry;

final class ModuleTableCleanup {
	public const HOOK = 'onumia_tables_cleanup';

	private const VERSION_OPTION = 'onumia_tables_cleanup_version';

	public function __construct(
		private readonly ModuleTableStore $store = new ModuleTableStore(),
	) {}

	public function register( ModuleRegistry $registry, string $version ): void {
		if ( function_exists( 'add_action' ) ) {
			\add_action( self::HOOK, fn(): int => $this->run( $registry ) );
		}

		$this->schedule();
		if ( ! function_exists( 'get_option' ) || \get_option( self::VERSION_OPTION, '' ) !== $version ) {
			$this->run( $registry );
			if ( function_exists( 'update_option' ) ) {
				\update_option( self::VERSION_OPTION, $version, false );
			}
		}
	}

	public function schedule(): void {
		if ( false === \wp_next_scheduled( self::HOOK ) ) {
			\wp_schedule_event( time() + 60, 'twicedaily', self::HOOK );
		}
	}

	public function run( ModuleRegistry $registry ): int {
		$removed = 0;
		foreach ( $registry->all() as $module ) {
			if ( ! $this->module_is_installable( $module ) ) {
				continue;
			}

			foreach ( $module->advanced()->tables() as $table ) {
				if ( null === $table->retention_days ) {
					continue;
				}

				$removed += $this->store->handle( $module, $table )->purge( $table->retention_days );
			}
		}

		return $removed;
	}

	private function module_is_installable( ModuleDefinition $module ): bool {
		return $module->release_enabled() && $module->feature_enabled();
	}
}
