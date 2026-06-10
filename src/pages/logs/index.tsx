import {
  Button,
  DataViews,
  type DataViewField,
  type DataViewState,
} from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';
import { Download } from 'lucide-react';
import { useMemo, useState } from 'react';

import LogDetailDialog from './components/LogDetailDialog';
import StatusBadge from './components/StatusBadge';
import { useLogs } from './hooks/useLogs';
import type { LogItem, LogsFilters } from './types';

type LogsView = DataViewState & {
  search?: string;
  status?: '' | 'sent' | 'failed' | 'pending';
};

const DEFAULT_VIEW: LogsView = {
  type: 'table',
  page: 1,
  perPage: 30,
  fields: ['created_at', 'type_label', 'status', 'details'],
  search: '',
  status: '',
  layout: {
    styles: {
      created_at: {
        width: '25%',
      },
      type_label: {
        width: '25%',
      },
      status: {
        width: '15%',
      },
      details: {
        width: '35%',
      },
    },
  },
};

const Logs = () => {
  const [view, setView] = useState<LogsView>(DEFAULT_VIEW);
  const [activeLog, setActiveLog] = useState<LogItem | null>(null);
  const [detailOpen, setDetailOpen] = useState<boolean>(false);

  const filters: LogsFilters = useMemo(
    () => ({
      page: view.page ?? 1,
      per_page: view.perPage ?? 10,
      status: view.status ?? '',
      type: '',
      search: view.search ?? '',
    }),
    [view.page, view.perPage, view.status, view.search]
  );

  const { data, loading } = useLogs(filters);

  const handleView = (log: LogItem): void => {
    setActiveLog(log);
    setDetailOpen(true);
  };

  const handleExport = (): void => {
    const params = new URLSearchParams();
    if (filters.status) {
      params.set('status', filters.status);
    }
    if (filters.search) {
      params.set('search', filters.search);
    }
    const url: string = `${
      window.location.origin
    }/wp-json/texty/v1/logs/export?${params.toString()}`;
    window.open(url, '_blank');
  };

  const fields: DataViewField<LogItem>[] = useMemo(
    () => [
      {
        id: 'created_at',
        label: __('Date & Time', 'texty'),
        render: ({ item }: { item: LogItem }) => (
          <span className="text-sm text-foreground">
            {item.created_at_formatted || item.created_at}
          </span>
        ),
        getValue: ({ item }: { item: LogItem }) => item.created_at,
      },
      {
        id: 'type_label',
        label: __('Type', 'texty'),
        render: ({ item }: { item: LogItem }) => (
          <span className="text-sm font-medium text-foreground">
            {item.type_label || '—'}
          </span>
        ),
        getValue: ({ item }: { item: LogItem }) =>
          item.type_label || item.notification_id,
      },
      {
        id: 'status',
        label: __('Status', 'texty'),
        render: ({ item }: { item: LogItem }) => (
          <StatusBadge status={item.status} />
        ),
        getValue: ({ item }: { item: LogItem }) => item.status,
      },
      {
        id: 'details',
        label: __('Details', 'texty'),
        render: ({ item }: { item: LogItem }) => (
          <button
            type="button"
            onClick={() => handleView(item)}
            className="cursor-pointer bg-transparent p-0 text-sm font-medium text-foreground underline underline-offset-4 hover:text-primary"
          >
            {__('View Log', 'texty')}
          </button>
        ),
        getValue: () => '',
      },
    ],
    []
  );

  const exportButton = (
    <Button
      variant="outline"
      size="sm"
      onClick={handleExport}
      className="gap-2"
    >
      <Download className="size-4" />
      {__('Export CSV', 'texty')}
    </Button>
  );

  return (
    <div className="flex flex-col gap-6">
      <DataViews<LogItem>
        namespace="texty_logs"
        view={view}
        onChangeView={(next: DataViewState) => setView(next as LogsView)}
        fields={fields}
        data={data.items}
        getItemId={(item: LogItem) => String(item.id)}
        isLoading={loading}
        paginationInfo={{
          totalItems: data.total,
          totalPages: data.total_pages,
        }}
        search
        searchPlaceholder={__('Search logs', 'texty')}
        emptyTitle={__('No logs yet', 'texty')}
        emptyDescription={__(
          'SMS events will appear here as your gateway sends messages.',
          'texty'
        )}
        tabs={{
          viewKey: 'status',
          defaultValue: '',
          items: [
            { value: '', label: __('All', 'texty') },
            { value: 'sent', label: __('Sent', 'texty') },
            { value: 'failed', label: __('Failed', 'texty') },
            { value: 'pending', label: __('Pending', 'texty') },
          ],
          headerContent: [exportButton],
        }}
      />

      <LogDetailDialog
        log={activeLog}
        open={detailOpen}
        onOpenChange={setDetailOpen}
      />
    </div>
  );
};

export default Logs;
