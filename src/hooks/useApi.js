import { useState, useEffect, useCallback } from 'react';

/**
 * Hook for fetching data with loading and error states
 * @param {Function} fetchFn - Async function that fetches data
 * @param {Array} deps - Dependencies array for re-fetching
 * @param {Object} options - Optional configuration
 * @param {any} options.initialData - Preloaded data to show immediately
 * @returns {Object} { data, loading, error, refetch }
 */
export function useQuery(fetchFn, deps = [], options = {}) {
	const { initialData = null } = options;
	const [data, setData] = useState(initialData);
	const [loading, setLoading] = useState(initialData === null);
	const [error, setError] = useState(null);

	const fetch = useCallback(async (opts = {}) => {
		const { background = false } = opts;
		if (!background) {
			setLoading(true);
		}
		setError(null);
		try {
			const result = await fetchFn();
			setData(result);
		} catch (err) {
			setError(err.message || 'An error occurred');
		} finally {
			if (!background) {
				setLoading(false);
			}
		}
	}, [fetchFn]);

	useEffect(() => {
		const shouldBackground = initialData !== null;
		fetch({ background: shouldBackground });
	}, [...deps, fetch, initialData]);

	return { data, loading, error, refetch: fetch };
}

/**
 * Hook for mutations (create, update, delete)
 * @param {Function} mutationFn - Async function that performs the mutation
 * @returns {Object} { mutate, loading, error }
 */
export function useMutation(mutationFn, options = {}) {
	const { onSuccess } = options;
	const [loading, setLoading] = useState(false);
	const [error, setError] = useState(null);

	const mutate = useCallback(async (...args) => {
		setLoading(true);
		setError(null);
		try {
			const result = await mutationFn(...args);
			if (onSuccess) {
				await onSuccess(result, ...args);
			}
			return result;
		} catch (err) {
			setError(err.message || 'An error occurred');
			throw err;
		} finally {
			setLoading(false);
		}
	}, [mutationFn, onSuccess]);

	return { mutate, loading, error };
}

/**
 * Hook for managing form state
 * @param {Object} initialValues - Initial form values
 * @returns {Object} { values, setValue, setValues, reset, isDirty }
 */
export function useForm(initialValues = {}) {
	const [values, setValuesState] = useState(initialValues);
	const [initialState] = useState(initialValues);

	const setValue = useCallback((key, value) => {
		setValuesState(prev => ({ ...prev, [key]: value }));
	}, []);

	const setValues = useCallback((newValues) => {
		setValuesState(prev => ({ ...prev, ...newValues }));
	}, []);

	const reset = useCallback((newInitial) => {
		setValuesState(newInitial || initialState);
	}, [initialState]);

	const isDirty = JSON.stringify(values) !== JSON.stringify(initialState);

	return { values, setValue, setValues, reset, isDirty };
}
