import React from 'react';
import { __ } from '@wordpress/i18n';
import { NavLink } from 'react-router-dom';

function Header() {
  return (
    <div className="bg-white ml-[-20px] shadow-sm">
      <div className="mx-auto w-full max-w-container">
        <div className="flex items-center py-4 lg:py-4 lg:px-0 px-5 max-sm:block max-sm:text-center">
          <div className="flex items-end max-sm:block max-sm:mb-2.5">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 97 28"
              width="97"
              height="28"
            >
              <g fill="none" fillRule="nonzero">
                <path
                  fill="#FF0F45"
                  d="M15.617 0c.893 0 1.766.084 2.613.244a7.954 7.954 0 00-1.633 4.087 9.766 9.766 0 00-.98-.05c-5.36 0-9.706 4.346-9.706 9.706 0 2.262.774 4.4 2.17 6.117l.933 1.148-1.433 2.44h8.036c5.36 0 9.705-4.345 9.705-9.705 0-.3-.013-.597-.04-.89a7.948 7.948 0 004.102-1.594c.145.806.22 1.636.22 2.484 0 7.725-6.262 13.988-13.987 13.988H0l3.862-6.405-.018-.028a13.934 13.934 0 01-2.214-7.555C1.63 6.262 7.892 0 15.617 0zm8.932 0a5.138 5.138 0 110 10.276A5.138 5.138 0 0124.55 0z"
                />
                <path
                  fill="#000"
                  d="M87.482 7.8l2.65 8.9 2.636-8.9h4.087l-5.734 16.53-.317.755-.039.084c-.856 1.813-2.25 2.72-4.18 2.72a5.977 5.977 0 01-1.687-.252v-2.896l.58.013h.09c.676-.01 1.184-.12 1.525-.33.356-.221.635-.587.837-1.098l.449-1.177-4.997-14.35h4.1zm-43.91-3.518V7.8h2.44v2.803h-2.44v7.142l.001.071c.009.492.11.847.303 1.066.202.23.589.344 1.16.344.422 0 .795-.03 1.12-.093v2.897l-.14.041a7.825 7.825 0 01-2.167.302c-2.671 0-4.033-1.353-4.086-4.06v-7.71H37.68V7.8h2.083V4.282h3.81zm9.894 3.253c1.95 0 3.486.617 4.607 1.852 1.1 1.211 1.66 2.92 1.68 5.127v1.683h-9.082l.013.093c.135.892.502 1.61 1.1 2.156.62.564 1.405.846 2.354.846 1.437 0 2.567-.511 3.389-1.534l.052-.066 1.871 2.103-.061.086c-.565.77-1.318 1.374-2.259 1.811-.975.454-2.056.681-3.243.681-2.091 0-3.794-.643-5.108-1.93-1.29-1.265-1.947-2.941-1.97-5.03v-.628c.018-1.38.295-2.617.83-3.712.554-1.133 1.338-2.006 2.353-2.619 1.015-.612 2.173-.919 3.474-.919zm26.533-3.253V7.8h2.44v2.803h-2.44v7.142l.001.071c.009.492.11.847.303 1.066.202.23.589.344 1.16.344.422 0 .795-.03 1.12-.093v2.897l-.14.041a7.825 7.825 0 01-2.167.302c-2.671 0-4.034-1.353-4.086-4.06v-7.71h-2.083V7.8h2.083V4.282H80zM64.541 7.8l2.347 4.271 2.4-4.271h4.072l-4.06 7.009 4.232 7.3h-4.087l-2.544-4.497-2.531 4.497h-4.1l4.232-7.3-4.047-7.01h4.086zm-11.089 2.83c-.755 0-1.368.255-1.839.767-.454.494-.75 1.193-.888 2.097l-.015.098h5.3v-.304l-.003-.087c-.03-.804-.258-1.43-.683-1.877-.44-.463-1.063-.694-1.872-.694z"
                />
              </g>
            </svg>
          </div>

          <div className="flex-1 text-right pr-1.5 max-sm:text-left">
            <NavLink
              to="/"
              exact
              className="no-underline ml-1 text-gray-700 py-2 px-5 pl-4 hover:bg-gray-100 hover:rounded rounded max-sm:text-left max-sm:block"
              activeClassName="bg-primary text-gray-100"
              title={__('Gateway Settings', 'texty')}
            >
              <span className="dashicons dashicons-admin-settings"></span>
              <span className="ml-1">{__('Gateway Settings', 'texty')}</span>
            </NavLink>

            <NavLink
              to="/notifications"
              className="no-underline ml-1 text-gray-700 py-2 px-5 pl-4 hover:bg-gray-100 hover:rounded rounded max-sm:text-left max-sm:block"
              activeClassName="bg-primary text-gray-100"
              title={__('Notifications', 'texty')}
            >
              <span className="dashicons dashicons-admin-plugins"></span>
              <span className="ml-1">{__('Notifications', 'texty')}</span>
            </NavLink>

            <NavLink
              to="/tools"
              className="no-underline ml-1 text-gray-700 py-2 px-5 pl-4 hover:bg-gray-100 hover:rounded rounded max-sm:text-left max-sm:block"
              activeClassName="bg-primary text-gray-100"
              title={__('Tools', 'texty')}
            >
              <span className="dashicons dashicons-admin-tools"></span>
              <span className="ml-1">{__('Tools', 'texty')}</span>
            </NavLink>
          </div>
        </div>
      </div>
    </div>
  );
}

export default Header;
