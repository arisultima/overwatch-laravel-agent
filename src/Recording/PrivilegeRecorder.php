<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
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
     * One account removed, counted once. With SoftDeletes the soft delete
     * is the removal: a later force delete of that trashed account raises
     * `deleted` again and is not counted. A force delete of an account that
     * was never trashed is its removal, and is counted.
     */
    public function accountDeleted(Model $user): void
    {
        try {
            if (method_exists($user, 'isForceDeleting') && $user->isForceDeleting()
                && method_exists($user, 'trashed') && $user->trashed()) {
                return;
            }

            $this->recorder->count('auth.accounts_deleted');
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
