import { pickLocale } from '@simuwork/core';
import { academyFromQr, normaliseCode } from '../src/lib/academy';
import { mix } from '../src/ui/theme';

describe('academy codes', () => {
  it.each([
    ['ubuntu', 'ubuntu'],
    [' UBUNTU ', 'ubuntu'],
    ['ubuntu.simuwork.co.za', 'ubuntu'],
    ['https://ubuntu.simuwork.co.za/join', 'ubuntu'],
    ['bad code!', null],
    ['', null],
  ])('%s → %s', (input, expected) => expect(normaliseCode(input)).toBe(expected));

  it('only accepts SimuWork QR codes', () => {
    expect(academyFromQr('https://ubuntu.simuwork.co.za/join')).toBe('ubuntu');
    expect(academyFromQr('https://evil.example.com/ubuntu')).toBeNull();
    expect(academyFromQr('http://ubuntu.simuwork.co.za')).toBeNull();
  });
});

describe('helpers', () => {
  it('picks a supported device language', () => {
    expect(pickLocale(['zu-ZA', 'en-ZA'])).toBe('zu');
    expect(pickLocale(['fr-FR', 'af-ZA'])).toBe('af');
    expect(pickLocale(['fr-FR'])).toBe('en');
  });

  it('mixes colours', () => {
    expect(mix('#000000', '#FFFFFF', 0.5)).toBe('#808080');
  });
});
