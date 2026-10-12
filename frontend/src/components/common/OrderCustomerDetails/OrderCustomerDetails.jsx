import React from 'react';
import './OrderCustomerDetails.css';

const OrderCustomerDetails = ({ order }) => {
  const address = order.customerAddress?.trim() || '';
  const area = order.deliveryLocationName?.trim() || '';
  return (
    <dl className="order-customer-details">
      <div><dt>Customer name</dt><dd>{order.customerName?.trim() || 'Name not provided'}</dd></div>
      <div><dt>Customer contact</dt><dd>{order.customerContact?.trim() || 'Contact not provided'}</dd></div>
      <div><dt>Customer address</dt><dd>{address || 'Address not provided'}</dd></div>
      {area && area !== address && <div><dt>Delivery area</dt><dd>{area}</dd></div>}
    </dl>
  );
};

export default OrderCustomerDetails;
