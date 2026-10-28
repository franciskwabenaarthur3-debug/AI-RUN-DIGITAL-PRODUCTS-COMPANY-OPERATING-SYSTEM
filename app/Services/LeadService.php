<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\LeadStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Agent;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * All lead writes go through here so every change leaves history and the
 * status workflow cannot be skipped.
 */
class LeadService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User|Agent $actor): Lead
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $lead = new Lead($attributes);
            $lead->created_by = $actor instanceof User ? $actor->id : null;
            $lead->save();

            $this->log($lead, 'created', 'Lead created.', $actor, ['source' => $lead->source->value]);
            $this->audit->record('lead.created', $lead, actor: $actor);

            return $lead;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Lead $lead, array $attributes, User $actor): Lead
    {
        return DB::transaction(function () use ($lead, $attributes, $actor) {
            $lead->fill($attributes);
            $changed = array_keys($lead->getDirty());

            if ($changed === []) {
                return $lead;
            }

            $lead->save();
            $this->log($lead, 'updated', 'Lead details updated.', $actor, ['fields' => $changed]);

            return $lead;
        });
    }

    public function changeStatus(Lead $lead, LeadStatus $target, User|Agent $actor, ?string $reason = null): Lead
    {
        if (! in_array($target, $lead->status->manualTransitions(), true)) {
            throw new InvalidTransition("A lead cannot move from {$lead->status->label()} to {$target->label()}.");
        }

        return DB::transaction(function () use ($lead, $target, $actor, $reason) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);

            if (! in_array($target, $lead->status->manualTransitions(), true)) {
                throw new InvalidTransition("A lead cannot move from {$lead->status->label()} to {$target->label()}.");
            }

            if ($target->isOutreach() && ! $lead->canBeContacted()) {
                throw new InvalidTransition('This contact has opted out and cannot enter outreach.');
            }

            $from = $lead->status;
            $lead->status = $target;
            $lead->save();

            $meta = ['from' => $from->value, 'to' => $target->value];
            $this->log($lead, 'status_changed', "Status changed from {$from->label()} to {$target->label()}.".($reason ? " Reason: {$reason}" : ''), $actor, $meta);
            $this->audit->record('lead.status_changed', $lead, $meta, $actor);

            return $lead;
        });
    }

    public function scheduleFollowUp(Lead $lead, ?CarbonInterface $at, User $actor): Lead
    {
        $lead->next_follow_up_at = $at;
        $lead->save();

        $this->log($lead, 'follow_up_scheduled', $at ? 'Follow-up scheduled for '.$at->toDayDateTimeString().'.' : 'Follow-up cleared.', $actor, [
            'at' => $at?->toIso8601String(),
        ]);

        return $lead;
    }

    public function addNote(Lead $lead, string $note, User $actor): LeadActivity
    {
        return $this->log($lead, 'note', $note, $actor);
    }

    /**
     * Store an outreach draft on the lead's history. Nothing is sent: a
     * person reviews the draft and sends it outside Company OS.
     */
    public function recordOutreachDraft(Lead $lead, string $channel, ?string $subject, string $body, User|Agent $actor): LeadActivity
    {
        if (! $lead->canBeContacted()) {
            throw new InvalidTransition('This contact has opted out; no outreach may be drafted.');
        }

        return DB::transaction(function () use ($lead, $channel, $subject, $body, $actor) {
            $activity = $this->log($lead, 'outreach_drafted', "Outreach draft ({$channel}, not sent).", $actor, [
                'channel' => $channel,
                'subject' => $subject,
                'body' => $body,
            ]);
            $this->audit->record('lead.outreach_drafted', $lead, ['channel' => $channel, 'activity_id' => $activity->id], actor: $actor);

            return $activity;
        });
    }

    /**
     * Record that the contact asked not to be contacted. This is permanent
     * through normal workflows: the lead can no longer enter outreach.
     */
    public function optOut(Lead $lead, string $reason, User $actor): Lead
    {
        return DB::transaction(function () use ($lead, $reason, $actor) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);

            if ($lead->do_not_contact) {
                return $lead;
            }

            $from = $lead->status;
            $lead->do_not_contact = true;
            $lead->opted_out_at = now();
            $lead->opt_out_reason = $reason;
            $lead->next_follow_up_at = null;
            if ($lead->status !== LeadStatus::Converted) {
                $lead->status = LeadStatus::Unsubscribed;
            }
            $lead->save();

            $this->log($lead, 'opted_out', "Contact opted out. Reason: {$reason}", $actor, ['from' => $from->value]);
            $this->audit->record('lead.opted_out', $lead, ['from' => $from->value], $actor);

            return $lead;
        });
    }

    /**
     * Convert a lead into a customer exactly once. Repeated or concurrent
     * calls return the customer created by the first one.
     */
    public function convert(Lead $lead, User $actor): Customer
    {
        return DB::transaction(function () use ($lead, $actor) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);

            if ($lead->customer_id !== null) {
                return $lead->customer;
            }

            if (! $lead->status->canTransitionTo(LeadStatus::Converted)) {
                throw new InvalidTransition("A lead in status {$lead->status->label()} cannot be converted.");
            }

            $customer = Customer::query()->create([
                'company_name' => $lead->company_name,
                'primary_contact' => $lead->contact_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'status' => CustomerStatus::Active,
            ]);

            $from = $lead->status;
            $lead->customer_id = $customer->id;
            $lead->status = LeadStatus::Converted;
            $lead->converted_at = now();
            $lead->next_follow_up_at = null;
            $lead->save();

            $this->log($lead, 'converted', "Converted to customer #{$customer->id}.", $actor, ['customer_id' => $customer->id, 'from' => $from->value]);
            $this->audit->record('lead.converted', $lead, ['customer_id' => $customer->id], $actor);
            $this->audit->record('customer.created', $customer, ['lead_id' => $lead->id], $actor);

            return $customer;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function log(Lead $lead, string $type, string $description, User|Agent $actor, array $metadata = []): LeadActivity
    {
        return $lead->activities()->create([
            'activity_type' => $type,
            'description' => $description,
            'actor_type' => $actor instanceof Agent ? 'agent' : 'user',
            'actor_id' => $actor->id,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
