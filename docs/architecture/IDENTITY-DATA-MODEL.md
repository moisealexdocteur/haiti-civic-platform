# Modèle de données — identité citoyenne

## Portée

Cette couche stocke uniquement les éléments nécessaires à l'identité
citoyenne et à sa vérification.

Elle ne contient aucune donnée d'opinion politique, d'idéologie ou de
ciblage individuel.

## citizen_identities

Chaque identité appartient exactement à un tenant.

Le NINU/CIN n'est jamais stocké en clair.

Deux représentations sont conservées :

- `ninu_ciphertext` : valeur protégée par la couche cryptographique ;
- `ninu_fingerprint` : HMAC-SHA-256 déterministe et tenant-scopé.

La contrainte unique `(tenant_id, ninu_fingerprint)` empêche un doublon
dans le même tenant.

Elle ne constitue volontairement pas une déduplication globale entre
tenants.

Le téléphone est stocké uniquement sous forme chiffrée.

Le consentement comporte au minimum une version et un horodatage.

## verification_documents

Les binaires CIN ou portrait ne sont jamais stockés dans MariaDB.

La table contient uniquement :

- une référence opaque de stockage ;
- un type de document ;
- un numéro de révision ;
- des métadonnées techniques minimales ;
- éventuellement un SHA-256 d'intégrité du binaire.

Plusieurs révisions d'un même type de document sont possibles.

## identity_verification_events

Cette table constitue l'historique métier de la vérification.

Elle est distincte de `audit_logs`, qui reste le journal de sécurité
tamper-evident de la plateforme.

Un événement peut provenir :

- d'un utilisateur du tenant ;
- d'un processus système, auquel cas `actor_user_id` reste NULL.

Lorsqu'un acteur utilisateur est fourni, la clé étrangère composite impose
son appartenance au même tenant.

`context_json` ne doit jamais contenir de NINU/CIN, téléphone, secret ou
document binaire.

## Isolation relationnelle

Les relations document → identité et événement → identité utilisent des
clés étrangères composites incluant `tenant_id`.

Une ligne d'un tenant ne peut donc pas pointer vers l'identité d'un autre
tenant.

## Suppression

Les identités et leurs preuves ne sont pas supprimées implicitement par
cascade lors de la suppression d'un tenant.

Les clés étrangères utilisent `RESTRICT`.

La politique de rétention, d'anonymisation et d'effacement explicite sera
traitée séparément avant la production.
