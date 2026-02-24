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
    <div className="texty-tools">
      <h1>{__('Tools', 'texty')}</h1>

      <Status />

      <div className="texty-two-col">
        <div className="texty-col">
          <Card className="mt-4">
            <CardHeader>{__('Test Message', 'texty')}</CardHeader>
            <CardBody>
              <TestMessage />
            </CardBody>
          </Card>
        </div>
        <div className="texty-col">
          <Card className="mt-4">
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
