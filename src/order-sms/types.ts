export type Suggestion = {
  type: 'billing' | 'shipping' | 'vendor' | string;
  label: string;
  phone: string;
};

export type OrderSmsConfig = {
  orderId: number;
  suggestions: Suggestion[];
  /** `{token}` => human label. */
  tokens: Record<string, string>;
  /** Active gateway's name; empty when none is active. */
  gateway: string;
  settingsUrl: string;
  maxLength: number;
};

export type OrderNote = {
  id: number;
  content: string;
  date: string;
  author: string;
};

export type SendResponse = {
  success: boolean;
  content: string;
  note: OrderNote;
};
