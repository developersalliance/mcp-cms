# Security Policy

## Reporting security vulnerabilities

If you believe you have found a security vulnerability in MCP CMS, please report it privately rather than opening a public GitHub issue.

Preferred channels:

1. Open a private security advisory on the project's GitHub repository (Security tab, "Report a vulnerability").
2. Or email `security@example.com` with a description of the issue, steps to reproduce, and any proof-of-concept code. Please replace this placeholder with the project owner's real address.

We will acknowledge receipt within a reasonable window and keep you updated as we investigate. Please give us time to release a fix before disclosing publicly.

## Supported versions

MCP CMS is currently on the `0.x` line. There is no long-term-support release yet. Security fixes land on the current `0.x` branch.

| Version | Supported          |
| ------- | ------------------ |
| 0.x     | Yes (current)      |
| < 0.x   | No                 |

## Recent hardening

A security audit was completed in May 2026. The following classes of issue were identified and fixed:

- CRITICAL: preview-endpoint authentication gap (admin preview routes were reachable without a session)
- HIGH: path-traversal in preview endpoints via the `page_id` parameter, and a case-sensitivity bug in the reserved-folder check
- MEDIUM: timing-unsafe MCP token comparison, session ID not regenerated on login, rate-limit JSON read/write without locking, SVG uploads accepted without a complete sanitizer, MCP handler executing tools outside the configured allow-list

See [CHANGELOG.md](CHANGELOG.md) for the per-fix list.
