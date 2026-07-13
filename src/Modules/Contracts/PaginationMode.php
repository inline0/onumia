<?php

/**
 * Module data source pagination mode.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Contracts;

/**
 * Defines pagination behavior for module data sources.
 *
 * Use this enum in `DataSource` declarations to indicate whether Onumia should
 * page data on the client from a full list or ask the backend for each page.
 * The mode must match the declared data source response shape.
 *
 * Client pagination expects a plain list. Server pagination expects the
 * structured paginated response enforced by the parser.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 360
 */
enum PaginationMode: string {
	case Client = 'client';
	case Server = 'server';
}
