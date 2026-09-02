# ADR-0001 : Isolation des tenants par contexte explicite

Statut : accepté

## Contexte

La plateforme est multi-tenant. Une fuite de données entre tenants
constituerait une défaillance de sécurité majeure.

Ajouter manuellement `tenant_id` à chaque requête n'est pas une
frontière de sécurité suffisante.

## Décision

L'accès tenant-scopé utilise un `TenantContext` explicite.

Le contexte :

- démarre sans tenant résolu ;
- échoue fermé si un identifiant est demandé avant résolution ;
- accepte uniquement un identifiant interne positif.

Les modèles tenant-scopés utilisent la composition plutôt que
l'héritage du modèle générique de CodeIgniter.

Le Query Builder reste interne et applique toujours le tenant courant.

`AuthorizationService` obtient lui aussi le tenant depuis ce contexte
et non depuis un paramètre arbitraire de l'appelant.

Le schéma MariaDB impose en parallèle des contraintes tenant-aware sur
les relations représentant une frontière de sécurité.

## Conséquences

Avantages :

- absence de tenant = échec explicite ;
- impossibilité de sélectionner un autre tenant par simple paramètre ;
- pas d'API générique d'écriture exposée par les modèles de lecture ;
- défense en profondeur entre PHP et MariaDB.

Contraintes :

- moins de CRUD générique ;
- chaque nouvelle opération tenant-scopée nécessite une API explicite ;
- les écritures futures devront passer par des services spécialisés.

## Résolution du tenant

Le mécanisme HTTP définitif n'est pas encore fixé.

Le tenant pourra être résolu depuis un domaine contrôlé, une session
authentifiée ou une autre association vérifiée côté serveur.

Un paramètre brut tel que `tenant_id=123` ne doit jamais constituer à
lui seul la source d'autorité.
