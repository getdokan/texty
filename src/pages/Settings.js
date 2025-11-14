/**
 * External dependencies
 */
import React, { Fragment, useState, useEffect } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
  Button,
  Spinner,
  BaseControl,
  TextControl,
  Card,
  CardBody,
  CardHeader,
} from '@wordpress/components';
import classNames from 'classnames';
import { toast } from 'react-toastify';
import PhoneInput from 'react-phone-input-2';

/**
 * Internal dependencies
 */
import ActiveIcon from '../components/ActiveIcon';

function Settings() {
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [settings, setSettings] = useState({});

  useEffect(() => {
    setIsLoading(true);

    apiFetch({
      path: '/texty/v1/settings?context=edit',
    }).then((resp) => {
      setSettings(resp);
      setIsLoading(false);
    });
  }, []);

  const setOption = (option, value) => {
    setSettings({
      ...settings,
      [option]: value,
    });
  };

  const setCredential = (provider, name, value) => {
    setSettings({
      ...settings,
      [provider]: {
        ...settings[provider],
        [name]: {
          ...settings[provider][name],
          ['value']: value,
        },
      },
    });
  };

  const handleSubmit = (e) => {
    e.preventDefault();

    let data = {
      gateway: settings.gateway,
    };

    Object.keys(settings.gateways).forEach((gateway) => {
      Object.keys(settings[gateway]).forEach((field) => {
        if (!data.hasOwnProperty(gateway)) {
          data[gateway] = {};
        }

        data[gateway][field] = settings[gateway][field]['value'];
      });
    });

    setIsSaving(true);

    apiFetch({
      path: '/texty/v1/settings',
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

  const gateways = Object.keys(settings.gateways);

  return (
    <div>
      <h1>{__('Settings', 'texty')}</h1>

      <form onSubmit={handleSubmit}>
        <Card>
          <CardHeader>{__('SMS Gateway', 'texty')}</CardHeader>
          <CardBody>
            <fieldset disabled={isSaving}>
              <div className="pb-5">
                <div>
                  <label className="text-base font-medium">{__('Gateways', 'texty')}</label>
                </div>
                <div className="mt-4">
                  <div className="flex flex-wrap max-sm:block">
                    {gateways.map((key) => {
                      const { name, logo } = settings.gateways[key];

                      return (
                        <div
                          className={classNames(
                            'flex flex-col w-40 bg-white mr-4 mb-4 p-5 px-4 rounded-md text-center cursor-pointer opacity-40 relative border border-gray-200',
                            'max-sm:w-auto max-sm:text-left max-sm:flex-row max-sm:mb-2.5 max-sm:mr-0 max-sm:items-center',
                            {
                              'opacity-100 border-2 border-primary-dark': key === settings.gateway,
                            }
                          )}
                          key={'gateway-' + key}
                          onClick={() => setOption('gateway', key)}
                        >
                          <ActiveIcon isActive={key === settings.gateway} />
                          <div className="h-8 max-sm:h-auto max-sm:flex-[0_40%]">
                            <img src={logo} alt={name} className="max-w-[90px] max-h-6 max-sm:max-w-[90%]" />
                          </div>

                          <div className="text-lg mt-2 font-medium max-sm:mt-0">{name}</div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              </div>

              {gateways.map((key) => {
                const { name, description } = settings.gateways[key];

                return (
                  settings.gateway === key && (
                    <div
                      className={'pb-5 settings-' + key}
                      key={'settings-' + key}
                    >
                      <h3>{name}</h3>

                      <p>
                        {
                          <span
                            className="help"
                            dangerouslySetInnerHTML={{
                              __html: description,
                            }}
                          ></span>
                        }
                      </p>

                      {Object.keys(settings[key]).map((item) => {
                        const { name, type, value, help } = settings[key][item];

                        return (
                          <Fragment key={'field' + item}>
                            {
                              // for "from" fields, use phone input field
                            }
                            {item === 'from' && (
                              <BaseControl label={name} help={help}>
                                <PhoneInput
                                  country="us"
                                  value={value}
                                  onChange={(value) =>
                                    setCredential(key, item, value)
                                  }
                                />
                              </BaseControl>
                            )}

                            {item !== 'from' && (
                              <TextControl
                                label={name}
                                value={value}
                                type={type}
                                help={help}
                                onChange={(value) =>
                                  setCredential(key, item, value)
                                }
                              />
                            )}
                          </Fragment>
                        );
                      })}
                    </div>
                  )
                );
              })}

              {/* {settings.gateway === 'twilio' && (
                <Twilio settings={settings.twilio} setOption={setCredential} />
              )}

              {settings.gateway === 'vonage' && (
                <Vonage settings={settings.vonage} setOption={setCredential} />
              )} */}
            </fieldset>
          </CardBody>
        </Card>

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

export default Settings;
