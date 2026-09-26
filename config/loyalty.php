<?php

return [
    'enabled' => env('CUSTOMER_LOYALTY_ENABLED', false),
    // Explicit allowlist: services has no shared/dedicated discriminator. Never infer from a name.
    'shared_service_ids' => array_values(array_filter(array_map('intval', explode(',', env('CUSTOMER_LOYALTY_SHARED_SERVICE_IDS', ''))), fn ($id) => $id > 0)),
];
