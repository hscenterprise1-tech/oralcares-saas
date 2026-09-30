import { useMemo } from 'react';
import { usePreview } from '@/contexts/PreviewContext';

// Simple mock generator. In the future, this can be expanded with faker.js
export function useDemoData() {
    const { previewState } = usePreview();

    const mockPatients = useMemo(() => {
        // Return a mocked array or a massive virtualized list placeholder
        // depending on previewState.patientVolume
        return {
            total: previewState.patientVolume,
            summary: `Simulating ${previewState.patientVolume.toLocaleString()} patient records.`
        };
    }, [previewState.patientVolume]);

    const mockStaff = useMemo(() => {
        return {
            total: previewState.staffVolume,
            summary: `Simulating ${previewState.staffVolume.toLocaleString()} staff members.`
        };
    }, [previewState.staffVolume]);

    const mockBranches = useMemo(() => {
        return {
            total: previewState.branchVolume,
            summary: `Simulating ${previewState.branchVolume.toLocaleString()} active branches.`
        };
    }, [previewState.branchVolume]);

    // Data generation for Phase 3 DataTable Virtualization tests
    const mockTableData = useMemo(() => {
        const rows = Math.min(previewState.patientVolume, 10000); // Cap at 10k for Dev Mode demo rendering safety
        const data = [];
        const statuses = ['Active', 'Completed', 'Pending', 'Archived'];
        const branches = ['Main Clinic', 'Branch A', 'Branch B'];
        
        for (let i = 0; i < rows; i++) {
            data.push({
                id: `PT-${10000 + i}`,
                name: `Patient Record ${i}`,
                status: statuses[Math.floor(Math.random() * statuses.length)],
                branch: branches[Math.floor(Math.random() * branches.length)],
                lastVisit: new Date(Date.now() - Math.random() * 10000000000).toISOString().split('T')[0],
                revenue: Math.floor(Math.random() * 5000),
            });
        }
        return data;
    }, [previewState.patientVolume]);

    return {
        mockPatients,
        mockStaff,
        mockBranches,
        mockTableData
    };
}
