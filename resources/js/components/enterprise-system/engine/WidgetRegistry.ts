import { WidgetManifest } from '../manifests/WidgetManifest';
import { ExecutiveHeroManifest } from '../manifests/executiveHero.manifest';
import { RevenueIntelligenceManifest } from '../manifests/revenueIntelligence.manifest';
import { SalesPipelineManifest } from '../manifests/salesPipeline.manifest';
import { TodaysDemosManifest } from '../manifests/todaysDemos.manifest';
import { PendingRenewalsManifest } from '../manifests/pendingRenewals.manifest';
import { CompanyActivityManifest } from '../manifests/companyActivity.manifest';
// Import growth metric manifests
import { ActiveClinicsManifest } from '../manifests/metrics/activeClinics.manifest';
import { TrialClinicsManifest } from '../manifests/metrics/trialClinics.manifest';
import { NewLeadsManifest } from '../manifests/metrics/newLeads.manifest';
import { DemosScheduledManifest } from '../manifests/metrics/demosScheduled.manifest';
import { ConversionsManifest } from '../manifests/metrics/conversions.manifest';
import { RenewalsDueMetricManifest } from '../manifests/metrics/renewalsDueMetric.manifest';

export class WidgetRegistry {
    private static manifests: Map<string, WidgetManifest> = new Map();

    static initialize() {
        if (this.manifests.size > 0) return; // Already initialized

        // Register Core
        this.register(ExecutiveHeroManifest);

        // Register Growth Metrics
        this.register(ActiveClinicsManifest);
        this.register(TrialClinicsManifest);
        this.register(NewLeadsManifest);
        this.register(DemosScheduledManifest);
        this.register(ConversionsManifest);
        this.register(RenewalsDueMetricManifest);

        // Register Main Analytics
        this.register(RevenueIntelligenceManifest);
        this.register(SalesPipelineManifest);

        // Register Operations
        this.register(TodaysDemosManifest);
        this.register(PendingRenewalsManifest);

        // Register Activity
        this.register(CompanyActivityManifest);
    }

    static register(manifest: WidgetManifest) {
        this.manifests.set(manifest.id, manifest);
    }

    static get(id: string): WidgetManifest | undefined {
        return this.manifests.get(id);
    }

    static getAll(): WidgetManifest[] {
        return Array.from(this.manifests.values());
    }

    static getByZone(zone: WidgetManifest['zone']): WidgetManifest[] {
        return this.getAll().filter(m => m.zone === zone);
    }
}
