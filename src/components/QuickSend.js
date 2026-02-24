import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
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
      <div className="form-group">
        <label htmlFor="phone-number">{__('Phone Number', 'texty')}</label>
        <input
          id="phone-number"
          type="tel"
          placeholder="+123456789"
          value={phoneNumber}
          onChange={(e) => setPhoneNumber(e.target.value)}
          required
          disabled={isSending}
        />
      </div>

      <div className="form-group">
        <label htmlFor="message">{__('Message', 'texty')}</label>
        <textarea
          id="message"
          value={message}
          placeholder={__('Write your message...', 'texty')}
          onChange={(e) => setMessage(e.target.value)}
          required
          disabled={isSending}
          rows="4"
        ></textarea>
      </div>

      <div className="submit-area">
        <button
          type="submit"
          className="button button-primary"
          disabled={isSending}
        >
          {isSending ? __('Sending...', 'texty') : __('Send Message', 'texty')}
        </button>
      </div>
    </form>
  );
}

export default QuickSend;
