<?php

namespace Texty\Notifications\WC;

class FailedCustomer extends Base {

    /**
     * Initialize
     */
    public function __construct() {
        $this->title = __( 'Customer - When Order Status is Failed', 'texty' );
        $this->id    = 'order_customer_failed';
        $this->group = 'wc';
        $this->type  = 'user';

        $this->default = <<<'EOD'
Hi {billing_name}, your payment for order #{order_id} on {site_name} failed. Please try placing your order again or contact us for assistance.
EOD;
    }
}
