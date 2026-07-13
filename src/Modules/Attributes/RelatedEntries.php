<?php

/**
 * Module related entry section attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;

/**
 * Declares a related entry collection for an entry detail view.
 *
 * Use this attribute when one entry surface should expose child or associated
 * records, such as license activations for a license or events for a customer.
 * It tells the renderer how to connect local and foreign keys.
 *
 * Related entries depend on another declared `Entries` collection. They do not
 * create storage by themselves.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 220
 */
#[Attribute( Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE )]
final readonly class RelatedEntries {
	public function __construct(
		public string $name,
		public string $entry,
		public string $local_key,
		public string $foreign_key,
		public ?string $label = null,
		public string $mode = 'manage',
		public int $order = 0,
	) {}
}
