<?php

/**
 * Lightweight definition validators.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Schema;

use Onumia\Core\Errors;
use Onumia\Structure\StructureComponentTypes;

final class SchemaValidator {
	private const NAME_PATTERN            = '/^[a-z0-9][a-z0-9-]*(?:\/[a-z0-9][a-z0-9-]*)+$/';
	private const DASH_PATTERN            = '/^[a-z0-9][a-z0-9-]*$/';
	private const COMPONENT_NAME_PATTERN  = '/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/';
	private const DEFINITION_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_.-]*$/';
	private const STATE_PATH_PATTERN      = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';
	private const SOURCE_PATTERN          = '/^[a-z][A-Za-z0-9]*(?:\.[A-Za-z0-9][A-Za-z0-9]*)+$/';
	private const REF_PATTERN             = '/^(settings|state|input|context|data|event|params|form|forms|collection|computed|route|item|row|selection)\.[A-Za-z0-9_.-]+$/';
	private const CSS_LENGTH_PATTERN      = '/^(\d+|\d*\.\d+)(px|rem|em|ch|%)$/';
	private const CONDITION_KEYS          = array( 'visibleWhen', 'enabledWhen', 'requiredWhen', 'readOnlyWhen', 'validateWhen', 'optionsWhen', 'when', 'refreshWhen', 'emptyWhen', 'dirtyWhen', 'touchedWhen' );
	private const GRANULARITIES           = array( 'day', 'hour', 'minute', 'second' );
	private const TIME_GRANULARITIES      = array( 'hour', 'minute', 'second' );
	private const CODE_EDITOR_LANGUAGES   = array( 'css', 'html', 'javascript', 'js', 'json', 'jsx', 'php', 'text', 'ts', 'tsx', 'typescript' );
	private const CHART_TYPES             = array( 'area', 'bar', 'donut', 'line', 'pie', 'radar', 'radial' );
	private const TABLE_COLUMN_TYPES      = array( 'badge', 'boolean', 'json', 'number', 'text' );
	private const TABLE_FILTER_TYPES      = array( 'boolean', 'multiOption', 'number', 'option', 'text' );
	private const TABLE_FILTER_STRATEGIES = array( 'client', 'server' );
	private const DRAWER_SIDES            = array( 'bottom', 'left', 'right', 'top' );
	private const DRAWER_SIZES            = array( 'sm', 'md', 'lg', 'xl', 'full' );
	private const ENTRY_DRAWER_LAYOUTS    = array( 'auto', 'sections', 'tabs' );
	private const CHART_BOOLEAN_PROPS     = array( 'axis', 'grid', 'legend', 'stacked', 'tooltip' );
	private const CHART_NUMBER_PROPS      = array( 'height', 'innerRadius', 'outerRadius' );
	private const CHART_STRING_PROPS      = array( 'xKey', 'nameKey', 'dataKey' );
	private const EFFECT_TYPES            = array( 'setState', 'setSetting', 'setData', 'resetSettings', 'event', 'action', 'source', 'navigate', 'setParam', 'clearParam', 'validateForm', 'submitForm', 'resetForm', 'setFormValue', 'setCollectionSelection' );
	private const EFFECT_MODES            = array( 'replace', 'push', 'merge', 'append', 'prepend' );
	private const EFFECT_STRING_KEYS      = array( 'action', 'source', 'form', 'collection', 'target', 'mode', 'url' );
	private const CONDITION_OPS           = array(
		'and',
		'or',
		'not',
		'equals',
		'notEquals',
		'in',
		'notIn',
		'contains',
		'notContains',
		'empty',
		'notEmpty',
		'greaterThan',
		'greaterThanOrEqual',
		'lessThan',
		'lessThanOrEqual',
		'matches',
		'hasCapability',
		'hasRole',
		'sourceAvailable',
		'settingEnabled',
	);

	/**
	 * @param array<string,mixed> $meta Metadata.
	 */
	public function validate_meta( array $meta, string $file ): void {
		$this->required_pattern( $meta, 'name', self::NAME_PATTERN, $file );
		$this->required_pattern( $meta, 'category', self::DASH_PATTERN, $file );
		$this->required_string( $meta, 'label', $file );
		$this->required_string( $meta, 'version', $file );

		if ( isset( $meta['description'] ) && ! is_string( $meta['description'] ) ) {
			throw Errors::invariant( "Module meta {$file} description must be a string." );
		}

		$tags = $meta['tags'] ?? array();
		if ( ! is_array( $tags ) ) {
			throw Errors::invariant( "Module meta {$file} tags must be an array." );
		}

		$seen = array();
		foreach ( $tags as $tag ) {
			if ( ! is_string( $tag ) || ! preg_match( self::DASH_PATTERN, $tag ) ) {
				throw Errors::invariant( "Module meta {$file} tags must be lowercase dash-case strings." );
			}

			if ( isset( $seen[ $tag ] ) ) {
				throw Errors::invariant( "Module meta {$file} contains duplicate tag {$tag}." );
			}
			$seen[ $tag ] = true;
		}

		if ( isset( $meta['devOnly'] ) && ! is_bool( $meta['devOnly'] ) ) {
			throw Errors::invariant( "Module meta {$file} devOnly must be a boolean." );
		}

		if ( isset( $meta['releaseEnabled'] ) && ! is_bool( $meta['releaseEnabled'] ) ) {
			throw Errors::invariant( "Module meta {$file} releaseEnabled must be a boolean." );
		}

		if ( isset( $meta['releaseReason'] ) && ! is_string( $meta['releaseReason'] ) ) {
			throw Errors::invariant( "Module meta {$file} releaseReason must be a string." );
		}

		$allowed = array( '$schema', 'name', 'category', 'tags', 'label', 'description', 'version', 'devOnly', 'releaseEnabled', 'releaseReason' );
		$this->assert_allowed_keys( $meta, $allowed, "Module meta {$file}" );
	}

	/**
	 * @param array<string,mixed> $structure Structure data.
	 */
	public function validate_structure( array $structure, string $file ): void {
		$this->assert_allowed_keys( $structure, array( '$schema', 'access', 'initialState', 'params', 'forms', 'data', 'computed', 'collections', 'components', 'views', 'states' ), "Structure {$file}" );
		$this->validate_access_policy( $structure['access'] ?? null, $file );

		$initial_state = $this->required_string( $structure, 'initialState', $file );
		foreach ( array( 'params', 'forms', 'data', 'computed', 'collections' ) as $definition_key ) {
			$this->validate_definition_map( $structure[ $definition_key ] ?? null, $definition_key, $file );
		}

		if ( ! isset( $structure['views'] ) || ! is_array( $structure['views'] ) || array() === $structure['views'] || array_is_list( $structure['views'] ) ) {
			throw Errors::invariant( "Structure {$file} must define views." );
		}

		if ( ! isset( $structure['states'] ) || ! is_array( $structure['states'] ) || array() === $structure['states'] || ! array_is_list( $structure['states'] ) ) {
			throw Errors::invariant( "Structure {$file} must define states." );
		}

		$view_names = array();
		foreach ( $structure['views'] as $name => $view ) {
			if ( ! is_string( $name ) || '' === $name ) {
				throw Errors::invariant( "Structure {$file} view names must be strings." );
			}

			$view_names[ $name ] = true;
			$this->validate_structure_view( $view, $name, $file );
		}

		$state_names = array();
		foreach ( $structure['states'] as $state ) {
			$state_name                 = $this->validate_structure_state( $state, $view_names, $file );
			$state_names[ $state_name ] = true;
		}

		foreach ( $structure['states'] as $state ) {
			$this->validate_state_transitions( $state, $state_names, $file );
		}

		if ( ! isset( $state_names[ $initial_state ] ) ) {
			throw Errors::invariant( "Structure {$file} initialState must reference a state." );
		}

		if ( isset( $structure['components'] ) ) {
			if ( ! is_array( $structure['components'] ) || array_is_list( $structure['components'] ) ) {
				throw Errors::invariant( "Structure {$file} components must be an object." );
			}

			foreach ( $structure['components'] as $name => $definition ) {
				if ( ! is_string( $name ) || ! preg_match( self::COMPONENT_NAME_PATTERN, $name ) ) {
					throw Errors::invariant( "Structure {$file} component names are invalid." );
				}

				if ( ! is_array( $definition ) ) {
					throw Errors::invariant( "Structure {$file} component {$name} must be an object." );
				}

				$this->validate_component_definition( $this->string_keyed_array( $definition, "Structure {$file} component {$name}" ), "{$file} component {$name}", false );
			}
		}
	}

	private function validate_access_policy( mixed $policy, string $file ): void {
		if ( null === $policy ) {
			return;
		}

		if ( ! is_array( $policy ) || ( array() !== $policy && array_is_list( $policy ) ) ) {
			throw Errors::invariant( "Structure {$file} access must be an object." );
		}

		$policy = $this->string_keyed_array( $policy, "Structure {$file} access" );
		$this->assert_allowed_keys( $policy, array( 'roles', 'userIds', 'capabilities' ), "Structure {$file} access" );

		foreach ( array( 'roles', 'capabilities' ) as $key ) {
			if ( ! array_key_exists( $key, $policy ) ) {
				continue;
			}

			if ( ! is_array( $policy[ $key ] ) || ! array_is_list( $policy[ $key ] ) ) {
				throw Errors::invariant( "Structure {$file} access.{$key} must be a string list." );
			}

			foreach ( $policy[ $key ] as $item ) {
				if ( ! is_string( $item ) || '' === trim( $item ) ) {
					throw Errors::invariant( "Structure {$file} access.{$key} must be a string list." );
				}
			}
		}

		if ( array_key_exists( 'userIds', $policy ) ) {
			if ( ! is_array( $policy['userIds'] ) || ! array_is_list( $policy['userIds'] ) ) {
				throw Errors::invariant( "Structure {$file} access.userIds must be a positive integer list." );
			}

			foreach ( $policy['userIds'] as $user_id ) {
				if ( ! is_int( $user_id ) || $user_id <= 0 ) {
					throw Errors::invariant( "Structure {$file} access.userIds must be a positive integer list." );
				}
			}
		}
	}

	/**
	 * @param array<string,mixed> $component Component definition.
	 */
	public function validate_component_definition( array $component, string $file, bool $standalone = true ): void {
		if ( $standalone ) {
			$this->required_pattern( $component, 'name', self::COMPONENT_NAME_PATTERN, $file );
		} elseif ( isset( $component['name'] ) && ( ! is_string( $component['name'] ) || ! preg_match( self::COMPONENT_NAME_PATTERN, $component['name'] ) ) ) {
			throw Errors::invariant( "Component {$file} has invalid name." );
		}

		$this->required_string( $component, 'label', $file );

		if ( isset( $component['description'] ) && ! is_string( $component['description'] ) ) {
			throw Errors::invariant( "Component {$file} description must be a string." );
		}

		if ( ! is_array( $component['component'] ?? null ) ) {
			throw Errors::invariant( "Component {$file} must define component." );
		}

		$allowed = array( '$schema', 'name', 'label', 'description', 'props', 'slots', 'component' );
		$this->assert_allowed_keys( $component, $allowed, "Component {$file}" );
		$this->validate_component_node( $component['component'], "{$file} component" );
	}

	/**
	 * @param array<array-key,mixed> $messages Messages.
	 */
	public function validate_messages( array $messages, string $file ): void {
		$this->validate_message_node( $messages, $file );
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private function required_string( array $data, string $key, string $file ): string {
		$value = $data[ $key ] ?? null;
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw Errors::invariant( "{$file} must define a non-empty {$key} string." );
		}

		return $value;
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private function required_pattern( array $data, string $key, string $pattern, string $file ): string {
		$value = $this->required_string( $data, $key, $file );
		if ( ! preg_match( $pattern, $value ) ) {
			throw Errors::invariant( "{$file} has invalid {$key}." );
		}

		return $value;
	}

	/**
	 * @param array<string,mixed> $data Data.
	 * @param string[]            $allowed Allowed keys.
	 */
	private function assert_allowed_keys( array $data, array $allowed, string $label ): void {
		$allowed_map = array_fill_keys( $allowed, true );
		foreach ( array_keys( $data ) as $key ) {
			if ( ! isset( $allowed_map[ (string) $key ] ) ) {
				throw Errors::invariant( "{$label} key {$key} is not supported." );
			}
		}
	}

	/**
	 * @param array<array-key,mixed> $node Node.
	 */
	private function validate_structure_node( array $node, string $file ): void {
		$type = is_string( $node['type'] ?? null ) ? StructureComponentTypes::canonical( $node['type'] ) : $node['type'] ?? null;

		if ( is_array( $node['props'] ?? null ) ) {
			$props = $node['props'];
			foreach ( array( 'multiple', 'allowCustom', 'required', 'persist', 'replace', 'enabled' ) as $key ) {
				if ( array_key_exists( $key, $props ) && ! is_bool( $props[ $key ] ) ) {
					throw Errors::invariant( "Structure {$file} props.{$key} must be a boolean." );
				}
			}

			$allowed_granularities = 'TimeField' === $type ? self::TIME_GRANULARITIES : self::GRANULARITIES;
			if ( array_key_exists( 'granularity', $props ) && ( ! is_string( $props['granularity'] ) || ! in_array( $props['granularity'], $allowed_granularities, true ) ) ) {
				throw Errors::invariant( 'Structure ' . $file . ' props.granularity must be one of ' . implode( ', ', $allowed_granularities ) . '.' );
			}

			if ( array_key_exists( 'language', $props ) && ( ! is_string( $props['language'] ) || ! in_array( $props['language'], self::CODE_EDITOR_LANGUAGES, true ) ) ) {
				throw Errors::invariant( "Structure {$file} props.language must be a supported code editor language." );
			}

			if ( array_key_exists( 'minColumnWidth', $props ) && ! $this->is_css_length( $props['minColumnWidth'] ) ) {
				throw Errors::invariant( "Structure {$file} props.minColumnWidth must be a positive CSS length." );
			}

			foreach ( array( 'columnSpan', 'gridColumns', 'rowSpan' ) as $key ) {
				if ( array_key_exists( $key, $props ) && ( ! is_int( $props[ $key ] ) || $props[ $key ] < 1 || $props[ $key ] > 12 ) ) {
					throw Errors::invariant( "Structure {$file} props.{$key} must be an integer between 1 and 12." );
				}
			}

			if ( 'Chart' === $type ) {
				$this->validate_chart_props( $props, $file );
			}

			if ( 'Table' === $type ) {
				$this->validate_table_props( $props, $file );
			}

			if ( 'Entries' === $type ) {
				$this->validate_entries_props( $props, $file );
			}

			if ( 'DynamicTabs' === $type ) {
				$this->validate_dynamic_tabs_props( $props, $file );
			}
		}

		if ( isset( $node['ref'] ) ) {
			if ( ! is_string( $node['ref'] ) || ! preg_match( self::REF_PATTERN, $node['ref'] ) ) {
				throw Errors::invariant( "Structure {$file} has invalid ref." );
			}
		}

		if ( isset( $node['optionsSource'] ) ) {
			$this->validate_options_source( $node['optionsSource'], $file );
		}

		foreach ( self::CONDITION_KEYS as $key ) {
			if ( array_key_exists( $key, $node ) ) {
				$this->validate_condition_container( $node[ $key ], $file, $key );
			}
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$this->validate_structure_node( $value, $file );
			}
		}
	}

	private function validate_options_source( mixed $source, string $file ): void {
		if ( ! is_array( $source ) || ! is_string( $source['source'] ?? null ) || ! preg_match( self::SOURCE_PATTERN, $source['source'] ) ) {
			throw Errors::invariant( "Structure {$file} optionsSource must define a dot-notation source." );
		}

		if ( isset( $source['params'] ) && ! is_array( $source['params'] ) ) {
			throw Errors::invariant( "Structure {$file} optionsSource params must be an object." );
		}
	}

	private function is_css_length( mixed $value ): bool {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value > 0;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( self::CSS_LENGTH_PATTERN, $value, $matches ) ) {
			return false;
		}

		return (float) $matches[1] > 0;
	}

	/**
	 * @param array<array-key,mixed> $props Props.
	 */
	private function validate_chart_props( array $props, string $file ): void {
		if ( array_key_exists( 'chartType', $props ) && ( ! is_string( $props['chartType'] ) || ! in_array( $props['chartType'], self::CHART_TYPES, true ) ) ) {
			throw Errors::invariant( "Structure {$file} Chart props.chartType must be a supported chart type." );
		}

		foreach ( self::CHART_BOOLEAN_PROPS as $key ) {
			if ( array_key_exists( $key, $props ) && ! is_bool( $props[ $key ] ) ) {
				throw Errors::invariant( "Structure {$file} Chart props.{$key} must be a boolean." );
			}
		}

		foreach ( self::CHART_NUMBER_PROPS as $key ) {
			if ( ! array_key_exists( $key, $props ) ) {
				continue;
			}

			$value = $props[ $key ];
			if ( ( ! is_int( $value ) && ! is_float( $value ) ) || $value <= 0 ) {
				throw Errors::invariant( "Structure {$file} Chart props.{$key} must be a positive number." );
			}
		}

		foreach ( self::CHART_STRING_PROPS as $key ) {
			if ( array_key_exists( $key, $props ) && ( ! is_string( $props[ $key ] ) || '' === trim( $props[ $key ] ) ) ) {
				throw Errors::invariant( "Structure {$file} Chart props.{$key} must be a non-empty string." );
			}
		}

		if ( array_key_exists( 'data', $props ) ) {
			if ( ! is_array( $props['data'] ) || ! array_is_list( $props['data'] ) ) {
				throw Errors::invariant( "Structure {$file} Chart props.data must be an array." );
			}

			foreach ( $props['data'] as $row ) {
				if ( ! is_array( $row ) || array_is_list( $row ) ) {
					throw Errors::invariant( "Structure {$file} Chart props.data rows must be objects." );
				}
			}
		}

		if ( array_key_exists( 'series', $props ) ) {
			if ( ! is_array( $props['series'] ) || ! array_is_list( $props['series'] ) ) {
				throw Errors::invariant( "Structure {$file} Chart props.series must be an array." );
			}

			foreach ( $props['series'] as $series ) {
				$this->validate_chart_series( $series, $file );
			}
		}
	}

	private function validate_chart_series( mixed $series, string $file ): void {
		if ( ! is_array( $series ) || array_is_list( $series ) ) {
			throw Errors::invariant( "Structure {$file} Chart props.series items must be objects." );
		}

		$series = $this->string_keyed_array( $series, "Structure {$file} Chart props.series item" );
		$this->assert_allowed_keys( $series, array( 'key', 'dataKey', 'label', 'color' ), "Structure {$file} Chart props.series item" );

		$has_key      = isset( $series['key'] );
		$has_data_key = isset( $series['dataKey'] );
		if ( $has_key === $has_data_key ) {
			throw Errors::invariant( "Structure {$file} Chart props.series item must define exactly one of key or dataKey." );
		}

		foreach ( array( 'key', 'dataKey', 'label', 'color' ) as $key ) {
			if ( isset( $series[ $key ] ) && ( ! is_string( $series[ $key ] ) || '' === trim( $series[ $key ] ) ) ) {
				throw Errors::invariant( "Structure {$file} Chart props.series item {$key} must be a non-empty string." );
			}
		}
	}

	/**
	 * @param array<array-key,mixed> $props Props.
	 */
	private function validate_table_props( array $props, string $file ): void {
		if ( array_key_exists( 'search', $props ) ) {
			$this->validate_table_search( $props['search'], $file );
		}

		if ( array_key_exists( 'selection', $props ) ) {
			$this->validate_table_selection( $props['selection'], $file );
		}

		if ( array_key_exists( 'filters', $props ) ) {
			$this->validate_table_filters( $props['filters'], $file );
		}

		if ( array_key_exists( 'pagination', $props ) ) {
			$this->validate_table_pagination( $props['pagination'], $file );
		}

		if ( array_key_exists( 'sorting', $props ) ) {
			$this->validate_table_sorting( $props['sorting'], $file );
		}

		if ( array_key_exists( 'columns', $props ) ) {
			if ( ! is_array( $props['columns'] ) || ! array_is_list( $props['columns'] ) ) {
				throw Errors::invariant( "Structure {$file} Table props.columns must be an array." );
			}

			foreach ( $props['columns'] as $column ) {
				$this->validate_table_column( $column, $file );
			}
		}
	}

	private function validate_table_search( mixed $search, string $file ): void {
		if ( is_bool( $search ) ) {
			return;
		}

		if ( ! is_array( $search ) || array_is_list( $search ) ) {
			throw Errors::invariant( "Structure {$file} Table props.search must be a boolean or object." );
		}

		$search = $this->string_keyed_array( $search, "Structure {$file} Table props.search" );
		$this->assert_allowed_keys( $search, array( 'enabled', 'label', 'placeholder' ), "Structure {$file} Table props.search" );

		if ( isset( $search['enabled'] ) && ! is_bool( $search['enabled'] ) ) {
			throw Errors::invariant( "Structure {$file} Table props.search.enabled must be a boolean." );
		}

		foreach ( array( 'label', 'placeholder' ) as $key ) {
			if ( isset( $search[ $key ] ) && ( ! is_string( $search[ $key ] ) || '' === trim( $search[ $key ] ) ) ) {
				throw Errors::invariant( "Structure {$file} Table props.search.{$key} must be a non-empty string." );
			}
		}
	}

	private function validate_table_selection( mixed $selection, string $file ): void {
		if ( is_bool( $selection ) ) {
			return;
		}

		if ( ! is_array( $selection ) || array_is_list( $selection ) ) {
			throw Errors::invariant( "Structure {$file} Table props.selection must be a boolean or object." );
		}

		$selection = $this->string_keyed_array( $selection, "Structure {$file} Table props.selection" );
		$this->assert_allowed_keys( $selection, array( 'enabled', 'selectAllLabel' ), "Structure {$file} Table props.selection" );

		if ( isset( $selection['enabled'] ) && ! is_bool( $selection['enabled'] ) ) {
			throw Errors::invariant( "Structure {$file} Table props.selection.enabled must be a boolean." );
		}

		if ( isset( $selection['selectAllLabel'] ) && ( ! is_string( $selection['selectAllLabel'] ) || '' === trim( $selection['selectAllLabel'] ) ) ) {
			throw Errors::invariant( "Structure {$file} Table props.selection.selectAllLabel must be a non-empty string." );
		}
	}

	private function validate_table_filters( mixed $filters, string $file ): void {
		if ( is_bool( $filters ) ) {
			return;
		}

		if ( ! is_array( $filters ) || array_is_list( $filters ) ) {
			throw Errors::invariant( "Structure {$file} Table props.filters must be a boolean or object." );
		}

		$filters = $this->string_keyed_array( $filters, "Structure {$file} Table props.filters" );
		$this->assert_allowed_keys( $filters, array( 'enabled', 'strategy', 'addLabel', 'clearAllLabel', 'columns' ), "Structure {$file} Table props.filters" );

		if ( isset( $filters['enabled'] ) && ! is_bool( $filters['enabled'] ) ) {
			throw Errors::invariant( "Structure {$file} Table props.filters.enabled must be a boolean." );
		}

		if ( isset( $filters['strategy'] ) && ( ! is_string( $filters['strategy'] ) || ! in_array( $filters['strategy'], self::TABLE_FILTER_STRATEGIES, true ) ) ) {
			throw Errors::invariant( "Structure {$file} Table props.filters.strategy must be client or server." );
		}

		foreach ( array( 'addLabel', 'clearAllLabel' ) as $key ) {
			if ( isset( $filters[ $key ] ) && ( ! is_string( $filters[ $key ] ) || '' === trim( $filters[ $key ] ) ) ) {
				throw Errors::invariant( "Structure {$file} Table props.filters.{$key} must be a non-empty string." );
			}
		}

		if ( isset( $filters['columns'] ) ) {
			if ( ! is_array( $filters['columns'] ) || ! array_is_list( $filters['columns'] ) ) {
				throw Errors::invariant( "Structure {$file} Table props.filters.columns must be an array." );
			}

			foreach ( $filters['columns'] as $column ) {
				$this->validate_table_filter_column( $column, $file );
			}
		}
	}

	private function validate_table_pagination( mixed $pagination, string $file ): void {
		if ( is_bool( $pagination ) ) {
			return;
		}

		if ( ! is_array( $pagination ) || array_is_list( $pagination ) ) {
			throw Errors::invariant( "Structure {$file} Table props.pagination must be a boolean or object." );
		}

		$pagination = $this->string_keyed_array( $pagination, "Structure {$file} Table props.pagination" );
		$this->assert_allowed_keys( $pagination, array( 'enabled', 'pageSize', 'pageSizeOptions', 'strategy' ), "Structure {$file} Table props.pagination" );

		if ( isset( $pagination['enabled'] ) && ! is_bool( $pagination['enabled'] ) ) {
			throw Errors::invariant( "Structure {$file} Table props.pagination.enabled must be a boolean." );
		}

		if ( isset( $pagination['strategy'] ) && ( ! is_string( $pagination['strategy'] ) || ! in_array( $pagination['strategy'], self::TABLE_FILTER_STRATEGIES, true ) ) ) {
			throw Errors::invariant( "Structure {$file} Table props.pagination.strategy must be client or server." );
		}

		if ( isset( $pagination['pageSize'] ) && ( ! is_int( $pagination['pageSize'] ) || $pagination['pageSize'] < 1 ) ) {
			throw Errors::invariant( "Structure {$file} Table props.pagination.pageSize must be a positive integer." );
		}

		if ( isset( $pagination['pageSizeOptions'] ) ) {
			if ( ! is_array( $pagination['pageSizeOptions'] ) || ! array_is_list( $pagination['pageSizeOptions'] ) || array() === $pagination['pageSizeOptions'] ) {
				throw Errors::invariant( "Structure {$file} Table props.pagination.pageSizeOptions must be a non-empty positive integer list." );
			}

			foreach ( $pagination['pageSizeOptions'] as $page_size ) {
				if ( ! is_int( $page_size ) || $page_size < 1 ) {
					throw Errors::invariant( "Structure {$file} Table props.pagination.pageSizeOptions must be a non-empty positive integer list." );
				}
			}
		}
	}

	private function validate_table_sorting( mixed $sorting, string $file ): void {
		if ( ! is_array( $sorting ) || array() === $sorting ) {
			throw Errors::invariant( "Structure {$file} Table props.sorting must be an object or list." );
		}

		if ( array_is_list( $sorting ) ) {
			foreach ( $sorting as $sort ) {
				$this->validate_table_sort( $sort, $file, 'Table props.sorting item' );
			}
			return;
		}

		$sorting = $this->string_keyed_array( $sorting, "Structure {$file} Table props.sorting" );
		$this->assert_allowed_keys( $sorting, array( 'default', 'initial' ), "Structure {$file} Table props.sorting" );

		foreach ( array( 'default', 'initial' ) as $key ) {
			if ( ! isset( $sorting[ $key ] ) ) {
				continue;
			}

			$value = $sorting[ $key ];
			if ( is_array( $value ) && array_is_list( $value ) ) {
				if ( array() === $value ) {
					throw Errors::invariant( "Structure {$file} Table props.sorting.{$key} must be a sort object or non-empty list." );
				}

				foreach ( $value as $sort ) {
					$this->validate_table_sort( $sort, $file, "Table props.sorting.{$key} item" );
				}
				continue;
			}

			$this->validate_table_sort( $value, $file, "Table props.sorting.{$key}" );
		}
	}

	private function validate_table_sort( mixed $sort, string $file, string $label ): void {
		if ( ! is_array( $sort ) || array_is_list( $sort ) ) {
			throw Errors::invariant( "Structure {$file} {$label} must be an object." );
		}

		$sort = $this->string_keyed_array( $sort, "Structure {$file} {$label}" );
		$this->assert_allowed_keys( $sort, array( 'key', 'id', 'column', 'direction', 'order' ), "Structure {$file} {$label}" );
		$this->assert_exactly_one_key( $sort, array( 'key', 'id', 'column' ), "Structure {$file} {$label}" );

		foreach ( array( 'key', 'id', 'column' ) as $key ) {
			if ( isset( $sort[ $key ] ) && ( ! is_string( $sort[ $key ] ) || '' === trim( $sort[ $key ] ) ) ) {
				throw Errors::invariant( "Structure {$file} {$label} {$key} must be a non-empty string." );
			}
		}

		foreach ( array( 'direction', 'order' ) as $key ) {
			if ( isset( $sort[ $key ] ) && ( ! is_string( $sort[ $key ] ) || ! in_array( $sort[ $key ], array( 'asc', 'desc' ), true ) ) ) {
				throw Errors::invariant( "Structure {$file} {$label} {$key} must be asc or desc." );
			}
		}
	}

	private function validate_table_column( mixed $column, string $file ): void {
		if ( ! is_array( $column ) || array_is_list( $column ) ) {
			throw Errors::invariant( "Structure {$file} Table props.columns items must be objects." );
		}

		$column = $this->string_keyed_array( $column, "Structure {$file} Table props.columns item" );
		$this->assert_allowed_keys( $column, array( 'key', 'accessorKey', 'label', 'type', 'sortable', 'searchable', 'align', 'width' ), "Structure {$file} Table props.columns item" );
		$this->assert_exactly_one_key( $column, array( 'key', 'accessorKey' ), "Structure {$file} Table props.columns item" );

		foreach ( array( 'key', 'accessorKey', 'label' ) as $key ) {
			if ( isset( $column[ $key ] ) && ( ! is_string( $column[ $key ] ) || '' === trim( $column[ $key ] ) ) ) {
				throw Errors::invariant( "Structure {$file} Table props.columns item {$key} must be a non-empty string." );
			}
		}

		if ( isset( $column['type'] ) && ( ! is_string( $column['type'] ) || ! in_array( $column['type'], self::TABLE_COLUMN_TYPES, true ) ) ) {
			throw Errors::invariant( "Structure {$file} Table props.columns item type is not supported." );
		}

		foreach ( array( 'sortable', 'searchable' ) as $key ) {
			if ( isset( $column[ $key ] ) && ! is_bool( $column[ $key ] ) ) {
				throw Errors::invariant( "Structure {$file} Table props.columns item {$key} must be a boolean." );
			}
		}

		if ( isset( $column['align'] ) && ! in_array( $column['align'], array( 'center', 'end', 'start' ), true ) ) {
			throw Errors::invariant( "Structure {$file} Table props.columns item align is not supported." );
		}

		if ( isset( $column['width'] ) && ( ( ! is_int( $column['width'] ) && ! is_float( $column['width'] ) ) || $column['width'] <= 0 ) ) {
			throw Errors::invariant( "Structure {$file} Table props.columns item width must be a positive number." );
		}
	}

	private function validate_table_filter_column( mixed $column, string $file ): void {
		if ( ! is_array( $column ) || array_is_list( $column ) ) {
			throw Errors::invariant( "Structure {$file} Table props.filters.columns items must be objects." );
		}

		$column = $this->string_keyed_array( $column, "Structure {$file} Table props.filters.columns item" );
		$this->assert_allowed_keys( $column, array( 'key', 'id', 'accessorKey', 'label', 'type', 'options', 'optionsSource', 'inferOptions' ), "Structure {$file} Table props.filters.columns item" );
		$this->assert_exactly_one_key( $column, array( 'key', 'id', 'accessorKey' ), "Structure {$file} Table props.filters.columns item" );

		foreach ( array( 'key', 'id', 'accessorKey', 'label' ) as $key ) {
			if ( isset( $column[ $key ] ) && ( ! is_string( $column[ $key ] ) || '' === trim( $column[ $key ] ) ) ) {
				throw Errors::invariant( "Structure {$file} Table props.filters.columns item {$key} must be a non-empty string." );
			}
		}

		if ( isset( $column['type'] ) && ( ! is_string( $column['type'] ) || ! in_array( $column['type'], self::TABLE_FILTER_TYPES, true ) ) ) {
			throw Errors::invariant( "Structure {$file} Table props.filters.columns item type is not supported." );
		}

		if ( isset( $column['inferOptions'] ) && ! is_bool( $column['inferOptions'] ) ) {
			throw Errors::invariant( "Structure {$file} Table props.filters.columns item inferOptions must be a boolean." );
		}

		if ( isset( $column['optionsSource'] ) ) {
			$this->validate_options_source( $column['optionsSource'], $file );
		}

		if ( isset( $column['options'] ) ) {
			if ( ! is_array( $column['options'] ) || ! array_is_list( $column['options'] ) ) {
				throw Errors::invariant( "Structure {$file} Table props.filters.columns item options must be an array." );
			}

			foreach ( $column['options'] as $option ) {
				$this->validate_table_filter_option( $option, $file );
			}
		}
	}

	private function validate_table_filter_option( mixed $option, string $file ): void {
		if ( ! is_array( $option ) || array_is_list( $option ) ) {
			throw Errors::invariant( "Structure {$file} Table props.filters.columns item options must contain objects." );
		}

		$option = $this->string_keyed_array( $option, "Structure {$file} Table filter option" );
		$this->assert_allowed_keys( $option, array( 'value', 'label', 'description', 'disabled', 'indent' ), "Structure {$file} Table filter option" );

		if ( ! isset( $option['value'] ) || ! is_string( $option['value'] ) || '' === trim( $option['value'] ) ) {
			throw Errors::invariant( "Structure {$file} Table filter option value must be a non-empty string." );
		}

		if ( ! isset( $option['label'] ) || ! is_string( $option['label'] ) || '' === trim( $option['label'] ) ) {
			throw Errors::invariant( "Structure {$file} Table filter option label must be a non-empty string." );
		}
	}

	/**
	 * @param array<array-key,mixed> $props Props.
	 */
	private function validate_entries_props( array $props, string $file ): void {
		foreach ( array( 'name', 'entry' ) as $key ) {
			if ( ! is_string( $props[ $key ] ?? null ) || '' === trim( $props[ $key ] ) ) {
				throw Errors::invariant( "Structure {$file} Entries props.{$key} must be a non-empty string." );
			}
		}

		foreach ( array( 'label', 'description', 'newLabel', 'emptyMessage' ) as $key ) {
			if ( isset( $props[ $key ] ) && ( ! is_string( $props[ $key ] ) || '' === trim( $props[ $key ] ) ) ) {
				throw Errors::invariant( "Structure {$file} Entries props.{$key} must be a non-empty string." );
			}
		}

		foreach ( array( 'create', 'edit', 'search' ) as $key ) {
			if ( isset( $props[ $key ] ) && ! is_bool( $props[ $key ] ) && ! is_array( $props[ $key ] ) ) {
				throw Errors::invariant( "Structure {$file} Entries props.{$key} must be a boolean or object." );
			}
		}

		if ( isset( $props['orderable'] ) && ! is_bool( $props['orderable'] ) ) {
			throw Errors::invariant( "Structure {$file} Entries props.orderable must be a boolean." );
		}

		if ( isset( $props['search'] ) ) {
			$this->validate_table_search( $props['search'], $file );
		}

		foreach ( array( 'createDrawer', 'editDrawer' ) as $key ) {
			if ( isset( $props[ $key ] ) ) {
				$this->validate_entry_drawer_props( $props[ $key ], $file, $key );
			}
		}

		if ( isset( $props['delete'] ) ) {
			$this->validate_entry_delete_props( $props['delete'], $file );
		}

		if ( isset( $props['filters'] ) ) {
			$this->validate_table_filters( $props['filters'], $file );
		}

		if ( isset( $props['pagination'] ) ) {
			$this->validate_table_pagination( $props['pagination'], $file );
		}

		if ( isset( $props['sorting'] ) ) {
			$this->validate_table_sorting( $props['sorting'], $file );
		}

		if ( isset( $props['columns'] ) ) {
			if ( ! is_array( $props['columns'] ) || ! array_is_list( $props['columns'] ) ) {
				throw Errors::invariant( "Structure {$file} Entries props.columns must be an array." );
			}

			foreach ( $props['columns'] as $column ) {
				$this->validate_table_column( $column, $file );
			}
		}
	}

	private function validate_entry_drawer_props( mixed $drawer, string $file, string $key ): void {
		if ( ! is_array( $drawer ) || array_is_list( $drawer ) ) {
			throw Errors::invariant( "Structure {$file} Entries props.{$key} must be an object." );
		}

		$drawer = $this->string_keyed_array( $drawer, "Structure {$file} Entries props.{$key}" );
		$this->assert_allowed_keys( $drawer, array( 'side', 'size', 'description', 'layout' ), "Structure {$file} Entries props.{$key}" );

		if ( isset( $drawer['side'] ) && ( ! is_string( $drawer['side'] ) || ! in_array( $drawer['side'], self::DRAWER_SIDES, true ) ) ) {
			throw Errors::invariant( "Structure {$file} Entries props.{$key}.side must be one of " . implode( ', ', self::DRAWER_SIDES ) . '.' );
		}

		if ( isset( $drawer['size'] ) && ( ! is_string( $drawer['size'] ) || ! in_array( $drawer['size'], self::DRAWER_SIZES, true ) ) ) {
			throw Errors::invariant( "Structure {$file} Entries props.{$key}.size must be one of " . implode( ', ', self::DRAWER_SIZES ) . '.' );
		}

		if ( isset( $drawer['layout'] ) && ( ! is_string( $drawer['layout'] ) || ! in_array( $drawer['layout'], self::ENTRY_DRAWER_LAYOUTS, true ) ) ) {
			throw Errors::invariant( "Structure {$file} Entries props.{$key}.layout must be auto, sections, or tabs." );
		}

		if ( isset( $drawer['description'] ) && ( ! is_string( $drawer['description'] ) || '' === trim( $drawer['description'] ) ) ) {
			throw Errors::invariant( "Structure {$file} Entries props.{$key}.description must be a non-empty string." );
		}
	}

	private function validate_entry_delete_props( mixed $delete, string $file ): void {
		if ( is_bool( $delete ) ) {
			return;
		}

		if ( ! is_array( $delete ) || array_is_list( $delete ) ) {
			throw Errors::invariant( "Structure {$file} Entries props.delete must be a boolean or object." );
		}

		$delete = $this->string_keyed_array( $delete, "Structure {$file} Entries props.delete" );
		$this->assert_allowed_keys( $delete, array( 'enabled', 'label', 'confirmTitle', 'confirmDescription', 'confirmLabel', 'cancelLabel' ), "Structure {$file} Entries props.delete" );

		if ( isset( $delete['enabled'] ) && ! is_bool( $delete['enabled'] ) ) {
			throw Errors::invariant( "Structure {$file} Entries props.delete.enabled must be a boolean." );
		}

		foreach ( array( 'label', 'confirmTitle', 'confirmDescription', 'confirmLabel', 'cancelLabel' ) as $key ) {
			if ( isset( $delete[ $key ] ) && ( ! is_string( $delete[ $key ] ) || '' === trim( $delete[ $key ] ) ) ) {
				throw Errors::invariant( "Structure {$file} Entries props.delete.{$key} must be a non-empty string." );
			}
		}
	}

	/**
	 * @param array<array-key,mixed> $props Props.
	 */
	private function validate_dynamic_tabs_props( array $props, string $file ): void {
		if ( ! array_key_exists( 'items', $props ) && ! array_key_exists( 'source', $props ) ) {
			throw Errors::invariant( "Structure {$file} DynamicTabs props.items must define a collection binding." );
		}

		foreach ( array( 'itemKey', 'itemLabel' ) as $key ) {
			if ( isset( $props[ $key ] ) && ( ! is_string( $props[ $key ] ) || '' === trim( $props[ $key ] ) ) ) {
				throw Errors::invariant( "Structure {$file} DynamicTabs props.{$key} must be a non-empty string." );
			}
		}

		if ( isset( $props['template'] ) ) {
			if ( ! is_array( $props['template'] ) || array_is_list( $props['template'] ) ) {
				throw Errors::invariant( "Structure {$file} DynamicTabs props.template must be a component object." );
			}

			$this->validate_component_node( $props['template'], "{$file} DynamicTabs template" );
		}
	}

	private function validate_definition_map( mixed $definitions, string $key, string $file ): void {
		if ( null === $definitions ) {
			return;
		}

		if ( ! is_array( $definitions ) || array_is_list( $definitions ) ) {
			throw Errors::invariant( "Structure {$file} {$key} must be an object." );
		}

		foreach ( $definitions as $name => $definition ) {
			if ( ! is_string( $name ) || ! preg_match( self::DEFINITION_NAME_PATTERN, $name ) ) {
				throw Errors::invariant( "Structure {$file} {$key} names are invalid." );
			}

			if ( ! is_array( $definition ) || array_is_list( $definition ) ) {
				throw Errors::invariant( "Structure {$file} {$key}.{$name} must be an object." );
			}

			$this->validate_structure_node( $definition, "{$file} {$key}.{$name}" );
		}
	}

	private function validate_structure_view( mixed $view, string $name, string $file ): void {
		if ( ! is_array( $view ) || array_is_list( $view ) ) {
			throw Errors::invariant( "Structure {$file} view {$name} must be an object." );
		}

		$view = $this->string_keyed_array( $view, "Structure {$file} view {$name}" );
		$this->assert_allowed_keys( $view, array( 'label', 'description', 'component' ), "Structure {$file} view {$name}" );

		if ( isset( $view['label'] ) && ! is_string( $view['label'] ) ) {
			throw Errors::invariant( "Structure {$file} view {$name} label must be a string." );
		}

		if ( isset( $view['description'] ) && ! is_string( $view['description'] ) ) {
			throw Errors::invariant( "Structure {$file} view {$name} description must be a string." );
		}

		if ( ! is_array( $view['component'] ?? null ) ) {
			throw Errors::invariant( "Structure {$file} view {$name} must define component." );
		}

		$this->validate_component_node( $view['component'], "{$file} view {$name}" );
	}

	/**
	 * @param array<string,true> $view_names View names.
	 */
	private function validate_structure_state( mixed $state, array $view_names, string $file ): string {
		if ( ! is_array( $state ) || array_is_list( $state ) ) {
			throw Errors::invariant( "Structure {$file} states must define objects." );
		}

		$state = $this->string_keyed_array( $state, "Structure {$file} state" );
		$this->assert_allowed_keys( $state, array( 'name', 'label', 'path', 'view', 'params', 'form', 'data', 'transitions' ), "Structure {$file} state" );

		if ( ! is_string( $state['name'] ?? null ) || '' === $state['name'] ) {
			throw Errors::invariant( "Structure {$file} states must define names." );
		}

		if ( isset( $state['label'] ) && ! is_string( $state['label'] ) ) {
			throw Errors::invariant( "Structure {$file} state {$state['name']} label must be a string." );
		}

		if ( isset( $state['path'] ) && ( ! is_string( $state['path'] ) || ! preg_match( self::STATE_PATH_PATTERN, $state['path'] ) ) ) {
			throw Errors::invariant( "Structure {$file} state {$state['name']} has invalid path." );
		}

		if ( ! is_string( $state['view'] ?? null ) || '' === $state['view'] ) {
			throw Errors::invariant( "Structure {$file} state {$state['name']} must reference a view." );
		}

		if ( ! isset( $view_names[ $state['view'] ] ) ) {
			throw Errors::invariant( "Structure {$file} state {$state['name']} must reference an existing view." );
		}

		return $state['name'];
	}

	/**
	 * @param array<string,true> $state_names State names.
	 */
	private function validate_state_transitions( mixed $state, array $state_names, string $file ): void {
		if ( ! is_array( $state ) || ! isset( $state['transitions'] ) ) {
			return;
		}

		if ( ! is_array( $state['transitions'] ) || ! array_is_list( $state['transitions'] ) ) {
			throw Errors::invariant( "Structure {$file} state transitions must be an array." );
		}

		foreach ( $state['transitions'] as $transition ) {
			$this->validate_transition( $transition, $state_names, $file );
		}
	}

	/**
	 * @param array<string,true> $state_names State names.
	 */
	private function validate_transition( mixed $transition, array $state_names, string $file ): void {
		if ( ! is_array( $transition ) || array_is_list( $transition ) ) {
			throw Errors::invariant( "Structure {$file} transition must be an object." );
		}

		$transition = $this->string_keyed_array( $transition, "Structure {$file} transition" );
		$this->assert_allowed_keys( $transition, array( 'event', 'target', 'label', 'description', 'guards', 'loadingTarget', 'successTarget', 'errorTarget', 'effects' ), "Structure {$file} transition" );

		if ( ! is_string( $transition['event'] ?? null ) || '' === $transition['event'] ) {
			throw Errors::invariant( "Structure {$file} transition must define event." );
		}

		if ( ! is_string( $transition['target'] ?? null ) || '' === $transition['target'] ) {
			throw Errors::invariant( "Structure {$file} transition must define target." );
		}

		$this->validate_transition_target( $transition, 'target', $state_names, $file );
		$this->validate_transition_target( $transition, 'loadingTarget', $state_names, $file );
		$this->validate_transition_target( $transition, 'successTarget', $state_names, $file );
		$this->validate_transition_target( $transition, 'errorTarget', $state_names, $file );

		if ( isset( $transition['label'] ) && ! is_string( $transition['label'] ) ) {
			throw Errors::invariant( "Structure {$file} transition label must be a string." );
		}

		if ( isset( $transition['description'] ) && ! is_string( $transition['description'] ) ) {
			throw Errors::invariant( "Structure {$file} transition description must be a string." );
		}

		if ( isset( $transition['guards'] ) ) {
			$this->validate_condition_container( $transition['guards'], $file, 'transition guards' );
		}

		if ( isset( $transition['effects'] ) ) {
			$this->validate_effects( $transition['effects'], $file );
		}
	}

	/**
	 * @param array<string,mixed> $transition Transition.
	 * @param array<string,true>  $state_names State names.
	 */
	private function validate_transition_target( array $transition, string $key, array $state_names, string $file ): void {
		if ( ! isset( $transition[ $key ] ) ) {
			return;
		}

		if ( ! is_string( $transition[ $key ] ) || '' === $transition[ $key ] || ! isset( $state_names[ $transition[ $key ] ] ) ) {
			throw Errors::invariant( "Structure {$file} transition {$key} must reference an existing state." );
		}
	}

	private function validate_effects( mixed $effects, string $file ): void {
		if ( ! is_array( $effects ) || ! array_is_list( $effects ) ) {
			throw Errors::invariant( "Structure {$file} transition effects must be an array." );
		}

		foreach ( $effects as $effect ) {
			if ( ! is_array( $effect ) || array_is_list( $effect ) ) {
				throw Errors::invariant( "Structure {$file} transition effect must be an object." );
			}

			$effect = $this->string_keyed_array( $effect, "Structure {$file} transition effect" );
			$this->assert_allowed_keys( $effect, array( 'type', 'action', 'source', 'form', 'collection', 'target', 'path', 'value', 'params', 'payload', 'when', 'mode', 'replace', 'url' ), "Structure {$file} transition effect" );

			if ( ! is_string( $effect['type'] ?? null ) || ! in_array( $effect['type'], self::EFFECT_TYPES, true ) ) {
				throw Errors::invariant( "Structure {$file} transition effect has invalid type." );
			}

			foreach ( self::EFFECT_STRING_KEYS as $key ) {
				if ( isset( $effect[ $key ] ) && ! is_string( $effect[ $key ] ) ) {
					throw Errors::invariant( "Structure {$file} transition effect {$key} must be a string." );
				}
			}

			if ( isset( $effect['mode'] ) && ! in_array( $effect['mode'], self::EFFECT_MODES, true ) ) {
				throw Errors::invariant( "Structure {$file} transition effect mode is invalid." );
			}

			if ( isset( $effect['source'] ) ) {
				$source = $effect['source'];
				if ( ! is_string( $source ) || ! preg_match( self::SOURCE_PATTERN, $source ) ) {
					throw Errors::invariant( "Structure {$file} transition effect source must be a dot-notation source." );
				}
			}

			if ( isset( $effect['path'] ) && ! is_string( $effect['path'] ) ) {
				throw Errors::invariant( "Structure {$file} transition effect path must be a string." );
			}

			if ( isset( $effect['params'] ) && ( ! is_array( $effect['params'] ) || array_is_list( $effect['params'] ) ) ) {
				throw Errors::invariant( "Structure {$file} transition effect params must be an object." );
			}

			if ( isset( $effect['payload'] ) && ( ! is_array( $effect['payload'] ) || array_is_list( $effect['payload'] ) ) ) {
				throw Errors::invariant( "Structure {$file} transition effect payload must be an object." );
			}

			if ( isset( $effect['replace'] ) && ! is_bool( $effect['replace'] ) ) {
				throw Errors::invariant( "Structure {$file} transition effect replace must be a boolean." );
			}

			$this->validate_structure_node( $effect, $file );
		}
	}

	/**
	 * @param array<array-key,mixed> $component Component tree node.
	 */
	private function validate_component_node( array $component, string $file ): void {
		$component = $this->string_keyed_array( $component, "Component {$file}" );
		$this->assert_allowed_keys(
			$component,
			array(
				'id',
				'key',
				'type',
				'componentRef',
				'slot',
				'initialState',
				'states',
				'views',
				'bindings',
				'form',
				'collection',
				'data',
				'enable',
				'help',
				'params',
				'props',
				'state',
				'children',
				'slots',
				'events',
				'visibleWhen',
				'enabledWhen',
				'requiredWhen',
				'readOnlyWhen',
				'validateWhen',
				'optionsWhen',
			),
			"Component {$file}"
		);

		$raw_type      = $component['type'] ?? null;
		$type          = is_string( $raw_type ) ? StructureComponentTypes::canonical( $raw_type ) : $raw_type;
		$component_ref = $component['componentRef'] ?? null;
		$has_type      = is_string( $type ) && '' !== trim( $type );
		$has_ref       = is_string( $component_ref ) && '' !== trim( $component_ref );

		if ( $has_type === $has_ref ) {
			throw Errors::invariant( "Component {$file} must define exactly one of type or componentRef." );
		}

		if ( $has_type && ! in_array( (string) $raw_type, StructureComponentTypes::schema_values(), true ) ) {
			throw Errors::invariant( "Component {$file} type {$raw_type} is not supported." );
		}

		if ( $has_ref && ! preg_match( self::COMPONENT_NAME_PATTERN, (string) $component_ref ) ) {
			throw Errors::invariant( "Component {$file} has invalid componentRef." );
		}

		if ( 'StateRouter' === $type ) {
			$this->validate_state_router_component( $component, $file );
		} elseif ( isset( $component['initialState'] ) || isset( $component['states'] ) || isset( $component['views'] ) ) {
			throw Errors::invariant( "Component {$file} state router keys are only supported on StateRouter." );
		}

		if ( isset( $component['props'] ) && ( ! is_array( $component['props'] ) || array_is_list( $component['props'] ) ) ) {
			throw Errors::invariant( "Component {$file} props must be an object." );
		}

		if ( isset( $component['state'] ) && ( ! is_array( $component['state'] ) || array_is_list( $component['state'] ) ) ) {
			throw Errors::invariant( "Component {$file} state must be an object." );
		}

		if ( isset( $component['enable'] ) ) {
			$this->validate_enable_binding( $component['enable'], "Component {$file} enable" );
		}

		if ( isset( $component['help'] ) ) {
			$this->validate_help( $component['help'], "Component {$file} help" );
		}

		if ( isset( $component['props'] ) && is_array( $component['props'] ) && isset( $component['props']['itemEnable'] ) ) {
			if ( 'Repeater' !== $type ) {
				throw Errors::invariant( "Component {$file} props.itemEnable is only supported on Repeater." );
			}

			$this->validate_enable_binding( $component['props']['itemEnable'], "Component {$file} props.itemEnable" );
		}

		if ( isset( $component['events'] ) ) {
			$this->validate_component_event_bindings( $component['events'], $file );
		}

		if ( 'DynamicTabs' === $type ) {
			$this->validate_dynamic_tabs_component( $component, $file );
		}

		if ( 'Repeater' === $type ) {
			$this->validate_repeater_component( $component, $file );
		}

		if ( 'Entries' === $type ) {
			$this->validate_entries_component( $component, $file );
		}

		if ( 'Drawer' === $type ) {
			$this->validate_drawer_component( $component, $file );
		}

		if ( isset( $component['children'] ) ) {
			if ( ! is_array( $component['children'] ) ) {
				throw Errors::invariant( "Component {$file} children must be an array." );
			}

			foreach ( $component['children'] as $index => $child ) {
				if ( ! is_array( $child ) ) {
					throw Errors::invariant( "Component {$file} child {$index} must be an object." );
				}

				$this->validate_component_node( $child, "{$file} child {$index}" );
			}
		}

		$this->validate_structure_node( $component, $file );
	}

	/**
	 * @param mixed $binding Binding.
	 */
	private function validate_enable_binding( mixed $binding, string $label ): void {
		if ( ! is_array( $binding ) || array_is_list( $binding ) ) {
			throw Errors::invariant( "{$label} must be an object." );
		}

		$binding = $this->string_keyed_array( $binding, $label );
		$this->assert_allowed_keys( $binding, array( 'setting', 'label', 'default' ), $label );

		if ( ! is_string( $binding['setting'] ?? null ) || ! preg_match( self::DEFINITION_NAME_PATTERN, $binding['setting'] ) ) {
			throw Errors::invariant( "{$label} must define a valid setting." );
		}

		if ( isset( $binding['label'] ) && ( ! is_string( $binding['label'] ) || '' === trim( $binding['label'] ) ) ) {
			throw Errors::invariant( "{$label} label must be a non-empty string." );
		}

		if ( isset( $binding['default'] ) && ! is_bool( $binding['default'] ) ) {
			throw Errors::invariant( "{$label} default must be a boolean." );
		}
	}

	private function validate_help( mixed $help, string $label ): void {
		if ( ! is_array( $help ) || array_is_list( $help ) ) {
			throw Errors::invariant( "{$label} must be an object." );
		}

		$help = $this->string_keyed_array( $help, $label );
		$this->assert_allowed_keys( $help, array( 'text', 'label' ), $label );

		if ( ! is_string( $help['text'] ?? null ) || '' === trim( $help['text'] ) ) {
			throw Errors::invariant( "{$label} text must be a non-empty string." );
		}

		if ( isset( $help['label'] ) && ( ! is_string( $help['label'] ) || '' === trim( $help['label'] ) ) ) {
			throw Errors::invariant( "{$label} label must be a non-empty string." );
		}
	}

	/**
	 * @param array<string,mixed> $component Component tree node.
	 */
	private function validate_state_router_component( array $component, string $file ): void {
		$initial_state = $this->required_string( $component, 'initialState', "Component {$file}" );

		if ( ! isset( $component['views'] ) || ! is_array( $component['views'] ) || array() === $component['views'] || array_is_list( $component['views'] ) ) {
			throw Errors::invariant( "Component {$file} StateRouter must define views." );
		}

		if ( ! isset( $component['states'] ) || ! is_array( $component['states'] ) || array() === $component['states'] || ! array_is_list( $component['states'] ) ) {
			throw Errors::invariant( "Component {$file} StateRouter must define states." );
		}

		$view_names = array();
		foreach ( $component['views'] as $name => $view ) {
			if ( ! is_string( $name ) || '' === $name ) {
				throw Errors::invariant( "Component {$file} StateRouter view names must be strings." );
			}

			$view_names[ $name ] = true;
			$this->validate_structure_view( $view, $name, $file );
		}

		$state_names = array();
		foreach ( $component['states'] as $state ) {
			$state_name                 = $this->validate_structure_state( $state, $view_names, $file );
			$state_names[ $state_name ] = true;
		}

		foreach ( $component['states'] as $state ) {
			$this->validate_state_transitions( $state, $state_names, $file );
		}

		if ( ! isset( $state_names[ $initial_state ] ) ) {
			throw Errors::invariant( "Component {$file} StateRouter initialState must reference a state." );
		}
	}

	/**
	 * @param array<string,mixed> $component Component tree node.
	 */
	private function validate_repeater_component( array $component, string $file ): void {
		$props = $component['props'] ?? null;
		if ( ! is_array( $props ) || array_is_list( $props ) ) {
			throw Errors::invariant( "Component {$file} Repeater must define props." );
		}

		if ( ! is_string( $props['name'] ?? null ) || '' === trim( $props['name'] ) ) {
			throw Errors::invariant( "Component {$file} Repeater props.name must be a non-empty string." );
		}

		if ( ! isset( $component['children'] ) || ! is_array( $component['children'] ) || array() === $component['children'] ) {
			throw Errors::invariant( "Component {$file} Repeater must define children." );
		}
	}

	/**
	 * @param array<string,mixed> $component Component tree node.
	 */
	private function validate_entries_component( array $component, string $file ): void {
		$props = $component['props'] ?? null;
		if ( ! is_array( $props ) || array_is_list( $props ) ) {
			throw Errors::invariant( "Component {$file} Entries must define props." );
		}

		$this->validate_entries_props( $props, $file );

		if ( isset( $component['children'] ) ) {
			throw Errors::invariant( "Component {$file} Entries must not define children." );
		}
	}

	/**
	 * @param array<string,mixed> $component Component tree node.
	 */
	private function validate_dynamic_tabs_component( array $component, string $file ): void {
		$props = $component['props'] ?? null;
		if ( ! is_array( $props ) || array_is_list( $props ) ) {
			throw Errors::invariant( "Component {$file} DynamicTabs must define props." );
		}

		$has_template = isset( $props['template'] );
		$has_children = isset( $component['children'] ) && is_array( $component['children'] ) && array() !== $component['children'];
		if ( ! $has_template && ! $has_children ) {
			throw Errors::invariant( "Component {$file} DynamicTabs must define a template or child component." );
		}
	}

	/**
	 * @param array<string,mixed> $component Component tree node.
	 */
	private function validate_drawer_component( array $component, string $file ): void {
		$props = $component['props'] ?? null;
		if ( ! is_array( $props ) || array_is_list( $props ) ) {
			throw Errors::invariant( "Component {$file} Drawer must define props." );
		}

		$props = $this->string_keyed_array( $props, "Component {$file} Drawer props" );
		foreach ( array( 'name', 'label', 'triggerLabel' ) as $key ) {
			if ( ! is_string( $props[ $key ] ?? null ) || '' === trim( $props[ $key ] ) ) {
				throw Errors::invariant( "Component {$file} Drawer props.{$key} must be a non-empty string." );
			}
		}

		foreach ( array( 'description', 'closeLabel', 'triggerVariant', 'triggerSize' ) as $key ) {
			if ( isset( $props[ $key ] ) && ( ! is_string( $props[ $key ] ) || '' === trim( $props[ $key ] ) ) ) {
				throw Errors::invariant( "Component {$file} Drawer props.{$key} must be a non-empty string." );
			}
		}

		if ( isset( $props['side'] ) && ( ! is_string( $props['side'] ) || ! in_array( $props['side'], self::DRAWER_SIDES, true ) ) ) {
			throw Errors::invariant( "Component {$file} Drawer props.side must be one of " . implode( ', ', self::DRAWER_SIDES ) . '.' );
		}

		if ( isset( $props['size'] ) && ( ! is_string( $props['size'] ) || ! in_array( $props['size'], self::DRAWER_SIZES, true ) ) ) {
			throw Errors::invariant( "Component {$file} Drawer props.size must be one of " . implode( ', ', self::DRAWER_SIZES ) . '.' );
		}

		foreach ( array( 'modal', 'dismissible', 'initialOpen' ) as $key ) {
			if ( isset( $props[ $key ] ) && ! is_bool( $props[ $key ] ) ) {
				throw Errors::invariant( "Component {$file} Drawer props.{$key} must be a boolean." );
			}
		}
	}

	private function validate_component_event_bindings( mixed $events, string $file ): void {
		if ( ! is_array( $events ) || array_is_list( $events ) ) {
			throw Errors::invariant( "Component {$file} events must be an object." );
		}

		foreach ( $events as $name => $event ) {
			if ( ! is_string( $name ) || '' === $name ) {
				throw Errors::invariant( "Component {$file} event names must be strings." );
			}

			if ( ! is_array( $event ) || array_is_list( $event ) ) {
				throw Errors::invariant( "Component {$file} event {$name} must be an object." );
			}

			$event = $this->string_keyed_array( $event, "Component {$file} event {$name}" );
			$this->assert_allowed_keys( $event, array( 'event', 'payload' ), "Component {$file} event {$name}" );

			if ( ! is_string( $event['event'] ?? null ) || '' === $event['event'] ) {
				throw Errors::invariant( "Component {$file} event {$name} must define event." );
			}

			if ( isset( $event['payload'] ) && ( ! is_array( $event['payload'] ) || array_is_list( $event['payload'] ) ) ) {
				throw Errors::invariant( "Component {$file} event {$name} payload must be an object." );
			}
		}
	}

	private function validate_condition_container( mixed $condition, string $file, string $key ): void {
		if ( ! is_array( $condition ) || array() === $condition ) {
			throw Errors::invariant( "Structure {$file} {$key} must be a condition object or list." );
		}

		if ( array_is_list( $condition ) ) {
			foreach ( $condition as $child ) {
				$this->validate_condition( $child, $file, $key );
			}
			return;
		}

		$this->validate_condition( $condition, $file, $key );
	}

	private function validate_condition( mixed $condition, string $file, string $key ): void {
		if ( ! is_array( $condition ) ) {
			throw Errors::invariant( "Structure {$file} {$key} must contain condition objects." );
		}

		$op = $condition['op'] ?? null;
		if ( ! is_string( $op ) || ! in_array( $op, self::CONDITION_OPS, true ) ) {
			throw Errors::invariant( "Structure {$file} {$key} has invalid condition op." );
		}

		if ( in_array( $op, array( 'and', 'or' ), true ) ) {
			if ( ! is_array( $condition['conditions'] ?? null ) || array() === $condition['conditions'] ) {
				throw Errors::invariant( "Structure {$file} {$key} {$op} condition must define conditions." );
			}

			foreach ( $condition['conditions'] as $child ) {
				$this->validate_condition( $child, $file, $key );
			}
		}

		if ( 'not' === $op ) {
			$this->validate_condition( $condition['condition'] ?? null, $file, $key );
		}

		$this->validate_structure_node(
			array_diff_key(
				$condition,
				array(
					'conditions' => true,
					'condition'  => true,
				)
			),
			$file
		);
	}

	/**
	 * @param array<array-key,mixed> $node Node.
	 */
	private function validate_message_node( array $node, string $file ): void {
		foreach ( $node as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				throw Errors::invariant( "Messages {$file} must use string keys." );
			}

			if ( is_string( $value ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$this->validate_message_node( $value, $file );
				continue;
			}

			throw Errors::invariant( "Messages {$file} values must be strings or objects." );
		}
	}

	/**
	 * @param array<string,mixed> $data Data.
	 * @param string[]            $keys Keys.
	 */
	private function assert_exactly_one_key( array $data, array $keys, string $label ): void {
		$count = 0;
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				++$count;
			}
		}

		if ( 1 !== $count ) {
			throw Errors::invariant( "{$label} must define exactly one of " . implode( ', ', $keys ) . '.' );
		}
	}

	/**
	 * @param array<mixed,mixed> $value Value.
	 * @return array<string,mixed>
	 */
	private function string_keyed_array( array $value, string $label ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) ) {
				throw Errors::invariant( "{$label} must use string keys." );
			}

			$result[ $key ] = $item;
		}

		return $result;
	}
}
