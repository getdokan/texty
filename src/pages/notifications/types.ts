export type NotificationGroupInfo = {
  title: string;
  description: string;
  available: boolean;
};

export type RoleOption = {
  label: string;
  value: string;
};

export type NotificationItem = {
  id: string;
  enabled: boolean;
  title: string;
  type: 'role' | string;
  message: string;
  route: string;
  group: string;
  recipients: string[];
  replacements: string[];
};

export type NotificationsResponse = {
  groups: Record<string, NotificationGroupInfo>;
  roles: RoleOption[];
  notifications: Record<string, NotificationItem>;
};

export type NotificationPatch = {
  enabled?: boolean;
  message?: string;
  recipients?: string[];
};
