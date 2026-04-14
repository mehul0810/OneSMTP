# Getting Started

## Prerequisites

- WordPress with plugin activation access
- At least one supported provider account
- Recommended: DNS access for SPF/DKIM/DMARC setup

## Quick Setup

1. Install and activate OneSMTP.
2. Configure your primary provider.
3. Configure a secondary provider for failover.
4. Send a test email from plugin settings.
5. Confirm delivery and provider in Email Logs.

## What Happens After Activation

- OneSMTP prepares required custom database tables.
- Retry workers rely on Action Scheduler.
- Log retention defaults to 30 days (filter-extendable up to 120).
