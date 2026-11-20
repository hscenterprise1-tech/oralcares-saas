import { useEffect, useMemo, useState } from "react";
import { Link, router } from "@inertiajs/react";
import {
    Activity,
    CalendarRange,
    ClipboardCheck,
    Dumbbell,
    FlaskConical,
    Gauge,
    HeartPulse,
    ImageIcon,
    LineChart,
    ListChecks,
    Microscope,
    Package,
    Smile,
    Stethoscope,
    Target,
} from "lucide-react";
import { cn } from "@/lib/utils";
import {
    CompleteDialog,
    ReasonDialog,
    normalize,
    type DentalPayload,
    type PlanItem,
} from "../../DentalSection";
import { LabSteps, SpecialtyPanels } from "../../DentalSpecialtyPanels";
import { SellPackage, type Physio } from "../../PhysioSection";
import type { Patient360Props } from "../../types";
import { useEndpoint } from "../store";
import {
    BTN_SM,
    COLUMN,
    Card,
    Empty,
    Figure,
    Pager,
    Pending,
    Pill,
    day,
    humanize,
    money,
    type Paged,
    type Tone,
} from "../ui";

type EncounterRow = {
    id: number;
    number: string | null;
    date: string | null;
    practitioner: string | null;
    branch: string | null;
    status: string;
    void_reason: string | null;
    complaint: string | null;
    diagnoses: { description: string; code: string | null; primary: boolean }[];
    counts: { procedures: number; prescriptions: number; orders: number };
    follow_up_on: string | null;
    url: string | null;
};

const ENCOUNTER: Record<string, [string, Tone]> = {
    signed: ["Signed", "mint"],
    draft: ["In draft", "amber"],
    closed: ["Closed", "slate"],
    voided: ["Voided", "rose"],
};

function Consultations({ endpoint }: { endpoint: string }) {
    const [page, setPage] = useState(1);
    const state = useEndpoint<Paged<EncounterRow>>(
        endpoint,
        page > 1 ? { page } : undefined,
    );

    return (
        <Card
            title="Consultations"
            caption="Diagnoses and what was done, consultation by consultation"
            icon={Stethoscope}
            tone="blue"
        >
            {!state.data ? (
                <Pending error={state.error} onRetry={state.reload} />
            ) : state.data.data.length === 0 ? (
                <Empty>No consultations yet.</Empty>
            ) : (
                <ul className="grid gap-3 lg:grid-cols-2">
                    {state.data.data.map((row) => {
                        const [label, tone] = ENCOUNTER[row.status] ?? [
                            humanize(row.status),
                            "slate" as Tone,
                        ];

                        return (
                            <li
                                key={row.id}
                                className="flex flex-col rounded-2xl border border-[#EDF1F7] bg-[#FBFCFE] p-4"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="text-[14px] font-semibold text-cx-nav-ink">
                                            {day(row.date) ?? "Undated"}
                                        </p>
                                        <p className="text-[12px] text-cx-text-2">
                                            {[row.practitioner, row.branch]
                                                .filter(Boolean)
                                                .join(" · ") || "—"}
                                        </p>
                                    </div>
                                    <Pill tone={tone}>{label}</Pill>
                                </div>
                                <div className="mt-3 space-y-2 text-[12.5px]">
                                    <p className="[overflow-wrap:anywhere]">
                                        <span className="text-cx-text-3">
                                            Complaint{" "}
                                        </span>
                                        <span className="text-cx-text">
                                            {row.complaint ?? "—"}
                                        </span>
                                    </p>
                                    <div>
                                        <span className="text-cx-text-3">
                                            Diagnosis{" "}
                                        </span>
                                        {row.diagnoses.length === 0 ? (
                                            <span className="text-cx-text">
                                                —
                                            </span>
                                        ) : (
                                            <span className="mt-1 flex flex-wrap gap-1.5">
                                                {row.diagnoses.map(
                                                    (diagnosis, index) => (
                                                        <Pill
                                                            key={index}
                                                            tone={
                                                                diagnosis.primary
                                                                    ? "blue"
                                                                    : "slate"
                                                            }
                                                        >
                                                            {
                                                                diagnosis.description
                                                            }
                                                            {diagnosis.code
                                                                ? ` · ${diagnosis.code}`
                                                                : ""}
                                                        </Pill>
                                                    ),
                                                )}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <div className="mt-auto pt-3">
                                    <dl className="grid grid-cols-3 gap-2 text-center">
                                        {(
                                            [
                                                [
                                                    "Procedures",
                                                    row.counts.procedures,
                                                ],
                                                [
                                                    "Prescriptions",
                                                    row.counts.prescriptions,
                                                ],
                                                [
                                                    "Investigations",
                                                    row.counts.orders,
                                                ],
                                            ] as const
                                        ).map(([name, count]) => (
                                            <div
                                                key={name}
                                                className="rounded-xl bg-white px-1 py-1.5 ring-1 ring-[#EDF1F7]"
                                            >
                                                <dd className="text-[15px] font-semibold tabular-nums text-cx-nav-ink">
                                                    {count}
                                                </dd>
                                                <dt className="truncate text-[10.5px] text-cx-text-3">
                                                    {name}
                                                </dt>
                                            </div>
                                        ))}
                                    </dl>
                                    {(row.follow_up_on || row.void_reason) && (
                                        <p className="mt-2 text-[12px] text-cx-text-2">
                                            {row.follow_up_on &&
                                                `Follow-up ${day(row.follow_up_on)}`}
                                            {row.void_reason && (
                                                <span className="block text-cx-rose-ink">
                                                    Voided: {row.void_reason}
                                                </span>
                                            )}
                                        </p>
                                    )}
                                    {row.url && (
                                        <Link
                                            href={row.url}
                                            className={cn(BTN_SM, "mt-3")}
                                        >
                                            Open consultation
                                        </Link>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
            {state.data && (
                <Pager
                    page={state.data.meta.page}
                    last={state.data.meta.last_page}
                    onPage={setPage}
                    busy={state.loading}
                />
            )}
        </Card>
    );
}

/* ------------------------------------------------------------------ dental */

const UPPER = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
const LOWER = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];
const PLAN_TONE: Record<string, Tone> = {
    proposed: "slate",
    accepted: "blue",
    active: "violet",
    paused: "amber",
    completed: "mint",
    declined: "slate",
    cancelled: "slate",
};

function DentalView({ endpoint }: { endpoint: string }) {
    const state = useEndpoint<DentalPayload>(endpoint);
    const data = useMemo(
        () => (state.data ? normalize(state.data) : null),
        [state.data],
    );
    const [tooth, setTooth] = useState<number | null>(null);
    const [reasonFor, setReasonFor] = useState<{
        url: string;
        label: string;
    } | null>(null);
    const [completing, setCompleting] = useState<PlanItem | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const act = (url: string, body: Record<string, unknown> = {}) =>
        router.post(url, body as never, {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) =>
                setProblem(
                    Object.values(errors as Record<string, string>)[0] ??
                        "Not done.",
                ),
            onSuccess: () => {
                setProblem(null);
                setReasonFor(null);
                setCompleting(null);
                void state.reload();
            },
        });

    if (!data) {
        return (
            <Card title="Dental" icon={Smile} tone="blue">
                <Pending error={state.error} onRetry={state.reload} />
            </Card>
        );
    }
    const styleOf = (number: number) => {
        const key = data.states[String(number)];

        return key ? data.state_styles[key] : undefined;
    };
    const used = Array.from(new Set(Object.values(data.states)));
    const record = data.teeth?.find((row) => row.tooth === tooth);
    const toothItems = (data.plans ?? []).flatMap((plan) =>
        plan.items
            .filter((item) => String(item.tooth) === String(tooth))
            .map((item) => ({ ...item, plan: plan.title })),
    );
    const toothImages = (data.images ?? []).filter(
        (image) => tooth !== null && image.teeth.includes(tooth),
    );
    const toothButton = (number: number) => {
        const style = styleOf(number);

        return (
            <button
                key={number}
                type="button"
                onClick={() => setTooth(tooth === number ? null : number)}
                aria-pressed={tooth === number}
                aria-label={`Tooth ${number}${style ? `: ${style.label}` : ""}`}
                className={cn(
                    "flex h-12 flex-col items-center justify-center rounded-[12px] border-2 text-[12px] font-bold tabular-nums transition-transform hover:-translate-y-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/40",
                    tooth === number && "ring-2 ring-[#1769AA]/50",
                )}
                style={{
                    background: style?.fill ?? "#FFFFFF",
                    borderColor: style?.stroke ?? "#E3E8EF",
                    color: "#1D2E4A",
                }}
            >
                <svg
                    viewBox="0 0 24 24"
                    className="h-4 w-4 opacity-70"
                    aria-hidden="true"
                >
                    <path
                        d="M7 3c-2.2 0-4 1.8-4 4.5 0 3 1.2 4.6 2 7.5.6 2.4 1 6 2.6 6 1.5 0 1.4-3.2 2.4-4.8.3-.5.7-.7 2-.7s1.7.2 2 .7c1 1.6.9 4.8 2.4 4.8 1.6 0 2-3.6 2.6-6 .8-2.9 2-4.5 2-7.5C21 4.8 19.2 3 17 3c-2 0-3 1-5 1S9 3 7 3z"
                        fill="currentColor"
                    />
                </svg>
                {number}
            </button>
        );
    };

    return (
        <div className="space-y-5">
            {problem && (
                <p
                    role="alert"
                    className="rounded-xl bg-cx-rose-bg px-4 py-2.5 text-[13px] font-semibold text-cx-rose-ink"
                >
                    {problem}
                </p>
            )}
            {data.summary && (
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Figure
                        icon={Smile}
                        tone="blue"
                        label="Teeth charted"
                        value={data.summary.charted}
                        detail={`DMFT ${data.summary.dmft.total}`}
                    />
                    <Figure
                        icon={Microscope}
                        tone="rose"
                        label="Decayed · Missing · Filled"
                        value={`${data.summary.dmft.decayed} · ${data.summary.dmft.missing} · ${data.summary.dmft.filled}`}
                    />
                    <Figure
                        icon={ListChecks}
                        tone="violet"
                        label="Open plans"
                        value={data.summary.open_plans}
                        detail={`${data.summary.items_to_do} items to do`}
                    />
                    <Figure
                        icon={ClipboardCheck}
                        tone="mint"
                        label="Still to do"
                        value={
                            data.summary.estimate_to_do !== null
                                ? money(data.summary.estimate_to_do)
                                : data.summary.items_to_do
                        }
                        detail={
                            data.summary.estimate_to_do !== null
                                ? "Estimate of the remaining work"
                                : "Planned items"
                        }
                    />
                </div>
            )}

            <div className="grid items-stretch gap-5 xl:grid-cols-12">
                <div className={cn(COLUMN, "xl:col-span-8")}>
                    <Card
                        title="Tooth chart (FDI)"
                        caption="Choose a tooth to see its record"
                        icon={Smile}
                        tone="blue"
                        action={
                            data.open_consultation ? (
                                <Link
                                    href={data.open_consultation.url}
                                    className={BTN_SM}
                                >
                                    Chart in consultation{" "}
                                    {data.open_consultation.number}
                                </Link>
                            ) : undefined
                        }
                    >
                        <div className="overflow-x-auto pb-1">
                            <div className="min-w-[560px] space-y-2">
                                <p className="text-[11px] font-semibold uppercase tracking-wide text-cx-text-3">
                                    Upper · right → left
                                </p>
                                <div className="grid grid-cols-[repeat(16,minmax(0,1fr))] gap-1">
                                    {UPPER.map(toothButton)}
                                </div>
                                <div className="my-1 border-t border-dashed border-cx-line" />
                                <div className="grid grid-cols-[repeat(16,minmax(0,1fr))] gap-1">
                                    {LOWER.map(toothButton)}
                                </div>
                                <p className="text-[11px] font-semibold uppercase tracking-wide text-cx-text-3">
                                    Lower · right → left
                                </p>
                            </div>
                        </div>
                        {used.length > 0 && (
                            <ul
                                className="mt-3 flex flex-wrap gap-x-3 gap-y-1.5 text-[12px] text-cx-text-2"
                                aria-label="Legend"
                            >
                                {used.map((key) => (
                                    <li
                                        key={key}
                                        className="flex items-center gap-1.5"
                                    >
                                        <span
                                            className="h-3 w-3 rounded border-2"
                                            style={{
                                                background:
                                                    data.state_styles[key]
                                                        ?.fill,
                                                borderColor:
                                                    data.state_styles[key]
                                                        ?.stroke,
                                            }}
                                            aria-hidden="true"
                                        />
                                        {data.state_styles[key]?.label ??
                                            humanize(key)}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {tooth !== null && (
                            <div
                                className="mt-4 rounded-2xl bg-cx-blue-bg/40 p-4"
                                aria-live="polite"
                            >
                                <p className="text-[14px] font-semibold text-cx-nav-ink">
                                    Tooth {tooth}
                                </p>
                                <p className="text-[12.5px] text-cx-text-2">
                                    {record
                                        ? [
                                              humanize(record.status),
                                              record.surfaces.length
                                                  ? `surfaces ${record.surfaces.join("")}`
                                                  : null,
                                              record.notes,
                                              record.updated_at
                                                  ? `updated ${day(record.updated_at)}`
                                                  : null,
                                          ]
                                              .filter(Boolean)
                                              .join(" · ")
                                        : "Nothing recorded for this tooth."}
                                </p>
                                {toothItems.length > 0 && (
                                    <ul className="mt-2 space-y-1 text-[12.5px]">
                                        {toothItems.map((item) => (
                                            <li
                                                key={item.id}
                                                className="flex flex-wrap justify-between gap-2"
                                            >
                                                <span>
                                                    {item.description}{" "}
                                                    <span className="text-cx-text-3">
                                                        · {item.plan}
                                                    </span>
                                                </span>
                                                <Pill
                                                    tone={
                                                        item.status ===
                                                        "completed"
                                                            ? "mint"
                                                            : "slate"
                                                    }
                                                >
                                                    {humanize(item.status)}
                                                </Pill>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {toothImages.length > 0 && (
                                    <p className="mt-2 flex flex-wrap gap-1.5">
                                        {toothImages.map((image) =>
                                            image.preview_url ? (
                                                <a
                                                    key={image.id}
                                                    href={image.preview_url}
                                                    target="_blank"
                                                    rel="noopener"
                                                    className={BTN_SM}
                                                >
                                                    <ImageIcon
                                                        className="h-3.5 w-3.5"
                                                        aria-hidden="true"
                                                    />{" "}
                                                    {image.name}
                                                </a>
                                            ) : null,
                                        )}
                                    </p>
                                )}
                            </div>
                        )}
                    </Card>

                    {(data.plans ?? []).length > 0 ||
                    data.view === "clinical" ? (
                        <Card
                            title="Treatment plans"
                            icon={ListChecks}
                            tone="violet"
                        >
                            {(data.plans ?? []).length === 0 ? (
                                <Empty>
                                    No treatment plan yet. Plans are proposed in
                                    a consultation.
                                </Empty>
                            ) : (
                                <ul className="space-y-3">
                                    {(data.plans ?? []).map((plan) => {
                                        const percent = plan.total
                                            ? Math.round(
                                                  (plan.done / plan.total) *
                                                      100,
                                              )
                                            : 0;

                                        return (
                                            <li
                                                key={plan.id}
                                                className="rounded-2xl border border-cx-line/70 p-3.5"
                                            >
                                                <div className="flex flex-wrap items-start justify-between gap-2">
                                                    <div className="min-w-0">
                                                        <p className="flex flex-wrap items-center gap-2 text-[14px] font-semibold text-cx-nav-ink">
                                                            {plan.title}
                                                            <Pill
                                                                tone={
                                                                    PLAN_TONE[
                                                                        plan
                                                                            .status
                                                                    ] ?? "slate"
                                                                }
                                                            >
                                                                {humanize(
                                                                    plan.status,
                                                                )}
                                                            </Pill>
                                                        </p>
                                                        <p className="text-[12px] text-cx-text-2">
                                                            {[
                                                                plan.author,
                                                                day(
                                                                    plan.created_at,
                                                                ),
                                                                plan.estimate
                                                                    ? `Estimate ${money(plan.estimate.total)}`
                                                                    : null,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(" · ")}
                                                        </p>
                                                    </div>
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {plan.actions?.estimate_url && (
                                                            <a
                                                                href={plan.actions.estimate_url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className={BTN_SM}
                                                            >
                                                                Estimate
                                                            </a>
                                                        )}
                                                        {plan.actions
                                                            ?.accept_url && (
                                                            <button
                                                                type="button"
                                                                className={
                                                                    BTN_SM
                                                                }
                                                                onClick={() =>
                                                                    act(
                                                                        plan
                                                                            .actions!
                                                                            .accept_url!,
                                                                    )
                                                                }
                                                            >
                                                                Accept
                                                            </button>
                                                        )}
                                                        {plan.actions
                                                            ?.resume_url && (
                                                            <button
                                                                type="button"
                                                                className={
                                                                    BTN_SM
                                                                }
                                                                onClick={() =>
                                                                    act(
                                                                        plan
                                                                            .actions!
                                                                            .resume_url!,
                                                                    )
                                                                }
                                                            >
                                                                Resume
                                                            </button>
                                                        )}
                                                        {plan.actions
                                                            ?.pause_url && (
                                                            <button
                                                                type="button"
                                                                className={
                                                                    BTN_SM
                                                                }
                                                                onClick={() =>
                                                                    setReasonFor(
                                                                        {
                                                                            url: plan
                                                                                .actions!
                                                                                .pause_url!,
                                                                            label: "Pause plan",
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Pause
                                                            </button>
                                                        )}
                                                        {plan.actions
                                                            ?.decline_url && (
                                                            <button
                                                                type="button"
                                                                className={
                                                                    BTN_SM
                                                                }
                                                                onClick={() =>
                                                                    setReasonFor(
                                                                        {
                                                                            url: plan
                                                                                .actions!
                                                                                .decline_url!,
                                                                            label: "Decline plan",
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Decline
                                                            </button>
                                                        )}
                                                        {plan.actions
                                                            ?.cancel_url && (
                                                            <button
                                                                type="button"
                                                                className={
                                                                    BTN_SM
                                                                }
                                                                onClick={() =>
                                                                    setReasonFor(
                                                                        {
                                                                            url: plan
                                                                                .actions!
                                                                                .cancel_url!,
                                                                            label: "Cancel plan",
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Cancel
                                                            </button>
                                                        )}
                                                    </div>
                                                </div>
                                                <span className="mt-2 block h-1.5 overflow-hidden rounded-full bg-cx-slate-bg">
                                                    <span
                                                        className="block h-full rounded-full bg-gradient-to-r from-[#9B7BF2] to-[#6B48DE]"
                                                        style={{
                                                            width: `${percent}%`,
                                                        }}
                                                    />
                                                </span>
                                                <p className="mt-1 text-[11.5px] text-cx-text-3">
                                                    {plan.done} of {plan.total}{" "}
                                                    done
                                                </p>
                                                <ul className="mt-2 divide-y divide-cx-line">
                                                    {plan.items.map((item) => (
                                                        <li
                                                            key={item.id}
                                                            className="flex flex-wrap items-center justify-between gap-2 py-2 text-[12.5px]"
                                                        >
                                                            <span className="min-w-0">
                                                                <span className="font-semibold text-cx-nav-ink">
                                                                    {
                                                                        item.description
                                                                    }
                                                                </span>
                                                                <span className="text-cx-text-2">
                                                                    {item.tooth
                                                                        ? ` · tooth ${item.tooth}${item.surfaces.length ? ` (${item.surfaces.join("")})` : ""}`
                                                                        : ""}
                                                                </span>
                                                            </span>
                                                            <span className="flex items-center gap-1.5">
                                                                {item.price !==
                                                                    null && (
                                                                    <span className="tabular-nums text-cx-text-2">
                                                                        {money(
                                                                            item.price,
                                                                        )}
                                                                    </span>
                                                                )}
                                                                <Pill
                                                                    tone={
                                                                        item.status ===
                                                                        "completed"
                                                                            ? "mint"
                                                                            : item.status ===
                                                                                "aborted"
                                                                              ? "slate"
                                                                              : "blue"
                                                                    }
                                                                >
                                                                    {humanize(
                                                                        item.status,
                                                                    )}
                                                                </Pill>
                                                                {item.complete_url &&
                                                                    item.status !==
                                                                        "completed" && (
                                                                        <button
                                                                            type="button"
                                                                            className={
                                                                                BTN_SM
                                                                            }
                                                                            onClick={() =>
                                                                                setCompleting(
                                                                                    item,
                                                                                )
                                                                            }
                                                                        >
                                                                            Mark
                                                                            done
                                                                        </button>
                                                                    )}
                                                            </span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </Card>
                    ) : null}

                    {(data.procedures ?? []).length > 0 && (
                        <Card
                            title="Procedures done"
                            icon={ClipboardCheck}
                            tone="mint"
                        >
                            <ul className="divide-y divide-cx-line text-[12.5px]">
                                {(data.procedures ?? []).map(
                                    (procedure, index) => (
                                        <li
                                            key={index}
                                            className="flex flex-wrap justify-between gap-2 py-2"
                                        >
                                            <span>
                                                <span className="font-semibold text-cx-nav-ink">
                                                    {procedure.name}
                                                </span>
                                                <span className="text-cx-text-2">
                                                    {procedure.tooth
                                                        ? ` · tooth ${procedure.tooth}`
                                                        : ""}
                                                    {procedure.consultation
                                                        ? ` · ${procedure.consultation}`
                                                        : ""}
                                                </span>
                                            </span>
                                            <span className="flex items-center gap-1.5 text-cx-text-2">
                                                {day(procedure.date)}
                                                {procedure.billed && (
                                                    <Pill tone="mint">
                                                        Billed
                                                    </Pill>
                                                )}
                                            </span>
                                        </li>
                                    ),
                                )}
                            </ul>
                        </Card>
                    )}
                </div>

                <div className={cn(COLUMN, "xl:col-span-4")}>
                    {(data.ongoing ?? []).length > 0 && (
                        <Card title="Under way" icon={Activity} tone="amber">
                            <ul className="space-y-2 text-[12.5px]">
                                {(data.ongoing ?? []).map((row) => (
                                    <li
                                        key={row.key}
                                        className="rounded-xl bg-cx-amber-bg/40 px-3 py-2"
                                    >
                                        <span className="font-semibold text-cx-nav-ink">
                                            {row.procedure}
                                        </span>
                                        {row.tooth && (
                                            <span className="text-cx-text-2">
                                                {" "}
                                                · tooth {row.tooth}
                                            </span>
                                        )}
                                        {row.next_step && (
                                            <span className="block text-cx-text-2">
                                                Next: {row.next_step}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                    {((data.imaging ?? []).length > 0 ||
                        (data.images ?? []).length > 0) && (
                        <Card title="Imaging" icon={ImageIcon} tone="blue">
                            <ul className="space-y-2 text-[12.5px]">
                                {(data.images ?? []).map((image) => (
                                    <li
                                        key={`i-${image.id}`}
                                        className="flex justify-between gap-2"
                                    >
                                        <span className="min-w-0 truncate font-semibold text-cx-nav-ink">
                                            {image.name}
                                        </span>
                                        {image.preview_url && (
                                            <a
                                                href={image.preview_url}
                                                target="_blank"
                                                rel="noopener"
                                                className="shrink-0 font-semibold text-cx-primary hover:underline"
                                            >
                                                View
                                            </a>
                                        )}
                                    </li>
                                ))}
                                {(data.imaging ?? []).map((row, index) => (
                                    <li key={`r-${index}`}>
                                        <span className="font-semibold text-cx-nav-ink">
                                            {row.name}
                                        </span>
                                        {row.is_abnormal && (
                                            <Pill
                                                tone="rose"
                                                className="ml-1.5"
                                            >
                                                Abnormal
                                            </Pill>
                                        )}
                                        <span className="block text-cx-text-2">
                                            {[
                                                humanize(row.status),
                                                day(row.date),
                                                row.summary,
                                            ]
                                                .filter(Boolean)
                                                .join(" · ")}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                    <SpecialtyPanels
                        endodontic={data.endodontic}
                        implants={data.implants}
                        orthodontic={data.orthodontic ?? null}
                        visitUrl={data.open_consultation?.url ?? null}
                    />
                    {(data.lab ?? []).length > 0 && (
                        <Card
                            title="Lab work"
                            icon={FlaskConical}
                            tone="violet"
                        >
                            <ul className="space-y-2 text-[12.5px]">
                                {(data.lab ?? []).map((row, index) => (
                                    <li
                                        key={row.id ?? index}
                                        className="rounded-xl bg-cx-violet-bg/40 px-3 py-2"
                                    >
                                        <span className="font-semibold text-cx-nav-ink">
                                            {row.restoration ?? "Lab case"}
                                        </span>
                                        {row.status && (
                                            <Pill
                                                tone="violet"
                                                className="ml-1.5"
                                            >
                                                {row.status_label ?? humanize(row.status)}
                                            </Pill>
                                        )}
                                        <span className="block text-cx-text-2">
                                            {[
                                                row.laboratory ?? row.lab,
                                                row.due
                                                    ? `due ${day(row.due)}`
                                                    : null,
                                                row.teeth?.length
                                                    ? `teeth ${row.teeth.join(", ")}`
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(" · ")}
                                        </span>
                                        <LabSteps
                                            order={row}
                                            onChanged={() => void state.reload()}
                                        />
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                    {data.periodontal?.latest && (
                        <Card title="Gum health" icon={HeartPulse} tone="rose">
                            <p className="text-[13px] text-cx-nav-ink">
                                {[
                                    data.periodontal.latest.stage
                                        ? `Stage ${data.periodontal.latest.stage}`
                                        : null,
                                    data.periodontal.latest.grade
                                        ? `Grade ${data.periodontal.latest.grade}`
                                        : null,
                                    data.periodontal.latest.extent
                                        ? humanize(
                                              data.periodontal.latest.extent,
                                          )
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(" · ") || "Charted"}
                            </p>
                            <p className="text-[12px] text-cx-text-2">
                                Recorded{" "}
                                {day(
                                    data.periodontal.latest.recorded_on ?? null,
                                )}
                            </p>
                        </Card>
                    )}
                    {(data.recall?.open ?? []).length > 0 && (
                        <Card title="Recalls" icon={CalendarRange} tone="teal">
                            <ul className="space-y-1.5 text-[12.5px]">
                                {(data.recall?.open ?? []).map((row) => (
                                    <li
                                        key={row.id}
                                        className="flex justify-between gap-2"
                                    >
                                        <span>{row.reason ?? "Recall"}</span>
                                        <span className="text-cx-text-2">
                                            {day(row.due_on)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </div>
            </div>

            {reasonFor && (
                <ReasonDialog
                    title={reasonFor.label}
                    onClose={() => setReasonFor(null)}
                    onConfirm={(reason) => act(reasonFor.url, { reason })}
                />
            )}
            {completing?.complete_url && data.conditions && (
                <CompleteDialog
                    item={completing}
                    conditions={data.conditions}
                    onClose={() => setCompleting(null)}
                    onConfirm={(body) => act(completing.complete_url!, body)}
                />
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ physio */

function PhysioView({ endpoint }: { endpoint: string }) {
    const state = useEndpoint<Physio>(endpoint);
    const data = state.data;

    if (!data) {
        return (
            <Card title="Physiotherapy" icon={Activity} tone="violet">
                <Pending error={state.error} onRetry={state.reload} />
            </Card>
        );
    }
    const plan = data.plan;
    const pain = data.progress.pain
        .filter(
            (point) => point.pain_before !== null || point.pain_after !== null,
        )
        .slice(-12);

    return (
        <div className="space-y-5">
            <div className="grid items-stretch gap-5 xl:grid-cols-12">
                <div className={cn(COLUMN, "xl:col-span-8")}>
                    <Card
                        title="Plan of care"
                        icon={Target}
                        tone="violet"
                        action={
                            data.open_consultation ? (
                                <Link
                                    href={data.open_consultation.url}
                                    className={BTN_SM}
                                >
                                    Open consultation{" "}
                                    {data.open_consultation.number}
                                </Link>
                            ) : undefined
                        }
                    >
                        {!plan ? (
                            <Empty>
                                No active plan of care. It is set in a
                                consultation.
                            </Empty>
                        ) : (
                            <>
                                <p className="text-[15px] font-semibold text-cx-nav-ink">
                                    {plan.title}
                                </p>
                                <p className="text-[12.5px] text-cx-text-2">
                                    {[
                                        plan.frequency,
                                        plan.starts_on
                                            ? `from ${day(plan.starts_on)}`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(" · ")}
                                </p>
                                {plan.planned !== null && (
                                    <>
                                        <span className="mt-3 block h-2 overflow-hidden rounded-full bg-cx-slate-bg">
                                            <span
                                                className="block h-full rounded-full bg-gradient-to-r from-[#9B7BF2] to-[#6B48DE]"
                                                style={{
                                                    width: `${Math.min(100, Math.round((plan.done / Math.max(plan.planned, 1)) * 100))}%`,
                                                }}
                                            />
                                        </span>
                                        <p className="mt-1 text-[12px] text-cx-text-2">
                                            {plan.done} of {plan.planned}{" "}
                                            sessions done
                                        </p>
                                    </>
                                )}
                                {plan.goals && (
                                    <p className="mt-3 rounded-xl bg-cx-violet-bg/40 px-3 py-2 text-[13px] text-cx-text">
                                        <b className="text-cx-violet-ink">
                                            Goals:
                                        </b>{" "}
                                        {plan.goals}
                                    </p>
                                )}
                                {plan.exercises.length > 0 && (
                                    <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                                        {plan.exercises.map((exercise) => (
                                            <li
                                                key={exercise.id}
                                                className="rounded-xl border border-cx-line/70 px-3 py-2 text-[12.5px]"
                                            >
                                                <p className="flex items-center justify-between gap-2 font-semibold text-cx-nav-ink">
                                                    <span className="flex items-center gap-1.5">
                                                        <Dumbbell
                                                            className="h-3.5 w-3.5 text-cx-violet-ink"
                                                            aria-hidden="true"
                                                        />
                                                        {exercise.name}
                                                    </span>
                                                    {exercise.is_home && (
                                                        <Pill tone="teal">
                                                            Home
                                                        </Pill>
                                                    )}
                                                </p>
                                                <p className="text-cx-text-2">
                                                    {[
                                                        exercise.sets
                                                            ? `${exercise.sets} sets`
                                                            : null,
                                                        exercise.reps
                                                            ? `${exercise.reps} reps`
                                                            : null,
                                                        exercise.hold_seconds
                                                            ? `hold ${exercise.hold_seconds}s`
                                                            : null,
                                                        exercise.frequency,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(" · ")}
                                                </p>
                                                {exercise.instructions && (
                                                    <p className="text-cx-text-3">
                                                        {exercise.instructions}
                                                    </p>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </>
                        )}
                    </Card>

                    <Card
                        title="Sessions"
                        caption="SOAP notes, pain before and after"
                        icon={ClipboardCheck}
                        tone="blue"
                    >
                        {data.sessions.length === 0 ? (
                            <Empty>No sessions yet.</Empty>
                        ) : (
                            <ol className="space-y-3">
                                {data.sessions.map((session) => (
                                    <li
                                        key={session.id}
                                        className="rounded-2xl border border-cx-line/70 p-3.5 text-[12.5px]"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <p className="text-[13.5px] font-semibold text-cx-nav-ink">
                                                Session {session.number ?? "—"}{" "}
                                                · {day(session.date)}
                                            </p>
                                            <span className="flex items-center gap-1.5">
                                                {(session.pain_before !==
                                                    null ||
                                                    session.pain_after !==
                                                        null) && (
                                                    <Pill tone="rose">
                                                        Pain{" "}
                                                        {session.pain_before ??
                                                            "–"}{" "}
                                                        →{" "}
                                                        {session.pain_after ??
                                                            "–"}
                                                    </Pill>
                                                )}
                                                <Pill
                                                    tone={
                                                        session.signed
                                                            ? "mint"
                                                            : "amber"
                                                    }
                                                >
                                                    {session.signed
                                                        ? "Signed"
                                                        : "Draft"}
                                                </Pill>
                                            </span>
                                        </div>
                                        <p className="text-cx-text-2">
                                            {[
                                                session.therapist,
                                                session.package
                                                    ? `from ${session.package}`
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(" · ")}
                                        </p>
                                        <dl className="mt-2 grid gap-x-4 gap-y-1 sm:grid-cols-2">
                                            {(
                                                [
                                                    ["S", session.subjective],
                                                    ["O", session.objective],
                                                    ["A", session.assessment],
                                                    ["P", session.plan],
                                                ] as const
                                            )
                                                .filter(([, text]) => text)
                                                .map(([letter, text]) => (
                                                    <div
                                                        key={letter}
                                                        className="flex gap-2"
                                                    >
                                                        <dt className="font-bold text-cx-blue-ink">
                                                            {letter}
                                                        </dt>
                                                        <dd className="text-cx-text [overflow-wrap:anywhere]">
                                                            {text}
                                                        </dd>
                                                    </div>
                                                ))}
                                        </dl>
                                        {session.treatment_performed && (
                                            <p className="mt-1 text-cx-text-2">
                                                Treatment:{" "}
                                                {session.treatment_performed}
                                            </p>
                                        )}
                                        <Link
                                            href={session.consultation_url}
                                            className="mt-2 inline-block font-semibold text-cx-primary hover:underline"
                                        >
                                            Open
                                        </Link>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Card>

                    {data.assessments.length > 0 && (
                        <Card title="Assessments" icon={Gauge} tone="teal">
                            <ul className="space-y-3">
                                {data.assessments.map((assessment) => (
                                    <li
                                        key={assessment.id}
                                        className="rounded-2xl bg-cx-teal-bg/30 p-3.5 text-[12.5px]"
                                    >
                                        <p className="flex flex-wrap items-center gap-2 text-[13.5px] font-semibold text-cx-nav-ink">
                                            {humanize(assessment.kind)} ·{" "}
                                            {day(assessment.date)}
                                            {(assessment.pain_at_rest !==
                                                null ||
                                                assessment.pain_on_activity !==
                                                    null) && (
                                                <Pill tone="rose">
                                                    Pain rest{" "}
                                                    {assessment.pain_at_rest ??
                                                        "–"}{" "}
                                                    · activity{" "}
                                                    {assessment.pain_on_activity ??
                                                        "–"}
                                                </Pill>
                                            )}
                                        </p>
                                        <p className="text-cx-text-2">
                                            {assessment.by}
                                        </p>
                                        {assessment.presenting_problem && (
                                            <p className="mt-1 text-cx-text">
                                                <b>Problem:</b>{" "}
                                                {assessment.presenting_problem}
                                            </p>
                                        )}
                                        {assessment.diagnosis && (
                                            <p className="text-cx-text">
                                                <b>Diagnosis:</b>{" "}
                                                {assessment.diagnosis}
                                            </p>
                                        )}
                                        {assessment.clinical_impression && (
                                            <p className="text-cx-text-2">
                                                {assessment.clinical_impression}
                                            </p>
                                        )}
                                        {assessment.functional_measures.length >
                                            0 && (
                                            <p className="mt-1.5 flex flex-wrap gap-1.5">
                                                {assessment.functional_measures.map(
                                                    (measure, index) => (
                                                        <Pill
                                                            key={index}
                                                            tone="slate"
                                                        >
                                                            {measure.label}{" "}
                                                            {measure.score ??
                                                                "–"}
                                                            {measure.max
                                                                ? `/${measure.max}`
                                                                : ""}
                                                        </Pill>
                                                    ),
                                                )}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </div>

                <div className={cn(COLUMN, "xl:col-span-4")}>
                    <Card
                        title="Packages"
                        icon={Package}
                        tone="violet"
                        action={
                            data.links.sell_package &&
                            data.links.sell_treatment_id ? (
                                <SellPackage
                                    url={data.links.sell_package}
                                    treatmentId={data.links.sell_treatment_id}
                                    planTitle={plan?.title ?? null}
                                    onSold={() => void state.reload()}
                                />
                            ) : undefined
                        }
                    >
                        {data.packages.length === 0 ? (
                            <Empty>No session packages.</Empty>
                        ) : (
                            <ul className="space-y-3">
                                {data.packages.map((item) => (
                                    <li key={item.id}>
                                        <p className="flex justify-between gap-2 text-[12.5px]">
                                            <span className="font-semibold text-cx-nav-ink">
                                                {item.name}
                                            </span>
                                            <span className="tabular-nums text-cx-text-2">
                                                {item.remaining} of {item.total}{" "}
                                                left
                                            </span>
                                        </p>
                                        <span className="mt-1 block h-2 overflow-hidden rounded-full bg-cx-slate-bg">
                                            <span
                                                className={cn(
                                                    "block h-full rounded-full",
                                                    item.expired
                                                        ? "bg-[#94A3B8]"
                                                        : "bg-gradient-to-r from-[#5FD9A6] to-[#12A877]",
                                                )}
                                                style={{
                                                    width: `${Math.round((item.remaining / Math.max(item.total, 1)) * 100)}%`,
                                                }}
                                            />
                                        </span>
                                        <p className="mt-0.5 text-[11.5px] text-cx-text-3">
                                            {[
                                                humanize(item.status),
                                                item.valid_until
                                                    ? `valid until ${day(item.valid_until)}`
                                                    : null,
                                                item.expired ? "expired" : null,
                                            ]
                                                .filter(Boolean)
                                                .join(" · ")}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {pain.length > 0 && (
                        <Card
                            title="Pain trend"
                            caption="Per session, 0–10"
                            icon={LineChart}
                            tone="rose"
                        >
                            <div
                                className="flex h-28 items-end gap-1.5"
                                role="img"
                                aria-label="Pain before and after each session"
                            >
                                {pain.map((point, index) => (
                                    <div
                                        key={index}
                                        className="flex flex-1 items-end gap-0.5"
                                    >
                                        <span
                                            className="flex-1 rounded-t bg-[#F58A94]"
                                            style={{
                                                height: `${((point.pain_before ?? 0) / 10) * 100}%`,
                                            }}
                                            title={`Before ${point.pain_before ?? "–"}`}
                                        />
                                        <span
                                            className="flex-1 rounded-t bg-[#5FD9A6]"
                                            style={{
                                                height: `${((point.pain_after ?? 0) / 10) * 100}%`,
                                            }}
                                            title={`After ${point.pain_after ?? "–"}`}
                                        />
                                    </div>
                                ))}
                            </div>
                            <p className="mt-2 flex gap-3 text-[11.5px] text-cx-text-2">
                                <span className="flex items-center gap-1">
                                    <span
                                        className="h-2 w-2 rounded-sm bg-[#F58A94]"
                                        aria-hidden="true"
                                    />
                                    Before
                                </span>
                                <span className="flex items-center gap-1">
                                    <span
                                        className="h-2 w-2 rounded-sm bg-[#5FD9A6]"
                                        aria-hidden="true"
                                    />
                                    After
                                </span>
                            </p>
                        </Card>
                    )}

                    {data.progress.outcomes.length > 0 && (
                        <Card title="Outcomes" icon={Gauge} tone="teal">
                            <ul className="space-y-2 text-[12.5px]">
                                {data.progress.outcomes.map((outcome) => (
                                    <li key={outcome.measure}>
                                        <span className="font-semibold text-cx-nav-ink">
                                            {outcome.label}
                                        </span>
                                        <span className="block text-cx-text-2">
                                            {outcome.points
                                                .map(
                                                    (point) =>
                                                        `${point.score ?? "–"}${outcome.max ? `/${outcome.max}` : ""}`,
                                                )
                                                .join(" → ")}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}

                    {data.discharges.length > 0 && (
                        <Card
                            title="Discharges"
                            icon={ClipboardCheck}
                            tone="mint"
                        >
                            <ul className="space-y-2 text-[12.5px]">
                                {data.discharges.map((discharge) => (
                                    <li key={discharge.id}>
                                        <span className="font-semibold text-cx-nav-ink">
                                            {discharge.title}
                                        </span>
                                        <span className="block text-cx-text-2">
                                            {[
                                                humanize(discharge.status),
                                                day(discharge.closed_at),
                                                `${discharge.done}${discharge.planned ? ` of ${discharge.planned}` : ""} sessions`,
                                            ]
                                                .filter(Boolean)
                                                .join(" · ")}
                                        </span>
                                        {discharge.note && (
                                            <span className="block text-cx-text-3">
                                                {discharge.note}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </div>
            </div>
        </div>
    );
}

const VIEWS = {
    dental: { label: "Dental", icon: Smile },
    physio: { label: "Physiotherapy", icon: Activity },
    clinical: { label: "Consultations", icon: Stethoscope },
} as const;

/**
 * Clinical (zone spec §2): the consultations, and the clinic's own
 * workspaces — Dental and/or Physiotherapy only where the server includes
 * them. A combined clinic opens the selected case's module, else the
 * member's practice. Medical history lives in the patient header, not here.
 * Patient-wide: a selected case never filters these records.
 */
export function ClinicalZone({
    props,
    sections,
    preferred,
}: {
    props: Patient360Props;
    sections: string[];
    preferred: "dental" | "physio" | null;
}) {
    const options = (["clinical", "dental", "physio"] as const).filter((key) =>
        sections.includes(key),
    );
    const persona =
        props.lens.persona === "physio"
            ? "physio"
            : props.lens.persona === "dental"
              ? "dental"
              : null;
    const first =
        (preferred && options.includes(preferred) ? preferred : null) ??
        (persona && options.includes(persona) ? persona : null) ??
        options[0] ??
        null;
    const [view, setView] = useState<(typeof options)[number] | null>(first);
    useEffect(() => {
        if (preferred && options.includes(preferred)) {
            setView(preferred);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [preferred]);

    return (
        <div className="space-y-5">
            {options.length > 1 && (
                <div
                    role="tablist"
                    aria-label="Clinical views"
                    className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 [scrollbar-width:none]"
                >
                    {options.map((key) => {
                        const look = VIEWS[key];
                        const Icon = look.icon;

                        return (
                            <button
                                key={key}
                                type="button"
                                role="tab"
                                aria-selected={view === key}
                                onClick={() => setView(key)}
                                className={cn(
                                    "inline-flex h-11 shrink-0 items-center gap-2 rounded-2xl px-4 text-[13.5px] font-semibold shadow-[0_1px_2px_rgba(15,23,42,0.04)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35",
                                    view === key
                                        ? "bg-white text-cx-nav-ink ring-2 ring-[#1769AA]/25"
                                        : "bg-white/70 text-cx-text-2 hover:bg-white",
                                )}
                            >
                                <Icon
                                    className="h-4 w-4"
                                    strokeWidth={1.75}
                                    aria-hidden="true"
                                />
                                {look.label}
                            </button>
                        );
                    })}
                </div>
            )}
            {view === "dental" && props.endpoints.dental && (
                <DentalView endpoint={props.endpoints.dental} />
            )}
            {view === "physio" && props.endpoints.physio && (
                <PhysioView endpoint={props.endpoints.physio} />
            )}
            {view === "clinical" && props.endpoints.clinical && (
                <Consultations endpoint={props.endpoints.clinical} />
            )}
        </div>
    );
}
