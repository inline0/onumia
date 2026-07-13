<?php
/**
 * Redirects table contracts.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Redirects;

use Onumia\Modules\Attributes\Column;
use Onumia\Modules\Attributes\Index;
use Onumia\Modules\Attributes\Table;
use Onumia\Modules\Contracts\ColumnType;

#[Table( 'hits', version: 1, label: 'Redirect hits', row_cap: 5000 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'rule_id', ColumnType::String, length: 64, label: 'Rule ID', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'count', ColumnType::Integer, unsigned: true, default: 0, label: 'Hits', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'last_hit_at', ColumnType::BigInt, unsigned: true, nullable: true, label: 'Last hit', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Index( 'rule_id', array( 'rule_id' ), unique: true )]
#[Index( 'last_hit_at', array( 'last_hit_at' ) )]
final class HitsTable {}
