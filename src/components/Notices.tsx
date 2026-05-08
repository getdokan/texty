import { AdminNotice } from '@wedevs/plugin-ui';

const Notices = () => {
  const texty = window.texty || {};
  const noticesUrlArgs: Record<string, string> = { scope: 'local' };

  return (
    <div className="mb-4 empty:hidden">
      <AdminNotice
        noticesUrl={`${texty.rest_url}texty/v1/notices/admin`}
        noticesUrlArgs={noticesUrlArgs}
        actionUrl={texty.ajax_url}
      />
    </div>
  );
};

export default Notices;
