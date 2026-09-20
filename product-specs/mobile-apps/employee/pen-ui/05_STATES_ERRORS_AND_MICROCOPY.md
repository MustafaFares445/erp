# States, Errors & Microcopy

Use plain employee-facing language. Never expose backend enum names, stack errors, IDs, or implementation terms.

## Authentication

Invalid credentials:
- Title: "We couldn't sign you in"
- Body: "Check your login details and try again."

Inactive employee:
- Title: "Account unavailable"
- Body: "Your employee account is not active. Contact your administrator."

Temporary password:
- Title: "Create a new password"
- Body: "For security, change the temporary password before continuing."

Different device:
- Title: "This account is linked to another device"
- Body: "Contact support to reset the registered device before signing in here."

Session expired:
- Title: "Session expired"
- Body: "Sign in again to continue."

## Connectivity

No internet before Check In:
- Title: "Internet connection required"
- Body: "Connect to the internet to start this visit."

No internet before Check Out:
- Title: "Reconnect to complete the visit"
- Body: "Your visit can be completed after pending location data is synced."
## Location and Check In

Permission denied:
- Title: "Location access is required"
- Body: "Allow location access to verify the customer visit and record the visit trail."
- Actions: Open Settings / Cancel.

Missing customer coordinates:
- Title: "Customer location is not configured"
- Body: "Check-in is unavailable until the customer location is updated by the administration."
- Action: Back.

Outside geofence:
- Title: "You're outside the visit area"
- Body: "Move closer to the customer location and try again."
- Action: Check Again.

Successful Check In:
- Short feedback: "Visit started"
- Secondary: "Location tracking is active until checkout."

GPS waiting:
- Label: "Waiting to sync location"
- Do not imply the visit has failed.

## Voice and AI

Recording upload:
- "Uploading recording…"

AI processing:
- Title: "Preparing visit summary"
- Body: "We're transcribing the recording and checking for sales opportunities."

AI failed:
- Title: "Automatic analysis couldn't finish"
- Body: "You can enter the outcome and sales opportunities manually."
- Action: Continue manually.
## Review and Check Out

Review heading:
- "Review before completing the visit"

AI label:
- "AI suggestion"

Employee label:
- "Confirmed by you"

No opportunity:
- "No sales opportunity from this visit"

Missing outcome:
- "Add and confirm the visit outcome to continue."

Opportunity not reviewed:
- "Review the suggested products or confirm that there is no sales opportunity."

GPS pending:
- "Location data is still syncing. Keep the app connected before checkout."

Checkout confirm:
- Title: "Complete this visit?"
- Body: "This records your checkout time and stops location tracking."
- Primary: "Complete Visit"
- Secondary: "Go Back"

Completed:
- Title: "Visit completed"
- Body: show duration and key result, not a generic success-only screen.

## Tasks

Start:
- "Start Task"

Complete:
- "Complete Task"

Completed state:
- "Completed"

Overdue:
- Use "Overdue" with date/time context; avoid alarming language when no action is required.
## Quotations

Draft saved:
- "Quotation saved as draft"

Cannot quote opportunity:
- "Quotation isn't available for this opportunity yet."
- Show the current review/status reason when the backend supplies it.

Server price changed:
- Title: "Price updated"
- Body: "The latest approved price has been applied. Review the quotation before sending."

Expired:
- "Expired"
- Action when allowed: "Create Requote"

## Empty states

Home, no work:
- "You're all caught up"
- "No tasks or visits are scheduled right now."

Tasks:
- "No tasks in this view"

Visits:
- "No visits in this view"

Opportunities:
- "No sales opportunities yet"

Quotations:
- "No quotations yet"

Notifications:
- "No notifications"

Conversation:
- "No messages yet"

## Loading behavior

Prefer skeletons for list/detail data already expected on the screen.
Use a blocking progress state only for state-changing operations such as Check In, final Submit, Check Out, or Send Quotation.
Never leave a primary CTA enabled while its state-changing request is already in flight.
