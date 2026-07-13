<?php

/**
 * Onumia chat REST routes.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Rest;

use Onumia\Chat\ChatLockConflict;
use Onumia\Chat\ChatRepository;

final class ChatRoutes {
	private const NAMESPACE = 'onumia/v1';

	public static function register( ChatRepository $repository = new ChatRepository() ): void {
		\register_rest_route(
			self::NAMESPACE,
			'/chats',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::list_chats( $repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::create_chat( $repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/chats/(?P<chat>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::get_chat( $repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/chats/(?P<chat>[A-Za-z0-9_-]+)/messages',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::append_message( $repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/chats/(?P<chat>[A-Za-z0-9_-]+)/lock',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::acquire_lock( $repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::release_lock( $repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/chats/(?P<chat>[A-Za-z0-9_-]+)/lock/refresh',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::refresh_lock( $repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/chats/(?P<chat>[A-Za-z0-9_-]+)/members',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::list_members( $repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::add_member( $repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/chats/(?P<chat>[A-Za-z0-9_-]+)/members/(?P<user>[0-9]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::update_member( $repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::remove_member( $repository, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/users',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response => self::search_users( $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);
	}

	public static function can_manage_onumia(): bool {
		return \current_user_can( 'manage_options' );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function list_chats( ChatRepository $repository, \WP_REST_Request $request ) {
		$entity = self::entity_from_request( $request );
		if ( $entity instanceof \WP_Error ) {
			return $entity;
		}

		try {
			$user_id = \get_current_user_id();
			return new \WP_REST_Response(
				array(
					'chats' => array_map(
						static fn( array $chat ): array => self::response_chat_with_messages( $repository, $chat, $user_id ),
						$repository->list_for_entity( $entity['type'], $entity['id'], $user_id )
					),
				),
				200
			);
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_chats_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_chat( ChatRepository $repository, \WP_REST_Request $request ) {
		$entity = self::entity_from_request( $request );
		if ( $entity instanceof \WP_Error ) {
			return $entity;
		}

		$title      = $request->get_param( 'title' );
		$model      = $request->get_param( 'model' );
		$visibility = $request->get_param( 'visibility' );

		try {
			$user_id = \get_current_user_id();
			$chat    = $repository->create(
				$entity['type'],
				$entity['id'],
				$user_id,
				is_string( $title ) ? $title : 'New chat',
				is_string( $model ) ? $model : 'gpt-5',
				is_string( $visibility ) ? $visibility : 'private'
			);

			return new \WP_REST_Response(
				array(
					'chat'     => self::response_chat( $repository, $chat, $user_id ),
					'messages' => array(),
				),
				201
			);
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_chat_create_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_chat( ChatRepository $repository, \WP_REST_Request $request ) {
		$chat_id = self::chat_id_from_request( $request );
		if ( $chat_id instanceof \WP_Error ) {
			return $chat_id;
		}

		$user_id = \get_current_user_id();
		$chat    = $repository->find( $chat_id, $user_id );
		if ( null === $chat ) {
			return new \WP_Error( 'onumia_chat_not_found', 'Chat was not found.', array( 'status' => 404 ) );
		}

		return new \WP_REST_Response(
			array(
				'chat'     => self::response_chat( $repository, $chat, $user_id ),
				'messages' => array_map(
					static fn( array $message ): array => self::response_message( $message ),
					$repository->messages( $chat_id, $user_id )
				),
			),
			200
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function list_members( ChatRepository $repository, \WP_REST_Request $request ) {
		$chat_id = self::chat_id_from_request( $request );
		if ( $chat_id instanceof \WP_Error ) {
			return $chat_id;
		}

		try {
			return new \WP_REST_Response(
				array(
					'members' => self::response_members( $repository->members( $chat_id, \get_current_user_id() ) ),
				),
				200
			);
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_chat_members_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add_member( ChatRepository $repository, \WP_REST_Request $request ) {
		$chat_id = self::chat_id_from_request( $request );
		if ( $chat_id instanceof \WP_Error ) {
			return $chat_id;
		}

		$user_id = self::user_id_from_request( $request->get_param( 'userId' ) );
		if ( $user_id instanceof \WP_Error ) {
			return $user_id;
		}

		$permission = self::permission_from_request( $request );
		if ( $permission instanceof \WP_Error ) {
			return $permission;
		}

		try {
			return new \WP_REST_Response(
				array(
					'member' => self::response_member( $repository->add_member( $chat_id, \get_current_user_id(), $user_id, $permission ) ),
				),
				201
			);
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_chat_member_add_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_member( ChatRepository $repository, \WP_REST_Request $request ) {
		$chat_id = self::chat_id_from_request( $request );
		if ( $chat_id instanceof \WP_Error ) {
			return $chat_id;
		}

		$user_id = self::user_id_from_request( $request->get_param( 'user' ) );
		if ( $user_id instanceof \WP_Error ) {
			return $user_id;
		}

		$permission = self::permission_from_request( $request );
		if ( $permission instanceof \WP_Error ) {
			return $permission;
		}

		try {
			return new \WP_REST_Response(
				array(
					'member' => self::response_member( $repository->update_member( $chat_id, \get_current_user_id(), $user_id, $permission ) ),
				),
				200
			);
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_chat_member_update_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function remove_member( ChatRepository $repository, \WP_REST_Request $request ) {
		$chat_id = self::chat_id_from_request( $request );
		if ( $chat_id instanceof \WP_Error ) {
			return $chat_id;
		}

		$user_id = self::user_id_from_request( $request->get_param( 'user' ) );
		if ( $user_id instanceof \WP_Error ) {
			return $user_id;
		}

		try {
			$repository->remove_member( $chat_id, \get_current_user_id(), $user_id );
			return new \WP_REST_Response( array( 'removed' => true ), 200 );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_chat_member_remove_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	public static function search_users( \WP_REST_Request $request ): \WP_REST_Response {
		$search = $request->get_param( 'search' );
		$search = is_string( $search ) ? trim( $search ) : '';
		$users  = array();

		if ( function_exists( 'get_users' ) ) {
			$found = \get_users(
				array(
					'number' => 20,
					'search' => '' === $search ? '' : '*' . $search . '*',
				)
			);

			foreach ( $found as $user ) {
				$user_id = is_object( $user ) && isset( $user->ID ) && is_numeric( $user->ID ) ? (int) $user->ID : 0;
				if ( $user_id > 0 ) {
					$users[] = self::user_for_response( $user_id );
				}
			}
		}

		return new \WP_REST_Response( array( 'users' => $users ), 200 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function append_message( ChatRepository $repository, \WP_REST_Request $request ) {
		$chat_id = self::chat_id_from_request( $request );
		if ( $chat_id instanceof \WP_Error ) {
			return $chat_id;
		}

		$role = $request->get_param( 'role' );
		if ( ! is_string( $role ) || '' === $role ) {
			return new \WP_Error( 'onumia_chat_missing_role', 'Message role is required.', array( 'status' => 400 ) );
		}

		$parts = self::list_of_records( $request->get_param( 'parts' ) );
		if ( null === $parts ) {
			return new \WP_Error( 'onumia_chat_invalid_parts', 'Message parts must be a list of objects.', array( 'status' => 400 ) );
		}

		$metadata = self::record( $request->get_param( 'metadata' ) ?? array() );
		if ( null === $metadata ) {
			return new \WP_Error( 'onumia_chat_invalid_metadata', 'Message metadata must be an object.', array( 'status' => 400 ) );
		}

		$status = $request->get_param( 'status' );

		try {
			return new \WP_REST_Response(
				array(
					'message' => self::response_message(
						$repository->append_message(
							$chat_id,
							\get_current_user_id(),
							$role,
							$parts,
							$metadata,
							is_string( $status ) && '' !== $status ? $status : 'ready'
						)
					),
				),
				201
			);
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_chat_message_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function acquire_lock( ChatRepository $repository, \WP_REST_Request $request ) {
		$chat_id = self::chat_id_from_request( $request );
		if ( $chat_id instanceof \WP_Error ) {
			return $chat_id;
		}

		try {
			return new \WP_REST_Response(
				array(
					'lock' => self::response_lock( $repository->acquire_lock( $chat_id, \get_current_user_id() ) ),
				),
				200
			);
		} catch ( ChatLockConflict $conflict ) {
			return self::lock_conflict_error( $conflict );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_chat_lock_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function refresh_lock( ChatRepository $repository, \WP_REST_Request $request ) {
		$chat_id = self::chat_id_from_request( $request );
		if ( $chat_id instanceof \WP_Error ) {
			return $chat_id;
		}

		$token = self::lock_token_from_request( $request );
		if ( $token instanceof \WP_Error ) {
			return $token;
		}

		try {
			return new \WP_REST_Response(
				array(
					'lock' => self::response_lock( $repository->refresh_lock( $chat_id, \get_current_user_id(), $token ) ),
				),
				200
			);
		} catch ( ChatLockConflict $conflict ) {
			return self::lock_conflict_error( $conflict );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_chat_lock_refresh_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function release_lock( ChatRepository $repository, \WP_REST_Request $request ) {
		$chat_id = self::chat_id_from_request( $request );
		if ( $chat_id instanceof \WP_Error ) {
			return $chat_id;
		}

		$token = self::lock_token_from_request( $request );
		if ( $token instanceof \WP_Error ) {
			return $token;
		}

		try {
			$repository->release_lock( $chat_id, \get_current_user_id(), $token );
			return new \WP_REST_Response( array( 'released' => true ), 200 );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'onumia_chat_lock_release_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return array{type:string,id:string}|\WP_Error
	 */
	private static function entity_from_request( \WP_REST_Request $request ): array|\WP_Error {
		$type = $request->get_param( 'entityType' );
		$id   = $request->get_param( 'entityId' );

		if ( ! is_string( $type ) || '' === trim( $type ) ) {
			return new \WP_Error( 'onumia_chat_missing_entity_type', 'Chat entity type is required.', array( 'status' => 400 ) );
		}
		if ( ! is_string( $id ) || '' === trim( $id ) ) {
			return new \WP_Error( 'onumia_chat_missing_entity_id', 'Chat entity id is required.', array( 'status' => 400 ) );
		}

		return array(
			'type' => $type,
			'id'   => $id,
		);
	}

	private static function chat_id_from_request( \WP_REST_Request $request ): string|\WP_Error {
		$chat_id = $request->get_param( 'chat' );
		if ( ! is_string( $chat_id ) || '' === trim( $chat_id ) ) {
			return new \WP_Error( 'onumia_chat_missing_id', 'Chat id is required.', array( 'status' => 400 ) );
		}

		return $chat_id;
	}

	private static function user_id_from_request( mixed $value ): int|\WP_Error {
		if ( ! is_int( $value ) && ! is_numeric( $value ) ) {
			return new \WP_Error( 'onumia_chat_missing_user', 'User id is required.', array( 'status' => 400 ) );
		}

		$user_id = (int) $value;
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'onumia_chat_invalid_user', 'User id is invalid.', array( 'status' => 400 ) );
		}

		return $user_id;
	}

	private static function lock_token_from_request( \WP_REST_Request $request ): string|\WP_Error {
		$token = $request->get_param( 'token' );
		if ( ! is_string( $token ) || '' === trim( $token ) ) {
			return new \WP_Error( 'onumia_chat_missing_lock_token', 'Chat lock token is required.', array( 'status' => 400 ) );
		}

		return trim( $token );
	}

	private static function permission_from_request( \WP_REST_Request $request ): string|\WP_Error {
		$permission = $request->get_param( 'permission' );
		if ( ! is_string( $permission ) || ! in_array( $permission, array( 'read', 'write', 'admin' ), true ) ) {
			return new \WP_Error( 'onumia_chat_invalid_permission', 'Chat permission is invalid.', array( 'status' => 400 ) );
		}

		return $permission;
	}

	/**
	 * @param  array<string,mixed> $message Persisted message.
	 * @return array<string,mixed>
	 */
	private static function response_message( array $message ): array {
		$metadata            = $message['metadata'] ?? array();
		$message['metadata'] = array() === $metadata ? new \stdClass() : $metadata;
		$user_id             = is_int( $message['userId'] ?? null ) ? $message['userId'] : 0;
		if ( $user_id > 0 ) {
			$message['user'] = self::user_for_response( $user_id );
		}

		return $message;
	}

	/**
	 * @param  array<string,mixed> $chat Chat.
	 * @return array<string,mixed>
	 */
	private static function response_chat( ChatRepository $repository, array $chat, int $user_id ): array {
		$chat_id         = is_string( $chat['id'] ?? null ) ? $chat['id'] : '';
		$chat['members'] = self::response_members( $repository->members( $chat_id, $user_id ) );
		$lock            = $repository->active_lock( $chat_id, $user_id );
		$chat['lock']    = null === $lock ? null : self::response_lock( $lock );

		return $chat;
	}

	/**
	 * @param  array<string,mixed> $chat Chat.
	 * @return array<string,mixed>
	 */
	private static function response_chat_with_messages( ChatRepository $repository, array $chat, int $user_id ): array {
		$chat_id          = is_string( $chat['id'] ?? null ) ? $chat['id'] : '';
		$chat             = self::response_chat( $repository, $chat, $user_id );
		$chat['messages'] = array_map(
			static fn( array $message ): array => self::response_message( $message ),
			$repository->messages( $chat_id, $user_id )
		);

		return $chat;
	}

	/**
	 * @param  list<array<string,mixed>> $members Members.
	 * @return list<array<string,mixed>>
	 */
	private static function response_members( array $members ): array {
		return array_map(
			static fn( array $member ): array => self::response_member( $member ),
			$members
		);
	}

	/**
	 * @param  array<string,mixed> $member Member.
	 * @return array<string,mixed>
	 */
	private static function response_member( array $member ): array {
		$user_id        = is_int( $member['userId'] ?? null ) ? $member['userId'] : 0;
		$member['user'] = self::user_for_response( $user_id );

		return $member;
	}

	/**
	 * @param  array<string,mixed> $lock Lock.
	 * @return array<string,mixed>
	 */
	private static function response_lock( array $lock ): array {
		$user_id = is_int( $lock['userId'] ?? null ) ? $lock['userId'] : 0;
		if ( $user_id > 0 ) {
			$lock['user'] = self::user_for_response( $user_id );
		}

		return $lock;
	}

	private static function lock_conflict_error( ChatLockConflict $conflict ): \WP_Error {
		return new \WP_Error(
			'onumia_chat_locked',
			$conflict->getMessage(),
			array(
				'lock'   => self::response_lock( $conflict->lock() ),
				'status' => 423,
			)
		);
	}

	/**
	 * @return array{id:int,name:string,email:string,avatarUrl:string}
	 */
	private static function user_for_response( int $user_id ): array {
		$name  = '';
		$email = '';
		if ( function_exists( 'get_userdata' ) ) {
			$user = \get_userdata( $user_id );
			if ( is_object( $user ) ) {
				foreach ( array( 'display_name', 'user_login' ) as $key ) {
					if ( is_string( $user->{$key} ?? null ) && '' !== trim( $user->{$key} ) ) {
						$name = $user->{$key};
						break;
					}
				}
				if ( is_string( $user->user_email ?? null ) && '' !== trim( $user->user_email ) ) {
					$email = $user->user_email;
				}
			}
		}

		if ( '' === trim( $name ) ) {
			$name = '' !== trim( $email ) ? $email : "User {$user_id}";
		}

		$avatar_url = '';
		if ( function_exists( 'get_avatar_url' ) ) {
			$avatar     = \get_avatar_url( $user_id, array( 'size' => 48 ) );
			$avatar_url = is_string( $avatar ) ? $avatar : '';
		}

		return array(
			'id'        => $user_id,
			'name'      => $name,
			'email'     => $email,
			'avatarUrl' => $avatar_url,
		);
	}

	/**
	 * @return list<array<string,mixed>>|null
	 */
	private static function list_of_records( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return null;
		}

		$records = array();
		foreach ( $value as $item ) {
			$record = self::record( $item );
			if ( null === $record ) {
				return null;
			}

			$records[] = $record;
		}

		return $records;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function record( mixed $value ): ?array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			return null;
		}

		$record = array();
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) ) {
				return null;
			}

			$record[ $key ] = $item;
		}

		return $record;
	}
}
