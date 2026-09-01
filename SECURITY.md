# Security Policy

This project processes potentially sensitive identity and membership data.

## Rules

1. Never commit production secrets.
2. Never commit real identity documents.
3. Never commit real NINU values.
4. Never use production personal data in automated tests.
5. Sensitive identifiers must not be used as plain database indexes.
6. Administrative actions must be auditable.
7. Access must follow least-privilege principles.
8. Production data exports must be logged and controlled.

Security issues must not be disclosed through public GitHub issues.
