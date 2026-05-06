import { cn } from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';

import type { LogStatus } from '../types';

type Props = {
  status: LogStatus;
};

const TONE: Record<string, { wrapper: string; dot: string; label: string }> = {
  sent: {
    wrapper: 'bg-emerald-100 text-emerald-700',
    dot: 'bg-emerald-500',
    label: __('Sent', 'texty'),
  },
  failed: {
    wrapper: 'bg-rose-100 text-rose-700',
    dot: 'bg-rose-500',
    label: __('Failed', 'texty'),
  },
  pending: {
    wrapper: 'bg-amber-100 text-amber-700',
    dot: 'bg-amber-500',
    label: __('Pending', 'texty'),
  },
};

const StatusBadge = ({ status }: Props) => {
  const tone = TONE[status] ?? {
    wrapper: 'bg-gray-100 text-gray-700',
    dot: 'bg-gray-400',
    label: status || __('Unknown', 'texty'),
  };

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
        tone.wrapper
      )}
    >
      <span className={cn('size-1.5 rounded-full', tone.dot)} />
      {tone.label}
    </span>
  );
};

export default StatusBadge;
