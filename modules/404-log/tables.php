<?php
/**
 * 404 Log table contracts.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\FourOhFourLog;

use Onumia\Modules\Attributes\Column;
use Onumia\Modules\Attributes\Index;
use Onumia\Modules\Attributes\Table;
use Onumia\Modules\Contracts\ColumnType;

#[Table( 'hits', version: 1, label: 'Broken links', row_cap: 10000, retention_days: 30 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'url_fingerprint', ColumnType::String, length: 40, label: 'URL fingerprint', table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'url', ColumnType::String, length: 1024, label: 'URL', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'count', ColumnType::Integer, unsigned: true, default: 1, label: 'Count', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'occurred_at', ColumnType::BigInt, unsigned: true, label: 'Occurred at' )]
#[Column( 'first_seen_at', ColumnType::BigInt, unsigned: true, label: 'First seen', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'last_seen_at', ColumnType::BigInt, unsigned: true, label: 'Last seen', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'last_referrer', ColumnType::String, length: 1024, nullable: true, label: 'Last referrer', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'last_user_agent', ColumnType::String, length: 255, nullable: true, label: 'Last user agent', searchable: true )]
#[Index( 'url_fingerprint', array( 'url_fingerprint' ), unique: true )]
#[Index( 'occurred_at', array( 'occurred_at' ) )]
#[Index( 'first_seen_at', array( 'first_seen_at' ) )]
#[Index( 'last_seen_at', array( 'last_seen_at' ) )]
final class HitsTable {}
