<?php

namespace Texty\Notifications\WP;

use Texty\Notifications\Notification;

class Registration extends Notification {

    /**
     * @var int
     */
    private $user_id;

    /**
     * Initialize
     */
    public function __construct() {
        $this->title              = __( 'New User Registration', 'texty' );
        $this->id                 = 'registration';
        $this->default_recipients = [ 'administrator' ];

        $this->default = <<<'EOD'
A new user registered on your site with the username "{username}".

Name: {display_name}
Email: {email}
Role: {role}
EOD;
    }

    /**
     * Set the user ID
     *
     * @param int $user_id
     *
     * @return self
     */
    public function set_user( $user_id ) {
        $this->user_id = $user_id;

        return $this;
    }

    /**
     * Return the message
     *
     * @return string
     */
    public function get_message() {
        $message = parent::get_message_raw();

        if ( ! $this->user_id ) {
            return $message;
        }

        $user = get_user_by( 'id', $this->user_id );

        if ( ! $user ) {
            // User deleted before the send: blank the user tokens (as before)
            // rather than leaving `{username}` etc. in the SMS.
            return $this->render_placeholders( $message, array_fill_keys( array_keys( $this->replacement_keys() ), '' ) );
        }

        $values = [];

        foreach ( $this->replacement_keys() as $search => $value ) {
            $value = isset( $user->$value ) ? $user->$value : '';

            if ( 'role' === $search ) {
                $roles    = [];
                $wp_roles = wp_roles();

                foreach ( $user->roles as $role ) {
                    if ( isset( $wp_roles->role_names[ $role ] ) ) {
                        $roles[] = $wp_roles->role_names[ $role ];
                    }
                }

                $value = implode( ', ', $roles );
            }

            $values[ $search ] = (string) $value;
        }

        // Applies third-party additions / overrides / removals too.
        return $this->render_placeholders( $message, $values );
    }

    /**
     * The registered user's ID, for placeholder callbacks.
     *
     * @since 2.0.3
     *
     * @return int
     */
    public function get_user_id() {
        return (int) $this->user_id;
    }

    /**
     * Return recipients
     *
     * @return array
     */
    public function get_recipients() {
        return $this->get_numbers_by_roles();
    }

    /**
     * Get replacement keys
     *
     * @return array
     */
    public function replacement_keys() {
        return [
            'user_id'      => 'ID',
            'username'     => 'user_login',
            'email'        => 'user_email',
            'display_name' => 'display_name',
            'first_name'   => 'first_name',
            'last_name'    => 'last_name',
            'role'         => 'role',
        ];
    }
}
