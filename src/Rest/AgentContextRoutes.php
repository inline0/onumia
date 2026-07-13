<?php

/**
 * Onumia agent context REST routes.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Rest;

use Onumia\Structure\StructureComponentTypes;
use Onumia\Support\JsonFile;

final class AgentContextRoutes {
	private const NAMESPACE = 'onumia/v1';

	public static function register(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/agent-context',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn(): \WP_REST_Response => self::context(),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);
	}

	public static function can_manage_onumia(): bool {
		return \current_user_can( 'manage_options' );
	}

	public static function context(): \WP_REST_Response {
		$instructions = array(
			'module'  => self::module_instructions(),
			'bootPhp' => self::boot_php_instructions(),
		);
		$schemas      = array(
			'meta'      => self::schema( 'meta.json' ),
			'structure' => self::schema( 'structure.json' ),
			'component' => self::schema( 'component.json' ),
			'messages'  => self::schema( 'messages.json' ),
		);

		if ( self::pro_available() ) {
			$instructions['app']        = self::app_instructions();
			$instructions['appBootPhp'] = self::app_boot_php_instructions();
			$schemas['app']             = self::schema( 'app.json' );
		}

		return new \WP_REST_Response(
			array(
				'instructions' => $instructions,
				'schemas'      => $schemas,
			),
			200
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function schema( string $file ): array {
		$schema = JsonFile::read_object( self::schema_directory() . DIRECTORY_SEPARATOR . $file, "Onumia {$file} schema" );

		if ( in_array( $file, array( 'app.json', 'component.json', 'structure.json' ), true ) ) {
			return self::schema_with_kebab_component_types( $schema );
		}

		return $schema;
	}

	/**
	 * @param array<string,mixed> $schema Schema.
	 * @return array<string,mixed>
	 */
	private static function schema_with_kebab_component_types( array $schema ): array {
		foreach ( $schema as $key => $child ) {
			$schema[ $key ] = self::map_schema_type_values( $child );
		}
		return $schema;
	}

	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function map_schema_type_values( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( isset( $value['const'] ) && is_string( $value['const'] ) ) {
			$value['const'] = StructureComponentTypes::kebab( $value['const'] );
		}

		if ( isset( $value['enum'] ) && is_array( $value['enum'] ) ) {
			$enum = array();
			$seen = array();
			foreach ( $value['enum'] as $entry ) {
				if ( is_string( $entry ) ) {
					$entry = StructureComponentTypes::kebab( $entry );
				}
				$dedupe_key = is_scalar( $entry ) || null === $entry ? gettype( $entry ) . ':' . (string) json_encode( $entry ) : null;
				if ( null !== $dedupe_key ) {
					if ( isset( $seen[ $dedupe_key ] ) ) {
						continue;
					}
					$seen[ $dedupe_key ] = true;
				}
				$enum[] = $entry;
			}
			$value['enum'] = $enum;
		}

		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::map_schema_type_values( $child );
		}

		return $value;
	}

	private static function schema_directory(): string {
		return dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'schemas';
	}

	private static function module_instructions(): string {
		return <<<'TEXT'
You edit one Onumia custom module at a time. Keep all work inside the provided virtual module files.

Allowed public module files:
- meta.json: static discovery metadata only. It owns name, category, tags, label, description, version, optional devOnly, and optional releaseEnabled/releaseReason availability metadata.
- structure.json: JSON-only UI/app contract. It owns renderer components, views, states, tabs, conditions, data requests, tables, charts, actions, and layout. Never put JavaScript, PHP callbacks, or executable expressions in structure.json.
- boot.php: PHP source of truth for runtime behavior, settings, actions, data sources, capabilities, and WordPress hooks.
- messages/*.json: optional module-local string catalogs. Do not list message paths in meta.json.

Do not create module-local JavaScript, assets, vendor folders, build output, or files outside the allowed paths. Prefer small, coherent edits. Every write-like tool call is validated automatically; rejected edits are not accepted as the current file state. If a tool command returns checker diagnostics in stderr, make a new corrected edit from the current accepted files.
TEXT;
	}

	private static function pro_available(): bool {
		return class_exists( '\Onumia\Pro\Bootstrap', false )
			&& true === \Onumia\Pro\Bootstrap::available();
	}

	private static function app_instructions(): string {
		return <<<'TEXT'
You edit one Onumia Pro custom app at a time. Keep all work inside the provided virtual app files.

Allowed public app files:
- meta.json: static discovery metadata only. It owns name, category, tags, label, description, version, and optional devOnly.
- app.json: JSON-only app renderer contract. It owns app-level views, states, layout, display components, module embeds, inline controls, conditions, data requests, tables, charts, and local component fragments.
- boot.php: PHP source of truth for app runtime behavior, capabilities, shortcode/admin/replacement surfaces, actions, and data sources.
- messages/*.json: optional app-local string catalogs. Do not list message paths in meta.json.

Do not create app-local JavaScript, assets, vendor folders, build output, or files outside the allowed paths. Apps are Pro-only and must stay in the active theme custom app area unless explicitly moved by filters.
TEXT;
	}

	private static function app_boot_php_instructions(): string {
		return <<<'TEXT'
boot.php must define exactly one PHP class extending Onumia\Pro\Apps\App.

Use PHP 8 attributes as the contract source:
- #[AppContract(capability: 'manage_options')] on the class.
- Repeatable #[AppSurface(AppSurfaceType::Shortcode|AdminPage|ReplaceAdminPage, slug: ..., title: ..., target: ..., capability: ...)] on the class.

Surfaces are PHP runtime truth. Do not duplicate shortcode tags, admin page slugs, replacement targets, or capabilities in app.json.

Typical boot.php skeleton:

<?php
declare(strict_types=1);

namespace Onumia\Pro\Apps\Custom\Example;

use Onumia\Pro\Apps\App;
use Onumia\Pro\Apps\Attributes\AppContract;
use Onumia\Pro\Apps\Attributes\AppSurface;
use Onumia\Pro\Apps\Contracts\AppSurfaceType;

#[AppContract(capability: 'manage_options')]
#[AppSurface(AppSurfaceType::AdminPage, slug: 'onumia-example', title: 'Example App')]
final class ExampleApp extends App {}
TEXT;
	}

	private static function boot_php_instructions(): string {
		return <<<'TEXT'
boot.php must define exactly one PHP class extending Onumia\Modules\Module. It must not return a class name and must not define a legacy contract() method.

Use PHP 8 attributes as the contract source:
- #[ModuleContract(default_enabled: false, capability: 'manage_options')] on the class.
- Repeatable #[Setting(name, SettingType::Boolean|String|Integer|Number|Array|Object, default: ..., allowed: ..., min: ..., max: ..., format: ...)] on the class.
- #[Action(name: null, surface: Surface::Backend, capability: null)] on public methods that are callable from structure.json.
- #[DataSource(name: null, surface: Surface::Backend, capability: null)] on public methods that return option lists or structured data for structure.json.
- Repeatable #[Input(name, SettingType::..., default: ..., allowed: ..., min: ..., max: ..., format: ..., required: false)] on action/data-source methods.
- #[WpAction(hook, priority: 10, accepted_args: null)] and #[WpFilter(hook, priority: 10, accepted_args: null)] on WordPress hook methods.

Default callable names are lowerCamel versions of snake_case method names. Prefer the default unless a stable explicit name is required. Use module helpers such as bool_setting(), string_setting(), int_setting(), float_setting(), and array_setting() when reading saved settings.

Typical boot.php skeleton:

<?php
declare(strict_types=1);

namespace Onumia\Modules\Custom\Example;

use Onumia\Modules\Attributes\Action;
use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Input;
use Onumia\Modules\Attributes\ModuleContract;
use Onumia\Modules\Attributes\Setting;
use Onumia\Modules\Attributes\WpAction;
use Onumia\Modules\Contracts\SettingType;
use Onumia\Modules\Module;

#[ModuleContract(default_enabled: false, capability: 'manage_options')]
#[Setting('enabled', SettingType::Boolean, default: false)]
final class Example extends Module {
	#[Action]
	#[Input('draft', SettingType::Boolean, default: false)]
	public function preview(array $input = array()): array {
		return array(
			'enabled' => $this->bool_setting('enabled'),
			'input' => $input,
		);
	}

	#[DataSource]
	public function choices(): array {
		return array(
			array('value' => 'one', 'label' => 'One'),
		);
	}

	#[WpAction('init')]
	public function register_runtime(): void {}
}
TEXT;
	}
}
