import { PASSWORD_HINT, passwordProblem } from './passwordRules';

describe('passwordProblem', () => {
  it.each(['123456', 'abcdefgh', '12345678', 'abc1234'])('rejects %s', (password) => {
    expect(passwordProblem(password)).toBe(PASSWORD_HINT);
  });

  it.each(['Secret123', 'riderpass1'])('accepts %s', (password) => {
    expect(passwordProblem(password)).toBe('');
  });
});
