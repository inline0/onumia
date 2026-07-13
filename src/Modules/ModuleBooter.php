<?php

/**
 * Module boot lifecycle.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Core\Errors;

final class ModuleBooter {
	/** @var array<string,Module> */
	private array $instances = array();
	/** @var array<string,bool> */
	private array $booted = array();
	private readonly HookRegistrar $hook_registrar;

	/**
	 * @param callable(string, callable, int, int): void|null $hook_adder Hook adder.
	 */
	public function __construct(
		private readonly ModuleSettingsRepository $settings_repository,
		?callable $hook_adder = null,
	) {
		$this->hook_registrar = new HookRegistrar( $hook_adder );
	}

	public function boot( ModuleDefinition $module ): Module {
		$instance = $this->instance( $module );
		if ( isset( $this->booted[ $module->name() ] ) ) {
			return $instance;
		}

		$instance->boot();
		$this->register_hooks( $module, $instance );
		$this->booted[ $module->name() ] = true;

		return $instance;
	}

	public function instance( ModuleDefinition $module ): Module {
		if ( isset( $this->instances[ $module->name() ] ) ) {
			return $this->instances[ $module->name() ];
		}

		$class_name = $module->contract()->class_name();
		if ( ! class_exists( $class_name, false ) ) {
			ModuleLocalAutoloader::register( $module );
			require_once $module->boot_file();
		}

		if ( ! is_subclass_of( $class_name, Module::class ) ) {
			throw Errors::invariant( "Module {$module->name()} class {$class_name} is not a Onumia module." );
		}

		$instance                           = new $class_name( $module, $this->settings_repository, $this->hook_registrar );
		$this->instances[ $module->name() ] = $instance;
		return $instance;
	}

	public function has_booted( string $module_name ): bool {
		return isset( $this->booted[ $module_name ] );
	}

	private function register_hooks( ModuleDefinition $module, Module $instance ): void {
		foreach ( $module->contract()->hooks() as $hook ) {
			$callback = array( $instance, $hook->method );
			if ( ! is_callable( $callback ) ) {
				throw Errors::invariant( "Module {$module->name()} hook method {$hook->method} is not callable." );
			}

			if ( $hook->is_action() ) {
				$this->hook_registrar->add_action( $hook->hook, $callback, $hook->priority, $hook->accepted_args );
				continue;
			}

			$this->hook_registrar->add_filter( $hook->hook, $callback, $hook->priority, $hook->accepted_args );
		}
	}
}
