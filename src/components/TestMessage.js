import React, { useState } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, TextControl } from '@wordpress/components';
import { toast } from 'react-toastify';

function TestMessage() {
  const [isSending, setIsSending] = useState(false);
  const [phoneNumber, setPhoneNumber] = useState('');

  const handleSubmit = (e) => {
    e.preventDefault();

    setIsSending(true);

    apiFetch({
      path: '/texty/v1/tools/test',
      method: 'POST',
      data: {
        to: phoneNumber,
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
          label={__('Test Number', 'texty')}
          placeholder="+123456789"
          type="tel"
          value={phoneNumber}
          onChange={(value) => setPhoneNumber(value)}
          help={__('Enter a phone number to test the SMS sending.', 'texty')}
          required
        />
      </fieldset>

      <div className="flex justify-end">
        <Button type="submit" isPrimary={true} isBusy={isSending}>
          {isSending ? __('Sending...', 'texty') : __('Send Test', 'texty')}
        </Button>
      </div>
    </form>
  );
}

export default TestMessage;
