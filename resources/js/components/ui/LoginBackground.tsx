export default function LoginBackground() {
    return (
        <div className="fixed inset-0 overflow-hidden bg-[#F8FAFC]">

            {/* Base Gradient */}
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,#CFF8FF_0%,transparent_38%),radial-gradient(circle_at_bottom_left,#D9EAFF_0%,transparent_42%),linear-gradient(to_bottom,#FFFFFF,#F8FAFC)]" />

            {/* Left Glass Panel */}
            <div className="absolute -left-40 bottom-[-140px] h-[900px] w-[520px] rotate-[-12deg] rounded-[120px] border border-white/60 bg-white/20 backdrop-blur-3xl shadow-[0_20px_80px_rgba(15,23,42,.08)]" />

            {/* Right Glass Panel */}
            <div className="absolute -right-24 top-[-100px] h-[900px] w-[500px] rotate-[8deg] rounded-[120px] border border-cyan-200/50 bg-cyan-100/20 backdrop-blur-3xl shadow-[0_20px_80px_rgba(15,23,42,.08)]" />

            {/* Aurora Top */}
            <div className="absolute -right-56 -top-56 h-[700px] w-[700px] rounded-full bg-cyan-400/60 blur-[140px] animate-[float_18s_ease-in-out_infinite]" />

            {/* Aurora Bottom */}
            <div className="absolute -bottom-56 -left-56 h-[650px] w-[650px] rounded-full bg-blue-500/50 blur-[140px] animate-[float_20s_ease-in-out_infinite]" />

            {/* Center Glow */}
            <div className="absolute left-1/2 top-1/2 h-[850px] w-[850px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(circle,rgba(255,255,255,.95),rgba(255,255,255,.45),transparent)] blur-[90px]" />

            {/* Large Curved Line */}
            <div className="absolute -bottom-72 -left-72 h-[900px] w-[1800px] rotate-[-8deg] rounded-full border border-white/80" />

            {/* Second Curved Line */}
            <div className="absolute -right-60 -top-72 h-[900px] w-[1700px] rotate-[10deg] rounded-full border border-white/80" />

            {/* Soft Highlight */}
            <div className="absolute left-1/2 top-1/2 h-[1200px] w-[1200px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-white/60 blur-[180px]" />


        </div>
    );
}
