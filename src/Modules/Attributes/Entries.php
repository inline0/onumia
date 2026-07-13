<?php

/**
 * Module entry collection attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;
use Onumia\Modules\Contracts\EntryStorage;

/**
 * Declares an entry collection exposed by a module.
 *
 * Use this attribute when a module needs a structured CRUD surface backed by
 * settings, manual callbacks, or a module-owned table. Entries are used by the
 * admin renderer for list, drawer, form, and related-record workflows.
 *
 * The declaration links UI entry behavior to backend storage and actions.
 * Storage keys and action names must match the rest of the module contract.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 130
 */
#[Attribute( Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE )]
final readonly class Entries {
	public function __construct(
		public string $name,
		public string $singular,
		public string $plural,
		public string $key,
		public EntryStorage|string $storage,
		public ?string $setting = null,
		public ?string $source = null,
		public ?string $table = null,
		public ?string $create_action = null,
		public ?string $update_action = null,
		public ?string $delete_action = null,
		public bool $close_on_success = true,
		public string $destructive_mode = 'delete',
	) {}
}
