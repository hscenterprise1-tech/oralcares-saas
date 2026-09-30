import React from 'react';
import { useForm } from '@inertiajs/react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthInput, AuthButton, AuthAlert } from '@/components/auth/AuthInput';
import { UserPlus } from 'lucide-react';

export default function InviteUser() {
  const { data, setData, post, processing, errors } = useForm({
    email: '',
    role: 'Sales Representative',
    department: 'Sales',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/company/invite-user');
  };

  return (
    <AuthShell
      pageTitle="Invite Team Member"
      formTitle="Invite Employee"
      formSubtitle="Send an invitation email to onboard a new team member to OralCares OS."
    >
      <AuthAlert message={errors.email || ''} />

      <form onSubmit={handleSubmit} className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm">
        <AuthInput
          id="email"
          label="Corporate Email Address"
          type="email"
          value={data.email}
          onChange={e => setData('email', e.target.value)}
          disabled={processing}
          autoFocus
          required
          placeholder="colleague@oralcares.in"
          error={errors.email}
        />

        <div className="space-y-2">
          <label htmlFor="role" className="block text-[12.5px] font-bold text-[#0F172A]">
            Assign System Role <span className="text-red-500">*</span>
          </label>
          <select
            id="role"
            value={data.role}
            onChange={e => setData('role', e.target.value)}
            disabled={processing}
            className="w-full h-[48px] bg-[#F8F9FB] border border-[#E4E7EE] focus:border-[#6366F1] focus:ring-2 focus:ring-[#6366F1]/20 rounded-xl px-4 text-[13.5px] text-[#0F172A] focus:outline-none"
          >
            <option value="Sales Representative">Sales Representative</option>
            <option value="Customer Support Manager">Customer Support Manager</option>
            <option value="Finance Specialist">Finance Specialist</option>
            <option value="Operations Admin">Operations Admin</option>
            <option value="Company Administrator">Company Administrator</option>
          </select>
        </div>

        <AuthButton
          type="submit"
          loading={processing}
          icon={<UserPlus className="w-4 h-4 text-emerald-300" />}
        >
          Send Official Invitation
        </AuthButton>
      </form>
    </AuthShell>
  );
}
