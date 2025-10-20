<?php

namespace Texty\Notifications\Dokan;

class CancelledVendor extends Base {

    /**
     * Initialize
     */
    public function __construct() {
        $this->title = __( 'Vendor - When Order Status is Cancelled', 'texty' );
        $this->id    = 'order_dokan_cancelled';
        $this->group = 'dokan';
        $this->type  = 'vendor';

        $this->default = <<<'EOD'
Order #{order_id} has been cancelled.
Customer: {billing_name}
Total: {order_total}
Site: {site_name}
EOD;
    }
}
