import { useState, useEffect, useCallback } from 'react';

export interface WidgetState<T> {
  data: T | null;
  isLoading: boolean;
  isError: boolean;
  isEmpty: boolean;
  error: Error | null;
  refresh: () => Promise<void>;
}

export function useWidgetData<T>(
  fetcher: () => Promise<T>,
  checkEmpty?: (data: T) => boolean,
  autoRefreshInterval?: number
): WidgetState<T> {
  const [data, setData] = useState<T | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isError, setIsError] = useState<boolean>(false);
  const [error, setError] = useState<Error | null>(null);

  const fetchData = useCallback(async () => {
    setIsLoading(true);
    setIsError(false);
    setError(null);
    try {
      const result = await fetcher();
      setData(result);
    } catch (err: any) {
      setIsError(true);
      setError(err instanceof Error ? err : new Error(err?.message || 'Failed to fetch widget data'));
    } finally {
      setIsLoading(false);
    }
  }, [fetcher]);

  useEffect(() => {
    fetchData();

    let intervalId: ReturnType<typeof setInterval>;
    if (autoRefreshInterval && autoRefreshInterval > 0) {
      intervalId = setInterval(fetchData, autoRefreshInterval);
    }

    return () => {
      if (intervalId) clearInterval(intervalId);
    };
  }, [fetchData, autoRefreshInterval]);

  const isEmpty = data === null || (checkEmpty ? checkEmpty(data) : false);

  return {
    data,
    isLoading,
    isError,
    isEmpty,
    error,
    refresh: fetchData,
  };
}
