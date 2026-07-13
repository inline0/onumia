<?php

/**
 * Entry collection storage presets.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Contracts;

/**
 * Defines storage modes for entry collections.
 *
 * Use this enum in `Entries` declarations to tell Onumia whether an entry
 * collection is backed by module settings, manual callbacks, or a module-owned
 * table. The storage mode controls which CRUD paths are valid.
 *
 * The selected mode must match the rest of the entry contract. For table-backed
 * entries, a matching `Table` declaration must exist.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 330
 */
enum EntryStorage: string {
	case Settings = 'settings';
	case Manual   = 'manual';
	case Table    = 'table';
}
