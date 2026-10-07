import { ApiError } from '../errors';
import { shouldRetry } from '../queryClient';

describe('shouldRetry', () => {
  it('retries network failures and 5xx up to twice', () => {
    expect(shouldRetry(0, new ApiError({ status: 0 }))).toBe(true);
    expect(shouldRetry(1, new ApiError({ status: 503 }))).toBe(true);
    expect(shouldRetry(2, new ApiError({ status: 503 }))).toBe(false);
  });

  it('never retries a 4xx, a timeout or an unknown error', () => {
    for (const status of [400, 401, 403, 404, 422, 429]) {
      expect(shouldRetry(0, new ApiError({ status }))).toBe(false);
    }
    expect(shouldRetry(0, new ApiError({ status: 0, timedOut: true }))).toBe(false);
    expect(shouldRetry(0, new Error('boom'))).toBe(false);
  });
});
