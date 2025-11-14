import React, { useState, useEffect } from 'react';
import { __ } from '@wordpress/i18n';
import { toast } from 'react-toastify';
import apiFetch from '@wordpress/api-fetch';
import classNames from 'classnames';
import {
  Button,
  Card,
  CardBody,
  CardHeader,
  Panel,
  Spinner,
} from '@wordpress/components';
import NotificationItem from '../components/NotificationItem';

function Notifications() {
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [settings, setSettings] = useState({});

  useEffect(() => {
    setIsLoading(true);

    apiFetch({
      path: '/texty/v1/notifications?context=edit',
    }).then((resp) => {
      setSettings(resp);
      setIsLoading(false);
    });
  }, []);

  const setOption = (name, option, value) => {
    setSettings({
      ...settings,
      ['notifications']: {
        ...settings['notifications'],
        [name]: {
          ...settings['notifications'][name],
          [option]: value,
        },
      },
    });
  };

  const handleSubmit = (e) => {
    e.preventDefault();

    let data = {};

    Object.keys(settings.notifications).forEach((item) => {
      if (!data.hasOwnProperty(item)) {
        data[item] = {};
      }

      let notif = settings.notifications[item];

      data[item] = {
        enabled: notif['enabled'],
        message: notif['message'],
        recipients: notif['recipients'],
        route: notif['route'],
      };
    });

    setIsSaving(true);

    apiFetch({
      path: '/texty/v1/notifications',
      method: 'POST',
      data: data,
    })
      .then((resp) => {
        setIsSaving(false);

        toast.success(__('Changes have been saved', 'texty'));
      })
      .catch((err) => {
        setIsSaving(false);
        console.log(err);
        toast.error(err.message);
      });
  };

  if (isLoading) {
    return <Spinner />;
  }

  return (
    <div>
      <h1>{__('Notifications', 'texty')}</h1>
      <p>
        {__(
          'Enable or disable notification based on different events.',
          'texty'
        )}
      </p>

      <form onSubmit={handleSubmit}>
        {Object.keys(settings.groups).map((group) => {
          const { title, available } = settings.groups[group];

          return (
            <Card key={group}>
              <CardHeader
                className={classNames({
                  inactive: !available,
                })}
              >
                {title}

                {!available && (
                  <span className="sub-heading">
                    {__('Plugin not installed', 'texty')}
                  </span>
                )}
              </CardHeader>

              {available && (
                <CardBody className="has-panel">
                  <Panel>
                    {Object.keys(settings.notifications).map((notify) => {
                      const notification = settings.notifications[notify];

                      return (
                        group === notification.group && (
                          <NotificationItem
                            key={notify}
                            title={notification.title}
                            roles={settings.roles}
                            keyName={notification.id}
                            settings={notification}
                            setOption={setOption}
                          />
                        )
                      );
                    })}
                  </Panel>
                </CardBody>
              )}
            </Card>
          );
        })}

        <div className="flex justify-end">
          <Button
            type="submit"
            isPrimary={true}
            isBusy={isSaving}
            className="large"
          >
            {isSaving ? __('Saving...', 'texty') : __('Save Changes', 'texty')}
          </Button>
        </div>
      </form>
    </div>
  );
}

export default Notifications;
