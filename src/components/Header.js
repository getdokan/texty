import { __ } from '@wordpress/i18n';
import { NavLink } from 'react-router-dom';

function Header() {
  return (
    <div className="texty-header-wrap">
      <div className="texty-header">
        <a href="#/dashboard" className="texty-admin-header__logo">
          <div className="texty-logo-icon">
            <svg viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg">
              <path d="M13 2L4.5 13.5H11L10 22L20.5 9.5H14L13 2Z" />
            </svg>
          </div>
          <span className="texty-logo-text">{__('Texty', 'texty')}</span>
        </a>

        <div className="texty-admin-header__menu">
          <NavLink
            to="/dashboard"
            exact
            className="header-link"
            activeClassName="active"
            title={__('Dashboard', 'texty')}
          >
            <span className="dashicons dashicons-menu-alt"></span>
            <span className="title">{__('Dashboard', 'texty')}</span>
          </NavLink>

          <NavLink
            to="/settings"
            className="header-link"
            activeClassName="active"
            title={__('Settings', 'texty')}
          >
            <span className="dashicons dashicons-admin-settings"></span>
            <span className="title">{__('Settings', 'texty')}</span>
          </NavLink>

          <NavLink
            to="/notifications"
            className="header-link"
            activeClassName="active"
            title={__('Notifications', 'texty')}
          >
            <span className="dashicons dashicons-bell"></span>
            <span className="title">{__('Notifications', 'texty')}</span>
          </NavLink>

          <NavLink
            to="/tools"
            className="header-link"
            activeClassName="active"
            title={__('Tools', 'texty')}
          >
            <span className="dashicons dashicons-admin-tools"></span>
            <span className="title">{__('Tools', 'texty')}</span>
          </NavLink>
        </div>
      </div>
    </div>
  );
}

export default Header;
