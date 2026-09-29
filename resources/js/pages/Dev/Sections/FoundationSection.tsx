export function FoundationSection() {
    return (
        <div className="space-y-10">
            <div>
                <h2 className="text-2xl font-bold tracking-tight mb-2">Foundation</h2>
                <p className="text-slate-500">Core design tokens including colors, typography, and spacing.</p>
            </div>

            <div className="space-y-6">
                <h3 className="text-lg font-semibold border-b pb-2">Color Palette</h3>
                <div className="grid grid-cols-2 md:grid-cols-6 gap-4">
                    {/* Primary */}
                    <div className="space-y-2">
                        <div className="h-20 rounded-md bg-ovs-primary shadow-sm border border-slate-200"></div>
                        <p className="text-xs font-mono font-medium text-center">Primary (Blue 600)</p>
                    </div>
                    {/* Secondary */}
                    <div className="space-y-2">
                        <div className="h-20 rounded-md bg-ovs-background shadow-sm border border-slate-200"></div>
                        <p className="text-xs font-mono font-medium text-center">Secondary (Slate 100)</p>
                    </div>
                    {/* Destructive */}
                    <div className="space-y-2">
                        <div className="h-20 rounded-md bg-ovs-semantic-danger shadow-sm border border-slate-200"></div>
                        <p className="text-xs font-mono font-medium text-center">Destructive (Red 500)</p>
                    </div>
                    {/* Muted */}
                    <div className="space-y-2">
                        <div className="h-20 rounded-md bg-ovs-background shadow-sm border border-slate-200"></div>
                        <p className="text-xs font-mono font-medium text-center">Muted (Slate 100)</p>
                    </div>
                </div>
            </div>

            <div className="space-y-6">
                <h3 className="text-lg font-semibold border-b pb-2">Typography (Inter)</h3>
                <div className="space-y-4">
                    <div>
                        <h1 className="text-4xl font-extrabold tracking-tight lg:text-5xl">Heading 1</h1>
                        <p className="text-sm text-slate-500 font-mono mt-1">text-4xl font-extrabold tracking-tight</p>
                    </div>
                    <div>
                        <h2 className="text-3xl font-semibold tracking-tight">Heading 2</h2>
                        <p className="text-sm text-slate-500 font-mono mt-1">text-3xl font-semibold tracking-tight</p>
                    </div>
                    <div>
                        <h3 className="text-2xl font-semibold tracking-tight">Heading 3</h3>
                        <p className="text-sm text-slate-500 font-mono mt-1">text-2xl font-semibold tracking-tight</p>
                    </div>
                    <div>
                        <p className="text-base leading-7 text-slate-900">
                            The quick brown fox jumps over the lazy dog. This is a standard paragraph with comfortable reading line height.
                        </p>
                        <p className="text-sm text-slate-500 font-mono mt-1">text-base leading-7</p>
                    </div>
                </div>
            </div>
        </div>
    );
}
