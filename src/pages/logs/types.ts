export type LogStatus = 'sent' | 'failed' | 'pending' | string;

export type LogItem = {
  id: number;
  receiver: string;
  gateway: string;
  status: LogStatus;
  notification_id: string;
  notification_group: string;
  type_label: string;
  message: string;
  response: string;
  reference_id: string;
  created_at: string;
  created_at_formatted: string;
  updated_at: string;
};

export type LogsResponse = {
  items: LogItem[];
  total: number;
  per_page: number;
  current_page: number;
  total_pages: number;
};

export type LogsFilters = {
  page: number;
  per_page: number;
  status: '' | 'sent' | 'failed' | 'pending';
  type: string;
  search: string;
};
