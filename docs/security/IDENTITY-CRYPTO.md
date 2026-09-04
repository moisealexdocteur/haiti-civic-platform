# Protection cryptographique de l'identité citoyenne

## Portée

Cette couche protège les identifiants sensibles utilisés par le module
d'identité citoyenne.

Elle ne définit pas encore le format métier du NINU/CIN ni du téléphone.
Les valeurs fournies au service cryptographique doivent déjà avoir été
normalisées par la couche métier responsable de ce format.

Aucune donnée réelle de citoyen n'est utilisée dans les tests.

## Séparation des secrets

Deux secrets maîtres distincts sont utilisés :

- `APP_KEY` : racine des clés de chiffrement ;
- `NINU_HMAC_KEY` : racine exclusivement réservée à l'empreinte HMAC
  déterministe du NINU/CIN.

Les environnements de test utilisent des secrets distincts des secrets du
POC.

Les secrets ne sont jamais stockés dans Git ni dans MariaDB.

## Dérivation des clés

Les clés opérationnelles sont dérivées avec HKDF-SHA-256.

La dérivation inclut :

- le but cryptographique ;
- la version ;
- l'identifiant interne du tenant.

Ainsi, deux tenants n'utilisent pas la même sous-clé opérationnelle même
si le service applicatif repose sur les mêmes secrets maîtres.

## Chiffrement

Le format `v1` utilise XChaCha20-Poly1305 IETF via Sodium.

Chaque chiffrement utilise un nonce aléatoire neuf.

Les données authentifiées associées (AAD) lient le ciphertext à :

- son tenant ;
- l'UUID public de l'identité ;
- le type de champ (`ninu` ou `phone`) ;
- la version cryptographique.

Un ciphertext déplacé vers un autre tenant, une autre identité ou un autre
type de champ doit donc échouer à l'authentification.

Le format externe est une enveloppe textuelle :

`v1.<nonce+ciphertext encodés en Base64 URL-safe>`

Le texte en clair n'est pas inclus dans cette enveloppe.

## Empreinte NINU/CIN

Le NINU/CIN utilise également une empreinte HMAC-SHA-256 déterministe pour
permettre la déduplication sans indexer le NINU/CIN en clair.

L'empreinte est tenant-scopée grâce à une sous-clé dérivée propre au
tenant.

Le même NINU/CIN produit donc :

- la même empreinte dans un tenant donné ;
- une empreinte différente dans un autre tenant.

Cette propriété évite de créer involontairement un identifiant de
corrélation global entre tenants.

Une éventuelle déduplication transversale réglementaire devra être conçue
séparément avec sa propre autorisation et sa propre frontière de
confiance.

## Journalisation

Les journaux d'audit ne doivent jamais contenir :

- le NINU/CIN en clair ;
- le téléphone en clair ;
- les secrets ;
- les clés dérivées ;
- le ciphertext complet si celui-ci n'est pas nécessaire à l'audit.

Les événements d'audit doivent décrire l'opération et les métadonnées
non sensibles nécessaires à la traçabilité.

## Frontière de confiance

Cette protection réduit l'impact d'une fuite de la base de données seule.

Elle ne protège pas contre la compromission complète du runtime applicatif
pendant que celui-ci possède les secrets nécessaires au déchiffrement.

Le mécanisme est versionné pour permettre une rotation ou une évolution
cryptographique future.

La stratégie de rotation des secrets et le stockage persistant des champs
chiffrés seront finalisés avec le modèle de données du Sprint 3.
