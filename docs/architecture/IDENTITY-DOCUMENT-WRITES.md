# Écriture des documents de vérification

Le Sprint 3 reconnaît trois types de documents :

- `cin_front`
- `cin_back`
- `portrait`

`portrait` sert uniquement à la vérification manuelle.
Aucune reconnaissance faciale n'est réalisée.

## Frontière de stockage

MariaDB ne contient jamais le binaire du document.

La table `verification_documents` conserve uniquement :

- une référence opaque `storage_ref`;
- le type;
- la révision;
- le type MIME éventuel;
- la taille éventuelle;
- une empreinte SHA-256 éventuelle;
- la date de capture éventuelle;
- le statut.

Le stockage documentaire réel devra être chiffré et séparé
de MariaDB en production.

## Révisions

Chaque nouvelle soumission d'un même type de document crée une
nouvelle révision :

`tenant + identité + type + revision_no`

La révision précédente n'est pas écrasée.

## Autorisation et isolation

Toute écriture requiert `identity.manage`.

L'identité cible doit appartenir au tenant courant.

Le service verrouille l'identité et utilise la même convention
de verrou d'audit tenant que les autres services d'écriture.

## Audit

L'enregistrement d'un document crée :

- `identity.document_registered`
  dans `identity_verification_events`;
- `citizen_identity.document_registered`
  dans `audit_logs`.

Le contexte d'audit ne contient pas :

- le NINU/CIN;
- le téléphone;
- `storage_ref`;
- l'empreinte SHA-256 du fichier;
- le contenu du document.

Si l'écriture d'audit échoue, l'enregistrement du document et
son événement sont annulés dans la même transaction.
