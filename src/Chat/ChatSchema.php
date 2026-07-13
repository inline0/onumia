<?php

/**
 * Database schema for Onumia chats.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Chat;

final class ChatSchema {
	private const VERSION        = '3';
	private const VERSION_OPTION = 'onumia_chat_schema_version';

	public function __construct(
		private readonly ChatDatabase $database = new WordPressChatDatabase(),
	) {
	}

	public function maybe_install(): void {
		if ( self::VERSION === \get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}

		$this->install();
		\update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	public function install(): void {
		$absolute_path = \defined( 'ABSPATH' ) ? \constant( 'ABSPATH' ) : '';
		$upgrade_file  = is_string( $absolute_path ) ? rtrim( $absolute_path, '/\\' ) . '/wp-admin/includes/upgrade.php' : '';
		if ( '' !== $upgrade_file && is_file( $upgrade_file ) ) {
			require_once $upgrade_file;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}

		try {
			$charset_collate = $this->charset_collate();
			$chats_table     = $this->chats_table();
			$messages_table  = $this->messages_table();
			$members_table   = $this->members_table();
		} catch ( \Throwable ) {
			return;
		}

		\dbDelta(
			"CREATE TABLE {$chats_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id varchar(64) NOT NULL,
				entity_type varchar(64) NOT NULL,
				entity_id varchar(191) NOT NULL,
				title varchar(255) NOT NULL,
				model varchar(100) NOT NULL,
				visibility varchar(20) NOT NULL DEFAULT 'private',
				owner_user_id bigint(20) unsigned NOT NULL,
				lock_user_id bigint(20) unsigned DEFAULT NULL,
				lock_token varchar(64) DEFAULT NULL,
				lock_status varchar(20) DEFAULT NULL,
				lock_updated_at datetime DEFAULT NULL,
				lock_expires_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY entity_user (entity_type, entity_id, owner_user_id),
				KEY lock_expires_at (lock_expires_at),
				KEY owner_user_id (owner_user_id)
			) {$charset_collate};"
		);

		\dbDelta(
			"CREATE TABLE {$messages_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id varchar(64) NOT NULL,
				chat_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				role varchar(20) NOT NULL,
				parts longtext NOT NULL,
				metadata longtext NOT NULL,
				status varchar(20) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY chat_id (chat_id),
				KEY user_id (user_id)
			) {$charset_collate};"
		);

		\dbDelta(
			"CREATE TABLE {$members_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				chat_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				permission varchar(20) NOT NULL,
				invited_by bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY chat_user (chat_id, user_id),
				KEY chat_id (chat_id),
				KEY user_id (user_id),
				KEY permission (permission)
			) {$charset_collate};"
		);
	}

	public function chats_table(): string {
		return $this->database->prefix() . 'onumia_chats';
	}

	public function messages_table(): string {
		return $this->database->prefix() . 'onumia_chat_messages';
	}

	public function members_table(): string {
		return $this->database->prefix() . 'onumia_chat_members';
	}

	private function charset_collate(): string {
		global $wpdb;

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' ) ) {
			$value = $wpdb->get_charset_collate();
			if ( is_string( $value ) ) {
				return $value;
			}
		}

		return '';
	}
}
