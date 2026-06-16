import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';

import type { LogItem } from '../types';
import StatusBadge from './StatusBadge';

type Props = {
  log: LogItem | null;
  open: boolean;
  onOpenChange: (next: boolean) => void;
};

const Field = ({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) => (
  <div>
    <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
      {label}
    </p>
    <div className="mt-1 text-sm wrap-break-word text-foreground">
      {children}
    </div>
  </div>
);

const LogDetailDialog = ({ log, open, onOpenChange }: Props) => {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-xl">
        <DialogHeader>
          <DialogTitle>{__('Log Details', 'texty')}</DialogTitle>
          <DialogDescription>
            {log?.type_label || __('SMS event', 'texty')}
          </DialogDescription>
        </DialogHeader>

        {log && (
          <div className="grid grid-cols-2 gap-4">
            <Field label={__('Status', 'texty')}>
              <StatusBadge status={log.status} />
            </Field>
            <Field label={__('Date & Time', 'texty')}>
              {log.created_at_formatted || log.created_at}
            </Field>
            <Field label={__('Receiver', 'texty')}>{log.receiver || '—'}</Field>
            <Field label={__('Gateway', 'texty')}>{log.gateway || '—'}</Field>
            <Field label={__('Reference ID', 'texty')}>
              {log.reference_id || '—'}
            </Field>
            <Field label={__('Notification', 'texty')}>
              {log.notification_id || '—'}
            </Field>
            <div className="col-span-2">
              <Field label={__('Message', 'texty')}>
                {log.message ? (
                  <pre className="m-0 mt-1 max-h-40 overflow-auto rounded-md border border-border bg-muted/40 p-3 text-xs whitespace-pre-wrap">
                    {log.message}
                  </pre>
                ) : (
                  '—'
                )}
              </Field>
            </div>
            {log.response && (
              <div className="col-span-2">
                <Field label={__('Gateway Response', 'texty')}>
                  <pre className="m-0 mt-1 max-h-40 overflow-auto rounded-md border border-border bg-muted/40 p-3 text-xs whitespace-pre-wrap">
                    {log.response}
                  </pre>
                </Field>
              </div>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
};

export default LogDetailDialog;
