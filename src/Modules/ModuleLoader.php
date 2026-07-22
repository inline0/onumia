<?php

/**
 * Loads module folders.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Onumia\Component\ComponentRegistry;
use Onumia\Core\Errors;
use Onumia\Messages\MessageCatalog;
use Onumia\Messages\MessageLoader;
use Onumia\Schema\SchemaValidator;
use Onumia\Structure\StructureComponentTypes;
use Onumia\Structure\StructureDataSourceRegistry;
use Onumia\Structure\StructureDefinition;
use Onumia\Structure\StructureLoader;
use Onumia\Support\JsonFile;
use SplFileInfo;

/**
 * @phpstan-type ModuleParsedPublicRoute array{path:string,method:string,auth:string,rate_limit:int}
 * @phpstan-type ModuleParsedJob array{name:?string,schedule:string,enabled:bool,run_on_activation:bool}
 * @phpstan-type ModuleParsedMethod array{required:int,total:int,public:bool,static:bool,returnsItemsTotalShape:bool,actions:list<array<string,mixed>>,dataSources:list<array<string,mixed>>,entries:list<array<string,mixed>>,entryFields:list<array<string,mixed>>,entrySections:list<array<string,mixed>>,relatedEntries:list<array<string,mixed>>,publicRoutes:list<ModuleParsedPublicRoute>,jobs:list<ModuleParsedJob>,inputs:array<string,array<string,mixed>>,hooks:list<array{type:string,hook:string,priority:int,accepted_args:int}>}
 */
final class ModuleLoader {
	private const DEFINITION_CACHE_VERSION = 1;

	private ?string $definition_cache_dir = null;

	public function __construct(
		private readonly SchemaValidator $validator = new SchemaValidator(),
		private readonly ModulePhpContractParser $contract_parser = new ModulePhpContractParser(),
		private readonly ModuleAdvancedContractParser $advanced_contract_parser = new ModuleAdvancedContractParser(),
		private readonly StructureLoader $structure_loader = new StructureLoader(),
		private readonly MessageLoader $message_loader = new MessageLoader(),
		private readonly StructureDataSourceRegistry $source_registry = new StructureDataSourceRegistry(),
		private ComponentRegistry $component_registry = new ComponentRegistry(),
	) {}

	/**
	 * Cache parsed module definitions on disk, keyed by each module
	 * directory's file signature, so unchanged modules skip the contract
	 * and structure parsers on subsequent loads.
	 */
	public function enable_definition_cache( string $directory ): void {
		$directory = rtrim( $directory, '/\\' );

		if ( '' === $directory ) {
			return;
		}

		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			return;
		}

		$this->definition_cache_dir = $directory;
	}

	/**
	 * @param string[] $roots Module root directories.
	 * @return ModuleDefinition[]
	 */
	public function load_roots( array $roots ): array {
		$this->hydrate_component_registry( $roots );

		$modules = array();
		$loaded  = array();
		foreach ( $roots as $root ) {
			foreach ( $this->load_root( $root ) as $module ) {
				if ( isset( $loaded[ $module->name() ] ) ) {
					throw Errors::invariant( "Duplicate module {$module->name()} found in {$loaded[ $module->name() ]} and {$module->directory()}." );
				}

				$loaded[ $module->name() ] = $module->directory();
				$modules[]                 = $module;
			}
		}

		return $modules;
	}

	/**
	 * @return ModuleDefinition[]
	 */
	public function load_root( string $root ): array {
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$modules = array();
		foreach ( $this->meta_files( $root ) as $file ) {
			$directory = dirname( $file );
			$module = $this->load_directory( $directory );
			$modules[] = $module;
		}

		usort( $modules, static fn( ModuleDefinition $a, ModuleDefinition $b ): int => $a->name() <=> $b->name() );
		return $modules;
	}

	public function load_directory( string $directory ): ModuleDefinition {
		$cache_file = null;
		$signature  = null;

		if ( null !== $this->definition_cache_dir ) {
			$signature  = $this->directory_signature( $directory );
			$cache_file = $this->definition_cache_dir . DIRECTORY_SEPARATOR . md5( $directory ) . '.cache';
			$cached     = $this->read_cached_definition( $cache_file, $signature );

			if ( null !== $cached ) {
				$this->hydrate_component_registry_for_directory( $directory );

				return $cached;
			}
		}

		$module = $this->parse_directory( $directory );

		if ( null !== $cache_file && null !== $signature ) {
			$this->write_cached_definition( $cache_file, $signature, $module );
		}

		return $module;
	}

	private function parse_directory( string $directory ): ModuleDefinition {
		$meta_file = $directory . DIRECTORY_SEPARATOR . 'meta.json';
		$meta      = JsonFile::read_object( $meta_file, 'Module meta' );
		$this->validator->validate_meta( $meta, $meta_file );
		$this->hydrate_component_registry_for_directory( $directory );

		$structure = $this->structure_loader->load_directory( $directory );
		$messages  = $this->message_loader->load_directory( $directory );
		$parsed    = $this->contract_parser->parse_file( $directory . DIRECTORY_SEPARATOR . 'boot.php' );
		$contract  = $parsed[0];
		$advanced  = $this->advanced_contract_parser->parse_optional_files( $directory )->merge( $this->advanced_from_methods( $parsed[1] ) );
		$contract  = $this->hydrate_table_entry_fields( $contract, $advanced );

		$this->cross_validate( $structure, $contract, $messages, $advanced );

		return new ModuleDefinition( $directory, $meta, $contract, $structure, $messages, $advanced );
	}

	private function directory_signature( string $directory ): string {
		$parts = array( 'v' . self::DEFINITION_CACHE_VERSION );

		if ( is_dir( $directory ) ) {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $files as $file ) {
				if ( $file instanceof \SplFileInfo && $file->isFile() ) {
					$parts[] = $file->getPathname() . ':' . $file->getMTime() . ':' . $file->getSize();
				}
			}
		}

		sort( $parts );

		return md5( implode( '|', $parts ) );
	}

	private function read_cached_definition( string $cache_file, string $signature ): ?ModuleDefinition {
		if ( ! is_file( $cache_file ) ) {
			return null;
		}

		$raw = @file_get_contents( $cache_file );
		if ( false === $raw || '' === $raw ) {
			return null;
		}

		$payload = @unserialize( $raw );
		if ( ! is_array( $payload ) || ( $payload['signature'] ?? null ) !== $signature ) {
			return null;
		}

		$module = $payload['module'] ?? null;

		return $module instanceof ModuleDefinition ? $module : null;
	}

	private function write_cached_definition( string $cache_file, string $signature, ModuleDefinition $module ): void {
		$payload = serialize(
			array(
				'signature' => $signature,
				'module'    => $module,
			)
		);

		$tmp = $cache_file . '.' . getmypid() . '.tmp';
		if ( false === @file_put_contents( $tmp, $payload ) ) {
			return;
		}

		if ( ! @rename( $tmp, $cache_file ) ) {
			@unlink( $tmp );
		}
	}

	/**
	 * @param array<string,ModuleParsedMethod> $methods Methods.
	 */
	private function advanced_from_methods( array $methods ): ModuleAdvancedContractDefinition {
		$routes = array();
		$jobs   = array();
		foreach ( $methods as $method => $method_info ) {
			foreach ( $method_info['publicRoutes'] as $route ) {
				if ( ! $method_info['public'] || $method_info['static'] ) {
					throw Errors::invariant( "PublicRoute method {$method} must be public and non-static." );
				}

				$routes[] = new ModulePublicRouteDefinition(
					$route['path'],
					$route['method'],
					$route['auth'],
					$route['rate_limit'],
					$method,
					$method_info['required'],
					$method_info['total'],
					$method_info['inputs']
				);
			}

			foreach ( $method_info['jobs'] as $job ) {
				if ( ! $method_info['public'] || $method_info['static'] ) {
					throw Errors::invariant( "Job method {$method} must be public and non-static." );
				}

				$name   = null === $job['name'] ? $this->callable_name( $method ) : $job['name'];
				$jobs[] = new ModuleJobDefinition( $name, $job['schedule'], $job['enabled'], $method, $job['run_on_activation'] );
			}
		}

		return new ModuleAdvancedContractDefinition( public_routes: $routes, jobs: $jobs );
	}

	private function callable_name( string $method ): string {
		$parts = explode( '_', $method );
		$name  = array_shift( $parts );
		foreach ( $parts as $part ) {
			$name .= ucfirst( $part );
		}

		return $name;
	}

	/**
	 * @return string[]
	 */
	private function meta_files( string $root ): array {
		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $entry ) {
			if ( ! $entry instanceof SplFileInfo || ! $entry->isFile() || 'meta.json' !== $entry->getFilename() ) {
				continue;
			}

			$files[] = $entry->getPathname();
		}

		sort( $files );
		return $files;
	}

	private function cross_validate( StructureDefinition $structure, ModuleContractDefinition $contract, MessageCatalog $messages, ModuleAdvancedContractDefinition $advanced ): void {
		foreach ( $structure->setting_refs() as $setting ) {
			if ( ! $contract->has_setting_path( $setting ) ) {
				throw Errors::invariant( "Structure {$structure->file()} references unknown setting {$setting}." );
			}
		}

		foreach ( $structure->action_refs() as $action ) {
			if ( null === $contract->action( $action ) ) {
				throw Errors::invariant( "Structure {$structure->file()} references unknown action {$action}." );
			}
		}

		foreach ( $structure->message_refs() as $message ) {
			if ( ! $messages->has( $message ) ) {
				throw Errors::invariant( "Structure {$structure->file()} references unknown message {$message}." );
			}
		}

		foreach ( $structure->source_refs() as $source ) {
			if ( str_starts_with( $source, 'module.' ) ) {
				$name = substr( $source, strlen( 'module.' ) );
				if ( '' === $name || null === $contract->data_source( $name ) ) {
					throw Errors::invariant( "Structure {$structure->file()} references unknown module data source {$source}." );
				}
				continue;
			}

			if ( ! $this->source_registry->has( $source ) ) {
				throw Errors::invariant( "Structure {$structure->file()} references unknown preset data source {$source}." );
			}
		}

		foreach ( $structure->entry_refs() as $entry ) {
			if ( null === $contract->entry( $entry ) ) {
				throw Errors::invariant( "Structure {$structure->file()} references unknown entry {$entry}." );
			}
		}

		foreach ( $contract->entries() as $entry ) {
			if ( 'table' === $entry->storage ) {
				if ( null === $entry->table || null === $advanced->table( $entry->table ) ) {
					throw Errors::invariant( "Entry {$entry->name} references unknown table {$entry->table}." );
				}
			}
		}

		$local_components = array_fill_keys( $structure->component_names(), true );
		foreach ( $structure->component_refs() as $component_ref ) {
			if ( ! isset( $local_components[ $component_ref ] ) && ! $this->component_registry->has( $component_ref ) ) {
				throw Errors::invariant( "Structure {$structure->file()} references unknown component {$component_ref}." );
			}
		}

		$this->validate_enable_bindings( $structure, $contract, $messages );
	}

	private function table_column( ?ModuleTableDefinition $table, string $column_name ): ?ModuleColumnDefinition {
		if ( null === $table ) {
			return null;
		}

		foreach ( $table->columns as $column ) {
			if ( $column_name === $column->name ) {
				return $column;
			}
		}

		return null;
	}

	private function hydrate_table_entry_fields( ModuleContractDefinition $contract, ModuleAdvancedContractDefinition $advanced ): ModuleContractDefinition {
		$entries = array();
		foreach ( $contract->entries() as $name => $entry ) {
			$entries[ $name ] = 'table' === $entry->storage
				? $this->hydrate_table_entry_field_metadata( $entry, $advanced->table( (string) $entry->table ), $contract )
				: $entry;
		}

		return $contract->with_entries( $entries );
	}

	private function hydrate_table_entry_field_metadata( ModuleEntryDefinition $entry, ?ModuleTableDefinition $table, ModuleContractDefinition $contract ): ModuleEntryDefinition {
		if ( null === $table ) {
			return $entry;
		}

		$fields          = $entry->fields;
		$explicit_fields = array_fill_keys( array_keys( $fields ), true );
		$infer_all       = array() === $fields;
		$column_order    = 0;
		foreach ( $table->columns as $column ) {
			++$column_order;
			$field_name = $column->entry_path ?? $column->name;
			if ( isset( $fields[ $field_name ] ) ) {
				continue;
			}

			if ( ! $infer_all && ! $this->column_has_entry_metadata( $column ) ) {
				continue;
			}

			$fields[ $field_name ] = $this->entry_field_from_column( $column, $entry, $column_order );
		}

		$hydrated = new ModuleEntryDefinition(
			$entry->name,
			$entry->singular,
			$entry->plural,
			$entry->key,
			$entry->storage,
			$entry->setting,
			$entry->source,
			$entry->table,
			$entry->create_action,
			$entry->update_action,
			$entry->delete_action,
			$entry->close_on_success,
			$entry->destructive_mode,
			$fields,
			$entry->sections,
			$entry->related_entries
		);

		$this->validate_hydrated_table_entry( $hydrated, $table, $contract, $explicit_fields );

		return $hydrated;
	}

	private function column_has_entry_metadata( ModuleColumnDefinition $column ): bool {
		return '' !== $column->label
			|| array() !== $column->allowed
			|| $column->table_list
			|| $column->table_filter
			|| null !== $column->filter_type
			|| array() !== $column->filter_operators
			|| array() !== $column->props
			|| $column->entry_list
			|| '' !== $column->entry_field
			|| null !== $column->entry_section
			|| 0 !== $column->entry_order
			|| null !== $column->entry_path
			|| $column->sortable
			|| $column->searchable
			|| null !== $column->required;
	}

	private function entry_field_from_column( ModuleColumnDefinition $column, ModuleEntryDefinition $entry, int $column_order ): ModuleEntryFieldDefinition {
		$field_name = $column->entry_path ?? $column->name;
		$primary    = $field_name === $entry->key || $column->primary;
		$type       = '' === $column->entry_field ? $this->entry_field_type_from_column( $column ) : $column->entry_field;
		$options    = $this->entry_field_options_from_column( $column );

		return new ModuleEntryFieldDefinition(
			$field_name,
			$type,
			'' === $column->label ? $this->human_label( $field_name ) : $column->label,
			$column->default,
			$column->allowed,
			$this->numeric_column_min( $column ),
			null,
			null,
			$column->required ?? ( ! $column->nullable && null === $column->default && ! $column->auto_increment ),
			$primary,
			$primary || $column->entry_list || $column->table_list,
			$column->table_filter || null !== $column->filter_type,
			$this->entry_filter_type_from_column( $column ),
			$options,
			$this->entry_options_source_from_column( $column ),
			$column->entry_section,
			$column->entry_create && ! $column->auto_increment,
			$column->entry_update && ! $primary && ! $column->auto_increment,
			$primary || $column->auto_increment,
			0 === $column->entry_order ? $column_order * 10 : $column->entry_order,
			null,
			$column->props
		);
	}

	private function entry_field_type_from_column( ModuleColumnDefinition $column ): string {
		return match ( $column->type ) {
			'bigint', 'integer' => 'integer',
			'boolean' => 'boolean',
			'decimal' => 'number',
			'json' => 'object',
			default => 'string',
		};
	}

	private function entry_filter_type_from_column( ModuleColumnDefinition $column ): string {
		if ( null !== $column->filter_type ) {
			return $column->filter_type;
		}

		if ( array() !== $column->allowed || array() !== $this->entry_field_options_from_column( $column ) || null !== $this->entry_options_source_from_column( $column ) ) {
			return 'option';
		}

		return match ( $column->type ) {
			'bigint', 'decimal', 'integer' => 'number',
			'boolean' => 'boolean',
			default => 'text',
		};
	}

	private function numeric_column_min( ModuleColumnDefinition $column ): ?int {
		if ( ! $column->unsigned || ! in_array( $column->type, array( 'bigint', 'decimal', 'integer' ), true ) ) {
			return null;
		}

		return 0;
	}

	/**
	 * @return list<array{label:string,value:string}>
	 */
	private function entry_field_options_from_column( ModuleColumnDefinition $column ): array {
		$options = $column->props['options'] ?? null;
		if ( ! is_array( $options ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $options as $option ) {
			if ( ! is_array( $option ) || ! is_string( $option['value'] ?? null ) || ! is_string( $option['label'] ?? null ) ) {
				continue;
			}

			$normalized[] = array(
				'label' => $option['label'],
				'value' => $option['value'],
			);
		}

		return $normalized;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function entry_options_source_from_column( ModuleColumnDefinition $column ): ?array {
		$options_source = $column->props['optionsSource'] ?? null;
		if ( ! is_array( $options_source ) ) {
			return null;
		}

		$normalized = array();
		foreach ( $options_source as $key => $value ) {
			if ( ! is_string( $key ) ) {
				return null;
			}
			$normalized[ $key ] = $value;
		}

		return $normalized;
	}

	private function human_label( string $field_name ): string {
		$leaf  = str_contains( $field_name, '.' ) ? substr( $field_name, (int) strrpos( $field_name, '.' ) + 1 ) : $field_name;
		$label = trim( (string) preg_replace( '/(?<!^)[A-Z]/', ' $0', str_replace( '_', ' ', $leaf ) ) );
		return ucwords( $label );
	}

	/**
	 * @param array<string,true> $explicit_fields Entry fields declared directly in boot.php.
	 */
	private function validate_hydrated_table_entry( ModuleEntryDefinition $entry, ModuleTableDefinition $table, ModuleContractDefinition $contract, array $explicit_fields ): void {
		if ( ! $entry->has_field( $entry->key ) ) {
			throw Errors::invariant( "Entry {$entry->name} key {$entry->key} is not declared on table {$table->name}." );
		}

		foreach ( $entry->fields as $field ) {
			if ( isset( $explicit_fields[ $field->name ] ) ) {
				continue;
			}

			$column_name = $this->entry_field_column_name( $field, $table );
			if ( null !== $column_name && null === $this->table_column( $table, $column_name ) ) {
				throw Errors::invariant( "Entry {$entry->name} field {$field->name} references unknown table column {$column_name}." );
			}
		}

		$this->validate_hydrated_entry_action_inputs( $entry, $contract );
	}

	private function entry_field_column_name( ModuleEntryFieldDefinition $field, ModuleTableDefinition $table ): ?string {
		foreach ( $table->columns as $column ) {
			$field_name = $column->entry_path ?? $column->name;
			if ( $field_name === $field->name ) {
				return $column->name;
			}
		}

		return false === strpos( $field->name, '.' ) ? $field->name : null;
	}

	private function validate_hydrated_entry_action_inputs( ModuleEntryDefinition $entry, ModuleContractDefinition $contract ): void {
		if ( null !== $entry->create_action ) {
			$action = $contract->action( $entry->create_action );
			if ( null !== $action ) {
				foreach ( $entry->fields as $field ) {
					if ( $field->create && ! $this->action_accepts_field( $action, $field->name ) ) {
						throw Errors::invariant( "Entry {$entry->name} create action {$action->name} must accept field {$field->name}." );
					}
				}
			}
		}

		if ( null !== $entry->update_action ) {
			$action = $contract->action( $entry->update_action );
			if ( null !== $action ) {
				foreach ( $entry->fields as $field ) {
					if ( $field->update && ! $this->action_accepts_field( $action, $field->name ) ) {
						throw Errors::invariant( "Entry {$entry->name} update action {$action->name} must accept field {$field->name}." );
					}
				}
				if ( ! $this->action_accepts_field( $action, $entry->key ) ) {
					throw Errors::invariant( "Entry {$entry->name} update action {$action->name} must accept primary key {$entry->key}." );
				}
			}
		}

		if ( null !== $entry->delete_action ) {
			$action = $contract->action( $entry->delete_action );
			if ( null !== $action && ! $this->action_accepts_field( $action, 'ids' ) ) {
				throw Errors::invariant( "Entry {$entry->name} delete action {$action->name} must accept ids." );
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

	private function validate_enable_bindings( StructureDefinition $structure, ModuleContractDefinition $contract, MessageCatalog $messages ): void {
		$this->validate_enable_binding_node( $structure->data(), $structure, $contract, $messages );
	}

	/**
	 * @param mixed $node Node.
	 */
	private function validate_enable_binding_node( mixed $node, StructureDefinition $structure, ModuleContractDefinition $contract, MessageCatalog $messages ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( is_array( $node['enable'] ?? null ) && is_string( $node['enable']['setting'] ?? null ) ) {
			$setting = $node['enable']['setting'];
			$this->assert_boolean_setting( $setting, 'enable', $structure, $contract );
			if ( $this->component_tree_has_control_name( $node['children'] ?? null, $setting ) ) {
				throw Errors::invariant( "Structure {$structure->file()} renders enable setting {$setting} more than once." );
			}
		}

		$type = is_string( $node['type'] ?? null ) ? StructureComponentTypes::canonical( $node['type'] ) : $node['type'] ?? null;
		if ( 'Repeater' === $type && is_array( $node['props'] ?? null ) ) {
			$props = $node['props'];
			if ( is_array( $props['itemEnable'] ?? null ) && is_string( $props['itemEnable']['setting'] ?? null ) ) {
				$setting = $props['itemEnable']['setting'];
				if ( $this->component_tree_has_control_name( $node['children'] ?? null, $setting ) ) {
					throw Errors::invariant( "Structure {$structure->file()} renders repeater item enable setting {$setting} more than once." );
				}
			}
		}

		if ( $this->component_has_stale_enable_module_label( $node, $messages ) ) {
			throw Errors::invariant( "Structure {$structure->file()} must use header enable bindings instead of an Enable module control label." );
		}

		foreach ( $node as $child ) {
			$this->validate_enable_binding_node( $child, $structure, $contract, $messages );
		}
	}

	private function assert_boolean_setting( string $setting, string $context, StructureDefinition $structure, ModuleContractDefinition $contract ): void {
		$type = $contract->setting_path_type( $setting );
		if ( 'boolean' !== $type ) {
			throw Errors::invariant( "Structure {$structure->file()} references non-boolean {$context} setting {$setting}." );
		}
	}

	/**
	 * @param mixed $node Node.
	 */
	private function component_tree_has_control_name( mixed $node, string $setting ): bool {
		if ( ! is_array( $node ) ) {
			return false;
		}

		if ( is_array( $node['props'] ?? null ) && ( $node['props']['name'] ?? null ) === $setting ) {
			return true;
		}

		foreach ( $node as $child ) {
			if ( $this->component_tree_has_control_name( $child, $setting ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<mixed> $component Component.
	 */
	private function component_has_stale_enable_module_label( array $component, MessageCatalog $messages ): bool {
		if ( ! is_array( $component['props'] ?? null ) ) {
			return false;
		}

		$label = $component['props']['label'] ?? null;
		if ( is_string( $label ) && 'Enable module' === $this->resolve_message_template( $label, $messages ) ) {
			return true;
		}

		return false;
	}

	private function resolve_message_template( string $label, MessageCatalog $messages ): string {
		if ( 1 === preg_match( '/^\{\{\s*messages\.([A-Za-z0-9_.-]+)\s*\}\}$/', $label, $matches ) ) {
			return $messages->get( $matches[1] ) ?? $label;
		}

		return $label;
	}

	/**
	 * @param string[] $module_roots Module roots.
	 */
	private function hydrate_component_registry( array $module_roots ): void {
		if ( ! $this->component_registry->is_empty() ) {
			return;
		}

		$component_roots = array();
		foreach ( $module_roots as $root ) {
			$component_roots[] = dirname( rtrim( $root, '/\\' ) ) . DIRECTORY_SEPARATOR . 'components';
		}

		$this->component_registry = ComponentRegistry::from_roots( array_values( array_unique( $component_roots ) ) );
	}

	private function hydrate_component_registry_for_directory( string $directory ): void {
		if ( ! $this->component_registry->is_empty() ) {
			return;
		}

		$component_roots = array( dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'components' );
		$current         = rtrim( $directory, '/\\' );
		while ( dirname( $current ) !== $current ) {
			if ( 'modules' === basename( $current ) ) {
				$component_roots[] = dirname( $current ) . DIRECTORY_SEPARATOR . 'components';
				break;
			}

			$current = dirname( $current );
		}

		$this->component_registry = ComponentRegistry::from_roots(
			array_values(
				array_unique(
					array_filter(
						$component_roots,
						static fn( string $root ): bool => is_dir( $root )
					)
				)
			)
		);
	}
}
