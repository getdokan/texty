<?php

namespace Texty\Notifications\WC;

class RefundedAdmin extends Base {

    /**
     * Initialize
     */
    public function __construct() {
        $this->title              = __( 'Admin - When Order Status is Refunded', 'texty' );
        $this->id                 = 'order_admin_refunded';
        $this->group              = 'wc';
        $this->default_recipients = [ 'administrator' ];

        $this->default = <<<'EOD'
A refund has been processed for order #{order_id}.
Customer: {billing_name}
Refund Amount: {order_total}
Site: {site_name}
EOD;
    }
}
