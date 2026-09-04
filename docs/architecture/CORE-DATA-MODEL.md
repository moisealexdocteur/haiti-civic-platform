# Modèle de données du noyau

## Portée

Ce document décrit le noyau multi-tenant avant l'ajout des modules liés
à l'identité citoyenne et aux processus électoraux.

Aucun NINU, document CIN, numéro de téléphone ou profil politique
individuel ne fait partie de cette couche.

## Frontière tenant

`tenants` constitue la frontière logique principale d'isolation.

`TenantContext` fonctionne en refus par défaut : aucun accès tenant-scopé
n'est possible tant qu'un tenant interne valide n'a pas été résolu.

Les modèles tenant-scopés ne reçoivent pas un `tenant_id` arbitraire
fourni par le client.

## Entités principales

- `tenants` : tenants de la plateforme.
- `organizations` : organisations appartenant à un tenant.
- `users` : identités utilisateur globales.
- `tenant_users` : appartenance d'un utilisateur à un tenant.
- `roles` : rôles rattachés à un tenant.
- `permissions` : catalogue global des permissions.
- `role_permissions` : permissions attribuées aux rôles.
- `user_roles` : rôles attribués aux utilisateurs dans un tenant.
- `modules` : catalogue des modules.
- `tenant_modules` : activation des modules par tenant.
- `audit_logs` : journal des opérations sensibles.

## Lecture tenant-scopée

Les modèles de lecture utilisent la composition plutôt que l'héritage
de `CodeIgniter\Model`.

L'API volontairement restreinte comprend actuellement :

- `find()` ;
- `findAll()` ;
- `first()` ;
- `countAllResults()`.

Le Query Builder interne applique systématiquement le tenant courant.
Les modèles de lecture n'exposent pas les méthodes génériques
`insert`, `update`, `save`, `delete`, `replace` ni un builder public.

## Autorisation

`AuthorizationService` utilise le `TenantContext` actif.

Une permission exige notamment :

- un utilisateur actif ;
- une appartenance active au tenant courant ;
- un rôle du même tenant ;
- une permission attribuée à ce rôle.

## Isolation relationnelle

MariaDB impose également des contraintes empêchant certaines relations
inter-tenant invalides.

L'isolation repose donc sur deux niveaux complémentaires :

1. scoping applicatif ;
2. intégrité relationnelle MariaDB.

## Futures écritures

Les futures opérations d'écriture devront passer par des services
explicites qui :

1. utilisent le tenant provenant du contexte fiable ;
2. vérifient les autorisations ;
3. vérifient l'appartenance de la ressource au tenant ;
4. exécutent une opération métier limitée ;
5. journalisent les opérations sensibles.
