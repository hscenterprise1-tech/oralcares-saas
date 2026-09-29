import React, { useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';

export interface AuthInputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  id: string;
  label: string;
  error?: string;
  isPassword?: boolean;
}

export function AuthInput({
  id,
  label,
  error,
  isPassword = false,
  required,
  className = '',
  type = 'text',
  ...props
}: AuthInputProps) {
  const [showPassword, setShowPassword] = useState(false);
  const inputType = isPassword ? (showPassword ? 'text' : 'password') : type;

  return (
    <div className="space-y-2">
      <label htmlFor={id} className="block text-[12.5px] font-bold text-[#0F172A]">
        {label} {required && <span className="text-red-500">*</span>}
      </label>

      <div className="relative">
        <input
          id={id}
          type={inputType}
          required={required}
          aria-invalid={!!error}
          aria-describedby={error ? `${id}-error` : undefined}
          className={`w-full h-[48px] bg-[#F8F9FB] border text-[13.5px] text-[#0F172A] rounded-xl px-4 transition-all duration-200 focus:outline-none placeholder:text-[#94A3B8] ${
            isPassword ? 'pr-11' : ''
          } ${
            error
              ? 'border-red-500 ring-2 ring-red-500/20'
              : 'border-[#E4E7EE] focus:border-[#6366F1] focus:ring-2 focus:ring-[#6366F1]/20'
          } ${className}`}
          {...props}
        />

        {isPassword && (
          <button
            type="button"
            onClick={() => setShowPassword(!showPassword)}
            aria-label={showPassword ? 'Hide password' : 'Show password as plain text'}
            className="absolute right-3 top-1/2 -translate-y-1/2 p-1.5 rounded-lg text-[#94A3B8] hover:text-[#0F172A] hover:bg-slate-100 transition-all focus:outline-none"
            disabled={props.disabled}
          >
            {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
          </button>
        )}
      </div>

      {error && (
        <p id={`${id}-error`} className="text-[11.5px] font-semibold text-red-600 animate-in fade-in duration-150">
          {error}
        </p>
      )}
    </div>
  );
}

export function AuthButton({
  children,
  loading = false,
  icon,
  className = '',
  disabled,
  ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & { loading?: boolean; icon?: React.ReactNode }) {
  return (
    <button
      disabled={disabled || loading}
      className={`w-full h-[50px] bg-[#0A2540] hover:bg-[#07192C] text-white text-[14px] font-extrabold rounded-xl shadow-md active:scale-[0.99] transition-all duration-200 flex items-center justify-center gap-2 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed ${className}`}
      {...props}
    >
      {loading ? (
        <>
          <svg className="animate-spin w-4 h-4 text-emerald-300" fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
          </svg>
          <span>Processing...</span>
        </>
      ) : (
        <>
          <span>{children}</span>
          {icon}
        </>
      )}
    </button>
  );
}

export function AuthAlert({ type = 'error', message }: { type?: 'error' | 'success'; message: string }) {
  if (!message) return null;
  const isError = type === 'error';
  return (
    <div className={`p-4 rounded-xl flex items-start gap-3 text-[12px] animate-in fade-in duration-200 ${
      isError ? 'bg-red-50 border border-red-200 text-red-800' : 'bg-emerald-50 border border-emerald-200 text-emerald-800'
    }`}>
      <span className="font-bold flex-1">{message}</span>
    </div>
  );
}
