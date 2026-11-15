<?php

/*
|--------------------------------------------------------------------------
| Platform (multi-tenant PaaS) configuration
|--------------------------------------------------------------------------
|
| When PLATFORM_ENABLED=true this Coolify fork runs as a paid, multi-tenant
| PaaS: the servers belong to the platform operator (root team, id 0) and every
| other team is a tenant that deploys onto shared regional nodes inside its own
| isolated Docker network, limited by the quotas of the plan it pays for.
|
| Platform mode implies cloud mode (SELF_HOSTED defaults to false when
| PLATFORM_ENABLED is true, see config/constants.php).
|
*/

return [
    'enabled' => (bool) env('PLATFORM_ENABLED', false),

    'name' => env('PLATFORM_NAME', 'Vieira Technology Cloud'),

    'support_email' => env('PLATFORM_SUPPORT_EMAIL'),

    'sales_url' => env('PLATFORM_SALES_URL'),

    'currency' => strtolower((string) env('PLATFORM_CURRENCY', 'brl')),

    'default_region' => env('PLATFORM_DEFAULT_REGION', 'sa-east-1'),

    /*
     * Docker network created for every tenant on every node of its region.
     * The final name is "{prefix}-{team_id}".
     */
    'tenant_network_prefix' => env('PLATFORM_TENANT_NETWORK_PREFIX', 'tenant'),

    /*
     * Base domain used for generated application URLs (the wildcard domain of
     * the platform nodes, e.g. apps.example.com). Only used for reserved-host
     * protection; the actual wildcard comes from each server's settings.
     */
    'base_domain' => env('PLATFORM_BASE_DOMAIN'),

    /*
     * Hosts that tenants can never claim (comma separated). The instance FQDN,
     * the base domain itself and these hosts are always reserved.
     */
    'reserved_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('PLATFORM_RESERVED_HOSTS', 'www,api,app,admin,dashboard,grafana,prometheus,status,mail,smtp,registry'))))),

    /*
     * Allow tenants to connect their own servers next to the shared ones.
     * Disabled by default: in the PaaS model the operator owns the hardware.
     */
    'allow_tenant_servers' => (bool) env('PLATFORM_ALLOW_TENANT_SERVERS', false),

    /*
     * Container terminal for tenants. The upstream terminal server executes
     * the SSH command sent by the browser, so it must stay disabled for
     * shared nodes until the terminal server validates commands server-side.
     */
    'tenant_terminal' => (bool) env('PLATFORM_TENANT_TERMINAL', false),

    'trial_days' => (int) env('PLATFORM_TRIAL_DAYS', 0),

    /*
     * Stripe Tax must be configured in the Stripe dashboard before enabling automatic tax.
     */
    'stripe_automatic_tax' => (bool) env('PLATFORM_STRIPE_AUTOMATIC_TAX', false),

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | Prices live in Stripe (one recurring price per plan and interval); this
    | file maps the Stripe price ids to quotas and features. Quotas are totals
    | for the whole tenant. "0" means unlimited where noted.
    |
    */
    'plans' => [
        'basic' => [
            'name' => 'Básico',
            'description' => 'Isolated containers with CPU/RAM quotas, managed SSL, basic logs and limited deployments.',
            'stripe_price_monthly' => env('PLATFORM_STRIPE_PRICE_BASIC_MONTHLY'),
            'stripe_price_yearly' => env('PLATFORM_STRIPE_PRICE_BASIC_YEARLY'),
            'contact_sales' => false,
            'quotas' => [
                'cpus' => (float) env('PLATFORM_BASIC_CPUS', 0.5),
                'memory_mb' => (int) env('PLATFORM_BASIC_MEMORY_MB', 1536),
                'storage_gb' => (int) env('PLATFORM_BASIC_STORAGE_GB', 10),
                'resources' => (int) env('PLATFORM_BASIC_RESOURCES', 3),
                'containers' => (int) env('PLATFORM_BASIC_CONTAINERS', 6),
                'deployments_per_day' => (int) env('PLATFORM_BASIC_DEPLOYMENTS_PER_DAY', 20),
                'custom_domains' => (int) env('PLATFORM_BASIC_CUSTOM_DOMAINS', 1),
                'egress_gb' => (int) env('PLATFORM_BASIC_EGRESS_GB', 50),
                'pids_per_container' => 256,
            ],
            'features' => [
                'databases' => true,
                'persistent_volumes' => false,
                'scheduled_backups' => false,
                'log_export' => false,
                'preview_deployments' => false,
                'public_databases' => false,
                'docker_compose' => true,
                'dedicated_server' => false,
                'sso' => false,
            ],
        ],

        'pro' => [
            'name' => 'Profissional',
            'description' => 'More CPU/RAM, persistent volumes, log export and scheduled backups.',
            'stripe_price_monthly' => env('PLATFORM_STRIPE_PRICE_PRO_MONTHLY'),
            'stripe_price_yearly' => env('PLATFORM_STRIPE_PRICE_PRO_YEARLY'),
            'contact_sales' => false,
            'quotas' => [
                'cpus' => (float) env('PLATFORM_PRO_CPUS', 2),
                'memory_mb' => (int) env('PLATFORM_PRO_MEMORY_MB', 4096),
                'storage_gb' => (int) env('PLATFORM_PRO_STORAGE_GB', 50),
                'resources' => (int) env('PLATFORM_PRO_RESOURCES', 15),
                'containers' => (int) env('PLATFORM_PRO_CONTAINERS', 30),
                'deployments_per_day' => (int) env('PLATFORM_PRO_DEPLOYMENTS_PER_DAY', 200),
                'custom_domains' => (int) env('PLATFORM_PRO_CUSTOM_DOMAINS', 20),
                'egress_gb' => (int) env('PLATFORM_PRO_EGRESS_GB', 250),
                'pids_per_container' => 512,
            ],
            'features' => [
                'databases' => true,
                'persistent_volumes' => true,
                'scheduled_backups' => true,
                'log_export' => true,
                'preview_deployments' => true,
                'public_databases' => true,
                'docker_compose' => true,
                'dedicated_server' => false,
                'sso' => false,
            ],
        ],

        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'Dedicated nodes (VM/LXD isolation), dedicated network, SSO (SAML/OIDC), custom SLA and SIEM integration.',
            'stripe_price_monthly' => env('PLATFORM_STRIPE_PRICE_ENTERPRISE_MONTHLY'),
            'stripe_price_yearly' => env('PLATFORM_STRIPE_PRICE_ENTERPRISE_YEARLY'),
            'contact_sales' => (bool) env('PLATFORM_ENTERPRISE_CONTACT_SALES', true),
            'quotas' => [
                'cpus' => (float) env('PLATFORM_ENTERPRISE_CPUS', 8),
                'memory_mb' => (int) env('PLATFORM_ENTERPRISE_MEMORY_MB', 16384),
                'storage_gb' => (int) env('PLATFORM_ENTERPRISE_STORAGE_GB', 250),
                'resources' => (int) env('PLATFORM_ENTERPRISE_RESOURCES', 100),
                'containers' => (int) env('PLATFORM_ENTERPRISE_CONTAINERS', 200),
                'deployments_per_day' => (int) env('PLATFORM_ENTERPRISE_DEPLOYMENTS_PER_DAY', 0),
                'custom_domains' => (int) env('PLATFORM_ENTERPRISE_CUSTOM_DOMAINS', 0),
                'egress_gb' => (int) env('PLATFORM_ENTERPRISE_EGRESS_GB', 1000),
                'pids_per_container' => 1024,
            ],
            'features' => [
                'databases' => true,
                'persistent_volumes' => true,
                'scheduled_backups' => true,
                'log_export' => true,
                'preview_deployments' => true,
                'public_databases' => true,
                'docker_compose' => true,
                'dedicated_server' => true,
                'sso' => true,
            ],
        ],
    ],

    /*
     * Overage rates (in cents of the platform currency). CPU and memory are
     * hard limits (cgroups) and therefore never billed as overage; egress and
     * storage above the plan are metered and billed on the next invoice.
     */
    'overage' => [
        'egress_gb_cents' => (int) env('PLATFORM_OVERAGE_EGRESS_GB_CENTS', 50),
        'storage_gb_month_cents' => (int) env('PLATFORM_OVERAGE_STORAGE_GB_MONTH_CENTS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Container hardening applied to every tenant container
    |--------------------------------------------------------------------------
    */
    'container' => [
        'default_cpus' => (float) env('PLATFORM_CONTAINER_DEFAULT_CPUS', 0.5),
        'default_memory_mb' => (int) env('PLATFORM_CONTAINER_DEFAULT_MEMORY_MB', 512),
        // Capabilities kept after "cap_drop: ALL" (enough for images that start as root and drop privileges).
        'cap_add' => array_values(array_filter(array_map('trim', explode(',', (string) env('PLATFORM_CONTAINER_CAP_ADD', 'CHOWN,DAC_OVERRIDE,FOWNER,FSETID,KILL,SETGID,SETUID,NET_BIND_SERVICE'))))),
        'oom_score_adj' => (int) env('PLATFORM_CONTAINER_OOM_SCORE_ADJ', 500),
        'log_max_size' => env('PLATFORM_CONTAINER_LOG_MAX_SIZE', '10m'),
        'log_max_file' => (string) env('PLATFORM_CONTAINER_LOG_MAX_FILE', '3'),
        // Optional sandboxed OCI runtime for tenant containers (e.g. "runsc" for gVisor). Must be installed on the nodes.
        'runtime' => env('PLATFORM_CONTAINER_RUNTIME'),
        // Requires overlay2 on XFS with pquota on the nodes.
        'enforce_storage_opt' => (bool) env('PLATFORM_ENFORCE_STORAGE_OPT', false),
        // Host ports tenants may publish (public databases). Outside this range publishing is refused.
        'public_port_min' => (int) env('PLATFORM_PUBLIC_PORT_MIN', 20000),
        'public_port_max' => (int) env('PLATFORM_PUBLIC_PORT_MAX', 29999),
    ],

    /*
    |--------------------------------------------------------------------------
    | Enforcement (wiki: 80% -> warning, 100% -> soft throttle, unpaid > 7d ->
    | suspend new deploys, unpaid > 30d -> stop containers)
    |--------------------------------------------------------------------------
    */
    'enforcement' => [
        'quota_warning_percent' => (int) env('PLATFORM_QUOTA_WARNING_PERCENT', 80),
        'suspend_after_days' => (int) env('PLATFORM_SUSPEND_AFTER_DAYS', 7),
        'hard_suspend_after_days' => (int) env('PLATFORM_HARD_SUSPEND_AFTER_DAYS', 30),
    ],

    'metering' => [
        'interval_minutes' => (int) env('PLATFORM_METERING_INTERVAL_MINUTES', 5),
        'raw_retention_days' => (int) env('PLATFORM_METERING_RAW_RETENTION_DAYS', 90),
    ],

    /*
     * Bearer token for the Prometheus endpoint (/platform/metrics).
     * The endpoint is disabled while this is empty.
     */
    'metrics_token' => env('PLATFORM_METRICS_TOKEN'),

    /*
     * Regional edge routers (infra/ansible/roles/edge). Each one polls
     * /api/v1/platform/edge/{region} with this bearer token and forwards every public host
     * to the node(s) running it: HTTP by Host header, HTTPS passed through by SNI.
     */
    'edge' => [
        'token' => env('PLATFORM_EDGE_TOKEN'),
        'node_http_port' => (int) env('PLATFORM_EDGE_NODE_HTTP_PORT', 80),
        'node_https_port' => (int) env('PLATFORM_EDGE_NODE_HTTPS_PORT', 443),
        // Send PROXY protocol v2 to the node proxies (enable proxyProtocol.trustedIPs on them first).
        'proxy_protocol' => (bool) env('PLATFORM_EDGE_PROXY_PROTOCOL', false),
        'cache_seconds' => (int) env('PLATFORM_EDGE_CACHE_SECONDS', 10),
    ],
];
