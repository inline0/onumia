<?php

/**
 * Dispatches declared module data sources.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Core\Errors;

final class ModuleDataSourceDispatcher {
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
	 * @param array<string,mixed> $params Params.
	 * @return mixed
	 */
	public function dispatch( ModuleDefinition $module, string $source_name, array $params = array() ): mixed {
		$source = $module->contract()->data_source( $source_name );
		if ( null === $source ) {
			throw Errors::invariant( "Module {$module->name()} data source {$source_name} is not declared." );
		}

		if ( ! $this->current_user_can( $source->capability ) ) {
			throw Errors::invariant( "Current user cannot read module {$module->name()} data source {$source_name}." );
		}

		$instance = $this->booter->instance( $module );
		$method   = $source->method;
		$params   = $this->validator->normalize_input( $source->inputs, $params, "Module {$module->name()} data source {$source_name}" );

		return 0 === $source->total_parameters ? $instance->$method() : $instance->$method( $params );
	}

	private function current_user_can( string $capability ): bool {
		if ( null !== $this->capability_checker ) {
			return (bool) ( $this->capability_checker )( $capability );
		}

		return ! function_exists( 'current_user_can' ) || \current_user_can( $capability );
	}
}
