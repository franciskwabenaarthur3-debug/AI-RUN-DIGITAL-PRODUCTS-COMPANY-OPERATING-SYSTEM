# Workflows

Only implemented workflows are described here.

## Lead lifecycle

Defined in `App\Enums\LeadStatus` and enforced by `App\Services\LeadService`. Any transition not listed is refused, both in the UI (only valid targets are offered) and on direct requests.

| From | Allowed next states |
| --- | --- |
| new | researched, qualified, disqualified, unsubscribed\*, lost |
| researched | qualified, disqualified, unsubscribed\*, lost |
| qualified | outreach_drafted, converted\*\*, disqualified, unsubscribed\*, lost |
| outreach_drafted | contacted, qualified, unsubscribed\*, lost |
| contacted | replied, converted\*\*, unsubscribed\*, lost |
| replied | converted\*\*, unsubscribed\*, lost |
| disqualified | new (re-open) |
| lost | qualified (re-open) |
| converted | none (terminal) |
| unsubscribed | none (terminal) |

\* Only through the **opt-out** action, which also sets `do_not_contact`, records the reason and clears the follow-up.
\*\* Only through **conversion**, which creates the customer.

Rules:

- A lead with `do_not_contact` set can never move into `outreach_drafted` or `contacted`, regardless of its status.
- Every change writes a `lead_activities` row; status changes, opt-outs and conversions also write `audit_logs`.
- Status changes, opt-out and conversion lock the lead row (`SELECT … FOR UPDATE`) so concurrent requests are serialized.

## Duplicate detection

On create and edit, a lead is rejected if another lead has the same email (case-insensitive) or the same normalized website domain (`https://www.Example.com/x` and `example.com` match). The error names the existing lead. Unique indexes on `email` and `website_domain` back this up at the database level.

Not yet checked: duplicates between leads and existing customers, and fuzzy company-name matches.

## Lead → customer conversion

1. Allowed from `qualified`, `contacted` or `replied`, and requires an explicit confirmation checkbox.
2. Inside a transaction the lead is locked. If it already has a `customer_id`, that customer is returned and nothing is created (safe against double clicks and retries).
3. Otherwise a customer is created from the lead's contact details, the lead is set to `converted` with `customer_id` and `converted_at`, and activity and audit records are written.
4. `leads.customer_id` is unique, so one customer can never be linked to two leads.

## Follow-ups

`next_follow_up_at` can be set on the lead form or from the lead page. The dashboard and the "Follow-up due" filter count leads whose follow-up time has passed, excluding converted, unsubscribed, lost and disqualified leads. No reminders are sent yet.
