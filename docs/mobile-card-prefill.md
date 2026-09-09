# Lecture de carte depuis le portail mobile

Le bouton précédent utilisait `BarcodeDetector` puis `TextDetector` dans le navigateur. Aucun appel au moteur OCR du serveur ne suppléait leur absence. Le message « téléphone non compatible » reflétait cette dépendance logicielle, pas la capacité matérielle du téléphone. `TextDetector` reste une proposition expérimentale : https://wicg.github.io/shape-detection-api/text.html.

Le correctif utilise la prise de photo existante, convertit l’image en JPEG (côté long 1 600 pixels, qualité 0,92), puis appelle une route POST avec CSRF. Une confirmation et les textes français/créole indiquent que la photo est envoyée au serveur. La route limite les appels par IP (4/minute), accepte au plus 2 Mio en JPEG/PNG et partage le verrou OCR non bloquant avec les inscriptions. La lecture est locale au serveur, sans fournisseur OCR externe. Le fichier temporaire est supprimé après le traitement; aucune recherche en base, inscription, validation ou journalisation du contenu OCR n’est effectuée par cette route. Les rejets de téléversement sont nettoyés par PHP en fin de requête.

La reconnaissance réutilise le repérage de carte et la politique de confiance du NINU. Les noms sont associés à leurs libellés par position, avec une deuxième lecture de la colonne des noms. Une tolérance aux erreurs OCR porte sur le libellé « prénom », jamais sur la valeur d’un nom ou du NINU. Les valeurs ambiguës ou trop peu confiantes restent vides. Ce sont des suggestions modifiables, pas une authentification ONI. Aucun modèle n’a été entraîné sur les données personnelles du lot.

## Mesures locales du 9 septembre 2026

Onze photos fournies ont été traitées après réduction et réencodage JPEG simulant le navigateur (Pillow; le codec exact du téléphone peut différer). Carte repérée : 9/11. Temps moteur : minimum 3,43 s, médiane 5,47 s, maximum 12,16 s. Une passe par photo pour cette configuration finale; le lot a servi à régler les règles, ce n’est pas un jeu indépendant de validation. Ces temps excluent la prise de photo, l’envoi réseau, PHP et la charge du VPS.

Six rectos visibles dans la conversation ont une référence visuelle vérifiée pour les trois champs :

| Champ | Exact | Vide | Erroné |
|---|---:|---:|---:|
| NINU | 4/6 | 2/6 | 0/6 |
| Prénom | 3/6 | 3/6 | 0/6 |
| Nom | 3/6 | 3/6 | 0/6 |

Les trois champs sont exacts ensemble sur 3/6. Cela ne justifie pas une promesse de lecture complète de toute carte. Les cinq autres photos entrent dans le chronométrage mais pas dans ce tableau d’exactitude des noms. Aucune photo, transcription ou valeur personnelle du benchmark n’est publiée.

## Vérification et limites

115 tests PHP unitaires (2 318 assertions), 9 tests Python et 3 tests JavaScript réussis localement. Les nouveaux tests couvrent le transport sans détecteurs natifs, les erreurs réseau/délai/limitation, la séparation des noms entre colonnes, les champs incertains et les NINU contradictoires. Les tests ne constituent pas des essais sur un vrai iPhone 17 Pro ou Galaxy S25.

À vérifier sur les deux appareils avant mise en service : caméra et galerie, lecture des trois champs, modification manuelle, Continuer/Retour, photo floue, interruption réseau, HEIC non décodable et conservation du parcours d’inscription. Une image non décodable doit inviter à choisir un JPEG/PNG ou à saisir les champs. Mesurer alors le temps clic-résultat sur le réseau réel et la consommation du VPS.

Le correctif ne change pas la règle de doublon ni son ordre de vérification. Aucun déploiement n’a été effectué dans ce travail.
