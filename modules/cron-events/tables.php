<?php
/**
 * Cron Events table contracts.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\CronEvents;

use Onumia\Modules\Attributes\Column;
use Onumia\Modules\Attributes\Index;
use Onumia\Modules\Attributes\Table;
use Onumia\Modules\Contracts\ColumnType;

#[Table( 'runs', version: 1, label: 'Runs', row_cap: 25000, retention_days: 14 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'started_at', ColumnType::BigInt, unsigned: true, label: 'Started at', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'finished_at', ColumnType::BigInt, unsigned: true, nullable: true, label: 'Finished at', table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'hook', ColumnType::String, length: 128, label: 'Hook', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'duration_ms', ColumnType::Integer, unsigned: true, nullable: true, label: 'Duration', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'status', ColumnType::String, length: 16, label: 'Status', allowed: array( 'ok', 'timeout', 'error' ), table_list: true, table_filter: true, filter_type: 'option' )]
#[Column( 'error', ColumnType::String, length: 512, nullable: true, label: 'Error', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Index( 'started_at', array( 'started_at' ) )]
#[Index( 'hook', array( 'hook' ) )]
#[Index( 'status', array( 'status' ) )]
final class RunsTable {}
