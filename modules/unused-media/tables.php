<?php
/**
 * Unused Media table contracts.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\UnusedMedia;

use Onumia\Modules\Attributes\Column;
use Onumia\Modules\Attributes\Index;
use Onumia\Modules\Attributes\Table;
use Onumia\Modules\Contracts\ColumnType;

#[Table( 'scan_results', version: 1, label: 'Scan results', row_cap: 10000 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'scanned_at', ColumnType::BigInt, unsigned: true, label: 'Scanned at', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'attachment_id', ColumnType::BigInt, unsigned: true, label: 'Attachment ID', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'filename', ColumnType::String, length: 255, label: 'Filename', table_list: true, table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'mime_type', ColumnType::String, length: 64, label: 'MIME type', table_list: true, table_filter: true, filter_type: 'option' )]
#[Column( 'size_bytes', ColumnType::BigInt, unsigned: true, label: 'Size', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'uploaded_at', ColumnType::BigInt, unsigned: true, label: 'Uploaded', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Index( 'scanned_at', array( 'scanned_at' ) )]
#[Index( 'attachment_id', array( 'attachment_id' ) )]
#[Index( 'uploaded_at', array( 'uploaded_at' ) )]
final class ScanResultsTable {}

#[Table( 'pending_deletions', version: 1, label: 'Pending deletions', row_cap: 10000 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'queued_at', ColumnType::BigInt, unsigned: true, label: 'Queued at', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'deletes_at', ColumnType::BigInt, unsigned: true, label: 'Deletes at', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'attachment_id', ColumnType::BigInt, unsigned: true, label: 'Attachment ID', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'queued_by_user_id', ColumnType::BigInt, unsigned: true, label: 'Queued by', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Index( 'deletes_at', array( 'deletes_at' ) )]
#[Index( 'attachment_id', array( 'attachment_id' ) )]
final class PendingDeletionsTable {}
