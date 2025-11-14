import React from 'react';

function ActiveIcon({ isActive }) {
  return (
    <svg
      fill="none"
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth="2"
      stroke="currentColor"
      viewBox="0 0 24 24"
      className={`${isActive ? 'block' : 'hidden'} text-green-500 absolute right-4 top-4 h-8`}
    >
      <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
    </svg>
  );
}

export default ActiveIcon;
