<?php

/**
 * Module table column type.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Contracts;

/**
 * Defines storage types for module-owned table columns.
 *
 * Use this enum in `Column` declarations to describe the database and
 * serialization contract for operational records. The selected type controls
 * installer SQL, normalization, and renderer hints.
 *
 * Column types are storage contracts. Changing a type after data exists should
 * be treated as a table migration.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 310
 */
enum ColumnType: string {
	case BigInt   = 'bigint';
	case Boolean  = 'boolean';
	case DateTime = 'datetime';
	case Decimal  = 'decimal';
	case Integer  = 'integer';
	case Json     = 'json';
	case LongText = 'longtext';
	case String   = 'string';
	case Text     = 'text';
}
