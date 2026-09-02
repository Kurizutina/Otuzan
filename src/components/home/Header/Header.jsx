import React from 'react';
import Logo from '../../common/Logo/Logo';
import './Header.css';
import SearchBar from './SearchBar/SearchBar';
import ServiceNavigation from './ServiceNavigation/ServiceNavigation';
import CustomerMenu from './CustomerMenu/CustomerMenu';

const MenuIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
    <line x1="3" y1="6" x2="21" y2="6" />
    <line x1="3" y1="12" x2="21" y2="12" />
    <line x1="3" y1="18" x2="21" y2="18" />
  </svg>
);

const CartIcon = () => (
  <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <circle cx="9" cy="20" r="1" />
    <circle cx="19" cy="20" r="1" />
    <path d="M3 4h2l2.4 10.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 7H6" />
  </svg>
);

const NotificationIcon = () => (
  <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
    <path d="M10 21h4" />
  </svg>
);

const Header = ({
  selectedService,
  onServiceChange
}) => {

  return (
    <header className="home-header">
      <div className="header-container">
        <div className="header-logo">
          <Logo />
        </div>

        <div className="header-main">
          <ServiceNavigation
            selectedService={selectedService}
            onServiceChange={onServiceChange}
          />

          <div className="header-tools">
            <div className="header-search">
              <SearchBar />
            </div>

            <div className="header-actions">
              <button className="header-action-button" type="button" aria-label="View notifications">
                <NotificationIcon />
              </button>

              <button className="header-action-button" type="button" aria-label="View cart">
                <CartIcon />
              </button>

              <CustomerMenu icon={<MenuIcon />} />
            </div>
          </div>
        </div>
      </div>

    </header>
  );
};

export default Header;
