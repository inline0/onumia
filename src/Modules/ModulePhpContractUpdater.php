<?php

/**
 * Updates module PHP contracts from structured data.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Core\Errors;
use Onumia\Lib\PhpParser\Error;
use Onumia\Lib\PhpParser\Node;
use Onumia\Lib\PhpParser\Node\Arg;
use Onumia\Lib\PhpParser\Node\ArrayItem;
use Onumia\Lib\PhpParser\Node\Attribute;
use Onumia\Lib\PhpParser\Node\AttributeGroup;
use Onumia\Lib\PhpParser\Node\Expr;
use Onumia\Lib\PhpParser\Node\Expr\Array_;
use Onumia\Lib\PhpParser\Node\Expr\ClassConstFetch;
use Onumia\Lib\PhpParser\Node\Expr\ConstFetch;
use Onumia\Lib\PhpParser\Node\Identifier;
use Onumia\Lib\PhpParser\Node\Name;
use Onumia\Lib\PhpParser\Node\Name\FullyQualified;
use Onumia\Lib\PhpParser\Node\Scalar\DNumber;
use Onumia\Lib\PhpParser\Node\Scalar\LNumber;
use Onumia\Lib\PhpParser\Node\Scalar\String_;
use Onumia\Lib\PhpParser\Node\Stmt;
use Onumia\Lib\PhpParser\Node\Stmt\Class_;
use Onumia\Lib\PhpParser\Node\Stmt\ClassMethod;
use Onumia\Lib\PhpParser\Node\Stmt\Namespace_;
use Onumia\Lib\PhpParser\Node\Stmt\Use_;
use Onumia\Lib\PhpParser\NodeTraverser;
use Onumia\Lib\PhpParser\NodeVisitor\CloningVisitor;
use Onumia\Lib\PhpParser\ParserFactory;
use Onumia\Lib\PhpParser\PrettyPrinter\Standard;

final class ModulePhpContractUpdater {
	private const ATTRIBUTE_ACTION          = 'Onumia\\Modules\\Attributes\\Action';
	private const ATTRIBUTE_DATA_SOURCE     = 'Onumia\\Modules\\Attributes\\DataSource';
	private const ATTRIBUTE_INPUT           = 'Onumia\\Modules\\Attributes\\Input';
	private const ATTRIBUTE_MODULE_CONTRACT = 'Onumia\\Modules\\Attributes\\ModuleContract';
	private const ATTRIBUTE_SETTING         = 'Onumia\\Modules\\Attributes\\Setting';
	private const ATTRIBUTE_WP_ACTION       = 'Onumia\\Modules\\Attributes\\WpAction';
	private const ATTRIBUTE_WP_FILTER       = 'Onumia\\Modules\\Attributes\\WpFilter';
	private const ENUM_SETTING_TYPE         = 'Onumia\\Modules\\Contracts\\SettingType';
	private const ENUM_SURFACE              = 'Onumia\\Modules\\Contracts\\Surface';
	private const SETTING_CASES             = array(
		'boolean' => 'Boolean',
		'string'  => 'String',
		'integer' => 'Integer',
		'number'  => 'Number',
		'array'   => 'Array',
		'object'  => 'Object',
	);
	private const SURFACE_CASES             = array(
		'backend'  => 'Backend',
		'admin'    => 'Admin',
		'frontend' => 'Frontend',
	);

	/**
	 * @param array<string,mixed> $patch Contract patch.
	 */
	public function update_file( string $file, array $patch ): ModuleContractDefinition {
		if ( ! is_file( $file ) ) {
			throw Errors::invariant( "Module boot file is missing: {$file}." );
		}

		$contents = file_get_contents( $file );
		$updated  = $this->updated_contents( false === $contents ? '' : $contents, $patch, $file );
		$this->validate_updated_contents( $updated );
		file_put_contents( $file, $updated );

		return ( new ModulePhpContractParser() )->parse_file( $file )[0];
	}

	/**
	 * @param array<string,mixed> $patch Contract patch.
	 */
	private function updated_contents( string $contents, array $patch, string $file ): string {
		$parser = ( new ParserFactory() )->createForNewestSupportedVersion();
		try {
			$original = $parser->parse( $contents ) ?? array();
		} catch ( Error $error ) {
			throw Errors::invariant( 'PHP syntax error in module boot file: ' . $error->getMessage() );
		}

		$traverser = new NodeTraverser();
		$traverser->addVisitor( new CloningVisitor() );
		$modified = $traverser->traverse( $original );
		$tokens   = $parser->getTokens();
		$class    = $this->module_class( $modified, $file );

		$this->apply_patch( $class, $patch, $file );

		return ( new Standard() )->printFormatPreserving( $modified, $original, $tokens );
	}

	private function validate_updated_contents( string $contents ): void {
		$file = sys_get_temp_dir() . '/onumia-boot-' . bin2hex( random_bytes( 16 ) ) . '.php';
		file_put_contents( $file, $contents );
		try {
			( new ModulePhpContractParser() )->parse_file( $file );
		} finally {
			@unlink( $file );
		}
	}

	/**
	 * @param Node[] $stmts Statements.
	 * @return array{class:Class_,namespace:string,uses:array<string,string>}
	 */
	private function module_class( array $stmts, string $file ): array {
		$classes = array();
		$this->scan_classes( $stmts, '', array(), $classes );
		if ( 1 !== count( $classes ) ) {
			throw Errors::invariant( "Module boot file {$file} must define exactly one class extending Onumia\\Modules\\Module." );
		}

		return $classes[0];
	}

	/**
	 * @param Node[] $stmts Statements.
	 * @param array<string,string> $uses Uses.
	 * @param list<array{class:Class_,namespace:string,uses:array<string,string>}> $classes Classes.
	 */
	private function scan_classes( array $stmts, string $namespace, array $uses, array &$classes ): void {
		foreach ( $stmts as $stmt ) {
			if ( $stmt instanceof Namespace_ ) {
				$this->scan_classes( $stmt->stmts, null === $stmt->name ? '' : $stmt->name->toString(), array(), $classes );
				continue;
			}

			if ( $stmt instanceof Use_ ) {
				foreach ( $stmt->uses as $use ) {
					$alias          = $use->alias instanceof Identifier ? $use->alias->toString() : $use->name->getLast();
					$uses[ $alias ] = $use->name->toString();
				}
				continue;
			}

			if ( ! $stmt instanceof Class_ || null === $stmt->extends ) {
				continue;
			}

			if ( 'Onumia\\Modules\\Module' !== $this->resolve_name( $stmt->extends, $namespace, $uses ) ) {
				continue;
			}

			$classes[] = array(
				'class'     => $stmt,
				'namespace' => $namespace,
				'uses'      => $uses,
			);
		}
	}

	/**
	 * @param array<string,string> $uses Uses.
	 */
	private function resolve_name( Name $name, string $namespace, array $uses ): string {
		$parts = $name->getParts();
		$first = $parts[0];
		if ( isset( $uses[ $first ] ) ) {
			$rest = array_slice( $parts, 1 );
			return $uses[ $first ] . ( array() === $rest ? '' : '\\' . implode( '\\', $rest ) );
		}

		if ( $name->isFullyQualified() ) {
			return $name->toString();
		}

		return '' === $namespace ? $name->toString() : $namespace . '\\' . $name->toString();
	}

	/**
	 * @param array{class:Class_,namespace:string,uses:array<string,string>} $class_info Class info.
	 * @param array<string,mixed>                                           $patch Contract patch.
	 */
	private function apply_patch( array $class_info, array $patch, string $file ): void {
		foreach ( $patch as $key => $_value ) {
			if ( ! in_array( $key, array( 'module', 'settings', 'methods' ), true ) ) {
				throw Errors::invariant( "Module contract patch contains unknown key {$key}." );
			}
		}

		$this->update_class_attributes( $class_info['class'], $patch, $file, $class_info['namespace'], $class_info['uses'] );
		$this->update_method_attributes( $class_info['class'], $patch, $file, $class_info['namespace'], $class_info['uses'] );
	}

	/**
	 * @param array<string,mixed> $patch Contract patch.
	 * @param array<string,string> $uses Uses.
	 */
	private function update_class_attributes( Class_ $class, array $patch, string $file, string $namespace, array $uses ): void {
		$module_groups = array_key_exists( 'module', $patch )
			? array( $this->module_contract_group( $this->array_value( $patch['module'], 'module' ) ) )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
			: $this->existing_attribute_groups( $class->attrGroups, array( self::ATTRIBUTE_MODULE_CONTRACT ), $namespace, $uses );
		$setting_groups = array_key_exists( 'settings', $patch )
			? $this->setting_groups( $this->list_value( $patch['settings'], 'settings' ), $file )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
			: $this->existing_attribute_groups( $class->attrGroups, array( self::ATTRIBUTE_SETTING ), $namespace, $uses );
		$other_groups = $this->other_attribute_groups(
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
			$class->attrGroups,
			array(
				self::ATTRIBUTE_MODULE_CONTRACT,
				self::ATTRIBUTE_SETTING,
			),
			$namespace,
			$uses
		);

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		$class->attrGroups = array_merge( $module_groups, $setting_groups, $other_groups );
	}

	/**
	 * @param array<string,mixed> $patch Contract patch.
	 * @param array<string,string> $uses Uses.
	 */
	private function update_method_attributes( Class_ $class, array $patch, string $file, string $namespace, array $uses ): void {
		if ( ! array_key_exists( 'methods', $patch ) ) {
			return;
		}

		$methods = $this->array_value( $patch['methods'], 'methods' );
		foreach ( $methods as $method_name => $method_patch ) {
			if ( '' === $method_name ) {
				throw Errors::invariant( 'Method patch keys must be non-empty method names.' );
			}

			$method = $this->class_method( $class, $method_name, $file );
			$this->replace_method_attributes( $method, $this->array_value( $method_patch, "method {$method_name}" ), $file, $namespace, $uses );
		}
	}

	private function class_method( Class_ $class, string $method_name, string $file ): ClassMethod {
		foreach ( $class->getMethods() as $method ) {
			if ( $method_name === $method->name->toString() ) {
				return $method;
			}
		}

		throw Errors::invariant( "Module contract patch references missing method {$method_name} in {$file}." );
	}

	/**
	 * @param array<string,mixed> $patch Method patch.
	 * @param array<string,string> $uses Uses.
	 */
	private function replace_method_attributes( ClassMethod $method, array $patch, string $file, string $namespace, array $uses ): void {
		foreach ( $patch as $key => $_value ) {
			if ( ! in_array( $key, array( 'actions', 'dataSources', 'inputs', 'wpActions', 'wpFilters' ), true ) ) {
				throw Errors::invariant( "Method contract patch contains unknown key {$key}." );
			}
		}

		$action_groups = array_key_exists( 'actions', $patch )
			? $this->callable_groups( self::ATTRIBUTE_ACTION, $this->list_value( $patch['actions'], 'actions' ), $file )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
			: $this->existing_attribute_groups( $method->attrGroups, array( self::ATTRIBUTE_ACTION ), $namespace, $uses );
		$source_groups = array_key_exists( 'dataSources', $patch )
			? $this->callable_groups( self::ATTRIBUTE_DATA_SOURCE, $this->list_value( $patch['dataSources'], 'dataSources' ), $file )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
			: $this->existing_attribute_groups( $method->attrGroups, array( self::ATTRIBUTE_DATA_SOURCE ), $namespace, $uses );
		$input_groups = array_key_exists( 'inputs', $patch )
			? $this->input_groups( $this->list_value( $patch['inputs'], 'inputs' ), $file )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
			: $this->existing_attribute_groups( $method->attrGroups, array( self::ATTRIBUTE_INPUT ), $namespace, $uses );
		$wp_actions = array_key_exists( 'wpActions', $patch )
			? $this->hook_groups( self::ATTRIBUTE_WP_ACTION, $this->list_value( $patch['wpActions'], 'wpActions' ) )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
			: $this->existing_attribute_groups( $method->attrGroups, array( self::ATTRIBUTE_WP_ACTION ), $namespace, $uses );
		$wp_filters = array_key_exists( 'wpFilters', $patch )
			? $this->hook_groups( self::ATTRIBUTE_WP_FILTER, $this->list_value( $patch['wpFilters'], 'wpFilters' ) )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
			: $this->existing_attribute_groups( $method->attrGroups, array( self::ATTRIBUTE_WP_FILTER ), $namespace, $uses );
		$other_groups = $this->other_attribute_groups(
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
			$method->attrGroups,
			array(
				self::ATTRIBUTE_ACTION,
				self::ATTRIBUTE_DATA_SOURCE,
				self::ATTRIBUTE_INPUT,
				self::ATTRIBUTE_WP_ACTION,
				self::ATTRIBUTE_WP_FILTER,
			),
			$namespace,
			$uses
		);

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		$method->attrGroups = array_merge( $action_groups, $source_groups, $input_groups, $wp_actions, $wp_filters, $other_groups );
	}

	/**
	 * @param array<string,mixed> $module Module patch.
	 */
	private function module_contract_group( array $module ): AttributeGroup {
		$args = array();
		if ( array_key_exists( 'defaultEnabled', $module ) ) {
			$args[] = $this->named_arg( 'default_enabled', $this->expr( $this->bool_value( $module['defaultEnabled'], 'module.defaultEnabled' ) ) );
		}

		if ( array_key_exists( 'capability', $module ) ) {
			$args[] = $this->named_arg( 'capability', $this->expr( $this->string_value( $module['capability'], 'module.capability' ) ) );
		}

		return $this->attribute_group( self::ATTRIBUTE_MODULE_CONTRACT, $args );
	}

	/**
	 * @param list<mixed> $settings Settings.
	 * @return AttributeGroup[]
	 */
	private function setting_groups( array $settings, string $file ): array {
		$groups = array();
		foreach ( $settings as $setting ) {
			$field    = $this->field_array( $setting, 'setting', false );
			$groups[] = $this->attribute_group( self::ATTRIBUTE_SETTING, $this->field_args( $field, false, $file ) );
		}

		return $groups;
	}

	/**
	 * @param list<mixed> $entries Entries.
	 * @return AttributeGroup[]
	 */
	private function callable_groups( string $attribute_class, array $entries, string $file ): array {
		$groups = array();
		foreach ( $entries as $entry ) {
			$entry = $this->array_value( $entry, $this->short_name( $attribute_class ) );
			$args  = array();
			if ( array_key_exists( 'name', $entry ) ) {
				$args[] = new Arg( $this->expr( $this->string_value( $entry['name'], 'callable.name' ) ) );
			}

			if ( array_key_exists( 'surface', $entry ) ) {
				$surface = $this->string_value( $entry['surface'], 'callable.surface' );
				if ( ! isset( self::SURFACE_CASES[ $surface ] ) ) {
					throw Errors::invariant( "Callable surface {$surface} in {$file} is invalid." );
				}
				$args[] = $this->named_arg( 'surface', $this->enum_expr( self::ENUM_SURFACE, self::SURFACE_CASES[ $surface ] ) );
			}

			if ( array_key_exists( 'capability', $entry ) ) {
				$args[] = $this->named_arg( 'capability', $this->expr( $this->string_value( $entry['capability'], 'callable.capability' ) ) );
			}

			$groups[] = $this->attribute_group( $attribute_class, $args );
		}

		return $groups;
	}

	/**
	 * @param list<mixed> $inputs Inputs.
	 * @return AttributeGroup[]
	 */
	private function input_groups( array $inputs, string $file ): array {
		$groups = array();
		foreach ( $inputs as $input ) {
			$field    = $this->field_array( $input, 'input', true );
			$groups[] = $this->attribute_group( self::ATTRIBUTE_INPUT, $this->field_args( $field, true, $file ) );
		}

		return $groups;
	}

	/**
	 * @param list<mixed> $hooks Hooks.
	 * @return AttributeGroup[]
	 */
	private function hook_groups( string $attribute_class, array $hooks ): array {
		$groups = array();
		foreach ( $hooks as $hook ) {
			$hook = $this->array_value( $hook, $this->short_name( $attribute_class ) );
			$args = array( new Arg( $this->expr( $this->string_value( $hook['hook'] ?? null, 'hook.hook' ) ) ) );
			if ( array_key_exists( 'priority', $hook ) ) {
				$args[] = $this->named_arg( 'priority', $this->expr( $this->int_value( $hook['priority'], 'hook.priority' ) ) );
			}

			if ( array_key_exists( 'acceptedArgs', $hook ) ) {
				$args[] = $this->named_arg( 'accepted_args', $this->expr( $this->nullable_int_value( $hook['acceptedArgs'], 'hook.acceptedArgs' ) ) );
			}

			$groups[] = $this->attribute_group( $attribute_class, $args );
		}

		return $groups;
	}

	/**
	 * @param array<string,mixed> $field Field.
	 * @return Arg[]
	 */
	private function field_args( array $field, bool $allow_required, string $file ): array {
		$type = $this->string_value( $field['type'] ?? null, 'field.type' );
		if ( ! isset( self::SETTING_CASES[ $type ] ) ) {
			throw Errors::invariant( "Field type {$type} in {$file} is invalid." );
		}

		$args = array(
			new Arg( $this->expr( $this->string_value( $field['name'] ?? null, 'field.name' ) ) ),
			new Arg( $this->enum_expr( self::ENUM_SETTING_TYPE, self::SETTING_CASES[ $type ] ) ),
		);

		foreach ( array( 'default', 'allowed', 'min', 'max', 'format' ) as $key ) {
			if ( array_key_exists( $key, $field ) ) {
				$args[] = $this->named_arg( $key, $this->expr( $field[ $key ] ) );
			}
		}

		if ( $allow_required && array_key_exists( 'required', $field ) ) {
			$args[] = $this->named_arg( 'required', $this->expr( $this->bool_value( $field['required'], 'field.required' ) ) );
		}

		return $args;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function field_array( mixed $value, string $label, bool $allow_required ): array {
		$field = $this->array_value( $value, $label );
		if ( $allow_required || ! array_key_exists( 'required', $field ) ) {
			return $field;
		}

		throw Errors::invariant( "{$label} fields do not support required." );
	}

	/**
	 * @param AttributeGroup[] $groups Attribute groups.
	 * @param string[]         $attribute_classes Attribute classes.
	 * @param array<string,string> $uses Uses.
	 * @return AttributeGroup[]
	 */
	private function existing_attribute_groups( array $groups, array $attribute_classes, string $namespace, array $uses ): array {
		$result = array();
		foreach ( $groups as $group ) {
			$attrs = array();
			foreach ( $group->attrs as $attribute ) {
				if ( in_array( $this->attribute_class( $attribute, $namespace, $uses ), $attribute_classes, true ) ) {
					$attrs[] = $attribute;
				}
			}

			if ( array() !== $attrs ) {
				$result[] = new AttributeGroup( $attrs );
			}
		}

		return $result;
	}

	/**
	 * @param AttributeGroup[] $groups Attribute groups.
	 * @param string[]         $attribute_classes Attribute classes.
	 * @param array<string,string> $uses Uses.
	 * @return AttributeGroup[]
	 */
	private function other_attribute_groups( array $groups, array $attribute_classes, string $namespace, array $uses ): array {
		$result = array();
		foreach ( $groups as $group ) {
			$attrs = array();
			foreach ( $group->attrs as $attribute ) {
				if ( ! in_array( $this->attribute_class( $attribute, $namespace, $uses ), $attribute_classes, true ) ) {
					$attrs[] = $attribute;
				}
			}

			if ( array() !== $attrs ) {
				$result[] = new AttributeGroup( $attrs );
			}
		}

		return $result;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 */
	private function attribute_class( Attribute $attribute, string $namespace, array $uses ): string {
		return $this->resolve_name( $attribute->name, $namespace, $uses );
	}

	/**
	 * @param Arg[] $args Arguments.
	 */
	private function attribute_group( string $attribute_class, array $args ): AttributeGroup {
		return new AttributeGroup(
			array(
				new Attribute(
					new FullyQualified( $attribute_class ),
					array_values( $args )
				),
			)
		);
	}

	private function named_arg( string $name, Expr $value ): Arg {
		return new Arg( $value, false, false, array(), new Identifier( $name ) );
	}

	private function enum_expr( string $class, string $case ): ClassConstFetch {
		return new ClassConstFetch( new FullyQualified( $class ), $case );
	}

	private function expr( mixed $value ): Expr {
		if ( is_string( $value ) ) {
			return new String_( $value );
		}

		if ( is_int( $value ) ) {
			return new LNumber( $value );
		}

		if ( is_float( $value ) ) {
			return new DNumber( $value );
		}

		if ( is_bool( $value ) ) {
			return new ConstFetch( new Name( $value ? 'true' : 'false' ) );
		}

		if ( null === $value ) {
			return new ConstFetch( new Name( 'null' ) );
		}

		if ( is_array( $value ) ) {
			return $this->array_expr( $value );
		}

		throw Errors::invariant( 'Module contract patch values must be JSON-literal compatible.' );
	}

	/**
	 * @param array<mixed> $value Value.
	 */
	private function array_expr( array $value ): Array_ {
		$items = array();
		$list  = array_is_list( $value );
		foreach ( $value as $key => $item ) {
			$items[] = new ArrayItem(
				$this->expr( $item ),
				$list ? null : $this->expr( $key )
			);
		}

		return new Array_( $items, array( 'kind' => Array_::KIND_LONG ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function array_value( mixed $value, string $label ): array {
		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $key => $item ) {
				if ( ! is_string( $key ) ) {
					throw Errors::invariant( "Module contract patch {$label} must be an object." );
				}

				$result[ $key ] = $item;
			}

			return $result;
		}

		throw Errors::invariant( "Module contract patch {$label} must be an object." );
	}

	/**
	 * @return list<mixed>
	 */
	private function list_value( mixed $value, string $label ): array {
		if ( is_array( $value ) && array_is_list( $value ) ) {
			return $value;
		}

		throw Errors::invariant( "Module contract patch {$label} must be a list." );
	}

	private function string_value( mixed $value, string $label ): string {
		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}

		throw Errors::invariant( "Module contract patch {$label} must be a non-empty string." );
	}

	private function bool_value( mixed $value, string $label ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		throw Errors::invariant( "Module contract patch {$label} must be boolean." );
	}

	private function int_value( mixed $value, string $label ): int {
		if ( is_int( $value ) ) {
			return $value;
		}

		throw Errors::invariant( "Module contract patch {$label} must be integer." );
	}

	private function nullable_int_value( mixed $value, string $label ): ?int {
		if ( null === $value || is_int( $value ) ) {
			return $value;
		}

		throw Errors::invariant( "Module contract patch {$label} must be integer or null." );
	}

	private function short_name( string $class ): string {
		$parts = explode( '\\', $class );
		$short = end( $parts );
		return false === $short ? $class : $short;
	}
}
