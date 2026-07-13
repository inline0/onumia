<?php

/**
 * Supported module setting types.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Contracts;

/**
 * Defines supported module setting and input types.
 *
 * Use this enum in `Setting`, `Input`, and `EntryField` declarations to keep
 * saved configuration, action parameters, and entry fields typed consistently.
 * The selected type controls parser validation and runtime normalization.
 *
 * Object values require an accompanying `ObjectShape` when used as callable
 * inputs. Array and object settings should remain JSON-serializable.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 380
 */
enum SettingType: string {
	case Boolean = 'boolean';
	case String  = 'string';
	case Integer = 'integer';
	case Number  = 'number';
	case Array   = 'array';
	case Object  = 'object';
}
