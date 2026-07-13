<?php
/**
 * Post Types module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\PostTypes;

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
#[Setting( 'types', SettingType::Array, default: array() )]
final class PostTypes extends Module {
	private const BUILTIN_POST_TYPES = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );
	private const DEFAULT_SUPPORTS   = array( 'title', 'editor' );
	private const POST_LABEL_KEYS    = array(
		'name',
		'singular_name',
		'menu_name',
		'all_items',
		'add_new',
		'add_new_item',
		'edit_item',
		'new_item',
		'view_item',
		'view_items',
		'search_items',
		'not_found',
		'not_found_in_trash',
		'parent_item_colon',
		'archives',
		'attributes',
		'insert_into_item',
		'uploaded_to_this_item',
		'featured_image',
		'set_featured_image',
		'remove_featured_image',
		'use_featured_image',
		'filter_items_list',
		'filter_by_date',
		'items_list',
		'items_list_navigation',
		'item_published',
		'item_published_privately',
		'item_reverted_to_draft',
		'item_trashed',
		'item_scheduled',
		'item_updated',
		'item_link',
		'item_link_description',
	);
	private const CAPABILITY_KEYS    = array(
		'edit_post',
		'read_post',
		'delete_post',
		'edit_posts',
		'edit_others_posts',
		'publish_posts',
		'read_private_posts',
		'delete_posts',
		'delete_private_posts',
		'delete_published_posts',
		'delete_others_posts',
		'edit_private_posts',
		'edit_published_posts',
		'create_posts',
		'read',
	);

	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'postTypes', shape: DataSourceShape::Collection, pagination: PaginationMode::Client )]
	#[Entries( name: 'types', singular: 'Post type', plural: 'Post types', key: 'slug', storage: EntryStorage::Manual, source: 'postTypes', create_action: 'savePostType', update_action: 'savePostType', delete_action: 'deletePostTypes' )]
	#[EntrySection( name: 'identity', label: 'Identity', description: 'Core post type identity.', order: 10, layout: 'tabs' )]
	#[EntrySection( name: 'labels', label: 'Labels', description: 'Admin labels exposed by WordPress.', order: 20, layout: 'tabs' )]
	#[EntrySection( name: 'visibility', label: 'Visibility', description: 'Admin and public visibility flags.', order: 30, layout: 'tabs' )]
	#[EntrySection( name: 'features', label: 'Features', description: 'Editor supports for the post type.', order: 40, layout: 'tabs' )]
	#[EntrySection( name: 'archive', label: 'Archive & URL', description: 'Archive, rewrite, and hierarchy settings.', order: 50, layout: 'tabs' )]
	#[EntrySection( name: 'taxonomies', label: 'Taxonomies', description: 'Existing taxonomies attached to the post type.', order: 60, layout: 'tabs' )]
	#[EntrySection( name: 'rest', label: 'REST', description: 'REST API exposure and route names.', order: 70, layout: 'tabs' )]
	#[EntrySection( name: 'capabilities', label: 'Capabilities', description: 'Capability mapping for custom post types.', order: 80, layout: 'tabs' )]
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
			'confirmChangeDescription' => 'Renaming a registered post type can orphan content using the old slug. Confirm only after existing content has been migrated or the old slug remains registered.',
			'confirmChangeLabel'       => 'Rename slug',
			'confirmChangeTitle'       => 'Rename post type slug?',
			'confirmOnChange'          => true,
			'lockedHelpText'           => 'Built-in and external post type slugs are owned by WordPress or another plugin and cannot be changed here.',
			'lockedOrigins'            => array( 'builtin', 'builtin-override', 'external', 'external-override' ),
			'mutablePrimary'           => true,
			'originalInput'            => 'originalSlug',
		)
	)]
	#[EntryField( name: 'origin', type: SettingType::String, label: 'Origin', default: 'custom', allowed: array( 'builtin', 'builtin-override', 'external', 'external-override', 'custom' ), create: false, update: true, read_only: true, section: 'identity', order: 25 )]
	#[EntryField( name: 'originLabel', type: SettingType::String, label: 'Origin', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'identity', order: 30 )]
	#[EntryField( name: 'pluralLabel', type: SettingType::String, label: 'Plural label', section: 'identity', order: 40 )]
	#[EntryField( name: 'description', type: SettingType::String, label: 'Description', section: 'identity', order: 50, props: array( 'multiline' => true ) )]
	#[EntryField( name: 'menuIcon', type: SettingType::String, label: 'Menu icon', default: 'dashicons-admin-post', section: 'identity', order: 60 )]
	#[EntryField( name: 'menuPosition', type: SettingType::Integer, label: 'Menu position', min: 1, section: 'identity', order: 70 )]
	#[EntryField( name: 'labels.name', type: SettingType::String, label: 'Name', section: 'labels', order: 10 )]
	#[EntryField( name: 'labels.singular_name', type: SettingType::String, label: 'Singular name', section: 'labels', order: 20 )]
	#[EntryField( name: 'labels.menu_name', type: SettingType::String, label: 'Menu name', section: 'labels', order: 30 )]
	#[EntryField( name: 'labels.all_items', type: SettingType::String, label: 'All items', section: 'labels', order: 40 )]
	#[EntryField( name: 'labels.add_new', type: SettingType::String, label: 'Add new', section: 'labels', order: 50 )]
	#[EntryField( name: 'labels.add_new_item', type: SettingType::String, label: 'Add new item', section: 'labels', order: 60 )]
	#[EntryField( name: 'labels.edit_item', type: SettingType::String, label: 'Edit item', section: 'labels', order: 70 )]
	#[EntryField( name: 'labels.new_item', type: SettingType::String, label: 'New item', section: 'labels', order: 80 )]
	#[EntryField( name: 'labels.view_item', type: SettingType::String, label: 'View item', section: 'labels', order: 90 )]
	#[EntryField( name: 'labels.view_items', type: SettingType::String, label: 'View items', section: 'labels', order: 100 )]
	#[EntryField( name: 'labels.search_items', type: SettingType::String, label: 'Search items', section: 'labels', order: 110 )]
	#[EntryField( name: 'labels.not_found', type: SettingType::String, label: 'Not found', section: 'labels', order: 120 )]
	#[EntryField( name: 'labels.not_found_in_trash', type: SettingType::String, label: 'Not found in trash', section: 'labels', order: 130 )]
	#[EntryField( name: 'labels.parent_item_colon', type: SettingType::String, label: 'Parent item colon', section: 'labels', order: 140 )]
	#[EntryField( name: 'labels.archives', type: SettingType::String, label: 'Archives', section: 'labels', order: 150 )]
	#[EntryField( name: 'labels.attributes', type: SettingType::String, label: 'Attributes', section: 'labels', order: 160 )]
	#[EntryField( name: 'labels.insert_into_item', type: SettingType::String, label: 'Insert into item', section: 'labels', order: 170 )]
	#[EntryField( name: 'labels.uploaded_to_this_item', type: SettingType::String, label: 'Uploaded to this item', section: 'labels', order: 180 )]
	#[EntryField( name: 'labels.featured_image', type: SettingType::String, label: 'Featured image', section: 'labels', order: 190 )]
	#[EntryField( name: 'labels.set_featured_image', type: SettingType::String, label: 'Set featured image', section: 'labels', order: 200 )]
	#[EntryField( name: 'labels.remove_featured_image', type: SettingType::String, label: 'Remove featured image', section: 'labels', order: 210 )]
	#[EntryField( name: 'labels.use_featured_image', type: SettingType::String, label: 'Use featured image', section: 'labels', order: 220 )]
	#[EntryField( name: 'labels.filter_items_list', type: SettingType::String, label: 'Filter items list', section: 'labels', order: 230 )]
	#[EntryField( name: 'labels.filter_by_date', type: SettingType::String, label: 'Filter by date', section: 'labels', order: 240 )]
	#[EntryField( name: 'labels.items_list', type: SettingType::String, label: 'Items list', section: 'labels', order: 250 )]
	#[EntryField( name: 'labels.items_list_navigation', type: SettingType::String, label: 'Items list navigation', section: 'labels', order: 260 )]
	#[EntryField( name: 'labels.item_published', type: SettingType::String, label: 'Item published', section: 'labels', order: 270 )]
	#[EntryField( name: 'labels.item_published_privately', type: SettingType::String, label: 'Item published privately', section: 'labels', order: 280 )]
	#[EntryField( name: 'labels.item_reverted_to_draft', type: SettingType::String, label: 'Item reverted to draft', section: 'labels', order: 290 )]
	#[EntryField( name: 'labels.item_trashed', type: SettingType::String, label: 'Item trashed', section: 'labels', order: 300 )]
	#[EntryField( name: 'labels.item_scheduled', type: SettingType::String, label: 'Item scheduled', section: 'labels', order: 310 )]
	#[EntryField( name: 'labels.item_updated', type: SettingType::String, label: 'Item updated', section: 'labels', order: 320 )]
	#[EntryField( name: 'labels.item_link', type: SettingType::String, label: 'Item link', section: 'labels', order: 330 )]
	#[EntryField( name: 'labels.item_link_description', type: SettingType::String, label: 'Item link description', section: 'labels', order: 340 )]
	#[EntryField( name: 'public', type: SettingType::Boolean, label: 'Public', default: true, section: 'visibility', order: 10 )]
	#[EntryField( name: 'publicLabel', type: SettingType::String, label: 'Public', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'visibility', order: 20 )]
	#[EntryField( name: 'publiclyQueryable', type: SettingType::Boolean, label: 'Publicly queryable', default: true, section: 'visibility', order: 30 )]
	#[EntryField( name: 'showUi', type: SettingType::Boolean, label: 'Show UI', default: true, section: 'visibility', order: 40 )]
	#[EntryField( name: 'showInMenu', type: SettingType::Boolean, label: 'Show in menu', default: true, section: 'visibility', order: 50 )]
	#[EntryField(
		name: 'showInMenuParent',
		type: SettingType::String,
		label: 'Parent menu slug',
		section: 'visibility',
		order: 60,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.showInMenu' ),
			'right' => true,
		)
	)]
	#[EntryField( name: 'showInNavMenus', type: SettingType::Boolean, label: 'Show in nav menus', default: true, section: 'visibility', order: 70 )]
	#[EntryField( name: 'showInAdminBar', type: SettingType::Boolean, label: 'Show in admin bar', default: true, section: 'visibility', order: 80 )]
	#[EntryField( name: 'excludeFromSearch', type: SettingType::Boolean, label: 'Exclude from search', default: false, section: 'visibility', order: 90 )]
	#[EntryField(
		name: 'supports',
		type: SettingType::Array,
		label: 'Supports',
		default: array( 'title', 'editor' ),
		options: array(
			array(
				'value' => 'title',
				'label' => 'Title',
			),
			array(
				'value' => 'editor',
				'label' => 'Editor',
			),
			array(
				'value' => 'thumbnail',
				'label' => 'Featured image',
			),
			array(
				'value' => 'excerpt',
				'label' => 'Excerpt',
			),
			array(
				'value' => 'comments',
				'label' => 'Comments',
			),
			array(
				'value' => 'author',
				'label' => 'Author',
			),
			array(
				'value' => 'custom-fields',
				'label' => 'Custom fields',
			),
			array(
				'value' => 'revisions',
				'label' => 'Revisions',
			),
			array(
				'value' => 'page-attributes',
				'label' => 'Page attributes',
			),
			array(
				'value' => 'trackbacks',
				'label' => 'Trackbacks',
			),
			array(
				'value' => 'post-formats',
				'label' => 'Post formats',
			),
			array(
				'value' => 'autosave',
				'label' => 'Autosave',
			),
		),
		section: 'features',
		order: 10
	)]
	#[EntryField( name: 'hasArchive', type: SettingType::Boolean, label: 'Has archive', default: true, section: 'archive', order: 10 )]
	#[EntryField(
		name: 'hasArchiveSlug',
		type: SettingType::String,
		label: 'Archive slug',
		section: 'archive',
		order: 20,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.hasArchive' ),
			'right' => true,
		)
	)]
	#[EntryField( name: 'rewrite', type: SettingType::Boolean, label: 'Rewrite', default: true, section: 'archive', order: 30 )]
	#[EntryField(
		name: 'rewriteOptions.slug',
		type: SettingType::String,
		label: 'Rewrite slug',
		section: 'archive',
		order: 40,
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
		section: 'archive',
		order: 50,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.rewrite' ),
			'right' => true,
		)
	)]
	#[EntryField(
		name: 'rewriteOptions.feeds',
		type: SettingType::Boolean,
		label: 'Feeds',
		default: true,
		section: 'archive',
		order: 60,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.rewrite' ),
			'right' => true,
		)
	)]
	#[EntryField(
		name: 'rewriteOptions.pages',
		type: SettingType::Boolean,
		label: 'Pages',
		default: true,
		section: 'archive',
		order: 70,
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
		section: 'archive',
		order: 80,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.rewrite' ),
			'right' => true,
		)
	)]
	#[EntryField( name: 'queryVar', type: SettingType::Boolean, label: 'Query var', default: true, section: 'archive', order: 90 )]
	#[EntryField(
		name: 'queryVarName',
		type: SettingType::String,
		label: 'Query var name',
		section: 'archive',
		order: 100,
		visible_when: array(
			'op' => 'equals',
			'left' => array( 'ref' => 'form.queryVar' ),
			'right' => true,
		)
	)]
	#[EntryField( name: 'hierarchical', type: SettingType::Boolean, label: 'Hierarchical', default: false, section: 'archive', order: 110 )]
	#[EntryField( name: 'canExport', type: SettingType::Boolean, label: 'Can export', default: true, section: 'archive', order: 120 )]
	#[EntryField(
		name: 'deleteWithUser',
		type: SettingType::String,
		label: 'Delete with user',
		default: 'inherit',
		allowed: array( 'inherit', 'yes', 'no' ),
		options: array(
			array(
				'value' => 'inherit',
				'label' => 'Inherit',
			),
			array(
				'value' => 'yes',
				'label' => 'Yes',
			),
			array(
				'value' => 'no',
				'label' => 'No',
			),
		),
		section: 'archive',
		order: 130
	)]
	#[EntryField(
		name: 'templateLock',
		type: SettingType::String,
		label: 'Template lock',
		default: 'none',
		allowed: array( 'none', 'all', 'insert' ),
		options: array(
			array(
				'value' => 'none',
				'label' => 'None',
			),
			array(
				'value' => 'all',
				'label' => 'All',
			),
			array(
				'value' => 'insert',
				'label' => 'Insert',
			),
		),
		section: 'features',
		order: 20
	)]
	#[EntryField( name: 'taxonomies', type: SettingType::Array, label: 'Taxonomies', default: array(), optionsSource: array( 'source' => 'taxonomies' ), section: 'taxonomies', order: 10 )]
	#[EntryField( name: 'showInRest', type: SettingType::Boolean, label: 'Show in REST', default: true, section: 'rest', order: 10 )]
	#[EntryField( name: 'restLabel', type: SettingType::String, label: 'REST', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'rest', order: 20 )]
	#[EntryField( name: 'restBase', type: SettingType::String, label: 'REST base', section: 'rest', order: 30 )]
	#[EntryField( name: 'restNamespace', type: SettingType::String, label: 'REST namespace', default: 'wp/v2', section: 'rest', order: 40 )]
	#[EntryField( name: 'restControllerClass', type: SettingType::String, label: 'REST controller class', section: 'rest', order: 50 )]
	#[EntryField( name: 'capabilityType', type: SettingType::String, label: 'Capability type', default: 'post', section: 'capabilities', order: 10 )]
	#[EntryField( name: 'capabilityTypePlural', type: SettingType::String, label: 'Capability type plural', section: 'capabilities', order: 20 )]
	#[EntryField(
		name: 'mapMetaCap',
		type: SettingType::String,
		label: 'Map meta cap',
		default: 'enable',
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
		section: 'capabilities',
		order: 30
	)]
	#[EntryField( name: 'capabilities.edit_post', type: SettingType::String, label: 'Edit post', section: 'capabilities', order: 40 )]
	#[EntryField( name: 'capabilities.read_post', type: SettingType::String, label: 'Read post', section: 'capabilities', order: 50 )]
	#[EntryField( name: 'capabilities.delete_post', type: SettingType::String, label: 'Delete post', section: 'capabilities', order: 60 )]
	#[EntryField( name: 'capabilities.edit_posts', type: SettingType::String, label: 'Edit posts', section: 'capabilities', order: 70 )]
	#[EntryField( name: 'capabilities.edit_others_posts', type: SettingType::String, label: 'Edit others posts', section: 'capabilities', order: 80 )]
	#[EntryField( name: 'capabilities.publish_posts', type: SettingType::String, label: 'Publish posts', section: 'capabilities', order: 90 )]
	#[EntryField( name: 'capabilities.read_private_posts', type: SettingType::String, label: 'Read private posts', section: 'capabilities', order: 100 )]
	#[EntryField( name: 'capabilities.delete_posts', type: SettingType::String, label: 'Delete posts', section: 'capabilities', order: 110 )]
	#[EntryField( name: 'capabilities.delete_private_posts', type: SettingType::String, label: 'Delete private posts', section: 'capabilities', order: 120 )]
	#[EntryField( name: 'capabilities.delete_published_posts', type: SettingType::String, label: 'Delete published posts', section: 'capabilities', order: 130 )]
	#[EntryField( name: 'capabilities.delete_others_posts', type: SettingType::String, label: 'Delete others posts', section: 'capabilities', order: 140 )]
	#[EntryField( name: 'capabilities.edit_private_posts', type: SettingType::String, label: 'Edit private posts', section: 'capabilities', order: 150 )]
	#[EntryField( name: 'capabilities.edit_published_posts', type: SettingType::String, label: 'Edit published posts', section: 'capabilities', order: 160 )]
	#[EntryField( name: 'capabilities.create_posts', type: SettingType::String, label: 'Create posts', section: 'capabilities', order: 170 )]
	#[EntryField( name: 'capabilities.read', type: SettingType::String, label: 'Read', section: 'capabilities', order: 180 )]
	public function post_types(): array {
		return $this->post_type_rows();
	}

	/**
	 * @return list<array{value:string,label:string}>
	 */
	#[DataSource( 'taxonomies', shape: DataSourceShape::Options )]
	public function taxonomies(): array {
		if ( ! function_exists( 'get_taxonomies' ) ) {
			return array();
		}

		$taxonomies = \get_taxonomies( array(), 'objects' );
		$options    = array();
		foreach ( $taxonomies as $name => $taxonomy ) {
			$key       = is_string( $name ) ? $name : (string) ( $taxonomy->name ?? '' );
			$label     = is_string( $taxonomy->label ?? null ) ? $taxonomy->label : $key;
			$options[] = array(
				'value' => $key,
				'label' => $label,
			);
		}

		usort( $options, static fn( array $a, array $b ): int => $a['label'] <=> $b['label'] );
		return $options;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{ok:bool,slug:string,origin:string}
	 */
	#[Action( 'savePostType' )]
	#[Input( 'slug', SettingType::String, required: true )]
	#[Input( 'originalSlug', SettingType::String, default: '' )]
	#[Input( 'origin', SettingType::String, default: 'custom' )]
	#[Input( 'label', SettingType::String, required: true )]
	#[Input( 'pluralLabel', SettingType::String, default: '' )]
	#[Input( 'description', SettingType::String, default: '' )]
	#[Input( 'menuIcon', SettingType::String, default: '' )]
	#[Input( 'menuPosition', SettingType::Integer, default: 0 )]
	#[Input( 'labels', SettingType::Object, default: array() )]
	#[Input( 'public', SettingType::Boolean, default: true )]
	#[Input( 'publiclyQueryable', SettingType::Boolean, default: true )]
	#[Input( 'showUi', SettingType::Boolean, default: true )]
	#[Input( 'showInMenu', SettingType::Boolean, default: true )]
	#[Input( 'showInMenuParent', SettingType::String, default: '' )]
	#[Input( 'showInNavMenus', SettingType::Boolean, default: true )]
	#[Input( 'showInAdminBar', SettingType::Boolean, default: true )]
	#[Input( 'excludeFromSearch', SettingType::Boolean, default: false )]
	#[Input( 'supports', SettingType::Array, default: array() )]
	#[Input( 'hasArchive', SettingType::Boolean, default: true )]
	#[Input( 'hasArchiveSlug', SettingType::String, default: '' )]
	#[Input( 'rewrite', SettingType::Boolean, default: true )]
	#[Input( 'rewriteOptions', SettingType::Object, default: array() )]
	#[Input( 'queryVar', SettingType::Boolean, default: true )]
	#[Input( 'queryVarName', SettingType::String, default: '' )]
	#[Input( 'hierarchical', SettingType::Boolean, default: false )]
	#[Input( 'canExport', SettingType::Boolean, default: true )]
	#[Input( 'deleteWithUser', SettingType::String, default: 'inherit', allowed: array( 'inherit', 'yes', 'no' ) )]
	#[Input( 'taxonomies', SettingType::Array, default: array() )]
	#[Input( 'showInRest', SettingType::Boolean, default: true )]
	#[Input( 'restBase', SettingType::String, default: '' )]
	#[Input( 'restNamespace', SettingType::String, default: 'wp/v2' )]
	#[Input( 'restControllerClass', SettingType::String, default: '' )]
	#[Input( 'capabilityType', SettingType::String, default: 'post' )]
	#[Input( 'capabilityTypePlural', SettingType::String, default: '' )]
	#[Input( 'capabilities', SettingType::Object, default: array() )]
	#[Input( 'mapMetaCap', SettingType::String, default: 'enable', allowed: array( 'inherit', 'enable', 'disable' ) )]
	#[Input( 'template', SettingType::Array, default: array() )]
	#[Input( 'templateLock', SettingType::String, default: 'none', allowed: array( 'none', 'all', 'insert' ) )]
	#[ObjectShape(
		name: 'labels',
		fields: array(
			'name' => 'string',
			'singular_name' => 'string',
			'menu_name' => 'string',
			'all_items' => 'string',
			'add_new' => 'string',
			'add_new_item' => 'string',
			'edit_item' => 'string',
			'new_item' => 'string',
			'view_item' => 'string',
			'view_items' => 'string',
			'search_items' => 'string',
			'not_found' => 'string',
			'not_found_in_trash' => 'string',
			'parent_item_colon' => 'string',
			'archives' => 'string',
			'attributes' => 'string',
			'insert_into_item' => 'string',
			'uploaded_to_this_item' => 'string',
			'featured_image' => 'string',
			'set_featured_image' => 'string',
			'remove_featured_image' => 'string',
			'use_featured_image' => 'string',
			'filter_items_list' => 'string',
			'filter_by_date' => 'string',
			'items_list' => 'string',
			'items_list_navigation' => 'string',
			'item_published' => 'string',
			'item_published_privately' => 'string',
			'item_reverted_to_draft' => 'string',
			'item_trashed' => 'string',
			'item_scheduled' => 'string',
			'item_updated' => 'string',
			'item_link' => 'string',
			'item_link_description' => 'string',
		)
	)]
	#[ObjectShape(
		name: 'rewriteOptions',
		fields: array(
			'slug' => 'string',
			'withFront' => 'boolean',
			'feeds' => 'boolean',
			'pages' => 'boolean',
			'epMask' => 'integer',
		)
	)]
	#[ObjectShape(
		name: 'capabilities',
		fields: array(
			'edit_post' => 'string',
			'read_post' => 'string',
			'delete_post' => 'string',
			'edit_posts' => 'string',
			'edit_others_posts' => 'string',
			'publish_posts' => 'string',
			'read_private_posts' => 'string',
			'delete_posts' => 'string',
			'delete_private_posts' => 'string',
			'delete_published_posts' => 'string',
			'delete_others_posts' => 'string',
			'edit_private_posts' => 'string',
			'edit_published_posts' => 'string',
			'create_posts' => 'string',
			'read' => 'string',
		)
	)]
	public function save_post_type( array $input ): array {
		$row           = $this->stored_row_from_input( $input );
		$original_slug = $this->sanitize_slug( (string) ( $input['originalSlug'] ?? '' ) );

		$this->persist_rows(
			function ( array $rows ) use ( $row, $original_slug ): array {
				$this->assert_slug_can_be_saved( $row['slug'], $original_slug, $rows );

				if ( '' !== $original_slug && $original_slug !== $row['slug'] ) {
					unset( $rows[ $original_slug ] );
				}

				$rows[ $row['slug'] ] = $row;
				return $rows;
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
	#[Action( 'deletePostTypes' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function delete_post_types( array $input ): array {
		$ids     = $this->string_list( $input['ids'] ?? array() );
		$deleted = array();

		$this->persist_rows(
			function ( array $rows ) use ( $ids, &$deleted ): array {
				foreach ( $ids as $slug ) {
					$row = $rows[ $slug ] ?? null;
					if ( ! is_array( $row ) ) {
						continue;
					}

					if ( 'custom' !== ( $row['origin'] ?? '' ) ) {
						throw Errors::invariant( "Post type {$slug} is not owned by Onumia and cannot be deleted." );
					}

					unset( $rows[ $slug ] );
					$deleted[] = $slug;
				}

				return $rows;
			}
		);
		$this->flush_rewrites();

		return array(
			'ok'      => true,
			'deleted' => $deleted,
		);
	}

	#[WpAction( 'init', priority: 9 )]
	public function register_custom_post_types(): void {
		foreach ( $this->stored_rows() as $row ) {
			if ( 'custom' !== ( $row['origin'] ?? '' ) ) {
				continue;
			}

			$slug = is_string( $row['slug'] ?? null ) ? $row['slug'] : '';
			if ( '' === $slug || ! function_exists( 'register_post_type' ) ) {
				continue;
			}

			\register_post_type( $slug, $this->args_for( $row ) );
		}
	}

	/**
	 * @param array<string,mixed> $args Existing post type args.
	 * @return array<string,mixed>
	 */
	#[WpFilter( 'register_post_type_args', priority: 99, accepted_args: 2 )]
	public function filter_post_type_args( array $args, string $post_type ): array {
		$row = $this->stored_rows_by_slug()[ $post_type ] ?? null;
		if ( ! is_array( $row ) || 'custom' === ( $row['origin'] ?? '' ) ) {
			return $args;
		}

		return array_merge( $args, $this->args_for( $row, $args ) );
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function post_type_rows(): array {
		$stored     = $this->stored_rows_by_slug();
		$registered = $this->registered_post_types();
		$rows       = array();

		foreach ( $registered as $slug => $object ) {
			$row        = $this->row_from_registered( $slug, $object );
			$stored_row = $stored[ $slug ] ?? null;
			if ( is_array( $stored_row ) ) {
				$row = array_merge( $row, $stored_row );
				if ( 'custom' !== ( $stored_row['origin'] ?? '' ) ) {
					$row['origin'] = true === ( $object->_builtin ?? false ) ? 'builtin-override' : 'external-override';
				}
			}
			$rows[ $slug ] = $this->decorate_row( $row );
		}

		foreach ( $stored as $slug => $stored_row ) {
			if ( isset( $rows[ $slug ] ) || ! is_array( $stored_row ) ) {
				continue;
			}

			$rows[ $slug ] = $this->decorate_row( $stored_row );
		}

		usort( $rows, static fn( array $a, array $b ): int => ( $a['label'] ?? $a['slug'] ?? '' ) <=> ( $b['label'] ?? $b['slug'] ?? '' ) );
		return array_values( $rows );
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function stored_rows(): array {
		return array_values( $this->stored_rows_by_slug() );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_rows_by_slug(): array {
		return $this->stored_rows_by_slug_from( $this->array_setting( 'types' ) );
	}

	/**
	 * @param mixed $value Stored row list.
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_rows_by_slug_from( mixed $value ): array {
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
			$rows[ $slug ] = $this->normalize_stored_row( $row );
		}

		return $rows;
	}

	/**
	 * @return array<string,object>
	 */
	private function registered_post_types(): array {
		if ( ! function_exists( 'get_post_types' ) ) {
			return array(
				'post' => (object) array(
					'name'     => 'post',
					'label'    => 'Posts',
					'_builtin' => true,
					'public'   => true,
					'show_ui'  => true,
					'supports' => self::DEFAULT_SUPPORTS,
				),
				'page' => (object) array(
					'name'         => 'page',
					'label'        => 'Pages',
					'_builtin'     => true,
					'public'       => true,
					'show_ui'      => true,
					'hierarchical' => true,
					'supports'     => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
				),
			);
		}

		$objects = \get_post_types( array(), 'objects' );
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
	 * @return array<string,mixed>
	 */
	private function row_from_registered( string $slug, object $object ): array {
		$labels  = is_object( $object->labels ?? null ) ? get_object_vars( $object->labels ) : array();
		$rewrite = is_array( $object->rewrite ?? null ) ? $object->rewrite : array();

		return array(
			'slug'              => $slug,
			'origin'            => true === ( $object->_builtin ?? false ) || in_array( $slug, self::BUILTIN_POST_TYPES, true ) ? 'builtin' : 'external',
			'label'             => $this->string_or_default( $object->label ?? null, $slug ),
			'pluralLabel'       => $this->string_or_default( $labels['name'] ?? null, $this->string_or_default( $object->label ?? null, $slug ) ),
			'description'       => $this->string_or_default( $object->description ?? null, '' ),
			'menuIcon'          => $this->string_or_default( $object->menu_icon ?? null, '' ),
			'menuPosition'      => is_int( $object->menu_position ?? null ) ? $object->menu_position : 0,
			'labels'            => $labels,
			'public'            => true === ( $object->public ?? false ),
			'publiclyQueryable' => true === ( $object->publicly_queryable ?? ( $object->public ?? false ) ),
			'showUi'            => true === ( $object->show_ui ?? false ),
			'showInMenu'        => false !== ( $object->show_in_menu ?? false ),
			'showInMenuParent'  => is_string( $object->show_in_menu ?? null ) ? $object->show_in_menu : '',
			'showInNavMenus'    => true === ( $object->show_in_nav_menus ?? false ),
			'showInAdminBar'    => true === ( $object->show_in_admin_bar ?? false ),
			'excludeFromSearch' => true === ( $object->exclude_from_search ?? false ),
			'supports'          => $this->post_type_supports( $slug, $object ),
			'hasArchive'        => false !== ( $object->has_archive ?? false ),
			'hasArchiveSlug'    => is_string( $object->has_archive ?? null ) ? $object->has_archive : '',
			'rewrite'           => false !== ( $object->rewrite ?? false ),
			'rewriteOptions'    => $rewrite,
			'queryVar'          => false !== ( $object->query_var ?? true ),
			'queryVarName'      => is_string( $object->query_var ?? null ) ? $object->query_var : '',
			'hierarchical'      => true === ( $object->hierarchical ?? false ),
			'canExport'         => true !== ( $object->can_export ?? true ) ? false : true,
			'deleteWithUser'    => $this->delete_with_user_value( $object->delete_with_user ?? null ),
			'taxonomies'        => $this->object_taxonomies( $slug ),
			'showInRest'        => true === ( $object->show_in_rest ?? false ),
			'restBase'          => $this->string_or_default( $object->rest_base ?? null, '' ),
			'restNamespace'     => $this->string_or_default( $object->rest_namespace ?? null, 'wp/v2' ),
			'restControllerClass' => $this->string_or_default( $object->rest_controller_class ?? null, '' ),
			'capabilityType'    => is_array( $object->capability_type ?? null ) ? (string) ( $object->capability_type[0] ?? 'post' ) : ( is_string( $object->capability_type ?? null ) ? $object->capability_type : 'post' ),
			'capabilityTypePlural' => is_array( $object->capability_type ?? null ) ? (string) ( $object->capability_type[1] ?? '' ) : '',
			'capabilities'      => is_array( $object->cap ?? null ) ? $this->capability_map_from_object( $object->cap ) : array(),
			'mapMetaCap'        => true === ( $object->map_meta_cap ?? null ) ? 'enable' : ( false === ( $object->map_meta_cap ?? null ) ? 'disable' : 'inherit' ),
			'template'          => is_array( $object->template ?? null ) ? $object->template : array(),
			'templateLock'      => is_string( $object->template_lock ?? null ) ? $object->template_lock : 'none',
		);
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function decorate_row( array $row ): array {
		$origin = is_string( $row['origin'] ?? null ) ? $row['origin'] : 'custom';
		return array_merge(
			$this->normalize_stored_row( $row ),
			array(
				'originLabel' => $this->origin_label( $origin ),
				'publicLabel' => true === ( $row['public'] ?? false ) ? 'Yes' : 'No',
				'restLabel'   => true === ( $row['showInRest'] ?? false ) ? 'Enabled' : 'Disabled',
				'canDelete'   => 'custom' === $origin,
			)
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	private function stored_row_from_input( array $input ): array {
		$raw_slug = (string) ( $input['slug'] ?? '' );
		$slug     = $this->sanitize_slug( $raw_slug );
		if ( '' === $slug ) {
			throw Errors::invariant( 'Post type slug is required.' );
		}

		if ( $raw_slug !== $slug || ! preg_match( '/^[a-z0-9_-]{1,20}$/', $slug ) ) {
			throw Errors::invariant( 'Post type slug must use 1-20 lowercase letters, numbers, dashes, or underscores.' );
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
				'pluralLabel'       => trim( (string) ( $input['pluralLabel'] ?? '' ) ),
				'description'       => trim( (string) ( $input['description'] ?? '' ) ),
				'menuIcon'          => trim( (string) ( $input['menuIcon'] ?? '' ) ),
				'menuPosition'      => max( 0, (int) ( $input['menuPosition'] ?? 0 ) ),
				'labels'            => $this->string_map( $input['labels'] ?? array() ),
				'public'            => true === ( $input['public'] ?? true ),
				'publiclyQueryable' => true === ( $input['publiclyQueryable'] ?? ( $input['public'] ?? true ) ),
				'showUi'            => true === ( $input['showUi'] ?? true ),
				'showInMenu'        => true === ( $input['showInMenu'] ?? true ),
				'showInMenuParent'  => trim( (string) ( $input['showInMenuParent'] ?? '' ) ),
				'showInNavMenus'    => true === ( $input['showInNavMenus'] ?? true ),
				'showInAdminBar'    => true === ( $input['showInAdminBar'] ?? true ),
				'excludeFromSearch' => true === ( $input['excludeFromSearch'] ?? false ),
				'supports'          => $this->string_list( $input['supports'] ?? self::DEFAULT_SUPPORTS ),
				'hasArchive'        => true === ( $input['hasArchive'] ?? true ),
				'hasArchiveSlug'    => trim( (string) ( $input['hasArchiveSlug'] ?? '' ) ),
				'rewrite'           => true === ( $input['rewrite'] ?? true ),
				'rewriteOptions'    => $this->rewrite_options_row( $input['rewriteOptions'] ?? array(), $slug ),
				'queryVar'          => true === ( $input['queryVar'] ?? true ),
				'queryVarName'      => trim( (string) ( $input['queryVarName'] ?? '' ) ),
				'hierarchical'      => true === ( $input['hierarchical'] ?? false ),
				'canExport'         => true === ( $input['canExport'] ?? true ),
				'deleteWithUser'    => $this->delete_with_user_value( $input['deleteWithUser'] ?? 'inherit' ),
				'taxonomies'        => $this->string_list( $input['taxonomies'] ?? array() ),
				'showInRest'        => true === ( $input['showInRest'] ?? true ),
				'restBase'          => trim( (string) ( $input['restBase'] ?? '' ) ),
				'restNamespace'     => trim( (string) ( $input['restNamespace'] ?? 'wp/v2' ) ),
				'restControllerClass' => trim( (string) ( $input['restControllerClass'] ?? '' ) ),
				'capabilityType'    => trim( (string) ( $input['capabilityType'] ?? 'post' ) ),
				'capabilityTypePlural' => trim( (string) ( $input['capabilityTypePlural'] ?? '' ) ),
				'capabilities'      => $this->allowed_string_map( $input['capabilities'] ?? array(), self::CAPABILITY_KEYS ),
				'mapMetaCap'        => $this->map_meta_cap_value( $input['mapMetaCap'] ?? 'enable' ),
				'template'          => is_array( $input['template'] ?? null ) ? array_values( $input['template'] ) : array(),
				'templateLock'      => $this->template_lock_value( $input['templateLock'] ?? 'none' ),
			)
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $rows Stored rows keyed by slug.
	 */
	private function assert_slug_can_be_saved( string $slug, string $original_slug, array $rows ): void {
		if ( '' === $original_slug ) {
			if ( isset( $rows[ $slug ] ) || isset( $this->registered_post_types()[ $slug ] ) ) {
				throw Errors::invariant( "Post type slug {$slug} already exists." );
			}

			return;
		}

		if ( $original_slug === $slug ) {
			return;
		}

		$original_row    = $rows[ $original_slug ] ?? null;
		$original_origin = is_array( $original_row ) ? (string) ( $original_row['origin'] ?? 'custom' ) : $this->registered_origin_for_slug( $original_slug );
		if ( 'custom' !== $original_origin ) {
			throw Errors::invariant( "Post type {$original_slug} is not owned by Onumia and cannot change slug." );
		}

		if ( isset( $rows[ $slug ] ) || isset( $this->registered_post_types()[ $slug ] ) ) {
			throw Errors::invariant( "Post type slug {$slug} already exists." );
		}
	}

	private function registered_origin_for_slug( string $slug ): string {
		$registered = $this->registered_post_types()[ $slug ] ?? null;
		if ( null === $registered ) {
			return 'custom';
		}

		return true === ( $registered->_builtin ?? false ) || in_array( $slug, self::BUILTIN_POST_TYPES, true ) ? 'builtin-override' : 'external-override';
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function normalize_stored_row( array $row ): array {
		$slug  = $this->sanitize_slug( (string) ( $row['slug'] ?? '' ) );
		$label = trim( (string) ( $row['label'] ?? $slug ) );

		return array(
			'slug'              => $slug,
			'origin'            => $this->origin_value( $row['origin'] ?? 'custom' ),
			'label'             => '' === $label ? $slug : $label,
			'pluralLabel'       => trim( (string) ( $row['pluralLabel'] ?? $label ) ),
			'description'       => trim( (string) ( $row['description'] ?? '' ) ),
			'menuIcon'          => trim( (string) ( $row['menuIcon'] ?? '' ) ),
			'menuPosition'      => max( 0, (int) ( $row['menuPosition'] ?? 0 ) ),
			'labels'            => $this->string_map( $row['labels'] ?? array() ),
			'public'            => true === ( $row['public'] ?? true ),
			'publiclyQueryable' => true === ( $row['publiclyQueryable'] ?? ( $row['public'] ?? true ) ),
			'showUi'            => true === ( $row['showUi'] ?? true ),
			'showInMenu'        => true === ( $row['showInMenu'] ?? true ),
			'showInMenuParent'  => trim( (string) ( $row['showInMenuParent'] ?? '' ) ),
			'showInNavMenus'    => true === ( $row['showInNavMenus'] ?? true ),
			'showInAdminBar'    => true === ( $row['showInAdminBar'] ?? ( $row['showInMenu'] ?? true ) ),
			'excludeFromSearch' => true === ( $row['excludeFromSearch'] ?? false ),
			'supports'          => $this->string_list( $row['supports'] ?? self::DEFAULT_SUPPORTS ),
			'hasArchive'        => true === ( $row['hasArchive'] ?? true ),
			'hasArchiveSlug'    => trim( (string) ( $row['hasArchiveSlug'] ?? '' ) ),
			'rewrite'           => is_bool( $row['rewrite'] ?? null ) ? (bool) $row['rewrite'] : true,
			'rewriteOptions'    => $this->rewrite_options_row( $row['rewriteOptions'] ?? ( is_array( $row['rewrite'] ?? null ) ? $row['rewrite'] : array() ), $slug ),
			'queryVar'          => true === ( $row['queryVar'] ?? true ),
			'queryVarName'      => trim( (string) ( $row['queryVarName'] ?? '' ) ),
			'hierarchical'      => true === ( $row['hierarchical'] ?? false ),
			'canExport'         => true === ( $row['canExport'] ?? true ),
			'deleteWithUser'    => $this->delete_with_user_value( $row['deleteWithUser'] ?? 'inherit' ),
			'taxonomies'        => $this->string_list( $row['taxonomies'] ?? array() ),
			'showInRest'        => true === ( $row['showInRest'] ?? true ),
			'restBase'          => trim( (string) ( $row['restBase'] ?? '' ) ),
			'restNamespace'     => trim( (string) ( $row['restNamespace'] ?? 'wp/v2' ) ),
			'restControllerClass' => trim( (string) ( $row['restControllerClass'] ?? '' ) ),
			'capabilityType'    => trim( (string) ( $row['capabilityType'] ?? 'post' ) ),
			'capabilityTypePlural' => trim( (string) ( $row['capabilityTypePlural'] ?? '' ) ),
			'capabilities'      => $this->allowed_string_map( $row['capabilities'] ?? array(), self::CAPABILITY_KEYS ),
			'mapMetaCap'        => $this->map_meta_cap_value( $row['mapMetaCap'] ?? 'enable' ),
			'template'          => is_array( $row['template'] ?? null ) ? array_values( $row['template'] ) : array(),
			'templateLock'      => $this->template_lock_value( $row['templateLock'] ?? 'none' ),
		);
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @param array<string,mixed> $base Base args.
	 * @return array<string,mixed>
	 */
	public function args_for( array $row, array $base = array() ): array {
		$row     = $this->normalize_stored_row( array_merge( $base, $row ) );
		$labels  = $this->labels_for( $row );
		$rewrite = false === $row['rewrite'] ? false : $this->rewrite_args( $row['rewriteOptions'] );
		$args    = array(
			'label'               => $row['label'],
			'labels'              => $labels,
			'description'         => $row['description'],
			'public'              => $row['public'],
			'publicly_queryable'  => $row['publiclyQueryable'],
			'show_ui'             => $row['showUi'],
			'show_in_menu'        => true === $row['showInMenu'] && '' !== $row['showInMenuParent'] ? $row['showInMenuParent'] : $row['showInMenu'],
			'show_in_nav_menus'   => $row['showInNavMenus'],
			'show_in_admin_bar'   => $row['showInAdminBar'],
			'exclude_from_search' => $row['excludeFromSearch'],
			'supports'            => array() === $row['supports'] ? false : $row['supports'],
			'has_archive'         => true === $row['hasArchive'] && '' !== $row['hasArchiveSlug'] ? $row['hasArchiveSlug'] : $row['hasArchive'],
			'rewrite'             => $rewrite,
			'query_var'           => true === $row['queryVar'] && '' !== $row['queryVarName'] ? $row['queryVarName'] : $row['queryVar'],
			'hierarchical'        => $row['hierarchical'],
			'taxonomies'          => $row['taxonomies'],
			'can_export'          => $row['canExport'],
			'show_in_rest'        => $row['showInRest'],
			'rest_base'           => '' === $row['restBase'] ? $row['slug'] : $row['restBase'],
			'rest_namespace'      => '' === $row['restNamespace'] ? 'wp/v2' : $row['restNamespace'],
			'capability_type'     => $this->capability_type_arg( $row['capabilityType'], $row['capabilityTypePlural'] ),
		);

		if ( '' !== $row['restControllerClass'] ) {
			$args['rest_controller_class'] = $row['restControllerClass'];
		}

		if ( array() !== $row['capabilities'] ) {
			$args['capabilities'] = $row['capabilities'];
		}

		if ( 'inherit' !== $row['mapMetaCap'] ) {
			$args['map_meta_cap'] = 'enable' === $row['mapMetaCap'];
		}

		if ( 'inherit' !== $row['deleteWithUser'] ) {
			$args['delete_with_user'] = 'yes' === $row['deleteWithUser'];
		}

		if ( array() !== $row['template'] ) {
			$args['template'] = $row['template'];
		}

		if ( 'none' !== $row['templateLock'] ) {
			$args['template_lock'] = $row['templateLock'];
		}

		if ( '' !== $row['menuIcon'] ) {
			$args['menu_icon'] = $row['menuIcon'];
		}

		if ( 0 < $row['menuPosition'] ) {
			$args['menu_position'] = $row['menuPosition'];
		}

		return $args;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,string>
	 */
	public function labels_for( array $row ): array {
		$row      = $this->normalize_stored_row( $row );
		$singular = $row['label'];
		$plural   = '' === $row['pluralLabel'] ? $row['label'] : $row['pluralLabel'];

		return $this->allowed_string_map(
			array_merge(
				array(
					'name'                  => $plural,
					'singular_name'         => $singular,
					'menu_name'             => $plural,
					'all_items'             => "All {$plural}",
					'add_new'               => 'Add new',
					'add_new_item'          => "Add new {$singular}",
					'edit_item'             => "Edit {$singular}",
					'new_item'              => "New {$singular}",
					'view_item'             => "View {$singular}",
					'search_items'          => "Search {$plural}",
					'not_found'             => "No {$plural} found",
					'not_found_in_trash'    => "No {$plural} found in Trash",
					'archives'              => "{$plural} archives",
					'attributes'            => "{$singular} attributes",
					'insert_into_item'      => "Insert into {$singular}",
					'uploaded_to_this_item' => "Uploaded to this {$singular}",
					'featured_image'        => 'Featured image',
					'set_featured_image'    => 'Set featured image',
					'remove_featured_image' => 'Remove featured image',
					'use_featured_image'    => 'Use as featured image',
					'items_list'            => "{$plural} list",
					'items_list_navigation' => "{$plural} list navigation",
				),
				$row['labels']
			),
			self::POST_LABEL_KEYS
		);
	}

	/**
	 * @param callable(array<string,array<string,mixed>>):array<string,array<string,mixed>> $updater Rows updater.
	 */
	private function persist_rows( callable $updater ): void {
		( new ModuleSettingsRepository() )->update_settings_with(
			$this->definition(),
			function ( array $settings ) use ( $updater ): array {
				$rows = $this->stored_rows_by_slug_from( $settings['types'] ?? array() );

				return array( 'types' => array_values( $updater( $rows ) ) );
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
	 * @return array{slug:string,withFront:bool,feeds:bool,pages:bool,epMask:int}
	 */
	private function rewrite_options_row( mixed $value, string $slug ): array {
		$rewrite = is_array( $value ) ? $value : array();
		return array(
			'slug'      => $this->sanitize_slug( is_string( $rewrite['slug'] ?? null ) && '' !== $rewrite['slug'] ? $rewrite['slug'] : $slug ),
			'withFront' => true === ( $rewrite['withFront'] ?? ( $rewrite['with_front'] ?? true ) ),
			'feeds'     => true === ( $rewrite['feeds'] ?? true ),
			'pages'     => true === ( $rewrite['pages'] ?? true ),
			'epMask'    => max( 0, (int) ( $rewrite['epMask'] ?? ( $rewrite['ep_mask'] ?? 0 ) ) ),
		);
	}

	/**
	 * @param array{slug:string,withFront:bool,feeds:bool,pages:bool,epMask:int} $rewrite Rewrite options.
	 * @return array<string,mixed>
	 */
	private function rewrite_args( array $rewrite ): array {
		$args = array(
			'slug'       => $rewrite['slug'],
			'with_front' => $rewrite['withFront'],
			'feeds'      => $rewrite['feeds'],
			'pages'      => $rewrite['pages'],
		);
		if ( 0 < $rewrite['epMask'] ) {
			$args['ep_mask'] = $rewrite['epMask'];
		}

		return $args;
	}

	private function capability_type_arg( string $singular, string $plural ): string|array {
		$singular = '' === $singular ? 'post' : $singular;
		if ( '' === $plural || $plural === $singular . 's' ) {
			return $singular;
		}

		return array( $singular, $plural );
	}

	private function map_meta_cap_value( mixed $value ): string {
		if ( true === $value ) {
			return 'enable';
		}
		if ( false === $value ) {
			return 'disable';
		}
		return in_array( $value, array( 'inherit', 'enable', 'disable' ), true ) ? (string) $value : 'enable';
	}

	private function delete_with_user_value( mixed $value ): string {
		if ( true === $value ) {
			return 'yes';
		}
		if ( false === $value ) {
			return 'no';
		}
		return in_array( $value, array( 'inherit', 'yes', 'no' ), true ) ? (string) $value : 'inherit';
	}

	private function template_lock_value( mixed $value ): string {
		return in_array( $value, array( 'none', 'all', 'insert' ), true ) ? (string) $value : 'none';
	}

	/**
	 * @param list<string> $allowed Allowed keys.
	 * @return array<string,string>
	 */
	private function allowed_string_map( mixed $value, array $allowed ): array {
		$map = $this->string_map( $value );
		return array_intersect_key( $map, array_fill_keys( $allowed, true ) );
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
	 * @return list<string>
	 */
	private function post_type_supports( string $slug, object $object ): array {
		if ( function_exists( 'get_all_post_type_supports' ) ) {
			$supports = \get_all_post_type_supports( $slug );
			if ( is_array( $supports ) ) {
				return array_keys( array_filter( $supports ) );
			}
		}

		return $this->string_list( $object->supports ?? self::DEFAULT_SUPPORTS );
	}

	/**
	 * @return list<string>
	 */
	private function object_taxonomies( string $slug ): array {
		if ( ! function_exists( 'get_object_taxonomies' ) ) {
			return array();
		}

		return $this->string_list( \get_object_taxonomies( $slug, 'names' ) );
	}
}
