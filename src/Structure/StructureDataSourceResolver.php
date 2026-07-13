<?php

/**
 * Resolves structure data source options.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Structure;

use Onumia\Core\Errors;
use Onumia\Modules\ModuleDataSourceDispatcher;
use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleRegistry;

/**
 * @phpstan-type StructureOption array{value:string,label:string,description?:string,disabled?:bool,indent?:int}
 */
final class StructureDataSourceResolver {
	/**
	 * @var list<StructureOption>|null
	 */
	private ?array $admin_menu_items_cache = null;

	/**
	 * @param array<string,mixed> $params Params.
	 * @return mixed
	 */
	public function resolve( ModuleDefinition $module, ModuleRegistry $registry, ModuleDataSourceDispatcher $dispatcher, string $source, array $params = array() ): mixed {
		if ( str_starts_with( $source, 'module.' ) ) {
			$name        = substr( $source, strlen( 'module.' ) );
			$result      = $dispatcher->dispatch( $module, $name, $params );
			$data_source = $module->contract()->data_source( $name );
			$shape       = null === $data_source ? 'options' : $data_source->shape;

			return 'options' === $shape ? $this->normalize_options( $result ) : $this->standard_data_response( $result, $shape );
		}

		return match ( $source ) {
			'wp.user.roles' => $this->user_roles(),
			'wp.user.capabilities' => $this->user_capabilities(),
			'wp.users' => $this->users( $params ),
			'wp.posts' => $this->posts( $params ),
			'wp.post.types' => $this->post_types( false ),
			'wp.post.types.public' => $this->post_types( true ),
			'wp.post.statuses' => $this->post_statuses(),
			'wp.terms' => $this->terms( $params ),
			'wp.taxonomies' => $this->taxonomies( $params ),
			'wp.rest.query' => $this->rest_query( $params ),
			'wp.image.sizes' => $this->image_sizes(),
			'wp.mime.types' => $this->mime_types(),
			'wp.plugins.installed' => $this->installed_plugins(),
			'wp.editor.blocks' => $this->editor_blocks(),
			'wp.admin.listTables' => $this->admin_list_tables(),
			'wp.admin.menuItems' => $this->admin_menu_items(),
			'wp.timezones' => $this->timezones(),
			'wp.rewrite.structures' => $this->rewrite_structures(),
			'onumia.modules' => $this->modules( $registry ),
			'onumia.module.categories' => $this->module_categories( $registry ),
			default => throw Errors::invariant( "Unknown structure data source {$source}." ),
		};
	}

	/**
	 * @return list<StructureOption>
	 */
	private function installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			return array();
		}

		$plugins = $this->installed_plugins_raw();
		if ( ! is_array( $plugins ) ) {
			return array();
		}

		$options = array();
		foreach ( $plugins as $file => $plugin ) {
			if ( ! is_string( $file ) || '' === $file || ! is_array( $plugin ) ) {
				continue;
			}

			$name        = is_string( $plugin['Name'] ?? null ) && '' !== $plugin['Name'] ? $plugin['Name'] : $file;
			$version     = is_string( $plugin['Version'] ?? null ) ? $plugin['Version'] : '';
			$author      = is_string( $plugin['AuthorName'] ?? null ) ? $plugin['AuthorName'] : ( is_string( $plugin['Author'] ?? null ) ? $plugin['Author'] : '' );
			$description = trim( implode( ' | ', array_filter( array( $version, $author ), static fn( string $value ): bool => '' !== $value ) ) );
			$options[]   = $this->option( $file, $name, array( 'description' => '' === $description ? null : $description ) );
		}

		usort( $options, static fn( array $left, array $right ): int => $left['label'] <=> $right['label'] );
		return $options;
	}

	/**
	 * @return list<StructureOption>
	 */
	private function editor_blocks(): array {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return array();
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		$blocks   = $this->registered_blocks_raw( $registry );
		if ( ! is_array( $blocks ) ) {
			return array();
		}

		$options = array();
		foreach ( $blocks as $name => $block ) {
			$key = $this->object_key( $name, $block );
			if ( '' === $key ) {
				continue;
			}

			$title       = is_object( $block ) && is_string( $block->title ?? null ) && '' !== $block->title ? $block->title : $this->human_label( $key );
			$category    = is_object( $block ) && is_string( $block->category ?? null ) ? $block->category : '';
			$description = '' !== $category ? $this->human_label( $category ) : null;
			$options[]   = $this->option( $key, $title, array( 'description' => $description ) );
		}

		usort( $options, static fn( array $left, array $right ): int => $left['label'] <=> $right['label'] );
		return $options;
	}

	private function installed_plugins_raw(): mixed {
		return \get_plugins();
	}

	private function registered_blocks_raw( object $registry ): mixed {
		if ( ! method_exists( $registry, 'get_all_registered' ) ) {
			return array();
		}

		return $registry->get_all_registered();
	}

	/**
	 * @return mixed
	 */
	private function standard_data_response( mixed $value, string $shape ): mixed {
		if ( 'collection' === $shape && is_array( $value ) && array_is_list( $value ) ) {
			return array(
				'items' => $value,
				'total' => count( $value ),
			);
		}

		if ( 'record' === $shape && is_array( $value ) && array_is_list( $value ) ) {
			return new \stdClass();
		}

		return $value;
	}

	/**
	 * @return list<StructureOption>
	 */
	private function user_roles(): array {
		$roles   = $this->roles();
		$options = array();
		foreach ( $roles as $slug => $role ) {
			$options[ $slug ] = is_string( $role['name'] ?? null ) ? $role['name'] : $this->human_label( $slug );
		}

		\ksort( $options );
		return $this->normalize_options( $options );
	}

	/**
	 * @return list<StructureOption>
	 */
	private function user_capabilities(): array {
		$roles        = $this->roles();
		$capabilities = array();
		foreach ( $roles as $role ) {
			$role_capabilities = $role['capabilities'] ?? null;
			if ( ! is_array( $role_capabilities ) ) {
				continue;
			}

			foreach ( $role_capabilities as $capability => $enabled ) {
				if ( is_string( $capability ) && true === $enabled ) {
					$capabilities[ $capability ] = $this->human_label( $capability );
				}
			}
		}

		\ksort( $capabilities );
		return $this->normalize_options( $capabilities );
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return list<StructureOption>
	 */
	private function users( array $params ): array {
		// @codeCoverageIgnoreStart
		if ( ! function_exists( 'get_users' ) ) {
			return array();
		}
		// @codeCoverageIgnoreEnd

		$search = $this->string_param( $params, 'search' );
		$number = $this->bounded_int_param( $params, 'number', 100, 1, 500 );
		$found  = \get_users(
			array(
				'number' => $number,
				'search' => '' === $search ? '' : '*' . $search . '*',
			)
		);

		$options = array();
		foreach ( $found as $user ) {
			$user_id = is_object( $user ) && isset( $user->ID ) && is_numeric( $user->ID ) ? (int) $user->ID : 0;
			if ( $user_id <= 0 ) {
				continue;
			}

			$options[] = $this->option( (string) $user_id, $this->user_label( $user ) );
		}

		return $options;
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return list<StructureOption>
	 */
	private function posts( array $params ): array {
		$post_types = $this->string_list_param( $params, 'postTypes' );
		$post_type  = $this->string_param( $params, 'postType' );
		if ( array() === $post_types && '' !== $post_type ) {
			$post_types = array( $post_type );
		}

		$status = $this->string_param( $params, 'status' );
		$found  = \get_posts(
			array(
				'numberposts' => $this->bounded_int_param( $params, 'number', 100, 1, 500 ),
				'orderby'     => 'title',
				'order'       => 'ASC',
				'post_status' => '' === $status ? 'any' : $status,
				'post_type'   => array() === $post_types ? 'any' : $post_types,
				's'           => $this->string_param( $params, 'search' ),
			)
		);

		$options = array();
		foreach ( $found as $post ) {
			$post_id = (int) $post->ID;
			if ( $post_id <= 0 ) {
				continue;
			}

			$title = $this->strip_tags( $post->post_title );
			$type  = $post->post_type;

			$options[] = $this->option(
				(string) $post_id,
				'' === $title ? 'Post ' . (string) $post_id : $title,
				array( 'description' => '' === $type ? null : $this->human_label( $type ) )
			);
		}

		return $options;
	}

	/**
	 * @return list<StructureOption>
	 */
	private function post_types( bool $public_only ): array {
		if ( ! function_exists( 'get_post_types' ) ) {
			return array();
		}

		$post_types = \get_post_types( $public_only ? array( 'public' => true ) : array(), 'objects' );
		$options    = array();
		foreach ( $post_types as $name => $post_type ) {
			$key = $this->object_key( $name, $post_type );
			if ( '' === $key ) {
				continue;
			}

			$options[ $key ] = $this->object_label( $post_type, $key );
		}

		\ksort( $options );
		return $this->normalize_options( $options );
	}

	/**
	 * @return list<StructureOption>
	 */
	private function post_statuses(): array {
		// @codeCoverageIgnoreStart
		if ( ! function_exists( 'get_post_stati' ) ) {
			return array();
		}
		// @codeCoverageIgnoreEnd

		$statuses = \get_post_stati( array(), 'objects' );
		$options  = array();
		foreach ( $statuses as $name => $status ) {
			$key = $this->object_key( $name, $status );
			if ( '' !== $key ) {
				$options[ $key ] = $this->object_label( $status, $key );
			}
		}

		\ksort( $options );
		return $this->normalize_options( $options );
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return list<StructureOption>
	 */
	private function taxonomies( array $params = array() ): array {
		if ( ! function_exists( 'get_taxonomies' ) ) {
			return array();
		}

		$taxonomies = \get_taxonomies(
			true === ( $params['public'] ?? false ) ? array( 'public' => true ) : array(),
			'objects'
		);
		$search     = strtolower( $this->string_param( $params, 'search' ) );
		$options    = array();
		foreach ( $taxonomies as $name => $taxonomy ) {
			$key = $this->object_key( $name, $taxonomy );
			if ( '' === $key ) {
				continue;
			}

			$label = $this->object_label( $taxonomy, $key );
			if ( '' !== $search && ! str_contains( strtolower( $key . ' ' . $label ), $search ) ) {
				continue;
			}

			$options[ $key ] = $label;
		}

		\ksort( $options );
		return $this->normalize_options( $options );
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return list<StructureOption>
	 */
	private function terms( array $params ): array {
		if ( ! function_exists( 'get_terms' ) || ! function_exists( 'get_taxonomies' ) ) {
			return array();
		}

		$taxonomies = $this->string_list_param( $params, 'taxonomies' );
		$taxonomy   = $this->string_param( $params, 'taxonomy' );
		if ( array() === $taxonomies && '' !== $taxonomy ) {
			$taxonomies = array( $taxonomy );
		}

		$raw_taxonomy_objects = \get_taxonomies( array(), 'objects' );
		$taxonomy_objects     = array();
		foreach ( $raw_taxonomy_objects as $key => $taxonomy_object ) {
			$taxonomy_key = $this->object_key( $key, $taxonomy_object );
			if ( '' !== $taxonomy_key ) {
				$taxonomy_objects[ $taxonomy_key ] = $taxonomy_object;
			}
		}
		if ( array() === $taxonomies ) {
			$taxonomies = array_keys( $taxonomy_objects );
		}

		$options = array();
		foreach ( $taxonomies as $taxonomy_name ) {
			$taxonomy_object = $taxonomy_objects[ $taxonomy_name ] ?? null;
			$options[]       = $this->option(
				'__taxonomy:' . $taxonomy_name,
				$this->object_label( $taxonomy_object, $taxonomy_name ),
				array( 'disabled' => true )
			);

			$terms = \get_terms(
				array(
					'hide_empty' => $this->bool_param( $params, 'hideEmpty', false ),
					'number'     => $this->bounded_int_param( $params, 'number', 100, 1, 500 ),
					'search'     => $this->string_param( $params, 'search' ),
					'taxonomy'   => $taxonomy_name,
				)
			);
			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$term_id = (int) $term->term_id;
				if ( $term_id <= 0 ) {
					continue;
				}

				$name = $this->strip_tags( $term->name );
				$slug = $term->slug;

				$options[] = $this->option(
					$taxonomy_name . ':' . (string) $term_id,
					'' === $name ? 'Term ' . (string) $term_id : $name,
					array(
						'description' => '' === $slug ? null : $slug,
						'indent'      => 1,
					)
				);
			}
		}

		return $options;
	}

	/**
	 * @return list<StructureOption>
	 */
	private function image_sizes(): array {
		$sizes = function_exists( 'get_intermediate_image_sizes' ) ? \get_intermediate_image_sizes() : array();
		$sizes = array_values( array_unique( array_merge( array( 'full' ), $sizes ) ) );

		return array_map(
			fn( string $size ): array => $this->option( $size, $this->human_label( $size ) ),
			$sizes
		);
	}

	/**
	 * @return list<StructureOption>
	 */
	private function mime_types(): array {
		$mime_types = function_exists( 'get_allowed_mime_types' ) ? \get_allowed_mime_types() : array();
		$options    = array();
		foreach ( $mime_types as $extensions => $mime_type ) {
			$options[ $mime_type ] = "{$mime_type} ({$extensions})";
		}

		\ksort( $options );
		return $this->normalize_options( $options );
	}

	/**
	 * @return list<StructureOption>
	 */
	private function admin_list_tables(): array {
		return $this->normalize_options(
			array(
				'posts'    => 'Posts',
				'pages'    => 'Pages',
				'media'    => 'Media',
				'comments' => 'Comments',
				'users'    => 'Users',
				'plugins'  => 'Plugins',
				'themes'   => 'Themes',
			)
		);
	}

	/**
	 * @return list<StructureOption>
	 */
	private function admin_menu_items(): array {
		if ( null !== $this->admin_menu_items_cache ) {
			return $this->admin_menu_items_cache;
		}

		$this->ensure_admin_menu_items_loaded();

		$options = array();
		foreach ( is_array( $GLOBALS['menu'] ?? null ) ? $GLOBALS['menu'] : array() as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item[2] ?? null ) ) {
				continue;
			}

			$label               = is_string( $item[0] ?? null ) ? $this->strip_tags( $item[0] ) : $item[2];
			$options[ $item[2] ] = '' === $label ? $item[2] : $label;
		}

		\ksort( $options );
		$this->admin_menu_items_cache = $this->normalize_options( $options );
		return $this->admin_menu_items_cache;
	}

	private function ensure_admin_menu_items_loaded(): void {
		if ( is_array( $GLOBALS['menu'] ?? null ) && array() !== $GLOBALS['menu'] ) {
			return;
		}

		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}

		$abspath = \constant( 'ABSPATH' );
		if ( ! is_string( $abspath ) ) {
			return;
		}

		$menu_file = rtrim( $abspath, '/\\' ) . DIRECTORY_SEPARATOR . 'wp-admin' . DIRECTORY_SEPARATOR . 'menu.php';
		if ( ! is_file( $menu_file ) ) {
			return;
		}

		global $menu, $submenu, $pagenow, $plugin_page, $typenow, $taxnow;

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress builds admin menu globals in wp-admin/menu.php; REST requests need the same context.
		$menu        = array();
		$submenu     = array();
		$pagenow     = is_string( $pagenow ?? null ) && '' !== $pagenow ? $pagenow : 'index.php';
		$plugin_page = is_string( $plugin_page ?? null ) ? $plugin_page : null;
		$typenow     = is_string( $typenow ?? null ) ? $typenow : '';
		$taxnow      = is_string( $taxnow ?? null ) ? $taxnow : '';
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		require $menu_file;
	}

	/**
	 * @return list<StructureOption>
	 */
	private function timezones(): array {
		return array_map(
			fn( string $timezone ): array => $this->option( $timezone, $timezone ),
			\DateTimeZone::listIdentifiers()
		);
	}

	/**
	 * @return list<StructureOption>
	 */
	private function rewrite_structures(): array {
		return $this->normalize_options(
			array(
				'plain'      => 'Plain',
				'day-name'   => 'Day and name',
				'month-name' => 'Month and name',
				'numeric'    => 'Numeric',
				'post-name'  => 'Post name',
			)
		);
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return list<StructureOption>
	 */
	private function rest_query( array $params ): array {
		if ( ! function_exists( 'rest_do_request' ) || ! class_exists( '\WP_REST_Request' ) ) {
			return array();
		}

		$route = $this->string_param( $params, 'route' );
		if ( '' === $route || str_contains( $route, '://' ) ) {
			return array();
		}

		$parts = parse_url( '/' . ltrim( $route, '/' ) );
		$path  = is_array( $parts ) && is_string( $parts['path'] ?? null ) ? $parts['path'] : '';
		// @codeCoverageIgnoreStart
		if ( '' === $path ) {
			return array();
		}
		// @codeCoverageIgnoreEnd

		$request = new \WP_REST_Request( 'GET', $path );
		if ( is_array( $parts ) && is_string( $parts['query'] ?? null ) ) {
			parse_str( $parts['query'], $query_params );
			foreach ( $query_params as $key => $value ) {
				if ( is_string( $key ) ) {
					$request->set_param( $key, $value );
				}
			}
		}

		$search = $this->string_param( $params, 'search' );
		if ( '' !== $search ) {
			$request->set_param( $this->string_param( $params, 'searchParam', 'search' ), $search );
		}

		$data = $this->rest_response_data( \rest_do_request( $request ) );
		if ( null === $data ) {
			return array();
		}

		$items_path = $this->string_param( $params, 'itemsPath' );
		$items      = '' === $items_path ? $data : $this->value_at_path( $data, $items_path );
		if ( ! is_array( $items ) ) {
			return array();
		}

		return $this->rest_items_to_options(
			$items,
			$this->string_param( $params, 'valuePath', 'id' ),
			$this->string_param( $params, 'labelPath', 'name' )
		);
	}

	private function rest_response_data( mixed $response ): mixed {
		if ( $response instanceof \WP_Error ) {
			return null;
		}

		return $response instanceof \WP_REST_Response ? $response->get_data() : $response;
	}

	/**
	 * @return list<StructureOption>
	 */
	private function modules( ModuleRegistry $registry ): array {
		return array_values(
			array_map(
				fn( ModuleDefinition $module ): array => $this->option( $module->name(), $module->label() ),
				$registry->all()
			)
		);
	}

	/**
	 * @return list<StructureOption>
	 */
	private function module_categories( ModuleRegistry $registry ): array {
		$categories = array();
		foreach ( $registry->all() as $module ) {
			$categories[ $module->category() ] = $this->human_label( $module->category() );
		}

		\ksort( $categories );
		return $this->normalize_options( $categories );
	}

	/**
	 * @param array<array-key,mixed> $items Items.
	 * @return list<StructureOption>
	 */
	private function rest_items_to_options( array $items, string $value_path, string $label_path ): array {
		$options = array();
		foreach ( $items as $key => $item ) {
			$value = $this->value_at_path( $item, $value_path );
			$label = $this->value_at_path( $item, $label_path );

			if ( ! is_scalar( $value ) ) {
				$value = is_string( $key ) ? $key : null;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$options[] = $this->option(
				(string) $value,
				is_scalar( $label ) && '' !== (string) $label ? $this->strip_tags( (string) $label ) : (string) $value
			);
		}

		return $options;
	}

	private function value_at_path( mixed $value, string $path ): mixed {
		if ( '' === $path ) {
			return $value;
		}

		$current = $value;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			if ( is_array( $current ) ) {
				$current = $current[ $segment ] ?? ( ctype_digit( $segment ) ? ( $current[ (int) $segment ] ?? null ) : null );
				continue;
			}

			if ( is_object( $current ) ) {
				$current = $current->{$segment} ?? null;
				continue;
			}

			return null;
		}

		return $current;
	}

	/**
	 * @param array<string,mixed> $params Params.
	 */
	private function string_param( array $params, string $key, string $fallback = '' ): string {
		$value = $params[ $key ] ?? null;

		return is_string( $value ) ? trim( $value ) : $fallback;
	}

	/**
	 * @param array<string,mixed> $params Params.
	 */
	private function bool_param( array $params, string $key, bool $fallback ): bool {
		return is_bool( $params[ $key ] ?? null ) ? $params[ $key ] : $fallback;
	}

	/**
	 * @param array<string,mixed> $params Params.
	 */
	private function bounded_int_param( array $params, string $key, int $fallback, int $min, int $max ): int {
		$value = $params[ $key ] ?? null;
		if ( is_numeric( $value ) ) {
			return max( $min, min( $max, (int) $value ) );
		}

		return max( $min, min( $max, $fallback ) );
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return list<string>
	 */
	private function string_list_param( array $params, string $key ): array {
		$value = $params[ $key ] ?? array();
		if ( is_string( $value ) ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					fn( mixed $item ): string => is_scalar( $item ) ? trim( (string) $item ) : '',
					$value
				),
				fn( string $item ): bool => '' !== $item
			)
		);
	}

	private function object_label( mixed $object, string $fallback ): string {
		if ( is_object( $object ) ) {
			if ( is_object( $object->labels ?? null ) && is_string( $object->labels->singular_name ?? null ) ) {
				return $object->labels->singular_name;
			}

			if ( is_string( $object->label ?? null ) ) {
				return $object->label;
			}
		}

		if ( is_array( $object ) && is_string( $object['label'] ?? null ) ) {
			return $object['label'];
		}

		return $this->human_label( $fallback );
	}

	private function object_key( int|string $key, mixed $object ): string {
		if ( is_string( $key ) && '' !== $key ) {
			return $key;
		}

		if ( is_object( $object ) && is_string( $object->name ?? null ) && '' !== $object->name ) {
			return $object->name;
		}

		if ( is_array( $object ) && is_string( $object['name'] ?? null ) && '' !== $object['name'] ) {
			return $object['name'];
		}

		return (string) $key;
	}

	/**
	 * @return list<StructureOption>
	 */
	private function normalize_options( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$options = array();
		foreach ( $value as $key => $item ) {
			$option = $this->normalize_option( $key, $item );
			if ( null !== $option ) {
				$options[] = $option;
			}
		}

		return $options;
	}

	/**
	 * @param int|string $key Key.
	 * @return StructureOption|null
	 */
	private function normalize_option( int|string $key, mixed $item ): ?array {
		if ( is_array( $item ) ) {
			$value = $item['value'] ?? null;
			$label = $item['label'] ?? null;

			if ( is_scalar( $value ) ) {
				$value = (string) $value;
				return $this->option(
					$value,
					is_string( $label ) && '' !== $label ? $label : $value,
					array(
						'description' => is_string( $item['description'] ?? null ) ? $item['description'] : null,
						'disabled'    => is_bool( $item['disabled'] ?? null ) ? $item['disabled'] : null,
						'indent'      => is_int( $item['indent'] ?? null ) ? $item['indent'] : null,
					)
				);
			}
		}

		if ( is_scalar( $item ) ) {
			$value = is_string( $key ) ? $key : (string) $item;
			$label = (string) $item;
			return $this->option( $value, '' !== $label ? $label : $value );
		}

		return null;
	}

	/**
	 * @param array{description?:string|null,disabled?:bool|null,indent?:int|null} $meta Meta.
	 * @return StructureOption
	 */
	private function option( string $value, string $label, array $meta = array() ): array {
		$option = array(
			'value' => $value,
			'label' => $label,
		);

		if ( is_string( $meta['description'] ?? null ) && '' !== $meta['description'] ) {
			$option['description'] = $meta['description'];
		}

		if ( is_bool( $meta['disabled'] ?? null ) ) {
			$option['disabled'] = $meta['disabled'];
		}

		if ( is_int( $meta['indent'] ?? null ) && $meta['indent'] > 0 ) {
			$option['indent'] = $meta['indent'];
		}

		return $option;
	}

	private function human_label( string $value ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $value ) );
	}

	private function user_label( object $user ): string {
		$name  = $this->user_string( $user, 'display_name' );
		$email = $this->user_string( $user, 'user_email' );

		if ( '' === $name ) {
			$name = $this->user_string( $user, 'user_login' );
		}

		if ( '' === $name ) {
			$name = is_numeric( $user->ID ?? null ) ? 'User ' . (string) (int) $user->ID : 'User';
		}

		return '' === $email ? $name : "{$name} ({$email})";
	}

	private function user_string( object $user, string $property ): string {
		$value = $user->{$property} ?? '';

		return is_string( $value ) ? $this->strip_tags( $value ) : '';
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function roles(): array {
		if ( function_exists( 'get_editable_roles' ) ) {
			return $this->normalize_roles( \get_editable_roles() );
		}

		if ( function_exists( 'wp_roles' ) ) {
			$roles = \wp_roles();
			return $this->normalize_roles( $roles->roles );
		}

		$wp_roles = $GLOBALS['wp_roles'] ?? null;
		if ( ! is_object( $wp_roles ) ) {
			return array();
		}

		return $this->normalize_roles( $wp_roles->roles ?? array() );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function normalize_roles( mixed $roles ): array {
		if ( ! is_array( $roles ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $roles as $slug => $role ) {
			if ( is_string( $slug ) && is_array( $role ) ) {
				$normalized[ $slug ] = $this->string_keyed_array( $role );
			}
		}

		return $normalized;
	}

	/**
	 * @param array<array-key,mixed> $value Value.
	 * @return array<string,mixed>
	 */
	private function string_keyed_array( array $value ): array {
		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}

		return $normalized;
	}

	private function strip_tags( string $value ): string {
		return function_exists( 'wp_strip_all_tags' ) ? \wp_strip_all_tags( $value ) : strip_tags( $value );
	}
}
