<?php

/**
 * Parses module PHP contracts without executing module code.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Core\Errors;
use Onumia\Lib\PhpParser\Error;
use Onumia\Lib\PhpParser\Node;
use Onumia\Lib\PhpParser\Node\Attribute as PhpAttribute;
use Onumia\Lib\PhpParser\Node\Expr\Array_;
use Onumia\Lib\PhpParser\Node\Expr\ClassConstFetch;
use Onumia\Lib\PhpParser\Node\Expr\ConstFetch;
use Onumia\Lib\PhpParser\Node\Expr\UnaryMinus;
use Onumia\Lib\PhpParser\Node\Identifier;
use Onumia\Lib\PhpParser\Node\Name;
use Onumia\Lib\PhpParser\Node\Scalar\DNumber;
use Onumia\Lib\PhpParser\Node\Scalar\LNumber;
use Onumia\Lib\PhpParser\Node\Scalar\String_;
use Onumia\Lib\PhpParser\Node\Stmt;
use Onumia\Lib\PhpParser\Node\Stmt\Class_;
use Onumia\Lib\PhpParser\Node\Stmt\ClassMethod;
use Onumia\Lib\PhpParser\Node\Stmt\Namespace_;
use Onumia\Lib\PhpParser\Node\Stmt\Return_;
use Onumia\Lib\PhpParser\Node\Stmt\Trait_;
use Onumia\Lib\PhpParser\Node\Stmt\TraitUse;
use Onumia\Lib\PhpParser\Node\Stmt\Use_;
use Onumia\Lib\PhpParser\ParserFactory;

/**
 * @phpstan-type ModuleParsedPublicRoute array{path:string,method:string,auth:string,rate_limit:int}
 * @phpstan-type ModuleParsedJob array{name:?string,schedule:string,enabled:bool,run_on_activation:bool}
 * @phpstan-type ModuleParsedEntry array{name:string,singular:string,plural:string,key:string,storage:string,setting:?string,source:?string,table:?string,create_action:?string,update_action:?string,delete_action:?string,close_on_success:bool,destructive_mode:string}
 * @phpstan-type ModuleParsedEntryField array{name:string,type:string,label:?string,default?:mixed,allowed?:list<mixed>,min?:float|int,max?:float|int,format?:?string,required?:bool,primary:bool,list:bool,filter:bool,filter_type:?string,options:list<array<string,mixed>>,optionsSource:?array<string,mixed>,section:?string,create:bool,update:bool,read_only:bool,order:int,visible_when:?array<string,mixed>,props:array<string,mixed>}
 * @phpstan-type ModuleParsedEntrySection array{name:string,label:string,description:?string,order:int,layout:string}
 * @phpstan-type ModuleParsedRelatedEntry array{name:string,entry:string,local_key:string,foreign_key:string,label:?string,mode:string,order:int}
 * @phpstan-type ModuleParsedMethod array{required:int,total:int,public:bool,static:bool,returnsItemsTotalShape:bool,actions:list<array<string,mixed>>,dataSources:list<array<string,mixed>>,entries:list<ModuleParsedEntry>,entryFields:list<ModuleParsedEntryField>,entrySections:list<ModuleParsedEntrySection>,relatedEntries:list<ModuleParsedRelatedEntry>,publicRoutes:list<ModuleParsedPublicRoute>,jobs:list<ModuleParsedJob>,objectShapes:array<string,array<string,string>>,inputs:array<string,array<string,mixed>>,hooks:list<array{type:string,hook:string,priority:int,accepted_args:int}>}
 */
final class ModulePhpContractParser {
	private const ATTRIBUTE_ACTION          = 'Onumia\\Modules\\Attributes\\Action';
	private const ATTRIBUTE_DATA_SOURCE     = 'Onumia\\Modules\\Attributes\\DataSource';
	private const ATTRIBUTE_ENTRIES         = 'Onumia\\Modules\\Attributes\\Entries';
	private const ATTRIBUTE_ENTRY_FIELD     = 'Onumia\\Modules\\Attributes\\EntryField';
	private const ATTRIBUTE_ENTRY_SECTION   = 'Onumia\\Modules\\Attributes\\EntrySection';
	private const ATTRIBUTE_INPUT           = 'Onumia\\Modules\\Attributes\\Input';
	private const ATTRIBUTE_MODULE_CONTRACT = 'Onumia\\Modules\\Attributes\\ModuleContract';
	private const ATTRIBUTE_OBJECT_SHAPE    = 'Onumia\\Modules\\Attributes\\ObjectShape';
	private const ATTRIBUTE_RELATED_ENTRIES = 'Onumia\\Modules\\Attributes\\RelatedEntries';
	private const ATTRIBUTE_SETTING         = 'Onumia\\Modules\\Attributes\\Setting';
	private const ATTRIBUTE_WP_ACTION       = 'Onumia\\Modules\\Attributes\\WpAction';
	private const ATTRIBUTE_WP_FILTER       = 'Onumia\\Modules\\Attributes\\WpFilter';
	private const ATTRIBUTE_PUBLIC_ROUTE    = 'Onumia\\Modules\\Attributes\\PublicRoute';
	private const ATTRIBUTE_JOB             = 'Onumia\\Modules\\Attributes\\Job';
	private const ENUM_SETTING_TYPE         = 'Onumia\\Modules\\Contracts\\SettingType';
	private const ENUM_SURFACE              = 'Onumia\\Modules\\Contracts\\Surface';
	private const ENUM_ACTION_INTENT        = 'Onumia\\Modules\\Contracts\\ActionIntent';
	private const ENUM_DATA_SOURCE_SHAPE    = 'Onumia\\Modules\\Contracts\\DataSourceShape';
	private const ENUM_ENTRY_STORAGE        = 'Onumia\\Modules\\Contracts\\EntryStorage';
	private const ENUM_HTTP_METHOD          = 'Onumia\\Modules\\Contracts\\HttpMethod';
	private const ENUM_ROUTE_AUTH           = 'Onumia\\Modules\\Contracts\\RouteAuth';
	private const ENUM_JOB_SCHEDULE         = 'Onumia\\Modules\\Contracts\\JobSchedule';
	private const ENUM_PAGINATION_MODE      = 'Onumia\\Modules\\Contracts\\PaginationMode';
	private const SETTING_TYPES             = array( 'boolean', 'string', 'integer', 'number', 'array', 'object' );
	private const SURFACES                  = array( 'backend', 'admin', 'frontend' );
	private const ACTION_INTENTS            = array( 'create', 'update', 'delete', 'archive', 'publish', 'sync', 'issue', 'revoke', 'read', 'custom' );
	private const DATA_SOURCE_SHAPES        = array( 'options', 'collection', 'record', 'metrics', 'custom' );
	private const PAGINATION_MODES          = array( 'client', 'server' );
	private const ENTRY_STORAGES            = array( 'settings', 'manual', 'table' );
	private const HTTP_METHODS              = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );
	private const ROUTE_AUTH_MODES          = array( 'none', 'license_key', 'download_token', 'webhook_signature', 'signature', 'wordpress_user' );
	private const JOB_SCHEDULES             = array( 'five_minutes', 'hourly', 'twice_daily', 'twicedaily', 'daily', 'weekly' );
	private const FIELD_FORMATS             = array( 'email', 'url' );
	private const OBJECT_SHAPE_TYPES        = array( 'boolean', 'string', 'integer', 'number', 'array', 'object', 'mixed' );
	private const ENTRY_FILTER_TYPES        = array( 'boolean', 'multiOption', 'number', 'option', 'text' );
	private const ENTRY_DRAWER_LAYOUTS      = array( 'auto', 'sections', 'tabs' );
	private const RELATED_ENTRY_MODES       = array( 'manage', 'readonly', 'actions' );
	private const DESTRUCTIVE_MODES         = array( 'delete', 'trash', 'archive', 'revoke', 'deactivate' );

	/**
	 * @return array{0: ModuleContractDefinition, 1: array<string,ModuleParsedMethod>}
	 */
	public function parse_file( string $file ): array {
		if ( ! is_file( $file ) ) {
			throw Errors::invariant( "Module boot file is missing: {$file}." );
		}

		$stmts = $this->parse_statements( $file, 'module boot file' );

		$namespace = '';
		$uses      = array();
		$classes   = array();
		$this->assert_no_top_level_return( $stmts, $file );
		$this->scan_statements( $stmts, $namespace, $uses, $classes );

		if ( 1 !== count( $classes ) ) {
			throw Errors::invariant( "Module boot file {$file} must define exactly one class extending Onumia\\Modules\\Module." );
		}

		$class_info = $classes[0];
		$contract   = $this->class_contract( $class_info['class'], $class_info['namespace'], $class_info['uses'], $file );
		$methods    = array_merge(
			$this->trait_methods( $file, $class_info['class'], $class_info['namespace'], $class_info['uses'] ),
			$this->methods( $class_info['class'], $class_info['namespace'], $class_info['uses'], $file )
		);
		$definition = $this->build_contract( $class_info['name'], $contract, $methods, $file );

		return array( $definition, $methods );
	}

	/**
	 * @return array<Stmt>
	 */
	private function parse_statements( string $file, string $label ): array {
		$contents = file_get_contents( $file );
		try {
			return ( new ParserFactory() )->createForNewestSupportedVersion()->parse( false === $contents ? '' : $contents ) ?? array();
		} catch ( Error $error ) {
			throw Errors::invariant( "PHP syntax error in {$label}: " . $error->getMessage() );
		}
	}

	/**
	 * @param array<Stmt> $stmts
	 * @param array<string,string> $uses
	 * @param list<array{name:string,class:Class_,namespace:string,uses:array<string,string>}> $classes
	 */
	private function scan_statements( array $stmts, string $namespace, array $uses, array &$classes ): void {
		foreach ( $stmts as $stmt ) {
			if ( $stmt instanceof Namespace_ ) {
				$this->scan_statements( $stmt->stmts, null === $stmt->name ? '' : $stmt->name->toString(), array(), $classes );
				continue;
			}

			if ( $stmt instanceof Use_ ) {
				foreach ( $stmt->uses as $use ) {
					$alias          = $use->alias instanceof Identifier ? $use->alias->toString() : $use->name->getLast();
					$uses[ $alias ] = $use->name->toString();
				}
				continue;
			}

			if ( ! $stmt instanceof Class_ || null === $stmt->name || null === $stmt->extends ) {
				continue;
			}

			if ( 'Onumia\\Modules\\Module' !== $this->resolve_name( $stmt->extends, $namespace, $uses ) ) {
				continue;
			}

			$class_name = '' === $namespace ? $stmt->name->toString() : $namespace . '\\' . $stmt->name->toString();
			$classes[]  = array(
				'name'      => $class_name,
				'class'     => $stmt,
				'namespace' => $namespace,
				'uses'      => $uses,
			);
		}
	}

	/**
	 * @param array<Stmt> $stmts
	 * @param array<string,string> $uses
	 * @param array<string,array{name:string,trait:Trait_,namespace:string,uses:array<string,string>}> $traits
	 */
	private function scan_trait_statements( array $stmts, string $namespace, array $uses, array &$traits ): void {
		foreach ( $stmts as $stmt ) {
			if ( $stmt instanceof Namespace_ ) {
				$this->scan_trait_statements( $stmt->stmts, null === $stmt->name ? '' : $stmt->name->toString(), array(), $traits );
				continue;
			}

			if ( $stmt instanceof Use_ ) {
				foreach ( $stmt->uses as $use ) {
					$alias          = $use->alias instanceof Identifier ? $use->alias->toString() : $use->name->getLast();
					$uses[ $alias ] = $use->name->toString();
				}
				continue;
			}

			if ( ! $stmt instanceof Trait_ || null === $stmt->name ) {
				continue;
			}

			$trait_name            = '' === $namespace ? $stmt->name->toString() : $namespace . '\\' . $stmt->name->toString();
			$traits[ $trait_name ] = array(
				'name'      => $trait_name,
				'trait'     => $stmt,
				'namespace' => $namespace,
				'uses'      => $uses,
			);
		}
	}

	/**
	 * @param array<Stmt> $stmts Statements.
	 */
	private function assert_no_top_level_return( array $stmts, string $file ): void {
		foreach ( $stmts as $stmt ) {
			if ( $stmt instanceof Namespace_ ) {
				$this->assert_no_top_level_return( $stmt->stmts, $file );
				continue;
			}

			if ( $stmt instanceof Return_ ) {
				throw Errors::invariant( "Module boot file {$file} must not return a class name; the module class is discovered from attributes." );
			}
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
	 * @param array<string,string> $uses Uses.
	 * @return array{default_enabled:bool,capability:string,feature_flag:?string,settings:array<string,array<string,mixed>>}
	 */
	private function class_contract( Class_ $class, string $namespace, array $uses, string $file ): array {
		foreach ( $class->getMethods() as $method ) {
			if ( 'contract' === $method->name->toString() ) {
				throw Errors::invariant( "Module boot file {$file} must use PHP attributes instead of contract()." );
			}
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		$module_attributes = $this->attributes_named( $class->attrGroups, self::ATTRIBUTE_MODULE_CONTRACT, $namespace, $uses );
		if ( 1 !== count( $module_attributes ) ) {
			throw Errors::invariant( "Module boot file {$file} must define exactly one ModuleContract attribute." );
		}

		$module_arguments = $this->attribute_arguments( $module_attributes[0], $file, $namespace, $uses );
		$this->assert_known_named_arguments( $module_arguments['named'], array( 'default_enabled', 'capability', 'feature_flag' ), "ModuleContract attribute in {$file}" );

		$default_enabled = $this->argument_value( $module_arguments, 'default_enabled', 0, false );
		if ( ! is_bool( $default_enabled ) ) {
			throw Errors::invariant( "ModuleContract attribute in {$file} default_enabled must be boolean." );
		}

		$capability = $this->argument_value( $module_arguments, 'capability', 1, 'manage_options' );
		if ( ! is_string( $capability ) || '' === $capability ) {
			throw Errors::invariant( "ModuleContract attribute in {$file} capability must be a string." );
		}

		$feature_flag = $this->argument_value( $module_arguments, 'feature_flag', 2, null );
		if ( null !== $feature_flag && ( ! is_string( $feature_flag ) || '' === $feature_flag ) ) {
			throw Errors::invariant( "ModuleContract attribute in {$file} feature_flag must be a non-empty string or null." );
		}

		return array(
			'default_enabled' => $default_enabled,
			'capability'      => $capability,
			'feature_flag'    => $feature_flag,
			'settings'        => $this->settings_from_attributes( $class, $file, $namespace, $uses ),
		);
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return array<string,ModuleParsedMethod>
	 */
	private function methods( Class_|Trait_ $class, string $namespace, array $uses, string $file ): array {
		$methods = array();
		foreach ( $class->getMethods() as $method ) {
			$name = $method->name->toString();
			if ( 'contract' === $name || '__construct' === $name ) {
				continue;
			}

			$required = 0;
			foreach ( $method->params as $param ) {
				if ( null === $param->default ) {
					++$required;
				}
			}

			$object_shapes = $this->object_shape_attributes( $method, $file, $namespace, $uses );
			$methods[ $name ] = array(
				'required'       => $required,
				'total'          => count( $method->params ),
				'public'         => $method->isPublic(),
				'static'         => $method->isStatic(),
				'returnsItemsTotalShape' => $this->returns_items_total_shape( $method ),
				'actions'        => $this->callable_attributes( $method, self::ATTRIBUTE_ACTION, $file, $namespace, $uses ),
				'dataSources'    => $this->callable_attributes( $method, self::ATTRIBUTE_DATA_SOURCE, $file, $namespace, $uses ),
				'entries'        => $this->entry_attributes( $method, $file, $namespace, $uses ),
				'entryFields'    => $this->entry_field_attributes( $method, $file, $namespace, $uses ),
				'entrySections'  => $this->entry_section_attributes( $method, $file, $namespace, $uses ),
				'relatedEntries' => $this->related_entry_attributes( $method, $file, $namespace, $uses ),
				'publicRoutes'   => $this->public_route_attributes( $method, $file, $namespace, $uses ),
				'jobs'           => $this->job_attributes( $method, $file, $namespace, $uses ),
				'objectShapes'    => $object_shapes,
				'inputs'         => $this->input_attributes( $method, $file, $namespace, $uses, $object_shapes ),
				'hooks'          => array_merge(
					$this->hook_attributes( $method, self::ATTRIBUTE_WP_ACTION, 'action', $file, $namespace, $uses, $required, count( $method->params ) ),
					$this->hook_attributes( $method, self::ATTRIBUTE_WP_FILTER, 'filter', $file, $namespace, $uses, $required, count( $method->params ) )
				),
			);
		}

		return $methods;
	}

	private function returns_items_total_shape( ClassMethod $method ): bool {
		$comment = $method->getDocComment();
		if ( null !== $comment && $this->docblock_returns_items_total_shape( $comment->getText() ) ) {
			return true;
		}

		foreach ( $method->stmts ?? array() as $stmt ) {
			if ( $this->node_returns_items_total_shape( $stmt ) ) {
				return true;
			}
		}

		return false;
	}

	private function docblock_returns_items_total_shape( string $comment ): bool {
		if ( 1 !== preg_match( '/@return\s+([^\n\r*]+)/', $comment, $matches ) ) {
			return false;
		}

		$return_type = preg_replace( '/\s+/', '', $matches[1] );
		return is_string( $return_type )
			&& str_starts_with( $return_type, 'array{' )
			&& str_contains( $return_type, 'items:' )
			&& str_contains( $return_type, 'total:' );
	}

	private function node_returns_items_total_shape( Node $node ): bool {
		if ( $node instanceof Return_ && $node->expr instanceof Array_ ) {
			return $this->array_has_items_total_keys( $node->expr );
		}

		foreach ( $node->getSubNodeNames() as $name ) {
			$value = $node->$name;
			if ( $value instanceof Node && $this->node_returns_items_total_shape( $value ) ) {
				return true;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			foreach ( $value as $item ) {
				if ( $item instanceof Node && $this->node_returns_items_total_shape( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function array_has_items_total_keys( Array_ $array ): bool {
		$keys = array();
		foreach ( $array->items as $item ) {
			if ( ! ( $item->key instanceof String_ ) ) {
				continue;
			}

			$keys[ $item->key->value ] = true;
		}

		return isset( $keys['items'], $keys['total'] );
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return array<string,ModuleParsedMethod>
	 */
	private function trait_methods( string $boot_file, Class_|Trait_ $class, string $namespace, array $uses ): array {
		$methods = array();
		$seen    = array();
		foreach ( $this->used_trait_names( $class, $namespace, $uses ) as $trait_name ) {
			$methods = array_merge( $methods, $this->trait_methods_from_name( $boot_file, $trait_name, $seen ) );
		}

		return $methods;
	}

	/**
	 * @param array<string,bool> $seen Seen trait names.
	 * @return array<string,ModuleParsedMethod>
	 */
	private function trait_methods_from_name( string $boot_file, string $trait_name, array &$seen ): array {
		if ( isset( $seen[ $trait_name ] ) ) {
			return array();
		}

		$seen[ $trait_name ] = true;
		$file                = $this->trait_file( $boot_file, $trait_name );
		if ( null === $file ) {
			return array();
		}

		$traits = array();
		$this->scan_trait_statements( $this->parse_statements( $file, 'module PHP file' ), '', array(), $traits );
		if ( ! isset( $traits[ $trait_name ] ) ) {
			return array();
		}

		$trait   = $traits[ $trait_name ];
		$methods = array();
		foreach ( $this->used_trait_names( $trait['trait'], $trait['namespace'], $trait['uses'] ) as $nested_trait_name ) {
			$methods = array_merge( $methods, $this->trait_methods_from_name( $boot_file, $nested_trait_name, $seen ) );
		}

		return array_merge( $methods, $this->methods( $trait['trait'], $trait['namespace'], $trait['uses'], $file ) );
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return string[]
	 */
	private function used_trait_names( Class_|Trait_ $class, string $namespace, array $uses ): array {
		$trait_names = array();
		foreach ( $class->stmts as $stmt ) {
			if ( ! $stmt instanceof TraitUse ) {
				continue;
			}

			foreach ( $stmt->traits as $trait ) {
				$trait_names[] = $this->resolve_name( $trait, $namespace, $uses );
			}
		}

		return $trait_names;
	}

	private function trait_file( string $boot_file, string $trait_name ): ?string {
		$directory = dirname( $boot_file );
		$short     = basename( str_replace( '\\', '/', $trait_name ) );
		$candidates = array(
			$directory . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $short . '.php',
			$directory . DIRECTORY_SEPARATOR . $short . '.php',
		);

		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}

		$src = $directory . DIRECTORY_SEPARATOR . 'src';
		if ( ! is_dir( $src ) ) {
			return null;
		}

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) {
			if ( $entry instanceof \SplFileInfo && $entry->isFile() && $short . '.php' === $entry->getFilename() ) {
				return $entry->getPathname();
			}
		}

		return null;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return array<string,array<string,mixed>>
	 */
	private function settings_from_attributes( Class_ $class, string $file, string $namespace, array $uses ): array {
		$settings = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		foreach ( $this->attributes_named( $class->attrGroups, self::ATTRIBUTE_SETTING, $namespace, $uses ) as $attribute ) {
			$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
			$this->assert_known_named_arguments( $arguments['named'], array( 'name', 'type', 'default', 'allowed', 'min', 'max', 'format' ), "Setting attribute in {$file}" );

			$field = $this->field_definition( $arguments, $file, "Setting attribute in {$file}", false );
			$name  = $field['name'];
			if ( isset( $settings[ $name ] ) ) {
				throw Errors::invariant( "Setting attribute in {$file} duplicates setting {$name}." );
			}

			unset( $field['name'] );
			$settings[ $name ] = $field;
		}

		return $settings;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return list<array<string,mixed>>
	 */
	private function callable_attributes( ClassMethod $method, string $attribute_class, string $file, string $namespace, array $uses ): array {
		$attributes = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		foreach ( $this->attributes_named( $method->attrGroups, $attribute_class, $namespace, $uses ) as $attribute ) {
			$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
			$extra     = self::ATTRIBUTE_ACTION === $attribute_class ? array( 'intent' ) : array( 'shape', 'pagination' );
			$this->assert_known_named_arguments( $arguments['named'], array_merge( array( 'name', 'surface', 'capability' ), $extra ), "{$this->short_attribute_name( $attribute_class )} attribute in {$file}" );

			$entry = array();
			if ( $this->has_argument( $arguments, 'name', 0 ) ) {
				$entry['name'] = $this->argument_value( $arguments, 'name', 0, null );
			}

			$entry['surface'] = $this->argument_value( $arguments, 'surface', 1, 'backend' );
			if ( $this->has_argument( $arguments, 'capability', 2 ) ) {
				$entry['capability'] = $this->argument_value( $arguments, 'capability', 2, null );
			}

			if ( self::ATTRIBUTE_ACTION === $attribute_class ) {
				$entry['intent'] = $this->argument_value( $arguments, 'intent', 3, 'custom' );
			} else {
				$entry['shape'] = $this->argument_value( $arguments, 'shape', 3, 'options' );
				$entry['pagination'] = $this->argument_value( $arguments, 'pagination', 4, 'client' );
			}

			$attributes[] = $entry;
		}

		return $attributes;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return list<ModuleParsedPublicRoute>
	 */
	private function public_route_attributes( ClassMethod $method, string $file, string $namespace, array $uses ): array {
		$routes = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		foreach ( $this->attributes_named( $method->attrGroups, self::ATTRIBUTE_PUBLIC_ROUTE, $namespace, $uses ) as $attribute ) {
			$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
			$this->assert_known_named_arguments( $arguments['named'], array( 'path', 'method', 'auth', 'rate_limit' ), "PublicRoute attribute in {$file}" );

			$first_positional = $this->argument_value( $arguments, 'path', 0, null );
			$method_first     = is_string( $first_positional ) && in_array( $first_positional, self::HTTP_METHODS, true ) && $this->has_argument( $arguments, 'path', 1 );
			$path             = $method_first ? $this->argument_value( $arguments, 'path', 1, null ) : $first_positional;
			if ( ! is_string( $path ) || '' === trim( $path, '/' ) ) {
				throw Errors::invariant( "PublicRoute attribute in {$file} path must be a non-empty string." );
			}

			$method_value = $method_first ? $first_positional : $this->argument_value( $arguments, 'method', 1, 'POST' );
			if ( ! is_string( $method_value ) || ! in_array( $method_value, self::HTTP_METHODS, true ) ) {
				throw Errors::invariant( "PublicRoute attribute in {$file} method is invalid." );
			}

			$auth = $this->argument_value( $arguments, 'auth', 2, 'none' );
			if ( ! is_string( $auth ) || ! in_array( $auth, self::ROUTE_AUTH_MODES, true ) ) {
				throw Errors::invariant( "PublicRoute attribute in {$file} auth is invalid." );
			}

			$rate_limit = $this->route_rate_limit( $this->argument_value( $arguments, 'rate_limit', 3, 60 ), $file );

			$routes[] = array(
				'path'       => '/' . trim( $path, '/' ),
				'method'     => $method_value,
				'auth'       => $auth,
				'rate_limit' => $rate_limit,
			);
		}

		return $routes;
	}

	private function route_rate_limit( mixed $value, string $file ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}

		if ( is_string( $value ) && 1 === preg_match( '/^([0-9]+)\\/(minute|hour|day)$/', $value, $match ) ) {
			return (int) $match[1];
		}

		throw Errors::invariant( "PublicRoute attribute in {$file} rate_limit must be a non-negative integer or a string like 60/hour." );
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return list<ModuleParsedJob>
	 */
	private function job_attributes( ClassMethod $method, string $file, string $namespace, array $uses ): array {
		$jobs = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		foreach ( $this->attributes_named( $method->attrGroups, self::ATTRIBUTE_JOB, $namespace, $uses ) as $attribute ) {
			$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
			$this->assert_known_named_arguments( $arguments['named'], array( 'name', 'schedule', 'enabled', 'run_on_activation' ), "Job attribute in {$file}" );

			$name = $this->argument_value( $arguments, 'name', 0, null );
			if ( null !== $name && ( ! is_string( $name ) || '' === $name ) ) {
				throw Errors::invariant( "Job attribute in {$file} name must be a non-empty string or null." );
			}

			$schedule = $this->argument_value( $arguments, 'schedule', 1, 'daily' );
			if ( ! is_string( $schedule ) || ! in_array( $schedule, self::JOB_SCHEDULES, true ) ) {
				throw Errors::invariant( "Job attribute in {$file} schedule is invalid." );
			}

			$enabled = $this->argument_value( $arguments, 'enabled', 2, true );
			if ( ! is_bool( $enabled ) ) {
				throw Errors::invariant( "Job attribute in {$file} enabled must be boolean." );
			}

			$run_on_activation = $this->argument_value( $arguments, 'run_on_activation', 3, false );
			if ( ! is_bool( $run_on_activation ) ) {
				throw Errors::invariant( "Job attribute in {$file} run_on_activation must be boolean." );
			}

			$jobs[] = array(
				'name'              => $name,
				'schedule'          => $schedule,
				'enabled'           => $enabled,
				'run_on_activation' => $run_on_activation,
			);
		}

		return $jobs;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return array<string,array<string,string>>
	 */
	private function object_shape_attributes( ClassMethod $method, string $file, string $namespace, array $uses ): array {
		$shapes = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		foreach ( $this->attributes_named( $method->attrGroups, self::ATTRIBUTE_OBJECT_SHAPE, $namespace, $uses ) as $attribute ) {
			$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
			$this->assert_known_named_arguments( $arguments['named'], array( 'name', 'fields' ), "ObjectShape attribute in {$file}" );

			$name = $this->identifier_argument( $arguments, 'name', 0, "ObjectShape attribute in {$file} name" );
			if ( isset( $shapes[ $name ] ) ) {
				throw Errors::invariant( "ObjectShape attribute in {$file} duplicates shape {$name}." );
			}

			$fields = $this->object_shape_fields( $this->argument_value( $arguments, 'fields', 1, array() ), "ObjectShape attribute in {$file} shape {$name}" );
			if ( array() === $fields ) {
				throw Errors::invariant( "ObjectShape attribute in {$file} shape {$name} fields must not be empty." );
			}

			$shapes[ $name ] = $fields;
		}

		return $shapes;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @param array<string,array<string,string>> $object_shapes Object shapes.
	 * @return array<string,array<string,mixed>>
	 */
	private function input_attributes( ClassMethod $method, string $file, string $namespace, array $uses, array $object_shapes ): array {
		$inputs = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		foreach ( $this->attributes_named( $method->attrGroups, self::ATTRIBUTE_INPUT, $namespace, $uses ) as $attribute ) {
			$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
			$this->assert_known_named_arguments( $arguments['named'], array( 'name', 'type', 'default', 'allowed', 'min', 'max', 'format', 'required' ), "Input attribute in {$file}" );

			$field = $this->field_definition( $arguments, $file, "Input attribute in {$file}", true );
			$name  = $field['name'];
			if ( isset( $inputs[ $name ] ) ) {
				throw Errors::invariant( "Input attribute in {$file} duplicates input {$name}." );
			}

			unset( $field['name'] );
			if ( 'object' === $field['type'] && isset( $object_shapes[ $name ] ) ) {
				$field['shape'] = $object_shapes[ $name ];
			}
			$inputs[ $name ] = $field;
		}

		foreach ( $object_shapes as $name => $_shape ) {
			if ( ! isset( $inputs[ $name ] ) ) {
				throw Errors::invariant( "ObjectShape attribute in {$file} references missing input {$name}." );
			}
			if ( 'object' !== $inputs[ $name ]['type'] ) {
				throw Errors::invariant( "ObjectShape attribute in {$file} references non-object input {$name}." );
			}
		}

		foreach ( $inputs as $name => $input ) {
			if ( 'object' === $input['type'] && ! isset( $input['shape'] ) ) {
				throw Errors::invariant( "Input attribute in {$file} object input {$name} must declare an ObjectShape." );
			}
		}

		return $inputs;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return list<ModuleParsedEntry>
	 */
	private function entry_attributes( ClassMethod $method, string $file, string $namespace, array $uses ): array {
		$entries = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		foreach ( $this->attributes_named( $method->attrGroups, self::ATTRIBUTE_ENTRIES, $namespace, $uses ) as $attribute ) {
			$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
			$this->assert_known_named_arguments(
				$arguments['named'],
				array( 'name', 'singular', 'plural', 'key', 'storage', 'setting', 'source', 'table', 'create_action', 'update_action', 'delete_action', 'close_on_success', 'destructive_mode' ),
				"Entries attribute in {$file}"
			);

			$entry = array(
				'name'             => $this->string_argument( $arguments, 'name', 0, "Entries attribute in {$file} name" ),
				'singular'         => $this->string_argument( $arguments, 'singular', 1, "Entries attribute in {$file} singular" ),
				'plural'           => $this->string_argument( $arguments, 'plural', 2, "Entries attribute in {$file} plural" ),
				'key'              => $this->entry_field_path_argument( $arguments, 'key', 3, "Entries attribute in {$file} key" ),
				'storage'          => $this->string_argument( $arguments, 'storage', 4, "Entries attribute in {$file} storage" ),
				'setting'          => $this->optional_string_argument( $arguments, 'setting', 5, "Entries attribute in {$file} setting" ),
				'source'           => $this->optional_string_argument( $arguments, 'source', 6, "Entries attribute in {$file} source" ),
				'table'            => $this->optional_string_argument( $arguments, 'table', 7, "Entries attribute in {$file} table" ),
				'create_action'    => $this->optional_string_argument( $arguments, 'create_action', 8, "Entries attribute in {$file} create_action" ),
				'update_action'    => $this->optional_string_argument( $arguments, 'update_action', 9, "Entries attribute in {$file} update_action" ),
				'delete_action'    => $this->optional_string_argument( $arguments, 'delete_action', 10, "Entries attribute in {$file} delete_action" ),
				'close_on_success' => $this->bool_argument( $arguments, 'close_on_success', 11, true, "Entries attribute in {$file} close_on_success" ),
				'destructive_mode' => $this->optional_string_argument( $arguments, 'destructive_mode', 12, "Entries attribute in {$file} destructive_mode" ) ?? 'delete',
			);

			if ( ! in_array( $entry['storage'], self::ENTRY_STORAGES, true ) ) {
				throw Errors::invariant( "Entries attribute in {$file} entry {$entry['name']} storage is invalid." );
			}

			if ( ! in_array( $entry['destructive_mode'], self::DESTRUCTIVE_MODES, true ) ) {
				throw Errors::invariant( "Entries attribute in {$file} entry {$entry['name']} destructive_mode is invalid." );
			}

			$entries[] = $entry;
		}

		return $entries;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return list<ModuleParsedEntryField>
	 */
	private function entry_field_attributes( ClassMethod $method, string $file, string $namespace, array $uses ): array {
		$fields = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		foreach ( $this->attributes_named( $method->attrGroups, self::ATTRIBUTE_ENTRY_FIELD, $namespace, $uses ) as $attribute ) {
			$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
			$this->assert_known_named_arguments(
				$arguments['named'],
				array( 'name', 'type', 'label', 'default', 'allowed', 'min', 'max', 'format', 'required', 'primary', 'list', 'filter', 'filter_type', 'options', 'optionsSource', 'section', 'create', 'update', 'read_only', 'order', 'visible_when', 'props' ),
				"EntryField attribute in {$file}"
			);

			$name = $this->entry_field_path_argument( $arguments, 'name', 0, "EntryField attribute in {$file} name" );
			$type = $this->string_argument( $arguments, 'type', 1, "EntryField attribute in {$file} field {$name} type" );
			if ( ! in_array( $type, self::SETTING_TYPES, true ) ) {
				throw Errors::invariant( "EntryField attribute in {$file} field {$name} has invalid type." );
			}

			$field = array(
				'name'          => $name,
				'type'          => $type,
				'label'         => $this->optional_string_argument( $arguments, 'label', 2, "EntryField attribute in {$file} field {$name} label" ),
				'primary'       => $this->bool_argument( $arguments, 'primary', 9, false, "EntryField attribute in {$file} field {$name} primary" ),
				'list'          => $this->bool_argument( $arguments, 'list', 10, false, "EntryField attribute in {$file} field {$name} list" ),
				'filter'        => $this->bool_argument( $arguments, 'filter', 11, false, "EntryField attribute in {$file} field {$name} filter" ),
				'filter_type'   => $this->optional_string_argument( $arguments, 'filter_type', 12, "EntryField attribute in {$file} field {$name} filter_type" ),
				'options'       => $this->options_argument( $arguments, 'options', 13, "EntryField attribute in {$file} field {$name} options" ),
				'optionsSource' => $this->optional_object_argument( $arguments, 'optionsSource', 14, "EntryField attribute in {$file} field {$name} optionsSource" ),
				'section'       => $this->optional_string_argument( $arguments, 'section', 15, "EntryField attribute in {$file} field {$name} section" ),
				'create'        => $this->bool_argument( $arguments, 'create', 16, true, "EntryField attribute in {$file} field {$name} create" ),
				'update'        => $this->bool_argument( $arguments, 'update', 17, true, "EntryField attribute in {$file} field {$name} update" ),
				'read_only'     => $this->bool_argument( $arguments, 'read_only', 18, false, "EntryField attribute in {$file} field {$name} read_only" ),
				'order'         => $this->int_argument( $arguments, 'order', 19, 0, "EntryField attribute in {$file} field {$name} order" ),
				'visible_when'  => $this->optional_object_argument( $arguments, 'visible_when', 20, "EntryField attribute in {$file} field {$name} visible_when" ),
				'props'         => $this->object_argument( $arguments, 'props', 21, "EntryField attribute in {$file} field {$name} props" ),
			);

			if ( $this->has_argument( $arguments, 'default', 3 ) ) {
				$default = $this->argument_value( $arguments, 'default', 3, null );
				$this->assert_value_matches_type( $default, $type, "EntryField attribute in {$file} field {$name} default" );
				$field['default'] = $default;
			}

			if ( $this->has_argument( $arguments, 'allowed', 4 ) ) {
				$allowed = $this->argument_value( $arguments, 'allowed', 4, array() );
				if ( ! is_array( $allowed ) ) {
					throw Errors::invariant( "EntryField attribute in {$file} field {$name} allowed must be an array." );
				}
				foreach ( $allowed as $allowed_value ) {
					$this->assert_value_matches_type( $allowed_value, $type, "EntryField attribute in {$file} field {$name} allowed value" );
				}
				$field['allowed'] = array_values( $allowed );
			}

			foreach ( array(
				'min' => 5,
				'max' => 6,
			) as $range_key => $position ) {
				if ( ! $this->has_argument( $arguments, $range_key, $position ) ) {
					continue;
				}
				if ( ! in_array( $type, array( 'integer', 'number' ), true ) ) {
					throw Errors::invariant( "EntryField attribute in {$file} field {$name} {$range_key} is only supported for numeric fields." );
				}
				$range = $this->argument_value( $arguments, $range_key, $position, null );
				if ( ! is_int( $range ) && ! is_float( $range ) ) {
					throw Errors::invariant( "EntryField attribute in {$file} field {$name} {$range_key} must be numeric." );
				}
				$field[ $range_key ] = $range;
			}

			if ( isset( $field['min'], $field['max'] ) && $field['min'] > $field['max'] ) {
				throw Errors::invariant( "EntryField attribute in {$file} field {$name} min must not exceed max." );
			}

			if ( $this->has_argument( $arguments, 'format', 7 ) ) {
				$format = $this->optional_string_argument( $arguments, 'format', 7, "EntryField attribute in {$file} field {$name} format" );
				if ( null !== $format && ! in_array( $format, self::FIELD_FORMATS, true ) ) {
					throw Errors::invariant( "EntryField attribute in {$file} field {$name} format is invalid." );
				}
				if ( null !== $format && 'string' !== $type ) {
					throw Errors::invariant( "EntryField attribute in {$file} field {$name} format is only supported for string fields." );
				}
				$field['format'] = $format;
			}

			if ( $this->has_argument( $arguments, 'required', 8 ) ) {
				$field['required'] = $this->bool_argument( $arguments, 'required', 8, false, "EntryField attribute in {$file} field {$name} required" );
			}

			if ( null !== $field['filter_type'] && ! in_array( $field['filter_type'], self::ENTRY_FILTER_TYPES, true ) ) {
				throw Errors::invariant( "EntryField attribute in {$file} field {$field['name']} filter_type is invalid." );
			}

			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return list<ModuleParsedEntrySection>
	 */
	private function entry_section_attributes( ClassMethod $method, string $file, string $namespace, array $uses ): array {
		$sections = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		foreach ( $this->attributes_named( $method->attrGroups, self::ATTRIBUTE_ENTRY_SECTION, $namespace, $uses ) as $attribute ) {
			$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
			$this->assert_known_named_arguments( $arguments['named'], array( 'name', 'label', 'description', 'order', 'layout' ), "EntrySection attribute in {$file}" );

			$section = array(
				'name'        => $this->identifier_argument( $arguments, 'name', 0, "EntrySection attribute in {$file} name" ),
				'label'       => $this->string_argument( $arguments, 'label', 1, "EntrySection attribute in {$file} label" ),
				'description' => $this->optional_string_argument( $arguments, 'description', 2, "EntrySection attribute in {$file} description" ),
				'order'       => $this->int_argument( $arguments, 'order', 3, 0, "EntrySection attribute in {$file} order" ),
				'layout'      => $this->optional_string_argument( $arguments, 'layout', 4, "EntrySection attribute in {$file} layout" ) ?? 'auto',
			);

			if ( ! in_array( $section['layout'], self::ENTRY_DRAWER_LAYOUTS, true ) ) {
				throw Errors::invariant( "EntrySection attribute in {$file} section {$section['name']} layout is invalid." );
			}

			$sections[] = $section;
		}

		return $sections;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return list<ModuleParsedRelatedEntry>
	 */
	private function related_entry_attributes( ClassMethod $method, string $file, string $namespace, array $uses ): array {
		$related_entries = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		foreach ( $this->attributes_named( $method->attrGroups, self::ATTRIBUTE_RELATED_ENTRIES, $namespace, $uses ) as $attribute ) {
			$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
			$this->assert_known_named_arguments( $arguments['named'], array( 'name', 'entry', 'local_key', 'foreign_key', 'label', 'mode', 'order' ), "RelatedEntries attribute in {$file}" );

			$related = array(
				'name'        => $this->identifier_argument( $arguments, 'name', 0, "RelatedEntries attribute in {$file} name" ),
				'entry'       => $this->identifier_argument( $arguments, 'entry', 1, "RelatedEntries attribute in {$file} entry" ),
				'local_key'   => $this->entry_field_path_argument( $arguments, 'local_key', 2, "RelatedEntries attribute in {$file} local_key" ),
				'foreign_key' => $this->entry_field_path_argument( $arguments, 'foreign_key', 3, "RelatedEntries attribute in {$file} foreign_key" ),
				'label'       => $this->optional_string_argument( $arguments, 'label', 4, "RelatedEntries attribute in {$file} label" ),
				'mode'        => $this->optional_string_argument( $arguments, 'mode', 5, "RelatedEntries attribute in {$file} mode" ) ?? 'manage',
				'order'       => $this->int_argument( $arguments, 'order', 6, 0, "RelatedEntries attribute in {$file} order" ),
			);

			if ( ! in_array( $related['mode'], self::RELATED_ENTRY_MODES, true ) ) {
				throw Errors::invariant( "RelatedEntries attribute in {$file} related {$related['name']} mode is invalid." );
			}

			$related_entries[] = $related;
		}

		return $related_entries;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return list<array{type:string,hook:string,priority:int,accepted_args:int}>
	 */
	private function hook_attributes( ClassMethod $method, string $attribute_class, string $type, string $file, string $namespace, array $uses, int $required_parameters, int $total_parameters ): array {
		$hooks = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		foreach ( $this->attributes_named( $method->attrGroups, $attribute_class, $namespace, $uses ) as $attribute ) {
			$label     = "{$this->short_attribute_name( $attribute_class )} attribute in {$file}";
			$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
			$this->assert_known_named_arguments( $arguments['named'], array( 'hook', 'priority', 'accepted_args' ), $label );

			$hook = $this->argument_value( $arguments, 'hook', 0, null );
			if ( ! is_string( $hook ) || '' === $hook ) {
				throw Errors::invariant( "{$label} hook must be a non-empty string." );
			}

			$priority = $this->argument_value( $arguments, 'priority', 1, 10 );
			if ( ! is_int( $priority ) ) {
				throw Errors::invariant( "{$label} priority must be an integer." );
			}

			$accepted_args = $this->argument_value( $arguments, 'accepted_args', 2, $total_parameters );
			if ( null === $accepted_args ) {
				$accepted_args = $total_parameters;
			}

			if ( ! is_int( $accepted_args ) || $accepted_args < 0 ) {
				throw Errors::invariant( "{$label} accepted_args must be a non-negative integer." );
			}

			if ( $required_parameters > $accepted_args || $accepted_args > $total_parameters ) {
				throw Errors::invariant( "{$label} accepted_args must cover required parameters and not exceed total parameters." );
			}

			$hooks[] = array(
				'type'          => $type,
				'hook'          => $hook,
				'priority'      => $priority,
				'accepted_args' => $accepted_args,
			);
		}

		return $hooks;
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 * @return array{name:string,type:string,default?:mixed,allowed?:list<mixed>,min?:int|float,max?:int|float,format?:string,required?:bool}
	 */
	private function field_definition( array $arguments, string $file, string $label, bool $allow_required ): array {
		$name = $this->argument_value( $arguments, 'name', 0, null );
		if ( ! is_string( $name ) || '' === $name ) {
			throw Errors::invariant( "{$label} name must be a non-empty string." );
		}

		$type = $this->argument_value( $arguments, 'type', 1, null );
		if ( ! is_string( $type ) || ! in_array( $type, self::SETTING_TYPES, true ) ) {
			throw Errors::invariant( "{$label} field {$name} has invalid type." );
		}

		$field = array(
			'name' => $name,
			'type' => $type,
		);

		if ( $this->has_argument( $arguments, 'default', 2 ) ) {
			$default = $this->argument_value( $arguments, 'default', 2, null );
			$this->assert_value_matches_type( $default, $type, "{$label} field {$name} default" );
			$field['default'] = $default;
		}

		if ( $this->has_argument( $arguments, 'allowed', 3 ) ) {
			$allowed = $this->argument_value( $arguments, 'allowed', 3, array() );
			if ( ! is_array( $allowed ) ) {
				throw Errors::invariant( "{$label} field {$name} allowed must be an array." );
			}

			foreach ( $allowed as $allowed_value ) {
				$this->assert_value_matches_type( $allowed_value, $type, "{$label} field {$name} allowed value" );
			}
			$field['allowed'] = array_values( $allowed );
		}

		foreach (
			array(
				'min' => 4,
				'max' => 5,
			) as $range_key => $position
		) {
			if ( ! $this->has_argument( $arguments, $range_key, $position ) ) {
				continue;
			}

			if ( ! in_array( $type, array( 'integer', 'number' ), true ) ) {
				throw Errors::invariant( "{$label} field {$name} {$range_key} is only supported for numeric fields." );
			}

			$range = $this->argument_value( $arguments, $range_key, $position, null );
			if ( ! is_int( $range ) && ! is_float( $range ) ) {
				throw Errors::invariant( "{$label} field {$name} {$range_key} must be numeric." );
			}
			$field[ $range_key ] = $range;
		}

		if ( isset( $field['min'], $field['max'] ) && $field['min'] > $field['max'] ) {
			throw Errors::invariant( "{$label} field {$name} min must not exceed max." );
		}

		if ( $this->has_argument( $arguments, 'format', 6 ) ) {
			$format = $this->argument_value( $arguments, 'format', 6, null );
			if ( ! is_string( $format ) || ! in_array( $format, self::FIELD_FORMATS, true ) ) {
				throw Errors::invariant( "{$label} field {$name} format is invalid." );
			}

			if ( 'string' !== $type ) {
				throw Errors::invariant( "{$label} field {$name} format is only supported for string fields." );
			}
			$field['format'] = $format;
		}

		if ( $allow_required && $this->has_argument( $arguments, 'required', 7 ) ) {
			$required = $this->argument_value( $arguments, 'required', 7, false );
			if ( ! is_bool( $required ) ) {
				throw Errors::invariant( "{$label} field {$name} required must be boolean." );
			}
			$field['required'] = $required;
		}

		if ( array_key_exists( 'default', $field ) ) {
			( new ModuleValueValidator() )->assert_value( $field['default'], $field, "{$label} field {$name} default" );
		}

		return $field;
	}

	/**
	 * @param array{default_enabled:bool,capability:string,feature_flag:?string,settings:array<string,array<string,mixed>>} $contract Contract.
	 * @param array<string,ModuleParsedMethod> $methods Methods.
	 */
	private function build_contract( string $class_name, array $contract, array $methods, string $file ): ModuleContractDefinition {
		$default_enabled = $contract['default_enabled'];
		$capability      = $contract['capability'];
		$settings        = $contract['settings'];
		$actions         = $this->actions( $capability, $methods, $file );
		$sources         = $this->data_sources( $capability, $methods, $file );
		$hooks           = $this->hooks( $methods, $file );
		$entries         = $this->entries( $settings, $actions, $sources, $methods, $file );

		return new ModuleContractDefinition( $class_name, $default_enabled, $capability, $contract['feature_flag'], $settings, $actions, $sources, $hooks, $entries );
	}

	/**
	 * @param array<string,ModuleParsedMethod> $methods Methods.
	 * @return array<string,ModuleDataSource>
	 */
	private function data_sources( string $module_capability, array $methods, string $file ): array {
		$normalized = array();
		foreach ( $methods as $method => $method_info ) {
			foreach ( $method_info['dataSources'] as $source ) {
				$name = $source['name'] ?? $this->callable_name( $method );
				if ( ! is_string( $name ) || '' === $name ) {
					throw Errors::invariant( "DataSource attribute in {$file} name must be a non-empty string." );
				}

				if ( isset( $normalized[ $name ] ) ) {
					throw Errors::invariant( "DataSource attribute in {$file} duplicates data source {$name}." );
				}

				$this->assert_callable_method( 'data source', $name, $method_info, $file );

				$surface = $source['surface'] ?? 'backend';
				if ( ! is_string( $surface ) || ! in_array( $surface, self::SURFACES, true ) ) {
					throw Errors::invariant( "DataSource attribute in {$file} data source {$name} surface is invalid." );
				}

				$capability = $source['capability'] ?? $module_capability;
				if ( ! is_string( $capability ) || '' === $capability ) {
					throw Errors::invariant( "DataSource attribute in {$file} data source {$name} capability must be a string." );
				}

				$shape = $source['shape'] ?? 'options';
				if ( ! is_string( $shape ) || ! in_array( $shape, self::DATA_SOURCE_SHAPES, true ) ) {
					throw Errors::invariant( "DataSource attribute in {$file} data source {$name} shape is invalid." );
				}

				$pagination = $source['pagination'] ?? 'client';
				if ( ! is_string( $pagination ) || ! in_array( $pagination, self::PAGINATION_MODES, true ) ) {
					throw Errors::invariant( "DataSource attribute in {$file} data source {$name} pagination is invalid." );
				}

				if ( 'server' === $pagination ) {
					$page_input      = $method_info['inputs']['page'] ?? null;
					$page_size_input = $method_info['inputs']['pageSize'] ?? null;
					if (
						! is_array( $page_input )
						|| ! is_array( $page_size_input )
						|| 'integer' !== ( $page_input['type'] ?? null )
						|| 'integer' !== ( $page_size_input['type'] ?? null )
					) {
						throw Errors::invariant( "DataSource attribute in {$file} data source {$name} server pagination must declare integer page and pageSize inputs." );
					}
				}

				if ( 'client' === $pagination && $method_info['returnsItemsTotalShape'] ) {
					throw Errors::invariant( "DataSource attribute in {$file} data source {$name} client pagination must return a list, not an items/total object." );
				}

				$normalized[ $name ] = new ModuleDataSource(
					$name,
					$method,
					$surface,
					$capability,
					$method_info['required'],
					$method_info['total'],
					$method_info['inputs'],
					$shape,
					$pagination
				);
			}
		}

		return $normalized;
	}

	/**
	 * @param array<string,ModuleParsedMethod> $methods Methods.
	 * @return array<string,ModuleAction>
	 */
	private function actions( string $module_capability, array $methods, string $file ): array {
		$normalized = array();
		foreach ( $methods as $method => $method_info ) {
			foreach ( $method_info['actions'] as $action ) {
				$name = $action['name'] ?? $this->callable_name( $method );
				if ( ! is_string( $name ) || '' === $name ) {
					throw Errors::invariant( "Action attribute in {$file} name must be a non-empty string." );
				}

				if ( isset( $normalized[ $name ] ) ) {
					throw Errors::invariant( "Action attribute in {$file} duplicates action {$name}." );
				}

				$this->assert_callable_method( 'action', $name, $method_info, $file );

				$surface = $action['surface'] ?? 'backend';
				if ( ! is_string( $surface ) || ! in_array( $surface, self::SURFACES, true ) ) {
					throw Errors::invariant( "Action attribute in {$file} action {$name} surface is invalid." );
				}

				$capability = $action['capability'] ?? $module_capability;
				if ( ! is_string( $capability ) || '' === $capability ) {
					throw Errors::invariant( "Action attribute in {$file} action {$name} capability must be a string." );
				}

				$intent = $action['intent'] ?? 'custom';
				if ( ! is_string( $intent ) || ! in_array( $intent, self::ACTION_INTENTS, true ) ) {
					throw Errors::invariant( "Action attribute in {$file} action {$name} intent is invalid." );
				}

				$normalized[ $name ] = new ModuleAction(
					$name,
					$method,
					$surface,
					$capability,
					$method_info['required'],
					$method_info['total'],
					$method_info['inputs'],
					$intent
				);
			}
		}

		return $normalized;
	}

	/**
	 * @param array<string,array<string,mixed>> $settings Settings.
	 * @param array<string,ModuleAction>       $actions Actions.
	 * @param array<string,ModuleDataSource>   $sources Sources.
	 * @param array<string,ModuleParsedMethod> $methods Methods.
	 * @return array<string,ModuleEntryDefinition>
	 */
	private function entries( array $settings, array $actions, array $sources, array $methods, string $file ): array {
		$entries = array();
		foreach ( $methods as $method_info ) {
			if ( array() === $method_info['entries'] ) {
				continue;
			}

			if (
				count( $method_info['entries'] ) > 1
				&& ( array() !== $method_info['entryFields'] || array() !== $method_info['entrySections'] || array() !== $method_info['relatedEntries'] )
			) {
				throw Errors::invariant( "Entries attribute in {$file} must use one method per entry when fields, sections, or related entries are declared." );
			}

			foreach ( $method_info['entries'] as $entry ) {
				$name = $entry['name'];
				if ( isset( $entries[ $name ] ) ) {
					throw Errors::invariant( "Entries attribute in {$file} duplicates entry {$name}." );
				}

				$fields   = $this->entry_fields( $method_info['entryFields'], $file, $name );
				$sections = $this->entry_sections( $method_info['entrySections'], $file, $name );
				$related  = $this->related_entries( $method_info['relatedEntries'], $file, $name );

				$definition = new ModuleEntryDefinition(
					$name,
					$entry['singular'],
					$entry['plural'],
					$entry['key'],
					$entry['storage'],
					$entry['setting'],
					$entry['source'],
					$entry['table'],
					$entry['create_action'],
					$entry['update_action'],
					$entry['delete_action'],
					$entry['close_on_success'],
					$entry['destructive_mode'],
					$fields,
					$sections,
					$related
				);

				$this->validate_entry_contract( $definition, $settings, $actions, $sources, $file );
				$entries[ $name ] = $definition;
			}
		}

		$this->validate_related_entry_contracts( $entries, $file );
		return $entries;
	}

	/**
	 * @param list<ModuleParsedEntryField> $fields Fields.
	 * @return array<string,ModuleEntryFieldDefinition>
	 */
	private function entry_fields( array $fields, string $file, string $entry_name ): array {
		$normalized = array();
		foreach ( $fields as $field ) {
			$name = $field['name'];
			if ( isset( $normalized[ $name ] ) ) {
				throw Errors::invariant( "EntryField attribute in {$file} entry {$entry_name} duplicates field {$name}." );
			}

			$normalized[ $name ] = new ModuleEntryFieldDefinition(
				$name,
				$field['type'],
				$field['label'],
				$field['default'] ?? null,
				$field['allowed'] ?? array(),
				$field['min'] ?? null,
				$field['max'] ?? null,
				$field['format'] ?? null,
				$field['required'] ?? false,
				$field['primary'],
				$field['list'],
				$field['filter'],
				$field['filter_type'],
				$field['options'],
				$field['optionsSource'],
				$field['section'],
				$field['create'],
				$field['update'],
				$field['read_only'],
				$field['order'],
				$field['visible_when'],
				$field['props']
			);
		}

		return $normalized;
	}

	/**
	 * @param list<ModuleParsedEntrySection> $sections Sections.
	 * @return array<string,ModuleEntrySectionDefinition>
	 */
	private function entry_sections( array $sections, string $file, string $entry_name ): array {
		$normalized = array();
		foreach ( $sections as $section ) {
			$name = $section['name'];
			if ( isset( $normalized[ $name ] ) ) {
				throw Errors::invariant( "EntrySection attribute in {$file} entry {$entry_name} duplicates section {$name}." );
			}
			$normalized[ $name ] = new ModuleEntrySectionDefinition( $name, $section['label'], $section['description'], $section['order'], $section['layout'] );
		}

		return $normalized;
	}

	/**
	 * @param list<ModuleParsedRelatedEntry> $related_entries Related entries.
	 * @return list<ModuleRelatedEntryDefinition>
	 */
	private function related_entries( array $related_entries, string $file, string $entry_name ): array {
		$normalized = array();
		$seen       = array();
		foreach ( $related_entries as $related ) {
			$name = $related['name'];
			if ( isset( $seen[ $name ] ) ) {
				throw Errors::invariant( "RelatedEntries attribute in {$file} entry {$entry_name} duplicates related entry {$name}." );
			}
			$seen[ $name ] = true;
			$normalized[]  = new ModuleRelatedEntryDefinition( $name, $related['entry'], $related['local_key'], $related['foreign_key'], $related['label'], $related['mode'], $related['order'] );
		}

		return $normalized;
	}

	/**
	 * @param array<string,array<string,mixed>> $settings Settings.
	 * @param array<string,ModuleAction>       $actions Actions.
	 * @param array<string,ModuleDataSource>   $sources Sources.
	 */
	private function validate_entry_contract( ModuleEntryDefinition $entry, array $settings, array $actions, array $sources, string $file ): void {
		if ( array() === $entry->fields && 'table' !== $entry->storage ) {
			throw Errors::invariant( "Entries attribute in {$file} entry {$entry->name} must declare at least one EntryField." );
		}

		if ( array() !== $entry->fields && ! $entry->has_field( $entry->key ) ) {
			throw Errors::invariant( "Entries attribute in {$file} entry {$entry->name} key {$entry->key} must be declared as an EntryField." );
		}

		$key_field = $entry->field( $entry->key );
		if ( null !== $key_field && ! $key_field->primary ) {
			throw Errors::invariant( "EntryField attribute in {$file} entry {$entry->name} key {$entry->key} must be marked primary." );
		}

		foreach ( $entry->fields as $field ) {
			if ( null !== $field->section && ! isset( $entry->sections[ $field->section ] ) ) {
				throw Errors::invariant( "EntryField attribute in {$file} entry {$entry->name} references unknown section {$field->section}." );
			}

			if ( null !== $field->visible_when ) {
				$this->validate_entry_visible_when_refs( $entry, $field, $settings, $file );
			}
		}

		if ( 'settings' === $entry->storage ) {
			if ( null === $entry->setting || ! $this->setting_path_exists( $settings, $entry->setting ) ) {
				throw Errors::invariant( "Entries attribute in {$file} entry {$entry->name} settings storage references unknown setting." );
			}

			$type = $this->setting_path_type( $settings, $entry->setting );
			if ( ! in_array( $type, array( 'array', 'object' ), true ) ) {
				throw Errors::invariant( "Entries attribute in {$file} entry {$entry->name} settings storage must reference an array or object setting." );
			}
		}

		if ( in_array( $entry->storage, array( 'manual', 'table' ), true ) ) {
			if ( null === $entry->source || ! isset( $sources[ $entry->source ] ) ) {
				throw Errors::invariant( "Entries attribute in {$file} entry {$entry->name} {$entry->storage} storage must reference a data source." );
			}

			if ( 'collection' !== $sources[ $entry->source ]->shape ) {
				throw Errors::invariant( "Entries attribute in {$file} entry {$entry->name} data source {$entry->source} must be collection-shaped." );
			}
		}

		if ( 'table' === $entry->storage && null === $entry->table ) {
			throw Errors::invariant( "Entries attribute in {$file} entry {$entry->name} table storage must reference a table." );
		}

		$this->validate_entry_action_inputs( $entry, $actions, $file );
	}

	/**
	 * @param array<string,array<string,mixed>> $settings Settings.
	 */
	private function setting_path_exists( array $settings, string $path ): bool {
		return null !== $this->setting_path_type( $settings, $path );
	}

	/**
	 * @param array<string,array<string,mixed>> $settings Settings.
	 */
	private function setting_path_type( array $settings, string $path ): ?string {
		$path     = str_starts_with( $path, 'settings.' ) ? substr( $path, strlen( 'settings.' ) ) : $path;
		$segments = explode( '.', $path );
		$root     = $segments[0];
		if ( '' === $root || ! isset( $settings[ $root ] ) ) {
			return null;
		}

		if ( 1 === count( $segments ) ) {
			$type = $settings[ $root ]['type'] ?? null;
			return is_string( $type ) ? $type : null;
		}

		$value = $settings[ $root ]['default'] ?? null;
		foreach ( array_slice( $segments, 1 ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}

			$value = $value[ $segment ];
		}

		return match ( true ) {
			is_bool( $value ) => 'boolean',
			is_int( $value ) => 'integer',
			is_float( $value ) => 'number',
			is_string( $value ) => 'string',
			is_array( $value ) => array_is_list( $value ) ? 'array' : 'object',
			default => 'object',
		};
	}

	/**
	 * @param array<string,ModuleAction> $actions Actions.
	 */
	private function validate_entry_action_inputs( ModuleEntryDefinition $entry, array $actions, string $file ): void {
		foreach (
			array(
				'create_action' => $entry->create_action,
				'update_action' => $entry->update_action,
				'delete_action' => $entry->delete_action,
			) as $property => $action_name
		) {
			if ( null !== $action_name && ! isset( $actions[ $action_name ] ) ) {
				throw Errors::invariant( "Entries attribute in {$file} entry {$entry->name} references unknown action {$action_name}." );
			}
		}

		if ( null !== $entry->create_action ) {
			$this->validate_entry_field_inputs( $entry, $actions[ $entry->create_action ], 'create', $file );
		}

		if ( null !== $entry->update_action ) {
			$this->validate_entry_field_inputs( $entry, $actions[ $entry->update_action ], 'update', $file );
			if ( ! $this->action_accepts_field( $actions[ $entry->update_action ], $entry->key ) ) {
				throw Errors::invariant( "Entries attribute in {$file} entry {$entry->name} update action {$entry->update_action} must accept primary key {$entry->key}." );
			}
		}

		if ( null !== $entry->delete_action && ! $this->action_accepts_field( $actions[ $entry->delete_action ], 'ids' ) ) {
			throw Errors::invariant( "Entries attribute in {$file} entry {$entry->name} delete action {$entry->delete_action} must accept ids." );
		}
	}

	/**
	 * @param array<string,array<string,mixed>> $settings Settings.
	 */
	private function validate_entry_visible_when_refs( ModuleEntryDefinition $entry, ModuleEntryFieldDefinition $field, array $settings, string $file ): void {
		foreach ( $this->entry_condition_refs( $field->visible_when ) as $ref ) {
			if ( '' === $ref ) {
				throw Errors::invariant( "EntryField attribute in {$file} entry {$entry->name} field {$field->name} visible_when reference must be non-empty." );
			}

			$parts = explode( '.', $ref, 2 );
			$root  = $parts[0];
			$path  = $parts[1] ?? '';
			if ( ! in_array( $root, array( 'data', 'form', 'owner', 'row', 'settings' ), true ) || '' === $path ) {
				throw Errors::invariant( "EntryField attribute in {$file} entry {$entry->name} field {$field->name} visible_when references invalid path {$ref}." );
			}

			if ( in_array( $root, array( 'form', 'row' ), true ) && ! $entry->has_field( $path ) ) {
				throw Errors::invariant( "EntryField attribute in {$file} entry {$entry->name} field {$field->name} visible_when references unknown {$root} field {$path}." );
			}

			if ( 'settings' === $root ) {
				$setting_name = explode( '.', $path, 2 )[0];
				if ( ! isset( $settings[ $setting_name ] ) ) {
					throw Errors::invariant( "EntryField attribute in {$file} entry {$entry->name} field {$field->name} visible_when references unknown setting {$setting_name}." );
				}
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private function entry_condition_refs( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$refs = array();
		if ( isset( $value['ref'] ) && is_string( $value['ref'] ) ) {
			$refs[] = $value['ref'];
		}

		foreach ( $value as $child ) {
			$refs = array_merge( $refs, $this->entry_condition_refs( $child ) );
		}

		return $refs;
	}

	private function validate_entry_field_inputs( ModuleEntryDefinition $entry, ModuleAction $action, string $mode, string $file ): void {
		foreach ( $entry->fields as $field ) {
			if ( 'create' === $mode && ! $field->create ) {
				continue;
			}

			if ( 'update' === $mode && ! $field->update ) {
				continue;
			}

			if ( ! $this->action_accepts_field( $action, $field->name ) ) {
				throw Errors::invariant( "Entries attribute in {$file} entry {$entry->name} {$mode} action {$action->name} must accept field {$field->name}." );
			}
		}
	}

	private function action_accepts_field( ModuleAction $action, string $field ): bool {
		if ( isset( $action->inputs[ $field ] ) ) {
			return true;
		}

		$root = explode( '.', $field, 2 )[0];
		return isset( $action->inputs[ $root ] );
	}

	/**
	 * @param array<string,ModuleEntryDefinition> $entries Entries.
	 */
	private function validate_related_entry_contracts( array $entries, string $file ): void {
		foreach ( $entries as $entry ) {
			foreach ( $entry->related_entries as $related ) {
				$target = $entries[ $related->entry ] ?? null;
				if ( null === $target ) {
					throw Errors::invariant( "RelatedEntries attribute in {$file} entry {$entry->name} references unknown entry {$related->entry}." );
				}

				if ( array() !== $target->related_entries ) {
					throw Errors::invariant( "RelatedEntries attribute in {$file} entry {$entry->name} target {$related->entry} nests related entries deeper than one level." );
				}

				if ( array() !== $entry->fields && ! $entry->has_field( $related->local_key ) ) {
					throw Errors::invariant( "RelatedEntries attribute in {$file} entry {$entry->name} local_key {$related->local_key} is not declared." );
				}

				if ( array() !== $target->fields && ! $target->has_field( $related->foreign_key ) ) {
					throw Errors::invariant( "RelatedEntries attribute in {$file} entry {$entry->name} foreign_key {$related->foreign_key} is not declared on {$related->entry}." );
				}
			}
		}
	}

	/**
	 * @param array<string,ModuleParsedMethod> $methods Methods.
	 * @return ModuleHook[]
	 */
	private function hooks( array $methods, string $file ): array {
		$hooks = array();
		foreach ( $methods as $method => $method_info ) {
			foreach ( $method_info['hooks'] as $hook ) {
				$type          = $hook['type'];
				$hook_name     = $hook['hook'];
				$priority      = $hook['priority'];
				$accepted_args = $hook['accepted_args'];

				if ( ! $method_info['public'] || $method_info['static'] ) {
					throw Errors::invariant( "WordPress hook attribute in {$file} hook {$hook_name} method must be public and non-static." );
				}

				$hooks[] = new ModuleHook(
					$type,
					$hook_name,
					$method,
					$priority,
					$accepted_args
				);
			}
		}

		return $hooks;
	}

	/**
	 * @param ModuleParsedMethod $method_info Method info.
	 */
	private function assert_callable_method( string $kind, string $name, array $method_info, string $file ): void {
		if ( ! $method_info['public'] || $method_info['static'] ) {
			throw Errors::invariant( "{$this->callable_attribute_label( $kind )} in {$file} {$kind} {$name} method must be public and non-static." );
		}

		if ( $method_info['required'] > 1 ) {
			throw Errors::invariant( "{$this->callable_attribute_label( $kind )} in {$file} {$kind} {$name} method may require at most one parameter." );
		}
	}

	private function callable_name( string $method ): string {
		$parts = explode( '_', $method );
		$name  = array_shift( $parts );
		foreach ( $parts as $part ) {
			$name .= ucfirst( $part );
		}

		return $name;
	}

	private function callable_attribute_label( string $kind ): string {
		return 'data source' === $kind ? 'DataSource attribute' : 'Action attribute';
	}

	/**
	 * @param array<\Onumia\Lib\PhpParser\Node\AttributeGroup> $attribute_groups Attribute groups.
	 * @param array<string,string>                              $uses            Uses.
	 * @return list<PhpAttribute>
	 */
	private function attributes_named( array $attribute_groups, string $attribute_class, string $namespace, array $uses ): array {
		$matches = array();
		foreach ( $attribute_groups as $group ) {
			foreach ( $group->attrs as $attribute ) {
				if ( $attribute_class === $this->resolve_name( $attribute->name, $namespace, $uses ) ) {
					$matches[] = $attribute;
				}
			}
		}

		return $matches;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return array{positional:list<mixed>,named:array<string,mixed>}
	 */
	private function attribute_arguments( PhpAttribute $attribute, string $file, string $namespace, array $uses ): array {
		$positional = array();
		$named      = array();
		foreach ( $attribute->args as $argument ) {
			$value = $this->literal_value( $argument->value, $file, $namespace, $uses );
			if ( $argument->name instanceof Identifier ) {
				$name = $argument->name->toString();
				if ( array_key_exists( $name, $named ) ) {
					throw Errors::invariant( "Attribute argument {$name} in {$file} is duplicated." );
				}

				$named[ $name ] = $value;
				continue;
			}

			$positional[] = $value;
		}

		return array(
			'positional' => $positional,
			'named'      => $named,
		);
	}

	/**
	 * @param array<string,mixed> $named Named arguments.
	 * @param string[]            $allowed Allowed argument names.
	 */
	private function assert_known_named_arguments( array $named, array $allowed, string $label ): void {
		$allowed = array_fill_keys( $allowed, true );
		foreach ( $named as $name => $_value ) {
			if ( ! isset( $allowed[ $name ] ) ) {
				throw Errors::invariant( "{$label} contains unknown argument {$name}." );
			}
		}
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 */
	private function argument_value( array $arguments, string $name, int $position, mixed $default ): mixed {
		if ( array_key_exists( $name, $arguments['named'] ) ) {
			return $arguments['named'][ $name ];
		}

		return array_key_exists( $position, $arguments['positional'] ) ? $arguments['positional'][ $position ] : $default;
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 */
	private function has_argument( array $arguments, string $name, int $position ): bool {
		return array_key_exists( $name, $arguments['named'] ) || array_key_exists( $position, $arguments['positional'] );
	}

	private function short_attribute_name( string $attribute_class ): string {
		$parts = explode( '\\', $attribute_class );
		$short = end( $parts );
		return false === $short ? $attribute_class : $short;
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 */
	private function string_argument( array $arguments, string $name, int $position, string $label ): string {
		$value = $this->argument_value( $arguments, $name, $position, null );
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw Errors::invariant( "{$label} must be a non-empty string." );
		}

		return $value;
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 */
	private function optional_string_argument( array $arguments, string $name, int $position, string $label ): ?string {
		$value = $this->argument_value( $arguments, $name, $position, null );
		if ( null === $value ) {
			return null;
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw Errors::invariant( "{$label} must be a non-empty string or null." );
		}

		return $value;
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 */
	private function bool_argument( array $arguments, string $name, int $position, bool $default, string $label ): bool {
		$value = $this->argument_value( $arguments, $name, $position, $default );
		if ( ! is_bool( $value ) ) {
			throw Errors::invariant( "{$label} must be boolean." );
		}

		return $value;
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 */
	private function int_argument( array $arguments, string $name, int $position, int $default, string $label ): int {
		$value = $this->argument_value( $arguments, $name, $position, $default );
		if ( ! is_int( $value ) ) {
			throw Errors::invariant( "{$label} must be an integer." );
		}

		return $value;
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 * @return array<string,mixed>
	 */
	private function object_argument( array $arguments, string $name, int $position, string $label ): array {
		$value = $this->argument_value( $arguments, $name, $position, array() );
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw Errors::invariant( "{$label} must be an object." );
		}

		return $this->object_value( $value, "{$label} must be an object." );
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 * @return array<string,mixed>|null
	 */
	private function optional_object_argument( array $arguments, string $name, int $position, string $label ): ?array {
		$value = $this->argument_value( $arguments, $name, $position, null );
		if ( null === $value ) {
			return null;
		}

		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw Errors::invariant( "{$label} must be an object or null." );
		}

		return $this->object_value( $value, "{$label} must be an object or null." );
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 * @return list<array<string,mixed>>
	 */
	private function options_argument( array $arguments, string $name, int $position, string $label ): array {
		$value = $this->argument_value( $arguments, $name, $position, array() );
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw Errors::invariant( "{$label} must be a list." );
		}

		$options = array();
		foreach ( $value as $option ) {
			if ( ! is_array( $option ) || array_is_list( $option ) ) {
				throw Errors::invariant( "{$label} must contain objects." );
			}
			$options[] = $this->object_value( $option, "{$label} must contain objects." );
		}

		return $options;
	}

	/**
	 * @param  array<mixed> $value Object value.
	 * @return array<string,mixed>
	 */
	private function object_value( array $value, string $message ): array {
		$object = array();
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) ) {
				throw Errors::invariant( $message );
			}
			$object[ $key ] = $item;
		}

		return $object;
	}

	/**
	 * @return array<string,string>
	 */
	private function object_shape_fields( mixed $value, string $label ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw Errors::invariant( "{$label} fields must be an object." );
		}

		$fields = array();
		foreach ( $value as $key => $type ) {
			if ( ! is_string( $key ) || ! is_string( $type ) ) {
				throw Errors::invariant( "{$label} fields must map keys to type strings." );
			}

			$normalized_type = $this->object_shape_type( $type );
			if ( null === $normalized_type ) {
				throw Errors::invariant( "{$label} field {$key} has invalid type." );
			}

			$fields[ $key ] = $normalized_type;
		}

		return $fields;
	}

	private function object_shape_type( string $type ): ?string {
		$normalized = match ( $type ) {
			'bool' => 'boolean',
			'int' => 'integer',
			'float' => 'number',
			default => $type,
		};

		return in_array( $normalized, self::OBJECT_SHAPE_TYPES, true ) ? $normalized : null;
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 */
	private function identifier_argument( array $arguments, string $name, int $position, string $label ): string {
		$value = $this->string_argument( $arguments, $name, $position, $label );
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $value ) ) {
			throw Errors::invariant( "{$label} must be an identifier." );
		}

		return $value;
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 */
	private function entry_field_path_argument( array $arguments, string $name, int $position, string $label ): string {
		return $this->entry_field_path( $this->string_argument( $arguments, $name, $position, $label ), $label );
	}

	private function entry_field_path( string $path, string $label ): string {
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(?:\\.[A-Za-z_][A-Za-z0-9_]*)*$/', $path ) ) {
			throw Errors::invariant( "{$label} must be a dot path." );
		}

		return $path;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 */
	private function literal_value( Node $node, string $file, string $namespace, array $uses ): mixed {
		if ( $node instanceof Array_ ) {
			$result = array();
			foreach ( $node->items as $item ) {
				$value = $this->literal_value( $item->value, $file, $namespace, $uses );
				if ( null === $item->key ) {
					$result[] = $value;
					continue;
				}

				$key = $this->literal_value( $item->key, $file, $namespace, $uses );
				if ( ! is_int( $key ) && ! is_string( $key ) ) {
					throw Errors::invariant( "Module attribute {$file} array keys must be literal strings or integers." );
				}

				$result[ $key ] = $value;
			}

			return $result;
		}

		if ( $node instanceof String_ ) {
			return $node->value;
		}

		if ( $node instanceof LNumber ) {
			return $node->value;
		}

		if ( $node instanceof DNumber ) {
			return $node->value;
		}

		if ( $node instanceof UnaryMinus ) {
			$value = $this->literal_value( $node->expr, $file, $namespace, $uses );
			if ( is_int( $value ) || is_float( $value ) ) {
				return -$value;
			}
		}

		if ( $node instanceof ConstFetch ) {
			$name = strtolower( $node->name->toString() );
			return match ( $name ) {
				'true' => true,
				'false' => false,
				'null' => null,
				default => throw Errors::invariant( "Module attribute {$file} contains unsupported constant {$name}." ),
			};
		}

		if ( $node instanceof ClassConstFetch ) {
			return $this->enum_case_value( $node, $file, $namespace, $uses );
		}

		throw Errors::invariant( "Module attribute {$file} must use literal values only." );
	}

	/**
	 * @param array<string,string> $uses Uses.
	 */
	private function enum_case_value( ClassConstFetch $node, string $file, string $namespace, array $uses ): string {
		if ( ! $node->class instanceof Name || ! $node->name instanceof Identifier ) {
			throw Errors::invariant( "Module attribute {$file} must use supported enum cases only." );
		}

		$class = $this->resolve_name( $node->class, $namespace, $uses );
		$case  = $node->name->toString();
		if ( self::ENUM_SETTING_TYPE === $class ) {
			return match ( $case ) {
				'Boolean' => 'boolean',
				'String' => 'string',
				'Integer' => 'integer',
				'Number' => 'number',
				'Array' => 'array',
				'Object' => 'object',
				default => throw Errors::invariant( "Module attribute {$file} contains unsupported SettingType case {$case}." ),
			};
		}

		if ( self::ENUM_SURFACE === $class ) {
			return match ( $case ) {
				'Backend' => 'backend',
				'Admin' => 'admin',
				'Frontend' => 'frontend',
				default => throw Errors::invariant( "Module attribute {$file} contains unsupported Surface case {$case}." ),
			};
		}

		if ( self::ENUM_ACTION_INTENT === $class ) {
			return strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '_$0', $case ) );
		}

		if ( self::ENUM_DATA_SOURCE_SHAPE === $class ) {
			return strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '_$0', $case ) );
		}

		if ( self::ENUM_PAGINATION_MODE === $class ) {
			return strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '_$0', $case ) );
		}

		if ( self::ENUM_ENTRY_STORAGE === $class ) {
			return strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '_$0', $case ) );
		}

		if ( self::ENUM_HTTP_METHOD === $class ) {
			return strtoupper( $case );
		}

		if ( self::ENUM_ROUTE_AUTH === $class ) {
			return match ( $case ) {
				'None' => 'none',
				'LicenseKey' => 'license_key',
				'DownloadToken' => 'download_token',
				'WebhookSignature' => 'webhook_signature',
				'Signature' => 'signature',
				'WordPressUser' => 'wordpress_user',
				default => throw Errors::invariant( "Module attribute {$file} contains unsupported RouteAuth case {$case}." ),
			};
		}

		if ( self::ENUM_JOB_SCHEDULE === $class ) {
			return match ( $case ) {
				'FiveMinutes' => 'five_minutes',
				'Hourly' => 'hourly',
				'TwiceDaily' => 'twice_daily',
				'Daily' => 'daily',
				'Weekly' => 'weekly',
				default => throw Errors::invariant( "Module attribute {$file} contains unsupported JobSchedule case {$case}." ),
			};
		}

		throw Errors::invariant( "Module attribute {$file} must not use unsupported class constants." );
	}

	/**
	 * @param 'boolean'|'string'|'integer'|'number'|'array'|'object' $type Type.
	 */
	private function assert_value_matches_type( mixed $value, string $type, string $label ): void {
		$valid = match ( $type ) {
			'boolean' => is_bool( $value ),
			'string' => is_string( $value ),
			'integer' => is_int( $value ),
			'number' => is_int( $value ) || is_float( $value ),
			'array' => is_array( $value ),
			'object' => is_array( $value ),
		};

		if ( ! $valid ) {
			throw Errors::invariant( "{$label} does not match its type." );
		}
	}
}
