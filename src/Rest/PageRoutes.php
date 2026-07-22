<?php

/**
 * Onumia page REST routes.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Rest;

use Onumia\Dev\UiLabAccess;
use Onumia\Pages\PagePostType;

/**
 * @phpstan-type PageRecord array{ID:int,post_parent:int,menu_order:int,post_name:string,post_title:string}
 * @phpstan-type BlockEditorDocument array<string,mixed>
 */
final class PageRoutes {

	private const NAMESPACE          = 'onumia/v1';
	private const UUID_PATTERN       = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}';
	private const MAX_DOCUMENT_BYTES = 8_388_608;
	private const MAX_DOCUMENT_DEPTH = 100;

	public static function register(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/pages',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_pages' ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'create_page' ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>' . self::UUID_PATTERN . ')',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'update_page' ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'delete_page' ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>' . self::UUID_PATTERN . ')/document',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_page_document' ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'update_page_document' ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);
	}

	public static function can_manage_onumia(): bool {
		return \current_user_can( 'manage_options' );
	}

	public static function list_pages( \WP_REST_Request $request ): \WP_REST_Response {
		$include_development = UiLabAccess::enabled_for_rest_request( $request );

		return new \WP_REST_Response( self::prepare_pages_for_response( self::all_pages( $include_development ) ), 200 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_page_document( \WP_REST_Request $request ) {
		$id                  = self::uuid( $request->get_param( 'id' ) );
		$include_development = UiLabAccess::enabled_for_rest_request( $request );
		$post                = null === $id ? null : self::find_page( $id, $include_development );
		if ( null === $post ) {
			return new \WP_Error( 'onumia_page_not_found', 'The page was not found.', array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( self::prepare_document_for_response( $post, $id ), 200 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_page( \WP_REST_Request $request ) {
		$id = self::uuid( $request->get_param( 'id' ) );
		if ( null === $id ) {
			return new \WP_Error( 'onumia_invalid_page_id', 'A valid page UUID is required.', array( 'status' => 400 ) );
		}
		if ( null !== self::find_page( $id, true ) ) {
			return new \WP_Error( 'onumia_page_exists', 'A page with this ID already exists.', array( 'status' => 409 ) );
		}

		$include_development = UiLabAccess::enabled_for_rest_request( $request );
		$parent              = self::parent_post( $request->get_param( 'parentId' ), $include_development );
		if ( $parent instanceof \WP_Error ) {
			return $parent;
		}

		$document = self::document( $request->get_param( 'document' ), ! array_key_exists( 'document', $request->get_params() ) );
		if ( $document instanceof \WP_Error ) {
			return $document;
		}
		$emoji = self::emoji( $request->get_param( 'emoji' ) );
		if ( $emoji instanceof \WP_Error ) {
			return $emoji;
		}

		$parent_id = null === $parent ? 0 : $parent['ID'];
		$position  = self::position( $request->get_param( 'position' ) ) ?? self::next_position( $parent_id, $include_development );
		$post_id   = \wp_insert_post(
			array(
				'post_type'    => PagePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_author'  => \get_current_user_id(),
				'post_name'    => $id,
				'post_title'   => self::title( $request->get_param( 'title' ) ),
				'post_content' => '',
				'post_parent'  => $parent_id,
				'menu_order'   => $position,
			)
		);

		if ( $post_id <= 0 ) {
			return new \WP_Error( 'onumia_page_create_failed', 'The page could not be created.', array( 'status' => 500 ) );
		}

		\update_post_meta( $post_id, PagePostType::DOCUMENT_META_KEY, $document );
		\update_post_meta( $post_id, PagePostType::EMOJI_META_KEY, $emoji );

		$post = \get_post( $post_id );
		if ( ! is_object( $post ) ) {
			return new \WP_Error( 'onumia_page_create_failed', 'The created page could not be loaded.', array( 'status' => 500 ) );
		}

		return new \WP_REST_Response(
			self::prepare_page_for_response(
				self::page_record( $post ),
				self::page_ids_by_post_id( self::all_pages( $include_development ) )
			),
			201
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_page( \WP_REST_Request $request ) {
		$id                  = self::uuid( $request->get_param( 'id' ) );
		$include_development = UiLabAccess::enabled_for_rest_request( $request );
		$post                = null === $id ? null : self::find_page( $id, $include_development );
		if ( null === $post ) {
			return new \WP_Error( 'onumia_page_not_found', 'The page was not found.', array( 'status' => 404 ) );
		}

		$params = $request->get_params();
		$update = array( 'ID' => $post['ID'] );
		$emoji  = null;

		if ( array_key_exists( 'title', $params ) ) {
			$update['post_title'] = self::title( $params['title'] );
		}
		if ( array_key_exists( 'emoji', $params ) ) {
			$emoji = self::emoji( $params['emoji'] );
			if ( $emoji instanceof \WP_Error ) {
				return $emoji;
			}
		}
		if ( array_key_exists( 'position', $params ) ) {
			$position = self::position( $params['position'] );
			if ( null === $position ) {
				return new \WP_Error( 'onumia_invalid_page_position', 'Page position must be a non-negative integer.', array( 'status' => 400 ) );
			}
			$update['menu_order'] = $position;
		}
		if ( array_key_exists( 'parentId', $params ) ) {
			$parent = self::parent_post( $params['parentId'], $include_development );
			if ( $parent instanceof \WP_Error ) {
				return $parent;
			}

			$parent_post_id = null === $parent ? 0 : $parent['ID'];
			if ( self::would_create_cycle( $post['ID'], $parent_post_id ) ) {
				return new \WP_Error( 'onumia_page_cycle', 'A page cannot be nested inside itself or one of its descendants.', array( 'status' => 400 ) );
			}
			$update['post_parent'] = $parent_post_id;
		}

		$result = $post['ID'];
		if ( count( $update ) > 1 ) {
			$result = \wp_update_post( $update );
			if ( \is_wp_error( $result ) || ! is_int( $result ) || $result <= 0 ) {
				return new \WP_Error( 'onumia_page_update_failed', 'The page could not be updated.', array( 'status' => 500 ) );
			}
		}

		if ( is_string( $emoji ) ) {
			\update_post_meta( $post['ID'], PagePostType::EMOJI_META_KEY, $emoji );
		}

		$updated_post = \get_post( $result );
		if ( ! is_object( $updated_post ) ) {
			return new \WP_Error( 'onumia_page_update_failed', 'The updated page could not be loaded.', array( 'status' => 500 ) );
		}

		return new \WP_REST_Response(
			self::prepare_page_for_response(
				self::page_record( $updated_post ),
				self::page_ids_by_post_id( self::all_pages( $include_development ) )
			),
			200
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_page_document( \WP_REST_Request $request ) {
		$id                  = self::uuid( $request->get_param( 'id' ) );
		$include_development = UiLabAccess::enabled_for_rest_request( $request );
		$post                = null === $id ? null : self::find_page( $id, $include_development );
		if ( null === $post ) {
			return new \WP_Error( 'onumia_page_not_found', 'The page was not found.', array( 'status' => 404 ) );
		}

		if ( ! array_key_exists( 'document', $request->get_params() ) ) {
			return new \WP_Error( 'onumia_page_document_required', 'A block editor document is required.', array( 'status' => 400 ) );
		}

		$document = self::document( $request->get_param( 'document' ) );
		if ( $document instanceof \WP_Error ) {
			return $document;
		}

		\update_post_meta( $post['ID'], PagePostType::DOCUMENT_META_KEY, $document );

		return new \WP_REST_Response( self::prepare_document_for_response( $post, $id ), 200 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete_page( \WP_REST_Request $request ) {
		$id                  = self::uuid( $request->get_param( 'id' ) );
		$include_development = UiLabAccess::enabled_for_rest_request( $request );
		$post                = null === $id ? null : self::find_page( $id, $include_development );
		if ( null === $post ) {
			return new \WP_Error( 'onumia_page_not_found', 'The page was not found.', array( 'status' => 404 ) );
		}

		$pages            = self::all_pages( $include_development );
		$page_ids         = self::page_ids_by_post_id( $pages );
		$subtree_post_ids = self::subtree_post_ids( $post['ID'], $pages );

		foreach ( array_reverse( $subtree_post_ids ) as $post_id ) {
			if ( ! is_object( \wp_delete_post( $post_id, true ) ) ) {
				return new \WP_Error( 'onumia_page_delete_failed', 'The page could not be deleted.', array( 'status' => 500 ) );
			}
		}

		return new \WP_REST_Response(
			array(
				'deletedIds' => array_values(
					array_map(
						static fn( int $post_id ): string => $page_ids[ $post_id ],
						$subtree_post_ids
					)
				),
			),
			200
		);
	}

	/**
	 * @return list<PageRecord>
	 */
	private static function all_pages( bool $include_development = false ): array {
		$posts = \get_posts(
			array(
				'post_type'      => PagePostType::POST_TYPE,
				'post_status'    => $include_development ? array( 'publish', 'private' ) : 'publish',
				'posts_per_page' => -1,
				'orderby'        => array(
					'post_parent' => 'ASC',
					'menu_order'  => 'ASC',
					'ID'          => 'ASC',
				),
			)
		);

		$pages = array();
		foreach ( $posts as $post ) {
			if ( is_object( $post ) ) {
				$pages[] = self::page_record( $post );
			}
		}
		\usort(
			$pages,
			static fn( array $left, array $right ): int => array(
				$left['post_parent'],
				$left['menu_order'],
				$left['ID'],
			) <=> array(
				$right['post_parent'],
				$right['menu_order'],
				$right['ID'],
			)
		);

		return $pages;
	}

	/**
	 * @return PageRecord|null
	 */
	private static function find_page( string $id, bool $include_development = false ): ?array {
		$posts = \get_posts(
			array(
				'post_type'      => PagePostType::POST_TYPE,
				'post_status'    => $include_development ? array( 'publish', 'private' ) : 'publish',
				'post_name__in'  => array( $id ),
				'posts_per_page' => 1,
			)
		);

		foreach ( $posts as $post ) {
			if ( is_object( $post ) ) {
				$page = self::page_record( $post );
				if ( strtolower( $page['post_name'] ) === $id ) {
					return $page;
				}
			}
		}

		return null;
	}

	/**
	 * @return PageRecord|\WP_Error|null
	 */
	private static function parent_post( mixed $value, bool $include_development = false ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$id = self::uuid( $value );
		if ( null === $id ) {
			return new \WP_Error( 'onumia_invalid_parent_page', 'Parent page ID must be a valid UUID or null.', array( 'status' => 400 ) );
		}

		$parent = self::find_page( $id, $include_development );
		return $parent ?? new \WP_Error( 'onumia_parent_page_not_found', 'The parent page was not found.', array( 'status' => 400 ) );
	}

	private static function would_create_cycle( int $post_id, int $parent_post_id ): bool {
		$visited = array();
		while ( $parent_post_id > 0 && ! isset( $visited[ $parent_post_id ] ) ) {
			if ( $parent_post_id === $post_id ) {
				return true;
			}

			$visited[ $parent_post_id ] = true;
			$parent                     = \get_post( $parent_post_id );
			if ( ! is_object( $parent ) ) {
				break;
			}
			$parent_post_id = self::page_record( $parent )['post_parent'];
		}

		return false;
	}

	/**
	 * @param  list<PageRecord> $pages Pages.
	 * @return list<int>
	 */
	private static function subtree_post_ids( int $root_post_id, array $pages ): array {
		$children_by_parent_id = array();
		foreach ( $pages as $page ) {
			$children_by_parent_id[ $page['post_parent'] ][] = $page['ID'];
		}

		$post_ids = array();
		$stack    = array( $root_post_id );
		$visited  = array();
		while ( array() !== $stack ) {
			$post_id = array_pop( $stack );
			if ( ! is_int( $post_id ) || isset( $visited[ $post_id ] ) ) {
				continue;
			}

			$visited[ $post_id ] = true;
			$post_ids[]          = $post_id;
			$children            = $children_by_parent_id[ $post_id ] ?? array();
			foreach ( array_reverse( $children ) as $child_post_id ) {
				$stack[] = $child_post_id;
			}
		}

		return $post_ids;
	}

	private static function next_position( int $parent_post_id, bool $include_development = false ): int {
		$position = -1;
		foreach ( self::all_pages( $include_development ) as $page ) {
			if ( $page['post_parent'] === $parent_post_id ) {
				$position = max( $position, $page['menu_order'] );
			}
		}

		return $position + 1;
	}

	/**
	 * @param  list<PageRecord> $pages Pages.
	 * @return list<array{id:string,parentId:string|null,title:string,emoji:string,position:int}>
	 */
	private static function prepare_pages_for_response( array $pages ): array {
		$ids = self::page_ids_by_post_id( $pages );

		return array_map(
			static fn( array $page ): array => self::prepare_page_for_response( $page, $ids ),
			$pages
		);
	}

	/**
	 * @param  PageRecord        $page Page.
	 * @param  array<int,string> $ids  Page UUIDs by WordPress post ID.
	 * @return array{id:string,parentId:string|null,title:string,emoji:string,position:int}
	 */
	private static function prepare_page_for_response( array $page, array $ids ): array {
		$post_id   = $page['ID'];
		$parent_id = $page['post_parent'];

		return array(
			'id'       => $ids[ $post_id ] ?? self::ensure_page_id( $page ),
			'parentId' => $parent_id > 0 ? ( $ids[ $parent_id ] ?? null ) : null,
			'title'    => self::title( $page['post_title'] ),
			'emoji'    => self::stored_emoji( $post_id ),
			'position' => max( 0, $page['menu_order'] ),
		);
	}

	/**
	 * @param  PageRecord $page Page.
	 * @return array{id:string,document:BlockEditorDocument}
	 */
	private static function prepare_document_for_response( array $page, string $id ): array {
		return array(
			'id'       => $id,
			'document' => self::stored_document( $page['ID'] ),
		);
	}

	/**
	 * @param  list<PageRecord>|null $pages Pages.
	 * @return array<int,string>
	 */
	private static function page_ids_by_post_id( ?array $pages = null ): array {
		$ids = array();
		foreach ( $pages ?? self::all_pages( false ) as $page ) {
			$post_id = $page['ID'];
			if ( $post_id > 0 ) {
				$ids[ $post_id ] = self::ensure_page_id( $page );
			}
		}

		return $ids;
	}

	/**
	 * @param PageRecord $page Page.
	 */
	private static function ensure_page_id( array $page ): string {
		$id = self::uuid( $page['post_name'] );
		if ( null !== $id ) {
			return $id;
		}

		$id      = strtolower( \wp_generate_uuid4() );
		$post_id = $page['ID'];
		if ( $post_id > 0 ) {
			\wp_update_post(
				array(
					'ID'        => $post_id,
					'post_name' => $id,
				)
			);
		}

		return $id;
	}

	/**
	 * @return PageRecord
	 */
	private static function page_record( object $page ): array {
		$values   = get_object_vars( $page );
		$id       = $values['ID'] ?? null;
		$parent   = $values['post_parent'] ?? null;
		$position = $values['menu_order'] ?? null;
		$name     = $values['post_name'] ?? null;
		$title    = $values['post_title'] ?? null;

		return array(
			'ID'          => is_int( $id ) ? $id : 0,
			'post_parent' => is_int( $parent ) ? $parent : 0,
			'menu_order'  => is_int( $position ) ? $position : 0,
			'post_name'   => is_string( $name ) ? $name : '',
			'post_title'  => is_string( $title ) ? $title : '',
		);
	}

	private static function uuid( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = strtolower( trim( $value ) );
		return 1 === preg_match( '/^' . self::UUID_PATTERN . '$/', $value ) ? $value : null;
	}

	private static function title( mixed $value ): string {
		$title = is_string( $value ) ? trim( \sanitize_text_field( $value ) ) : '';

		return '' === $title ? 'Untitled' : $title;
	}

	/**
	 * @return BlockEditorDocument|\WP_Error
	 */
	private static function document( mixed $value, bool $use_default = false ) {
		if ( $use_default ) {
			return self::default_document();
		}

		if ( ! is_array( $value ) || array_is_list( $value ) || 'doc' !== ( $value['type'] ?? null ) ) {
			return new \WP_Error( 'onumia_invalid_page_document', 'The page document must be a valid block editor document.', array( 'status' => 400 ) );
		}

		$document = array();
		foreach ( $value as $key => $child ) {
			if ( ! is_string( $key ) ) {
				return new \WP_Error( 'onumia_invalid_page_document', 'The page document must use valid JSON object keys.', array( 'status' => 400 ) );
			}
			$document[ $key ] = $child;
		}

		$content = $document['content'] ?? array();
		if ( ! is_array( $content ) || ! array_is_list( $content ) || ! self::is_json_value( $document ) ) {
			return new \WP_Error( 'onumia_invalid_page_document', 'The page document must be valid JSON data.', array( 'status' => 400 ) );
		}

		$encoded = json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_DOCUMENT_BYTES ) {
			return new \WP_Error( 'onumia_page_document_too_large', 'The page document exceeds the maximum allowed size.', array( 'status' => 413 ) );
		}

		return $document;
	}

	private static function is_json_value( mixed $value, int $depth = 0 ): bool {
		if ( $depth > self::MAX_DOCUMENT_DEPTH ) {
			return false;
		}

		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_string( $value ) ) {
			return ! is_float( $value ) || is_finite( $value );
		}

		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( $value as $child ) {
			if ( ! self::is_json_value( $child, $depth + 1 ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return BlockEditorDocument
	 */
	private static function stored_document( int $post_id ): array {
		$stored = \get_post_meta( $post_id, PagePostType::DOCUMENT_META_KEY, true );
		if ( is_string( $stored ) && '' !== $stored ) {
			$decoded = json_decode( $stored, true );
			$stored  = is_array( $decoded ) ? $decoded : null;
		}

		$document = self::document( $stored );
		return $document instanceof \WP_Error ? self::default_document() : $document;
	}

	/**
	 * @return BlockEditorDocument
	 */
	private static function default_document(): array {
		return array(
			'type'    => 'doc',
			'content' => array(
				array( 'type' => 'paragraph' ),
			),
		);
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function emoji( mixed $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( ! is_string( $value ) ) {
			return new \WP_Error( 'onumia_invalid_page_emoji', 'The page emoji must be a string.', array( 'status' => 400 ) );
		}

		$emoji = trim( \sanitize_text_field( $value ) );
		if ( strlen( $emoji ) > 128 ) {
			return new \WP_Error( 'onumia_invalid_page_emoji', 'The page emoji is too long.', array( 'status' => 400 ) );
		}

		return $emoji;
	}

	private static function stored_emoji( int $post_id ): string {
		$emoji = self::emoji( \get_post_meta( $post_id, PagePostType::EMOJI_META_KEY, true ) );

		return is_string( $emoji ) ? $emoji : '';
	}

	private static function position( mixed $value ): ?int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return null;
		}

		$position = (int) $value;
		return $position >= 0 ? $position : null;
	}
}
