<?php
/**
 * Database Optimizer table contracts.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\DatabaseOptimizer;

use Onumia\Modules\Attributes\Column;
use Onumia\Modules\Attributes\Index;
use Onumia\Modules\Attributes\Table;
use Onumia\Modules\Contracts\ColumnType;

#[Table( 'runs', version: 1, label: 'Runs', row_cap: 25000, retention_days: 90 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'run_id', ColumnType::String, length: 40, label: 'Run ID', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'started_at', ColumnType::BigInt, unsigned: true, label: 'Started at', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'finished_at', ColumnType::BigInt, unsigned: true, nullable: true, label: 'Finished at', sortable: true )]
#[Column( 'task', ColumnType::String, length: 32, label: 'Task', table_list: true, table_filter: true, filter_type: 'option', searchable: true )]
#[Column( 'items_processed', ColumnType::Integer, unsigned: true, default: 0, label: 'Processed', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'items_removed', ColumnType::Integer, unsigned: true, default: 0, label: 'Removed', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'duration_ms', ColumnType::Integer, unsigned: true, default: 0, label: 'Duration ms', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'status', ColumnType::String, length: 16, label: 'Status', allowed: array( 'ok', 'failed' ), table_list: true, table_filter: true, filter_type: 'option' )]
#[Column( 'error', ColumnType::String, length: 512, nullable: true, label: 'Error', searchable: true )]
#[Index( 'started_at', array( 'started_at' ) )]
#[Index( 'run_id', array( 'run_id' ) )]
#[Index( 'status', array( 'status' ) )]
final class RunsTable {}
