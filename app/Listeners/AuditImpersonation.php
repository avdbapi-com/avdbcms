<?php

namespace App\Listeners;

use App\Http\Controllers\Admin\AuditLogController;
use App\Support\AuditDetails;
use Lab404\Impersonate\Events\TakeImpersonation;

/**
 * Tools > Logs > Admin actions: an administrator signed in as a member ("Log in as" on a member's page; R2A-020).
 * The package has already switched the session to the member when it says so: the row names the administrator by id.
 */
class AuditImpersonation
{
    public function handle(TakeImpersonation $event): void
    {
        AuditLogController::log('member.impersonated', AuditDetails::member($event->impersonated), (int) $event->impersonator->getAuthIdentifier());
    }
}
