export interface TextyGlobal {
  version?: {
    lite?: string;
    business?: string;
  };
  docs_url?: string;
  support_url?: string;
  feature_request_url?: string;
  asset_url?: string;
  rest_url?: string;
  ajax_url?: string;
  nonce?: string;
}

declare global {
  interface Window {
    texty?: TextyGlobal;
  }
}
