<?php
/**
 * Activity Log table contracts.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\ActivityLog;

use Onumia\Modules\Attributes\Column;
use Onumia\Modules\Attributes\Index;
use Onumia\Modules\Attributes\Table;
use Onumia\Modules\Contracts\ColumnType;

#[Table( 'events', version: 1, label: 'Events', row_cap: 100000, retention_days: 90 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'occurred_at', ColumnType::BigInt, unsigned: true, label: 'Occurred at', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'group', ColumnType::String, length: 16, label: 'Group', allowed: array( 'auth', 'posts', 'users', 'plugins', 'themes', 'options' ), table_list: true, table_filter: true, filter_type: 'option', searchable: true )]
#[Column( 'event', ColumnType::String, length: 64, label: 'Event', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'actor_type', ColumnType::String, length: 16, label: 'Actor type', allowed: array( 'user', 'system' ), table_filter: true, filter_type: 'option' )]
#[Column( 'actor_id', ColumnType::BigInt, unsigned: true, nullable: true, label: 'Actor ID', table_filter: true, filter_type: 'number' )]
#[Column( 'actor_login', ColumnType::String, length: 60, nullable: true, label: 'Actor', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'target_type', ColumnType::String, length: 32, nullable: true, label: 'Target type', table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'target_id', ColumnType::String, length: 64, nullable: true, label: 'Target ID', table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'summary', ColumnType::String, length: 255, label: 'Summary', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'payload', ColumnType::LongText, nullable: true, label: 'Payload', searchable: true )]
#[Index( 'occurred_at', array( 'occurred_at' ) )]
#[Index( 'group', array( 'group' ) )]
#[Index( 'event', array( 'event' ) )]
#[Index( 'actor_id', array( 'actor_id' ) )]
final class EventsTable {}
