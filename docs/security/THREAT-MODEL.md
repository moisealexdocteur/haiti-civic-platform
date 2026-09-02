# Modèle de menaces

## Menaces principales

- vol d'identifiants ;
- prise de contrôle de compte ;
- accès inter-tenant non autorisé ;
- énumération d'identifiants ;
- inscriptions en double ;
- abus OTP ;
- vol de documents ;
- export non autorisé ;
- élévation de privilèges ;
- administrateur malveillant ;
- compromission des identifiants MariaDB ;
- altération du journal d'audit ;
- perte d'appareil de terrain ;
- réseau public non sécurisé ;
- falsification de localisation ;
- collecte automatisée ;
- déni de service.

## Contrôles déjà implémentés

### Isolation tenant

- `TenantContext` explicite et fail-closed ;
- modèles de lecture tenant-scopés ;
- autorisation tenant-scopée ;
- contraintes relationnelles tenant-aware ;
- absence d'API générique d'écriture sur les modèles de lecture.

### Autorisation

- utilisateur actif requis ;
- appartenance active au tenant requise ;
- rôles tenant-scopés ;
- catalogue explicite de permissions.

### Audit

- chaîne SHA-256 par tenant ;
- JSON canonicalisé ;
- verrou MariaDB par tenant pour sérialiser les écritures ;
- validation de l'acteur ;
- `UPDATE` interdit sur `audit_logs` ;
- `DELETE` interdit sur `audit_logs` ;
- suppression physique d'un acteur référencé interdite ;
- service de vérification de chaîne.

### Privilèges MariaDB

L'architecture de test valide deux comptes :

- un compte de migration disposant des privilèges DDL ;
- un compte runtime limité à `SELECT`, `INSERT`, `UPDATE`, `DELETE`.

Le compte runtime de production devra suivre le même principe avant le
durcissement de production.

## Limites

La chaîne d'audit est tamper-evident, mais n'est pas un registre externe
immuable.

MariaDB `root`, le compte de migration ou un administrateur de l'hôte
peut contourner les protections locales.

Les contrôles liés à l'identité citoyenne, OTP, chiffrement des
documents, appareils de terrain, exports et protections anti-abus seront
traités dans les modules correspondants.
