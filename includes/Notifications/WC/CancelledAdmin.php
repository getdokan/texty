<?php

namespace Texty\Notifications\WC;

class CancelledAdmin extends Base {

    /**
     * Initialize
     */
    public function __construct() {
        $this->title              = __( 'Admin - When Order Status is Cancelled', 'texty' );
        $this->id                 = 'order_admin_cancelled';
        $this->group              = 'wc';
        $this->default_recipients = [ 'administrator' ];

        $this->default = <<<'EOD'
Order #{order_id} has been cancelled.
Customer: {billing_name}
Total: {order_total}
Site: {site_name}
EOD;
    }
}
