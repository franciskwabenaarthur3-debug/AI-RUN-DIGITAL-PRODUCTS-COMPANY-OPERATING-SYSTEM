# User manual

For the founder (and operators) running the company day to day in Company OS. It describes what the app does today. Anything not described here, Company OS does not do for you.

## Who can do what

| | Founder | Operator | Viewer |
| --- | --- | --- | --- |
| Read leads, customers, proposals, projects, maintenance | yes | yes | yes |
| Create and change leads, customers, proposals, projects, releases, visits | yes | yes | no |
| Approve anything, send proposals, record production deployments | yes | no | no |
| Service packages, maintenance plans and prices, settings, agents, billing details, audit log | yes | no | no |
| Reports, costs, System page | yes | yes | no |

Accounts are created on the server with `php artisan company-os:create-user`. There is no sign-up page.

## Signing in

Enter your email and password, then the six-digit code from your authenticator app. Founders set this up on first sign-in: on **Account security**, choose **Set up two-step sign-in**, scan the QR code with an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password and others work), and enter the code it shows. Save the eight recovery codes somewhere safe: each lets you in once if you lose your phone. Change your password on the same page.

Lost your phone and your recovery codes? Someone with server access runs `php artisan company-os:reset-two-factor your@email` after checking it is you.

## A daily routine

1. **Dashboard:** approvals waiting, alerts, maintenance due, overdue milestones and the recommended priorities.
2. **Approvals:** decide each request. Read the exact payload: approving allows exactly that, once.
3. **Alerts:** deal with critical ones first, then acknowledge them.
4. **System** (weekly, or when an alert says so): every line should be OK.

## Leads and customers

Add leads by hand or let the Research Agent create them with their sources. Move a lead through researched, qualified, outreach drafted, contacted and replied. Outreach drafts are only drafts: you send messages yourself. A lead marked do-not-contact is never contacted again. Convert a lead to a customer when they agree to work with you; it happens once.

## Agents

Agents start disabled. On **Agents**, the founder enables an agent, grants the tools it may use, and sets its runtime (the mock runtime for testing, `llm` for a real model when an API key is set), model and limits. Give an agent work under **Tasks**, queue it, and review the result. Results that need review wait for you to accept or reject them. Agents cannot send anything, spend beyond the budget, approve anything or change their own permissions. Settings → **Paused** stops all agent work and approved actions at once.

## Proposals

1. Create a proposal from a service package (or let the Sales Agent draft one). Prices come from the package and the lines you add; discounts have a limit and need a reason.
2. **Submit** it. The version is frozen and an approval request is created.
3. As founder, approve it under Approvals after checking the preview.
4. **Send**: by email if email is configured, or send it yourself and record that you did.
5. Record the client's answer with the evidence (for example "replied yes by email on 2 Nov"). Changing a sent proposal means a new version and a new approval.

Accepting a proposal creates its project, once.

## Projects and releases

The project comes with milestones and tasks from the package's delivery template. Assign tasks to people or agents and track blockers, deliverables and the handover checklist. Requests outside the agreed scope become change-request proposals.

Releases record what you deployed with your own tools. Record each quality check with its evidence. A staging release is marked deployed, then checked (health check or by hand). Production needs every mandatory check passed, the same version verified on staging, the client's approval and the founder's approval of the exact release; then the founder records the deployment and runs the health check. A verified production release marks the project delivered. If something goes wrong, follow the rollback plan and record the rollback.

## Maintenance

From a delivered project, **Offer a maintenance plan**: price, billing frequency, how often to visit, the checklist and the term. Visits appear a week before each service date. On a visit: **Start**, record a result for every checklist item, run the health check, and record findings. Checks must not change the site. If fixing something changes the live site or costs the client extra, mark the finding as risky and request approval with the exact change and how to undo it; make the change only after the founder approves, then record it. **Complete** the visit with evidence of what was done; the record is then frozen and the service report can be printed for the client. Renewal reminders appear 30 days before the term ends; record the renewal when the client agrees.

## Reports

**Reports** shows the day, week or month so far, counted only from records in Company OS, with stored copies of each finished period. Invoices, payments, expenses other than AI usage, hosting and support are not recorded, so the report lists them as not recorded rather than guessing. Agreed proposal values and plan prices are not money received. **AI costs** shows model usage by month, agent and model. The recommended priorities are suggestions from fixed rules.

## What Company OS does not do

It does not deploy websites, change client sites, send outreach, take payments, issue invoices, email alerts or copy backups off the server. People do those things with their own tools, and Company OS records them.
