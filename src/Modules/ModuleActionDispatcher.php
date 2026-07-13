<?php

/**
 * Dispatches declared module actions.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Core\Errors;

final class ModuleActionDispatcher {
	/** @var callable(string): bool|null */
	private $capability_checker;

	/**
	 * @param callable(string): bool|null $capability_checker Capability checker.
	 */
	public function __construct(
		private readonly ModuleBooter $booter,
		?callable $capability_checker = null,
		private readonly ModuleValueValidator $validator = new ModuleValueValidator(),
	) {
		$this->capability_checker = $capability_checker;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return mixed
	 */
	public function dispatch( ModuleDefinition $module, string $action_name, array $input = array() ): mixed {
		$action = $module->contract()->action( $action_name );
		if ( null === $action ) {
			throw Errors::invariant( "Module {$module->name()} action {$action_name} is not declared." );
		}

		if ( ! $this->current_user_can( $action->capability ) ) {
			throw Errors::invariant( "Current user cannot run module {$module->name()} action {$action_name}." );
		}

		$instance = $this->booter->instance( $module );
		$method   = $action->method;
		$input    = $this->validator->normalize_input( $action->inputs, $input, "Module {$module->name()} action {$action_name}" );

		return 0 === $action->total_parameters ? $instance->$method() : $instance->$method( $input );
	}

	private function current_user_can( string $capability ): bool {
		if ( null !== $this->capability_checker ) {
			return (bool) ( $this->capability_checker )( $capability );
		}

		return ! function_exists( 'current_user_can' ) || \current_user_can( $capability );
	}
}
