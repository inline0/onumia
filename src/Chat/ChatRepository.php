<?php

/**
 * Persistent chat repository.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Chat;

use Onumia\Core\Errors;

final class ChatRepository {
	private const DEFAULT_LOCK_TTL_SECONDS = 120;
	private const DEFAULT_MODEL            = 'gpt-5';
	private const PERMISSIONS              = array( 'read', 'write', 'admin' );

	public function __construct(
		private readonly ChatDatabase $database = new WordPressChatDatabase(),
		private readonly ?ChatSchema $schema = null,
	) {
	}

	/**
	 * @return array{id:string,entityType:string,entityId:string,title:string,model:string,visibility:string,ownerUserId:int,currentUserPermission:string,memberCount:int,createdAt:string,updatedAt:string}
	 */
	public function create( string $entity_type, string $entity_id, int $owner_user_id, string $title = 'New chat', string $model = self::DEFAULT_MODEL, string $visibility = 'private' ): array {
		$entity_type = $this->valid_entity_type( $entity_type );
		$entity_id   = $this->valid_entity_id( $entity_id );
		$title       = $this->valid_title( $title );
		$model       = $this->valid_model( $model );
		$visibility  = $this->valid_visibility( $visibility );
		$now         = $this->now();
		$public_id   = $this->public_id( 'chat' );

		$chat_row_id = $this->database->insert(
			$this->schema()->chats_table(),
			array(
				'public_id'     => $public_id,
				'entity_type'   => $entity_type,
				'entity_id'     => $entity_id,
				'title'         => $title,
				'model'         => $model,
				'visibility'    => $visibility,
				'owner_user_id' => $owner_user_id,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		$this->insert_member( $chat_row_id, $owner_user_id, 'admin', $owner_user_id, $now );

		$chat = $this->find( $public_id, $owner_user_id );
		if ( null === $chat ) {
			throw Errors::invariant( 'Created Onumia chat could not be loaded.' );
		}

		return $chat;
	}

	/**
	 * @return list<array{id:string,entityType:string,entityId:string,title:string,model:string,visibility:string,ownerUserId:int,currentUserPermission:string,memberCount:int,createdAt:string,updatedAt:string}>
	 */
	public function list_for_entity( string $entity_type, string $entity_id, int $user_id ): array {
		$sql = $this->database->prepare(
			'SELECT DISTINCT c.* FROM ' . $this->schema()->chats_table() . ' c LEFT JOIN ' . $this->schema()->members_table() . ' m ON m.chat_id = c.id AND m.user_id = %d WHERE c.entity_type = %s AND c.entity_id = %s AND (c.owner_user_id = %d OR m.user_id = %d) ORDER BY c.updated_at DESC, c.id DESC',
			$user_id,
			$this->valid_entity_type( $entity_type ),
			$this->valid_entity_id( $entity_id ),
			$user_id,
			$user_id
		);

		return array_map(
			fn( array $row ): array => $this->chat_from_row( $row, $user_id ),
			$this->database->get_results( $sql )
		);
	}

	/**
	 * @return array{id:string,entityType:string,entityId:string,title:string,model:string,visibility:string,ownerUserId:int,currentUserPermission:string,memberCount:int,createdAt:string,updatedAt:string}|null
	 */
	public function find( string $chat_id, int $user_id ): ?array {
		$row = $this->database->get_row(
			$this->database->prepare(
				'SELECT c.* FROM ' . $this->schema()->chats_table() . ' c LEFT JOIN ' . $this->schema()->members_table() . ' m ON m.chat_id = c.id AND m.user_id = %d WHERE c.public_id = %s AND (c.owner_user_id = %d OR m.user_id = %d) LIMIT 1',
				$user_id,
				$chat_id,
				$user_id,
				$user_id
			)
		);

		return null === $row ? null : $this->chat_from_row( $row, $user_id );
	}

	/**
	 * @return list<array{userId:int,permission:string,invitedBy:int|null,createdAt:string,updatedAt:string}>
	 */
	public function members( string $chat_id, int $user_id ): array {
		$chat_row = $this->chat_row( $chat_id, $user_id );
		if ( null === $chat_row ) {
			return array();
		}

		$sql = $this->database->prepare(
			'SELECT * FROM ' . $this->schema()->members_table() . ' WHERE chat_id = %d ORDER BY id ASC',
			$this->row_int( $chat_row, 'id' )
		);

		return array_map(
			fn( array $row ): array => $this->member_from_row( $row ),
			$this->database->get_results( $sql )
		);
	}

	/**
	 * @return array{userId:int,permission:string,invitedBy:int|null,createdAt:string,updatedAt:string}
	 */
	public function add_member( string $chat_id, int $actor_user_id, int $target_user_id, string $permission ): array {
		$chat_row    = $this->admin_chat_row( $chat_id, $actor_user_id );
		$chat_id_int = $this->row_int( $chat_row, 'id' );
		if ( $target_user_id === $this->row_int( $chat_row, 'owner_user_id' ) ) {
			throw Errors::invariant( 'Onumia chat owner is already an admin member.' );
		}

		foreach ( $this->members_for_chat_row( $chat_id_int ) as $member ) {
			if ( $target_user_id === $this->row_int( $member, 'user_id' ) ) {
				return $this->update_member( $chat_id, $actor_user_id, $target_user_id, $permission );
			}
		}

		$now = $this->now();
		$this->insert_member( $chat_id_int, $target_user_id, $this->valid_permission( $permission ), $actor_user_id, $now );
		$this->touch_chat( $chat_id_int, $now );

		return $this->member_for_chat_row( $chat_id_int, $target_user_id );
	}

	/**
	 * @return array{userId:int,permission:string,invitedBy:int|null,createdAt:string,updatedAt:string}
	 */
	public function update_member( string $chat_id, int $actor_user_id, int $target_user_id, string $permission ): array {
		$chat_row    = $this->admin_chat_row( $chat_id, $actor_user_id );
		$chat_id_int = $this->row_int( $chat_row, 'id' );
		if ( $target_user_id === $this->row_int( $chat_row, 'owner_user_id' ) ) {
			throw Errors::invariant( 'Onumia chat owner permission cannot be changed.' );
		}

		$this->database->update(
			$this->schema()->members_table(),
			array(
				'permission' => $this->valid_permission( $permission ),
				'updated_at' => $this->now(),
			),
			array(
				'chat_id' => $chat_id_int,
				'user_id' => $target_user_id,
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);
		$this->touch_chat( $chat_id_int, $this->now() );

		return $this->member_for_chat_row( $chat_id_int, $target_user_id );
	}

	public function remove_member( string $chat_id, int $actor_user_id, int $target_user_id ): void {
		$chat_row    = $this->admin_chat_row( $chat_id, $actor_user_id );
		$chat_id_int = $this->row_int( $chat_row, 'id' );
		if ( $target_user_id === $this->row_int( $chat_row, 'owner_user_id' ) ) {
			throw Errors::invariant( 'Onumia chat owner cannot be removed.' );
		}

		$this->database->delete(
			$this->schema()->members_table(),
			array(
				'chat_id' => $chat_id_int,
				'user_id' => $target_user_id,
			),
			array( '%d', '%d' )
		);
		$this->touch_chat( $chat_id_int, $this->now() );
	}

	/**
	 * @return list<array{id:string,chatId:string,role:string,parts:list<array<string,mixed>>,metadata:array<string,mixed>,status:string,userId:int|null,createdAt:string}>
	 */
	public function messages( string $chat_id, int $user_id ): array {
		$chat_row = $this->chat_row( $chat_id, $user_id );
		if ( null === $chat_row ) {
			return array();
		}

		$sql = $this->database->prepare(
			'SELECT * FROM ' . $this->schema()->messages_table() . ' WHERE chat_id = %d ORDER BY id ASC',
			$this->row_int( $chat_row, 'id' )
		);

		return array_map(
			fn( array $row ): array => $this->message_from_row( $row, $this->row_string( $chat_row, 'public_id' ) ),
			$this->database->get_results( $sql )
		);
	}

	/**
	 * @return array{userId:int,status:string,updatedAt:string,expiresAt:string,ownedByCurrentUser:bool,token?:string}
	 */
	public function acquire_lock( string $chat_id, int $user_id, string $status = 'streaming', int $ttl_seconds = self::DEFAULT_LOCK_TTL_SECONDS ): array {
		$chat_row = $this->chat_row( $chat_id, $user_id );
		if ( null === $chat_row ) {
			throw Errors::invariant( 'Onumia chat was not found.' );
		}
		if ( ! $this->can_write_chat_row( $chat_row, $user_id ) ) {
			throw Errors::invariant( 'Onumia chat is read-only for this user.' );
		}

		$now        = $this->now();
		$expires_at = $this->datetime_after( $now, $ttl_seconds );
		$token      = $this->public_id( 'lock' );
		$status     = $this->valid_lock_status( $status );
		$affected   = $this->database->query(
			$this->database->prepare(
				'UPDATE ' . $this->schema()->chats_table() . ' SET lock_user_id = %d, lock_token = %s, lock_status = %s, lock_updated_at = %s, lock_expires_at = %s WHERE public_id = %s AND (lock_user_id IS NULL OR lock_user_id = %d OR lock_expires_at IS NULL OR lock_expires_at <= %s)',
				$user_id,
				$token,
				$status,
				$now,
				$expires_at,
				$chat_id,
				$user_id,
				$now
			)
		);

		if ( $affected < 1 ) {
			throw new ChatLockConflict( $this->active_lock( $chat_id, $user_id ) ?? array( 'chatId' => $chat_id ) );
		}

		$locked_row = $this->chat_row( $chat_id, $user_id );
		if ( null === $locked_row ) {
			throw Errors::invariant( 'Onumia chat lock could not be loaded.' );
		}

		$lock = $this->lock_from_row( $locked_row, $user_id, true );
		if ( null === $lock ) {
			throw Errors::invariant( 'Onumia chat lock could not be created.' );
		}

		return $lock;
	}

	/**
	 * @return array{userId:int,status:string,updatedAt:string,expiresAt:string,ownedByCurrentUser:bool,token?:string}
	 */
	public function refresh_lock( string $chat_id, int $user_id, string $token, int $ttl_seconds = self::DEFAULT_LOCK_TTL_SECONDS ): array {
		$token = trim( $token );
		if ( '' === $token ) {
			throw Errors::invariant( 'Onumia chat lock token is required.' );
		}

		$now        = $this->now();
		$expires_at = $this->datetime_after( $now, $ttl_seconds );
		$affected   = $this->database->query(
			$this->database->prepare(
				'UPDATE ' . $this->schema()->chats_table() . ' SET lock_updated_at = %s, lock_expires_at = %s WHERE public_id = %s AND lock_user_id = %d AND lock_token = %s AND lock_expires_at > %s',
				$now,
				$expires_at,
				$chat_id,
				$user_id,
				$token,
				$now
			)
		);

		if ( $affected < 1 ) {
			throw new ChatLockConflict( $this->active_lock( $chat_id, $user_id ) ?? array( 'chatId' => $chat_id ) );
		}

		$lock = $this->active_lock( $chat_id, $user_id, true );
		if ( null === $lock ) {
			throw Errors::invariant( 'Onumia chat lock could not be refreshed.' );
		}

		return $lock;
	}

	public function release_lock( string $chat_id, int $user_id, string $token ): void {
		$token = trim( $token );
		if ( '' === $token ) {
			return;
		}

		$this->database->update(
			$this->schema()->chats_table(),
			array(
				'lock_user_id'    => null,
				'lock_token'      => null,
				'lock_status'     => null,
				'lock_updated_at' => null,
				'lock_expires_at' => null,
			),
			array(
				'public_id'    => $chat_id,
				'lock_user_id' => $user_id,
				'lock_token'   => $token,
			),
			array( '%d', '%s', '%s', '%s', '%s' ),
			array( '%s', '%d', '%s' )
		);
	}

	/**
	 * @return array{userId:int,status:string,updatedAt:string,expiresAt:string,ownedByCurrentUser:bool,token?:string}|null
	 */
	public function active_lock( string $chat_id, int $user_id, bool $include_token = false ): ?array {
		$chat_row = $this->chat_row( $chat_id, $user_id );
		if ( null === $chat_row ) {
			return null;
		}

		return $this->lock_from_row( $chat_row, $user_id, $include_token );
	}

	/**
	 * @param  array<array-key,mixed> $parts    AI SDK UI message parts.
	 * @param  array<string,mixed>    $metadata Message metadata.
	 * @return array{id:string,chatId:string,role:string,parts:list<array<string,mixed>>,metadata:array<string,mixed>,status:string,userId:int|null,createdAt:string}
	 */
	public function append_message( string $chat_id, int $user_id, string $role, array $parts, array $metadata = array(), string $status = 'ready' ): array {
		$chat_row = $this->chat_row( $chat_id, $user_id );
		if ( null === $chat_row ) {
			throw Errors::invariant( 'Onumia chat was not found.' );
		}
		if ( ! $this->can_write_chat_row( $chat_row, $user_id ) ) {
			throw Errors::invariant( 'Onumia chat is read-only for this user.' );
		}

		$now        = $this->now();
		$public_id  = $this->public_id( 'msg' );
		$parts_json = $this->encode_json( $this->valid_parts( $parts ) );
		$meta_json  = $this->encode_json( $metadata );
		$role       = $this->valid_role( $role );
		$status     = $this->valid_status( $status );

		$this->database->insert(
			$this->schema()->messages_table(),
			array(
				'public_id'  => $public_id,
				'chat_id'    => $this->row_int( $chat_row, 'id' ),
				'user_id'    => 'assistant' === $role ? null : $user_id,
				'role'       => $role,
				'parts'      => $parts_json,
				'metadata'   => $meta_json,
				'status'     => $status,
				'created_at' => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		$this->touch_chat( $this->row_int( $chat_row, 'id' ), $now );

		$message = $this->message_row( $public_id );
		if ( null === $message ) {
			throw Errors::invariant( 'Created Onumia chat message could not be loaded.' );
		}

		return $this->message_from_row( $message, $chat_id );
	}

	private function schema(): ChatSchema {
		return $this->schema ?? new ChatSchema( $this->database );
	}

	private function touch_chat( int $id, string $updated_at ): void {
		$this->database->update(
			$this->schema()->chats_table(),
			array( 'updated_at' => $updated_at ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function chat_row( string $chat_id, int $user_id ): ?array {
		return $this->database->get_row(
			$this->database->prepare(
				'SELECT c.* FROM ' . $this->schema()->chats_table() . ' c LEFT JOIN ' . $this->schema()->members_table() . ' m ON m.chat_id = c.id AND m.user_id = %d WHERE c.public_id = %s AND (c.owner_user_id = %d OR m.user_id = %d) LIMIT 1',
				$user_id,
				$chat_id,
				$user_id,
				$user_id
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function admin_chat_row( string $chat_id, int $user_id ): array {
		$row = $this->chat_row( $chat_id, $user_id );
		if ( null === $row ) {
			throw Errors::invariant( 'Onumia chat was not found.' );
		}

		if ( 'admin' !== $this->permission_for_row( $row, $user_id ) ) {
			throw Errors::invariant( 'Onumia chat admin permission is required.' );
		}

		return $row;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function message_row( string $message_id ): ?array {
		return $this->database->get_row(
			$this->database->prepare(
				'SELECT * FROM ' . $this->schema()->messages_table() . ' WHERE public_id = %s LIMIT 1',
				$message_id
			)
		);
	}

	/**
	 * @param  array<string,mixed> $row Row.
	 * @return array{id:string,entityType:string,entityId:string,title:string,model:string,visibility:string,ownerUserId:int,currentUserPermission:string,memberCount:int,createdAt:string,updatedAt:string}
	 */
	private function chat_from_row( array $row, int $user_id ): array {
		return array(
			'id'                    => $this->row_string( $row, 'public_id' ),
			'entityType'            => $this->row_string( $row, 'entity_type' ),
			'entityId'              => $this->row_string( $row, 'entity_id' ),
			'title'                 => $this->row_string( $row, 'title' ),
			'model'                 => $this->row_string( $row, 'model' ),
			'visibility'            => $this->row_string( $row, 'visibility' ),
			'ownerUserId'           => $this->row_int( $row, 'owner_user_id' ),
			'currentUserPermission' => $this->permission_for_row( $row, $user_id ),
			'memberCount'           => count( $this->members_for_chat_row( $this->row_int( $row, 'id' ) ) ),
			'createdAt'             => $this->response_datetime( $this->row_string( $row, 'created_at' ) ),
			'updatedAt'             => $this->response_datetime( $this->row_string( $row, 'updated_at' ) ),
		);
	}

	/**
	 * @param  array<string,mixed> $row Row.
	 * @return array{userId:int,status:string,updatedAt:string,expiresAt:string,ownedByCurrentUser:bool,token?:string}|null
	 */
	private function lock_from_row( array $row, int $current_user_id, bool $include_token = false ): ?array {
		$user_id    = $this->row_nullable_int( $row, 'lock_user_id' );
		$token      = $this->row_nullable_string( $row, 'lock_token' );
		$status     = $this->row_nullable_string( $row, 'lock_status' );
		$updated_at = $this->row_nullable_string( $row, 'lock_updated_at' );
		$expires_at = $this->row_nullable_string( $row, 'lock_expires_at' );

		if ( null === $user_id || null === $token || null === $status || null === $updated_at || null === $expires_at ) {
			return null;
		}
		if ( $this->datetime_expired( $expires_at ) ) {
			return null;
		}

		$lock = array(
			'userId'             => $user_id,
			'status'             => $this->valid_lock_status( $status ),
			'updatedAt'          => $this->response_datetime( $updated_at ),
			'expiresAt'          => $this->response_datetime( $expires_at ),
			'ownedByCurrentUser' => $user_id === $current_user_id,
		);

		if ( $include_token && $user_id === $current_user_id ) {
			$lock['token'] = $token;
		}

		return $lock;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function members_for_chat_row( int $chat_id ): array {
		return $this->database->get_results(
			$this->database->prepare(
				'SELECT * FROM ' . $this->schema()->members_table() . ' WHERE chat_id = %d ORDER BY id ASC',
				$chat_id
			)
		);
	}

	/**
	 * @return array{userId:int,permission:string,invitedBy:int|null,createdAt:string,updatedAt:string}
	 */
	private function member_for_chat_row( int $chat_id, int $user_id ): array {
		foreach ( $this->members_for_chat_row( $chat_id ) as $row ) {
			if ( $user_id === $this->row_int( $row, 'user_id' ) ) {
				return $this->member_from_row( $row );
			}
		}

		throw Errors::invariant( 'Onumia chat member was not found.' );
	}

	/**
	 * @param  array<string,mixed> $row Row.
	 * @return array{userId:int,permission:string,invitedBy:int|null,createdAt:string,updatedAt:string}
	 */
	private function member_from_row( array $row ): array {
		return array(
			'userId'     => $this->row_int( $row, 'user_id' ),
			'permission' => $this->row_string( $row, 'permission' ),
			'invitedBy'  => $this->row_nullable_int( $row, 'invited_by' ),
			'createdAt'  => $this->response_datetime( $this->row_string( $row, 'created_at' ) ),
			'updatedAt'  => $this->response_datetime( $this->row_string( $row, 'updated_at' ) ),
		);
	}

	private function insert_member( int $chat_id, int $user_id, string $permission, ?int $invited_by, string $now ): void {
		$this->database->insert(
			$this->schema()->members_table(),
			array(
				'chat_id'    => $chat_id,
				'user_id'    => $user_id,
				'permission' => $this->valid_permission( $permission ),
				'invited_by' => $invited_by,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * @param array<string,mixed> $row Row.
	 */
	private function permission_for_row( array $row, int $user_id ): string {
		if ( $user_id === $this->row_int( $row, 'owner_user_id' ) ) {
			return 'admin';
		}

		foreach ( $this->members_for_chat_row( $this->row_int( $row, 'id' ) ) as $member ) {
			if ( $user_id === $this->row_int( $member, 'user_id' ) ) {
				return $this->row_string( $member, 'permission' );
			}
		}

		return 'read';
	}

	/**
	 * @param array<string,mixed> $row Row.
	 */
	private function can_write_chat_row( array $row, int $user_id ): bool {
		return in_array( $this->permission_for_row( $row, $user_id ), array( 'write', 'admin' ), true );
	}

	/**
	 * @param  array<string,mixed> $row Row.
	 * @return array{id:string,chatId:string,role:string,parts:list<array<string,mixed>>,metadata:array<string,mixed>,status:string,userId:int|null,createdAt:string}
	 */
	private function message_from_row( array $row, string $chat_id ): array {
		return array(
			'id'        => $this->row_string( $row, 'public_id' ),
			'chatId'    => $chat_id,
			'role'      => $this->row_string( $row, 'role' ),
			'parts'     => $this->decode_parts( $this->row_string( $row, 'parts' ) ),
			'metadata'  => $this->decode_record( $this->row_string( $row, 'metadata' ) ),
			'status'    => $this->row_string( $row, 'status' ),
			'userId'    => $this->row_nullable_int( $row, 'user_id' ),
			'createdAt' => $this->response_datetime( $this->row_string( $row, 'created_at' ) ),
		);
	}

	private function public_id( string $prefix ): string {
		return $prefix . '_' . bin2hex( random_bytes( 16 ) );
	}

	private function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	private function response_datetime( string $value ): string {
		if ( str_contains( $value, 'T' ) ) {
			return $value;
		}

		return str_replace( ' ', 'T', $value ) . 'Z';
	}

	private function datetime_after( string $value, int $seconds ): string {
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			$timestamp = time();
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp + $seconds );
	}

	private function datetime_expired( string $value ): bool {
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return true;
		}

		return $timestamp <= time();
	}

	private function valid_entity_type( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/', $value ) ) {
			throw Errors::invariant( 'Onumia chat entity type is invalid.' );
		}

		return $value;
	}

	private function valid_entity_id( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || $this->utf8_length( $value ) > 191 ) {
			throw Errors::invariant( 'Onumia chat entity id is invalid.' );
		}

		return $value;
	}

	private function valid_title( string $value ): string {
		$value = trim( wp_strip_all_tags( $value ) );
		if ( '' === $value ) {
			return 'New chat';
		}

		return $this->utf8_substr( $value, 255 );
	}

	private function valid_model( string $value ): string {
		$value = trim( $value );
		return '' === $value ? self::DEFAULT_MODEL : $this->utf8_substr( $value, 100 );
	}

	private function utf8_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}

		// @codeCoverageIgnoreStart
		if ( preg_match_all( '/./us', $value, $matches ) ) {
			return count( $matches[0] );
		}

		return strlen( $value );
		// @codeCoverageIgnoreEnd
	}

	private function utf8_substr( string $value, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length, 'UTF-8' );
		}

		// @codeCoverageIgnoreStart
		if ( preg_match_all( '/./us', $value, $matches ) ) {
			return implode( '', array_slice( $matches[0], 0, $length ) );
		}

		return substr( $value, 0, $length );
		// @codeCoverageIgnoreEnd
	}

	private function valid_visibility( string $value ): string {
		$value = trim( $value );
		if ( ! in_array( $value, array( 'private', 'shared' ), true ) ) {
			throw Errors::invariant( 'Onumia chat visibility is invalid.' );
		}

		return $value;
	}

	private function valid_role( string $value ): string {
		$value = trim( $value );
		if ( ! in_array( $value, array( 'system', 'user', 'assistant' ), true ) ) {
			throw Errors::invariant( 'Onumia chat message role is invalid.' );
		}

		return $value;
	}

	private function valid_status( string $value ): string {
		$value = trim( $value );
		if ( ! in_array( $value, array( 'ready', 'submitted', 'streaming', 'stopped', 'error' ), true ) ) {
			throw Errors::invariant( 'Onumia chat message status is invalid.' );
		}

		return $value;
	}

	private function valid_lock_status( string $value ): string {
		$value = trim( $value );
		if ( ! in_array( $value, array( 'submitted', 'streaming' ), true ) ) {
			throw Errors::invariant( 'Onumia chat lock status is invalid.' );
		}

		return $value;
	}

	private function valid_permission( string $value ): string {
		$value = trim( $value );
		if ( ! in_array( $value, self::PERMISSIONS, true ) ) {
			throw Errors::invariant( 'Onumia chat member permission is invalid.' );
		}

		return $value;
	}

	/**
	 * @param  array<array-key,mixed> $parts Parts.
	 * @return list<array<string,mixed>>
	 */
	private function valid_parts( array $parts ): array {
		if ( ! array_is_list( $parts ) ) {
			throw Errors::invariant( 'Onumia chat message parts must be a list.' );
		}

		$normalized = array();
		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) || ! is_string( $part['type'] ?? null ) || '' === $part['type'] ) {
				throw Errors::invariant( 'Onumia chat message parts are invalid.' );
			}

			$normalized[] = $this->string_keyed_array( $part );
		}

		return $normalized;
	}

	private function encode_json( mixed $value ): string {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			throw Errors::invariant( 'Could not encode Onumia chat JSON.' );
		}

		return $json;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function decode_parts( string $value ): array {
		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) || ! array_is_list( $decoded ) ) {
			return array();
		}

		$parts = array();
		foreach ( $decoded as $part ) {
			if ( is_array( $part ) ) {
				$parts[] = $this->string_keyed_array( $part );
			}
		}

		return $parts;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function decode_record( string $value ): array {
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) && ! array_is_list( $decoded ) ? $this->string_keyed_array( $decoded ) : array();
	}

	/**
	 * @param  array<array-key,mixed> $value Value.
	 * @return array<string,mixed>
	 */
	private function string_keyed_array( array $value ): array {
		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}

		return $normalized;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 */
	private function row_string( array $row, string $key ): string {
		$value = $row[ $key ] ?? null;
		if ( ! is_scalar( $value ) ) {
			throw Errors::invariant( "Onumia chat row field {$key} is invalid." );
		}

		return (string) $value;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 */
	private function row_int( array $row, string $key ): int {
		$value = $row[ $key ] ?? null;
		if ( ! is_int( $value ) && ! is_numeric( $value ) ) {
			throw Errors::invariant( "Onumia chat row field {$key} is invalid." );
		}

		return (int) $value;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 */
	private function row_nullable_int( array $row, string $key ): ?int {
		$value = $row[ $key ] ?? null;
		if ( null === $value ) {
			return null;
		}
		if ( ! is_int( $value ) && ! is_numeric( $value ) ) {
			throw Errors::invariant( "Onumia chat row field {$key} is invalid." );
		}

		return (int) $value;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 */
	private function row_nullable_string( array $row, string $key ): ?string {
		$value = $row[ $key ] ?? null;
		if ( null === $value ) {
			return null;
		}
		if ( ! is_scalar( $value ) ) {
			throw Errors::invariant( "Onumia chat row field {$key} is invalid." );
		}

		$value = (string) $value;
		return '' === $value ? null : $value;
	}
}
