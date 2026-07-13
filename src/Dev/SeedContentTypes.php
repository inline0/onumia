<?php

/**
 * Development-only seed content types.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Dev;

final class SeedContentTypes {

	public const OPTION = 'onumia_dev_seed_content_types';

	public static function register(): void {
		// @codeCoverageIgnoreStart
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		// @codeCoverageIgnoreEnd

		\add_action( 'init', array( self::class, 'register_content_types' ) );
	}

	public static function enabled(): bool {
		// @codeCoverageIgnoreStart
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		// @codeCoverageIgnoreEnd

		$value = \get_option( self::OPTION, '' );
		return is_string( $value ) && '1' === $value;
	}

	public static function register_content_types(): void {
		if ( ! self::enabled() ) {
			return;
		}

		self::register_post_types();
		self::register_taxonomies();
	}

	private static function register_post_types(): void {
		// @codeCoverageIgnoreStart
		if ( ! function_exists( 'register_post_type' ) ) {
			return;
		}
		// @codeCoverageIgnoreEnd

		self::register_post_type( 'onumia_campaign', 'Campaigns', 'Campaign', array( 'title', 'editor', 'excerpt', 'thumbnail' ) );
		self::register_post_type( 'onumia_case', 'Case studies', 'Case study', array( 'title', 'editor', 'excerpt', 'thumbnail' ) );
		self::register_post_type( 'onumia_workflow', 'Workflows', 'Workflow', array( 'title', 'editor', 'custom-fields' ) );
		self::register_post_type( 'onumia_resource', 'Resources', 'Resource', array( 'title', 'editor', 'excerpt' ) );
	}

	private static function register_taxonomies(): void {
		// @codeCoverageIgnoreStart
		if ( ! function_exists( 'register_taxonomy' ) ) {
			return;
		}
		// @codeCoverageIgnoreEnd

		$object_types = array( 'onumia_campaign', 'onumia_case', 'onumia_workflow', 'onumia_resource' );

		self::register_taxonomy( 'onumia_sector', $object_types, 'Sectors', 'Sector', true );
		self::register_taxonomy( 'onumia_region', $object_types, 'Regions', 'Region', true );
		self::register_taxonomy( 'onumia_stage', $object_types, 'Stages', 'Stage', false );
	}

	/**
	 * @param lowercase-string&non-empty-string $slug     Slug.
	 * @param list<string>                     $supports Supports.
	 */
	private static function register_post_type( string $slug, string $name, string $singular_name, array $supports ): void {
		\register_post_type(
			$slug,
			array(
				'labels'       => array(
					'name'          => $name,
					'singular_name' => $singular_name,
				),
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => $supports,
			)
		);
	}

	/**
	 * @param lowercase-string&non-empty-string       $slug         Slug.
	 * @param non-empty-list<lowercase-string&non-empty-string> $object_types Object types.
	 */
	private static function register_taxonomy( string $slug, array $object_types, string $name, string $singular_name, bool $hierarchical ): void {
		\register_taxonomy(
			$slug,
			$object_types,
			array(
				'labels'       => array(
					'name'          => $name,
					'singular_name' => $singular_name,
				),
				'public'       => true,
				'show_in_rest' => true,
				'hierarchical' => $hierarchical,
			)
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function post_types(): array {
		return array(
			'onumia_campaign' => array(
				'labels'       => array(
					'name'          => 'Campaigns',
					'singular_name' => 'Campaign',
				),
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
			),
			'onumia_case'     => array(
				'labels'       => array(
					'name'          => 'Case studies',
					'singular_name' => 'Case study',
				),
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
			),
			'onumia_workflow' => array(
				'labels'       => array(
					'name'          => 'Workflows',
					'singular_name' => 'Workflow',
				),
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'custom-fields' ),
			),
			'onumia_resource' => array(
				'labels'       => array(
					'name'          => 'Resources',
					'singular_name' => 'Resource',
				),
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'excerpt' ),
			),
		);
	}

	/**
	 * @return array<string,array{object_type:string[],args:array<string,mixed>}>
	 */
	public static function taxonomies(): array {
		$object_types = array_keys( self::post_types() );

		return array(
			'onumia_sector' => array(
				'object_type' => $object_types,
				'args'        => array(
					'labels'       => array(
						'name'          => 'Sectors',
						'singular_name' => 'Sector',
					),
					'public'       => true,
					'show_in_rest' => true,
					'hierarchical' => true,
				),
			),
			'onumia_region' => array(
				'object_type' => $object_types,
				'args'        => array(
					'labels'       => array(
						'name'          => 'Regions',
						'singular_name' => 'Region',
					),
					'public'       => true,
					'show_in_rest' => true,
					'hierarchical' => true,
				),
			),
			'onumia_stage'  => array(
				'object_type' => $object_types,
				'args'        => array(
					'labels'       => array(
						'name'          => 'Stages',
						'singular_name' => 'Stage',
					),
					'public'       => true,
					'show_in_rest' => true,
					'hierarchical' => false,
				),
			),
		);
	}
}
