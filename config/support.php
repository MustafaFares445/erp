<?php

declare(strict_types=1);

return [
    'workspace_v2_enabled' => (bool) env('SUPPORT_WORKSPACE_V2_ENABLED', true),
    'sla_v2_enabled' => (bool) env('SUPPORT_SLA_V2_ENABLED', true),
    'smart_routing_enabled' => (bool) env('SUPPORT_SMART_ROUTING_ENABLED', false),
    'support_automation_enabled' => (bool) env('SUPPORT_AUTOMATION_ENABLED', false),
    'field_service_enabled' => (bool) env('SUPPORT_FIELD_SERVICE_ENABLED', true),
    'knowledge_base_enabled' => (bool) env('SUPPORT_KNOWLEDGE_BASE_ENABLED', false),
    'customer_support_api_enabled' => (bool) env('SUPPORT_CUSTOMER_API_ENABLED', false),
    'equipment_installation_enabled' => (bool) env('SUPPORT_EQUIPMENT_INSTALLATION_ENABLED', true),
    'calibration_enabled' => (bool) env('SUPPORT_CALIBRATION_ENABLED', true),
    'loaner_equipment_enabled' => (bool) env('SUPPORT_LOANER_EQUIPMENT_ENABLED', false),
    'external_repair_enabled' => (bool) env('SUPPORT_EXTERNAL_REPAIR_ENABLED', false),
    'csat_enabled' => (bool) env('SUPPORT_CSAT_ENABLED', false),

    // Hosts the customer app may use as diagnostic-payment success/cancel redirect targets (comma separated,
    // subdomains included). The application's own host is always allowed.
    'customer_api_redirect_hosts' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('SUPPORT_CUSTOMER_API_REDIRECT_HOSTS', '')),
    ))),
];
