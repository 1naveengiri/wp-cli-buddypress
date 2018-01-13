<?php
/**
 * Manage BuddyPress Messages.
 *
 * @since 1.6.0
 */
class BPCLI_Message extends BPCLI_Component {

	/**
	 * Object fields.
	 *
	 * @var array
	 */
	protected $obj_fields = array(
		'id',
		'subject',
		'message',
	);

	/**
	 * Add a message.
	 *
	 * ## OPTIONS
	 *
	 * --from=<user>
	 * : Identifier for the user. Accepts either a user_login or a numeric ID.
	 *
	 * --to=<user>
	 * : Identifier for the recipient. Accepts either a user_login or a numeric ID.
	 *
	 * [--subject=<subject>]
	 * : Subject of the message.
	 * ---
	 * default: Message Subject.
	 * ---
	 *
	 * [--content=<content>]
	 * : Content of the message.
	 * ---
	 * default: Random content.
	 * ---
	 *
	 * [--thread-id=<thread-id>]
	 * : Thread ID.
	 * ---
	 * default: false
	 * ---
	 *
	 * [--date-sent=<date-sent>]
	 * : MySQL-formatted date.
	 * ---
	 * default: current date.
	 * ---
	 *
	 * [--silent=<silent>]
	 * : Whether to silent the message creation.
	 * ---
	 * default: false.
	 * ---
	 *
	 * [--porcelain]
	 * : Return the thread id of the message.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp bp message create --from=user1 --to=user2 --subject="Message Title" --content="We are ready"
	 *     Success: Message successfully created.
	 *
	 *     $ wp bp message add --from=545 --to=313
	 *     Success: Message successfully created.
	 *
	 * @alias add
	 */
	public function create( $args, $assoc_args ) {
		$r = wp_parse_args( $assoc_args, array(
			'subject'   => sprintf( 'Message Subject' ),
			'content'   => $this->generate_random_text(),
			'thread-id' => false,
			'date-sent' => bp_core_current_time(),
			'silent'    => false,
		) );

		$user = $this->get_user_id_from_identifier( $assoc_args['from'] );
		$recipient = $this->get_user_id_from_identifier( $assoc_args['to'] );
		if ( ! $user || ! $recipient ) {
			WP_CLI::error( 'No user found by that username or ID.' );
		}

		$thread_id = messages_new_message( array(
			'sender_id'  => $user->ID,
			'recipients' => array( $recipient->ID ),
			'subject'    => $r['subject'],
			'content'    => $r['content'],
			'thread_id'  => $r['thread-id'],
			'date_sent'  => $r['date-sent'],
		) );

		if ( ! is_numeric( $thread_id ) ) {
			WP_CLI::error( 'Could not add a message.' );
		}

		if ( $r['silent'] ) {
			return;
		}

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain' ) ) {
			WP_CLI::line( $thread_id );
		} else {
			WP_CLI::success( 'Message successfully created.' );
		}
	}

	/**
	 * Delete message thread(s) for a given user.
	 *
	 * ## OPTIONS
	 *
	 * <thread-id>...
	 * : Thread ID(s).
	 *
	 * --user-id=<user>
	 * : Identifier for the user. Accepts either a user_login or a numeric ID.
	 *
	 * [--yes]
	 * : Answer yes to the confirmation message.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp bp message delete 500 687867 --user-id=40
	 *     Success: Thread successfully deleted.
	 *
	 *     $ wp bp message delete 564 5465465 456456 --user-id=user_logon --yes
	 *     Success: Thread successfully deleted.
	 *
	 * @alias remove
	 */
	public function delete( $args, $assoc_args ) {
		$thread_id = $args[0];

		$user = $this->get_user_id_from_identifier( $assoc_args['user-id'] );
		if ( ! $user ) {
			WP_CLI::error( 'No user found by that username or ID.' );
		}
		$user_id = $user->ID;

		WP_CLI::confirm( 'Are you sure you want to delete this thread(s) ?', $assoc_args );

		parent::_delete( array( $thread_id ), $assoc_args, function( $thread_id ) {

			// Bail if the user has no access to the thread.
			$msg_id = messages_check_thread_access( $thread_id, $user_id );
			if ( ! is_numeric( $msg_id ) ) {
				WP_CLI::error( 'This user has no access to this thread.' );
			}

			if ( messages_delete_thread( $thread_id, $user_id ) ) {
				return array( 'success', 'Thread successfully deleted.' );
			} else {
				return array( 'error', 'Could not delete the thread.' );
			}
		} );
	}

	/**
	 * Get a message.
	 *
	 * ## OPTIONS
	 *
	 * <message-id>
	 * : Identifier for the message.
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific fields.
	 * ---
	 * default: All fields.
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - haml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp bp message get 5465
	 *     $ wp bp message see 5454
	 *
	 * @alias see
	 */
	public function get( $args, $assoc_args ) {
		$m = new BP_Messages_Message( $args[0] );
		$message_arr = get_object_vars( $m );

		if ( empty( $assoc_args['fields'] ) ) {
			$assoc_args['fields'] = array_keys( $message_arr );
		}

		$formatter = $this->get_formatter( $assoc_args );
		$formatter->display_item( $message_arr );
	}

	/**
	 * Get a list of messages.
	 *
	 * ## OPTIONS
	 *
	 * [--<field>=<value>]
	 * : One or more parameters to pass. See BP_Messages_Box_Template
	 *
	 * [--fields=<fields>]
	 * : Fields to display.
	 *
	 * [--<count>=<count>]
	 * : How many messages to list.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - ids
	 *   - count
	 *   - csv
	 *   - json
	 *   - haml
	 * ---
	 *
	 * ## EXAMPLE
	 *
	 *     $ wp bp message list --count=12 --format=count
	 *     10
	 *
	 * @subcommand list
	 */
	public function _list( $_, $assoc_args ) {
		$formatter = $this->get_formatter( $assoc_args );

		$r = wp_parse_args( $assoc_args, array(
			'user-id'      => '',
			'box'          => 'sentbox',
			'type'         => 'all',
			'search'       => '',
			'count'        => 10,
		) );

		$user = $this->get_user_id_from_identifier( $r['user-id'] );
		if ( empty( $r['user-id'] ) || ! $user ) {
			WP_CLI::error( 'No user found by that username or ID.' );
		}

		$type = $r['type'];
		if ( ! in_array( $r['type'], $this->message_types(), true ) ) {
			$type = 'all';
		}

		$box = $r['box'];
		if ( ! in_array( $r['box'], $this->message_boxes(), true ) ) {
			$box = 'sentbox';
		}

		$inbox = new BP_Messages_Box_Template( array(
			'user_id'      => $user->ID,
			'box'          => $box,
			'type'         => $type,
			'max'          => $r['count'],
			'search_terms' => $r['search'],
		) );

		if ( ! $inbox->has_threads() ) {
			WP_CLI::error( 'No messages found.' );
		}

		$messages = $inbox->threads->messages;

		if ( 'ids' === $formatter->format ) {
			echo implode( ' ', wp_list_pluck( $messages, 'id' ) ); // WPCS: XSS ok.
		} elseif ( 'count' === $formatter->format ) {
			$formatter->display_items( $messages );
		} else {
			$formatter->display_items( $messages );
		}
	}

	/**
	 * Generate random messages.
	 *
	 * ## OPTIONS
	 *
	 * [--thread-id=<thread-id>]
	 * : Thread ID to generate messages against.
	 * ---
	 * default: false
	 * ---
	 *
	 * [--count=<number>]
	 * : How many messages to generate.
	 * ---
	 * default: 20
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp bp message generate --thread-id=6465 --count=10
	 *     $ wp bp message generate --count=100
	 */
	public function generate( $args, $assoc_args ) {
		$notify = \WP_CLI\Utils\make_progress_bar( 'Generating messages', $assoc_args['count'] );

		for ( $i = 0; $i < $assoc_args['count']; $i++ ) {
			$this->create( array(), array(
				'from'      => $this->get_random_user_id(),
				'to'        => $this->get_random_user_id(),
				'subject'   => sprintf( 'Message Subject - #%d', $i ),
				'thread-id' => $assoc_args['thread-id'],
				'silent'    => true,
			) );

			$notify->tick();
		}

		$notify->finish();
	}

	/**
	 * Star a message.
	 *
	 * ## OPTIONS
	 *
	 * --message-id=<message-id>
	 * : Message ID to star.
	 *
	 * --user-id=<user>
	 * : User that is starring the message. Accepts either a user_login or a numeric ID.
	 *
	 * ## EXAMPLE
	 *
	 *     $ wp bp message star --message-id=3543 --user-id=user_login
	 *     Success: Message was successfully starred.
	 */
	public function star( $args, $assoc_args ) {
		$user = $this->get_user_id_from_identifier( $assoc_args['user-id'] );
		if ( ! $user ) {
			WP_CLI::error( 'No user found by that username or ID.' );
		}

		$user_id = $user->ID;
		$msg_id  = (int) $assoc_args['message-id'];

		if ( bp_messages_is_message_starred( $msg_id, $user_id ) ) {
			WP_CLI::error( 'The message is already starred.' );
		}

		$star_args = array(
			'action'     => 'star',
			'message_id' => $msg_id,
			'user_id'    => $user_id,
		);

		if ( bp_messages_star_set_action( $star_args ) ) {
			WP_CLI::success( 'Message was successfully starred.' );
		} else {
			WP_CLI::error( 'Message was not starred.' );
		}
	}

	/**
	 * Unstar a thread.
	 *
	 * ## OPTIONS
	 *
	 * --thread-id=<thread-id>
	 * : Thread ID to unstar.
	 *
	 * --user-id=<user>
	 * : User that is unstarring the thread. Accepts either a user_login or a numeric ID.
	 *
	 * ## EXAMPLE
	 *
	 *     $ wp bp message unstar --thread-id=212 --user-id=another_user_login
	 *     Success: Message was successfully unstarred.
	 */
	public function unstar( $args, $assoc_args ) {
		$user = $this->get_user_id_from_identifier( $assoc_args['user-id'] );
		if ( ! $user ) {
			WP_CLI::error( 'No user found by that username or ID.' );
		}

		$star_args = array(
			'action'    => 'unstar',
			'thread_id' => (int) $assoc_args['thread-id'],
			'user_id'   => $user->ID,
			'bulk'      => true,
		);

		if ( bp_messages_star_set_action( $star_args ) ) {
			WP_CLI::success( 'Message was successfully unstarred.' );
		} else {
			WP_CLI::error( 'Message was not unstarred.' );
		}
	}

	/**
	 * Send a notice.
	 *
	 * ## OPTIONS
	 *
	 * [--subject=<subject>]
	 * : Subject of the notice/message.
	 *
	 * [--content=<content>]
	 * : Content of the message.
	 *
	 * ## EXAMPLE
	 *
	 *     $ wp bp message send --subject="Important notice" --content="We need to improve"
	 *     Success: Notice was successfully sent.
	 *
	 * @alias send_notice
	 */
	public function send( $args, $assoc_args ) {
		if ( empty( $assoc_args['subject'] ) ) {
			$assoc_args['subject'] = sprintf( 'Random Notice Subject' );
		}

		if ( empty( $assoc_args['content'] ) ) {
			$assoc_args['content'] = $this->generate_random_text();
		}

		if ( messages_send_notice( $assoc_args['subject'], $assoc_args['content'] ) ) {
			WP_CLI::success( 'Notice was successfully sent.' );
		} else {
			WP_CLI::error( 'Notice was not sent.' );
		}
	}

	/**
	 * Message Types.
	 *
	 * @since 1.6.0
	 *
	 * @return array An array of message types.
	 */
	protected function message_types() {
		return array( 'all', 'read', 'unread' );
	}

	/**
	 * Message Boxes.
	 *
	 * @since 1.6.0
	 *
	 * @return array An array of message boxes.
	 */
	protected function message_boxes() {
		return array( 'notices', 'sentbox', 'inbox' );
	}
}

WP_CLI::add_command( 'bp message', 'BPCLI_Message', array(
	'before_invoke' => function() {
		if ( ! bp_is_active( 'messages' ) ) {
			WP_CLI::error( 'The Message component is not active.' );
		}
	},
) );

