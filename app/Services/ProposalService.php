<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\LeadStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\RiskLevel;
use App\Events\ApprovalDecided;
use App\Exceptions\InvalidTransition;
use App\Governance\ApprovalRefused;
use App\Governance\Payload;
use App\Mail\ProposalMail;
use App\Models\Agent;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalEvent;
use App\Models\ServicePackage;
use App\Models\User;
use App\Proposals\PriceBreakdown;
use App\Proposals\ProposalChecks;
use App\Proposals\ProposalPricing;
use App\Proposals\ProposalRefused;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Every proposal change. Prices are computed here from the package and
 * the entered lines; a version is frozen when it is submitted; sending
 * runs only through an approval of that exact version and recipient.
 */
class ProposalService
{
    public const SEND_ACTION = 'proposal.send';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ApprovalService $approvals,
        private readonly LeadService $leads,
        private readonly ProjectService $projects,
    ) {}

    /**
     * @param  array{lead_id?: int|null, customer_id?: int|null, project_id?: int|null, service_package_id: int, title: string, recipient_name?: string|null, recipient_email?: string|null, introduction?: string|null, scope?: list<string>|null, exclusions?: list<string>|null, extra_items?: list<array{description: string, quantity: int, unit_price_minor: int}>, discount_type?: string, discount_value?: int, discount_reason?: string|null, payment_terms?: string|null, expires_at?: string|null}  $data
     */
    public function create(array $data, User|Agent $actor): Proposal
    {
        $package = ServicePackage::query()->findOrFail($data['service_package_id']);
        [$lead, $customer] = $this->recipientRecord($data);
        $project = $this->changeRequestProject($data, $lead, $customer);

        if (! $package->is_active) {
            throw ValidationException::withMessages(['service_package_id' => 'Choose an active service package.']);
        }
        if ($lead && ! $lead->canBeContacted()) {
            throw ValidationException::withMessages(['lead_id' => 'This lead has opted out and cannot receive a proposal.']);
        }

        $price = $this->price($package, $data);

        return DB::transaction(function () use ($data, $package, $lead, $customer, $project, $price, $actor) {
            $proposal = Proposal::query()->create([
                'reference' => 'P-new-'.Str::uuid(),
                'version' => 1,
                'lead_id' => $lead?->id,
                'customer_id' => $customer?->id,
                'project_id' => $project?->id,
                'service_package_id' => $package->id,
                'title' => $data['title'],
                'recipient_name' => $this->filledOr($data['recipient_name'] ?? null, $lead?->contact_name ?? $customer?->primary_contact),
                'recipient_email' => $this->filledOr($data['recipient_email'] ?? null, $lead?->email ?? $customer?->email),
                'introduction' => $data['introduction'] ?? null,
                'scope_snapshot' => $this->scope($package, $data),
                'subtotal_minor' => $price->subtotalMinor,
                'discount_minor' => $price->discountMinor,
                'discount_reason' => $price->discountMinor > 0 ? $data['discount_reason'] : null,
                'amount_minor' => $price->amountMinor,
                'currency' => $package->currency,
                'payment_terms' => $this->filledOr($data['payment_terms'] ?? null, config('company_os.proposals.default_payment_terms')),
                'status' => ProposalStatus::Draft,
                'expires_at' => $data['expires_at'] ?? now()->addDays((int) config('company_os.proposals.default_valid_days'))->toDateString(),
                'created_by_user_id' => $actor instanceof User ? $actor->id : null,
                'created_by_agent_id' => $actor instanceof Agent ? $actor->id : null,
            ]);
            $proposal->reference = sprintf('P-%05d', $proposal->id);
            $proposal->save();

            $this->writeItems($proposal, $price);
            $this->event($proposal, 'created', $actor, ['amount_minor' => $price->amountMinor, 'currency' => $proposal->currency]);
            $this->audit->record('proposal.created', $proposal, ['reference' => $proposal->reference, 'amount_minor' => $price->amountMinor], $actor);

            return $proposal;
        });
    }

    /**
     * Change a draft. Anything else needs a new version.
     *
     * @param  array<string, mixed>  $data  same keys as create(), except the lead, customer and package
     */
    public function update(Proposal $proposal, array $data, User $actor): Proposal
    {
        return DB::transaction(function () use ($proposal, $data, $actor) {
            $proposal = $this->lockLatest($proposal);

            if (! $proposal->isEditable()) {
                throw new ProposalRefused("Proposal {$proposal->label()} is {$proposal->status->label()}; start a new version to change it.");
            }

            $package = $proposal->servicePackage;
            $price = $this->price($package, $data);

            $proposal->fill([
                'title' => $data['title'],
                'recipient_name' => $data['recipient_name'] ?? null,
                'recipient_email' => $data['recipient_email'] ?? null,
                'introduction' => $data['introduction'] ?? null,
                'scope_snapshot' => $this->scope($package, $data),
                'subtotal_minor' => $price->subtotalMinor,
                'discount_minor' => $price->discountMinor,
                'discount_reason' => $price->discountMinor > 0 ? $data['discount_reason'] : null,
                'amount_minor' => $price->amountMinor,
                'payment_terms' => $data['payment_terms'],
                'expires_at' => $data['expires_at'],
            ]);
            $changed = array_keys($proposal->getDirty());
            $proposal->save();

            $proposal->items()->delete();
            $this->writeItems($proposal, $price);

            $this->event($proposal, 'edited', $actor, ['fields' => $changed]);
            $this->audit->record('proposal.edited', $proposal, ['fields' => $changed, 'amount_minor' => $price->amountMinor], $actor);

            return $proposal;
        });
    }

    /**
     * Freeze the version and ask the founder to approve sending it. Also
     * re-requests approval when the earlier request expired or failed.
     */
    public function submit(Proposal $proposal, User $actor): Proposal
    {
        return DB::transaction(function () use ($proposal, $actor) {
            $proposal = $this->lockLatest($proposal);
            $current = $proposal->approvalRequest;

            $resubmit = in_array($proposal->status, [ProposalStatus::PendingApproval, ProposalStatus::Approved], true);
            if ($resubmit && $current && in_array($current->status, [ApprovalStatus::Pending, ApprovalStatus::Approved, ApprovalStatus::Executing, ApprovalStatus::Executed], true) && ! $current->isExpired()) {
                throw new ProposalRefused('This version already has an open approval request.');
            }
            if (! $resubmit && $proposal->status !== ProposalStatus::Draft) {
                throw new ProposalRefused("Proposal {$proposal->label()} is {$proposal->status->label()} and cannot be submitted.");
            }

            if ($problems = ProposalChecks::problems($proposal)) {
                throw new ProposalRefused('Fix these before submitting:', $problems);
            }

            $payload = $this->payload($proposal);
            $attempt = ApprovalRequest::query()->where('action_type', self::SEND_ACTION)->where('resource_type', 'proposal')->where('resource_id', $proposal->id)->count() + 1;

            $approval = $this->approvals->request(
                actionType: self::SEND_ACTION,
                payload: $payload,
                reason: "Send proposal {$proposal->label()} ({$this->money($proposal)}) to {$proposal->recipient_name} <{$proposal->recipient_email}>.",
                risk: RiskLevel::Medium,
                rollbackPlan: 'A sent proposal cannot be unsent. Withdraw it in Company OS and send the recipient a written withdrawal or a corrected version.',
                idempotencyKey: "proposal:{$proposal->id}:send:{$attempt}",
                requester: $actor,
                resourceType: 'proposal',
                resourceId: $proposal->id,
                evidence: ["Content hash {$this->contentHash($payload)}"],
            );

            $proposal->status = ProposalStatus::PendingApproval;
            $proposal->approval_request_id = $approval->id;
            $proposal->setRelation('approvalRequest', $approval);
            $proposal->content_hash = $this->contentHash($payload);
            $proposal->submitted_at ??= now();
            $proposal->approved_at = null;
            $proposal->save();

            $this->event($proposal, $resubmit ? 'approval_requested_again' : 'submitted', $actor, ['approval_request_id' => $approval->id, 'content_hash' => $proposal->content_hash]);
            $this->audit->record('proposal.submitted', $proposal, ['approval_request_id' => $approval->id, 'content_hash' => $proposal->content_hash], $actor);

            return $proposal;
        });
    }

    /** Keeps the proposal in step with the founder's decision on its approval request. */
    public function onApprovalDecided(ApprovalDecided $event): void
    {
        $request = $event->request;

        if ($request->action_type !== self::SEND_ACTION || $request->resource_type !== 'proposal') {
            return;
        }

        $proposal = Proposal::query()->lockForUpdate()->find($request->resource_id);

        if (! $proposal || $proposal->approval_request_id !== $request->id || $proposal->status !== ProposalStatus::PendingApproval) {
            return;
        }

        $approved = $request->status === ApprovalStatus::Approved;
        $decision = $request->decision()->first();
        $approver = User::query()->find($decision?->approver_user_id);

        $proposal->status = $approved ? ProposalStatus::Approved : ProposalStatus::Rejected;
        $proposal->approved_at = $approved ? now() : null;
        $proposal->save();

        $this->event($proposal, $approved ? 'approved' : 'rejected', $approver, ['approval_request_id' => $request->id, 'reason' => $decision?->reason]);
    }

    /**
     * Send an approved version, by email or by recording that the founder
     * sent it themselves. Runs once per approval; the approval engine
     * refuses a payload that differs from what was approved.
     *
     * @param  'email'|'manual'  $method
     */
    public function send(Proposal $proposal, string $method, User $actor, ?string $note = null): Proposal
    {
        if (! $actor->is_active || ! $actor->isFounder()) {
            throw new ProposalRefused('Only the founder can send proposals.');
        }
        if ($method === 'email' && ! config('company_os.proposals.email_enabled')) {
            throw new ProposalRefused('Email sending is not configured. Set PROPOSALS_EMAIL_ENABLED and a mail service, or record a send you made yourself.');
        }
        if ($method === 'manual' && blank($note)) {
            throw new ProposalRefused('Say how and when you sent it.');
        }

        $proposal = $proposal->fresh();
        if ($proposal->status !== ProposalStatus::Approved || ! $proposal->isLatest()) {
            throw new ProposalRefused("Proposal {$proposal->label()} is {$proposal->status->label()}; only the latest approved version can be sent.");
        }
        if ($problems = ProposalChecks::problems($proposal)) {
            $this->event($proposal, 'send_refused', $actor, ['problems' => $problems]);

            throw new ProposalRefused('The proposal can no longer be sent:', $problems);
        }

        try {
            $result = $this->approvals->execute(
                $proposal->approvalRequest,
                self::SEND_ACTION,
                'proposal',
                $proposal->id,
                $this->payload($proposal),
                fn () => $method === 'email' ? $this->deliverByEmail($proposal) : ['method' => 'manual', 'delivery_status' => 'recorded_manually', 'note' => Str::limit((string) $note, 500)],
            );
        } catch (ApprovalRefused $e) {
            $this->event($proposal, 'send_refused', $actor, ['reason' => $e->reasonCode]);

            throw new ProposalRefused($e->getMessage().' Request approval again to send this version.');
        } catch (Throwable $e) {
            DB::transaction(function () use ($proposal, $method, $actor, $e) {
                $proposal = Proposal::query()->lockForUpdate()->findOrFail($proposal->id);
                $proposal->delivery_method = $method;
                $proposal->delivery_status = 'failed';
                $proposal->save();
                $this->event($proposal, 'send_failed', $actor, ['error' => Str::limit($e->getMessage(), 300)]);
            });
            report($e);

            throw new ProposalRefused('Sending failed: '.Str::limit($e->getMessage(), 200).' Nothing was recorded as sent. Request approval again to retry.');
        }

        return DB::transaction(function () use ($proposal, $result, $actor) {
            $proposal = Proposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $proposal->status = ProposalStatus::Sent;
            $proposal->sent_at = now();
            $proposal->delivery_method = $result['method'];
            $proposal->delivery_status = $result['delivery_status'];
            $proposal->delivery_reference = $result['message_id'] ?? null;
            $proposal->save();

            $this->event($proposal, 'sent', $actor, $result);
            $this->audit->record('proposal.sent', $proposal, $result, $actor);

            if ($lead = $proposal->lead) {
                $this->markLeadContacted($lead, $proposal, $actor);
            }

            return $proposal;
        });
    }

    /**
     * Record the recipient's answer to a sent version. Only people record
     * this, from what the recipient actually said; repeating the same
     * answer changes nothing.
     *
     * @param  'accepted'|'declined'|'changes_requested'  $outcome
     */
    public function recordResponse(Proposal $proposal, string $outcome, string $note, Carbon $respondedAt, User $actor): Proposal
    {
        $target = ProposalStatus::from($outcome);

        return DB::transaction(function () use ($proposal, $target, $note, $respondedAt, $actor) {
            $proposal = Proposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($proposal->status === $target) {
                return $proposal;
            }
            if ($proposal->status !== ProposalStatus::Sent || ! $proposal->status->canTransitionTo($target)) {
                throw new ProposalRefused("Proposal {$proposal->label()} is {$proposal->status->label()}; only a sent proposal can be answered.");
            }

            $proposal->status = $target;
            $proposal->responded_at = $respondedAt;
            $proposal->response_note = $note;
            $proposal->save();

            $metadata = ['responded_at' => $respondedAt->toDateString()];
            if ($target === ProposalStatus::Accepted && ($lead = $proposal->lead)) {
                $metadata['customer_id'] = $this->convertLead($lead, $actor);
            }

            $this->event($proposal, $target->value, $actor, $metadata + ['note' => $note]);
            $this->audit->record('proposal.'.$target->value, $proposal, $metadata, $actor);

            // Runs once: only the sent -> accepted transition above reaches it.
            if ($target === ProposalStatus::Accepted) {
                $proposal->isChangeRequest()
                    ? $this->projects->applyChangeRequest($proposal, $actor)
                    : $this->projects->createFromProposal($proposal, $actor);
            }

            return $proposal;
        });
    }

    public function withdraw(Proposal $proposal, string $reason, User $actor): Proposal
    {
        return DB::transaction(function () use ($proposal, $reason, $actor) {
            $proposal = Proposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if (! $proposal->status->canTransitionTo(ProposalStatus::Withdrawn)) {
                throw new ProposalRefused("Proposal {$proposal->label()} is {$proposal->status->label()} and cannot be withdrawn.");
            }

            $this->closeApproval($proposal, 'withdrawn', 'The proposal was withdrawn.');
            $proposal->status = ProposalStatus::Withdrawn;
            $proposal->save();

            $this->event($proposal, 'withdrawn', $actor, ['reason' => $reason, 'was_sent' => $proposal->sent_at !== null]);
            $this->audit->record('proposal.withdrawn', $proposal, ['reason' => $reason], $actor);

            return $proposal;
        });
    }

    /**
     * Start a new draft version. A version that was waiting for approval or
     * approved but not sent is withdrawn, and its approval invalidated.
     */
    public function newVersion(Proposal $proposal, User $actor): Proposal
    {
        return DB::transaction(function () use ($proposal, $actor) {
            $proposal = $this->lockLatest($proposal);

            if (! $proposal->status->allowsNewVersion()) {
                throw new ProposalRefused("Proposal {$proposal->label()} is {$proposal->status->label()}; a new version cannot be started from it.");
            }

            if (in_array($proposal->status, [ProposalStatus::PendingApproval, ProposalStatus::Approved], true)) {
                $this->closeApproval($proposal, 'superseded', 'A new version of the proposal was started.');
                $proposal->status = ProposalStatus::Withdrawn;
                $proposal->save();
                $this->event($proposal, 'superseded', $actor, []);
            }

            $package = $proposal->servicePackage;
            $lines = $proposal->items->map(fn ($i) => ['description' => $i->description, 'quantity' => $i->quantity, 'unit_price_minor' => $i->unit_price_minor])->all();
            // The package line is re-priced at the package's current price.
            $lines[0]['unit_price_minor'] = $package->base_price_minor;
            $lines[0]['description'] = $package->name;

            try {
                $price = ProposalPricing::calculate($lines, $proposal->discount_minor > 0 ? 'amount' : 'none', $proposal->discount_minor, $proposal->discount_reason);
            } catch (ValidationException) {
                $price = ProposalPricing::calculate($lines);
            }

            $next = $proposal->replicate(['status', 'approval_request_id', 'content_hash', 'submitted_at', 'approved_at', 'sent_at', 'delivery_method', 'delivery_status', 'delivery_reference', 'responded_at', 'response_note', 'created_by_agent_id']);
            $next->fill([
                'version' => $proposal->version + 1,
                'previous_version_id' => $proposal->id,
                'status' => ProposalStatus::Draft,
                'subtotal_minor' => $price->subtotalMinor,
                'discount_minor' => $price->discountMinor,
                'discount_reason' => $price->discountMinor > 0 ? $proposal->discount_reason : null,
                'amount_minor' => $price->amountMinor,
                'currency' => $package->currency,
                'expires_at' => $proposal->expires_at->isFuture() ? $proposal->expires_at : now()->addDays((int) config('company_os.proposals.default_valid_days')),
                'created_by_user_id' => $actor->id,
            ]);
            $next->save();
            $this->writeItems($next, $price);

            $this->event($next, 'version_created', $actor, ['previous_version_id' => $proposal->id]);
            $this->audit->record('proposal.version_created', $next, ['reference' => $next->reference, 'version' => $next->version], $actor);

            return $next;
        });
    }

    /** Expire sent proposals past their validity date. Returns how many. */
    public function expireSent(): int
    {
        $count = 0;
        $ids = Proposal::query()->where('status', ProposalStatus::Sent)->whereDate('expires_at', '<', today())->pluck('id');

        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id) {
                $proposal = Proposal::query()->lockForUpdate()->find($id);

                if (! $proposal || $proposal->status !== ProposalStatus::Sent || ! $proposal->expires_at->isBefore(today())) {
                    return 0;
                }

                $proposal->status = ProposalStatus::Expired;
                $proposal->save();
                $this->event($proposal, 'expired', null, []);
                $this->audit->record('proposal.expired', $proposal, [], actorType: 'system');

                return 1;
            });
        }

        return $count;
    }

    /**
     * The exact content the founder approves: recipient, scope, lines and
     * totals. Sending recomputes it from the stored version and must match.
     *
     * @return array<string, mixed>
     */
    public function payload(Proposal $proposal): array
    {
        $payload = [
            'reference' => $proposal->reference,
            'version' => $proposal->version,
            'recipient' => ['name' => $proposal->recipient_name, 'email' => $proposal->recipient_email, 'lead_id' => $proposal->lead_id, 'customer_id' => $proposal->customer_id],
            'title' => $proposal->title,
            'introduction' => $proposal->introduction,
            'service_package_id' => $proposal->service_package_id,
            'scope' => $proposal->scope_snapshot,
            'items' => $proposal->items()->get()->map(fn ($i) => [
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit_price_minor' => $i->unit_price_minor,
                'line_total_minor' => $i->line_total_minor,
            ])->all(),
            'subtotal_minor' => $proposal->subtotal_minor,
            'discount_minor' => $proposal->discount_minor,
            'discount_reason' => $proposal->discount_reason,
            'amount_minor' => $proposal->amount_minor,
            'currency' => $proposal->currency,
            'payment_terms' => $proposal->payment_terms,
            'expires_at' => $proposal->expires_at->toDateString(),
        ];

        // Only change requests carry it, so approvals made before projects existed still match.
        if ($proposal->project_id) {
            $payload['project_id'] = $proposal->project_id;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function contentHash(array $payload): string
    {
        return hash('sha256', Payload::canonical($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function deliverByEmail(Proposal $proposal): array
    {
        $mailer = config('company_os.proposals.mailer') ?: config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport");
        $sent = Mail::mailer($mailer)->to($proposal->recipient_email, $proposal->recipient_name)->send(new ProposalMail($proposal));

        return [
            'method' => 'email',
            'mailer' => $mailer,
            // A log or array mailer writes the message locally; nothing reaches the recipient.
            'delivery_status' => in_array($transport, ['log', 'array'], true) ? 'not_delivered_local_mailer' : 'accepted_by_mail_server',
            'message_id' => $sent?->getMessageId(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Lead|null, 1: Customer|null}
     */
    private function recipientRecord(array $data): array
    {
        $lead = filled($data['lead_id'] ?? null) ? Lead::query()->findOrFail($data['lead_id']) : null;
        $customer = filled($data['customer_id'] ?? null) ? Customer::query()->findOrFail($data['customer_id']) : null;

        if (($lead === null) === ($customer === null)) {
            throw ValidationException::withMessages(['lead_id' => 'Choose either a lead or a customer.']);
        }

        return [$lead, $customer];
    }

    /**
     * A change request must go to the same client as the project it extends.
     *
     * @param  array<string, mixed>  $data
     */
    private function changeRequestProject(array $data, ?Lead $lead, ?Customer $customer): ?Project
    {
        if (blank($data['project_id'] ?? null)) {
            return null;
        }

        $project = Project::query()->with('proposal')->findOrFail($data['project_id']);
        $sameClient = ($customer && $project->customer_id === $customer->id)
            || ($lead && $project->proposal?->lead_id === $lead->id);

        if (! $project->status->isActive() && $project->status !== ProjectStatus::Delivered) {
            throw ValidationException::withMessages(['project_id' => "Project \"{$project->name}\" is {$project->status->label()}; it cannot take a change request."]);
        }
        if (! $sameClient) {
            throw ValidationException::withMessages(['project_id' => 'A change request goes to the project\'s own client.']);
        }

        return $project;
    }

    /**
     * The package line at the package's price, then any extra lines.
     *
     * @param  array<string, mixed>  $data
     */
    private function price(ServicePackage $package, array $data): PriceBreakdown
    {
        $lines = [['description' => $package->name, 'quantity' => 1, 'unit_price_minor' => $package->base_price_minor]];
        foreach ($data['extra_items'] ?? [] as $item) {
            $lines[] = ['description' => $item['description'], 'quantity' => $item['quantity'], 'unit_price_minor' => $item['unit_price_minor']];
        }

        try {
            return ProposalPricing::calculate($lines, $data['discount_type'] ?? 'none', (int) ($data['discount_value'] ?? 0), $data['discount_reason'] ?? null);
        } catch (ValidationException $e) {
            // Line 0 is the package; entered lines start at 1 in the calculator.
            $errors = [];
            foreach ($e->errors() as $key => $messages) {
                $key = preg_replace_callback('/^items\.(\d+)\./', fn ($m) => 'extra_items.'.($m[1] - 1).'.', $key);
                $errors[$key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scope(ServicePackage $package, array $data): array
    {
        return [
            'package' => $package->name,
            'scope' => array_values($data['scope'] ?? []) ?: array_values($package->scope ?? []),
            'exclusions' => isset($data['scope']) ? array_values($data['exclusions'] ?? []) : array_values($package->exclusions ?? []),
            'estimated_days' => $package->estimated_days,
            'revision_limit' => $package->revision_limit,
        ];
    }

    private function writeItems(Proposal $proposal, PriceBreakdown $price): void
    {
        foreach ($price->lines as $position => $line) {
            $proposal->items()->create($line + ['position' => $position]);
        }
    }

    private function lockLatest(Proposal $proposal): Proposal
    {
        $proposal = Proposal::query()->lockForUpdate()->findOrFail($proposal->id);

        if (! $proposal->isLatest()) {
            throw new ProposalRefused("A newer version of {$proposal->reference} exists; work on that one.");
        }

        return $proposal;
    }

    private function closeApproval(Proposal $proposal, string $code, string $message): void
    {
        if ($proposal->approvalRequest) {
            $this->approvals->invalidate($proposal->approvalRequest, $code, $message);
        }
    }

    private function markLeadContacted(Lead $lead, Proposal $proposal, User $actor): void
    {
        $lead->activities()->create([
            'activity_type' => 'proposal_sent',
            'description' => "Proposal {$proposal->label()} sent ({$proposal->delivery_status}).",
            'actor_type' => 'user',
            'actor_id' => $actor->id,
            'metadata' => ['proposal_id' => $proposal->id],
        ]);

        if ($lead->status->canTransitionTo(LeadStatus::Contacted)) {
            $this->leads->changeStatus($lead, LeadStatus::Contacted, $actor, "Proposal {$proposal->label()} sent.");
        }
    }

    private function convertLead(Lead $lead, User $actor): ?int
    {
        if ($lead->customer_id) {
            return $lead->customer_id;
        }

        try {
            return $this->leads->convert($lead, $actor, acceptedProposal: true)->id;
        } catch (InvalidTransition) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function event(Proposal $proposal, string $type, User|Agent|null $actor, array $metadata): ProposalEvent
    {
        return $proposal->events()->create([
            'event_type' => $type,
            'actor_type' => match (true) {
                $actor instanceof Agent => 'agent',
                $actor instanceof User => 'user',
                default => 'system',
            },
            'actor_id' => $actor?->id,
            'metadata' => $metadata === [] ? null : $this->audit->redact($metadata),
        ]);
    }

    private function money(Proposal $proposal): string
    {
        return Money::format($proposal->amount_minor, $proposal->currency);
    }

    private function filledOr(?string $value, ?string $fallback): ?string
    {
        return filled($value) ? $value : $fallback;
    }
}
