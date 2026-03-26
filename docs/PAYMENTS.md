SoloDrive Payment Architecture

Status: LOCKED

SoloDrive uses Stripe authorization-first payments.

Payments are correlated through attempt records.

1. Attempt Record

CPT:

sd_attempt

Purpose:

Track all Stripe interactions.

## Payment Timing Rule (LOCKED)

Authorization occurs BEFORE ride creation.

Ride must not exist prior to:
- successful payment authorization

Meta includes:

stripe_session_id
stripe_payment_intent
stripe_event_id
tenant_id
lead_id
ride_id (on capture, or autth intent changes after ride created)
quote_id
attempt_status
2. Payment Flow
Passenger accepts quote
       ↓
Stripe Checkout Session created
       ↓
Attempt record created
       ↓
Passenger authorizes payment
       ↓
Webhook received
       ↓
Quote → PAYMENT_PENDING
Ride → CREATED
3. Authorization Model

SoloDrive uses:

manual capture

Meaning:

authorize now
capture after ride completion
4. Capture Workflow

When ride completes:

RIDE_COMPLETE
   ↓
Capture PaymentIntent
   ↓
Record capture timestamp

Capture module:

payments-capture.php
5. Webhook Correlation

Stripe webhooks resolve events to attempts using:

payment_intent
checkout_session
event_id

Attempt record acts as canonical correlation layer.