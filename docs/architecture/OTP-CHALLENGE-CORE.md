# Coeur des challenges OTP — Sprint 6

## Objectif

Ce composant ajoute la gestion locale et tenant-scopée d'un challenge OTP avant l'intégration d'un fournisseur WhatsApp ou SMS réel.

Il couvre la génération, la persistance sécurisée, l'expiration, le nombre maximal de tentatives, la réémission et la consommation à usage unique.

## Limite de preuve

Un OTP validé démontre uniquement qu'un utilisateur a eu accès au canal associé au numéro de téléphone au moment du challenge. Il ne prouve ni l'identité civile, ni la propriété juridique du numéro.

## Données persistées

La table `otp_challenges` ne stocke jamais le numéro de téléphone ni le code OTP en clair.

Elle contient notamment :

- un UUID opaque ;
- le tenant ;
- le but du challenge ;
- une empreinte HMAC tenant-scopée du téléphone normalisé ;
- un digest HMAC tenant/challenge-scopé du code ;
- le nombre de tentatives et sa limite ;
- le canal demandé ;
- des champs préparés pour le canal réellement utilisé et la référence fournisseur ;
- une empreinte technique optionnelle du demandeur, fournie déjà sous forme SHA-256 ;
- les dates d'expiration, consommation, invalidation et verrouillage.

## Cryptographie

`OtpChallengeCrypto` utilise `APP_KEY` comme secret maître déjà disponible dans l'environnement, avec dérivation HKDF-SHA256 séparée par usage et par tenant.

Les domaines cryptographiques sont distincts :

- `civic.otp.phone-fingerprint.v1` pour le téléphone ;
- `civic.otp.code-digest.v1|challenge:<uuid>` pour le code.

Le code à six chiffres n'est donc pas protégé par un simple SHA-256 non secret. Une copie isolée de la base ne suffit pas à vérifier hors ligne les 1 000 000 possibilités sans disposer aussi du secret applicatif.

Une séparation future vers un secret OTP dédié reste possible sans modifier le modèle fonctionnel.

## Émission

`OtpChallengeService::issue()` :

1. normalise le numéro haïtien avec `IdentityInputNormalizer` ;
2. calcule l'empreinte HMAC du téléphone ;
3. acquiert un verrou MySQL tenant+téléphone pour éviter les émissions concurrentes contournant les limites ;
4. impose au maximum cinq challenges par heure pour un même téléphone et un même but ;
5. impose un délai minimal de 60 secondes avant réémission ;
6. peut appliquer une limite distincte par empreinte technique de demandeur ;
7. invalide l'ancien challenge encore actif lors d'une réémission autorisée ;
8. produit un code aléatoire à six chiffres avec `random_int()` ;
9. persiste uniquement son digest HMAC ;
10. expire le challenge après cinq minutes ;
11. journalise `otp.challenge_created` sans téléphone ni code.

Le code en clair est retourné uniquement au caller afin qu'il soit remis immédiatement au transport. Le service ne le journalise et ne le persiste pas.

## Vérification

`OtpChallengeService::verify()` verrouille la ligne avec `FOR UPDATE` et applique les états dans cet ordre :

- challenge absent dans le tenant : `not_found` ;
- déjà consommé : `consumed` ;
- verrouillé : `locked` ;
- invalidé par réémission : `invalidated` ;
- expiré : `expired` ;
- code incorrect : compteur incrémenté ;
- cinquième échec : verrouillage ;
- code correct : `consumed_at` est défini et la réponse est `accepted`.

Un challenge consommé ne peut pas être réutilisé.

Les événements `otp.challenge_verified` et `otp.challenge_locked` sont audités sans donnée OTP sensible.

## Limites anti-abus initiales

Valeurs du coeur Sprint 6 :

- TTL : 300 secondes ;
- délai minimal de réémission : 60 secondes ;
- cinq challenges par téléphone/tenant/but sur une heure ;
- vingt challenges par empreinte technique de demandeur sur une heure lorsque cette empreinte est fournie ;
- cinq essais de code par challenge.

Ces valeurs sont des garde-fous applicatifs initiaux et pourront devenir configurables par tenant après validation opérationnelle.

## Isolation tenant

L'empreinte du téléphone est dérivée avec une clé différente pour chaque tenant. Le même numéro dans deux tenants ne produit donc pas la même valeur persistée.

La vérification recherche le challenge par `tenant_id + uuid`. Un UUID provenant d'un autre tenant est traité comme inexistant.

## Transport

Le coeur n'appelle encore aucun fournisseur externe.

L'abstraction déjà présente conserve l'ordre de préférence `WhatsApp -> SMS` via `OtpChannelRouter`. Le prochain bloc du Sprint 6 raccordera le challenge à un transport réel et remplira les métadonnées de livraison sans déplacer la logique d'expiration, de limitation ou de vérification dans le fournisseur.

## Tests

`OtpChallengeServiceTest` couvre :

- absence de téléphone/code en clair dans le modèle persistant ;
- code valide à usage unique ;
- verrouillage après cinq codes erronés ;
- expiration ;
- cooldown de réémission ;
- invalidation du challenge précédent ;
- plafond horaire par téléphone ;
- isolation multi-tenant et UUID cross-tenant masqué ;
- absence de code et de téléphone dans les contextes d'audit du challenge.

Les tests existants de `OtpChannelRouter` continuent à couvrir la préférence WhatsApp et le fallback SMS au niveau de l'abstraction de transport.

## Validation VPS du coeur

Validation réalisée le 2 septembre 2026 sur la branche `feature/otp-verification`, HEAD `8f5ecaaeed34fc98c19c0e619037926dc08411d8` :

- lint des nouveaux fichiers : OK ;
- migration `000700` appliquée uniquement sur la base TEST pendant la validation ;
- tests ciblés OTP : `9 tests / 65 assertions` ;
- table `otp_challenges` présente dans TEST ;
- colonnes critiques confirmées, dont empreinte téléphone, digest code, tentatives, expiration, consommation, invalidation et verrouillage ;
- résidus fixtures : `0 / 0` ;
- MAIN non migré et inchangé : `1 identité / 3 documents / 3 événements` ;
- `/health` : OK.

La migration MAIN doit être réalisée séparément, avec sauvegarde préalable, après cette validation.
