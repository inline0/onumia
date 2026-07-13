<?php

/**
 * WordPress hook registration adapter.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class HookRegistrar {
	/** @var callable(string, callable, int, int): void */
	private $action_adder;
	/** @var callable(string, callable, int, int): void */
	private $filter_adder;

	/**
	 * @param callable(string, callable, int, int): void|null $action_adder Action adder.
	 * @param callable(string, callable, int, int): void|null $filter_adder Filter adder.
	 */
	public function __construct( ?callable $action_adder = null, ?callable $filter_adder = null ) {
		$this->action_adder = $action_adder ?? static function ( string $hook, callable $callback, int $priority, int $accepted_args ): void {
			if ( \function_exists( 'add_action' ) ) {
				\add_action( $hook, $callback, $priority, $accepted_args );
			}
		};
		$this->filter_adder = $filter_adder ?? static function ( string $hook, callable $callback, int $priority, int $accepted_args ): void {
			if ( \function_exists( 'add_filter' ) ) {
				\add_filter( $hook, $callback, $priority, $accepted_args );
			}
		};
	}

	public function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		( $this->action_adder )( $hook, $callback, $priority, $accepted_args );
	}

	public function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		( $this->filter_adder )( $hook, $callback, $priority, $accepted_args );
	}
}
