<?php

namespace Texty\Notifications\WC;

class FailedAdmin extends Base {

    /**
     * Initialize
     */
    public function __construct() {
        $this->title              = __( 'Admin - When Order Status is Failed', 'texty' );
        $this->id                 = 'order_admin_failed';
        $this->group              = 'wc';
        $this->default_recipients = [ 'administrator' ];

        $this->default = <<<'EOD'
Payment failed for order #{order_id}.
Customer: {billing_name}
Total: {order_total}
Site: {site_name}
EOD;
    }
}
