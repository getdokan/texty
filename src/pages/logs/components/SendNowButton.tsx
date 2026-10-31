import { Button, toast } from '@wedevs/plugin-ui';
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Send } from 'lucide-react';

type Props = {
  logId: number;
  onSent: () => void;
};

type SendNowResponse = {
  success: boolean;
};

/**
 * Sends a queued (pending) notification immediately instead of waiting for
 * the background worker — POST /texty/v1/logs/{id}/send.
 */
const SendNowButton = ({ logId, onSent }: Props) => {
  const [sending, setSending] = useState<boolean>(false);

  const handleClick = async (): Promise<void> => {
    setSending(true);
    try {
      await apiFetch<SendNowResponse>({
        path: `/texty/v1/logs/${logId}/send`,
        method: 'POST',
      });
      toast.success(__('Message sent. Check the log for the result.', 'texty'));
    } catch (err) {
      console.error(err);
      const message: string =
        (err as { message?: string }).message ??
        __('Failed to send message.', 'texty');
      toast.error(message);
    } finally {
      setSending(false);
      // Refresh either way: on success the pending row is replaced by its
      // sent/failed rows; on "no longer pending" the worker already ran.
      onSent();
    }
  };

  return (
    <Button
      size="sm"
      variant="outline"
      onClick={handleClick}
      disabled={sending}
      className="h-7 gap-1.5 px-2.5 text-xs"
    >
      <Send className="size-3.5" />
      {sending ? __('Sending…', 'texty') : __('Send now', 'texty')}
    </Button>
  );
};

export default SendNowButton;
