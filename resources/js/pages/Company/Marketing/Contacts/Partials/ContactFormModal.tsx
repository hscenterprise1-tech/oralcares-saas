import React, { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { CrmModal } from '@/pages/Company/design/CrmOverlay';
import { CrmButton } from '@/pages/Company/design/CrmControls';
import { Button } from '@/ovs/primitives/button';
import { Input } from '@/ovs/primitives/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/ovs/primitives/select';
import type { MarketingContact, MarketingTag } from '../types';

interface ContactFormModalProps {
  open: boolean;
  onClose: () => void;
  contact: MarketingContact | null;
  tags: MarketingTag[];
}

export function ContactFormModal({ open, onClose, contact, tags }: ContactFormModalProps) {
  const isEditing = Boolean(contact);

  const form = useForm({
    name: '',
    phone: '',
    organization: '',
    city: '',
    marketing_status: 'active',
    tags: [] as string[],
  });

  useEffect(() => {
    if (contact) {
      form.setData({
        name: contact.name || '',
        phone: contact.phone || '',
        organization: contact.organization || '',
        city: contact.city || '',
        marketing_status: contact.marketing_status || 'active',
        tags: contact.tags ? contact.tags.map((t) => t.name) : [],
      });
    } else {
      form.reset();
    }
  }, [contact, open]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (isEditing && contact) {
      form.put(`/company/marketing/contacts/${contact.id}`, {
        onSuccess: () => onClose(),
      });
    } else {
      form.post('/company/marketing/contacts', {
        onSuccess: () => {
          form.reset();
          onClose();
        },
      });
    }
  };

  const toggleTag = (tagName: string) => {
    const current = form.data.tags;
    if (current.includes(tagName)) {
      form.setData('tags', current.filter((t) => t !== tagName));
    } else {
      form.setData('tags', [...current, tagName]);
    }
  };

  return (
    <CrmModal
      open={open}
      onClose={onClose}
      title={isEditing ? 'Edit contact' : 'New contact'}
      description={
        isEditing
          ? 'Update their details, organisation and tags.'
          : 'Someone you can message. A phone number is what makes them reachable.'
      }
      footer={
        <>
          <CrmButton onClick={onClose}>Cancel</CrmButton>
          <CrmButton tone="primary" type="submit" form="contact-form" loading={form.processing}>
            {isEditing ? 'Save changes' : 'Create contact'}
          </CrmButton>
        </>
      }
    >
      <form id="contact-form" onSubmit={handleSubmit} className="space-y-4">
          <div className="space-y-1">
            <label className="text-xs font-semibold text-ovs-primary">Full Name *</label>
            <Input
              value={form.data.name}
              onChange={(e) => form.setData('name', e.target.value)}
              placeholder="e.g. Dr. Rajesh Sharma"
              required
            />
            {form.errors.name && (
              <p className="text-2xs text-ovs-semantic-danger">{form.errors.name}</p>
            )}
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1">
              <label className="text-xs font-semibold text-ovs-primary">Phone Number *</label>
              <Input
                value={form.data.phone}
                onChange={(e) => form.setData('phone', e.target.value)}
                placeholder="e.g. +91 98765 43210"
                required
              />
              {form.errors.phone && (
                <p className="text-2xs text-ovs-semantic-danger">{form.errors.phone}</p>
              )}
            </div>

            <div className="space-y-1">
              <label className="text-xs font-semibold text-ovs-primary">City</label>
              <Input
                value={form.data.city}
                onChange={(e) => form.setData('city', e.target.value)}
                placeholder="e.g. Mumbai, Vadodara"
              />
              {form.errors.city && (
                <p className="text-2xs text-ovs-semantic-danger">{form.errors.city}</p>
              )}
            </div>
          </div>

          <div className="space-y-1">
            <label className="text-xs font-semibold text-ovs-primary">Organization / Clinic</label>
            <Input
              value={form.data.organization}
              onChange={(e) => form.setData('organization', e.target.value)}
              placeholder="e.g. Smile Dental Care"
            />
            {form.errors.organization && (
              <p className="text-2xs text-ovs-semantic-danger">{form.errors.organization}</p>
            )}
          </div>

          <div className="space-y-1">
            <label className="text-xs font-semibold text-ovs-primary">Marketing Status</label>
            <Select
              value={form.data.marketing_status}
              onValueChange={(val) => form.setData('marketing_status', val)}
            >
              <SelectTrigger>
                <SelectValue placeholder="Select Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="active">Active (Subscribed)</SelectItem>
                <SelectItem value="opted_out">Opted Out</SelectItem>
                <SelectItem value="suppressed">Suppressed</SelectItem>
              </SelectContent>
            </Select>
            {form.errors.marketing_status && (
              <p className="text-2xs text-ovs-semantic-danger">{form.errors.marketing_status}</p>
            )}
          </div>

          <div className="space-y-2">
            <label className="text-xs font-semibold text-ovs-primary">Assign Tags</label>
            <div className="flex flex-wrap gap-1.5 max-h-28 overflow-y-auto p-2 rounded-xl border border-ovs-border-subtle bg-ovs-background/50">
              {tags.map((tag) => {
                const isSelected = form.data.tags.includes(tag.name);
                return (
                  <button
                    key={tag.id}
                    type="button"
                    onClick={() => toggleTag(tag.name)}
                    className={`text-2xs font-semibold px-2.5 py-1 rounded-lg border transition-all ${
                      isSelected
                        ? 'bg-ovs-accent text-white border-ovs-accent shadow-2xs'
                        : 'bg-white text-ovs-secondary border-ovs-border-subtle hover:border-ovs-accent/40'
                    }`}
                  >
                    {tag.name}
                  </button>
                );
              })}
            </div>
          </div>

        </form>
    </CrmModal>
  );
}
