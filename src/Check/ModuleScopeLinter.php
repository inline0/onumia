<?php

/**
 * Onumia module and component scope linter.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Check;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Onumia\Check\Structure\StructureLinter;
use Onumia\Component\ComponentLoader;
use Onumia\Component\ComponentRegistry;
use Onumia\Lib\PhpParser\Error;
use Onumia\Lib\PhpParser\Node;
use Onumia\Lib\PhpParser\Node\Attribute as PhpAttribute;
use Onumia\Lib\PhpParser\Node\Expr\Eval_;
use Onumia\Lib\PhpParser\Node\Expr\FuncCall;
use Onumia\Lib\PhpParser\Node\Expr\ShellExec;
use Onumia\Lib\PhpParser\Node\Identifier;
use Onumia\Lib\PhpParser\Node\Name;
use Onumia\Lib\PhpParser\Node\Stmt;
use Onumia\Lib\PhpParser\Node\Stmt\Class_;
use Onumia\Lib\PhpParser\Node\Stmt\ClassMethod;
use Onumia\Lib\PhpParser\Node\Stmt\Namespace_;
use Onumia\Lib\PhpParser\Node\Stmt\Use_;
use Onumia\Lib\PhpParser\NodeTraverser;
use Onumia\Lib\PhpParser\NodeVisitorAbstract;
use Onumia\Lib\PhpParser\ParserFactory;
use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleEntryDefinition;
use Onumia\Modules\ModuleLoader;
use Onumia\Support\JsonFile;
use SplFileInfo;
use Throwable;

final class ModuleScopeLinter {
	private const REQUIRED_MODULE_FILES     = array( 'meta.json', 'structure.json', 'boot.php' );
	private const ATTRIBUTE_ACTION          = 'Onumia\\Modules\\Attributes\\Action';
	private const ATTRIBUTE_DATA_SOURCE     = 'Onumia\\Modules\\Attributes\\DataSource';
	private const ATTRIBUTE_ENTRIES         = 'Onumia\\Modules\\Attributes\\Entries';
	private const ATTRIBUTE_ENTRY_FIELD     = 'Onumia\\Modules\\Attributes\\EntryField';
	private const ATTRIBUTE_ENTRY_SECTION   = 'Onumia\\Modules\\Attributes\\EntrySection';
	private const ATTRIBUTE_INPUT           = 'Onumia\\Modules\\Attributes\\Input';
	private const ATTRIBUTE_MODULE_CONTRACT = 'Onumia\\Modules\\Attributes\\ModuleContract';
	private const ATTRIBUTE_OBJECT_SHAPE    = 'Onumia\\Modules\\Attributes\\ObjectShape';
	private const ATTRIBUTE_RELATED_ENTRIES = 'Onumia\\Modules\\Attributes\\RelatedEntries';
	private const ATTRIBUTE_TABLE           = 'Onumia\\Modules\\Attributes\\Table';
	private const ATTRIBUTE_COLUMN          = 'Onumia\\Modules\\Attributes\\Column';
	private const ATTRIBUTE_INDEX           = 'Onumia\\Modules\\Attributes\\Index';
	private const ATTRIBUTE_SECRET          = 'Onumia\\Modules\\Attributes\\Secret';
	private const ATTRIBUTE_PUBLIC_ROUTE    = 'Onumia\\Modules\\Attributes\\PublicRoute';
	private const ATTRIBUTE_JOB             = 'Onumia\\Modules\\Attributes\\Job';
	private const ATTRIBUTE_SETTING         = 'Onumia\\Modules\\Attributes\\Setting';
	private const ATTRIBUTE_WP_ACTION       = 'Onumia\\Modules\\Attributes\\WpAction';
	private const ATTRIBUTE_WP_FILTER       = 'Onumia\\Modules\\Attributes\\WpFilter';
	private const CLASS_ONLY_ATTRIBUTES     = array(
		self::ATTRIBUTE_MODULE_CONTRACT,
		self::ATTRIBUTE_SETTING,
		self::ATTRIBUTE_TABLE,
		self::ATTRIBUTE_COLUMN,
		self::ATTRIBUTE_INDEX,
		self::ATTRIBUTE_SECRET,
	);
	private const METHOD_ONLY_ATTRIBUTES    = array(
		self::ATTRIBUTE_ACTION,
		self::ATTRIBUTE_DATA_SOURCE,
		self::ATTRIBUTE_ENTRIES,
		self::ATTRIBUTE_ENTRY_FIELD,
		self::ATTRIBUTE_ENTRY_SECTION,
		self::ATTRIBUTE_INPUT,
		self::ATTRIBUTE_OBJECT_SHAPE,
		self::ATTRIBUTE_PUBLIC_ROUTE,
		self::ATTRIBUTE_RELATED_ENTRIES,
		self::ATTRIBUTE_JOB,
		self::ATTRIBUTE_WP_ACTION,
		self::ATTRIBUTE_WP_FILTER,
	);
	private const FORBIDDEN_FUNCTIONS       = array(
		'exec',
		'passthru',
		'pcntl_exec',
		'popen',
		'proc_open',
		'shell_exec',
		'system',
	);
	private const UI_STRING_KEYS            = array(
		'description',
		'emptyLabel',
		'errorLabel',
		'label',
		'loadingLabel',
		'placeholder',
		'searchLabel',
		'searchPlaceholder',
		'text',
		'title',
	);
	private const HELP_TEXT_STRING_KEYS     = array(
		'description' => true,
		'emptyLabel' => true,
		'errorLabel' => true,
		'loadingLabel' => true,
		'text' => true,
	);

	public function __construct(
		private readonly ComponentLoader $component_loader = new ComponentLoader(),
	) {}

	/**
	 * @return Finding[]
	 */
	public function lint_target( string $target_dir ): array {
		$target_dir      = rtrim( $target_dir, '/\\' );
		$component_roots = array( $target_dir . DIRECTORY_SEPARATOR . 'components' );
		$findings        = $this->lint_component_roots( $component_roots );
		$registry        = $this->component_registry( $component_roots, $findings );
		$module_root     = $target_dir . DIRECTORY_SEPARATOR . 'modules';
		$findings        = array_merge(
			$findings,
			$this->lint_module_root( $module_root, new ModuleLoader( component_registry: $registry ) ),
			$this->lint_merged_source_folders( $target_dir, $module_root )
		);

		return $this->sort_findings( $findings );
	}

	/**
	 * @param string[] $paths Paths.
	 * @return Finding[]
	 */
	public function lint_paths( array $paths ): array {
		$findings = array();
		$loader   = new ModuleLoader();
		foreach ( $paths as $path ) {
			$findings = array_merge( $findings, $this->lint_path( $path, $loader ) );
		}

		return $this->sort_findings( $findings );
	}

	/**
	 * @return Finding[]
	 */
	private function lint_path( string $path, ModuleLoader $module_loader ): array {
		if ( ! file_exists( $path ) ) {
			return array( new Finding( "Check path does not exist: {$path}", 'onumia.check.pathMissing', $path ) );
		}

		if ( is_file( $path ) ) {
			return $this->lint_file( $path );
		}

		if ( is_file( $path . DIRECTORY_SEPARATOR . 'meta.json' ) ) {
			return $this->lint_module_directory( $path, $module_loader );
		}

		if ( is_file( $path . DIRECTORY_SEPARATOR . 'component.json' ) || 'components' === basename( $path ) ) {
			return $this->lint_component_roots( array( $path ) );
		}

		return $this->lint_module_root( $path, $module_loader );
	}

	/**
	 * @return Finding[]
	 */
	private function lint_module_root( string $root, ModuleLoader $module_loader ): array {
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$findings = array();
		foreach ( $this->module_directories( $root ) as $directory ) {
			$findings = array_merge( $findings, $this->lint_module_directory( $directory, $module_loader ) );
		}

		return $findings;
	}

	/**
	 * @return Finding[]
	 */
	private function lint_module_directory( string $directory, ModuleLoader $module_loader ): array {
		$findings   = array();
		$syntax_ok  = true;
		$complete   = true;
		$directory  = rtrim( $directory, '/\\' );
		$module_key = $directory . DIRECTORY_SEPARATOR;

		foreach ( self::REQUIRED_MODULE_FILES as $file ) {
			$path = $module_key . $file;
			if ( ! is_file( $path ) ) {
				$complete   = false;
				$findings[] = new Finding( "Module is missing required {$file}.", 'onumia.check.moduleFileMissing', $path );
			}
		}

		foreach ( $this->files_below( $directory ) as $file ) {
			if ( str_ends_with( $file, DIRECTORY_SEPARATOR . 'component.json' ) ) {
				$findings[] = new Finding( 'component.json belongs in a component root, not inside a module.', 'onumia.check.moduleComponentFile', $file );
			}

			if ( str_ends_with( $file, '.js' ) ) {
				$findings[] = new Finding( 'Module-local JavaScript is not supported.', 'onumia.check.noModuleJs', $file );
			}

			foreach ( $this->lint_file( $file ) as $finding ) {
				if ( 'onumia.check.phpSyntax' === $finding->identifier ) {
					$syntax_ok = false;
				}
				$findings[] = $finding;
			}
		}

		if ( $complete && $syntax_ok ) {
			$findings = array_merge( $findings, $this->lint_structure_strings( $directory ) );
		}

		if ( $complete && $syntax_ok ) {
			try {
				$module   = $module_loader->load_directory( $directory );
				$findings = array_merge( $findings, $this->lint_loaded_module( $module ) );
			} catch ( Throwable $throwable ) {
				$findings[] = new Finding( $throwable->getMessage(), 'onumia.check.moduleContract', $directory . DIRECTORY_SEPARATOR . 'meta.json' );
			}
		}

		return $findings;
	}

	/**
	 * @return Finding[]
	 */
	private function lint_loaded_module( ModuleDefinition $module ): array {
		$findings = array();
		if ( $this->is_canonical_placeholder_module( $module ) ) {
			$findings[] = new Finding(
				'Canonical placeholder modules are not allowed in the active catalog.',
				'onumia.check.placeholderModule',
				$module->directory() . DIRECTORY_SEPARATOR . 'meta.json'
			);
		}

		if ( ! $module->dev_only() && ! $module->contract()->default_enabled() ) {
			$enabled = $module->contract()->settings()['enabled'] ?? null;
			if ( is_array( $enabled ) && true === ( $enabled['default'] ?? null ) ) {
				$findings[] = new Finding(
					'Disabled-by-default modules must not default the enabled setting to true.',
					'onumia.check.defaultActivationMismatch',
					$module->directory() . DIRECTORY_SEPARATOR . 'boot.php'
				);
			}
		}

		return array_merge( $findings, $this->lint_action_reachability( $module ) );
	}

	/**
	 * @return Finding[]
	 */
	private function lint_action_reachability( ModuleDefinition $module ): array {
		$actions = $module->contract()->actions();
		if ( array() === $actions ) {
			return array();
		}

		$reachable = array_fill_keys( $module->structure()->action_refs(), true );
		$visited   = array();
		foreach ( $module->structure()->entry_refs() as $entry_name ) {
			$this->collect_entry_actions( $module, $entry_name, $reachable, $visited );
		}

		$findings = array();
		foreach ( $actions as $action_name => $action ) {
			if ( isset( $reachable[ $action_name ] ) ) {
				continue;
			}

			$findings[] = new Finding(
				"Module action {$action->name} is not reachable from structure actions, rendered Entries controls, or related Entries controls.",
				'onumia.check.actionReachability',
				$module->directory() . DIRECTORY_SEPARATOR . 'boot.php'
			);
		}

		return $findings;
	}

	/**
	 * @param array<string,true> $reachable Reachable action names.
	 * @param array<string,true> $visited   Visited entry names.
	 */
	private function collect_entry_actions( ModuleDefinition $module, string $entry_name, array &$reachable, array &$visited ): void {
		if ( isset( $visited[ $entry_name ] ) ) {
			return;
		}

		$visited[ $entry_name ] = true;
		$entry                 = $module->contract()->entry( $entry_name );
		if ( ! $entry instanceof ModuleEntryDefinition ) {
			return;
		}

		foreach ( array( $entry->create_action, $entry->update_action, $entry->delete_action ) as $action ) {
			if ( is_string( $action ) && '' !== $action ) {
				$reachable[ $action ] = true;
			}
		}

		foreach ( $entry->related_entries as $related ) {
			$this->collect_entry_actions( $module, $related->entry, $reachable, $visited );
		}
	}

	private function is_canonical_placeholder_module( ModuleDefinition $module ): bool {
		if ( $module->dev_only() ) {
			return false;
		}

		$contract     = $module->contract();
		$setting_keys = array_keys( $contract->settings() );
		sort( $setting_keys );

		if ( array( 'enabled', 'limit', 'mode', 'rules' ) !== $setting_keys ) {
			return false;
		}

		if ( array() !== $contract->hooks() || array() !== $contract->data_sources() || array() !== $contract->entries() ) {
			return false;
		}

		$actions = array_keys( $contract->actions() );
		sort( $actions );

		return array() === $actions || array( 'status' ) === $actions;
	}

	/**
	 * @return Finding[]
	 */
	private function lint_structure_strings( string $directory ): array {
		$structure_file = $this->structure_file_for_directory( $directory );
		$message_file   = $directory . DIRECTORY_SEPARATOR . 'messages' . DIRECTORY_SEPARATOR . 'en_EN.json';
		if ( ! is_file( $message_file ) ) {
			return array(
				new Finding( 'Module help and UI text require messages/en_EN.json.', 'onumia.check.messagesMissing', $message_file ),
			);
		}

		try {
			$structure = JsonFile::read_object( $structure_file, 'Structure' );
			$messages  = JsonFile::read_object( $message_file, 'Messages' );
		} catch ( Throwable ) {
			return array();
		}

		$flat_messages = $this->flatten_messages( $messages );
		return array_merge(
			( new StructureLinter() )->lint( $structure, $structure_file ),
			$this->lint_structure_node( $structure, $flat_messages, $structure_file, '$' )
		);
	}

	private function structure_file_for_directory( string $directory ): string {
		return $directory . DIRECTORY_SEPARATOR . 'structure.json';
	}

	/**
	 * @param array<mixed,mixed> $messages Messages.
	 * @return array<string,string>
	 */
	private function flatten_messages( array $messages, string $prefix = '' ): array {
		$flat = array();
		foreach ( $messages as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$path = '' === $prefix ? $key : "{$prefix}.{$key}";
			if ( is_string( $value ) ) {
				$flat[ $path ] = $value;
				continue;
			}

			if ( is_array( $value ) ) {
				$flat = array_merge( $flat, $this->flatten_messages( $value, $path ) );
			}
		}

		return $flat;
	}

	/**
	 * @param array<mixed,mixed>   $node     Node.
	 * @param array<string,string> $messages Messages.
	 * @return Finding[]
	 */
	private function lint_structure_node( array $node, array $messages, string $file, string $path ): array {
		$findings = array();
		if ( $this->is_component_node( $node ) ) {
			$findings = array_merge( $findings, $this->lint_component_help( $node, $messages, $file, $path ) );
		}

		foreach ( $node as $key => $value ) {
			$child_path = is_int( $key ) ? "{$path}[{$key}]" : "{$path}.{$key}";
			if ( is_string( $value ) ) {
				$findings = array_merge( $findings, $this->lint_structure_string( $key, $value, $messages, $file, $child_path ) );
				continue;
			}

			if ( is_array( $value ) ) {
				$findings = array_merge( $findings, $this->lint_structure_node( $value, $messages, $file, $child_path ) );
			}
		}

		return $findings;
	}

	/**
	 * @param array<mixed> $node Node.
	 */
	private function is_component_node( array $node ): bool {
		return is_string( $node['type'] ?? null );
	}

	/**
	 * @param array<mixed>         $node     Component node.
	 * @param array<string,string> $messages Messages.
	 * @return Finding[]
	 */
	private function lint_component_help( array $node, array $messages, string $file, string $path ): array {
		$help = $node['help'] ?? null;
		if ( ! is_array( $help ) ) {
			return array();
		}

		if ( ! is_string( $help['text'] ?? null ) ) {
			return array();
		}

		return $this->lint_message_template(
			$help['text'],
			$messages,
			$file,
			"{$path}.help.text",
			'onumia.check.structureHelpMessage',
			true
		);
	}

	/**
	 * @param int|string           $key      Key.
	 * @param array<string,string> $messages Messages.
	 * @return Finding[]
	 */
	private function lint_structure_string( int|string $key, string $value, array $messages, string $file, string $path ): array {
		if ( ! in_array( (string) $key, self::UI_STRING_KEYS, true ) ) {
			return array();
		}

		if ( $this->is_dynamic_template( $value ) ) {
			return array();
		}

		return $this->lint_message_template(
			$value,
			$messages,
			$file,
			$path,
			'onumia.check.structureStringMessage',
			isset( self::HELP_TEXT_STRING_KEYS[ (string) $key ] )
		);
	}

	private function is_dynamic_template( string $value ): bool {
		if ( 1 !== preg_match( '/{{\s*(item|settings|data|state)\.[^}]+\s*}}/', $value ) ) {
			return false;
		}

		$stripped = preg_replace( '/{{\s*(item|settings|data|state)\.[^}]+\s*}}/', '', $value );
		return '' === trim( is_string( $stripped ) ? $stripped : $value );
	}

	/**
	 * @param array<string,string> $messages Messages.
	 * @return Finding[]
	 */
	private function lint_message_template( string $value, array $messages, string $file, string $path, string $identifier, bool $help_text = false ): array {
		$references = $this->message_references( $value );
		if ( array() === $references ) {
			return array(
				new Finding(
					"Structure UI string at {$path} must use {{messages.*}}.",
					'onumia.check.structureStringLiteral',
					$file
				),
			);
		}

		$findings = array();
		$casing   = new MessageCasingLinter();
		foreach ( $references as $reference ) {
			if ( ! isset( $messages[ $reference ] ) ) {
				$findings[] = new Finding(
					"Structure message {$reference} referenced at {$path} does not exist.",
					$identifier,
					$file
				);
				continue;
			}

			$findings = array_merge(
				$findings,
				$help_text
					? $casing->lint_help_text( $messages[ $reference ], $reference, $file, $path )
					: $casing->lint_ui_label( $messages[ $reference ], $reference, $file, $path )
			);
		}

		return $findings;
	}

	/**
	 * @return string[]
	 */
	private function message_references( string $value ): array {
		$count = preg_match_all( '/{{\s*messages\.([^}\s]+)\s*}}/', $value, $matches );
		if ( false === $count || 0 === $count ) {
			return array();
		}

		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * @return Finding[]
	 */
	private function lint_merged_source_folders( string $target_dir, string $module_root ): array {
		$prd = $target_dir . DIRECTORY_SEPARATOR . 'MODULE_UNIFICATION_PRD.md';
		if ( ! is_file( $prd ) || ! is_dir( $module_root ) ) {
			return array();
		}

		$contents = file_get_contents( $prd );
		if ( false === $contents ) {
			return array();
		}

		$findings = array();
		foreach ( $this->merged_source_modules_from_prd( $contents ) as $target => $sources ) {
			if ( ! is_dir( $module_root . DIRECTORY_SEPARATOR . $this->module_path_from_name( $target ) ) ) {
				continue;
			}

			foreach ( $sources as $source ) {
				$source_path = $module_root . DIRECTORY_SEPARATOR . $this->module_path_from_name( $source );
				if ( is_dir( $source_path ) ) {
					$findings[] = new Finding(
						"Merged source module {$source} must be deleted after {$target} exists.",
						'onumia.check.mergedSourceModule',
						$source_path
					);
				}
			}
		}

		return $findings;
	}

	/**
	 * @return array<string,list<string>>
	 */
	private function merged_source_modules_from_prd( string $contents ): array {
		$merged = array();
		$lines  = preg_split( '/\R/', $contents );
		foreach ( false === $lines ? array() : $lines as $line ) {
			if ( 1 !== preg_match( '/^\|\s*`(onumia\/[^`]+)`\s*\|/', $line, $target_match ) ) {
				continue;
			}

			preg_match_all( '/`(onumia\/[^`]+)`/', $line, $matches );
			$modules = $matches[1];
			$target  = array_shift( $modules );
			if ( ! is_string( $target ) || array() === $modules ) {
				continue;
			}

			$merged[ $target ] = $modules;
		}

		return $merged;
	}

	private function module_path_from_name( string $module_name ): string {
		$relative = preg_replace( '/^onumia\//', '', $module_name );
		return str_replace( '/', DIRECTORY_SEPARATOR, is_string( $relative ) ? $relative : $module_name );
	}

	/**
	 * @param string[] $roots Roots.
	 * @return Finding[]
	 */
	private function lint_component_roots( array $roots ): array {
		$findings = array();
		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}

			foreach ( $this->files_below( $root ) as $file ) {
				if ( str_ends_with( $file, '.php' ) || str_ends_with( $file, '.js' ) ) {
					$findings[] = new Finding( 'Component groups must stay JSON-only.', 'onumia.check.componentExecutableFile', $file );
				}

				foreach ( $this->lint_file( $file ) as $finding ) {
					$findings[] = $finding;
				}

				if ( str_ends_with( $file, DIRECTORY_SEPARATOR . 'component.json' ) ) {
					try {
						$this->component_loader->load_file( $file );
					} catch ( Throwable $throwable ) {
						$findings[] = new Finding( $throwable->getMessage(), 'onumia.check.componentContract', $file );
					}
				}
			}

			try {
				$this->component_loader->load_root( $root );
			} catch ( Throwable $throwable ) {
				$findings[] = new Finding( $throwable->getMessage(), 'onumia.check.componentRegistry', $root );
			}
		}

		return $findings;
	}

	/**
	 * @param string[]  $component_roots Component roots.
	 * @param Finding[] $findings Existing findings.
	 */
	private function component_registry( array $component_roots, array &$findings ): ComponentRegistry {
		try {
			return ComponentRegistry::from_roots( $component_roots, $this->component_loader );
		} catch ( Throwable $throwable ) {
			$root       = $component_roots[0] ?? 'components';
			$findings[] = new Finding( $throwable->getMessage(), 'onumia.check.componentRegistry', $root );
			return new ComponentRegistry();
		}
	}

	/**
	 * @return Finding[]
	 */
	private function lint_file( string $file ): array {
		if ( str_ends_with( $file, '.json' ) ) {
			return $this->lint_json_file( $file );
		}

		if ( str_ends_with( $file, '.php' ) ) {
			return $this->lint_php_file( $file );
		}

		return array();
	}

	/**
	 * @return Finding[]
	 */
	private function lint_json_file( string $file ): array {
		$contents = file_get_contents( $file );
		json_decode( false === $contents ? '' : $contents, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return array();
		}

		return array(
			new Finding(
				'JSON syntax error: ' . json_last_error_msg(),
				'onumia.check.jsonSyntax',
				$file
			),
		);
	}

	/**
	 * @return Finding[]
	 */
	private function lint_php_file( string $file ): array {
		$contents = file_get_contents( $file );
		try {
			$stmts = ( new ParserFactory() )->createForNewestSupportedVersion()->parse( false === $contents ? '' : $contents ) ?? array();
		} catch ( Error $error ) {
			return array(
				new Finding(
					'PHP syntax error: ' . $error->getMessage(),
					'onumia.check.phpSyntax',
					$file,
					max( 1, $error->getStartLine() )
				),
			);
		}

		return array_merge(
			$this->lint_php_storage( false === $contents ? '' : $contents, $file ),
			$this->lint_php_nodes( $stmts, $file ),
			$this->lint_php_attribute_api( $stmts, $file )
		);
	}

	/**
	 * @return Finding[]
	 */
	private function lint_php_storage( string $contents, string $file ): array {
		if ( $this->is_test_file( $file ) || 1 !== preg_match( '/\$GLOBALS\[\s*[\'"]onumia_/', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		return array(
			new Finding(
				'Runtime module storage must not use $GLOBALS["onumia_*"]; use theme JSON, module tables, or request-local state.',
				'onumia.check.noSleekosGlobalsStorage',
				$file,
				$this->line_for_offset( $contents, (int) $matches[0][1] )
			),
		);
	}

	private function is_test_file( string $file ): bool {
		$normalized = str_replace( '\\', '/', $file );
		return str_contains( $normalized, '/tests/' );
	}

	private function line_for_offset( string $contents, int $offset ): int {
		return 1 + substr_count( substr( $contents, 0, max( 0, $offset ) ), "\n" );
	}

	/**
	 * @param Node[] $stmts Statements.
	 * @return array<int,Finding>
	 */
	private function lint_php_nodes( array $stmts, string $file ): array {
		$forbidden = array_fill_keys( self::FORBIDDEN_FUNCTIONS, true );
		$traverser = new NodeTraverser();
		$visitor   = new class( $file, $forbidden ) extends NodeVisitorAbstract {
			/** @var array<int,Finding> */
			private array $findings = array();

			/** @var array<string,bool> */
			private readonly array $forbidden;

			/**
			 * @param array<string,bool> $forbidden Forbidden functions.
			 */
			public function __construct(
				private readonly string $file,
				array $forbidden,
			) {
				$this->forbidden = $forbidden;
			}

			public function enterNode( Node $node ) {
				if ( $node instanceof ClassMethod && 'contract' === $node->name->toString() ) {
					$this->findings[] = new Finding(
						'Module PHP contracts must use attributes instead of contract().',
						'onumia.check.legacyModuleContract',
						$this->file,
						$node->getStartLine()
					);
					return null;
				}

				if ( $node instanceof Eval_ || $node instanceof ShellExec ) {
					$this->findings[] = new Finding(
						'Dynamic code execution is not allowed in module PHP.',
						'onumia.check.forbiddenPhpExecution',
						$this->file,
						$node->getStartLine()
					);
					return null;
				}

				if ( ! $node instanceof FuncCall || ! $node->name instanceof Name ) {
					return null;
				}

				$name = strtolower( $node->name->toString() );
				if ( isset( $this->forbidden[ $name ] ) ) {
					$this->findings[] = new Finding(
						"Forbidden PHP function {$name}().",
						'onumia.check.forbiddenPhpFunction',
						$this->file,
						$node->getStartLine()
					);
				}

				return null;
			}

			/**
			 * @return array<int,Finding>
			 */
			public function findings(): array {
				return $this->findings;
			}
		};
		$traverser->addVisitor( $visitor );
		$traverser->traverse( $stmts );

		return $visitor->findings();
	}

	/**
	 * @param Node[] $stmts Statements.
	 * @return Finding[]
	 */
	private function lint_php_attribute_api( array $stmts, string $file ): array {
		return $this->lint_attribute_statements( $stmts, $file, '', array() );
	}

	/**
	 * @param Node[]               $stmts Statements.
	 * @param array<string,string> $uses  Uses.
	 * @return Finding[]
	 */
	private function lint_attribute_statements( array $stmts, string $file, string $namespace, array $uses ): array {
		$findings = array();
		foreach ( $stmts as $stmt ) {
			if ( $stmt instanceof Namespace_ ) {
				$findings = array_merge(
					$findings,
					$this->lint_attribute_statements( $stmt->stmts, $file, null === $stmt->name ? '' : $stmt->name->toString(), array() )
				);
				continue;
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

			$findings = array_merge( $findings, $this->lint_class_attributes( $stmt, $file, $namespace, $uses ) );
		}

		return $findings;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return Finding[]
	 */
	private function lint_class_attributes( Class_ $class, string $file, string $namespace, array $uses ): array {
		$findings = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		foreach ( $this->attributes( $class->attrGroups, $namespace, $uses ) as $attribute ) {
			if ( in_array( $attribute['class'], self::METHOD_ONLY_ATTRIBUTES, true ) ) {
				$findings[] = new Finding(
					"Onumia attribute {$attribute['short']} must be declared on a method.",
					'onumia.check.attributeTarget',
					$file,
					$attribute['line']
				);
			}
		}

		foreach ( $class->getMethods() as $method ) {
			$findings = array_merge( $findings, $this->lint_method_attributes( $method, $file, $namespace, $uses ) );
		}

		return $findings;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 * @return Finding[]
	 */
	private function lint_method_attributes( ClassMethod $method, string $file, string $namespace, array $uses ): array {
		$findings = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- php-parser AST property.
		$attributes = $this->attributes( $method->attrGroups, $namespace, $uses );
		$classes    = array_column( $attributes, 'class' );
		foreach ( $attributes as $attribute ) {
			if ( in_array( $attribute['class'], self::CLASS_ONLY_ATTRIBUTES, true ) ) {
				$findings[] = new Finding(
					"Onumia attribute {$attribute['short']} must be declared on the module class.",
					'onumia.check.attributeTarget',
					$file,
					$attribute['line']
				);
			}
		}

		if (
			in_array( self::ATTRIBUTE_INPUT, $classes, true )
			&& ! in_array( self::ATTRIBUTE_ACTION, $classes, true )
			&& ! in_array( self::ATTRIBUTE_DATA_SOURCE, $classes, true )
			&& ! in_array( self::ATTRIBUTE_PUBLIC_ROUTE, $classes, true )
		) {
			$findings[] = new Finding(
				'Onumia Input attributes must belong to an Action or DataSource method.',
				'onumia.check.orphanInputAttribute',
				$file,
				$method->getStartLine()
			);
		}

		return $findings;
	}

	/**
	 * @param array<\Onumia\Lib\PhpParser\Node\AttributeGroup> $groups Attribute groups.
	 * @param array<string,string>                              $uses   Uses.
	 * @return list<array{class:string,short:string,line:int}>
	 */
	private function attributes( array $groups, string $namespace, array $uses ): array {
		$attributes = array();
		foreach ( $groups as $group ) {
			foreach ( $group->attrs as $attribute ) {
				$class = $this->resolve_attribute_name( $attribute, $namespace, $uses );
				if ( ! str_starts_with( $class, 'Onumia\\Modules\\Attributes\\' ) ) {
					continue;
				}

				$attributes[] = array(
					'class' => $class,
					'short' => $attribute->name->getLast(),
					'line'  => $attribute->getStartLine(),
				);
			}
		}

		return $attributes;
	}

	/**
	 * @param array<string,string> $uses Uses.
	 */
	private function resolve_attribute_name( PhpAttribute $attribute, string $namespace, array $uses ): string {
		$name  = $attribute->name;
		$parts = $name->getParts();
		$first = $parts[0];
		if ( isset( $uses[ $first ] ) ) {
			$rest = array_slice( $parts, 1 );
			return $uses[ $first ] . ( array() === $rest ? '' : '\\' . implode( '\\', $rest ) );
		}

		if ( $name->isFullyQualified() ) {
			return $name->toString();
		}

		if ( str_contains( $name->toString(), '\\' ) ) {
			return '' === $namespace ? $name->toString() : $namespace . '\\' . $name->toString();
		}

		return 'Onumia\\Modules\\Attributes\\' . $name->toString();
	}

	/**
	 * @return string[]
	 */
	private function module_directories( string $root ): array {
		$directories = array();
		foreach ( $this->files_below( $root ) as $file ) {
			if ( 'meta.json' === basename( $file ) ) {
				$directories[] = dirname( $file );
			}
		}

		sort( $directories );
		return $directories;
	}

	/**
	 * @return string[]
	 */
	private function files_below( string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $entry ) {
			if ( ! $entry instanceof SplFileInfo || ! $entry->isFile() ) {
				continue;
			}

			$files[] = $entry->getPathname();
		}

		sort( $files );
		return $files;
	}

	/**
	 * @param Finding[] $findings Findings.
	 * @return Finding[]
	 */
	private function sort_findings( array $findings ): array {
		usort(
			$findings,
			static fn( Finding $a, Finding $b ): int => array( $a->file, $a->line, $a->identifier ) <=> array( $b->file, $b->line, $b->identifier )
		);

		return $findings;
	}
}
