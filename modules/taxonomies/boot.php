<?php
/**
 * Taxonomies module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Taxonomies;

use Onumia\Core\Errors;
use Onumia\Modules\Attributes\Action;
use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Entries;
use Onumia\Modules\Attributes\EntryField;
use Onumia\Modules\Attributes\EntrySection;
use Onumia\Modules\Attributes\Input;
use Onumia\Modules\Attributes\ModuleContract;
use Onumia\Modules\Attributes\ObjectShape;
use Onumia\Modules\Attributes\Setting;
use Onumia\Modules\Attributes\WpAction;
use Onumia\Modules\Attributes\WpFilter;
use Onumia\Modules\Contracts\DataSourceShape;
use Onumia\Modules\Contracts\EntryStorage;
use Onumia\Modules\Contracts\PaginationMode;
use Onumia\Modules\Contracts\SettingType;
use Onumia\Modules\Module;
use Onumia\Modules\ModuleSettingsRepository;

#[ModuleContract( capability: 'manage_options' )]
#[Setting( 'taxonomies', SettingType::Array, default: array() )]
#[Setting( 'overrides', SettingType::Object, default: array() )]
final class Taxonomies extends Module {
	private const BUILTIN_TAXONOMIES = array( 'category', 'post_tag', 'nav_menu', 'link_category', 'post_format', 'wp_pattern_category' );
	private const LABEL_KEYS         = array(
		'name',
		'singular_name',
		'menu_name',
		'search_items',
		'popular_items',
		'all_items',
		'parent_item',
		'parent_item_colon',
		'edit_item',
		'view_item',
		'update_item',
		'add_new_item',
		'new_item_name',
		'separate_items_with_commas',
		'add_or_remove_items',
		'choose_from_most_used',
		'not_found',
		'no_terms',
		'name_field_description',
		'slug_field_description',
		'parent_field_description',
		'desc_field_description',
		'filter_by_item',
		'items_list',
		'items_list_navigation',
		'back_to_items',
		'item_link',
		'item_link_description',
	);
	private const CAPABILITY_KEYS    = array( 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms' );
	private const QUERY_ARG_KEYS     = array( 'orderby', 'order', 'number', 'hide_empty', 'include', 'exclude', 'update_term_meta_cache', 'pad_counts' );

	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'taxonomies', shape: DataSourceShape::Collection, pagination: PaginationMode::Client )]
	#[Entries( name: 'taxonomies', singular: 'Taxonomy', plural: 'Taxonomies', key: 'slug', storage: EntryStorage::Manual, source: 'taxonomies', create_action: 'saveTaxonomy', update_action: 'saveTaxonomy', delete_action: 'deleteTaxonomies' )]
	#[EntrySection( name: 'identity', label: 'Identity', description: 'Core taxonomy identity.', order: 10, layout: 'tabs' )]
	#[EntrySection( name: 'labels', label: 'Labels', description: 'Admin labels exposed by WordPress.', order: 20, layout: 'tabs' )]
	#[EntrySection( name: 'objects', label: 'Object types', description: 'Post types this taxonomy attaches to.', order: 30, layout: 'tabs' )]
	#[EntrySection( name: 'visibility', label: 'Visibility', description: 'Admin and public visibility flags.', order: 40, layout: 'tabs' )]
	#[EntrySection( name: 'hierarchy', label: 'Hierarchy', description: 'Hierarchy and default term settings.', order: 50, layout: 'tabs' )]
	#[EntrySection( name: 'rest', label: 'REST', description: 'REST API exposure and route names.', order: 60, layout: 'tabs' )]
	#[EntrySection( name: 'rewrite', label: 'Rewrite', description: 'Rewrite and query-var settings.', order: 70, layout: 'tabs' )]
	#[EntrySection( name: 'capabilities', label: 'Capabilities', description: 'Capability mapping for custom taxonomies.', order: 80, layout: 'tabs' )]
	#[EntryField( name: 'label', type: SettingType::String, label: 'Label', required: true, list: true, filter: true, filter_type: 'text', section: 'identity', order: 10 )]
	#[EntryField(
		name: 'slug',
		type: SettingType::String,
		label: 'Slug',
		primary: true,
		required: true,
		list: true,
		filter: true,
		filter_type: 'text',
		section: 'identity',
		order: 20,
		props: array(
			'autoSuggest'              => array(
				'from'     => 'label',
				'strategy' => 'slug',
			),
			'confirmChangeDescription' => 'Renaming a registered taxonomy can orphan existing term relationships using the old slug. Confirm only after term relationships have been migrated or the old slug remains registered.',
			'confirmChangeLabel'       => 'Rename slug',
			'confirmChangeTitle'       => 'Rename taxonomy slug?',
			'confirmOnChange'          => true,
			'lockedHelpText'           => 'Built-in and external taxonomy slugs are owned by WordPress or another plugin and cannot be changed here.',
			'lockedOrigins'            => array( 'builtin', 'builtin-override', 'external', 'external-override' ),
			'mutablePrimary'           => true,
			'originalInput'            => 'originalSlug',
		)
	)]
	#[EntryField( name: 'origin', type: SettingType::String, label: 'Origin', default: 'custom', allowed: array( 'builtin', 'builtin-override', 'external', 'external-override', 'custom' ), create: false, update: true, read_only: true, section: 'identity', order: 25 )]
	#[EntryField( name: 'originLabel', type: SettingType::String, label: 'Origin', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'identity', order: 30 )]
	#[EntryField( name: 'plural', type: SettingType::String, label: 'Plural label', section: 'identity', order: 40 )]
	#[EntryField( name: 'description', type: SettingType::String, label: 'Description', section: 'identity', order: 50, props: array( 'multiline' => true ) )]
	#[EntryField( name: 'labels.name', type: SettingType::String, label: 'Name', section: 'labels', order: 10 )]
	#[EntryField( name: 'labels.singular_name', type: SettingType::String, label: 'Singular name', section: 'labels', order: 20 )]
	#[EntryField( name: 'labels.menu_name', type: SettingType::String, label: 'Menu name', section: 'labels', order: 30 )]
	#[EntryField( name: 'labels.search_items', type: SettingType::String, label: 'Search items', section: 'labels', order: 40 )]
	#[EntryField(
		name: 'labels.popular_items',
		type: SettingType::String,
		label: 'Popular items',
		section: 'labels',
		order: 50,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.hierarchical' ),
			'right' => false,
		)
	)]
	#[EntryField( name: 'labels.all_items', type: SettingType::String, label: 'All items', section: 'labels', order: 60 )]
	#[EntryField(
		name: 'labels.parent_item',
		type: SettingType::String,
		label: 'Parent item',
		section: 'labels',
		order: 70,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.hierarchical' ),
			'right' => true,
		)
	)]
	#[EntryField(
		name: 'labels.parent_item_colon',
		type: SettingType::String,
		label: 'Parent item colon',
		section: 'labels',
		order: 80,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.hierarchical' ),
			'right' => true,
		)
	)]
	#[EntryField( name: 'labels.edit_item', type: SettingType::String, label: 'Edit item', section: 'labels', order: 90 )]
	#[EntryField( name: 'labels.view_item', type: SettingType::String, label: 'View item', section: 'labels', order: 100 )]
	#[EntryField( name: 'labels.update_item', type: SettingType::String, label: 'Update item', section: 'labels', order: 110 )]
	#[EntryField( name: 'labels.add_new_item', type: SettingType::String, label: 'Add new item', section: 'labels', order: 120 )]
	#[EntryField( name: 'labels.new_item_name', type: SettingType::String, label: 'New item name', section: 'labels', order: 130 )]
	#[EntryField(
		name: 'labels.separate_items_with_commas',
		type: SettingType::String,
		label: 'Separate items with commas',
		section: 'labels',
		order: 140,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.hierarchical' ),
			'right' => false,
		)
	)]
	#[EntryField(
		name: 'labels.add_or_remove_items',
		type: SettingType::String,
		label: 'Add or remove items',
		section: 'labels',
		order: 150,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.hierarchical' ),
			'right' => false,
		)
	)]
	#[EntryField(
		name: 'labels.choose_from_most_used',
		type: SettingType::String,
		label: 'Choose from most used',
		section: 'labels',
		order: 160,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.hierarchical' ),
			'right' => false,
		)
	)]
	#[EntryField( name: 'labels.not_found', type: SettingType::String, label: 'Not found', section: 'labels', order: 170 )]
	#[EntryField( name: 'labels.no_terms', type: SettingType::String, label: 'No terms', section: 'labels', order: 180 )]
	#[EntryField( name: 'labels.name_field_description', type: SettingType::String, label: 'Name field description', section: 'labels', order: 190 )]
	#[EntryField( name: 'labels.slug_field_description', type: SettingType::String, label: 'Slug field description', section: 'labels', order: 200 )]
	#[EntryField(
		name: 'labels.parent_field_description',
		type: SettingType::String,
		label: 'Parent field description',
		section: 'labels',
		order: 210,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.hierarchical' ),
			'right' => true,
		)
	)]
	#[EntryField( name: 'labels.desc_field_description', type: SettingType::String, label: 'Description field description', section: 'labels', order: 220 )]
	#[EntryField(
		name: 'labels.filter_by_item',
		type: SettingType::String,
		label: 'Filter by item',
		section: 'labels',
		order: 230,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.hierarchical' ),
			'right' => true,
		)
	)]
	#[EntryField( name: 'labels.items_list', type: SettingType::String, label: 'Items list', section: 'labels', order: 240 )]
	#[EntryField( name: 'labels.items_list_navigation', type: SettingType::String, label: 'Items list navigation', section: 'labels', order: 250 )]
	#[EntryField( name: 'labels.back_to_items', type: SettingType::String, label: 'Back to items', section: 'labels', order: 260 )]
	#[EntryField( name: 'labels.item_link', type: SettingType::String, label: 'Item link', section: 'labels', order: 270 )]
	#[EntryField( name: 'labels.item_link_description', type: SettingType::String, label: 'Item link description', section: 'labels', order: 280 )]
	#[EntryField( name: 'objectTypes', type: SettingType::Array, label: 'Object types', default: array(), optionsSource: array( 'source' => 'postTypes' ), section: 'objects', order: 10, props: array( 'helpText' => 'Taxonomy attachments are registered as the union of this setting and any Post Types module declaration.' ) )]
	#[EntryField( name: 'objectTypesLabel', type: SettingType::String, label: 'Object types', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true, section: 'objects', order: 20 )]
	#[EntryField( name: 'public', type: SettingType::Boolean, label: 'Public', default: true, section: 'visibility', order: 10 )]
	#[EntryField( name: 'publicLabel', type: SettingType::String, label: 'Public', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'visibility', order: 20 )]
	#[EntryField( name: 'publiclyQueryable', type: SettingType::Boolean, label: 'Publicly queryable', default: true, section: 'visibility', order: 30 )]
	#[EntryField( name: 'showUi', type: SettingType::Boolean, label: 'Show UI', default: true, section: 'visibility', order: 40 )]
	#[EntryField( name: 'showInMenu', type: SettingType::Boolean, label: 'Show in menu', default: true, section: 'visibility', order: 50 )]
	#[EntryField( name: 'showInNavMenus', type: SettingType::Boolean, label: 'Show in nav menus', default: true, section: 'visibility', order: 60 )]
	#[EntryField( name: 'showAdminColumn', type: SettingType::Boolean, label: 'Show admin column', default: true, section: 'visibility', order: 70 )]
	#[EntryField( name: 'showInQuickEdit', type: SettingType::Boolean, label: 'Show in quick edit', default: true, section: 'visibility', order: 80 )]
	#[EntryField(
		name: 'showTagcloud',
		type: SettingType::Boolean,
		label: 'Show tagcloud',
		default: true,
		section: 'visibility',
		order: 90,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.hierarchical' ),
			'right' => false,
		)
	)]
	#[EntryField( name: 'hierarchical', type: SettingType::Boolean, label: 'Hierarchical', default: false, list: true, filter: true, filter_type: 'boolean', section: 'hierarchy', order: 10 )]
	#[EntryField( name: 'hierarchicalLabel', type: SettingType::String, label: 'Hierarchical', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'hierarchy', order: 20 )]
	#[EntryField(
		name: 'defaultTerm',
		type: SettingType::Boolean,
		label: 'Default term',
		default: false,
		section: 'hierarchy',
		order: 30,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.hierarchical' ),
			'right' => true,
		)
	)]
	#[EntryField(
		name: 'defaultTermSpec.name',
		type: SettingType::String,
		label: 'Default term name',
		section: 'hierarchy',
		order: 40,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.defaultTerm' ),
			'right' => true,
		)
	)]
	#[EntryField(
		name: 'defaultTermSpec.slug',
		type: SettingType::String,
		label: 'Default term slug',
		section: 'hierarchy',
		order: 50,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.defaultTerm' ),
			'right' => true,
		)
	)]
	#[EntryField(
		name: 'defaultTermSpec.description',
		type: SettingType::String,
		label: 'Default term description',
		section: 'hierarchy',
		order: 60,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.defaultTerm' ),
			'right' => true,
		)
	)]
	#[EntryField(
		name: 'sort',
		type: SettingType::String,
		label: 'Sort',
		default: 'inherit',
		allowed: array( 'inherit', 'enable', 'disable' ),
		options: array(
			array(
				'value' => 'inherit',
				'label' => 'Inherit',
			),
			array(
				'value' => 'enable',
				'label' => 'Enable',
			),
			array(
				'value' => 'disable',
				'label' => 'Disable',
			),
		),
		section: 'hierarchy',
		order: 70
	)]
	#[EntryField( name: 'showInRest', type: SettingType::Boolean, label: 'Show in REST', default: true, section: 'rest', order: 10 )]
	#[EntryField( name: 'restBase', type: SettingType::String, label: 'REST base', section: 'rest', order: 20 )]
	#[EntryField( name: 'restNamespace', type: SettingType::String, label: 'REST namespace', default: 'wp/v2', section: 'rest', order: 30 )]
	#[EntryField( name: 'restControllerClass', type: SettingType::String, label: 'REST controller class', section: 'rest', order: 40 )]
	#[EntryField( name: 'rewrite', type: SettingType::Boolean, label: 'Rewrite', default: true, section: 'rewrite', order: 10 )]
	#[EntryField(
		name: 'rewriteOptions.slug',
		type: SettingType::String,
		label: 'Rewrite slug',
		section: 'rewrite',
		order: 20,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.rewrite' ),
			'right' => true,
		)
	)]
	#[EntryField(
		name: 'rewriteOptions.withFront',
		type: SettingType::Boolean,
		label: 'With front',
		default: true,
		section: 'rewrite',
		order: 30,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.rewrite' ),
			'right' => true,
		)
	)]
	#[EntryField(
		name: 'rewriteOptions.hierarchical',
		type: SettingType::Boolean,
		label: 'Hierarchical URLs',
		default: false,
		section: 'rewrite',
		order: 40,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.rewrite' ),
			'right' => true,
		)
	)]
	#[EntryField(
		name: 'rewriteOptions.epMask',
		type: SettingType::Integer,
		label: 'Endpoint mask',
		default: 0,
		min: 0,
		section: 'rewrite',
		order: 50,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.rewrite' ),
			'right' => true,
		)
	)]
	#[EntryField( name: 'queryVar', type: SettingType::Boolean, label: 'Query var', default: true, section: 'rewrite', order: 60 )]
	#[EntryField(
		name: 'queryVarName',
		type: SettingType::String,
		label: 'Query var name',
		section: 'rewrite',
		order: 70,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.queryVar' ),
			'right' => true,
		)
	)]
	#[EntryField( name: 'capabilities.manage_terms', type: SettingType::String, label: 'Manage terms', section: 'capabilities', order: 10 )]
	#[EntryField( name: 'capabilities.edit_terms', type: SettingType::String, label: 'Edit terms', section: 'capabilities', order: 20 )]
	#[EntryField( name: 'capabilities.delete_terms', type: SettingType::String, label: 'Delete terms', section: 'capabilities', order: 30 )]
	#[EntryField( name: 'capabilities.assign_terms', type: SettingType::String, label: 'Assign terms', section: 'capabilities', order: 40 )]
	#[EntryField( name: 'args.orderby', type: SettingType::String, label: 'Default orderby', section: 'capabilities', order: 50 )]
	#[EntryField( name: 'args.order', type: SettingType::String, label: 'Default order', section: 'capabilities', order: 60 )]
	#[EntryField( name: 'args.number', type: SettingType::Integer, label: 'Default number', min: 0, section: 'capabilities', order: 70 )]
	#[EntryField( name: 'args.hide_empty', type: SettingType::Boolean, label: 'Hide empty by default', section: 'capabilities', order: 80 )]
	public function taxonomies(): array {
		return $this->taxonomy_rows();
	}

	/**
	 * @return list<array{value:string,label:string}>
	 */
	#[DataSource( 'postTypes', shape: DataSourceShape::Options )]
	public function post_types(): array {
		$objects = $this->registered_post_types();
		$options = array();
		foreach ( $objects as $slug => $object ) {
			$options[] = array(
				'value' => $slug,
				'label' => $this->string_or_default( $object->label ?? null, $slug ),
			);
		}

		usort( $options, static fn( array $a, array $b ): int => $a['label'] <=> $b['label'] );
		return $options;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{ok:bool,slug:string,origin:string}
	 */
	#[Action( 'saveTaxonomy' )]
	#[Input( 'slug', SettingType::String, required: true )]
	#[Input( 'originalSlug', SettingType::String, default: '' )]
	#[Input( 'origin', SettingType::String, default: 'custom' )]
	#[Input( 'label', SettingType::String, required: true )]
	#[Input( 'plural', SettingType::String, default: '' )]
	#[Input( 'description', SettingType::String, default: '' )]
	#[Input( 'labels', SettingType::Object, default: array() )]
	#[Input( 'objectTypes', SettingType::Array, default: array() )]
	#[Input( 'public', SettingType::Boolean, default: true )]
	#[Input( 'publiclyQueryable', SettingType::Boolean, default: true )]
	#[Input( 'showUi', SettingType::Boolean, default: true )]
	#[Input( 'showInMenu', SettingType::Boolean, default: true )]
	#[Input( 'showInNavMenus', SettingType::Boolean, default: true )]
	#[Input( 'showAdminColumn', SettingType::Boolean, default: true )]
	#[Input( 'showInQuickEdit', SettingType::Boolean, default: true )]
	#[Input( 'showTagcloud', SettingType::Boolean, default: true )]
	#[Input( 'hierarchical', SettingType::Boolean, default: false )]
	#[Input( 'defaultTerm', SettingType::Boolean, default: false )]
	#[Input( 'defaultTermSpec', SettingType::Object, default: array() )]
	#[Input( 'sort', SettingType::String, default: 'inherit', allowed: array( 'inherit', 'enable', 'disable' ) )]
	#[Input( 'showInRest', SettingType::Boolean, default: true )]
	#[Input( 'restBase', SettingType::String, default: '' )]
	#[Input( 'restNamespace', SettingType::String, default: 'wp/v2' )]
	#[Input( 'restControllerClass', SettingType::String, default: '' )]
	#[Input( 'rewrite', SettingType::Boolean, default: true )]
	#[Input( 'rewriteOptions', SettingType::Object, default: array() )]
	#[Input( 'queryVar', SettingType::Boolean, default: true )]
	#[Input( 'queryVarName', SettingType::String, default: '' )]
	#[Input( 'capabilities', SettingType::Object, default: array() )]
	#[Input( 'args', SettingType::Object, default: array() )]
	#[ObjectShape(
		name: 'labels',
		fields: array(
			'name' => 'string',
			'singular_name' => 'string',
			'menu_name' => 'string',
			'search_items' => 'string',
			'popular_items' => 'string',
			'all_items' => 'string',
			'parent_item' => 'string',
			'parent_item_colon' => 'string',
			'edit_item' => 'string',
			'view_item' => 'string',
			'update_item' => 'string',
			'add_new_item' => 'string',
			'new_item_name' => 'string',
			'separate_items_with_commas' => 'string',
			'add_or_remove_items' => 'string',
			'choose_from_most_used' => 'string',
			'not_found' => 'string',
			'no_terms' => 'string',
			'name_field_description' => 'string',
			'slug_field_description' => 'string',
			'parent_field_description' => 'string',
			'desc_field_description' => 'string',
			'filter_by_item' => 'string',
			'items_list' => 'string',
			'items_list_navigation' => 'string',
			'back_to_items' => 'string',
			'item_link' => 'string',
			'item_link_description' => 'string',
		)
	)]
	#[ObjectShape(
		name: 'defaultTermSpec',
		fields: array(
			'name' => 'string',
			'slug' => 'string',
			'description' => 'string',
		)
	)]
	#[ObjectShape(
		name: 'rewriteOptions',
		fields: array(
			'slug' => 'string',
			'withFront' => 'boolean',
			'hierarchical' => 'boolean',
			'epMask' => 'integer',
		)
	)]
	#[ObjectShape(
		name: 'capabilities',
		fields: array(
			'manage_terms' => 'string',
			'edit_terms' => 'string',
			'delete_terms' => 'string',
			'assign_terms' => 'string',
		)
	)]
	#[ObjectShape(
		name: 'args',
		fields: array(
			'orderby' => 'string',
			'order' => 'string',
			'number' => 'integer',
			'hide_empty' => 'boolean',
			'include' => 'string',
			'exclude' => 'string',
			'update_term_meta_cache' => 'boolean',
			'pad_counts' => 'boolean',
		)
	)]
	public function save_taxonomy( array $input ): array {
		$row           = $this->stored_row_from_input( $input );
		$original_slug = $this->sanitize_slug( (string) ( $input['originalSlug'] ?? '' ) );

		$this->persist_rows(
			function ( array $custom, array $overrides ) use ( $row, $original_slug ): array {
				$this->assert_slug_can_be_saved( $row['slug'], $original_slug, $custom );

				if ( '' !== $original_slug && $original_slug !== $row['slug'] ) {
					unset( $custom[ $original_slug ], $overrides[ $original_slug ] );
				}

				if ( 'custom' === $row['origin'] ) {
					$custom[ $row['slug'] ] = $row;
				} else {
					$overrides[ $row['slug'] ] = $row;
				}

				return array(
					'custom'    => $custom,
					'overrides' => $overrides,
				);
			}
		);
		$this->flush_rewrites();

		return array(
			'ok'     => true,
			'slug'   => $row['slug'],
			'origin' => $row['origin'],
		);
	}

	/**
	 * @param array{ids:array<mixed>} $input Input.
	 * @return array{ok:bool,deleted:list<string>}
	 */
	#[Action( 'deleteTaxonomies' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function delete_taxonomies( array $input ): array {
		$ids       = $this->string_list( $input['ids'] ?? array() );
		$deleted   = array();

		$this->persist_rows(
			function ( array $custom, array $overrides ) use ( $ids, &$deleted ): array {
				foreach ( $ids as $slug ) {
					if ( isset( $custom[ $slug ] ) ) {
						unset( $custom[ $slug ] );
						$deleted[] = $slug;
						if ( function_exists( 'unregister_taxonomy' ) ) {
							\unregister_taxonomy( $slug );
						}
						continue;
					}

					if ( isset( $overrides[ $slug ] ) || isset( $this->registered_taxonomies()[ $slug ] ) ) {
						throw Errors::invariant( "Taxonomy {$slug} is not owned by Onumia and cannot be deleted." );
					}
				}

				return array(
					'custom'    => $custom,
					'overrides' => $overrides,
				);
			}
		);
		$this->flush_rewrites();

		return array(
			'ok'      => true,
			'deleted' => $deleted,
		);
	}

	#[WpAction( 'init', priority: 9 )]
	public function register_custom_taxonomies(): void {
		foreach ( $this->stored_custom_rows_by_slug() as $row ) {
			$slug = is_string( $row['slug'] ?? null ) ? $row['slug'] : '';
			if ( '' === $slug || ! function_exists( 'register_taxonomy' ) ) {
				continue;
			}

			\register_taxonomy( $slug, $this->object_types_for( $row ), $this->args_for( $row ) );
		}
	}

	/**
	 * @param array<string,mixed> $args Existing taxonomy args.
	 * @return array<string,mixed>
	 */
	#[WpFilter( 'register_taxonomy_args', priority: 99, accepted_args: 2 )]
	public function filter_taxonomy_args( array $args, string $taxonomy ): array {
		$row = $this->stored_overrides_by_slug()[ $taxonomy ] ?? null;
		if ( ! is_array( $row ) ) {
			return $args;
		}

		$overrides = $this->args_for( $row, $args );
		if ( 'custom' !== ( $row['origin'] ?? 'custom' ) ) {
			unset( $overrides['query_var'] );
		}

		return array_merge( $args, $overrides );
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function taxonomy_rows(): array {
		$custom     = $this->stored_custom_rows_by_slug();
		$overrides  = $this->stored_overrides_by_slug();
		$registered = $this->registered_taxonomies();
		$rows       = array();

		foreach ( $registered as $slug => $object ) {
			$row        = $this->row_from_registered( $slug, $object );
			$stored_row = $overrides[ $slug ] ?? $custom[ $slug ] ?? null;
			if ( is_array( $stored_row ) ) {
				$row = array_merge( $row, $stored_row );
				if ( 'custom' !== ( $stored_row['origin'] ?? '' ) ) {
					$row['origin'] = true === ( $object->_builtin ?? false ) ? 'builtin-override' : 'external-override';
				}
			}
			$rows[ $slug ] = $this->decorate_row( $row );
		}

		foreach ( $custom as $slug => $stored_row ) {
			if ( isset( $rows[ $slug ] ) ) {
				continue;
			}
			$rows[ $slug ] = $this->decorate_row( $stored_row );
		}

		usort( $rows, static fn( array $a, array $b ): int => ( $a['label'] ?? $a['slug'] ?? '' ) <=> ( $b['label'] ?? $b['slug'] ?? '' ) );
		return array_values( $rows );
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @param array<string,mixed> $base Base args.
	 * @return array<string,mixed>
	 */
	public function args_for( array $row, array $base = array() ): array {
		$row     = $this->normalize_stored_row( array_merge( $base, $row ) );
		$rewrite = false === $row['rewrite'] ? false : $this->rewrite_args( $row['rewriteOptions'] );
		$args    = array(
			'label'                 => $row['label'],
			'labels'                => $this->labels_for_taxonomy( $row ),
			'description'           => $row['description'],
			'public'                => $row['public'],
			'publicly_queryable'    => $row['publiclyQueryable'],
			'hierarchical'          => $row['hierarchical'],
			'show_ui'               => $row['showUi'],
			'show_in_menu'          => $row['showInMenu'],
			'show_in_nav_menus'     => $row['showInNavMenus'],
			'show_admin_column'     => $row['showAdminColumn'],
			'show_in_quick_edit'    => $row['showInQuickEdit'],
			'show_tagcloud'         => $row['showTagcloud'],
			'show_in_rest'          => $row['showInRest'],
			'rest_base'             => '' === $row['restBase'] ? $row['slug'] : $row['restBase'],
			'rest_namespace'        => '' === $row['restNamespace'] ? 'wp/v2' : $row['restNamespace'],
			'rewrite'               => $rewrite,
			'query_var'             => true === $row['queryVar'] && '' !== $row['queryVarName'] ? $row['queryVarName'] : $row['queryVar'],
		);

		if ( '' !== $row['restControllerClass'] ) {
			$args['rest_controller_class'] = $row['restControllerClass'];
		}
		if ( array() !== $row['capabilities'] ) {
			$args['capabilities'] = $row['capabilities'];
		}
		if ( true === $row['defaultTerm'] ) {
			$args['default_term'] = $row['defaultTermSpec'];
		}
		if ( 'inherit' !== $row['sort'] ) {
			$args['sort'] = 'enable' === $row['sort'];
		}
		if ( array() !== $row['args'] ) {
			$args['args'] = $row['args'];
		}

		return $args;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,string>
	 */
	public function labels_for_taxonomy( array $row ): array {
		$row      = $this->normalize_stored_row( $row );
		$singular = $row['label'];
		$plural   = '' === $row['plural'] ? $row['label'] : $row['plural'];

		return $this->allowed_string_map(
			array_merge(
				array(
					'name'                       => $plural,
					'singular_name'              => $singular,
					'menu_name'                  => $plural,
					'search_items'               => "Search {$plural}",
					'popular_items'              => "Popular {$plural}",
					'all_items'                  => "All {$plural}",
					'parent_item'                => "Parent {$singular}",
					'parent_item_colon'          => "Parent {$singular}:",
					'edit_item'                  => "Edit {$singular}",
					'view_item'                  => "View {$singular}",
					'update_item'                => "Update {$singular}",
					'add_new_item'               => "Add new {$singular}",
					'new_item_name'              => "New {$singular} name",
					'separate_items_with_commas' => "Separate {$plural} with commas",
					'add_or_remove_items'        => "Add or remove {$plural}",
					'choose_from_most_used'      => "Choose from the most used {$plural}",
					'not_found'                  => "No {$plural} found",
					'no_terms'                   => "No {$plural}",
					'items_list'                 => "{$plural} list",
					'items_list_navigation'      => "{$plural} list navigation",
					'back_to_items'              => "Back to {$plural}",
				),
				$row['labels']
			),
			self::LABEL_KEYS
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_custom_rows_by_slug(): array {
		return $this->stored_custom_rows_by_slug_from( $this->array_setting( 'taxonomies' ) );
	}

	/**
	 * @param mixed $value Custom taxonomy rows.
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_custom_rows_by_slug_from( mixed $value ): array {
		$rows = array();
		if ( ! is_array( $value ) ) {
			return $rows;
		}

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['slug'] ?? null ) ) {
				continue;
			}
			$slug = $this->sanitize_slug( $row['slug'] );
			if ( '' === $slug ) {
				continue;
			}
			$row['slug']   = $slug;
			$row['origin'] = 'custom';
			$rows[ $slug ] = $this->normalize_stored_row( $row );
		}

		return $rows;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_overrides_by_slug(): array {
		return $this->stored_overrides_by_slug_from( $this->object_setting( 'overrides' ) );
	}

	/**
	 * @param mixed $value Override rows.
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_overrides_by_slug_from( mixed $value ): array {
		$rows = array();
		if ( ! is_array( $value ) ) {
			return $rows;
		}

		foreach ( $value as $slug => $row ) {
			if ( ! is_string( $slug ) || ! is_array( $row ) ) {
				continue;
			}
			$clean = $this->sanitize_slug( $slug );
			if ( '' === $clean ) {
				continue;
			}
			$row['slug']   = $clean;
			$row['origin'] = $this->registered_origin_for_slug( $clean );
			$rows[ $clean ] = $this->normalize_stored_row( $row );
		}

		return $rows;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	private function stored_row_from_input( array $input ): array {
		$raw_slug = (string) ( $input['slug'] ?? '' );
		$slug     = $this->sanitize_slug( $raw_slug );
		if ( '' === $slug ) {
			throw Errors::invariant( 'Taxonomy slug is required.' );
		}
		if ( $raw_slug !== $slug || ! preg_match( '/^[a-z0-9_-]{1,32}$/', $slug ) ) {
			throw Errors::invariant( 'Taxonomy slug must use 1-32 lowercase letters, numbers, dashes, or underscores.' );
		}

		$origin = is_string( $input['origin'] ?? null ) ? $input['origin'] : 'custom';
		if ( 'builtin' === $origin ) {
			$origin = 'builtin-override';
		} elseif ( 'external' === $origin ) {
			$origin = 'external-override';
		}
		if ( ! in_array( $origin, array( 'custom', 'builtin-override', 'external-override' ), true ) ) {
			$origin = $this->registered_origin_for_slug( $slug );
		}

		return $this->normalize_stored_row(
			array(
				'slug'              => $slug,
				'origin'            => $origin,
				'label'             => trim( (string) ( $input['label'] ?? $slug ) ),
				'plural'            => trim( (string) ( $input['plural'] ?? '' ) ),
				'description'       => trim( (string) ( $input['description'] ?? '' ) ),
				'labels'            => $this->allowed_string_map( $input['labels'] ?? array(), self::LABEL_KEYS ),
				'objectTypes'       => $this->string_list( $input['objectTypes'] ?? array() ),
				'public'            => true === ( $input['public'] ?? true ),
				'publiclyQueryable' => true === ( $input['publiclyQueryable'] ?? ( $input['public'] ?? true ) ),
				'hierarchical'      => true === ( $input['hierarchical'] ?? false ),
				'showUi'            => true === ( $input['showUi'] ?? true ),
				'showInMenu'        => true === ( $input['showInMenu'] ?? true ),
				'showInNavMenus'    => true === ( $input['showInNavMenus'] ?? true ),
				'showAdminColumn'   => true === ( $input['showAdminColumn'] ?? true ),
				'showInQuickEdit'   => true === ( $input['showInQuickEdit'] ?? true ),
				'showTagcloud'      => true === ( $input['showTagcloud'] ?? true ),
				'showInRest'        => true === ( $input['showInRest'] ?? true ),
				'restBase'          => trim( (string) ( $input['restBase'] ?? '' ) ),
				'restNamespace'     => trim( (string) ( $input['restNamespace'] ?? 'wp/v2' ) ),
				'restControllerClass' => trim( (string) ( $input['restControllerClass'] ?? '' ) ),
				'rewrite'           => true === ( $input['rewrite'] ?? true ),
				'rewriteOptions'    => $this->rewrite_options_row( $input['rewriteOptions'] ?? array(), $slug ),
				'queryVar'          => true === ( $input['queryVar'] ?? true ),
				'queryVarName'      => trim( (string) ( $input['queryVarName'] ?? '' ) ),
				'defaultTerm'       => true === ( $input['defaultTerm'] ?? false ),
				'defaultTermSpec'   => $this->default_term_row( $input['defaultTermSpec'] ?? array() ),
				'sort'              => $this->tri_state_value( $input['sort'] ?? 'inherit' ),
				'capabilities'      => $this->allowed_string_map( $input['capabilities'] ?? array(), self::CAPABILITY_KEYS ),
				'args'              => $this->query_args_map( $input['args'] ?? array() ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function normalize_stored_row( array $row ): array {
		$slug  = $this->sanitize_slug( (string) ( $row['slug'] ?? '' ) );
		$label = trim( (string) ( $row['label'] ?? $slug ) );

		return array(
			'slug'                => $slug,
			'origin'              => $this->origin_value( $row['origin'] ?? 'custom' ),
			'label'               => '' === $label ? $slug : $label,
			'plural'              => trim( (string) ( $row['plural'] ?? ( $row['pluralLabel'] ?? $label ) ) ),
			'description'         => trim( (string) ( $row['description'] ?? '' ) ),
			'labels'              => $this->allowed_string_map( $row['labels'] ?? array(), self::LABEL_KEYS ),
			'objectTypes'         => $this->string_list( $row['objectTypes'] ?? array() ),
			'public'              => true === ( $row['public'] ?? true ),
			'publiclyQueryable'   => true === ( $row['publiclyQueryable'] ?? ( $row['public'] ?? true ) ),
			'hierarchical'        => true === ( $row['hierarchical'] ?? false ),
			'showUi'              => true === ( $row['showUi'] ?? true ),
			'showInMenu'          => true === ( $row['showInMenu'] ?? true ),
			'showInNavMenus'      => true === ( $row['showInNavMenus'] ?? true ),
			'showAdminColumn'     => true === ( $row['showAdminColumn'] ?? true ),
			'showInQuickEdit'     => true === ( $row['showInQuickEdit'] ?? true ),
			'showTagcloud'        => true === ( $row['showTagcloud'] ?? true ),
			'showInRest'          => true === ( $row['showInRest'] ?? true ),
			'restBase'            => trim( (string) ( $row['restBase'] ?? '' ) ),
			'restNamespace'       => trim( (string) ( $row['restNamespace'] ?? 'wp/v2' ) ),
			'restControllerClass' => trim( (string) ( $row['restControllerClass'] ?? '' ) ),
			'rewrite'             => is_bool( $row['rewrite'] ?? null ) ? (bool) $row['rewrite'] : true,
			'rewriteOptions'      => $this->rewrite_options_row( $row['rewriteOptions'] ?? ( is_array( $row['rewrite'] ?? null ) ? $row['rewrite'] : array() ), $slug ),
			'queryVar'            => true === ( $row['queryVar'] ?? true ),
			'queryVarName'        => trim( (string) ( $row['queryVarName'] ?? '' ) ),
			'defaultTerm'         => true === ( $row['defaultTerm'] ?? false ),
			'defaultTermSpec'     => $this->default_term_row( $row['defaultTermSpec'] ?? ( $row['defaultTerm'] ?? array() ) ),
			'sort'                => $this->tri_state_value( $row['sort'] ?? 'inherit' ),
			'capabilities'        => $this->allowed_string_map( $row['capabilities'] ?? array(), self::CAPABILITY_KEYS ),
			'args'                => $this->query_args_map( $row['args'] ?? array() ),
		);
	}

	/**
	 * @return array<string,object>
	 */
	private function registered_taxonomies(): array {
		if ( ! function_exists( 'get_taxonomies' ) ) {
			return array(
				'category' => (object) array(
					'name'          => 'category',
					'label'         => 'Categories',
					'_builtin'      => true,
					'public'        => true,
					'hierarchical'  => true,
					'object_type'   => array( 'post' ),
				),
				'post_tag' => (object) array(
					'name'          => 'post_tag',
					'label'         => 'Tags',
					'_builtin'      => true,
					'public'        => true,
					'hierarchical'  => false,
					'object_type'   => array( 'post' ),
				),
			);
		}

		$objects = \get_taxonomies( array(), 'objects' );
		$rows    = array();
		foreach ( $objects as $name => $object ) {
			$slug = is_string( $name ) ? $name : (string) ( $object->name ?? '' );
			if ( '' !== $slug && is_object( $object ) ) {
				$rows[ $slug ] = $object;
			}
		}

		return $rows;
	}

	/**
	 * @return array<string,object>
	 */
	private function registered_post_types(): array {
		$fallback = array(
			'post' => (object) array(
				'name' => 'post',
				'label' => 'Posts',
				'_builtin' => true,
			),
			'page' => (object) array(
				'name' => 'page',
				'label' => 'Pages',
				'_builtin' => true,
			),
		);
		if ( ! function_exists( 'get_post_types' ) ) {
			return $fallback;
		}

		$objects = \get_post_types( array(), 'objects' );
		$rows    = array();
		foreach ( $objects as $name => $object ) {
			$slug = is_string( $name ) ? $name : (string) ( $object->name ?? '' );
			if ( '' !== $slug && is_object( $object ) ) {
				$rows[ $slug ] = $object;
			}
		}

		return array() === $rows ? $fallback : $rows;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row_from_registered( string $slug, object $object ): array {
		$labels  = is_object( $object->labels ?? null ) ? get_object_vars( $object->labels ) : array();
		$rewrite = is_array( $object->rewrite ?? null ) ? $object->rewrite : array();

		return array(
			'slug'                => $slug,
			'origin'              => true === ( $object->_builtin ?? false ) || in_array( $slug, self::BUILTIN_TAXONOMIES, true ) ? 'builtin' : 'external',
			'label'               => $this->string_or_default( $object->label ?? null, $slug ),
			'plural'              => $this->string_or_default( $labels['name'] ?? null, $this->string_or_default( $object->label ?? null, $slug ) ),
			'description'         => $this->string_or_default( $object->description ?? null, '' ),
			'labels'              => $labels,
			'objectTypes'         => $this->string_list( $object->object_type ?? array() ),
			'public'              => true === ( $object->public ?? false ),
			'publiclyQueryable'   => true === ( $object->publicly_queryable ?? ( $object->public ?? false ) ),
			'hierarchical'        => true === ( $object->hierarchical ?? false ),
			'showUi'              => true === ( $object->show_ui ?? false ),
			'showInMenu'          => true === ( $object->show_in_menu ?? false ),
			'showInNavMenus'      => true === ( $object->show_in_nav_menus ?? false ),
			'showAdminColumn'     => true === ( $object->show_admin_column ?? false ),
			'showInQuickEdit'     => true === ( $object->show_in_quick_edit ?? false ),
			'showTagcloud'        => true !== ( $object->show_tagcloud ?? true ) ? false : true,
			'showInRest'          => true === ( $object->show_in_rest ?? false ),
			'restBase'            => $this->string_or_default( $object->rest_base ?? null, '' ),
			'restNamespace'       => $this->string_or_default( $object->rest_namespace ?? null, 'wp/v2' ),
			'restControllerClass' => $this->string_or_default( $object->rest_controller_class ?? null, '' ),
			'rewrite'             => false !== ( $object->rewrite ?? false ),
			'rewriteOptions'      => $rewrite,
			'queryVar'            => false !== ( $object->query_var ?? true ),
			'queryVarName'        => is_string( $object->query_var ?? null ) ? $object->query_var : '',
			'defaultTerm'         => is_array( $object->default_term ?? null ),
			'defaultTermSpec'     => is_array( $object->default_term ?? null ) ? $object->default_term : array(),
			'sort'                => true === ( $object->sort ?? null ) ? 'enable' : ( false === ( $object->sort ?? null ) ? 'disable' : 'inherit' ),
			'capabilities'        => is_array( $object->cap ?? null ) ? $this->capability_map_from_object( $object->cap ) : array(),
			'args'                => is_array( $object->args ?? null ) ? $object->args : array(),
		);
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function decorate_row( array $row ): array {
		$row    = $this->normalize_stored_row( $row );
		$origin = $row['origin'];
		$row['objectTypes']      = $this->object_types_for( $row );
		$row['originLabel']      = $this->origin_label( $origin );
		$row['hierarchicalLabel'] = true === $row['hierarchical'] ? 'Yes' : 'No';
		$row['publicLabel']      = true === $row['public'] ? 'Yes' : 'No';
		$row['objectTypesLabel'] = implode( ', ', $row['objectTypes'] );
		$row['canDelete']        = 'custom' === $origin;
		return $row;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return list<string>
	 */
	private function object_types_for( array $row ): array {
		$slug  = is_string( $row['slug'] ?? null ) ? $row['slug'] : '';
		$types = array_merge( $this->string_list( $row['objectTypes'] ?? array() ), $this->post_type_declared_object_types( $slug ) );
		return array_values( array_unique( $types ) );
	}

	/**
	 * @return list<string>
	 */
	private function post_type_declared_object_types( string $taxonomy ): array {
		$post_types = ( new ModuleSettingsRepository() )->stored_settings_by_module_name( 'onumia/post-types' );
		$types      = $post_types['types'] ?? null;
		if ( ! is_array( $types ) ) {
			return array();
		}

		$object_types = array();
		foreach ( $types as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['slug'] ?? null ) ) {
				continue;
			}
			if ( in_array( $taxonomy, $this->string_list( $row['taxonomies'] ?? array() ), true ) ) {
				$object_types[] = $this->sanitize_slug( $row['slug'] );
			}
		}

		return array_values( array_filter( $object_types ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $custom Stored custom rows keyed by slug.
	 */
	private function assert_slug_can_be_saved( string $slug, string $original_slug, array $custom ): void {
		if ( '' === $original_slug ) {
			if ( isset( $custom[ $slug ] ) || isset( $this->registered_taxonomies()[ $slug ] ) ) {
				throw Errors::invariant( "Taxonomy slug {$slug} already exists." );
			}
			return;
		}
		if ( $original_slug === $slug ) {
			return;
		}

		$original_row    = $custom[ $original_slug ] ?? null;
		$original_origin = is_array( $original_row ) ? (string) ( $original_row['origin'] ?? 'custom' ) : $this->registered_origin_for_slug( $original_slug );
		if ( 'custom' !== $original_origin ) {
			throw Errors::invariant( "Taxonomy {$original_slug} is not owned by Onumia and cannot change slug." );
		}
		if ( isset( $custom[ $slug ] ) || isset( $this->registered_taxonomies()[ $slug ] ) ) {
			throw Errors::invariant( "Taxonomy slug {$slug} already exists." );
		}
	}

	private function registered_origin_for_slug( string $slug ): string {
		$registered = $this->registered_taxonomies()[ $slug ] ?? null;
		if ( null === $registered ) {
			return 'custom';
		}

		return true === ( $registered->_builtin ?? false ) || in_array( $slug, self::BUILTIN_TAXONOMIES, true ) ? 'builtin-override' : 'external-override';
	}

	/**
	 * @param callable(array<string,array<string,mixed>>,array<string,array<string,mixed>>):array{custom:array<string,array<string,mixed>>,overrides:array<string,array<string,mixed>>} $updater Rows updater.
	 */
	private function persist_rows( callable $updater ): void {
		( new ModuleSettingsRepository() )->update_settings_with(
			$this->definition(),
			function ( array $settings ) use ( $updater ): array {
				$custom    = $this->stored_custom_rows_by_slug_from( $settings['taxonomies'] ?? array() );
				$overrides = $this->stored_overrides_by_slug_from( $settings['overrides'] ?? array() );
				$updated   = $updater( $custom, $overrides );
				$overrides = $updated['overrides'];
				ksort( $overrides );

				return array(
					'taxonomies' => array_values( $updated['custom'] ),
					'overrides'  => $overrides,
				);
			}
		);
	}

	private function flush_rewrites(): void {
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			\flush_rewrite_rules( false );
		}
	}

	private function sanitize_slug( string $slug ): string {
		return function_exists( 'sanitize_key' ) ? \sanitize_key( $slug ) : strtolower( preg_replace( '/[^a-z0-9_-]+/', '', $slug ) ?? '' );
	}

	private function origin_value( mixed $origin ): string {
		return in_array( $origin, array( 'builtin', 'builtin-override', 'external', 'external-override', 'custom' ), true ) ? (string) $origin : 'custom';
	}

	private function origin_label( string $origin ): string {
		return match ( $origin ) {
			'builtin' => 'Built-in',
			'builtin-override' => 'Built-in override',
			'external' => 'External',
			'external-override' => 'External override',
			default => 'Custom',
		};
	}

	/**
	 * @return array<string,string>
	 */
	private function string_map( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$map = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && is_scalar( $item ) && '' !== trim( (string) $item ) ) {
				$map[ $key ] = trim( (string) $item );
			}
		}

		return $map;
	}

	/**
	 * @param list<string> $allowed Allowed keys.
	 * @return array<string,string>
	 */
	private function allowed_string_map( mixed $value, array $allowed ): array {
		return array_intersect_key( $this->string_map( $value ), array_fill_keys( $allowed, true ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function query_args_map( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$allowed = array_fill_keys( self::QUERY_ARG_KEYS, true );
		$map     = array();
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) || ! isset( $allowed[ $key ] ) ) {
				continue;
			}
			if ( is_scalar( $item ) ) {
				$map[ $key ] = $item;
			}
		}

		return $map;
	}

	/**
	 * @return array{slug:string,withFront:bool,hierarchical:bool,epMask:int}
	 */
	private function rewrite_options_row( mixed $value, string $slug ): array {
		$rewrite = is_array( $value ) ? $value : array();
		return array(
			'slug'         => $this->sanitize_slug( is_string( $rewrite['slug'] ?? null ) && '' !== $rewrite['slug'] ? $rewrite['slug'] : $slug ),
			'withFront'    => true === ( $rewrite['withFront'] ?? ( $rewrite['with_front'] ?? true ) ),
			'hierarchical' => true === ( $rewrite['hierarchical'] ?? false ),
			'epMask'       => max( 0, (int) ( $rewrite['epMask'] ?? ( $rewrite['ep_mask'] ?? 0 ) ) ),
		);
	}

	/**
	 * @param array{slug:string,withFront:bool,hierarchical:bool,epMask:int} $rewrite Rewrite options.
	 * @return array<string,mixed>
	 */
	private function rewrite_args( array $rewrite ): array {
		$args = array(
			'slug'         => $rewrite['slug'],
			'with_front'   => $rewrite['withFront'],
			'hierarchical' => $rewrite['hierarchical'],
		);
		if ( 0 < $rewrite['epMask'] ) {
			$args['ep_mask'] = $rewrite['epMask'];
		}

		return $args;
	}

	/**
	 * @return array{name:string,slug:string,description:string}
	 */
	private function default_term_row( mixed $value ): array {
		$term = is_array( $value ) ? $value : array();
		return array(
			'name'        => trim( (string) ( $term['name'] ?? '' ) ),
			'slug'        => $this->sanitize_slug( (string) ( $term['slug'] ?? '' ) ),
			'description' => trim( (string) ( $term['description'] ?? '' ) ),
		);
	}

	private function tri_state_value( mixed $value ): string {
		if ( true === $value ) {
			return 'enable';
		}
		if ( false === $value ) {
			return 'disable';
		}
		return in_array( $value, array( 'inherit', 'enable', 'disable' ), true ) ? (string) $value : 'inherit';
	}

	/**
	 * @return array<string,string>
	 */
	private function capability_map_from_object( object $capabilities ): array {
		$map = array();
		foreach ( self::CAPABILITY_KEYS as $key ) {
			$value = $capabilities->$key ?? null;
			if ( is_string( $value ) && '' !== $value ) {
				$map[ $key ] = $value;
			}
		}

		return $map;
	}

	private function string_or_default( mixed $value, string $default ): string {
		return is_string( $value ) && '' !== $value ? $value : $default;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function object_setting( string $key ): array {
		$value = $this->setting( $key );
		if ( ! is_array( $value ) ) {
			throw Errors::invariant( "Setting {$key} must be object." );
		}

		$object = array();
		foreach ( $value as $item_key => $item_value ) {
			if ( is_string( $item_key ) ) {
				$object[ $item_key ] = $item_value;
			}
		}

		return $object;
	}
}
