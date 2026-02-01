import { useState, useEffect, useCallback } from 'react';

/**
 * Hook for fetching data with loading and error states
 * @param {Function} fetchFn - Async function that fetches data
 * @param {Array} deps - Dependencies array for re-fetching
 * @returns {Object} { data, loading, error, refetch }
 */
export function useQuery(fetchFn, deps = []) {
	const [data, setData] = useState(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);

	const fetch = useCallback(async () => {
		setLoading(true);
		setError(null);
		try {
			const result = await fetchFn();
			setData(result);
		} catch (err) {
			setError(err.message || 'An error occurred');
		} finally {
			setLoading(false);
		}
	}, [fetchFn]);

	useEffect(() => {
		fetch();
	}, [...deps, fetch]);

	return { data, loading, error, refetch: fetch };
}

/**
 * Hook for mutations (create, update, delete)
 * @param {Function} mutationFn - Async function that performs the mutation
 * @returns {Object} { mutate, loading, error }
 */
export function useMutation(mutationFn) {
	const [loading, setLoading] = useState(false);
	const [error, setError] = useState(null);

	const mutate = useCallback(async (...args) => {
		setLoading(true);
		setError(null);
		try {
			const result = await mutationFn(...args);
			return result;
		} catch (err) {
			setError(err.message || 'An error occurred');
			throw err;
		} finally {
			setLoading(false);
		}
	}, [mutationFn]);

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
