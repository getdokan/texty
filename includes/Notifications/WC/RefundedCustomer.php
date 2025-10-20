<?php

namespace Texty\Notifications\WC;

class RefundedCustomer extends Base {

    /**
     * Initialize
     */
    public function __construct() {
        $this->title = __( 'Customer - When Order Status is Refunded', 'texty' );
        $this->id    = 'order_customer_refunded';
        $this->group = 'wc';
        $this->type  = 'user';

        $this->default = <<<'EOD'
Hi {billing_name}, we have processed a refund for your order #{order_id} from {site_name}. The amount was {order_total}.
EOD;
    }
}
