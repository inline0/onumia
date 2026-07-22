<?php

/**
 * Registers the hierarchical Onumia page content type.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Pages;

final class PagePostType {

	public const POST_TYPE         = 'onumia_page';
	public const DOCUMENT_META_KEY = '_onumia_page_document';
	public const EMOJI_META_KEY    = '_onumia_page_emoji';

	public static function register(): void {
		\add_action( 'init', array( self::class, 'register_post_type' ) );
	}

	public static function register_post_type(): void {
		\register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'          => 'Onumia Pages',
					'singular_name' => 'Onumia Page',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'hierarchical'        => true,
				'query_var'           => false,
				'rewrite'             => false,
				'map_meta_cap'        => true,
				'capability_type'     => 'page',
				'delete_with_user'    => false,
				'supports'            => array( 'title', 'editor', 'page-attributes', 'revisions' ),
			)
		);
	}
}
