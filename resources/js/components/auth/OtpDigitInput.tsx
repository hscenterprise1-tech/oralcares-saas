import React, { useRef } from 'react';
import { Delete } from 'lucide-react';

export function OtpDigitInput({
  value = '',
  onChange,
  length = 6,
  disabled = false,
}: {
  value: string;
  onChange: (val: string) => void;
  length?: number;
  disabled?: boolean;
}) {
  const inputsRef = useRef<(HTMLInputElement | null)[]>([]);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>, index: number) => {
    const val = e.target.value;
    if (/^[0-9]$/.test(val)) {
      const arr = value.split('');
      arr[index] = val;
      const newOtp = arr.join('').slice(0, length);
      onChange(newOtp);
      if (index < length - 1) {
        inputsRef.current[index + 1]?.focus();
      }
    } else if (val === '') {
      const arr = value.split('');
      arr[index] = '';
      onChange(arr.join(''));
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>, index: number) => {
    if (e.key === 'Backspace' && !value[index] && index > 0) {
      inputsRef.current[index - 1]?.focus();
    }
  };

  const handlePaste = (e: React.ClipboardEvent<HTMLInputElement>) => {
    e.preventDefault();
    const pasteData = e.clipboardData.getData('text').trim();
    if (/^\d+$/.test(pasteData)) {
      const pastedOtp = pasteData.slice(0, length);
      onChange(pastedOtp);
      const nextIndex = Math.min(pastedOtp.length, length - 1);
      inputsRef.current[nextIndex]?.focus();
    }
  };

  return (
    <div className="flex items-center justify-between gap-2 my-4">
      {Array.from({ length }).map((_, idx) => (
        <input
          key={idx}
          ref={el => (inputsRef.current[idx] = el)}
          type="text"
          inputMode="numeric"
          maxLength={1}
          disabled={disabled}
          value={value[idx] || ''}
          onChange={e => handleChange(e, idx)}
          onKeyDown={e => handleKeyDown(e, idx)}
          onPaste={handlePaste}
          className="w-12 h-14 bg-[#F8F9FB] border border-[#E4E7EE] focus:border-[#6366F1] focus:ring-2 focus:ring-[#6366F1]/20 rounded-xl text-center font-mono font-black text-xl text-[#0F172A] focus:outline-none transition-all shadow-2xs"
        />
      ))}
    </div>
  );
}

export function PinPadInput({
  value = '',
  onChange,
  length = 4,
  disabled = false,
}: {
  value: string;
  onChange: (val: string) => void;
  length?: number;
  disabled?: boolean;
}) {
  const handleDigit = (digit: string) => {
    if (value.length < length) {
      onChange(value + digit);
    }
  };

  const handleDelete = () => {
    onChange(value.slice(0, -1));
  };

  const handleClear = () => {
    onChange('');
  };

  return (
    <div className="space-y-6 my-4">
      {/* Masked Dots Indicator */}
      <div className="flex items-center justify-center gap-4">
        {Array.from({ length }).map((_, idx) => (
          <div
            key={idx}
            className={`w-4 h-4 rounded-full border-2 transition-all duration-200 ${
              value[idx]
                ? 'bg-[#0A2540] border-[#0A2540] scale-110 shadow-sm'
                : 'border-[#CBD5E1] bg-white'
            }`}
          />
        ))}
      </div>

      {/* Keypad Grid */}
      <div className="grid grid-cols-3 gap-3 max-w-[280px] mx-auto">
        {['1', '2', '3', '4', '5', '6', '7', '8', '9'].map(num => (
          <button
            key={num}
            type="button"
            disabled={disabled}
            onClick={() => handleDigit(num)}
            className="w-full h-14 bg-white border border-[#E4E7EE] hover:bg-[#F8F9FB] active:bg-[#EEF2FF] rounded-2xl text-[18px] font-black text-[#0F172A] shadow-2xs transition-all flex items-center justify-center cursor-pointer select-none"
          >
            {num}
          </button>
        ))}
        <button
          type="button"
          disabled={disabled}
          onClick={handleClear}
          className="w-full h-14 bg-[#F8F9FB] border border-[#E4E7EE] hover:bg-slate-100 rounded-2xl text-[11px] font-bold text-[#64748B] transition-all flex items-center justify-center cursor-pointer uppercase"
        >
          Clear
        </button>
        <button
          type="button"
          disabled={disabled}
          onClick={() => handleDigit('0')}
          className="w-full h-14 bg-white border border-[#E4E7EE] hover:bg-[#F8F9FB] active:bg-[#EEF2FF] rounded-2xl text-[18px] font-black text-[#0F172A] shadow-2xs transition-all flex items-center justify-center cursor-pointer select-none"
        >
          0
        </button>
        <button
          type="button"
          disabled={disabled}
          onClick={handleDelete}
          className="w-full h-14 bg-[#F8F9FB] border border-[#E4E7EE] hover:bg-slate-100 rounded-2xl text-[13px] font-bold text-[#64748B] transition-all flex items-center justify-center cursor-pointer"
        >
          <Delete className="w-5 h-5 text-[#475569]" />
        </button>
      </div>
    </div>
  );
}
