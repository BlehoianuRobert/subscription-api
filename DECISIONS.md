# DECISIONS.md

## What I built and what I skipped

I built all 4 required endpoints plus the optional history one. I also wrote
tests for the cases that I thought were most likely to break things in production.

I skipped authentication because the spec said to assume requests are already
authenticated, so I didn't see the point of adding it.

For multi-tenancy I capture the tenant_id from the X-Tenant-Id header and store
it, but I don't actually filter queries by it. So technically tenant A could
access tenant B's subscription if they knew the ID. I noticed this but decided
it was out of scope for the assignment since the spec said it was optional.

I also didn't build the background job that cancels subscriptions when grace
period expires. The grace_ends_at date is stored so a scheduler could pick it
up, but nothing actually runs automatically. I just didn't have time for it and
it felt like infrastructure work more than logic work.

---

## Ambiguities I found in the spec

**Grace period length.**
The spec says payment failed moves to grace but never says for how long. I
looked it up online and most billing systems use somewhere between 3 and 7 days.
I went with 3 days as the minimum I found. In a real product this would probably
be a config value.

**Whether cancelled is terminal.**
The spec doesn't say. I decided yes, cancelled is permanent. My thinking was
that if a user cancels, that's intentional. A delayed webhook from the carrier
shouldn't silently undo that. If they want to resubscribe they should start a
new subscription.

**What happens to a payment webhook after cancel.**
Also not in the spec. I store it and write an audit record so there's a
financial trail, but the subscription stays cancelled. Feels like the right
call.

**Payment failed during trial.**
The spec doesn't cover this either. I keep the subscription in trialing if the
trial hasn't ended yet because the user isn't being billed during the trial
anyway. It only goes to grace if the trial already expired.

**Repeated failures in grace.**
I don't extend the grace period on repeated failures. If I did, someone could
just keep failing payments forever and stay on grace indefinitely which doesn't
make sense.

---

## Data and storage choices

Three tables: subscriptions, billing_events, and audit_events.

subscriptions holds the current state. billing_events stores every raw webhook
exactly as it came in from the carrier. audit_events stores every decision the
system made and why.

I kept billing_events and audit_events separate on purpose. One is raw data from
outside, the other is internal business logic. Mixing them would make it harder
to debug later.

I used PostgreSQL because it has proper timestamp types with timezone support,
NUMERIC for money so you don't get floating point weirdness like 9.990000001,
and JSONB for the metadata column which lets you query inside the JSON if needed.

One thing I would change: I didn't add a foreign key between subscriptions and
audit_events. That means you could theoretically delete a subscription and leave
orphan audit records behind, or make a mistake and not realise. A foreign key
would prevent that. I noticed this after I had already built it and didn't want
to redo the migrations.

---

## Trade-offs I made

Cancelled is terminal. Makes the race condition simple to handle — one if
statement. The downside is no reactivation, but I think that's fine for this
scope.

No transaction around the billing event insert and subscription update. They're
two separate database calls. If the update fails after the insert, the webhook
looks processed but the subscription wasn't actually updated. I know this is a
gap but kept it simple for the assignment.

No row level locking. With a single PHP process this doesn't matter but in
production with multiple workers two webhooks for the same subscription could
race. I mentioned it in DECISIONS but didn't fix it.

---

## The scenario

Cancel arrives before the webhook.

1. Cancel request comes in first — subscription goes to cancelled.
2. payment.succeeded webhook arrives after — state machine sees cancelled,
   returns ignored_after_cancel.
3. Billing event is stored, audit record is written, subscription stays
   cancelled.

The way I thought about it: the user clicked cancel, that was intentional. The
payment happening at basically the same time was a coincidence. The cancel should
win. If the carrier already charged them that's a refund conversation, not a
reactivation conversation.

This is tested in testCancelThenDelayedPaymentRace.

---

## Edge cases and failure modes

Duplicate webhooks — handled and tested. Check event_id before doing anything
else. Duplicate gets 200 back so the carrier stops retrying.

Cancel then delayed payment — handled and tested. Covered by the scenario above.

Payment failed during trial — handled in the state machine, not tested with a
dedicated test. Would need to inject a past trial_ends_at date which I didn't
build a helper for.

Repeated failures in grace — handled, grace period doesn't extend. No separate
test for this one.

Unknown subscription in webhook — handled and tested. Stores the event anyway
for investigation, returns 404.

Grace expiration — not handled. Needs a background job. Documented it instead.

Concurrent webhooks — not handled. Would need SELECT FOR UPDATE inside a
transaction. Single process for now so it doesn't actually happen, but it's a
real production risk.

---

## What I would do differently with more time

Add the foreign key between subscriptions and audit_events. Right now there's
nothing stopping someone from deleting a subscription and leaving orphan records.
A foreign key with ON DELETE RESTRICT would force you to think before deleting
anything, which is what you want with billing data.

Wrap the billing event insert and subscription update in one transaction so
they're atomic.

Add SELECT FOR UPDATE when reading the subscription in the webhook handler.

Write a dedicated test for payment failed during an active trial. I handle it
in code but never actually tested that specific path.

---

## How I used AI on this assignment

I used Claude. It helped me scaffold the project, set up Slim with PostgreSQL,
and generate test cases.

The hardest part wasn't the code — it was thinking through the state machine
logic and figuring out how each webhook call should connect to the state machine.
The code was mostly AI generated but the logical decisions were mine. I also
corrected the AI where it got things wrong.

One example: the first version of the state machine reactivated a cancelled
subscription when payment.succeeded arrived. That's wrong — cancelled is
terminal by my design. I caught it reading through the logic and fixed it. I
also wrote the test testPaymentSucceededAfterCancelDoesNotReactivate specifically
because of that mistake so it can't quietly come back.