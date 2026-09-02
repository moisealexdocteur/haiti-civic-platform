# Modèle de sécurité du journal d'audit

## Objectif

Le journal d'audit fournit un historique append-only et tenant-scopé des
actions sensibles.

Il vise à empêcher le compte runtime normal de réécrire l'historique et
à rendre certaines altérations détectables.

Il ne constitue pas un registre externe immuable.

## Chaîne par tenant

`AuditService` :

1. récupère le tenant depuis `TenantContext` ;
2. valide l'acteur ;
3. prend un verrou MariaDB propre au tenant ;
4. lit le dernier `entry_hash` ;
5. l'utilise comme `prev_hash` ;
6. canonicalise les données ;
7. calcule un SHA-256 ;
8. insère la ligne ;
9. valide la transaction ;
10. libère le verrou.

La conception utilise ce verrou pour sérialiser les écritures
concurrentes d'un même tenant. Ce comportement doit encore faire l'objet
d'un test de concurrence ou de charge dédié avant d'être considéré comme
validé expérimentalement.

## Validation de l'acteur

Pour `actor_type = user`, `actor_user_id` est obligatoire.

L'utilisateur doit être actif et posséder une appartenance active au
tenant courant. Un utilisateur d'un autre tenant est refusé.

## Protection append-only dans MariaDB

La migration
`2026-09-02-000300_HardenAuditLogIntegrity`
installe :

- `trg_audit_logs_no_update` ;
- `trg_audit_logs_no_delete`.

Elle remplace également la clé étrangère de l'acteur par
`fk_audit_actor_restrict` avec `ON DELETE RESTRICT`.

La migration
`2026-09-02-000400_RestrictAuditForeignKeyUpdates`
complète ce durcissement en imposant `ON UPDATE RESTRICT` sur :

- `fk_audit_actor_restrict` ;
- `fk_audit_tenant`.

`actor_user_id` et `tenant_id` participent au calcul de `entry_hash`.
Une mise à jour en cascade de l'une de ces valeurs modifierait donc
rétroactivement une entrée déjà hachée.

Ces restrictions empêchent les mises à jour de clés parentes de modifier
implicitement ces colonnes du journal.

La suppression physique de l'acteur et du tenant référencés reste
également en `ON DELETE RESTRICT`.

## Séparation des comptes MariaDB

Le compte runtime doit être limité à :

- `SELECT` ;
- `INSERT` ;
- `UPDATE` ;
- `DELETE`.

Il ne doit pas recevoir `ALTER`, `CREATE`, `DROP`, `TRIGGER` ni
`ALL PRIVILEGES`.

Le compte de migration possède les privilèges DDL nécessaires au schéma
mais ne doit pas être utilisé par l'application en fonctionnement
normal.

## Vérification

`AuditService::verifyCurrentTenantChain()` contrôle dans l'ordre :

- le `prev_hash` attendu ;
- le format du `entry_hash` ;
- le recalcul du SHA-256 de chaque entrée.

## Limite de confiance

Le journal est tamper-evident dans la frontière du compte runtime.

Il n'est pas immuable face à un attaquant contrôlant MariaDB `root`, le
compte de migration ou l'hôte. Un tel attaquant pourrait retirer les
protections et recalculer une nouvelle chaîne cohérente.

La formulation correcte est donc :

**journal applicatif append-only et détectable en cas d'altération dans
la frontière du runtime**.

## Durcissement futur

Des versions ultérieures pourront ajouter :

- points de contrôle signés ;
- exports chiffrés hors hôte ;
- stockage immuable ou Object Lock ;
- service de journalisation indépendant ;
- surveillance des changements de schéma et de triggers.

## Données sensibles

`context_json` ne doit pas contenir inutilement de documents d'identité
bruts, secrets d'authentification ou valeurs personnelles sensibles.
