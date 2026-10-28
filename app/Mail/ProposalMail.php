<?php

namespace App\Mail;

use App\Models\CompanySetting;
use App\Models\Proposal;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The customer-facing proposal: the same document as the preview. */
class ProposalMail extends Mailable
{
    public function __construct(public readonly Proposal $proposal) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Proposal {$this->proposal->reference}: {$this->proposal->title}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.proposal', with: [
            'proposal' => $this->proposal,
            'items' => $this->proposal->items()->get(),
            'company' => CompanySetting::current(),
        ]);
    }
}
