<?php
/**
 * Onumia UI lab module.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Development\UiLab;

use Onumia\Modules\Attributes\Action;
use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Entries;
use Onumia\Modules\Attributes\EntryField;
use Onumia\Modules\Attributes\EntrySection;
use Onumia\Modules\Attributes\Input;
use Onumia\Modules\Attributes\ModuleContract;
use Onumia\Modules\Attributes\ObjectShape;
use Onumia\Modules\Attributes\RelatedEntries;
use Onumia\Modules\Attributes\Setting;
use Onumia\Modules\Contracts\DataSourceShape;
use Onumia\Modules\Contracts\EntryStorage;
use Onumia\Modules\Contracts\PaginationMode;
use Onumia\Modules\Contracts\SettingType;
use Onumia\Modules\Module;

#[ModuleContract]
#[Setting( 'checkboxEnabled', SettingType::Boolean, default: true )]
#[Setting( 'toggleEnabled', SettingType::Boolean, default: false )]
#[Setting( 'title', SettingType::String, default: 'Onumia UI lab' )]
#[Setting( 'url', SettingType::String, default: 'https://example.com', format: 'url' )]
#[Setting( 'email', SettingType::String, default: 'admin@example.com', format: 'email' )]
#[Setting( 'password', SettingType::String, default: '' )]
#[Setting( 'phone', SettingType::String, default: '+12025550148' )]
#[Setting( 'notes', SettingType::String, default: 'Long-form module notes.' )]
#[Setting( 'code', SettingType::String, default: 'add_filter( "the_content", "__return_empty_string" );' )]
#[Setting( 'dateField', SettingType::String, default: '2026-05-14' )]
#[Setting( 'datePicker', SettingType::String, default: '2026-05-15' )]
#[Setting(
	'dateRange',
	SettingType::Object,
	default: array(
		'start' => '2026-05-01',
		'end'   => '2026-05-31',
	)
)]
#[Setting( 'timeField', SettingType::String, default: '14:30' )]
#[Setting( 'layoutTitle', SettingType::String, default: 'Responsive grid' )]
#[Setting( 'layoutUrl', SettingType::String, default: 'https://example.com/layout', format: 'url' )]
#[Setting( 'layoutEmail', SettingType::String, default: 'layout@example.com', format: 'email' )]
#[Setting( 'layoutPhone', SettingType::String, default: '+12025550149' )]
#[Setting( 'layoutLimit', SettingType::Integer, default: 6, min: 1, max: 24 )]
#[Setting( 'layoutEnabled', SettingType::Boolean, default: true )]
#[Setting( 'count', SettingType::Integer, default: 3, min: 0, max: 20 )]
#[Setting( 'rangeValue', SettingType::Integer, default: 30, min: 0, max: 100 )]
#[Setting( 'conditionalFeatureEnabled', SettingType::Boolean, default: true )]
#[Setting( 'conditionalMode', SettingType::String, default: 'simple', allowed: array( 'simple', 'advanced', 'locked' ) )]
#[Setting( 'conditionalAudience', SettingType::String, default: 'editors', allowed: array( 'editors', 'members', 'customers' ) )]
#[Setting( 'conditionalLimit', SettingType::Integer, default: 50, min: 0, max: 100 )]
#[Setting( 'conditionalVisibleText', SettingType::String, default: 'Shown while advanced controls are enabled.' )]
#[Setting( 'conditionalEnabledNote', SettingType::String, default: 'Editors can adjust this note.' )]
#[Setting( 'conditionalRequiredBrief', SettingType::String, default: 'Add rollout notes when advanced mode is selected.' )]
#[Setting( 'conditionalReadOnlySlug', SettingType::String, default: 'locked-admin-slug' )]
#[Setting( 'conditionalValidatedScore', SettingType::Integer, default: 72, min: 0, max: 100 )]
#[Setting( 'conditionalSourceChoice', SettingType::String, default: 'post' )]
#[Setting( 'moduleSelect', SettingType::String, default: 'module-alpha', allowed: array( 'module-alpha', 'module-beta', 'module-gamma' ) )]
#[Setting( 'postTypes', SettingType::Array, default: array( 'post', 'page' ) )]
#[Setting( 'selectedUser', SettingType::String, default: '1' )]
#[Setting( 'selectedUsers', SettingType::Array, default: array( '1', '8' ) )]
#[Setting( 'selectedPost', SettingType::String, default: '1' )]
#[Setting( 'selectedPosts', SettingType::Array, default: array( '1', '2' ) )]
#[Setting( 'selectedTerm', SettingType::String, default: 'category:1' )]
#[Setting( 'selectedTerms', SettingType::Array, default: array( 'category:1', 'post_tag:2' ) )]
#[Setting( 'selectedTaxonomy', SettingType::String, default: 'category' )]
#[Setting( 'selectedTaxonomies', SettingType::Array, default: array( 'category', 'post_tag' ) )]
#[Setting( 'customRestItem', SettingType::String, default: 'post' )]
#[Setting( 'missingSelectedUser', SettingType::String, default: '999999' )]
#[Setting( 'missingSelectedUsers', SettingType::Array, default: array( '999999', '888888' ) )]
#[Setting( 'missingSelectedPost', SettingType::String, default: '999999' )]
#[Setting( 'missingSelectedPosts', SettingType::Array, default: array( '999999', '888888' ) )]
#[Setting( 'missingSelectedTerm', SettingType::String, default: 'category:999999' )]
#[Setting( 'missingSelectedTerms', SettingType::Array, default: array( 'category:999999', 'post_tag:888888' ) )]
#[Setting( 'missingSelectedTaxonomy', SettingType::String, default: 'onumia_missing_taxonomy' )]
#[Setting( 'missingSelectedTaxonomies', SettingType::Array, default: array( 'onumia_missing_taxonomy', 'onumia_archived_taxonomy' ) )]
#[Setting( 'helpApiNamespace', SettingType::String, default: 'onumia/v1' )]
#[Setting( 'helpBatchSize', SettingType::Integer, default: 25, min: 1, max: 500 )]
#[Setting( 'helpFallbackRole', SettingType::String, default: 'members', allowed: array( 'editors', 'members', 'customers' ) )]
#[Setting( 'helpDryRun', SettingType::Boolean, default: true )]
#[Setting( 'drawerTitle', SettingType::String, default: 'Drawer workflow' )]
#[Setting( 'drawerLimit', SettingType::Integer, default: 12, min: 1, max: 100 )]
#[Setting( 'drawerMode', SettingType::String, default: 'draft', allowed: array( 'draft', 'review', 'published' ) )]
#[Setting( 'drawerEntityName', SettingType::String, default: 'Priority segment' )]
#[Setting( 'drawerEntitySlug', SettingType::String, default: 'priority-segment' )]
#[Setting( 'drawerEntityType', SettingType::String, default: 'segment', allowed: array( 'segment', 'workflow', 'report' ) )]
#[Setting( 'drawerEntityPublic', SettingType::Boolean, default: false )]
#[Setting( 'drawerEntityNotes', SettingType::String, default: 'Keep this workflow in draft until the launch checklist is complete.' )]
#[Setting( 'drawerEntityCode', SettingType::String, default: '{"source":"wp.posts","limit":5}' )]
#[Setting(
	'drawerRules',
	SettingType::Array,
	default: array(
		array(
			'label'   => 'Notify editors',
			'action'  => 'notify',
			'notes'   => 'Send a short digest to the editorial channel.',
			'enabled' => true,
		),
		array(
			'label'   => 'Archive stale drafts',
			'action'  => 'archive',
			'notes'   => 'Only apply after the campaign closes.',
			'enabled' => false,
		),
	)
)]
#[Setting(
	'drawerPreviewPayload',
	SettingType::Object,
	default: array(
		'status' => 'ready',
		'items'  => 3,
	)
)]
#[Setting( 'drawerDeepValue', SettingType::String, default: 'Nested drawer state' )]
#[Setting( 'roles', SettingType::Array, default: array( 'administrator' ) )]
#[Setting( 'radioChoice', SettingType::String, default: 'comfortable', allowed: array( 'compact', 'comfortable', 'spacious' ) )]
#[Setting( 'checkboxGroup', SettingType::Array, default: array( 'header', 'footer' ) )]
#[Setting(
	'keyValuePairs',
	SettingType::Object,
	default: array(
		'first'  => 'one',
		'second' => 'two',
	)
)]
#[Setting( 'structuredDataEnabled', SettingType::Boolean, default: true )]
#[Setting(
	'repeaterRows',
	SettingType::Array,
	default: array(
		array(
			'enabled' => true,
			'label'   => 'Primary',
			'value'   => 'primary',
		),
	)
)]
#[Setting( 'previewState', SettingType::Object, default: array( 'status' => 'ready' ) )]
#[Setting( 'statusItems', SettingType::Array, default: array( 'ready', 'cached', 'source-backed' ) )]
#[Setting(
	'tableRows',
	SettingType::Array,
	default: array(
		array(
			'module'  => 'UI Lab',
			'area'    => 'Admin',
			'status'  => 'enabled',
			'owner'   => 'Platform',
			'score'   => 94,
			'public'  => true,
			'updated' => '2026-05-14',
		),
		array(
			'module'  => 'Application Passwords',
			'area'    => 'Security',
			'status'  => 'enabled',
			'owner'   => 'Security',
			'score'   => 88,
			'public'  => false,
			'updated' => '2026-05-12',
		),
		array(
			'module'  => 'Disable Emojis',
			'area'    => 'Performance',
			'status'  => 'draft',
			'owner'   => 'Frontend',
			'score'   => 73,
			'public'  => true,
			'updated' => '2026-05-09',
		),
		array(
			'module'  => 'Security Headers',
			'area'    => 'Security',
			'status'  => 'review',
			'owner'   => 'Security',
			'score'   => 91,
			'public'  => true,
			'updated' => '2026-05-10',
		),
		array(
			'module'  => 'Reading Time',
			'area'    => 'Content',
			'status'  => 'enabled',
			'owner'   => 'Editorial',
			'score'   => 82,
			'public'  => true,
			'updated' => '2026-05-08',
		),
		array(
			'module'  => 'Robots Editor',
			'area'    => 'Utilities',
			'status'  => 'paused',
			'owner'   => 'SEO',
			'score'   => 69,
			'public'  => false,
			'updated' => '2026-05-06',
		),
	)
)]
#[Setting( 'buttonAction', SettingType::String, default: 'preview' )]
#[Setting( 'buttonGroupAction', SettingType::String, default: 'preview' )]
#[Setting( 'submitAction', SettingType::String, default: 'preview' )]
#[Setting( 'resetAction', SettingType::String, default: 'preview' )]
#[Setting( 'dangerAction', SettingType::String, default: 'preview' )]
#[Setting( 'miniAppName', SettingType::String, default: 'Onumia launch' )]
#[Setting( 'miniAppMode', SettingType::String, default: 'guided', allowed: array( 'guided', 'manual', 'automatic' ) )]
#[Setting( 'miniAppPublic', SettingType::Boolean, default: false )]
#[Setting( 'contentTitle', SettingType::String, default: 'May campaign' )]
#[Setting( 'contentGoal', SettingType::String, default: 'Launch a polished content workflow for editors.' )]
#[Setting( 'contentAudience', SettingType::String, default: 'editors', allowed: array( 'editors', 'members', 'customers' ) )]
#[Setting( 'contentChannels', SettingType::Array, default: array( 'site', 'email' ) )]
#[Setting( 'contentPostTypes', SettingType::Array, default: array( 'post', 'page' ) )]
#[Setting( 'contentLaunchDate', SettingType::String, default: '2026-05-20' )]
#[Setting(
	'contentWindow',
	SettingType::Object,
	default: array(
		'start' => '2026-05-20',
		'end'   => '2026-05-27',
	)
)]
#[Setting( 'contentReviewTime', SettingType::String, default: '10:30' )]
#[Setting( 'contentRulesCode', SettingType::String, default: 'return array_filter( $items );' )]
#[Setting( 'opsEnvironment', SettingType::String, default: 'staging', allowed: array( 'staging', 'production' ) )]
#[Setting( 'opsContactPhone', SettingType::String, default: '+12025550148' )]
#[Setting( 'opsSecret', SettingType::String, default: '' )]
#[Setting( 'opsDryRun', SettingType::Boolean, default: true )]
#[Setting( 'opsLimit', SettingType::Integer, default: 10, min: 1, max: 50 )]
#[Setting( 'opsIntensity', SettingType::Integer, default: 40, min: 0, max: 100 )]
#[Setting( 'opsModules', SettingType::Array, default: array( 'module-alpha' ) )]
#[Setting( 'opsChecklist', SettingType::Array, default: array( 'backup', 'cache' ) )]
#[Setting(
	'opsPayload',
	SettingType::Object,
	default: array(
		'cache'  => 'warm',
		'assets' => 'verify',
	)
)]
#[Setting(
	'entrySegments',
	SettingType::Array,
	default: array(
		array(
			'id'       => 'launch',
			'name'     => 'Launch segment',
			'status'   => 'active',
			'owner'    => 'Platform',
			'priority' => 85,
			'public'   => true,
			'notes'    => 'Coordinates release-ready workflows.',
		),
		array(
			'id'       => 'security',
			'name'     => 'Security review',
			'status'   => 'review',
			'owner'    => 'Security',
			'priority' => 72,
			'public'   => false,
			'notes'    => 'Validates access-sensitive changes.',
		),
		array(
			'id'       => 'content',
			'name'     => 'Content polish',
			'status'   => 'draft',
			'owner'    => 'Editorial',
			'priority' => 54,
			'public'   => true,
			'notes'    => 'Keeps editorial cleanup visible.',
		),
	)
)]
#[Setting(
	'entryRules',
	SettingType::Array,
	default: array(
		array(
			'id'        => 'notify-launch',
			'segmentId' => 'launch',
			'label'     => 'Notify launch owners',
			'action'    => 'notify',
			'enabled'   => true,
		),
		array(
			'id'        => 'archive-launch',
			'segmentId' => 'launch',
			'label'     => 'Archive stale launch drafts',
			'action'    => 'archive',
			'enabled'   => false,
		),
		array(
			'id'        => 'notify-security',
			'segmentId' => 'security',
			'label'     => 'Escalate security review',
			'action'    => 'notify',
			'enabled'   => true,
		),
	)
)]
final class UiLab extends Module {

	/**
	 * @return array<string,mixed>
	 */
	#[Entries( name: 'segments', singular: 'Segment', plural: 'Segments', key: 'id', storage: EntryStorage::Settings, setting: 'entrySegments', close_on_success: false )]
	#[EntrySection( name: 'identity', label: 'Identity', description: 'Core segment fields.', order: 10 )]
	#[EntrySection( name: 'operations', label: 'Operations', description: 'Status, visibility, and notes.', order: 20 )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, list: true, filter: true, filter_type: 'text', section: 'identity', create: false, update: false, order: 1 )]
	#[EntryField( name: 'name', type: SettingType::String, label: 'Name', required: true, list: true, filter: true, filter_type: 'text', section: 'identity', order: 2 )]
	#[EntryField(
		name: 'status',
		type: SettingType::String,
		label: 'Status',
		default: 'draft',
		allowed: array( 'active', 'draft', 'review', 'paused' ),
		list: true,
		filter: true,
		filter_type: 'option',
		options: array(
			array(
				'value' => 'active',
				'label' => 'Active',
			),
			array(
				'value' => 'draft',
				'label' => 'Draft',
			),
			array(
				'value' => 'review',
				'label' => 'Review',
			),
			array(
				'value' => 'paused',
				'label' => 'Paused',
			),
		),
		section: 'operations',
		order: 3
	)]
	#[EntryField( name: 'owner', type: SettingType::String, label: 'Owner', default: 'Platform', list: true, filter: true, filter_type: 'option', optionsSource: array( 'source' => 'module.tableOwners' ), section: 'operations', order: 4 )]
	#[EntryField( name: 'priority', type: SettingType::Integer, label: 'Priority', default: 50, min: 0, max: 100, list: true, filter: true, filter_type: 'number', section: 'operations', order: 5 )]
	#[EntryField( name: 'public', type: SettingType::Boolean, label: 'Public', default: false, list: true, filter: true, filter_type: 'boolean', section: 'operations', order: 6 )]
	#[EntryField( name: 'notes', type: SettingType::String, label: 'Notes', default: '', section: 'operations', order: 7 )]
	#[RelatedEntries( name: 'rules', entry: 'segmentRules', local_key: 'id', foreign_key: 'segmentId', label: 'Rules', mode: 'manage', order: 20 )]
	public function segments_entry_contract(): array {
		return array();
	}

	/**
	 * @return array<string,mixed>
	 */
	#[Entries( name: 'segmentRules', singular: 'Rule', plural: 'Rules', key: 'id', storage: EntryStorage::Settings, setting: 'entryRules' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, list: true, create: false, update: false, order: 1 )]
	#[EntryField( name: 'segmentId', type: SettingType::String, label: 'Segment ID', list: true, create: false, update: false, order: 2 )]
	#[EntryField( name: 'label', type: SettingType::String, label: 'Label', required: true, list: true, order: 3 )]
	#[EntryField(
		name: 'action',
		type: SettingType::String,
		label: 'Action',
		default: 'notify',
		allowed: array( 'notify', 'assign', 'archive' ),
		list: true,
		options: array(
			array(
				'value' => 'notify',
				'label' => 'Notify',
			),
			array(
				'value' => 'assign',
				'label' => 'Assign',
			),
			array(
				'value' => 'archive',
				'label' => 'Archive',
			),
		),
		order: 4
	)]
	#[EntryField( name: 'enabled', type: SettingType::Boolean, label: 'Enabled', default: true, list: true, order: 5 )]
	public function segment_rules_entry_contract(): array {
		return array();
	}

	/**
	 * @return array<string,mixed>
	 */
	#[Entries( name: 'activityEvents', singular: 'Activity event', plural: 'Activity events', key: 'id', storage: EntryStorage::Manual, source: 'entryActivity', create_action: 'createEntryActivity', update_action: 'updateEntryActivity', delete_action: 'deleteEntryActivity' )]
	#[EntrySection( name: 'details', label: 'Details', description: 'Event metadata and nested payload.', order: 10 )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, list: true, create: false, update: false, order: 1 )]
	#[EntryField( name: 'title', type: SettingType::String, label: 'Title', required: true, list: true, filter: true, filter_type: 'text', section: 'details', order: 2 )]
	#[EntryField(
		name: 'kind',
		type: SettingType::String,
		label: 'Kind',
		default: 'system',
		allowed: array( 'system', 'user', 'agent' ),
		list: true,
		filter: true,
		filter_type: 'option',
		options: array(
			array(
				'value' => 'system',
				'label' => 'System',
			),
			array(
				'value' => 'user',
				'label' => 'User',
			),
			array(
				'value' => 'agent',
				'label' => 'Agent',
			),
		),
		section: 'details',
		order: 3
	)]
	#[EntryField( name: 'meta.channel', type: SettingType::String, label: 'Channel', default: 'admin', allowed: array( 'admin', 'chat', 'cron' ), filter: true, filter_type: 'option', section: 'details', order: 4 )]
	#[EntryField(
		name: 'meta.score',
		type: SettingType::Integer,
		label: 'Score',
		default: 50,
		min: 0,
		max: 100,
		list: true,
		filter: true,
		filter_type: 'number',
		section: 'details',
		order: 5,
		visible_when: array(
			'op'    => 'equals',
			'left'  => array( 'ref' => 'form.kind' ),
			'right' => 'agent',
		)
	)]
	public function activity_events_entry_contract(): array {
		return array();
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'entryContacts', shape: DataSourceShape::Collection )]
	#[Entries( name: 'entryContacts', singular: 'Contact', plural: 'Contacts', key: 'id', storage: EntryStorage::Table, source: 'entryContacts', table: 'entry_contacts', create_action: 'createEntryContact', update_action: 'updateEntryContact', delete_action: 'deleteEntryContacts' )]
	public function entry_contacts(): array {
		return $this->entry_contact_rows();
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	#[Action( 'createEntryContact' )]
	#[Input( 'name', SettingType::String, required: true )]
	#[Input( 'status', SettingType::String, default: 'active', allowed: array( 'active', 'review', 'paused' ) )]
	#[Input( 'owner', SettingType::String, default: 'Platform' )]
	#[Input( 'score', SettingType::Integer, default: 50, min: 0, max: 100 )]
	#[Input( 'public', SettingType::Boolean, default: false )]
	#[Input( 'notes', SettingType::String, default: '' )]
	public function create_entry_contact( array $input ): array {
		return array(
			'id'      => 'contact-' . substr( md5( $this->string_input( $input, 'name', '' ) ), 0, 8 ),
			'created' => true,
			'input'   => $input,
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	#[Action( 'updateEntryContact' )]
	#[Input( 'id', SettingType::String, required: true )]
	#[Input( 'name', SettingType::String, required: true )]
	#[Input( 'status', SettingType::String, default: 'active', allowed: array( 'active', 'review', 'paused' ) )]
	#[Input( 'owner', SettingType::String, default: 'Platform' )]
	#[Input( 'score', SettingType::Integer, default: 50, min: 0, max: 100 )]
	#[Input( 'public', SettingType::Boolean, default: false )]
	#[Input( 'notes', SettingType::String, default: '' )]
	public function update_entry_contact( array $input ): array {
		$id_value = $this->string_input( $input, 'id', '' );
		$id       = ctype_digit( $id_value ) ? (int) $id_value : 0;
		$table    = $this->table( 'entry_contacts' );
		$row      = $input;
		unset( $row['id'] );

		if ( null === $table->find( $id ) ) {
			$seed = array_values(
				array_filter(
					$this->entry_contact_rows(),
					static fn( array $contact ): bool => self::scalar_string( $contact['id'] ?? null ) === $id_value
				)
			)[0] ?? array(
				'id'         => $id,
				'created_at' => gmdate( 'Y-m-d H:i:s', $this->now() ),
			);
			$table->insert( array_merge( $seed, $row, array( 'id' => $id ) ) );
		} else {
			$table->update( $id, $row );
		}

		return array(
			'updated' => true,
			'input'   => $input,
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	#[Action( 'deleteEntryContacts' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function delete_entry_contacts( array $input ): array {
		return array(
			'deleted' => is_array( $input['ids'] ?? null ) ? $input['ids'] : array(),
		);
	}

	/**
	 * @return array<int,array{value:string,label:string}>
	 */
	#[DataSource( 'moduleChoices' )]
	public function module_choices(): array {
		return array(
			array(
				'value' => 'module-alpha',
				'label' => 'Module alpha',
			),
			array(
				'value' => 'module-beta',
				'label' => 'Module beta',
			),
			array(
				'value' => 'module-gamma',
				'label' => 'Module gamma',
			),
		);
	}

	/**
	 * @return array<int,array{value:string,label:string}>
	 */
	#[DataSource( 'tableOwners' )]
	public function table_owners(): array {
		return array(
			array(
				'value' => 'Platform',
				'label' => 'Platform',
			),
			array(
				'value' => 'Security',
				'label' => 'Security',
			),
			array(
				'value' => 'Frontend',
				'label' => 'Frontend',
			),
			array(
				'value' => 'Editorial',
				'label' => 'Editorial',
			),
			array(
				'value' => 'SEO',
				'label' => 'SEO',
			),
		);
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'entryActivity', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
	#[Input( 'query', SettingType::Object, default: array() )]
	#[Input( 'page', SettingType::Integer, default: 0 )]
	#[Input( 'pageSize', SettingType::Integer, default: 5 )]
	#[ObjectShape(
		'query',
		array(
			'search'  => 'string',
			'filters' => 'array',
			'sorting' => 'array',
			'page'    => 'array',
		)
	)]
	public function entry_activity( array $params ): array {
		$query   = is_array( $params['query'] ?? null ) ? $params['query'] : array();
		$rows    = $this->entry_activity_rows();
		$search  = is_string( $query['search'] ?? null ) ? strtolower( $query['search'] ) : '';
		$filters = is_array( $query['filters'] ?? null ) ? $query['filters'] : array();

		if ( '' !== $search ) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn( array $row ): bool => str_contains( strtolower( self::scalar_string( $row['title'] ?? null ) ), $search )
				)
			);
		}

		foreach ( $filters as $filter ) {
			if ( ! is_array( $filter ) || ! is_string( $filter['columnId'] ?? null ) ) {
				continue;
			}

			$column   = $filter['columnId'];
			$values   = is_array( $filter['values'] ?? null ) ? $filter['values'] : array();
			$operator = is_string( $filter['operator'] ?? null ) ? $filter['operator'] : 'is';
			$needle   = self::scalar_string( $values[0] ?? null );
			if ( '' === $needle ) {
				continue;
			}

			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $column, $needle, $operator ): bool {
						$value = self::scalar_string( self::entry_row_value( $row, $column ) );
						return match ( $operator ) {
							'is' => $value === $needle,
							'isNot' => $value !== $needle,
							default => str_contains( strtolower( $value ), strtolower( $needle ) ),
						};
					}
				)
			);
		}

		$sorting = is_array( $query['sorting'] ?? null ) ? $query['sorting'] : array();
		foreach ( array_reverse( $sorting ) as $sort ) {
			if ( ! is_array( $sort ) || ! is_string( $sort['columnId'] ?? null ) ) {
				continue;
			}

			$column    = $sort['columnId'];
			$direction = is_string( $sort['direction'] ?? null ) ? $sort['direction'] : 'asc';
			usort(
				$rows,
				static function ( array $left, array $right ) use ( $column, $direction ): int {
					$result = strnatcmp( self::scalar_string( self::entry_row_value( $left, $column ) ), self::scalar_string( self::entry_row_value( $right, $column ) ) );
					return 'desc' === $direction ? -$result : $result;
				}
			);
		}

		$total = count( $rows );
		$page  = is_array( $query['page'] ?? null ) ? $query['page'] : array();
		$index = isset( $page['index'] ) && is_numeric( $page['index'] )
			? max( 0, (int) $page['index'] )
			: ( isset( $params['page'] ) && is_numeric( $params['page'] ) ? max( 0, (int) $params['page'] ) : 0 );
		$size  = isset( $page['size'] ) && is_numeric( $page['size'] )
			? max( 1, min( 50, (int) $page['size'] ) )
			: ( isset( $params['pageSize'] ) && is_numeric( $params['pageSize'] ) ? max( 1, min( 50, (int) $params['pageSize'] ) ) : 5 );

		return array(
			'items'    => array_slice( $rows, $index * $size, $size ),
			'total'    => $total,
			'page'     => $index,
			'pageSize' => $size,
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	#[Action( 'createEntryActivity' )]
	#[Input( 'title', SettingType::String, required: true )]
	#[Input( 'kind', SettingType::String, default: 'system', allowed: array( 'system', 'user', 'agent' ) )]
	#[Input( 'meta', SettingType::Object, default: array() )]
	#[ObjectShape(
		'meta',
		array(
			'channel' => 'string',
			'score'   => 'integer',
		)
	)]
	public function create_entry_activity( array $input ): array {
		return array(
			'id'      => 'activity-' . substr( md5( $this->string_input( $input, 'title', '' ) ), 0, 8 ),
			'created' => true,
			'input'   => $input,
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	#[Action( 'updateEntryActivity' )]
	#[Input( 'id', SettingType::String, required: true )]
	#[Input( 'title', SettingType::String, required: true )]
	#[Input( 'kind', SettingType::String, default: 'system', allowed: array( 'system', 'user', 'agent' ) )]
	#[Input( 'meta', SettingType::Object, default: array() )]
	#[ObjectShape(
		'meta',
		array(
			'channel' => 'string',
			'score'   => 'integer',
		)
	)]
	public function update_entry_activity( array $input ): array {
		return array(
			'updated' => true,
			'input'   => $input,
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	#[Action( 'deleteEntryActivity' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function delete_entry_activity( array $input ): array {
		return array(
			'deleted' => is_array( $input['ids'] ?? null ) ? $input['ids'] : array(),
		);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function entry_activity_rows(): array {
		return array(
			array(
				'id'    => 'evt-001',
				'title' => 'Loaded module contracts',
				'kind'  => 'system',
				'meta'  => array(
					'channel' => 'admin',
					'score'   => 84,
				),
			),
			array(
				'id'    => 'evt-002',
				'title' => 'User changed settings',
				'kind'  => 'user',
				'meta'  => array(
					'channel' => 'admin',
					'score'   => 41,
				),
			),
			array(
				'id'    => 'evt-003',
				'title' => 'Agent drafted an entry update',
				'kind'  => 'agent',
				'meta'  => array(
					'channel' => 'chat',
					'score'   => 93,
				),
			),
			array(
				'id'    => 'evt-004',
				'title' => 'Cron reconciled stale data',
				'kind'  => 'system',
				'meta'  => array(
					'channel' => 'cron',
					'score'   => 67,
				),
			),
			array(
				'id'    => 'evt-005',
				'title' => 'Agent validated drawer fields',
				'kind'  => 'agent',
				'meta'  => array(
					'channel' => 'chat',
					'score'   => 88,
				),
			),
			array(
				'id'    => 'evt-006',
				'title' => 'User reviewed bulk delete',
				'kind'  => 'user',
				'meta'  => array(
					'channel' => 'admin',
					'score'   => 59,
				),
			),
		);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function entry_contact_rows(): array {
		return array(
			array(
				'id'         => '101',
				'name'       => 'Ava Operations',
				'status'     => 'active',
				'owner'      => 'Platform',
				'score'      => 92,
				'public'     => true,
				'notes'      => 'Primary contact for release operations.',
				'created_at' => '2026-05-12 09:30:00',
			),
			array(
				'id'         => '102',
				'name'       => 'Noah Security',
				'status'     => 'review',
				'owner'      => 'Security',
				'score'      => 78,
				'public'     => false,
				'notes'      => 'Reviews access-sensitive entry changes.',
				'created_at' => '2026-05-13 12:15:00',
			),
			array(
				'id'         => '103',
				'name'       => 'Mia Editorial',
				'status'     => 'paused',
				'owner'      => 'Editorial',
				'score'      => 63,
				'public'     => true,
				'notes'      => 'Paused until the launch copy is approved.',
				'created_at' => '2026-05-14 16:45:00',
			),
		);
	}

	/**
	 * @param array<string,mixed> $row Row.
	 */
	private static function entry_row_value( array $row, string $path ): mixed {
		$value = $row;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * @return array<string,mixed>
	 */
	#[Action( 'preview' )]
	public function preview(): array {
		return array(
			'module'   => $this->definition()->name(),
			'settings' => $this->settings(),
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	#[Action( 'composeBrief' )]
	#[Input( 'title', SettingType::String, default: '' )]
	#[Input( 'goal', SettingType::String, default: '' )]
	#[Input( 'audience', SettingType::String, default: 'editors' )]
	#[Input( 'channels', SettingType::Array, default: array() )]
	#[Input( 'postTypes', SettingType::Array, default: array() )]
	#[Input( 'launchDate', SettingType::String, default: '' )]
	#[Input( 'window', SettingType::Object, default: array() )]
	#[Input( 'reviewTime', SettingType::String, default: '' )]
	#[Input( 'rules', SettingType::String, default: '' )]
	#[ObjectShape(
		'window',
		array(
			'start' => 'string',
			'end'   => 'string',
		)
	)]
	public function compose_brief( array $input ): array {
		$title      = $this->string_input( $input, 'title', 'Untitled campaign' );
		$channels   = $this->string_list_input( $input, 'channels' );
		$post_types = $this->string_list_input( $input, 'postTypes' );
		$window     = is_array( $input['window'] ?? null ) ? $input['window'] : array();
		$start      = self::scalar_string( $window['start'] ?? 'start' );
		$end        = self::scalar_string( $window['end'] ?? 'end' );

		return array(
			'title'    => $title,
			'summary'  => sprintf(
				'%s for %s across %s.',
				$this->string_input( $input, 'goal', 'Content workflow' ),
				$this->string_input( $input, 'audience', 'editors' ),
				implode( ', ', $channels )
			),
			'status'   => array( 'brief-ready', 'backend-action', 'json-driven' ),
			'sections' => array(
				array(
					'name'  => 'Launch date',
					'value' => $this->string_input( $input, 'launchDate', 'not scheduled' ),
				),
				array(
					'name'  => 'Publishing window',
					'value' => $start . ' to ' . $end,
				),
				array(
					'name'  => 'Post types',
					'value' => implode( ', ', $post_types ),
				),
				array(
					'name'  => 'Review time',
					'value' => $this->string_input( $input, 'reviewTime', 'not set' ),
				),
			),
			'rules'    => $this->string_input( $input, 'rules', '' ),
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	#[Action( 'runAudit' )]
	#[Input( 'environment', SettingType::String, default: 'staging' )]
	#[Input( 'dryRun', SettingType::Boolean, default: true )]
	#[Input( 'limit', SettingType::Integer, default: 10, min: 1, max: 50 )]
	#[Input( 'intensity', SettingType::Integer, default: 40, min: 0, max: 100 )]
	#[Input( 'modules', SettingType::Array, default: array() )]
	#[Input( 'checklist', SettingType::Array, default: array() )]
	#[Input( 'payload', SettingType::Object, default: array() )]
	#[Input( 'phone', SettingType::String, default: '' )]
	#[ObjectShape(
		'payload',
		array(
			'cache'  => 'string',
			'assets' => 'string',
		)
	)]
	public function run_audit( array $input ): array {
		$modules   = $this->string_list_input( $input, 'modules' );
		$checklist = $this->string_list_input( $input, 'checklist' );
		$dry_run   = true === ( $input['dryRun'] ?? true );
		$limit     = $this->integer_input( $input, 'limit', 10 );
		$intensity = $this->integer_input( $input, 'intensity', 40 );

		return array(
			'environment' => $this->string_input( $input, 'environment', 'staging' ),
			'mode'        => $dry_run ? 'dry-run' : 'live',
			'status'      => array(
				$dry_run ? 'safe-mode' : 'live-mode',
				'audit-complete',
				'limit-' . $limit,
			),
			'rows'        => array(
				array(
					'check'  => 'Modules',
					'value'  => implode( ', ', $modules ),
					'result' => array() === $modules ? 'none' : 'ready',
				),
				array(
					'check'  => 'Checklist',
					'value'  => implode( ', ', $checklist ),
					'result' => count( $checklist ) >= 2 ? 'covered' : 'thin',
				),
				array(
					'check'  => 'Intensity',
					'value'  => (string) $intensity,
					'result' => $intensity > 75 ? 'high' : 'normal',
				),
				array(
					'check'  => 'Contact',
					'value'  => $this->string_input( $input, 'phone', 'none' ),
					'result' => '' === $this->string_input( $input, 'phone', '' ) ? 'missing' : 'ready',
				),
			),
			'payload'     => is_array( $input['payload'] ?? null ) ? $input['payload'] : array(),
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 */
	private function string_input( array $input, string $key, string $fallback ): string {
		return is_string( $input[ $key ] ?? null ) && '' !== $input[ $key ] ? $input[ $key ] : $fallback;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 */
	private function integer_input( array $input, string $key, int $fallback ): int {
		return is_int( $input[ $key ] ?? null ) ? $input[ $key ] : $fallback;
	}

	private static function scalar_string( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return list<string>
	 */
	private function string_list_input( array $input, string $key ): array {
		$value = $input[ $key ] ?? array();
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$value,
				static fn( mixed $item ): bool => is_string( $item ) && '' !== $item
			)
		);
	}
}
