import { Button } from '@wedevs/plugin-ui';
import { __ } from '@wordpress/i18n';
import { MessageSquareText, Send } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

const QuickSendCta = () => {
  const navigate = useNavigate();

  return (
    <div className="flex flex-col gap-4 rounded-xl border border-gray-200 bg-white px-6 py-5 shadow-xs sm:flex-row sm:items-center sm:justify-between">
      <div className="flex items-start gap-3">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-700">
          <MessageSquareText className="size-5" />
        </span>
        <div className="min-w-0">
          <h3 className="m-0 text-base font-semibold text-gray-900">
            {__('Quick Send SMS', 'texty')}
          </h3>
          <p className="m-0 mt-1 text-sm text-gray-500">
            {__(
              'Send a custom message instantly, or check that your gateway works.',
              'texty'
            )}
          </p>
        </div>
      </div>

      <Button
        onClick={() => navigate('/quick-send')}
        className="gap-2 self-start bg-gray-900 text-white hover:bg-gray-800 sm:self-center"
      >
        <Send className="size-4" />
        {__('Send a Message', 'texty')}
      </Button>
    </div>
  );
};

export default QuickSendCta;
