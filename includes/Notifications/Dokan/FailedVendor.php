<?php

namespace Texty\Notifications\Dokan;

class FailedVendor extends Base {

    /**
     * Initialize
     */
    public function __construct() {
        $this->title = __( 'Vendor - When Order Status is Failed', 'texty' );
        $this->id    = 'order_dokan_failed';
        $this->group = 'dokan';
        $this->type  = 'vendor';

        $this->default = <<<'EOD'
Payment failed for order #{order_id}.
Customer: {billing_name}
Total: {order_total}
Site: {site_name}
EOD;
    }
}
