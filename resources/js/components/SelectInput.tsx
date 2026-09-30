import React, { forwardRef, useRef, useEffect } from 'react';
export default forwardRef(function SelectInput({ className = '', children, isFocused = false, ...props }: any, ref: any) {
    const input = ref ? ref : useRef<HTMLSelectElement>(null);
    useEffect(() => { if (isFocused) { input.current?.focus(); } }, []);
    return <select {...props} className={`border-gray-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm ${className}`} ref={input}>{children}</select>;
});
