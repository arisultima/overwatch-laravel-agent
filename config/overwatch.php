<?php

declare(strict_types=1);

return [

    /*
    | Reporting is on only when this is true AND both the url and the token
    | below are set. With any of them missing the package registers nothing:
    | no listeners, no middleware and no schedule entry.
    */
    'enabled' => env('OVERWATCH_ENABLED', true),

    /*
    | Your Overwatch server, for example https://overwatch.example.com, and the
    | app token it issued (it starts with satapp_).
    */
    'url' => env('OVERWATCH_URL'),

    'token' => env('OVERWATCH_TOKEN'),

    /*
    | The name this machine reports under. Null uses the hostname; set it when
    | hostnames are not stable (containers, autoscaling), to a different value
    | on every machine: machines sharing one name are counted as one.
    */
    'instance' => env('OVERWATCH_INSTANCE'),

    /*
    | The cache store the per-minute counters live in. Null uses the default
    | store. Share it between machines so one node can report for all of them.
    */
    'cache_store' => env('OVERWATCH_CACHE_STORE'),

    /*
    | Thresholds, in milliseconds, for counting a query or a request as slow.
    */
    'slow_query_ms' => 500,

    'slow_request_ms' => 1000,

    /*
    | A sign-in by a user holding any of these roles is counted as privileged.
    */
    'privileged_roles' => ['root', 'superadmin', 'admin'],

    /*
    | The user model whose created and deleted events are counted. Null uses
    | auth.providers.users.model.
    */
    'user_model' => null,

    /*
    | Route names of the sign-in endpoints, for counting throttled sign-ins.
    */
    'signin_routes' => ['login', 'login.store', 'two-factor.login', 'two-factor.login.store'],

    /*
    | Queue names on the default queue connection whose depth and oldest
    | waiting job are reported, at most 20. Empty uses that connection's
    | default queue.
    */
    'queues' => [],

];
