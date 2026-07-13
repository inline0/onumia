<?php

/**
 * Module data source attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;
use Onumia\Modules\Contracts\DataSourceShape;
use Onumia\Modules\Contracts\PaginationMode;
use Onumia\Modules\Contracts\Surface;

/**
 * Declares a callable module data source.
 *
 * Use this attribute on module methods that return options, records,
 * collections, metrics, or custom read models for the Onumia renderer. Data
 * sources are read-oriented and should not perform user-visible mutations.
 *
 * The parser records response shape, pagination mode, surface, and capability
 * before runtime dispatch. Returned data must match the declared shape.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 120
 */
#[Attribute( Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE )]
final readonly class DataSource {
	public function __construct(
		public ?string $name = null,
		public Surface|string $surface = Surface::Backend,
		public ?string $capability = null,
		public DataSourceShape|string $shape = DataSourceShape::Options,
		public PaginationMode|string $pagination = PaginationMode::Client,
	) {}
}
