<?php
/**
 * UI Lab table contracts.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Development\UiLab;

use Onumia\Modules\Attributes\Column;
use Onumia\Modules\Attributes\Table;
use Onumia\Modules\Contracts\ColumnType;

#[Table( 'entry_contacts', version: 1, label: 'Entry contacts' )]
#[Column( 'id', ColumnType::BigInt, primary: true, auto_increment: true, unsigned: true, label: 'ID', entry_list: true )]
#[Column( 'name', ColumnType::String, length: 191, label: 'Name', table_list: true, table_filter: true, searchable: true, entry_section: 'identity', entry_order: 10 )]
#[Column(
	name: 'status',
	type: ColumnType::String,
	length: 32,
	default: 'active',
	label: 'Status',
	allowed: array( 'active', 'review', 'paused' ),
	table_list: true,
	table_filter: true,
	filter_type: 'option',
	props: array(
		'options' => array(
			array(
				'value' => 'active',
				'label' => 'Active',
			),
			array(
				'value' => 'review',
				'label' => 'In review',
			),
			array(
				'value' => 'paused',
				'label' => 'Paused',
			),
		),
	),
	entry_section: 'identity',
	entry_order: 20
)]
#[Column(
	name: 'owner',
	type: ColumnType::String,
	length: 96,
	default: 'Platform',
	label: 'Owner',
	table_list: true,
	table_filter: true,
	filter_type: 'option',
	props: array(
		'optionsSource' => array(
			'source' => 'module.tableOwners',
		),
		'search'        => true,
	),
	entry_section: 'identity',
	entry_order: 30
)]
#[Column(
	name: 'score',
	type: ColumnType::Integer,
	unsigned: true,
	default: 50,
	label: 'Score',
	table_list: true,
	table_filter: true,
	filter_type: 'number',
	props: array(
		'min' => 0,
		'max' => 100,
	),
	entry_section: 'metrics',
	entry_order: 40
)]
#[Column( 'public', ColumnType::Boolean, default: false, label: 'Public', table_list: true, table_filter: true, entry_section: 'metrics', entry_order: 50 )]
#[Column( 'notes', ColumnType::Text, nullable: true, label: 'Notes', props: array( 'multiline' => true ), entry_section: 'details', entry_order: 60 )]
#[Column( 'created_at', ColumnType::DateTime, label: 'Created at', table_list: true, entry_create: false, entry_update: false )]
final class EntryContactsTable {}
