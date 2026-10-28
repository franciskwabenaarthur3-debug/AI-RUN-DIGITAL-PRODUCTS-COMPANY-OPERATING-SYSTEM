<?php

namespace App\Events;

use App\Models\ApprovalRequest;

/**
 * Fired inside the decision's transaction, so listeners that update the
 * approved resource commit or roll back with the decision.
 */
final class ApprovalDecided
{
    public function __construct(public readonly ApprovalRequest $request) {}
}
