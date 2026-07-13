<?php
/**
 * Image Sizes table contracts.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\ImageSizes;

use Onumia\Modules\Attributes\Column;
use Onumia\Modules\Attributes\Index;
use Onumia\Modules\Attributes\Table;
use Onumia\Modules\Contracts\ColumnType;

#[Table( 'regen_log', version: 1, label: 'Regeneration log', row_cap: 50000, retention_days: 30 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'started_at', ColumnType::BigInt, unsigned: true, label: 'Started at', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'finished_at', ColumnType::BigInt, unsigned: true, nullable: true, label: 'Finished at', table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'attachment_id', ColumnType::BigInt, unsigned: true, label: 'Attachment ID', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'size_name', ColumnType::String, length: 60, label: 'Size', table_list: true, table_filter: true, filter_type: 'option', searchable: true )]
#[Column( 'status', ColumnType::String, length: 16, label: 'Status', table_list: true, table_filter: true, filter_type: 'option' )]
#[Column( 'error', ColumnType::String, length: 255, nullable: true, label: 'Error', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Index( 'started_at', array( 'started_at' ) )]
#[Index( 'attachment_id', array( 'attachment_id' ) )]
final class RegenLogTable {}
