import { __ } from '@wordpress/i18n';
import classNames from 'classnames';
import {
  ToggleControl,
  TextareaControl,
  BaseControl,
  PanelBody,
} from '@wordpress/components';
import Select from 'react-select';

function NotificationItem({
  title,
  description,
  keyName,
  roles,
  settings,
  setOption,
}) {
  const replacements = settings.replacements
    .map((item) => `<code>{${item}}</code>`)
    .join(', ');

  return (
    <PanelBody
      initialOpen={false}
      title={
        <>
          <span>{title}</span>
          <span
            className={classNames('label', {
              active: settings.enabled === true,
            })}
          >
            {settings.enabled ? __('active', 'texty') : __('inactive', 'texty')}
          </span>
        </>
      }
    >
      <ToggleControl
        label={__('Enable', 'texty')}
        help={description}
        checked={settings.enabled}
        onChange={(isChecked) => setOption(keyName, 'enabled', isChecked)}
      />

      {settings.type === 'role' && (
        <BaseControl
          label={__('Recipients')}
          help={
            settings.type === 'role'
              ? __(
                  'Select one or multiple user roles. Users with phone number in their profile will receive the text',
                  'texty'
                )
              : ''
          }
        >
          <Select
            required={true}
            isMulti={settings.type === 'role'}
            value={roles.filter((item) =>
              settings.recipients.includes(item.value)
            )}
            options={roles}
            onChange={(options) => {
              setOption(
                keyName,
                'recipients',
                options !== null ? options.map((item) => item.value) : []
              );
            }}
          />
        </BaseControl>
      )}

      <TextareaControl
        label={__('Message', 'texty')}
        className="monospace"
        required={true}
        help={
          <span
            className="help"
            dangerouslySetInnerHTML={{
              __html:
                __('You may use these variables: ', 'texty') + replacements,
            }}
          ></span>
        }
        value={settings.message}
        onChange={(text) => setOption(keyName, 'message', text)}
      />
    </PanelBody>
  );
}

export default NotificationItem;
