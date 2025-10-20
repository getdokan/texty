<?php

namespace Texty\Notifications\WC;

class CancelledCustomer extends Base {

    /**
     * Initialize
     */
    public function __construct() {
        $this->title = __( 'Customer - When Order Status is Cancelled', 'texty' );
        $this->id    = 'order_customer_cancelled';
        $this->group = 'wc';
        $this->type  = 'user';

        $this->default = <<<'EOD'
Hi {billing_name}, your order #{order_id} from {site_name} has been cancelled. If you have any questions, please contact our support.
EOD;
    }
}
