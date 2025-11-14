import React, { useState } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, TextControl, TextareaControl } from '@wordpress/components';
import { toast } from 'react-toastify';

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
        // toast.error(err.message);
      });
  };

  return (
    <form onSubmit={handleSubmit}>
      <fieldset disabled={isSending}>
        <TextControl
          label={__('Phone Number', 'texty')}
          placeholder="+123456789"
          type="tel"
          value={phoneNumber}
          onChange={(value) => setPhoneNumber(value)}
          required
        />

        <TextareaControl
          label={__('Message', 'texty')}
          value={message}
          placeholder={__('Write your message...', 'texty')}
          onChange={(value) => setMessage(value)}
          required
        />
      </fieldset>

      <div className="flex justify-end">
        <Button type="submit" isPrimary={true} isBusy={isSending}>
          {isSending ? __('Sending...', 'texty') : __('Send Message', 'texty')}
        </Button>
      </div>
    </form>
  );
}

export default QuickSend;
