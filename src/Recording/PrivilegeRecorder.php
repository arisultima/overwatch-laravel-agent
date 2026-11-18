<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Auth\Access\Response;
use Throwable;

/**
 * Counts role and permission changes, accounts created and deleted, and
 * authorisation checks denied. Only that something happened is counted;
 * never which user, role, ability or model.
 */
final class PrivilegeRecorder
{
    public function __construct(private readonly Recorder $recorder) {}

    public function count(string $key): void
    {
        try {
            $this->recorder->count($key);
        } catch (Throwable) {
            // Recording never throws into the app.
        }
    }

    /**
     * The gate's final answer, after every before and after callback. A
     * check nothing granted (a null result) is a denial too: the gate
     * refuses it.
     */
    public function gateEvaluated(GateEvaluated $event): void
    {
        try {
            $result = $event->result;
            $denied = $result instanceof Response ? $result->denied() : ! $result;

            if ($denied) {
                $this->recorder->count('auth.gate_denials');
            }
        } catch (Throwable) {
            // Recording never throws into the app.
        }
    }
}
