<?php

namespace Texty;

use ActionScheduler;
use Exception;
use Texty\Dependencies\WeDevs\WPKit\DataLayer\DataLayerFactory;
use Texty\Models\SmsStat;
use Texty\Models\SmsStatStore;
use Texty\Notifications\Notification;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Background dispatch for notifications.
 *
 * Event handlers (order status change, user registration, comment) call
 * `push()` instead of sending inline. When Action Scheduler is available
 * (bundled with WooCommerce) the send is queued as an async action, so the
 * triggering request — a customer checkout, an admin bulk status change —
 * never waits on gateway HTTP calls. Otherwise it sends immediately, as before.
 *
 * Every queued send gets a `pending` row in the SMS log (shown under
 * Logs → Pending). The row stores the Action Scheduler action id in
 * `reference_id` and the queued context in `response`, so it can be sent or
 * cancelled from the Logs screen even if the action itself is gone. Whoever
 * acts first — the background worker, "Send now" or "Cancel" — claims the row
 * by deleting it, so a notification is sent at most once; the normal send
 * then logs its sent/failed rows.
 *
 * The message is rendered when the notification is queued, so tokens such as
 * `{status}` reflect the event that triggered it rather than whatever the
 * order looks like when the worker runs. Recipients and every `texty_*` send
 * hook still run in the worker through the notification's normal `send()`, so
 * idempotency (WC order meta) and logging are unchanged.
 *
 * @since 2.0.3
 */
class Queue {

    /**
     * Action Scheduler hook the worker listens on.
     */
    const HOOK = 'texty_send_notification';

    /**
     * Action Scheduler group.
     */
    const GROUP = 'texty';

    /**
     * Outcome: the pending row was already claimed by another caller.
     */
    const CLAIMED_ELSEWHERE = 'claimed';

    /**
     * Outcome: the notification sent to at least one recipient.
     */
    const SENT = 'sent';

    /**
     * Outcome: nothing was sent (disabled, already sent, no recipients, all failed).
     */
    const NOT_SENT = 'not_sent';

    /**
     * Register the worker.
     */
    public function __construct() {
        add_action( self::HOOK, [ $this, 'run' ], 10, 3 );
    }

    /**
     * Queue a notification, or send it now when queueing isn't available.
     *
     * @param string $notification_id Registered notification id, e.g. `order_customer_processing`.
     * @param array  $context         Ids to bind: `order_id`, `vendor_id`, `user_id` or `comment_id`.
     *
     * @return void
     */
    public function push( $notification_id, array $context ) {
        $notification = $this->build( $notification_id, $context );

        // Disabled notifications never reach the queue.
        if ( ! $notification || ! $notification->enabled() ) {
            return;
        }

        // Already sent for this order (WC\Base's `_texty_{id}` meta): don't
        // queue a row that the worker would silently drop.
        if ( method_exists( $notification, 'has_sent' ) && $notification->has_sent() ) {
            return;
        }

        if ( ! $this->should_queue( $notification_id, $context ) ) {
            $notification->send();

            return;
        }

        $context['message'] = $this->render_message( $notification );

        $row_id    = $this->log_pending( $notification, $notification_id, $context );
        $action_id = 0;

        try {
            // Not `unique`: Action Scheduler's uniqueness check compares only
            // hook and group, not args, so it would drop every other order's
            // SMS while one is pending.
            $action_id = (int) as_enqueue_async_action( self::HOOK, [ $notification_id, $context, $row_id ], self::GROUP );
        } catch ( Throwable $e ) {
            $action_id = 0;
        }

        if ( ! $action_id ) {
            // Nothing was queued: drop the placeholder and send inline so the
            // notification is never lost.
            if ( $row_id ) {
                $this->claim( $row_id );
            }

            $notification->send();

            return;
        }

        if ( $row_id ) {
            $this->update_row( $row_id, [ 'reference_id' => (string) $action_id ] );
        }
    }

    /**
     * Worker: claim the pending row, rebuild the notification and send it.
     *
     * @param string $notification_id Notification id.
     * @param array  $context         Context captured by push().
     * @param int    $row_id          Pending log row id, 0 when none was written.
     *
     * @return void
     */
    public function run( $notification_id, $context, $row_id = 0 ) {
        $this->execute( (string) $notification_id, is_array( $context ) ? $context : [], (int) $row_id );
    }

    /**
     * Send a queued notification immediately, from its pending log row.
     *
     * @param int $row_id Pending log row id.
     *
     * @return true|WP_Error
     */
    public function send_now( $row_id ) {
        $queued = $this->queued_from_row( $row_id );

        if ( is_wp_error( $queued ) ) {
            return $queued;
        }

        // Stop the background copy; if it already started, the row claim in
        // execute() still guarantees only one of the two sends goes out.
        $this->cancel_action( $queued['action_id'] );

        $outcome = $this->execute( $queued['notification_id'], $queued['context'], (int) $row_id );

        if ( self::CLAIMED_ELSEWHERE === $outcome ) {
            return $this->not_pending_error();
        }

        if ( self::NOT_SENT === $outcome ) {
            return new WP_Error(
                'texty_not_sent',
                __( 'The message was not sent: it may be disabled, already sent, or have no recipients. Check the log for details.', 'texty' ),
                [ 'status' => 422 ]
            );
        }

        return true;
    }

    /**
     * Cancel a queued notification without sending it.
     *
     * @param int $row_id Pending log row id.
     *
     * @return true|WP_Error
     */
    public function cancel( $row_id ) {
        $queued = $this->queued_from_row( $row_id );

        if ( is_wp_error( $queued ) && 'texty_action_missing' !== $queued->get_error_code() ) {
            return $queued;
        }

        if ( ! $this->claim( (int) $row_id ) ) {
            return $this->not_pending_error();
        }

        if ( ! is_wp_error( $queued ) ) {
            $this->cancel_action( $queued['action_id'] );
        }

        return true;
    }

    /**
     * Claim the row (if any), rebuild the notification and send it.
     *
     * @param string $notification_id Notification id.
     * @param array  $context         Context captured by push().
     * @param int    $row_id          Pending log row id, 0 when none.
     *
     * @return string One of the outcome constants.
     */
    protected function execute( $notification_id, array $context, $row_id ) {
        if ( $row_id && ! $this->claim( $row_id ) ) {
            // Already sent or cancelled by another caller.
            return self::CLAIMED_ELSEWHERE;
        }

        $notification = $this->build( $notification_id, $context );

        if ( ! $notification ) {
            return self::NOT_SENT;
        }

        $snapshot = isset( $context['message'] ) && is_string( $context['message'] ) && '' !== $context['message']
            ? $context['message']
            : null;

        // Send the message as it was rendered at queue time. Priority 0 so
        // every other `texty_notification_message` filter still runs after it.
        $use_snapshot = static function ( $content, $instance ) use ( $snapshot, $notification ) {
            return ( null !== $snapshot && $instance === $notification ) ? $snapshot : $content;
        };

        add_filter( 'texty_notification_message', $use_snapshot, 0, 2 );

        try {
            $sent = $notification->send();
        } finally {
            remove_filter( 'texty_notification_message', $use_snapshot, 0 );
        }

        return $sent ? self::SENT : self::NOT_SENT;
    }

    /**
     * Instantiate a notification and bind its context.
     *
     * @param string $notification_id Notification id.
     * @param array  $context         Ids to bind.
     *
     * @return Notification|null Null when the class or a bound object is missing.
     */
    protected function build( $notification_id, array $context ) {
        $class = texty()->notifications()->get( $notification_id );

        if ( ! $class || ! class_exists( $class ) ) {
            return null;
        }

        $notification = new $class();

        if ( ! empty( $context['order_id'] ) ) {
            $order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $context['order_id'] ) : false;

            if ( ! $order || ! method_exists( $notification, 'set_order' ) ) {
                return null;
            }

            $notification->set_order( $order );
        }

        if ( ! empty( $context['vendor_id'] ) && method_exists( $notification, 'set_vendor' ) ) {
            $notification->set_vendor( (int) $context['vendor_id'] );
        }

        if ( ! empty( $context['user_id'] ) ) {
            if ( ! get_userdata( (int) $context['user_id'] ) || ! method_exists( $notification, 'set_user' ) ) {
                return null;
            }

            $notification->set_user( (int) $context['user_id'] );
        }

        if ( ! empty( $context['comment_id'] ) ) {
            if ( ! get_comment( (int) $context['comment_id'] ) || ! method_exists( $notification, 'set_comment' ) ) {
                return null;
            }

            $notification->set_comment( (int) $context['comment_id'] );
        }

        return $notification;
    }

    /**
     * Whether this send should go through Action Scheduler.
     *
     * @param string $notification_id Notification id.
     * @param array  $context         Ids to bind.
     *
     * @return bool
     */
    protected function should_queue( $notification_id, array $context ) {
        $available = function_exists( 'as_enqueue_async_action' )
            && class_exists( ActionScheduler::class )
            && method_exists( ActionScheduler::class, 'is_initialized' )
            && ActionScheduler::is_initialized();

        /**
         * Filter whether a notification is sent in the background.
         *
         * Return false to send inline during the triggering request (the
         * pre-2.0.3 behaviour). Ignored when Action Scheduler isn't loaded.
         *
         * @since 2.0.3
         *
         * @param bool   $async           Whether to queue the send. Default true.
         * @param string $notification_id Notification id.
         * @param array  $context         Ids bound to the notification.
         */
        return $available && (bool) apply_filters( 'texty_send_async', true, $notification_id, $context );
    }

    /**
     * Render the notification message now, for the queue-time snapshot.
     *
     * @param Notification $notification Bound notification.
     *
     * @return string
     */
    protected function render_message( Notification $notification ) {
        try {
            return (string) $notification->get_message();
        } catch ( Throwable $e ) {
            return '';
        }
    }

    /**
     * Write the `pending` log row for a queued notification.
     *
     * @param Notification $notification    Bound notification.
     * @param string       $notification_id Notification id.
     * @param array        $context         Queued context (ids + message snapshot).
     *
     * @return int Row id, 0 on failure.
     */
    protected function log_pending( Notification $notification, $notification_id, array $context ) {
        $store = $this->store();

        if ( ! $store ) {
            return 0;
        }

        $gateway = texty()->gateways()->active_gateway();
        $now     = current_time( 'mysql' );

        $sms = new SmsStat();
        $sms->set_props(
            [
                'receiver'           => '',
                'gateway'            => $gateway ? (string) $gateway->name() : '',
                'status'             => 'pending',
                'notification_id'    => (string) $notification->get_id(),
                'notification_group' => (string) $notification->get_group(),
                'message'            => isset( $context['message'] ) ? (string) $context['message'] : '',
                'response'           => wp_json_encode(
                    [
                        'queued' => [
                            'notification_id' => $notification_id,
                            'context'         => $context,
                        ],
                    ]
                ),
                'reference_id'       => '',
                'created_at'         => $now,
                'updated_at'         => $now,
            ]
        );

        try {
            return (int) $store->create( $sms );
        } catch ( Throwable $e ) {
            return 0;
        }
    }

    /**
     * Load a pending row and resolve what it queued.
     *
     * Prefers the context stored on the row; falls back to the Action
     * Scheduler action args for rows written before the context was stored.
     *
     * @param int $row_id Pending log row id.
     *
     * @return array{notification_id:string,context:array,action_id:int}|WP_Error
     */
    protected function queued_from_row( $row_id ) {
        $store = $this->store();

        if ( ! $store ) {
            return new WP_Error( 'texty_no_store', __( 'Logs store unavailable.', 'texty' ), [ 'status' => 500 ] );
        }

        $result = $store->query(
            [
                'id'          => (int) $row_id,
                'per_page'    => 1,
                'count_total' => false,
                'no_cache'    => true,
            ]
        );
        $row    = isset( $result['items'][0] ) ? (array) $result['items'][0] : null;

        if ( ! $row || 'pending' !== ( $row['status'] ?? '' ) ) {
            return $this->not_pending_error();
        }

        $action_id = (int) ( $row['reference_id'] ?? 0 );
        $stored    = json_decode( (string) ( $row['response'] ?? '' ), true );

        if ( isset( $stored['queued']['notification_id'], $stored['queued']['context'] ) && is_array( $stored['queued']['context'] ) ) {
            return [
                'notification_id' => (string) $stored['queued']['notification_id'],
                'context'         => $stored['queued']['context'],
                'action_id'       => $action_id,
            ];
        }

        $args = $this->action_args( $action_id );

        if ( ! $args ) {
            return new WP_Error( 'texty_action_missing', __( 'The queued message could not be found.', 'texty' ), [ 'status' => 404 ] );
        }

        return [
            'notification_id' => (string) $args[0],
            'context'         => $args[1],
            'action_id'       => $action_id,
        ];
    }

    /**
     * Claim a pending row by deleting it; only one caller can succeed.
     *
     * @param int $row_id Pending log row id.
     *
     * @return bool True when this caller deleted the row.
     */
    protected function claim( $row_id ) {
        $store = $this->store();

        if ( ! $store ) {
            return true;
        }

        try {
            $where = [
                'id'     => $row_id,
                'status' => 'pending',
            ];

            return $store->delete_by( $where ) > 0;
        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * Update columns on a log row, swallowing store errors.
     *
     * @param int   $row_id Row id.
     * @param array $data   Column => value.
     *
     * @return void
     */
    protected function update_row( $row_id, array $data ) {
        $store = $this->store();

        if ( ! $store ) {
            return;
        }

        try {
            $store->update_by( [ 'id' => $row_id ], $data );
        } catch ( Exception $e ) {
            unset( $e );
        }
    }

    /**
     * Cancel a queued Action Scheduler action, if it still exists.
     *
     * @param int $action_id Action Scheduler action id.
     *
     * @return void
     */
    protected function cancel_action( $action_id ) {
        if ( ! $action_id || ! class_exists( ActionScheduler::class ) ) {
            return;
        }

        try {
            ActionScheduler::store()->cancel_action( $action_id );
        } catch ( Throwable $e ) {
            unset( $e );
        }
    }

    /**
     * The [notification_id, context] args of a queued action.
     *
     * @param int $action_id Action Scheduler action id.
     *
     * @return array|null
     */
    protected function action_args( $action_id ) {
        if ( ! $action_id || ! class_exists( ActionScheduler::class ) ) {
            return null;
        }

        try {
            $args = ActionScheduler::store()->fetch_action( $action_id )->get_args();
        } catch ( Throwable $e ) {
            return null;
        }

        if ( ! is_array( $args ) || ! isset( $args[0], $args[1] ) || ! is_array( $args[1] ) ) {
            return null;
        }

        return $args;
    }

    /**
     * The "no longer pending" REST error.
     *
     * @return WP_Error
     */
    protected function not_pending_error() {
        return new WP_Error( 'texty_not_pending', __( 'This message is no longer pending.', 'texty' ), [ 'status' => 409 ] );
    }

    /**
     * The SMS log store.
     *
     * @return SmsStatStore|null
     */
    protected function store() {
        $store = DataLayerFactory::make_store( SmsStat::class );

        return $store instanceof SmsStatStore ? $store : null;
    }
}
