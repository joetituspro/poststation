import { createContext, useContext, useMemo, useState } from 'react';

const UnsavedChangesContext = createContext({
	isDirty: false,
	setIsDirty: () => {},
});

export function UnsavedChangesProvider({ children }) {
	const [isDirty, setIsDirty] = useState(false);
	const value = useMemo(() => ({ isDirty, setIsDirty }), [isDirty]);

	return (
		<UnsavedChangesContext.Provider value={value}>
			{children}
		</UnsavedChangesContext.Provider>
	);
}

export function useUnsavedChanges() {
	return useContext(UnsavedChangesContext);
}
