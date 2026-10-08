import { focusManager } from '@tanstack/react-query';
import { AppState, type AppStateStatus } from 'react-native';

import { ApiError } from '../errors';
import { refetchOnAppForeground, shouldRetry } from '../queryClient';

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

describe('refetchOnAppForeground', () => {
  it('tells TanStack Query the app is focused only while it is active', () => {
    let onChange: (status: AppStateStatus) => void = () => undefined;
    const remove = jest.fn();
    const spy = jest.spyOn(AppState, 'addEventListener').mockImplementation((_, listener) => {
      onChange = listener;
      return { remove };
    });

    const unsubscribe = refetchOnAppForeground();

    onChange('background');
    expect(focusManager.isFocused()).toBe(false);
    onChange('active');
    expect(focusManager.isFocused()).toBe(true);

    unsubscribe();
    expect(remove).toHaveBeenCalled();
    spy.mockRestore();
    focusManager.setFocused(undefined);
  });
});
