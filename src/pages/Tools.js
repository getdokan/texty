import React from 'react';
import { __ } from '@wordpress/i18n';
import { Card, CardBody, CardHeader } from '@wordpress/components';

/**
 * Internal dependencies
 */
import Status from '../components/Status';
import QuickSend from '../components/QuickSend';
import TestMessage from '../components/TestMessage';

function Tools() {
  return (
    <div>
      <h1>{__('Tools', 'texty')}</h1>

      <Status />

      <div className="flex flex-wrap justify-between">
        <div className="flex-[0_48%] max-sm:flex-[0_100%]">
          <Card className="mt-5">
            <CardHeader>{__('Test Message', 'texty')}</CardHeader>
            <CardBody>
              <TestMessage />
            </CardBody>
          </Card>
        </div>
        <div className="flex-[0_48%] max-sm:flex-[0_100%]">
          <Card className="mt-5">
            <CardHeader>{__('Quick Send', 'texty')}</CardHeader>
            <CardBody>
              <QuickSend />
            </CardBody>
          </Card>
        </div>
      </div>
    </div>
  );
}

export default Tools;
