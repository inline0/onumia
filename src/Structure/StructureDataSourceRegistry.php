<?php

/**
 * Known structure data source presets.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Structure;

final class StructureDataSourceRegistry {
	private const DEFAULT_SOURCES = array(
		'wp.user.roles',
		'wp.user.capabilities',
		'wp.users',
		'wp.posts',
		'wp.post.types',
		'wp.post.types.public',
		'wp.post.statuses',
		'wp.terms',
		'wp.taxonomies',
		'wp.rest.query',
		'wp.image.sizes',
		'wp.mime.types',
		'wp.plugins.installed',
		'wp.editor.blocks',
		'wp.admin.listTables',
		'wp.admin.menuItems',
		'wp.timezones',
		'wp.rewrite.structures',
		'onumia.modules',
		'onumia.module.categories',
	);

	/** @var array<string,true> */
	private array $sources;

	/**
	 * @param string[]|null $sources Sources.
	 */
	public function __construct( ?array $sources = null ) {
		$this->sources = array_fill_keys(
			$sources ?? self::DEFAULT_SOURCES,
			true
		);
	}

	public function has( string $source ): bool {
		return isset( $this->sources[ $source ] );
	}

	/**
	 * @return string[]
	 */
	public function all(): array {
		return array_keys( $this->sources );
	}
}
