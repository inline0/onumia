<?php
/**
 * Email Log table contracts.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\EmailLog;

use Onumia\Modules\Attributes\Column;
use Onumia\Modules\Attributes\Index;
use Onumia\Modules\Attributes\Table;
use Onumia\Modules\Contracts\ColumnType;

#[Table( 'emails', version: 1, label: 'Emails', row_cap: 25000, retention_days: 30 )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID' )]
#[Column( 'occurred_at', ColumnType::BigInt, unsigned: true, label: 'Occurred at' )]
#[Column( 'sent_at', ColumnType::BigInt, unsigned: true, label: 'Sent at', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'status', ColumnType::String, length: 16, label: 'Status', allowed: array( 'sent', 'failed' ), table_list: true, table_filter: true, filter_type: 'option' )]
#[Column( 'to_recipients', ColumnType::Json, label: 'To recipients', table_filter: true, filter_type: 'text', searchable: true )]
#[Column( 'cc_recipients', ColumnType::Json, nullable: true, label: 'CC recipients', searchable: true )]
#[Column( 'bcc_recipients', ColumnType::Json, nullable: true, label: 'BCC recipients', searchable: true )]
#[Column( 'from_address', ColumnType::String, length: 255, nullable: true, label: 'From', searchable: true )]
#[Column( 'reply_to', ColumnType::String, length: 255, nullable: true, label: 'Reply-To', searchable: true )]
#[Column( 'subject', ColumnType::String, length: 255, label: 'Subject', table_list: true, table_filter: true, filter_type: 'text', searchable: true, sortable: true )]
#[Column( 'body_text', ColumnType::LongText, nullable: true, label: 'Text body', searchable: true )]
#[Column( 'body_html', ColumnType::LongText, nullable: true, label: 'HTML body', searchable: true )]
#[Column( 'headers', ColumnType::Json, label: 'Headers', searchable: true )]
#[Column( 'attachments', ColumnType::Json, nullable: true, label: 'Attachments', searchable: true )]
#[Column( 'attachments_count', ColumnType::Integer, unsigned: true, default: 0, label: 'Attachments', table_list: true, table_filter: true, filter_type: 'number', sortable: true )]
#[Column( 'error', ColumnType::String, length: 512, nullable: true, label: 'Error', searchable: true )]
#[Column( 'fingerprint', ColumnType::String, length: 40, label: 'Fingerprint', table_filter: true, filter_type: 'text', searchable: true )]
#[Index( 'occurred_at', array( 'occurred_at' ) )]
#[Index( 'sent_at', array( 'sent_at' ) )]
#[Index( 'status', array( 'status' ) )]
#[Index( 'subject', array( 'subject' ) )]
#[Index( 'fingerprint', array( 'fingerprint' ) )]
final class EmailsTable {}
