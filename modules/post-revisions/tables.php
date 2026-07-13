<?php
/**
 * Post Revisions table contracts.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\PostRevisions;

use Onumia\Modules\Attributes\Column;
use Onumia\Modules\Attributes\Index;
use Onumia\Modules\Attributes\Table;
use Onumia\Modules\Contracts\ColumnType;

#[Table( 'prune_log', version: 1, label: 'Prune log', row_cap: 10000, retention_days: 90 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'pruned_at', ColumnType::BigInt, unsigned: true, label: 'Pruned at', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'post_type', ColumnType::String, length: 60, label: 'Post type', table_list: true, table_filter: true, filter_type: 'option', searchable: true )]
#[Column( 'post_id', ColumnType::BigInt, unsigned: true, label: 'Post ID', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'reason', ColumnType::String, length: 32, label: 'Reason', allowed: array( 'cap_exceeded', 'age_exceeded', 'both' ), table_list: true, table_filter: true, filter_type: 'option', searchable: true )]
#[Column( 'revisions_removed', ColumnType::Integer, unsigned: true, default: 0, label: 'Removed', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Index( 'pruned_at', array( 'pruned_at' ) )]
#[Index( 'post_type', array( 'post_type' ) )]
#[Index( 'post_id', array( 'post_id' ) )]
final class PruneLogTable {}
