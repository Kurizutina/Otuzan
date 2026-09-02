import React from 'react';
import './ServiceNavigation.css';

const ServiceNavigation = ({
  selectedService = 'food',
  onServiceChange
}) => {

  const handleServiceChange = (service) => {
    if (onServiceChange) {
      onServiceChange(service);
    }
  };

  return (
    <div className="service-navigation">

      <button
        className={`service-tab ${
          selectedService === 'food' ? 'active' : ''
        }`}
        onClick={() => handleServiceChange('food')}
      >
        <i className="fa-solid fa-utensils"></i>
        <span>Food Delivery</span>
      </button>

      <button
        className={`service-tab ${
          selectedService === 'item' ? 'active' : ''
        }`}
        onClick={() => handleServiceChange('item')}
      >
        <i className="fa-solid fa-box"></i>
        <span>Item Delivery</span>
      </button>

      <button
        className={`service-tab ${
          selectedService === 'bills' ? 'active' : ''
        }`}
        onClick={() => handleServiceChange('bills')}
      >
        <i className="fa-solid fa-file-invoice-dollar"></i>
        <span>Pay Bills</span>
      </button>

    </div>
  );
};

export default ServiceNavigation;