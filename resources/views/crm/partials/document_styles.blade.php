{{--
    The shared visual foundation for every OralCares commercial document —
    Quotation, Proforma Invoice, Tax Invoice, Receipt. One stylesheet so the
    four read as one document family rather than four separate designs.

    Deliberately table/float-based rather than flexbox or grid: dompdf's most
    reliable, battle-tested layout primitive is the CSS table, and a document
    that has to render identically in a real PDF as well as a browser tab is
    not the place to spend that risk on a fragile layout mode.

    Colours are pulled straight from the CRM's own design tokens
    (resources/css/ovs-tokens.generated.css) and the OralCares logo's navy —
    the same palette a user already sees inside the product, not a new one
    invented for print.
--}}
<style>
    :root {
        --doc-navy: #0A2540;
        --doc-navy-soft: #12345C;
        --doc-teal: #12A0B5;
        --doc-teal-dark: #0A7181;
        --doc-teal-soft: #DDF4F8;
        --doc-ink: #0B0B0F;
        --doc-text: #333A47;
        --doc-text-secondary: #565F73;
        --doc-text-muted: #6B7385;
        --doc-border: #E4E7EC;
        --doc-surface-inset: #F5F6F9;
        --doc-green-text: #0C7A42;
        --doc-green-soft: #DDFBEA;
        --doc-coral-text: #A32E17;
        --doc-coral-soft: #FDE6E0;
        --doc-yellow-text: #8A6A05;
        --doc-yellow-soft: #FEF6D8;
    }

    * { box-sizing: border-box; }

    html, body {
        margin: 0;
        padding: 0;
        background: #fff;
        color: var(--doc-text);
        /*
         * Deliberately not naming "DejaVu Sans" explicitly, even though it
         * is the font actually used: dompdf renders an explicitly-named
         * DejaVu Sans as a Type0/CID composite font (glyph-index encoded,
         * necessary for arbitrary Unicode coverage), which makes every text
         * run unreadable as literal bytes in the PDF content stream — this
         * is what several tests inflate and search for. Leaving the stack as
         * a generic family lets dompdf fall back to its own default font
         * (still DejaVu Sans under the hood) via a simple encoding that
         * keeps plain ASCII text byte-searchable, while ₹ and other non-Latin
         * glyphs still render correctly through dompdf's automatic font
         * substitution for characters the current font can't cover.
         */
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        font-size: 13px;
        line-height: 1.5;
    }

    .doc-page {
        max-width: 800px;
        margin: 0 auto;
        padding: 0 0 36px;
    }

    /* ── Print controls (browser view only) ─────────────────────────────── */
    .doc-print-bar { padding: 18px 28px 0; text-align: right; }
    .doc-print-button {
        background: var(--doc-teal-dark);
        color: #fff;
        border: none;
        padding: 10px 22px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
    }
    @media print {
        .doc-no-print { display: none !important; }
        .doc-page { max-width: none; }
    }

    /* ── Header band ──────────────────────────────────────────────────── */
    .doc-header-band {
        background: var(--doc-navy);
        background-image: linear-gradient(135deg, var(--doc-navy) 0%, var(--doc-navy-soft) 100%);
        padding: 28px 32px 24px;
    }
    .doc-header-accent { height: 5px; background: var(--doc-teal); }
    .doc-header-table { width: 100%; border-collapse: collapse; }
    .doc-header-table td { vertical-align: top; padding: 0; border: none; }
    .doc-brand-mark {
        display: inline-block; width: 30px; height: 30px; line-height: 30px;
        background: var(--doc-teal); color: var(--doc-navy); font-weight: 800; font-size: 16px;
        text-align: center; border-radius: 8px; vertical-align: middle; margin-right: 10px;
    }
    .doc-brand-name { color: #fff; font-size: 21px; font-weight: 800; letter-spacing: -0.3px; vertical-align: middle; }
    .doc-brand-sub { color: #9FC2D6; font-size: 10.5px; margin-top: 3px; letter-spacing: 0.2px; }
    .doc-brand-meta { color: #B9CEDD; font-size: 10px; margin-top: 10px; line-height: 1.6; }
    .doc-doctype {
        color: #fff; font-size: 19px; font-weight: 800; text-align: right;
        letter-spacing: 0.5px; text-transform: uppercase;
    }
    .doc-doc-meta { color: #B9CEDD; font-size: 11px; text-align: right; margin-top: 6px; line-height: 1.7; }
    .doc-doc-meta strong { color: #fff; font-weight: 700; }

    /* ── Body ─────────────────────────────────────────────────────────── */
    .doc-body { padding: 26px 32px 0; }

    .doc-banner {
        border-radius: 10px;
        padding: 13px 16px;
        font-size: 11.5px;
        font-weight: 600;
        margin-bottom: 22px;
        border: 1px solid transparent;
    }
    .doc-banner-info { background: var(--doc-teal-soft); color: var(--doc-teal-dark); border-color: #A7E0E9; }
    .doc-banner-warn { background: var(--doc-yellow-soft); color: var(--doc-yellow-text); border-color: #F4E29B; }
    .doc-banner-good { background: var(--doc-green-soft); color: var(--doc-green-text); border-color: #A7EEC4; }

    /* Two-column info cards (customer / document / status) */
    .doc-card-row { width: 100%; border-collapse: separate; border-spacing: 12px 0; margin: 0 -12px 22px; }
    .doc-card-row td { vertical-align: top; width: 50%; padding: 0; }
    .doc-card {
        background: var(--doc-surface-inset);
        border: 1px solid var(--doc-border);
        border-radius: 12px;
        padding: 16px 18px;
    }
    .doc-eyebrow {
        font-size: 9.5px; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase;
        color: var(--doc-teal-dark); margin: 0 0 8px;
    }
    .doc-card-title { font-size: 15px; font-weight: 700; color: var(--doc-ink); margin-bottom: 4px; }
    .doc-card-line { font-size: 11.5px; color: var(--doc-text-secondary); line-height: 1.65; }

    /* ── Items table ──────────────────────────────────────────────────── */
    .doc-items { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
    .doc-items thead th {
        background: var(--doc-navy); color: #fff; text-align: left;
        font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        padding: 11px 12px;
    }
    .doc-items thead th.num { text-align: right; }
    .doc-items thead th:first-child { border-radius: 8px 0 0 0; }
    .doc-items thead th:last-child { border-radius: 0 8px 0 0; }
    .doc-items tbody td {
        padding: 11px 12px; font-size: 12px; color: var(--doc-text);
        border-bottom: 1px solid var(--doc-border);
    }
    .doc-items tbody tr:nth-child(even) td { background: var(--doc-surface-inset); }
    .doc-items td.num { text-align: right; font-weight: 600; }
    .doc-item-title { font-weight: 600; color: var(--doc-ink); }
    .doc-item-desc { font-size: 10.5px; color: var(--doc-text-muted); margin-top: 2px; }
    .doc-item-tag {
        display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 999px;
        background: var(--doc-teal-soft); color: var(--doc-teal-dark); font-size: 8.5px;
        font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;
    }
    .doc-empty-row td { text-align: center; color: var(--doc-text-muted); padding: 18px; }

    /* ── Totals ───────────────────────────────────────────────────────── */
    .doc-totals-wrap { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    .doc-totals-wrap td { border: none; padding: 0; vertical-align: top; }
    .doc-totals { width: 300px; float: right; }
    .doc-totals-row {
        width: 100%; border-collapse: collapse;
    }
    .doc-totals-row td { padding: 6px 0; font-size: 11.5px; border: none; }
    .doc-totals-row td.label { color: var(--doc-text-secondary); }
    .doc-totals-row td.value { text-align: right; color: var(--doc-ink); font-weight: 600; }
    .doc-totals-row.tax td.label, .doc-totals-row.tax td.value { color: var(--doc-teal-dark); }
    .doc-totals-row.discount td.value { color: var(--doc-coral-text); }
    .doc-grand-total {
        background: var(--doc-navy);
        border-radius: 10px;
        padding: 13px 16px;
        margin-top: 8px;
    }
    .doc-grand-total table { width: 100%; border-collapse: collapse; }
    .doc-grand-total td { border: none; padding: 0; }
    .doc-grand-total .label { color: #B9CEDD; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
    .doc-grand-total .value { color: #fff; font-size: 18px; font-weight: 800; text-align: right; }
    .doc-clear { clear: both; }

    /* ── Payment / terms / notes cards ────────────────────────────────── */
    .doc-section { margin-bottom: 18px; }
    .doc-section-title {
        font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px;
        color: var(--doc-text-muted); margin: 0 0 8px;
        border-bottom: 1px solid var(--doc-border); padding-bottom: 6px;
    }
    .doc-payment-card {
        background: var(--doc-teal-soft); border: 1px solid #A7E0E9; border-radius: 10px;
        padding: 14px 18px; font-size: 11.5px; color: var(--doc-teal-dark);
    }
    .doc-payment-card .row { margin-bottom: 3px; }
    .doc-payment-card .row strong { color: var(--doc-navy); }

    .doc-terms-grid { width: 100%; border-collapse: separate; border-spacing: 0 10px; }
    .doc-terms-grid td { vertical-align: top; padding: 0; border: none; }
    .doc-term-label { font-size: 10px; font-weight: 700; color: var(--doc-navy); width: 150px; padding-right: 14px; }
    .doc-term-value { font-size: 11px; color: var(--doc-text-secondary); line-height: 1.6; }

    .doc-notes-card {
        background: var(--doc-surface-inset); border: 1px solid var(--doc-border); border-radius: 10px;
        padding: 12px 16px; font-size: 11px; color: var(--doc-text-secondary);
    }

    /* ── Footer ───────────────────────────────────────────────────────── */
    .doc-footer {
        margin-top: 28px; padding: 16px 32px 0; border-top: 1px solid var(--doc-border);
        text-align: center; font-size: 10px; color: var(--doc-text-muted);
    }
    .doc-footer strong { color: var(--doc-text-secondary); }
</style>
