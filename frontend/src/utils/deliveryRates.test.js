import { calculateDeliveryFee, findDeliveryLocation } from './deliveryRates';

const villaJavier = findDeliveryLocation('villa-javier');

test('students receive regular rates through 8:00 PM and a 50% surcharge after', () => {
  expect(calculateDeliveryFee(villaJavier, 'student', new Date('2026-09-17T20:00:00+08:00'))).toMatchObject({
    baseFee: 75, surcharge: 0, serviceFee: 75, surchargeApplied: false
  });
  expect(calculateDeliveryFee(villaJavier, 'student', new Date('2026-09-17T20:01:00+08:00'))).toMatchObject({
    baseFee: 75, surcharge: 37.5, serviceFee: 112.5, surchargeApplied: true
  });
});

test('non-students receive regular rates through 6:00 PM and a 50% surcharge after', () => {
  expect(calculateDeliveryFee(villaJavier, 'non_student', new Date('2026-09-17T18:00:00+08:00'))).toMatchObject({
    serviceFee: 75, surchargeApplied: false
  });
  expect(calculateDeliveryFee(villaJavier, 'non_student', new Date('2026-09-17T18:01:00+08:00'))).toMatchObject({
    baseFee: 75, surcharge: 37.5, serviceFee: 112.5, surchargeApplied: true
  });
});

test('the night cutoff follows Manila time, not the device clock', () => {
  // 12:01 UTC is 20:01 in Manila - a student is past their cutoff even on a UTC device.
  expect(calculateDeliveryFee(villaJavier, 'student', new Date('2026-09-17T12:01:00Z'))).toMatchObject({
    serviceFee: 112.5, surchargeApplied: true
  });
  // 02:00 UTC is 10:00 in Manila - daytime for everyone.
  expect(calculateDeliveryFee(villaJavier, 'non_student', new Date('2026-09-17T02:00:00Z'))).toMatchObject({
    serviceFee: 75, surchargeApplied: false
  });
});
