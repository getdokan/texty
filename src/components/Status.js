import { useState, useEffect } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Notice, Icon, Spinner } from '@wordpress/components';
import domReady from '@wordpress/dom-ready';

function Status() {
  const [isConnected, setIsConnected] = useState(false);
  const [isFetching, setIsFetching] = useState(true);

  useEffect(() => {
      setIsFetching(true);
      try {
        apiFetch({
          path: '/texty/v1/status',
        }).then((resp) => {
          console.log('Fetching status.. complete', resp);
          setIsConnected(resp.success);
          setIsFetching(false);


        });
      } catch(error) {
        console.error('Error fetching status:', error);
      } finally {
      }
  }, [isConnected]);

  const FetchStatus = () =>  (
      <Notice>
        <Spinner />
      </Notice>
    );
  

  const StatusNotice = () => (
    <Notice status={isConnected ? 'success' : 'error'} isDismissible={false}>
      <Icon icon={isConnected ? 'yes-alt' : 'dismiss'} />
      <span>
        {isConnected ? __('Connected', 'texty') : __('Not connected', 'texty')}
      </span>
    </Notice>
  );

  return (
    <div className="texty-status">
      {isFetching ? <FetchStatus /> : <StatusNotice />}
    </div>
  );
}

export default Status;
