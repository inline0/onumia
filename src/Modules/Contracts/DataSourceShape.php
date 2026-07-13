<?php

/**
 * Module data source response shape.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Contracts;

/**
 * Defines response shapes for module data sources.
 *
 * Use this enum in `DataSource` declarations so Onumia can validate and route
 * read-only responses to the right renderer. Shapes describe whether a method
 * returns options, records, collections, metrics, or custom payloads.
 *
 * The data source method must return data compatible with the declared shape.
 * Shape metadata is parsed before runtime dispatch.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 320
 */
enum DataSourceShape: string {
	case Options    = 'options';
	case Collection = 'collection';
	case Record     = 'record';
	case Metrics    = 'metrics';
	case Custom     = 'custom';
}
