<?php

/**
 * Registers module-declared background jobs.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Core\Errors;

final class ModuleJobRegistrar {
	public function __construct(
		private readonly ModuleBooter $booter,
	) {}

	public function register( ModuleRegistry $registry ): void {
		foreach ( $registry->all() as $module ) {
			if ( ! $module->release_enabled() || ! $module->feature_enabled() ) {
				continue;
			}

			foreach ( $module->advanced()->jobs() as $job ) {
				$this->register_job( $module, $job );
			}
		}
	}

	private function register_job( ModuleDefinition $module, ModuleJobDefinition $job ): void {
		if ( ! $job->enabled ) {
			return;
		}

		$hook = $this->hook_name( $module, $job );
		if ( function_exists( 'add_action' ) ) {
			\add_action( $hook, fn(): mixed => $this->run_job( $module, $job ), 10, 0 );
		}

		if (
			function_exists( 'wp_next_scheduled' )
			&& function_exists( 'wp_schedule_event' )
			&& false === \wp_next_scheduled( $hook )
		) {
			\wp_schedule_event( time() + 60, $this->wordpress_schedule( $job->schedule ), $hook );
		}
	}

	private function run_job( ModuleDefinition $module, ModuleJobDefinition $job ): mixed {
		if ( ! $module->feature_enabled() ) {
			return null;
		}

		$instance = $this->booter->instance( $module );
		if ( ! is_callable( array( $instance, $job->handler ) ) ) {
			throw Errors::invariant( "Module {$module->name()} job {$job->name} handler is not callable." );
		}

		return $instance->{$job->handler}();
	}

	private function hook_name( ModuleDefinition $module, ModuleJobDefinition $job ): string {
		return 'onumia_module_job_' . substr( sha1( $module->name() . ':' . $job->name ), 0, 24 );
	}

	private function wordpress_schedule( string $schedule ): string {
		return match ( $schedule ) {
			'twice_daily' => 'twicedaily',
			default => $schedule,
		};
	}
}
