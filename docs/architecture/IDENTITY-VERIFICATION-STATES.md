# États de vérification d'identité

## Objet

Ce document définit la machine d'états minimale du noyau
d'identité du Sprint 3.

Il s'agit d'une règle applicative explicite. Elle ne constitue
pas une règle métier du CEP ou de l'ONI.

## États

### `pending`

État initial d'une identité créée dans la plateforme.

La vérification manuelle n'a pas encore abouti.

`verified_at` doit être `NULL`.

### `verified`

La vérification manuelle de l'identité a abouti.

Le passage vers cet état renseigne `verified_at`.

Pour le Sprint 3, cet état est terminal.

Une éventuelle révocation ou remise en vérification d'une
identité déjà vérifiée nécessitera une politique explicite
ultérieure et n'est pas inventée dans le présent sprint.

### `rejected`

La vérification n'a pas abouti.

Une transition vers cet état exige un `reason_code`
non vide afin de conserver une justification auditable.

`verified_at` doit être `NULL`.

## Transitions autorisées

- `pending -> verified`
- `pending -> rejected`
- `rejected -> pending`

Toutes les autres transitions sont interdites.

Les auto-transitions sont interdites.

## Réouverture

`rejected -> pending` représente uniquement une remise en
vérification après correction ou nouvelle soumission.

Elle ne constitue pas une validation.

## Audit

Chaque transition doit produire :

- un événement dans `identity_verification_events`;
- les valeurs `from_status` et `to_status`;
- le `reason_code` lorsqu'il est requis;
- un événement correspondant dans `audit_logs`.

Aucun NINU/CIN, téléphone, ciphertext ou empreinte HMAC ne doit
être placé dans le contexte d'audit.

## Autorisation

La mutation de l'état est réservée à un acteur disposant de
`identity.manage` dans le tenant courant.

## Limites du Sprint 3

Cette machine ne définit pas :

- la révocation d'une identité déjà vérifiée;
- une expiration automatique;
- une intégration ONI ou CEP;
- une décision biométrique;
- une reconnaissance faciale;
- un workflow politique ou d'adhésion.

Ces comportements nécessitent des règles distinctes avant toute
extension de la machine d'états.
