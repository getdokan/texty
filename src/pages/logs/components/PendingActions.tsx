import { Button, toast } from '@wedevs/plugin-ui';
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Send, X } from 'lucide-react';

type Props = {
  logId: number;
  onDone: () => void;
};

type PendingResponse = {
  success: boolean;
};

type Busy = 'send' | 'cancel' | null;

/**
 * Actions for a queued (pending) notification:
 * - Send now — POST /texty/v1/logs/{id}/send, sends immediately instead of
 *   waiting for the background worker.
 * - Cancel  — DELETE /texty/v1/logs/{id}, drops it without sending.
 */
const PendingActions = ({ logId, onDone }: Props) => {
  const [busy, setBusy] = useState<Busy>(null);

  const request = async (
    action: Exclude<Busy, null>,
    method: 'POST' | 'DELETE',
    path: string,
    successMessage: string
  ): Promise<void> => {
    setBusy(action);
    try {
      await apiFetch<PendingResponse>({ path, method });
      toast.success(successMessage);
    } catch (err) {
      console.error(err);
      const message: string =
        (err as { message?: string }).message ??
        __('The action could not be completed.', 'texty');
      toast.error(message);
    } finally {
      setBusy(null);
      // Refresh either way: the pending row is gone after success, and a
      // "no longer pending" error means the worker already handled it.
      onDone();
    }
  };

  const handleSend = (): Promise<void> =>
    request(
      'send',
      'POST',
      `/texty/v1/logs/${logId}/send`,
      __('Message sent. Check the log for the result.', 'texty')
    );

  const handleCancel = (): Promise<void> =>
    request(
      'cancel',
      'DELETE',
      `/texty/v1/logs/${logId}`,
      __('Queued message cancelled.', 'texty')
    );

  return (
    <div className="flex items-center gap-2">
      <Button
        size="sm"
        variant="outline"
        onClick={handleSend}
        disabled={null !== busy}
        className="h-7 gap-1.5 px-2.5 text-xs"
      >
        <Send className="size-3.5" />
        {'send' === busy ? __('Sending…', 'texty') : __('Send now', 'texty')}
      </Button>
      <Button
        size="sm"
        variant="ghost"
        onClick={handleCancel}
        disabled={null !== busy}
        className="h-7 gap-1.5 px-2.5 text-xs text-muted-foreground"
      >
        <X className="size-3.5" />
        {'cancel' === busy ? __('Cancelling…', 'texty') : __('Cancel', 'texty')}
      </Button>
    </div>
  );
};

export default PendingActions;
