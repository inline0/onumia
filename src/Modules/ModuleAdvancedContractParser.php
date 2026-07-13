<?php

/**
 * Parses advanced module contract files.
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
use Onumia\Lib\PhpParser\Node\Stmt\Namespace_;
use Onumia\Lib\PhpParser\Node\Stmt\Use_;
use Onumia\Lib\PhpParser\ParserFactory;

final class ModuleAdvancedContractParser {
	private const ATTRIBUTE_TABLE  = 'Onumia\\Modules\\Attributes\\Table';
	private const ATTRIBUTE_COLUMN = 'Onumia\\Modules\\Attributes\\Column';
	private const ATTRIBUTE_INDEX  = 'Onumia\\Modules\\Attributes\\Index';
	private const ATTRIBUTE_SECRET = 'Onumia\\Modules\\Attributes\\Secret';
	private const ENUM_COLUMN_TYPE = 'Onumia\\Modules\\Contracts\\ColumnType';
	private const COLUMN_TYPES     = array( 'bigint', 'boolean', 'datetime', 'decimal', 'integer', 'json', 'longtext', 'string', 'text' );
	private const FILTER_TYPES     = array( 'boolean', 'multiOption', 'number', 'option', 'text' );
	private const FIELD_TYPES      = array( 'boolean', 'string', 'integer', 'number', 'array', 'object' );
	private const COLUMN_PROPS     = array( 'language', 'max', 'min', 'multiline', 'options', 'optionsSource', 'placeholder', 'rows', 'search' );

	public function parse_optional_files( string $directory ): ModuleAdvancedContractDefinition {
		$contract = new ModuleAdvancedContractDefinition();
		foreach ( array( 'tables.php', 'rest.php', 'jobs.php', 'secrets.php' ) as $file ) {
			$path = $directory . DIRECTORY_SEPARATOR . $file;
			if ( is_file( $path ) ) {
				$contract = $contract->merge( $this->parse_file( $path ) );
			}
		}

		return $contract;
	}

	public function parse_file( string $file ): ModuleAdvancedContractDefinition {
		$contents = file_get_contents( $file );
		try {
			$stmts = ( new ParserFactory() )->createForNewestSupportedVersion()->parse( false === $contents ? '' : $contents ) ?? array();
		} catch ( Error $error ) {
			throw Errors::invariant( 'PHP syntax error in advanced module contract file: ' . $error->getMessage() );
		}

		return $this->parse_statements( $stmts, $file, '', array() );
	}

	/**
	 * @param Stmt[]               $stmts Statements.
	 * @param array<string,string> $uses Uses.
	 */
	private function parse_statements( array $stmts, string $file, string $namespace, array $uses ): ModuleAdvancedContractDefinition {
		$tables  = array();
		$columns = array();
		$indexes = array();
		$secrets = array();

		foreach ( $stmts as $stmt ) {
			if ( $stmt instanceof Namespace_ ) {
				return $this->parse_statements( $stmt->stmts, $file, null === $stmt->name ? '' : $stmt->name->toString(), array() );
			}

			if ( $stmt instanceof Use_ ) {
				foreach ( $stmt->uses as $use ) {
					$alias          = $use->alias instanceof Identifier ? $use->alias->toString() : $use->name->getLast();
					$uses[ $alias ] = $use->name->toString();
				}
				continue;
			}

			if ( ! $stmt instanceof Class_ ) {
				continue;
			}

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$attribute_groups = $stmt->attrGroups;

			$table_attributes = $this->attributes_named( $attribute_groups, self::ATTRIBUTE_TABLE, $namespace, $uses );
			if ( count( $table_attributes ) > 1 ) {
				throw Errors::invariant( "Advanced module contract {$file} classes may declare only one Table attribute." );
			}

			$table = array() === $table_attributes ? null : $this->table_definition( $table_attributes[0], $file, $namespace, $uses );
			if ( null !== $table ) {
				if ( isset( $tables[ $table->name ] ) ) {
					throw Errors::invariant( "Advanced module contract {$file} duplicates table {$table->name}." );
				}
				$tables[ $table->name ] = $table;
			}

			$class_columns = $this->attributes_named( $attribute_groups, self::ATTRIBUTE_COLUMN, $namespace, $uses );
			$class_indexes = $this->attributes_named( $attribute_groups, self::ATTRIBUTE_INDEX, $namespace, $uses );
			if ( null === $table && ( array() !== $class_columns || array() !== $class_indexes ) ) {
				throw Errors::invariant( "Advanced module contract {$file} columns and indexes must be declared on a class with a Table attribute." );
			}

			foreach ( $this->attributes_named( $attribute_groups, self::ATTRIBUTE_COLUMN, $namespace, $uses ) as $attribute ) {
				$column                      = $this->column_definition( $attribute, $file, $namespace, $uses, null === $table ? '' : $table->name );
				$columns[ $column->table ][] = $column;
			}

			foreach ( $this->attributes_named( $attribute_groups, self::ATTRIBUTE_INDEX, $namespace, $uses ) as $attribute ) {
				$index                      = $this->index_definition( $attribute, $file, $namespace, $uses, null === $table ? '' : $table->name );
				$indexes[ $index->table ][] = $index;
			}

			foreach ( $this->attributes_named( $attribute_groups, self::ATTRIBUTE_SECRET, $namespace, $uses ) as $attribute ) {
				$secret = $this->secret_definition( $attribute, $file, $namespace, $uses );
				if ( isset( $secrets[ $secret->name ] ) ) {
					throw Errors::invariant( "Advanced module contract {$file} duplicates secret {$secret->name}." );
				}
				$secrets[ $secret->name ] = $secret;
			}
		}

		$resolved_tables = array();
		foreach ( $tables as $name => $table ) {
			$table_columns = $columns[ $name ] ?? array();
			if ( array() === $table_columns ) {
				throw Errors::invariant( "Advanced module contract {$file} table {$name} must declare at least one column." );
			}

			$resolved_tables[ $name ] = new ModuleTableDefinition( $name, $table->label, $table->version, $table_columns, $indexes[ $name ] ?? array(), $table->row_cap, $table->retention_days, $table->driver );
		}

		// @codeCoverageIgnoreStart
		foreach ( array_keys( $columns + $indexes ) as $table_name ) {
			if ( ! isset( $tables[ $table_name ] ) ) {
				throw Errors::invariant( "Advanced module contract {$file} references unknown table {$table_name}." );
			}
		}
		// @codeCoverageIgnoreEnd

		return new ModuleAdvancedContractDefinition( $resolved_tables, array(), array(), $secrets );
	}

	/**
	 * @param array<\Onumia\Lib\PhpParser\Node\AttributeGroup> $attribute_groups Attribute groups.
	 * @param array<string,string>                              $uses Uses.
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
	 */
	private function table_definition( PhpAttribute $attribute, string $file, string $namespace, array $uses ): ModuleTableDefinition {
		$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
		$this->assert_known_named_arguments( $arguments['named'], array( 'name', 'version', 'label', 'row_cap', 'retention_days', 'driver' ), "Table attribute in {$file}" );

		$name = $this->string_argument( $arguments, 'name', 0, "Table attribute in {$file} name" );
		$this->assert_identifier( $name, "Table attribute in {$file} name" );

		$label = $this->argument_value( $arguments, 'label', 2, null );
		if ( null !== $label && ! is_string( $label ) ) {
			throw Errors::invariant( "Table attribute in {$file} label must be a string or null." );
		}

		$version = $this->argument_value( $arguments, 'version', 1, 1 );
		if ( ! is_int( $version ) || $version < 1 ) {
			throw Errors::invariant( "Table attribute in {$file} version must be a positive integer." );
		}

		$row_cap = $this->argument_value( $arguments, 'row_cap', 3, null );
		if ( null !== $row_cap && ( ! is_int( $row_cap ) || $row_cap < 1 || $row_cap > 100000 ) ) {
			throw Errors::invariant( "Table attribute in {$file} row_cap must be an integer between 1 and 100000." );
		}

		$retention_days = $this->argument_value( $arguments, 'retention_days', 4, null );
		if ( null !== $retention_days && ( ! is_int( $retention_days ) || $retention_days < 1 || $retention_days > 365 ) ) {
			throw Errors::invariant( "Table attribute in {$file} retention_days must be an integer between 1 and 365." );
		}

		$driver = $this->argument_value( $arguments, 'driver', 5, 'auto' );
		if ( ! is_string( $driver ) || ! in_array( $driver, array( 'auto', 'mysql', 'sqlite' ), true ) ) {
			throw Errors::invariant( "Table attribute in {$file} driver must be auto, mysql, or sqlite." );
		}

		return new ModuleTableDefinition( $name, null === $label ? '' : $label, $version, array(), array(), $row_cap, $retention_days, $driver );
	}

	/**
	 * @param array<string,string> $uses Uses.
	 */
	private function column_definition( PhpAttribute $attribute, string $file, string $namespace, array $uses, string $table ): ModuleColumnDefinition {
		$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
		$this->assert_known_named_arguments(
			$arguments['named'],
			array(
				'name',
				'type',
				'length',
				'precision',
				'scale',
				'nullable',
				'default',
				'primary',
				'auto_increment',
				'unsigned',
				'label',
				'allowed',
				'table_list',
				'table_filter',
				'filter_type',
				'filter_operators',
				'props',
				'entry_list',
				'entry_field',
				'entry_section',
				'entry_order',
				'entry_path',
				'entry_create',
				'entry_update',
				'sortable',
				'searchable',
				'required',
			),
			"Column attribute in {$file}"
		);

		$name = $this->string_argument( $arguments, 'name', 0, "Column attribute in {$file} name" );
		$this->assert_identifier( $name, "Column attribute in {$file} name" );

		$type = $this->argument_value( $arguments, 'type', 1, null );
		if ( ! is_string( $type ) || ! in_array( $type, self::COLUMN_TYPES, true ) ) {
			throw Errors::invariant( "Column attribute in {$file} type is invalid." );
		}

		$label = $this->optional_string_argument( $arguments, 'label', 10, "Column attribute in {$file} label" ) ?? '';

		$allowed = $this->array_argument( $arguments, 'allowed', 11, "Column attribute in {$file} allowed" );
		foreach ( $allowed as $allowed_value ) {
			if ( ! is_scalar( $allowed_value ) && null !== $allowed_value ) {
				throw Errors::invariant( "Column attribute in {$file} allowed values must be scalar or null." );
			}
		}

		$filter_type = $this->optional_string_argument( $arguments, 'filter_type', 14, "Column attribute in {$file} filter_type" );
		if ( null !== $filter_type && ! in_array( $filter_type, self::FILTER_TYPES, true ) ) {
			throw Errors::invariant( "Column attribute in {$file} filter_type is invalid." );
		}

		$filter_operators = $this->string_list_argument( $arguments, 'filter_operators', 15, "Column attribute in {$file} filter_operators" );
		$props            = $this->renderer_props_argument( $arguments, 'props', 16, "Column attribute in {$file} props" );
		$entry_field      = $this->optional_string_argument( $arguments, 'entry_field', 18, "Column attribute in {$file} entry_field" ) ?? '';
		if ( '' !== $entry_field && ! in_array( $entry_field, self::FIELD_TYPES, true ) ) {
			throw Errors::invariant( "Column attribute in {$file} entry_field must be a supported field type." );
		}

		$entry_section = $this->optional_string_argument( $arguments, 'entry_section', 19, "Column attribute in {$file} entry_section" );
		if ( null !== $entry_section ) {
			$this->assert_identifier( $entry_section, "Column attribute in {$file} entry_section" );
		}

		$entry_path = $this->optional_string_argument( $arguments, 'entry_path', 21, "Column attribute in {$file} entry_path" );
		if ( null !== $entry_path ) {
			$this->assert_field_path( $entry_path, "Column attribute in {$file} entry_path" );
		}

		return new ModuleColumnDefinition(
			$table,
			$name,
			$type,
			$this->bool_argument( $arguments, 'nullable', 5, false, "Column attribute in {$file} nullable" ),
			$this->argument_value( $arguments, 'default', 6, null ),
			$this->nullable_int_argument( $arguments, 'length', 2, "Column attribute in {$file} length" ),
			$this->nullable_int_argument( $arguments, 'precision', 3, "Column attribute in {$file} precision" ),
			$this->nullable_int_argument( $arguments, 'scale', 4, "Column attribute in {$file} scale" ),
			$this->bool_argument( $arguments, 'unsigned', 9, false, "Column attribute in {$file} unsigned" ),
			$this->bool_argument( $arguments, 'auto_increment', 8, false, "Column attribute in {$file} auto_increment" ),
			$this->bool_argument( $arguments, 'primary', 7, false, "Column attribute in {$file} primary" ),
			$label,
			array_values( $allowed ),
			$this->bool_argument( $arguments, 'table_list', 12, false, "Column attribute in {$file} table_list" ),
			$this->bool_argument( $arguments, 'table_filter', 13, false, "Column attribute in {$file} table_filter" ),
			$filter_type,
			$filter_operators,
			$props,
			$this->bool_argument( $arguments, 'entry_list', 17, false, "Column attribute in {$file} entry_list" ),
			$entry_field,
			$entry_section,
			$this->int_argument( $arguments, 'entry_order', 20, 0, "Column attribute in {$file} entry_order" ),
			$entry_path,
			$this->bool_argument( $arguments, 'entry_create', 22, true, "Column attribute in {$file} entry_create" ),
			$this->bool_argument( $arguments, 'entry_update', 23, true, "Column attribute in {$file} entry_update" ),
			$this->bool_argument( $arguments, 'sortable', 24, false, "Column attribute in {$file} sortable" ),
			$this->bool_argument( $arguments, 'searchable', 25, false, "Column attribute in {$file} searchable" ),
			$this->nullable_bool_argument( $arguments, 'required', 26, "Column attribute in {$file} required" )
		);
	}

	/**
	 * @param array<string,string> $uses Uses.
	 */
	private function index_definition( PhpAttribute $attribute, string $file, string $namespace, array $uses, string $table ): ModuleIndexDefinition {
		$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
		$this->assert_known_named_arguments( $arguments['named'], array( 'name', 'columns', 'unique' ), "Index attribute in {$file}" );

		$name    = $this->string_argument( $arguments, 'name', 0, "Index attribute in {$file} name" );
		$columns = $this->argument_value( $arguments, 'columns', 1, array() );
		if ( ! is_array( $columns ) || array() === $columns ) {
			throw Errors::invariant( "Index attribute in {$file} columns must be a non-empty array." );
		}

		$normalized_columns = array();
		foreach ( $columns as $column ) {
			if ( ! is_string( $column ) || '' === $column ) {
				throw Errors::invariant( "Index attribute in {$file} columns must be strings." );
			}
			$this->assert_identifier( $column, "Index attribute in {$file} column" );
			$normalized_columns[] = $column;
		}

		$this->assert_identifier( $name, "Index attribute in {$file} name" );

		return new ModuleIndexDefinition(
			$table,
			$name,
			$normalized_columns,
			$this->bool_argument( $arguments, 'unique', 2, false, "Index attribute in {$file} unique" )
		);
	}

	/**
	 * @param array<string,string> $uses Uses.
	 */
	private function secret_definition( PhpAttribute $attribute, string $file, string $namespace, array $uses ): ModuleSecretDefinition {
		$arguments = $this->attribute_arguments( $attribute, $file, $namespace, $uses );
		$this->assert_known_named_arguments( $arguments['named'], array( 'name', 'constant', 'label', 'required' ), "Secret attribute in {$file}" );

		$name = $this->string_argument( $arguments, 'name', 0, "Secret attribute in {$file} name" );
		$this->assert_identifier( $name, "Secret attribute in {$file} name" );

		$constant = $this->argument_value( $arguments, 'constant', 1, null );
		if ( null !== $constant && ( ! is_string( $constant ) || '' === $constant ) ) {
			throw Errors::invariant( "Secret attribute in {$file} constant must be a non-empty string or null." );
		}

		$label = $this->argument_value( $arguments, 'label', 2, '' );
		if ( ! is_string( $label ) ) {
			throw Errors::invariant( "Secret attribute in {$file} label must be a string." );
		}

		return new ModuleSecretDefinition(
			$name,
			$constant,
			$label,
			$this->bool_argument( $arguments, 'required', 3, false, "Secret attribute in {$file} required" )
		);
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 */
	private function string_argument( array $arguments, string $name, int $position, string $label ): string {
		$value = $this->argument_value( $arguments, $name, $position, null );
		if ( ! is_string( $value ) || '' === $value ) {
			throw Errors::invariant( "{$label} must be a non-empty string." );
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
	private function nullable_int_argument( array $arguments, string $name, int $position, string $label ): int {
		$value = $this->argument_value( $arguments, $name, $position, null );
		if ( null === $value ) {
			return 0;
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			throw Errors::invariant( "{$label} must be a non-negative integer or null." );
		}

		return $value;
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 */
	private function int_argument( array $arguments, string $name, int $position, int $default, string $label ): int {
		$value = $this->argument_value( $arguments, $name, $position, $default );
		if ( ! is_int( $value ) || $value < 0 ) {
			throw Errors::invariant( "{$label} must be a non-negative integer." );
		}

		return $value;
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 */
	private function nullable_bool_argument( array $arguments, string $name, int $position, string $label ): ?bool {
		$value = $this->argument_value( $arguments, $name, $position, null );
		if ( null === $value ) {
			return null;
		}
		if ( ! is_bool( $value ) ) {
			throw Errors::invariant( "{$label} must be boolean or null." );
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
		if ( ! is_string( $value ) || '' === $value ) {
			throw Errors::invariant( "{$label} must be a non-empty string or null." );
		}

		return $value;
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 * @return array<mixed>
	 */
	private function array_argument( array $arguments, string $name, int $position, string $label ): array {
		$value = $this->argument_value( $arguments, $name, $position, array() );
		if ( ! is_array( $value ) ) {
			throw Errors::invariant( "{$label} must be an array." );
		}

		return $value;
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 * @return list<string>
	 */
	private function string_list_argument( array $arguments, string $name, int $position, string $label ): array {
		$value  = $this->array_argument( $arguments, $name, $position, $label );
		$result = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === $item ) {
				throw Errors::invariant( "{$label} must contain non-empty strings." );
			}

			$result[] = $item;
		}

		return $result;
	}

	/**
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 * @return array<string,mixed>
	 */
	private function renderer_props_argument( array $arguments, string $name, int $position, string $label ): array {
		$value = $this->array_argument( $arguments, $name, $position, $label );
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) || ! in_array( $key, self::COLUMN_PROPS, true ) ) {
				throw Errors::invariant( "{$label} contains unsupported key {$key}." );
			}
			$this->assert_json_serializable_value( $item, "{$label}.{$key}" );
		}

		return $value;
	}

	private function assert_identifier( string $value, string $label ): void {
		if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/', $value ) ) {
			throw Errors::invariant( "{$label} must use letters, numbers, and underscores." );
		}
	}

	private function assert_field_path( string $value, string $label ): void {
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/', $value ) ) {
			throw Errors::invariant( "{$label} must use dot-separated identifiers." );
		}
	}

	private function assert_json_serializable_value( mixed $value, string $label ): void {
		if ( is_scalar( $value ) || null === $value ) {
			return;
		}

		if ( ! is_array( $value ) ) {
			throw Errors::invariant( "{$label} must be JSON-serializable." );
		}

		foreach ( $value as $key => $item ) {
			$this->assert_json_serializable_value( $item, "{$label}.{$key}" );
		}
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
	 * @param array<string,string> $uses Uses.
	 * @return array{positional:list<mixed>,named:array<string,mixed>}
	 */
	private function attribute_arguments( PhpAttribute $attribute, string $file, string $namespace, array $uses ): array {
		$positional = array();
		$named      = array();
		foreach ( $attribute->args as $argument ) {
			$value = $this->literal_value( $argument->value, $file, $namespace, $uses );
			if ( $argument->name instanceof Identifier ) {
				$named[ $argument->name->toString() ] = $value;
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
	 * @param array{positional:list<mixed>,named:array<string,mixed>} $arguments Arguments.
	 */
	private function argument_value( array $arguments, string $name, int $position, mixed $default ): mixed {
		if ( array_key_exists( $name, $arguments['named'] ) ) {
			return $arguments['named'][ $name ];
		}

		return array_key_exists( $position, $arguments['positional'] ) ? $arguments['positional'][ $position ] : $default;
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
					throw Errors::invariant( "Advanced module attribute {$file} array keys must be literal strings or integers." );
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
			return match ( strtolower( $node->name->toString() ) ) {
				'true' => true,
				'false' => false,
				'null' => null,
				default => throw Errors::invariant( "Advanced module attribute {$file} contains unsupported constant." ),
			};
		}

		if ( $node instanceof ClassConstFetch && $node->class instanceof Name && $node->name instanceof Identifier ) {
			$class = $this->resolve_name( $node->class, $namespace, $uses );
			if ( self::ENUM_COLUMN_TYPE === $class ) {
				return $this->column_type_enum_value( $node->name->toString() );
			}
		}

		throw Errors::invariant( "Advanced module attribute {$file} must use literal values only." );
	}

	private function column_type_enum_value( string $case ): string {
		return match ( $case ) {
			'BigInt' => 'bigint',
			'Boolean' => 'boolean',
			'DateTime' => 'datetime',
			'Decimal' => 'decimal',
			'Integer' => 'integer',
			'Json' => 'json',
			'LongText' => 'longtext',
			'String' => 'string',
			'Text' => 'text',
			default => throw Errors::invariant( "ColumnType enum case {$case} is unsupported." ),
		};
	}
}
