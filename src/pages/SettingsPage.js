/**
 * Settings page using @wedevs/plugin-ui Settings component.
 *
 * Fetches schema from the BaseSettingsRESTController endpoint
 * (GET texty/v1/settings/schema). Field values are embedded in the schema
 * as `default` properties — no separate values prop is needed.
 * Saves via POST to the same endpoint.
 */
import { useState, useEffect, useCallback } from 'react';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { toast } from 'react-toastify';
import { Settings, Button } from '@wedevs/plugin-ui';
import { Save } from 'lucide-react';

function SettingsPage() {
  const [schema, setSchema] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setLoading(true);

    apiFetch({
      path: '/texty/v1/settings/schema',
    })
      .then((resp) => {
        setSchema(resp.schema || []);
        setLoading(false);
      })
      .catch((err) => {
        console.error('Failed to load settings schema:', err);
        toast.error(
          __('Failed to load settings. Please refresh the page.', 'texty')
        );
        setLoading(false);
      });
  }, []);

  const handleSave = useCallback((scopeId, scopeValues) => {
    return apiFetch({
      path: '/texty/v1/settings/schema',
      method: 'POST',
      data: {
        scopeId,
        values: scopeValues,
      },
    })
      .then((resp) => {
        if (resp.success) {
          toast.success(__('Changes have been saved', 'texty'));
        } else if (resp.errors) {
          const errorMessages = Object.values(resp.errors).join(', ');
          toast.error(errorMessages);
        }
      })
      .catch((err) => {
        console.error('Failed to save settings:', err);

        if (err.errors) {
          const errorMessages = Object.values(err.errors).join(', ');
          toast.error(errorMessages);
        } else {
          toast.error(err.message || __('Failed to save settings.', 'texty'));
        }
      });
  }, []);

  return (
    <div className="texty-settings-page">
      <Settings
        schema={schema}
        onSave={handleSave}
        loading={loading}
        title={__('Settings', 'texty')}
        renderSaveButton={({ dirty, onSave: save }) => (
          <Button onClick={save} disabled={!dirty}>
            <Save className="size-4 mr-2" />
            {__('Save Changes', 'texty')}
          </Button>
        )}
      />
    </div>
  );
}

export default SettingsPage;
