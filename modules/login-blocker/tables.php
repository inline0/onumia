<?php
/**
 * Login Blocker table contracts.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\LoginBlocker;

use Onumia\Modules\Attributes\Column;
use Onumia\Modules\Attributes\Index;
use Onumia\Modules\Attributes\Table;
use Onumia\Modules\Contracts\ColumnType;

#[Table( 'login_attempts', version: 1, label: 'Login attempts', row_cap: 50000, retention_days: 30 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'attempted_at', ColumnType::BigInt, unsigned: true, label: 'Attempted at', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'username', ColumnType::String, length: 60, label: 'Username', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'ip_hash', ColumnType::String, length: 64, label: 'IP hash', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'user_agent', ColumnType::String, length: 255, nullable: true, label: 'User agent', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'result', ColumnType::String, length: 32, label: 'Result', allowed: array( 'allowed', 'wrong_password', 'blocked_by_rule', 'locked_out', 'throttled' ), table_list: true, table_filter: true, filter_type: 'option' )]
#[Column( 'rule_id', ColumnType::String, length: 64, nullable: true, label: 'Rule ID', table_filter: true, filter_type: 'text' )]
#[Index( 'attempted_at', array( 'attempted_at' ) )]
#[Index( 'username', array( 'username' ) )]
#[Index( 'ip_hash', array( 'ip_hash' ) )]
#[Index( 'result', array( 'result' ) )]
final class LoginAttemptsTable {}

#[Table( 'lockouts', version: 1, label: 'Lockouts', row_cap: 5000 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'target_type', ColumnType::String, length: 16, label: 'Target type', allowed: array( 'ip', 'username' ), table_list: true, table_filter: true, filter_type: 'option' )]
#[Column( 'target_value', ColumnType::String, length: 255, label: 'Target', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'locked_at', ColumnType::BigInt, unsigned: true, label: 'Locked at', table_list: true, sortable: true )]
#[Column( 'expires_at', ColumnType::BigInt, unsigned: true, label: 'Expires at', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'attempt_count', ColumnType::Integer, unsigned: true, default: 1, label: 'Attempts', table_list: true, sortable: true )]
#[Column( 'last_username', ColumnType::String, length: 60, nullable: true, label: 'Last username', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'rule_id', ColumnType::String, length: 64, nullable: true, label: 'Rule ID', table_filter: true, filter_type: 'text' )]
#[Index( 'target', array( 'target_type', 'target_value' ) )]
#[Index( 'expires_at', array( 'expires_at' ) )]
final class LockoutsTable {}
