import { useState, useEffect } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Notice, Icon, Spinner } from '@wordpress/components';

function Status() {
  const [isConnected, setIsConnected] = useState(false);
  const [isFetching, setIsFetching] = useState(true);

  useEffect(() => {
    setIsFetching(true);

    apiFetch({
      path: '/texty/v1/status',
    }).then((resp) => {
      setIsFetching(false);
      setIsConnected(resp.success);
    });
  }, []);

  if (isFetching) {
    return (
      <Notice>
        <Spinner />
      </Notice>
    );
  }

  return (
    <Notice status={isConnected ? 'success' : 'error'} isDismissible={false}>
      <Icon icon={isConnected ? 'yes-alt' : 'dismiss'} />
      <span>
        {isConnected ? __('Connected', 'texty') : __('Not connected', 'texty')}
      </span>
    </Notice>
  );
}

export default Status;
