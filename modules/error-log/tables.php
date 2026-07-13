<?php
/**
 * Error Log table contracts.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\ErrorLog;

use Onumia\Modules\Attributes\Column;
use Onumia\Modules\Attributes\Index;
use Onumia\Modules\Attributes\Table;
use Onumia\Modules\Contracts\ColumnType;

#[Table( 'errors', version: 1, label: 'Errors', row_cap: 10000, retention_days: 30 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'first_seen_at', ColumnType::BigInt, unsigned: true, label: 'First seen', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'last_seen_at', ColumnType::BigInt, unsigned: true, label: 'Last seen', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'count', ColumnType::Integer, unsigned: true, default: 1, label: 'Count', table_list: true, sortable: true )]
#[Column( 'fingerprint', ColumnType::String, length: 40, label: 'Fingerprint', table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'severity', ColumnType::String, length: 16, label: 'Severity', allowed: array( 'notice', 'warning', 'error', 'fatal', 'deprecated' ), table_list: true, table_filter: true, filter_type: 'option' )]
#[Column( 'message', ColumnType::Text, label: 'Message', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'file', ColumnType::String, length: 255, nullable: true, label: 'File', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'line', ColumnType::Integer, unsigned: true, nullable: true, label: 'Line', table_list: true, sortable: true )]
#[Column( 'stack', ColumnType::LongText, nullable: true, label: 'Stack trace', searchable: true )]
#[Column( 'request_url', ColumnType::String, length: 512, nullable: true, label: 'Request URL', searchable: true )]
#[Column( 'user_id', ColumnType::BigInt, unsigned: true, nullable: true, label: 'User ID', table_filter: true, filter_type: 'number' )]
#[Index( 'last_seen_at', array( 'last_seen_at' ) )]
#[Index( 'first_seen_at', array( 'first_seen_at' ) )]
#[Index( 'fingerprint', array( 'fingerprint' ) )]
#[Index( 'severity', array( 'severity' ) )]
final class ErrorsTable {}
