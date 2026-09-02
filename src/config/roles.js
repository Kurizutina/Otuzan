export const ROLES = {
  CUSTOMER: {
    key: 'customer',
    label: 'Customer',
    icon: 'fa-solid fa-user',
    color: '#F9C12F',
    requiresCode: false,
    fields: ['username', 'address', 'contactNumber']
  },

  DRIVER: {
    key: 'driver',
    label: 'Driver',
    icon: 'fa-solid fa-truck-fast',
    color: '#F15A29',
    requiresCode: true,
    codeLabel: 'Driver Access Code',
    fields: ['name', 'contactNumber']
  },

  ADMIN: {
    key: 'admin',
    label: 'Admin',
    icon: 'fa-solid fa-shield-halved',
    color: '#DA1C5C',
    requiresCode: true,
    codeLabel: 'Admin Access Code',
    fields: ['name', 'contactNumber']
  }
};

export const VALID_CODES = {
  driver: 'DRIVER2024',
  admin: 'ADMIN2024'
};