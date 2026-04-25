import {
  Button,
  Field,
  FieldContent,
  FieldLabel,
  Textarea,
  toast,
} from '@wedevs/plugin-ui';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useState, type ChangeEvent, type FormEvent } from 'react';
import PhoneInput from 'react-phone-input-2';

const MAX_MESSAGE_LENGTH = 120;

type SendResponse = {
  success: boolean;
  message?: string;
};

type Props = {
  onSent?: () => void;
};

const QuickSendCard = ({ onSent }: Props) => {
  const [phone, setPhone] = useState<string>('');
  const [message, setMessage] = useState<string>('');
  const [sending, setSending] = useState<boolean>(false);

  const handleSubmit = async (e: FormEvent<HTMLFormElement>): Promise<void> => {
    e.preventDefault();

    if (!phone || !message) {
      return;
    }

    setSending(true);
    try {
      const resp = await apiFetch<SendResponse>({
        path: '/texty/v1/send',
        method: 'POST',
        data: {
          to: phone.startsWith('+') ? phone : `+${phone}`,
          message,
        },
      });

      if (resp.success) {
        toast.success(__('Message has been sent.', 'texty'));
        setMessage('');
        onSent?.();
      } else {
        toast.error(
          [__('Error, message could not be sent.', 'texty'), resp.message]
            .filter(Boolean)
            .join(' ')
        );
      }
    } catch (err) {
      console.error(err);
      toast.error(__('Failed to send message.', 'texty'));
    } finally {
      setSending(false);
    }
  };

  return (
    <div className="rounded-xl border border-gray-200 bg-white shadow-xs">
      <div className="border-b border-gray-100 px-6 py-4">
        <h3 className="m-0 text-base font-semibold text-gray-900">
          {__('Quick Send SMS', 'texty')}
        </h3>
        <p className="mt-1 text-sm text-gray-500">
          {__(
            'Send a custom message instantly to desired Mobile number',
            'texty'
          )}
        </p>
      </div>

      <form onSubmit={handleSubmit} className="space-y-5 px-6 py-5">
        <Field>
          <FieldLabel className="text-sm font-medium text-gray-900">
            {__('Phone Number', 'texty')}
          </FieldLabel>
          <FieldContent>
            <PhoneInput
              country="bd"
              value={phone}
              onChange={(val: string) => setPhone(val)}
              disabled={sending}
              enableSearch
              inputProps={{ name: 'phone', required: true }}
              containerClass="texty-phone-input"
              inputClass="!h-10 !w-full !rounded-md !border !border-gray-200 !bg-white !pl-14 !text-sm !text-gray-900"
              buttonClass="!rounded-l-md !border-r !border-gray-200 !bg-white"
              dropdownClass="!text-sm"
            />
          </FieldContent>
        </Field>

        <Field>
          <FieldLabel className="text-sm font-medium text-gray-900">
            {__('Message', 'texty')}
          </FieldLabel>
          <FieldContent>
            <Textarea
              value={message}
              onChange={(e: ChangeEvent<HTMLTextAreaElement>) =>
                setMessage(e.target.value.slice(0, MAX_MESSAGE_LENGTH))
              }
              placeholder={__('Write here', 'texty')}
              maxLength={MAX_MESSAGE_LENGTH}
              disabled={sending}
              rows={4}
              required
            />
            <div className="mt-1.5 text-xs text-gray-500">
              {message.length}/{MAX_MESSAGE_LENGTH} {__('characters', 'texty')}
            </div>
          </FieldContent>
        </Field>

        <Button
          type="submit"
          disabled={sending || !phone || !message}
          className="bg-gray-900 text-white hover:bg-gray-800"
        >
          {sending ? __('Sending…', 'texty') : __('Send Message', 'texty')}
        </Button>
      </form>
    </div>
  );
};

export default QuickSendCard;
