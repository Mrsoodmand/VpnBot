<?php

return [
    'enabled' => env('CUSTOMER_LOYALTY_ENABLED', true),
    // Owner confirmed that all current bot services are shared configurations.
    // Set false and use the allowlist before adding any non-shared services.
    'all_services_shared' => env('CUSTOMER_LOYALTY_ALL_SERVICES_SHARED', true),
    'shared_service_ids' => array_values(array_filter(array_map('intval', explode(',', env('CUSTOMER_LOYALTY_SHARED_SERVICE_IDS', ''))), fn ($id) => $id > 0)),
];
