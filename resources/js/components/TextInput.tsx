import React, { forwardRef, useEffect, useRef } from 'react';
export default forwardRef(function TextInput(
    { type = 'text', className = '', isFocused = false, ...props }: any, ref: any
) {
    const input = ref ? ref : useRef<HTMLInputElement>(null);
    useEffect(() => { if (isFocused) { input.current?.focus(); } }, []);
    return <input {...props} type={type} className={`border-gray-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm ${className}`} ref={input} />;
});
