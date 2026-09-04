# Architecture

## Architecture cible

La plateforme suit une architecture légère :

```text
Internet
  |
Traefik
  |
Application CodeIgniter
  |
+---------------------------+
| Noyau multi-tenant        |
| Modules                   |
| API REST                  |
| PWA                       |
+---------------------------+
  |
MariaDB
```

Principes actuels :

- architecture modulaire ;
- isolation multi-tenant ;
- rendu côté serveur par défaut ;
- amélioration progressive côté client ;
- faible consommation de bande passante ;
- séparation entre noyau et modules métier ;
- sécurité appliquée à la fois dans l'application et dans MariaDB.

## Documentation du noyau

- [Modèle de données du noyau](CORE-DATA-MODEL.md)
- [ADR-0001 : Isolation des tenants](ADR-0001-TENANT-ISOLATION.md)
- [Modèle de sécurité du journal d'audit](../security/AUDIT-LOG.md)
- [Modèle de menaces](../security/THREAT-MODEL.md)

Le noyau actuel fournit le contexte tenant, l'autorisation tenant-scopée,
des modèles de lecture restreints, des contraintes relationnelles
multi-tenant et les fondations du journal d'audit.
