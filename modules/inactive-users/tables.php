<?php
/**
 * Inactive Users table contracts.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\InactiveUsers;

use Onumia\Modules\Attributes\Column;
use Onumia\Modules\Attributes\Index;
use Onumia\Modules\Attributes\Table;
use Onumia\Modules\Contracts\ColumnType;

#[Table( 'actions', version: 1, label: 'Actions', row_cap: 50000 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'occurred_at', ColumnType::BigInt, unsigned: true, label: 'Occurred at', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'user_id', ColumnType::BigInt, unsigned: true, nullable: true, label: 'User ID', table_filter: true, filter_type: 'number' )]
#[Column( 'user_login', ColumnType::String, length: 60, label: 'User', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'action', ColumnType::String, length: 16, label: 'Action', allowed: array( 'disabled', 'deleted', 're_enabled' ), table_list: true, table_filter: true, filter_type: 'option' )]
#[Column( 'reason', ColumnType::String, length: 255, label: 'Reason', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'previous_role', ColumnType::String, length: 60, nullable: true, label: 'Previous role', table_filter: true, filter_type: 'option' )]
#[Index( 'occurred_at', array( 'occurred_at' ) )]
#[Index( 'user_id', array( 'user_id' ) )]
#[Index( 'user_login', array( 'user_login' ) )]
#[Index( 'action', array( 'action' ) )]
final class ActionsTable {}
