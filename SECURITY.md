# Security policy

## Supported versions

| Version | Filament | Supported |
| --- | --- | --- |
| 2.x | 4 and 5 | Yes |
| 1.x | 4 and 5 | No — upgrade to 2.x ([UPGRADE.md](UPGRADE.md)); 1.x is affected by GHSA-hj3q-8gc4-6mpw |

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it privately through GitHub: [**Report a vulnerability**](https://github.com/andrecorugda/synapse-app-builder/security/advisories/new) (Security tab → Advisories → Report a vulnerability). Include the affected version, the steps or a proof of concept, and the impact you see.

What to expect:

- An acknowledgement within **3 working days**.
- An assessment and, if confirmed, a fix plan within **10 working days**.
- A patched release for every supported line, then a published GitHub Security Advisory (and a CVE where it applies).
- Credit in the advisory, the changelog and the release notes, unless you prefer to stay anonymous. Fixes contributed through the advisory's private fork are merged with your authorship.

## Scope

In scope: the code in this repository as installed through Composer (`andrecorugda/synapse`), including the bundled Filament admin UI and any HTTP routes it registers.

Out of scope: vulnerabilities in Laravel, Filament or other dependencies (report those upstream), and issues that need an already-compromised admin account or server.

Thank you for helping keep Synapse and its users safe.
