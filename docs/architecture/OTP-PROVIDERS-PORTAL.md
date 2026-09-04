# OTP — fournisseurs et raccordement au portail citoyen

## Objet

Ce bloc raccorde le coeur OTP du Sprint 6 au parcours public sans lier la logique métier à un fournisseur unique.

Ordre de préférence :

1. WhatsApp Business Platform / Cloud API direct via Meta ;
2. SMS de secours via un transport configurable ;
3. échec fermé si aucun transport n'est disponible.

Le code OTP reste généré par `OtpChallengeService`. Il n'est jamais stocké en clair dans la base, l'audit, la session ou une URL.

## WhatsApp direct Meta

Le transport `MetaWhatsAppOtpTransport` envoie un modèle d'authentification approuvé à l'endpoint Graph API du numéro WhatsApp Business.

Variables :

- `WHATSAPP_PROVIDER=meta`
- `WHATSAPP_GRAPH_VERSION`
- `WHATSAPP_ACCESS_TOKEN`
- `WHATSAPP_PHONE_NUMBER_ID`
- `WHATSAPP_OTP_TEMPLATE`
- `WHATSAPP_OTP_TEMPLATE_LANG`

Le modèle doit être de catégorie `AUTHENTICATION` et comporter un bouton OTP de type copie du code. Le nom et la langue configurés doivent correspondre exactement au modèle approuvé dans le compte WhatsApp Business.

Le token et les identifiants fournisseur ne sont jamais journalisés.

## SMS de secours

Le premier transport SMS implémenté est Twilio, tout en restant derrière `OtpTransportInterface`.

Variables :

- `SMS_PROVIDER=twilio`
- `TWILIO_ACCOUNT_SID`
- `TWILIO_AUTH_TOKEN`
- `TWILIO_FROM` ou `TWILIO_MESSAGING_SERVICE_SID`

Le message SMS est volontairement court et ASCII afin de limiter le risque de segmentation :

`Code de verification: 123456. Expire dans 5 minutes.`

Le code présent dans le message est nécessaire à la livraison SMS mais n'est jamais écrit dans les logs applicatifs ni l'audit.

## Parcours public

Deux POST CSRF sont ajoutés :

- `/inscription/{tenant}/otp/demander`
- `/inscription/{tenant}/otp/verifier`

La demande :

1. normalise le numéro haïtien ;
2. crée le challenge tenant-scopé ;
3. essaie WhatsApp puis SMS ;
4. enregistre uniquement le canal réellement livré et la référence fournisseur ;
5. conserve en session une preuve dérivée liée au tenant et au challenge.

La vérification correcte marque cette preuve comme vérifiée pour une durée courte.

La soumission finale du dossier appelle `assertVerifiedPhone()` avant toute création d'identité. La preuve est supprimée uniquement après une soumission réussie.

## Anti-abus et confidentialité

- challenge 6 chiffres, TTL 5 minutes ;
- 5 essais maximum ;
- cooldown de réémission 60 secondes ;
- limites horaires par empreinte téléphone et empreinte requête ;
- empreinte requête HMAC avec `APP_KEY`, jamais IP brute en base ;
- code et téléphone absents des contextes d'audit ;
- réponses OTP `Cache-Control: no-store` ;
- challenge UUID jamais placé dans l'URL ;
- preuve session tenant-scopée et liée à l'empreinte du téléphone ;
- l'OTP prouve l'accès au numéro, pas l'identité civile.

## Déploiement

Tant que les variables fournisseur ne sont pas renseignées, laisser `WHATSAPP_PROVIDER` et `SMS_PROVIDER` vides. Le portail échoue alors fermé lors d'une demande OTP réelle.

Ne jamais versionner les tokens, SID ou secrets. Les injecter via le `.env` local du VPS et recréer le conteneur `app`.
