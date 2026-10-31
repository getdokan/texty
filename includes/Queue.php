<?php

namespace Texty;

use ActionScheduler;
use Exception;
use Texty\Dependencies\WeDevs\WPKit\DataLayer\DataLayerFactory;
use Texty\Models\SmsStat;
use Texty\Models\SmsStatStore;
use Texty\Notifications\Notification;
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
 * Logs → Pending) holding the Action Scheduler action id. Whoever runs the
 * send first — the background worker or the "Send now" button — claims the
 * row by deleting it, so a notification is sent at most once; the normal
 * send then logs its sent/failed rows. Deleting a pending row cancels it.
 *
 * Only ids are queued; the worker rebuilds the notification and calls its
 * normal `send()`, so idempotency (WC order meta), logging and every
 * `texty_*` send hook behave exactly as in a synchronous send.
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

        if ( ! $this->should_queue( $notification_id, $context ) ) {
            $notification->send();

            return;
        }

        $row_id = $this->log_pending( $notification );

        // Not `unique`: Action Scheduler's uniqueness check compares only hook
        // and group, not args, so it would drop every other order's SMS while
        // one is pending. Repeat sends for the same order are already stopped
        // in the worker by WC\Base's `_texty_{id}` order meta.
        $action_id = as_enqueue_async_action( self::HOOK, [ $notification_id, $context, $row_id ], self::GROUP );

        $store = $this->store();

        if ( $row_id && $store ) {
            if ( $action_id ) {
                $store->update_by( [ 'id' => $row_id ], [ 'reference_id' => (string) $action_id ] );
            } else {
                // Couldn't queue: drop the placeholder and send inline instead.
                $store->delete_by_id( $row_id );
                $notification->send();
            }
        }
    }

    /**
     * Worker: claim the pending row, rebuild the notification and send it.
     *
     * @param string $notification_id Notification id.
     * @param array  $context         Ids captured by push().
     * @param int    $row_id          Pending log row id, 0 when none was written.
     *
     * @return bool False when another caller already claimed the row.
     */
    public function run( $notification_id, $context, $row_id = 0 ) {
        if ( $row_id && ! $this->claim( (int) $row_id ) ) {
            // Already sent via "Send now" or the worker, or cancelled by deleting the row.
            return false;
        }

        $notification = $this->build( (string) $notification_id, is_array( $context ) ? $context : [] );

        if ( $notification ) {
            $notification->send();
        }

        return true;
    }

    /**
     * Send a queued notification immediately, from its pending log row.
     *
     * @param int $row_id Pending log row id.
     *
     * @return true|WP_Error
     */
    public function send_now( $row_id ) {
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
            return new WP_Error( 'texty_not_pending', __( 'This message is no longer pending.', 'texty' ), [ 'status' => 409 ] );
        }

        $action_id = (int) ( $row['reference_id'] ?? 0 );
        $args      = $this->action_args( $action_id );

        if ( ! $args ) {
            return new WP_Error( 'texty_action_missing', __( 'The queued message could not be found.', 'texty' ), [ 'status' => 404 ] );
        }

        // Stop the background copy; if it already started, the row claim in
        // run() still guarantees only one of the two sends goes out.
        try {
            ActionScheduler::store()->cancel_action( $action_id );
        } catch ( Exception $e ) {
            unset( $e );
        }

        if ( ! $this->run( $args[0], $args[1], (int) $row_id ) ) {
            return new WP_Error( 'texty_not_pending', __( 'This message is no longer pending.', 'texty' ), [ 'status' => 409 ] );
        }

        return true;
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

        if ( ! empty( $context['user_id'] ) && method_exists( $notification, 'set_user' ) ) {
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
     * Write the `pending` log row for a queued notification.
     *
     * @param Notification $notification Bound notification.
     *
     * @return int Row id, 0 on failure.
     */
    protected function log_pending( Notification $notification ) {
        $store = $this->store();

        if ( ! $store ) {
            return 0;
        }

        try {
            $message = (string) $notification->get_message();
        } catch ( Exception $e ) {
            $message = '';
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
                'message'            => $message,
                'response'           => '',
                'reference_id'       => '',
                'created_at'         => $now,
                'updated_at'         => $now,
            ]
        );

        return (int) $store->create( $sms );
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
        } catch ( Exception $e ) {
            return null;
        }

        if ( ! is_array( $args ) || ! isset( $args[0], $args[1] ) || ! is_array( $args[1] ) ) {
            return null;
        }

        return $args;
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
