<?php

/**
 * Module action intent contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Contracts;

/**
 * Defines semantic intents for module actions.
 *
 * Use this enum when declaring `Action` metadata so Onumia can describe and
 * style actions consistently across generated UI, REST dispatch, and future
 * automation. The intent does not execute behavior by itself.
 *
 * Action intent values are parsed from PHP attributes before module instances
 * are created. Choose the closest built-in intent and use `Custom` for
 * module-specific commands.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 300
 */
enum ActionIntent: string {
	case Create  = 'create';
	case Update  = 'update';
	case Delete  = 'delete';
	case Archive = 'archive';
	case Publish = 'publish';
	case Sync    = 'sync';
	case Issue   = 'issue';
	case Revoke  = 'revoke';
	case Read    = 'read';
	case Custom  = 'custom';
}
