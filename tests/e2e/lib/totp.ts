/**
 * RFC 6238 TOTP code generator for the E2E TOTP-login spec.
 *
 * Kept dependency-free on purpose: the full scheme is under thirty lines
 * with node's built-in crypto, and pulling in `otpauth`/`speakeasy` would
 * expand the `tests/e2e/package.json` footprint for one test. The generated
 * codes are verified by PHP's OTPHP library on the server side, so any
 * deviation from the spec surfaces as a login-verify failure in the spec.
 */
import { createHmac } from 'node:crypto';

/** Default RFC 6238 parameters: 30 s step, 6 digits, SHA-1. */
const PERIOD_SECONDS = 30;
const DIGITS = 6;
const ALGORITHM = 'sha1';

/**
 * Decode a Base32 (RFC 4648, no padding required) string into raw bytes.
 * Accepts upper- and lowercase input; whitespace is stripped.
 */
function base32Decode(input: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const clean = input.replace(/=+$/, '').replace(/\s+/g, '').toUpperCase();
  const bytes: number[] = [];
  let buffer = 0;
  let bitsInBuffer = 0;
  for (const char of clean) {
    const value = alphabet.indexOf(char);
    if (value < 0) {
      throw new Error(`Invalid Base32 character: ${char}`);
    }
    buffer = (buffer << 5) | value;
    bitsInBuffer += 5;
    if (bitsInBuffer >= 8) {
      bitsInBuffer -= 8;
      bytes.push((buffer >> bitsInBuffer) & 0xff);
    }
  }
  return Buffer.from(bytes);
}

/**
 * Compute the current 6-digit TOTP code for `secret`.
 *
 * `timestamp` defaults to now; pass an explicit value for deterministic
 * tests that need to reason about time windows.
 */
export function generateTotp(secret: string, timestamp: number = Date.now()): string {
  const counter = Math.floor(timestamp / 1000 / PERIOD_SECONDS);
  const counterBuf = Buffer.alloc(8);
  // 32-bit JS bitwise ops are signed — split the counter into two halves.
  counterBuf.writeUInt32BE(Math.floor(counter / 0x100000000), 0);
  counterBuf.writeUInt32BE(counter & 0xffffffff, 4);

  const hmac = createHmac(ALGORITHM, base32Decode(secret)).update(counterBuf).digest();
  const offset = hmac[hmac.length - 1] & 0x0f;
  const binary =
    ((hmac[offset] & 0x7f) << 24) |
    ((hmac[offset + 1] & 0xff) << 16) |
    ((hmac[offset + 2] & 0xff) << 8) |
    (hmac[offset + 3] & 0xff);

  const code = binary % 10 ** DIGITS;
  return code.toString().padStart(DIGITS, '0');
}
