import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Input, Textarea, Button, Field, FieldLabel, FieldContent, toast } from '@wedevs/plugin-ui';

function QuickSend() {
  const [isSending, setIsSending] = useState(false);
  const [phoneNumber, setPhoneNumber] = useState('');
  const [message, setMessage] = useState('');

  const handleSubmit = (e) => {
    e.preventDefault();

    setIsSending(true);

    apiFetch({
      path: '/texty/v1/send',
      method: 'POST',
      data: {
        to: phoneNumber,
        message: message,
      },
    })
      .then((resp) => {
        setIsSending(false);

        if (resp.success) {
          toast.success(__('Message has been sent.', 'texty'));
          setPhoneNumber('');
          setMessage('');
        } else {
          toast.error(
            __('Error, message could not be sent.', 'texty') +
            ' ' +
            resp.message
          );
        }
      })
      .catch((err) => {
        setIsSending(false);
        console.log(err);
      });
  };

  return (
    <form onSubmit={handleSubmit} className="texty-quick-send-form">
      <Field>
        <FieldLabel>{__('Phone Number', 'texty')}</FieldLabel>
        <FieldContent>
          <Input
            value={phoneNumber}
            onChange={(e) => setPhoneNumber(e.target.value)}
            placeholder="+123456789"
            type="tel"
            disabled={isSending}
            required
          />
        </FieldContent>
      </Field>

      <Field>
        <FieldLabel>{__('Message', 'texty')}</FieldLabel>
        <FieldContent>
          <Textarea
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            placeholder={__('Write your message...', 'texty')}
            maxLength={160}
            disabled={isSending}
            required
          />
        </FieldContent>
      </Field>

      <Button
        variant="default"
        type="submit"
        disabled={isSending}
      >
        {isSending ? __('Sending...', 'texty') : __('Send Message', 'texty')}
      </Button>
    </form>
  );
}

export default QuickSend;
