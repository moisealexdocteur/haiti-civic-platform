# Architecture

Target architecture:

Internet
  |
Traefik
  |
Application
  |
+-------------------+
| Core              |
| Modules           |
| REST API          |
| PWA               |
+-------------------+
  |
MariaDB

The platform is designed as:

- modular
- multi-tenant
- API-first where appropriate
- server-side rendered by default
- progressive enhancement on the client
- low bandwidth by design
