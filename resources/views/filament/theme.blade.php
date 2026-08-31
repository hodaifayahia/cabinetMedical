{{-- Visual layer for the Drclick platform console.

     This is loaded through a HEAD_END render hook rather than a compiled
     Filament theme so the panel needs no extra Vite entry point. It only
     builds on Filament's own colour variables (--primary-*, --gray-*), so a
     palette change in the panel provider flows through automatically, and it
     styles nothing that Filament positions or sizes. --}}
<style>
    .fi-body {
        background-image:
            radial-gradient(60rem 32rem at 78% -12%, color-mix(in oklab, var(--primary-500) 12%, transparent), transparent 65%),
            radial-gradient(48rem 28rem at -8% 4%, color-mix(in oklab, var(--info-500) 9%, transparent), transparent 60%);
        background-attachment: fixed;
        background-repeat: no-repeat;
    }

    .dark .fi-body {
        background-image:
            radial-gradient(60rem 32rem at 78% -12%, color-mix(in oklab, var(--primary-400) 14%, transparent), transparent 65%),
            radial-gradient(48rem 28rem at -8% 4%, color-mix(in oklab, var(--info-400) 10%, transparent), transparent 60%);
    }

    /* Sidebar: quieter group headings, a clear accent rail on the open page. */

    .fi-sidebar-group-label {
        text-transform: uppercase;
        letter-spacing: 0.09em;
        font-size: 0.6875rem;
        font-weight: 700;
        opacity: 0.62;
    }

    .fi-sidebar-item-btn {
        border-inline-start: 2px solid transparent;
        transition: background-color 150ms ease, border-color 150ms ease;
    }

    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn,
    .fi-sidebar-item-btn.fi-active {
        border-inline-start-color: var(--primary-500);
        background-color: color-mix(in oklab, var(--primary-500) 12%, transparent);
    }

    .fi-sidebar-item-btn:hover {
        background-color: color-mix(in oklab, var(--primary-500) 7%, transparent);
    }

    /* Sidebar footer card injected at SIDEBAR_NAV_START. */

    .drz-console-card {
        margin: 0 0.25rem 1rem;
        padding: 0.75rem 0.875rem;
        border-radius: 0.75rem;
        border: 1px solid color-mix(in oklab, var(--primary-500) 22%, transparent);
        background: color-mix(in oklab, var(--primary-500) 9%, transparent);
        display: grid;
        gap: 0.35rem;
    }

    .drz-console-card__title {
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        opacity: 0.75;
    }

    .drz-console-card__row {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 0.75rem;
        font-size: 0.75rem;
    }

    .drz-console-card__value {
        font-weight: 700;
        font-variant-numeric: tabular-nums;
    }

    .drz-console-card__muted {
        opacity: 0.7;
    }

    /* Collapsed sidebar: the card would overflow the icon rail. */
    .fi-sidebar:not(.fi-sidebar-open) .drz-console-card {
        display: none;
    }

    /* Stat tiles read as the headline of the dashboard, so give them a
       little more presence and a hover affordance where they link out. */

    .fi-wi-stats-overview-stat {
        transition: transform 150ms ease, box-shadow 150ms ease;
    }

    .fi-wi-stats-overview-stat:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 24px -18px color-mix(in oklab, var(--gray-950) 60%, transparent);
    }

    .fi-wi-stats-overview-stat-value {
        font-variant-numeric: tabular-nums;
        letter-spacing: -0.02em;
    }

    .fi-wi-stats-overview-stat-label {
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-size: 0.6875rem;
        font-weight: 700;
        opacity: 0.7;
    }

    /* Tables and sections: softer edges, calmer separators. */

    .fi-section,
    .fi-ta-ctn {
        border-radius: 0.875rem;
    }

    .fi-ta-header-cell {
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-size: 0.6875rem;
    }

    .fi-ta-row:hover {
        background-color: color-mix(in oklab, var(--primary-500) 5%, transparent);
    }

    /* Page headings get the brand accent instead of plain black text. */

    .fi-header-heading {
        letter-spacing: -0.02em;
    }

    .fi-header-subheading {
        max-width: 68ch;
    }

    @media (prefers-reduced-motion: reduce) {
        .fi-wi-stats-overview-stat,
        .fi-sidebar-item-btn {
            transition: none;
        }

        .fi-wi-stats-overview-stat:hover {
            transform: none;
        }
    }
</style>
