# Contributing to MCP CMS

Thanks for your interest in improving MCP CMS. This document covers the basics for getting a development copy running and sending changes upstream.

## Development setup

1. Clone the repository.
2. Run a local PHP server pointed at the repo root:

   ```bash
   php -S 0.0.0.0:8080
   ```

3. Visit `http://localhost:8080/install.php` and complete the installer. The installer copies the example config files into place and creates the first admin user.
4. Log in at `http://localhost:8080/admin/`.

No build step, no package manager, no database. PHP 8.0+ with the extensions listed in `README.md` is the entire toolchain.

## Filing an issue

Before opening a new issue, please search existing issues so we don't duplicate work. When you open one, use the bug-report or feature-request template under `.github/ISSUE_TEMPLATE/` and fill in as much detail as you can. For bugs we especially appreciate exact steps to reproduce and your PHP / browser / OS versions.

For anything that looks like a security issue, follow the process in [SECURITY.md](SECURITY.md) instead of filing a public issue.

## Sending a pull request

1. Fork the repository and create a topic branch off `main`.
2. Make your change. Keep PRs focused: one logical change per PR is much easier to review than a sweep of unrelated edits.
3. Run `php -l` over any PHP files you touched. The lint workflow under `.github/workflows/lint.yml` will do this in CI as well.
4. Update `CHANGELOG.md` under the `[Unreleased]` section if your change is user-visible.
5. Open a PR using the template at `.github/PULL_REQUEST_TEMPLATE.md`. Include a clear summary, link any related issue, and describe how you tested the change.

## Tests

There is no automated test suite yet. The current CI step is just `php -l`. Contributions that add a test harness, or even targeted tests around the security-sensitive paths (auth, path resolution, MCP token handling, upload validation), are very welcome.

## Code style

The codebase is not strictly PSR-12 yet, but please match the surrounding style: four-space indentation, opening braces on the same line for control structures, lowercase keywords, snake_case for procedural helpers, PascalCase for classes.

## Code of conduct

Participation in this project is governed by the [Contributor Covenant](CODE_OF_CONDUCT.md).
