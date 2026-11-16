<?php

return [
    // Optional comma-separated IPs/CIDRs. Wildcards and catch-all CIDRs are rejected.
    // Leave empty for the Cloudflare container path: canonical origin handling
    // below does not trust any request-supplied forwarding headers.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    // Explicit HTTPS deployment origin, injected by the Worker from APP_URL.
    // Applied only when the actual HTTP Host exactly matches this origin.
    'public_origin' => env('PUBLIC_ORIGIN', ''),

    // Set by the Cloudflare Worker: requests reach the container only through it, and it puts the
    // visitor's address (Cloudflare's CF-Connecting-IP) in x-erp-client-ip. Off everywhere else.
    'client_ip_from_worker' => (bool) env('ERP_CLIENT_IP_FROM_WORKER', false),
];
