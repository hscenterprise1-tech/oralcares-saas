<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Routes excluded from the client route table
    |--------------------------------------------------------------------------
    |
    | `@routes` inlines the application's named routes into every full page
    | load — 340 of them, about 61 KB. In an Inertia SPA that cost is paid once
    | per hard load rather than per navigation, so it is not what makes a page
    | feel slow; it is still weight on first paint and on every new tab.
    |
    | Only routes the browser never resolves by name are removed, and each group
    | is listed with the reason rather than guessed at:
    |
    |   api.*        consumed by fetch() against literal paths, not route()
    |   webhooks.*   called by WhatsApp's servers, never by this app
    |   storage.*    signed file URLs are generated server-side and sent whole
    |   boost.*      Laravel Boost's own dev endpoint
    |   public.*     the unauthenticated enquiry form, which is not in the SPA
    |   design-lab   a local-only development page
    |
    | `company.*` is deliberately kept in full: 286 of the named routes are
    | company routes and the SPA resolves them by name everywhere. Trimming
    | those would mean a runtime error the first time a missed one is called,
    | which is a far worse trade than a few kilobytes.
    |
    */

    'except' => [
        'api.*',
        'webhooks.*',
        'storage.*',
        'boost.*',
        'public.*',
        'design-lab',
    ],

];
