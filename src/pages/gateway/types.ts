/**
 * Gateway metadata sent on each gateway page element as `meta`
 * (see `Texty\Gateways\Metadata` on the PHP side).
 */
export type GatewayMeta = {
  status: 'stable' | 'beta';
  docs_url: string;
  signup_url: string;
  regions: string[];
  features: {
    unicode: boolean;
    sender_id: boolean;
    template: boolean;
    balance_check: boolean;
  };
  validation: 'live' | 'format_only';
  template_required: boolean;
  sandbox: boolean;
};
