import { useState, useEffect } from 'react';

/**
 * Custom hook for debouncing search input with a configurable delay (default 500ms).
 * If the input query is only 1 character, it ignores the search (returns empty string),
 * ensuring search only activates when at least 2 characters are typed or when reset to 0.
 *
 * @param value Input search string
 * @param delay Delay in milliseconds (default 500ms)
 * @param minChars Minimum characters required to apply filter (default 2)
 */
export function useDebounce(value: string, delay = 500, minChars = 2): string {
  const [debouncedValue, setDebouncedValue] = useState<string>(() => {
    const trimmed = (value || '').trim();
    return trimmed.length >= minChars ? trimmed : '';
  });

  useEffect(() => {
    const timer = setTimeout(() => {
      const trimmed = (value || '').trim();
      // If 1 char, don't execute search / filter (treat as empty search)
      if (trimmed.length > 0 && trimmed.length < minChars) {
        setDebouncedValue('');
      } else {
        setDebouncedValue(trimmed);
      }
    }, delay);

    return () => {
      clearTimeout(timer);
    };
  }, [value, delay, minChars]);

  return debouncedValue;
}
