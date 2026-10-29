# AVIA Attribution

### WordPress · Boulevard CRM · Google Ads

An attribution integration connecting advertising clicks with CRM bookings, appointment outcomes, and collected revenue.

**Developed by:** PROS Global Inc × Drunall Technologies  
**Client:** AVIA Medical Spa LLC  
**Project type:** WordPress Plugin & Marketing Integration  

> This repository documents the integration. Application source code, credentials, client records, and account configuration are not included.

## Overview

AVIA Attribution connects a WordPress website, Boulevard CRM, and Google Ads.

The integration captures advertising identifiers on website visits, passes attribution context into the booking workflow, processes CRM lifecycle events, and prepares eligible revenue-based offline conversions for upload.

Its purpose is to connect marketing activity with completed business outcomes rather than relying only on booking-button clicks or form submissions.

## Business Challenge

Website analytics and CRM records often describe different parts of the customer journey.

For this project, the main challenges were:

- Preserving attribution when visitors open an external booking experience.
- Connecting a website visit with a Boulevard client and appointment.
- Distinguishing booking intent from completed appointments.
- Associating collected revenue with eligible advertising clicks.
- Handling cancellations, duplicate events, and failed uploads.
- Giving operators visibility into the attribution and upload process.

## Solution

A custom WordPress plugin acts as the integration layer between website activity, Boulevard events, and Google Ads conversion ingestion.

The implementation combines:

- First-party attribution capture.
- Booking-widget parameter forwarding.
- Boulevard API integration.
- Verified webhook processing.
- An attribution event ledger.
- Revenue extraction and conversion eligibility checks.
- Offline conversion uploads.
- Operational dashboards and diagnostics.

## Main Capabilities

### 1. First-Party Attribution Capture

The website capture layer records available attribution context, including:

- Google click identifiers: `gclid`, `gbraid`, and `wbraid`.
- UTM source, medium, campaign, term, and content.
- Landing page and referrer.
- A generated attribution identifier.

The identifier connects website sessions with downstream CRM events.

Persistence depends on browser restrictions, consent settings, and the deployed configuration. A configured cookie lifetime does not guarantee retention in every browser.

### 2. Boulevard Booking Bridge

The booking bridge passes available attribution parameters into the Boulevard booking widget.

It also exposes booking-intent events through the data layer, allowing the marketing team to distinguish:

- Attribution capture.
- Book Now clicks.
- Booking-widget openings.

These events describe website activity and are separate from completed appointment outcomes.

### 3. CRM Attribution Write-Back

The Boulevard Admin GraphQL API integration writes attribution context into client custom fields.

This makes selected attribution identifiers available within the CRM and supports correlation between website sessions and client records.

The integration also retrieves available client tags and referral context for operational reporting.

### 4. Appointment & Order Webhooks

Webhook processing records CRM lifecycle events, including:

- Client creation and updates.
- Appointment creation.
- Appointment completion.
- Appointment cancellation.
- Completed orders.
- Referral events.

The documented implementation includes signature verification and location-aware event handling.

### 5. Revenue-Based Offline Conversions

Eligible completed outcomes are prepared for upload through the Google Data Manager API integration.

Conversion records include the applicable:

- Advertising click identifier.
- Conversion timestamp.
- Revenue value.
- Currency.
- Transaction identifier.

This connects eligible advertising interactions with recorded business outcomes.

API acceptance, matching, attribution, and reporting remain subject to Google's processing and account configuration.

### 6. Conversion Eligibility & Integrity

The integration includes checks intended to keep unrelated or duplicate records out of the upload workflow.

These include:

- Skipping records without the required advertising identifiers.
- Excluding ineligible cancelled outcomes.
- Tracking transaction identifiers for deduplication.
- Reviewing repeated events and checkout records.
- Recording validation and processing failures.

A missing click identifier means a record is ineligible for this click-based upload path; it does not conclusively prove that the customer came from organic traffic.

### 7. Upload Queue & Retries

Operators can review conversion processing states.

| Status | Meaning |
| --- | --- |
| Pending | Waiting for processing or upload |
| Uploaded | Recorded as uploaded by the integration |
| Skipped | Did not meet the upload eligibility rules |
| Failed | Encountered an error requiring retry or review |

The upload workflow includes retry handling for transient failures.

An uploaded status is distinct from confirmation that Google Ads has attributed and reported the conversion.

### 8. Reporting Dashboard

The dashboard combines advertising reporting and CRM-derived operational information.

Documented views include:

- Advertising spend, clicks, impressions, and CPC.
- Conversion upload counts and values.
- CRM-collected revenue.
- Campaign performance.
- Pending and failed uploads.
- Individual attribution records.

CRM revenue, uploaded conversion value, and Google Ads-reported conversion value are separate measures and should be interpreted accordingly.

### 9. Event Journey & Diagnostics

A conversion details view helps operators inspect the journey behind a record.

It includes:

- Related CRM identifiers.
- Attribution information.
- Custom-field write-back status.
- A chronological event history.
- Upload status and error details.
- Links to the related Boulevard record.

### 10. Maintenance

Scheduled maintenance supports:

- Webhook log retention.
- Version-specific data migrations.
- Integrity checks.
- Supported revenue backfill operations.

## Technology Stack

| Area | Technologies |
| --- | --- |
| Platform | WordPress |
| Backend | PHP |
| Frontend Capture | JavaScript |
| Database | WordPress database tables |
| CRM Integration | Boulevard Admin GraphQL API |
| CRM Authentication | OAuth-based integration |
| Event Processing | REST webhooks |
| Webhook Verification | HMAC SHA-256 |
| Conversion Ingestion | Google Data Manager API |
| Advertising Reporting | Google Ads API |
| Website Events | Google Tag Manager data layer |
| Scheduled Tasks | WP-Cron |

## End-to-End Workflow

1. A visitor arrives with available advertising and campaign parameters.
2. The website creates an attribution identifier and records session context.
3. The booking bridge forwards attribution parameters into Boulevard.
4. CRM events connect client and appointment records with attribution context.
5. Appointment and checkout events update the ledger.
6. Eligibility rules determine whether a conversion should be queued.
7. The integration submits eligible conversion records.
8. Operators review upload states, errors, and reporting.

## Engineering Challenges

### Crossing the Booking Boundary

The implementation carries website attribution context into a separately hosted booking experience.

### Delayed Business Outcomes

Appointment completion and payment can occur after the initial visit. The integration therefore relies on CRM events rather than website activity alone.

### Event Correlation

Session, client, appointment, order, and location identifiers connect events originating from different systems.

### Repeated & Out-of-Order Events

Webhook delivery and processing can involve repeated or differently timed events. The ledger and transaction identifiers support reconciliation and deduplication.

### Operational Visibility

Status tracking and event timelines help distinguish capture issues, correlation gaps, validation failures, and upload errors.

## Functional Outcomes

The integration provides:

- A connection between website attribution and CRM records.
- Visibility into booking and appointment lifecycle events.
- Revenue-based conversion preparation.
- Eligibility checks before uploads.
- Retry and diagnostic workflows.
- Reporting across advertising and CRM activity.


## Data Handling & Public Documentation

This repository contains no patient records, appointment details, advertising click identifiers, credentials, or raw webhook payloads.

Data collection, retention, consent, and advertising eligibility require deployment-specific review. Hashing or hiding identifiers alone does not establish legal or platform-policy compliance.

## Repository Scope

This is a documentation-only repository.

It does not distribute the WordPress plugin or provide installation files. Software licensing is governed separately by the applicable project license and agreements.

## Credits

**Developed by:** PROS Global Inc × Drunall Technologies  
**For:** AVIA Medical Spa LLC
