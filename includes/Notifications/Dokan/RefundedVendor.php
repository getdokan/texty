<?php

namespace Texty\Notifications\Dokan;

class RefundedVendor extends Base {

    /**
     * Initialize
     */
    public function __construct() {
        $this->title = __( 'Vendor - When Order Status is Refunded', 'texty' );
        $this->id    = 'order_dokan_refunded';
        $this->group = 'dokan';
        $this->type  = 'vendor';

        $this->default = <<<'EOD'
A refund has been processed for order #{order_id}.
Customer: {billing_name}
Refund Amount: {order_total}
Site: {site_name}
EOD;
    }
}
