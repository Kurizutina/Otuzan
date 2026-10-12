import { ROLES, VALID_CODES } from '../config/roles';

const CONTACT_NUMBER_PATTERN = /^09\d{9}$/;
export const sanitizeContactNumber = (value) => String(value).replace(/\D/g, '').slice(0, 11);

// Gmail username rules, applied to every provider: 6-30 characters of letters,
// digits and periods, with no leading, trailing or repeated periods.
const EMAIL_USERNAME_PATTERN = /^(?!\.)(?!.*\.\.)[a-z0-9.]{6,30}(?<!\.)$/i;

export const validateEmailUsername = (email, setMessage) => {
  const local = String(email).trim().split('@')[0];
  if (EMAIL_USERNAME_PATTERN.test(local)) return true;
  setMessage({
    type: 'error',
    text: 'Email username must be 6-30 characters using only letters, numbers and periods (no leading, trailing or repeated periods).'
  });
  return false;
};

export const validateContactNumber = (contactNumber, setMessage) => {
  if (CONTACT_NUMBER_PATTERN.test(contactNumber.trim())) return true;
  setMessage({
    type: 'error',
    text: 'Contact number must be exactly 11 digits and start with 09.'
  });
  return false;
};

export const validateAccessCode = (
  selectedRole,
  accessCode,
  setMessage
) => {
  const currentRole = ROLES[selectedRole.toUpperCase()];

  if (!currentRole.requiresCode) {
    return true;
  }

  if (!accessCode.trim()) {
    setMessage({
      type: 'error',
      text: `Please enter your ${currentRole.codeLabel}`
    });

    return false;
  }

  const validCode = VALID_CODES[selectedRole];

  if (accessCode !== validCode) {
    setMessage({
      type: 'error',
      text: `Invalid ${currentRole.codeLabel}. Please try again.`
    });

    return false;
  }

  return true;
};


export const validateAdditionalFields = (
  mode,
  selectedRole,
  fields,
  setMessage
) => {
  if (mode === 'login') {
    return true;
  }

  const {
    username,
    name,
    address,
    contactNumber
  } = fields;

  if (selectedRole === 'customer') {
    if (!username.trim()) {
      setMessage({
        type: 'error',
        text: 'Full name is required for customers'
      });
      return false;
    }

    if (!address.trim()) {
      setMessage({
        type: 'error',
        text: 'Address is required for delivery'
      });
      return false;
    }

    if (!contactNumber.trim()) {
      setMessage({
        type: 'error',
        text: 'Contact number is required'
      });
      return false;
    }

    if (!validateContactNumber(contactNumber, setMessage)) return false;
  }

  if (
    selectedRole === 'rider' ||
    selectedRole === 'admin'
  ) {
    if (!name.trim()) {
      setMessage({
        type: 'error',
        text: 'Full name is required'
      });
      return false;
    }

    if (!contactNumber.trim()) {
      setMessage({
        type: 'error',
        text: 'Contact number is required'
      });
      return false;
    }

    if (!validateContactNumber(contactNumber, setMessage)) return false;
  }

  return true;
};
