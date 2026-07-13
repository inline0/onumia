<?php

/**
 * Module catalog audit metrics.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Structure\StructureComponentTypes;

final class ModuleCatalogAuditor {
	/**
	 * @return array{total:int,free:int,pro:int,dev:int,releaseDisabled:int,tabSurfaces:int,real:int,placeholders:int,partials:int,placeholderTabs:string[],modules:array<int,array{name:string,tabSurfaces:int,real:int,placeholders:int,partials:int,placeholderTabs:string[],devOnly:bool,pro:bool,releaseEnabled:bool}>}
	 */
	public function audit( ModuleRegistry $registry ): array {
		$result = array(
			'total'           => 0,
			'free'            => 0,
			'pro'             => 0,
			'dev'             => 0,
			'releaseDisabled' => 0,
			'tabSurfaces'     => 0,
			'real'            => 0,
			'placeholders'    => 0,
			'partials'        => 0,
			'placeholderTabs' => array(),
			'modules'         => array(),
		);

		$modules = $registry->all();
		usort( $modules, static fn( ModuleDefinition $left, ModuleDefinition $right ): int => $left->name() <=> $right->name() );

		foreach ( $modules as $module ) {
			++$result['total'];
			$is_pro       = $this->is_pro_module( $module );
			$tab_surfaces = $this->tab_surfaces( $module );
			$placeholder  = $this->is_placeholder( $module );
			$partial      = $this->is_partial( $module );
			$real         = ! $placeholder && ! $partial && $this->is_real( $module );

			if ( $is_pro ) {
				++$result['pro'];
			} else {
				++$result['free'];
			}

			if ( $module->dev_only() ) {
				++$result['dev'];
			}

			if ( ! $module->release_enabled() ) {
				++$result['releaseDisabled'];
			}

			$result['tabSurfaces'] += $tab_surfaces;
			$placeholder_tabs       = $placeholder ? $this->surface_names( $module ) : array();
			if ( $placeholder ) {
				$result['placeholders']   += $tab_surfaces;
				$result['placeholderTabs'] = array_merge( $result['placeholderTabs'], $placeholder_tabs );
			}

			if ( $partial ) {
				$result['partials'] += $tab_surfaces;
			}

			if ( $real ) {
				$result['real'] += $tab_surfaces;
			}

			$result['modules'][] = array(
				'name'            => $module->name(),
				'tabSurfaces'     => $tab_surfaces,
				'real'            => $real ? $tab_surfaces : 0,
				'placeholders'    => $placeholder ? $tab_surfaces : 0,
				'partials'        => $partial ? $tab_surfaces : 0,
				'placeholderTabs' => $placeholder_tabs,
				'devOnly'         => $module->dev_only(),
				'pro'             => $is_pro,
				'releaseEnabled'  => $module->release_enabled(),
			);
		}

		return $result;
	}

	private function is_pro_module( ModuleDefinition $module ): bool {
		return str_contains( $module->directory(), DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Pro' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR );
	}

	private function is_placeholder( ModuleDefinition $module ): bool {
		if ( $module->dev_only() || $this->is_pro_module( $module ) ) {
			return false;
		}

		$contract     = $module->contract();
		$setting_keys = array_keys( $contract->settings() );
		sort( $setting_keys );

		if ( array( 'enabled', 'limit', 'mode', 'rules' ) !== $setting_keys ) {
			return false;
		}

		$actions = array_keys( $contract->actions() );
		sort( $actions );

		return array() === $contract->hooks()
			&& array() === $contract->data_sources()
			&& array() === $contract->entries()
			&& ( array() === $actions || array( 'status' ) === $actions );
	}

	private function is_partial( ModuleDefinition $module ): bool {
		if ( $module->dev_only() || $this->is_pro_module( $module ) || ! $module->release_enabled() ) {
			return false;
		}

		$contract = $module->contract();
		return array() === $contract->actions()
			&& array() === $contract->data_sources()
			&& array() === $contract->hooks()
			&& array() === $contract->entries()
			&& array() === $module->advanced()->tables();
	}

	private function is_real( ModuleDefinition $module ): bool {
		if ( ! $module->release_enabled() ) {
			return false;
		}

		if ( $module->dev_only() || $this->is_pro_module( $module ) ) {
			return true;
		}

		$contract = $module->contract();
		return array() !== $contract->actions()
			|| array() !== $contract->data_sources()
			|| array() !== $contract->hooks()
			|| array() !== $contract->entries()
			|| array() !== $module->advanced()->tables();
	}

	private function tab_surfaces( ModuleDefinition $module ): int {
		return max( 1, count( $this->surface_names( $module ) ) );
	}

	/**
	 * @return string[]
	 */
	private function surface_names( ModuleDefinition $module ): array {
		$surfaces = array();
		$this->collect_tab_surfaces( $module->structure()->data()['views'] ?? array(), $module->name(), $surfaces );

		return array_values( array_unique( $surfaces ) );
	}

	/**
	 * @param mixed    $node     Structure node.
	 * @param string   $prefix   Module prefix.
	 * @param string[] $surfaces Surfaces.
	 */
	private function collect_tab_surfaces( mixed $node, string $prefix, array &$surfaces ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		$type = is_string( $node['type'] ?? null ) ? StructureComponentTypes::canonical( $node['type'] ) : $node['type'] ?? null;
		if ( 'Tabs' === $type && is_array( $node['children'] ?? null ) ) {
			foreach ( $node['children'] as $child ) {
				if ( ! is_array( $child ) ) {
					continue;
				}

				$props = $child['props'] ?? array();
				$value = is_array( $props ) && is_string( $props['value'] ?? null ) ? $props['value'] : '';
				if ( ! $this->has_nested_tabs( $child ) ) {
					$surfaces[] = '' === $value ? $prefix : "{$prefix}:{$value}";
				}
			}
		}

		foreach ( $node as $value ) {
			$this->collect_tab_surfaces( $value, $prefix, $surfaces );
		}
	}

	/**
	 * @param mixed $node Structure node.
	 */
	private function has_nested_tabs( mixed $node ): bool {
		if ( ! is_array( $node ) ) {
			return false;
		}

		$children = $node['children'] ?? array();
		if ( ! is_array( $children ) ) {
			return false;
		}

		foreach ( $children as $child ) {
			$type = is_array( $child ) && is_string( $child['type'] ?? null ) ? StructureComponentTypes::canonical( $child['type'] ) : '';
			if ( 'Tabs' === $type ) {
				return true;
			}
		}

		return false;
	}
}
