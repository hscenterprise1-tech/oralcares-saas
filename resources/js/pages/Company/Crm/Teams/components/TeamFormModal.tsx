import React, { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Loader2, Plus, Trash2, Users, MapPin, Shuffle } from 'lucide-react';
import { CrmModal } from '../../components/CrmOverlay';
import { CrmButton } from '../../components/CrmControls';
import { Input } from '../../components/CrmFormPrimitives';
import { Switch } from '../../components/CrmFormPrimitives';
import { Team, AssignableUser } from '../types';

interface TeamFormModalProps {
  isOpen: boolean;
  onClose: () => void;
  team?: Team | null;
  assignableUsers: AssignableUser[];
}

interface TerritoryRow {
  state: string;
  userId: string;
}

export const TeamFormModal: React.FC<TeamFormModalProps> = ({ isOpen, onClose, team, assignableUsers }) => {
  const isEditing = !!team;

  const { data, setData, post, put, processing, errors, reset } = useForm({
    name: '',
    description: '',
    manager_id: '' as string,
    is_active: true,
    assignment_strategy: 'round_robin' as 'round_robin' | 'territory',
    member_ids: [] as number[],
    territory_map: {} as Record<string, number>,
  });

  const [territoryRows, setTerritoryRows] = useState<TerritoryRow[]>([]);

  useEffect(() => {
    if (!isOpen) return;
    if (team) {
      setData({
        name: team.name,
        description: team.description ?? '',
        manager_id: team.manager ? String(team.manager.id) : '',
        is_active: team.is_active,
        assignment_strategy: team.assignment_strategy as 'round_robin' | 'territory',
        member_ids: team.members.map((m) => m.id),
        territory_map: team.territory_map,
      });
      setTerritoryRows(
        Object.entries(team.territory_map).map(([state, userId]) => ({ state, userId: String(userId) }))
      );
    } else {
      reset();
      setTerritoryRows([]);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, team]);

  const toggleMember = (userId: number) => {
    setData(
      'member_ids',
      data.member_ids.includes(userId)
        ? data.member_ids.filter((id) => id !== userId)
        : [...data.member_ids, userId]
    );
  };

  const addTerritoryRow = () => setTerritoryRows([...territoryRows, { state: '', userId: '' }]);
  const removeTerritoryRow = (idx: number) => setTerritoryRows(territoryRows.filter((_, i) => i !== idx));
  const updateTerritoryRow = (idx: number, field: keyof TerritoryRow, value: string) => {
    setTerritoryRows(territoryRows.map((row, i) => (i === idx ? { ...row, [field]: value } : row)));
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    const territory_map: Record<string, number> = {};
    territoryRows.forEach((row) => {
      if (row.state.trim() && row.userId) {
        territory_map[row.state.trim()] = Number(row.userId);
      }
    });
    setData('territory_map', territory_map);

    const options = {
      preserveScroll: true,
      onSuccess: () => {
        reset();
        onClose();
      },
    };

    if (isEditing && team) {
      put(route('company.crm.teams.update', team.id), options);
    } else {
      post(route('company.crm.teams.store'), options);
    }
  };

  return (
    <CrmModal
      open={isOpen}
      onClose={onClose}
      title={isEditing ? 'Edit sales team' : 'Create sales team'}
      description="Name the team, pick its lead, and map the territories it covers."
      footer={
        <>
          <CrmButton tone="ghost" onClick={onClose}>Cancel</CrmButton>
          <CrmButton
            tone="primary"
            type="submit"
            form="team-form"
            disabled={processing || !data.name.trim()}
          >
            {processing && <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />}
            {isEditing ? 'Save changes' : 'Create team'}
          </CrmButton>
        </>
      }
    >
        <form id="team-form" onSubmit={handleSubmit} className="space-y-5">
          <div className="space-y-1.5">
            <label className="block text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">
              Team Name <span className="text-ovs-crm-danger">*</span>
            </label>
            <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. North India Sales Team" />
            {errors.name && <p className="text-2xs text-ovs-crm-danger mt-1">{errors.name}</p>}
          </div>

          <div className="space-y-1.5">
            <label className="block text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">Description</label>
            <Input value={data.description} onChange={(e) => setData('description', e.target.value)} placeholder="Optional" />
          </div>

          <div className="space-y-2">
            <label className="block text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">Team Manager</label>
            <select
              value={data.manager_id}
              onChange={(e) => setData('manager_id', e.target.value)}
              className="w-full rounded-[16px] border border-ovs-crm-border bg-white px-3 py-2 text-[13px] text-ovs-crm-text-primary"
            >
              <option value="">No manager</option>
              {assignableUsers.map((u) => (
                <option key={u.id} value={u.id}>{u.name}</option>
              ))}
            </select>
            {errors.manager_id && <p className="text-2xs text-ovs-crm-danger">{errors.manager_id}</p>}
          </div>

          <div className="flex items-center justify-between py-1">
            <span className="text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">Active</span>
            <Switch checked={data.is_active} onCheckedChange={(v) => setData('is_active', v)} />
          </div>

          {/* ASSIGNMENT STRATEGY */}
          <div className="space-y-2">
            <label className="block text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">New Lead Assignment</label>
            <div className="grid grid-cols-2 gap-2">
              <button
                type="button"
                onClick={() => setData('assignment_strategy', 'round_robin')}
                className={`flex items-center gap-2 h-10 px-3 rounded-full border text-[11.5px] font-semibold transition-all cursor-pointer ${
                  data.assignment_strategy === 'round_robin'
                    ? 'border-ovs-crm-brand bg-ovs-crm-brand/10 text-ovs-crm-brand'
                    : 'border-ovs-crm-border bg-white text-ovs-crm-text-secondary hover:border-ovs-crm-brand/40'
                }`}
              >
                <Shuffle className="w-3.5 h-3.5" /> Round-robin
              </button>
              <button
                type="button"
                onClick={() => setData('assignment_strategy', 'territory')}
                className={`flex items-center gap-2 h-10 px-3 rounded-full border text-[11.5px] font-semibold transition-all cursor-pointer ${
                  data.assignment_strategy === 'territory'
                    ? 'border-ovs-crm-brand bg-ovs-crm-brand/10 text-ovs-crm-brand'
                    : 'border-ovs-crm-border bg-white text-ovs-crm-text-secondary hover:border-ovs-crm-brand/40'
                }`}
              >
                <MapPin className="w-3.5 h-3.5" /> State-wise
              </button>
            </div>
          </div>

          {/* TERRITORY MAP — only when strategy = territory */}
          {data.assignment_strategy === 'territory' && (
            <div className="space-y-2 p-3 rounded-[16px] bg-ovs-crm-surface-inset border border-ovs-crm-border">
              <div className="flex items-center justify-between">
                <span className="text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">State → Rep Mapping</span>
                <button
                  type="button"
                  onClick={addTerritoryRow}
                  className="flex items-center gap-1 text-2xs font-semibold text-ovs-crm-brand hover:text-ovs-crm-brand/80 cursor-pointer"
                >
                  <Plus className="w-3 h-3" /> Add
                </button>
              </div>

              {territoryRows.length === 0 && (
                <p className="text-2xs text-ovs-crm-text-muted italic">No states mapped yet — add one below.</p>
              )}

              {territoryRows.map((row, idx) => (
                <div key={idx} className="flex items-center gap-2">
                  <input
                    type="text"
                    value={row.state}
                    onChange={(e) => updateTerritoryRow(idx, 'state', e.target.value)}
                    placeholder="e.g. Maharashtra"
                    className="flex-1 h-8 px-2.5 text-[11.5px] rounded-[16px] border border-ovs-crm-border bg-white text-ovs-crm-text-primary focus:ring-1 focus:ring-ovs-crm-text-primary focus:outline-none"
                  />
                  <select
                    value={row.userId}
                    onChange={(e) => updateTerritoryRow(idx, 'userId', e.target.value)}
                    className="flex-1 h-8 px-2.5 text-[11.5px] rounded-[16px] border border-ovs-crm-border bg-white text-ovs-crm-text-primary focus:ring-1 focus:ring-ovs-crm-text-primary focus:outline-none cursor-pointer"
                  >
                    <option value="">Select rep</option>
                    {assignableUsers.map((u) => (
                      <option key={u.id} value={u.id}>{u.name}</option>
                    ))}
                  </select>
                  <button
                    type="button"
                    onClick={() => removeTerritoryRow(idx)}
                    className="text-ovs-crm-text-muted hover:text-ovs-crm-danger cursor-pointer flex-shrink-0"
                  >
                    <Trash2 className="w-3.5 h-3.5" />
                  </button>
                </div>
              ))}
            </div>
          )}

          {/* MEMBERS */}
          <div className="space-y-2">
            <label className="block text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">
              Team Members
              {data.assignment_strategy === 'round_robin' && <span className="font-normal normal-case text-ovs-crm-text-muted"> — leads cycle through these reps in order</span>}
            </label>
            <div className="max-h-52 overflow-y-auto space-y-1 p-2 rounded-[16px] bg-white shadow-ovs-crmPanel">
              {assignableUsers.length === 0 && (
                <p className="text-2xs text-ovs-crm-text-muted italic p-2">No assignable users found in this organization.</p>
              )}
              {assignableUsers.map((u) => (
                <label
                  key={u.id}
                  className="flex items-center gap-2.5 p-1.5 rounded-[16px] hover:bg-ovs-crm-surface-inset cursor-pointer"
                >
                  <input
                    type="checkbox"
                    checked={data.member_ids.includes(u.id)}
                    onChange={() => toggleMember(u.id)}
                    className="w-3.5 h-3.5 rounded border-ovs-crm-border text-ovs-crm-brand focus:ring-ovs-crm-brand/30 cursor-pointer"
                  />
                  <span className="text-[11.5px] font-medium text-ovs-crm-text-primary">{u.name}</span>
                  <span className="text-2xs text-ovs-crm-text-muted">{u.email}</span>
                </label>
              ))}
            </div>
          </div>

        </form>
    </CrmModal>
  );
};

export default TeamFormModal;
