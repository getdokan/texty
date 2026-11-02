export interface TextyGlobal {
  version?: {
    lite?: string;
    pro?: string;
  };
  docs_url?: string;
  support_url?: string;
  feature_request_url?: string;
  asset_url?: string;
  rest_url?: string;
  ajax_url?: string;
  nonce?: string;
  /** Lowercase ISO 3166-1 alpha-2 code from WooCommerce / site locale, or ''. */
  default_country?: string;
}

declare global {
  interface Window {
    texty?: TextyGlobal;
  }
}
