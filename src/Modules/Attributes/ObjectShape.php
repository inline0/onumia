<?php

/**
 * Module object input shape attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;

/**
 * Declares the field map for an object input.
 *
 * Use this attribute with an `Input` whose type is object so Onumia can parse,
 * validate, and document structured payloads without accepting arbitrary
 * unbounded arrays.
 *
 * The `name` must match the object input name. Field types should use the
 * supported setting/input type names understood by the contract parser.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 200
 */
#[Attribute( Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE )]
final readonly class ObjectShape {
	/**
	 * @param array<string,string> $fields Field type map.
	 */
	public function __construct(
		public string $name,
		public array $fields,
	) {}
}
