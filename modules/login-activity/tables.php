<?php
/**
 * Login Activity table contracts.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\LoginActivity;

use Onumia\Modules\Attributes\Column;
use Onumia\Modules\Attributes\Index;
use Onumia\Modules\Attributes\Table;
use Onumia\Modules\Contracts\ColumnType;

#[Table( 'logins', version: 1, label: 'Logins', row_cap: 100000, retention_days: 90 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'occurred_at', ColumnType::BigInt, unsigned: true, label: 'Occurred at', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'user_id', ColumnType::BigInt, unsigned: true, nullable: true, label: 'User ID', table_filter: true, filter_type: 'number' )]
#[Column( 'user_login', ColumnType::String, length: 60, label: 'User', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'result', ColumnType::String, length: 16, label: 'Result', allowed: array( 'success', 'failed' ), table_list: true, table_filter: true, filter_type: 'option' )]
#[Column( 'ip_hash', ColumnType::String, length: 64, label: 'IP hash', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'user_agent', ColumnType::String, length: 255, nullable: true, label: 'User agent', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'referrer', ColumnType::String, length: 512, nullable: true, label: 'Referrer', table_filter: true, filter_type: 'text', searchable: true )]
#[Index( 'occurred_at', array( 'occurred_at' ) )]
#[Index( 'user_id', array( 'user_id' ) )]
#[Index( 'user_login', array( 'user_login' ) )]
#[Index( 'result', array( 'result' ) )]
#[Index( 'ip_hash', array( 'ip_hash' ) )]
final class LoginsTable {}
