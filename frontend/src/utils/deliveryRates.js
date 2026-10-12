export const DELIVERY_LOCATIONS = [
  { id: 'clsu-main-campus', name: 'CLSU Main Campus', fee: 75 },
  { id: 'bagong-sikat', name: 'Bagong Sikat', fee: 75 },
  { id: 'villa-javier', name: 'Villa Javier', fee: 75 },
  { id: 'bukang-liwayway', name: 'Bukang Liwayway', fee: 75 },
  { id: 'tobias-ville', name: 'Tobias Ville', fee: 75 },
  { id: 'villa-isidra', name: 'Villa Isidra', fee: 75 },
  { id: 'pnr-main', name: 'PNR Main', fee: 75 }
];

export const CUSTOMER_TYPES = [
  { id: 'student', label: 'Student', cutoffMinutes: 20 * 60 },
  { id: 'non_student', label: 'Non-student', cutoffMinutes: 18 * 60 }
];

const normalise = (value = '') => value.trim().toLowerCase();

export const findDeliveryLocation = (selection, address = '') => {
  const selected = DELIVERY_LOCATIONS.find((location) => location.id === selection);
  if (selected) return selected;
  const addressText = normalise(address);
  return DELIVERY_LOCATIONS.find((location) => addressText.includes(normalise(location.name))) || null;
};

export const deliveryRateLabel = (location) => location ? `₱${location.fee}` : 'Select location';

// The cutoff is business time (Asia/Manila), whatever timezone the device is
// set to - the server (App\Support\DeliveryFee) charges by the same clock.
const manilaMinutes = (time) => {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
  }).formatToParts(time);
  const part = (type) => Number(parts.find((item) => item.type === type)?.value) || 0;
  return (part('hour') * 60) + part('minute');
};

export const calculateDeliveryFee = (location, customerType = 'non_student', orderTime = new Date()) => {
  const type = CUSTOMER_TYPES.find((item) => item.id === customerType) || CUSTOMER_TYPES[1];
  const minutes = manilaMinutes(new Date(orderTime));
  const baseFee = Number(location?.fee) || 0;
  const surchargeApplied = baseFee > 0 && minutes > type.cutoffMinutes;
  const surcharge = surchargeApplied ? baseFee * 0.5 : 0;
  return {
    customerType: type.id,
    baseFee,
    surcharge,
    serviceFee: baseFee + surcharge,
    surchargeApplied
  };
};
