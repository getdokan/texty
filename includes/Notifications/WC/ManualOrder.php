<?php

namespace Texty\Notifications\WC;

defined( 'ABSPATH' ) || exit;

/**
 * A one-off SMS written by an admin on the order screen.
 *
 * Not registered in the notifications registry (it has no settings and never
 * fires on its own). It exists so a manual message can reuse the WooCommerce
 * `{token}` replacements and so its log rows carry a recognisable
 * notification id (`order_manual`). Its send() is never used — the
 * `_texty_{id}` once-per-order guard in Base doesn't apply to manual SMS.
 *
 * @since 2.0.3
 */
class ManualOrder extends Base {

    /**
     * Notification id used on log rows.
     */
    const ID = 'order_manual';

    /**
     * @var string
     */
    protected $id = self::ID;

    /**
     * @var string
     */
    protected $group = 'wc';

    /**
     * The admin's message, with `{tokens}` not yet replaced.
     *
     * @var string
     */
    protected $custom_message = '';

    /**
     * Title shown in logs.
     *
     * @return string
     */
    public function get_title() {
        return __( 'Manual order SMS', 'texty' );
    }

    /**
     * Set the message to send.
     *
     * @param string $message Message with optional `{tokens}`.
     *
     * @return self
     */
    public function set_message( $message ) {
        $this->custom_message = (string) $message;

        return $this;
    }

    /**
     * The admin's message (tokens are replaced by Base::get_message()).
     *
     * @return string
     */
    public function get_message_raw() {
        return $this->custom_message;
    }

    /**
     * Settings for this one-off send.
     *
     * Base::get_message() reads the template through the parent class's
     * get_message_raw(), which takes it from settings(); returning the
     * admin's message here is what makes their text (not the stored
     * notification template) get its tokens replaced.
     *
     * @return array
     */
    public function settings() {
        return [
            'enabled' => true,
            'message' => $this->custom_message,
        ];
    }
}
