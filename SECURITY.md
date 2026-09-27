# Security policy

Airworthy – PHP Compatibility & Upgrade Checker is made by digitalkind.ie (https://digitalkind.ie).

## Reporting a vulnerability

Email **security@airworthywp.com**.

Please include the Airworthy version, what an attacker can do, and the steps to reproduce it. Don't report security problems in the public WordPress.org support forum or in public GitHub issues.

You can also report through the Patchstack or Wordfence vulnerability programmes for WordPress. They will pass the report to us.

## What happens next

| When | What we do |
|---|---|
| Within 2 working days | Confirm we received your report. |
| Within 7 days | Tell you whether we can reproduce it and how severe we think it is (CVSS). |
| Within 30 days (critical issues: as fast as possible) | Release a fixed version on WordPress.org. |
| After the fix is out | Publish an advisory and credit you, unless you prefer not to be named. |

We ask you to keep the details private until a fix is released, or for 90 days, whichever comes first. We'll keep you updated if a fix needs longer.

## Supported versions

Only the latest release gets security fixes. WordPress sites update to it through the normal plugin update.

## Scope

In scope: the Airworthy plugin as released on WordPress.org and at https://airworthywp.com, including the libraries it bundles (listed in `docs/sbom.cdx.json` and in the readme). If a problem is in a bundled library, we'll report it to that project too and ship an updated copy.

Out of scope: findings that need an administrator account to exploit, unless they cross a boundary an administrator shouldn't be able to cross (for example on multisite, a site administrator reaching network-level data).

How we handle reports internally, including EU Cyber Resilience Act reporting: [docs/VULNERABILITY-HANDLING.md](docs/VULNERABILITY-HANDLING.md).
