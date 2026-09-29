import React, { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { CrmModal } from './CrmOverlay';
import { CrmButton } from './CrmControls';
import { Button } from './CrmFormPrimitives';
import { Input } from './CrmFormPrimitives';
import { Label } from './CrmFormPrimitives';
import { CrmDatePicker, CRM_DATE_PICKER_OVS_FIELD } from '@/pages/Company/design';

export interface Task {
    id: number;
    title: string;
    description?: string;
    status: string;
    type: string;
    priority: string;
    due_at: string;
    assigned_user_id?: number | null;
    taskable_type?: string;
    taskable_id?: number;
}

interface TaskModalProps {
    isOpen: boolean;
    onClose: () => void;
    task?: Task | null;
    defaultTaskableType?: string;
    defaultTaskableId?: number;
}

export function TaskModal({ isOpen, onClose, task, defaultTaskableType, defaultTaskableId }: TaskModalProps) {
    const isEdit = !!task?.id;

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        title: '',
        description: '',
        status: 'pending',
        type: 'to_do',
        priority: 'medium',
        due_at: '',
        taskable_type: defaultTaskableType || null,
        taskable_id: defaultTaskableId || null,
    });

    useEffect(() => {
        if (isOpen) {
            if (task) {
                setData({
                    title: task.title || '',
                    description: task.description || '',
                    status: task.status || 'pending',
                    type: task.type || 'to_do',
                    priority: task.priority || 'medium',
                    due_at: task.due_at ? task.due_at.split('T')[0] : '', // simple date input formatting
                    taskable_type: task.taskable_type || defaultTaskableType || null,
                    taskable_id: task.taskable_id || defaultTaskableId || null,
                });
            } else {
                reset();
                setData('taskable_type', defaultTaskableType || null);
                setData('taskable_id', defaultTaskableId || null);
            }
            clearErrors();
        }
    }, [isOpen, task, defaultTaskableType, defaultTaskableId]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (isEdit) {
            put(`/company/crm/tasks/${task.id}`, {
                onSuccess: () => {
                    onClose();
                    reset();
                },
            });
        } else {
            post('/company/crm/tasks', {
                onSuccess: () => {
                    onClose();
                    reset();
                },
            });
        }
    };

    return (
        <CrmModal
            open={isOpen}
            onClose={onClose}
            title={isEdit ? 'Edit task' : 'New task'}
            description={isEdit ? 'Update this task.' : 'Something that needs doing, and when it needs doing by.'}
            footer={
                <>
                    <CrmButton onClick={onClose}>Cancel</CrmButton>
                    <CrmButton tone="primary" type="submit" form="task-form" loading={processing}>
                        {isEdit ? 'Save changes' : 'Create task'}
                    </CrmButton>
                </>
            }
        >
            <form id="task-form" onSubmit={handleSubmit}>
                    
                    <div className="grid gap-4 py-4">
                        <div className="space-y-2">
                            <Label htmlFor="title">Task Title <span className="text-ovs-crm-danger">*</span></Label>
                            <Input
                                id="title"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                placeholder="e.g. Follow up on proposal"
                            />
                            {errors.title && <p className="text-[13px] text-ovs-crm-danger">{errors.title}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="description">Description</Label>
                            <textarea
                                id="description"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                className="flex min-h-[80px] w-full rounded-[16px] border border-ovs-crm-border bg-white px-3 py-2 text-[13px] ring-offset-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-text-primary disabled:cursor-not-allowed disabled:opacity-50"
                                placeholder="Add extra context..."
                            />
                            {errors.description && <p className="text-[13px] text-ovs-crm-danger">{errors.description}</p>}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="type">Task Type <span className="text-ovs-crm-danger">*</span></Label>
                                <select
                                    id="type"
                                    value={data.type}
                                    onChange={(e) => setData('type', e.target.value)}
                                    className="flex h-10 w-full rounded-[16px] bg-ovs-crm-surface-inset px-3 py-2 text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-text-primary focus:outline-none focus:ring-2 focus:ring-ovs-crm-ink/20"
                                >
                                    <option value="call">Call</option>
                                    <option value="email">Email</option>
                                    <option value="demo">Demo</option>
                                    <option value="follow_up">Follow Up</option>
                                    <option value="to_do">To Do</option>
                                </select>
                                {errors.type && <p className="text-[13px] text-ovs-crm-danger">{errors.type}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="priority">Priority <span className="text-ovs-crm-danger">*</span></Label>
                                <select
                                    id="priority"
                                    value={data.priority}
                                    onChange={(e) => setData('priority', e.target.value)}
                                    className="flex h-10 w-full rounded-[16px] bg-ovs-crm-surface-inset px-3 py-2 text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-text-primary focus:outline-none focus:ring-2 focus:ring-ovs-crm-ink/20"
                                >
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                                {errors.priority && <p className="text-[13px] text-ovs-crm-danger">{errors.priority}</p>}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="due_at">Due Date <span className="text-ovs-crm-danger">*</span></Label>
                                <CrmDatePicker
                                    id="due_at"
                                    required
                                    className={CRM_DATE_PICKER_OVS_FIELD}
                                    invalid={Boolean(errors.due_at)}
                                    value={data.due_at}
                                    onChange={(e) => setData('due_at', e.target.value)}
                                />
                                {errors.due_at && <p className="text-[13px] text-ovs-crm-danger">{errors.due_at}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="status">Status <span className="text-ovs-crm-danger">*</span></Label>
                                <select
                                    id="status"
                                    value={data.status}
                                    onChange={(e) => setData('status', e.target.value)}
                                    className="flex h-10 w-full rounded-[16px] bg-ovs-crm-surface-inset px-3 py-2 text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-text-primary focus:outline-none focus:ring-2 focus:ring-ovs-crm-ink/20"
                                >
                                    <option value="pending">Pending</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                                {errors.status && <p className="text-[13px] text-ovs-crm-danger">{errors.status}</p>}
                            </div>
                        </div>
                    </div>

            </form>
        </CrmModal>
    );
}
