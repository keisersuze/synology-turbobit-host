# TurboBitOrg — Synology Download Station

Module `.host` pour télécharger des fichiers avec un compte **Turbobit Premium** depuis Synology Download Station.

**Version de cette branche : 1.0.6 candidate.** Les corrections de compatibilité sont implémentées et testées sans compte réel. La 1.0.4 reste la référence ayant réussi un transfert Premium dans Download Station. La 1.0.6 doit encore passer cette validation avant diffusion comme version stable. Voir le [bilan de validation](docs/VALIDATION-1.0.5.md) et le [changelog](CHANGELOG.md).

La préversion 1.0.5-rc.1 présente une régression de validation du login (HTTP 200 suivi de `API_NOT_JSON_OBJECT`). La 1.0.6 accepte l’accusé JSON vide du login et conserve la vérification obligatoire du compte via `/user/info`. Voir le changelog.

## Fonctionnement

- Liens de fichiers sur `turbobit.net`, `trbt.cc` et `torbobit.net`, avec variantes `www`, HTTP/HTTPS et ports standard : normalisation vers HTTPS sur `turbobit.net`.
- Liens `/download/redirect/<token>/<id>` : extraction de l’identifiant et renouvellement via une résolution Premium ; le token fourni n’est pas réutilisé. Les liens de fichiers avec un nom (`/<id>/<nom>.html`) sont acceptés.
- Authentification via l’API actuelle de Turbobit, vérification explicite du statut Premium ; prise en charge du formulaire HTML classique lorsqu’il est présent.
- Résolution du lien de fichier/CDN, cookies transmis à Download Station, aucun header Host forcé.
- Aucun identifiant, cookie ni mot de passe intégré. Vérification TLS activée.

## Construire et installer

Prérequis de construction : Python 3. Aucun accès réseau ni compte Turbobit requis pour créer l’archive.

```sh
python3 scripts/build.py
```

L’archive est créée dans `dist/TurboBitOrg(1.0.6).host`, avec son empreinte SHA-256.

1. Dans Download Station → Paramètres → Hébergement de fichiers, ajouter cette archive.
2. Vérifier la version affichée et activer le module. Éviter plusieurs modules Turbobit actifs pour les mêmes domaines.
3. Renseigner le compte Premium dans DSM et vérifier le compte. Une mise à jour peut nécessiter de ressaisir les identifiants.
4. Créer une nouvelle tâche avec un lien de fichier. Contrôler le vrai nom, la taille et la progression.

Conserver l’ancienne archive avant mise à jour pour permettre un retour arrière. La conservation des identifiants lors des différentes procédures de remplacement reste à tester.

## Diagnostic local

Ajouter `;local_log=1` au nom d’utilisateur dans DSM pour activer les logs. Le mot de passe reste dans son champ habituel.

Journaux : `/tmp/turbobit_dot_org/<id>.log` ; vérification du compte : `default.log`. La lecture peut nécessiter les droits administrateur.

Les valeurs des cookies, identifiants, mots de passe, chemins signés, paramètres d’URL et noms de fichiers sont masqués dans les nouveaux logs. Une trace indique la version, un identifiant d’exécution, les codes HTTP et une catégorie d’erreur. En mode API, `FILE PAGE HTTP CODE` indique explicitement que la page HTML n’a pas été demandée ; le code réseau figure dans `FILE API HTTP CODE`.

Rétention des logs : sept jours, environ 100 fichiers et 2 Mio par fichier, appliquée lors des exécutions avec logs actifs. Les fichiers verrouillés sont ignorés par le nettoyage ; ce plafond n’est pas une limite stricte lors d’écritures concurrentes. Les anciens logs peuvent encore contenir des données sensibles.

Pour préparer un diagnostic partageable, utiliser sur une machine disposant de Python 3 :

```sh
python3 scripts/export_diagnostics.py /chemin/vers/fichier.log
```

L’export conserve uniquement les champs et statuts autorisés, masque URLs, noms et cookies, et ignore les anciennes lignes non reconnues. Relire l’export avant publication ; ne pas joindre l’original ni une capture contenant les identifiants.

Les cookies transmis au moteur restent sous `/tmp/turbobit_session_*`. Il n’existe pas de signal fiable permettant au module de déterminer la fin d’une tâche : **pas de suppression automatique des sessions en cours**. Un outil distinct, à exécuter sur le NAS avec les droits nécessaires et Python 3, propose un inventaire sans lire les cookies :

```sh
python3 scripts/cleanup_sessions.py
```

Une fois toutes les tâches utilisant ce module terminées ou abandonnées, y compris les tâches suspendues/en attente, l’opérateur peut explicitement nettoyer les sessions :

```sh
python3 scripts/cleanup_sessions.py --delete --confirm-no-active-downloads
```

Ce script n’est pas inclus dans le `.host` et ne s’exécute jamais automatiquement.

## Compatibilité et limites

- 1.0.4 : transfert Premium réel sur DSM 7.4.1-90080, Download Station 4.1.2-5012, x86_64.
- 1.0.5 : tests simulés et journaux exécutés sur le NAS avec PHP 8.1.32 ; tests locaux avec PHP 8.5. Aucun remplacement du module installé effectué pendant cette validation.
- Une matrice CI vérifie PHP 5.6, 7.4, 8.1 et 8.5. Elle ne remplace pas les essais DSM, TLS et architectures ARM ; résultats précis dans le bilan.
- Les liens sans `.html` et les liens directs sans identifiant reconnu restent refusés.
- Téléchargement parallèle d’un même fichier désactivé ; pause/reprise et plusieurs vraies tâches DSM encore à valider.
- CAPTCHA interactif non résolu. API privée du site susceptible de changer. Pas de boucle de login ni de relance automatique sur quota ou HTTP 429.
- Jusqu’à trois liens fournis par l’API pour le même fichier peuvent être essayés séquentiellement après une panne de transport/CDN. Un contenu invalide ou une limitation de débit interrompt la résolution.
- Les fichiers texte/HTML/JSON/XML ou sans type nécessitent une taille cohérente avec les métadonnées API pour éviter de prendre une page d’erreur pour le fichier. Une réponse ambiguë peut être refusée ; le repli HTML seul ne dispose pas de ces métadonnées.
- Les fichiers vides connus bénéficient d’une sonde sans Range après HTTP 416. Une URL véritablement utilisable une seule fois reste incompatible avec la sonde puis le téléchargement Synology.
- Sans constante Synology pour le Referer, la sonde n’en envoie pas : un CDN qui exige ce header peut être refusé. Le User-Agent est celui exposé par Download Station.

## Tests sans identifiants

Prérequis : PHP avec cURL et DOM pour couvrir les deux chemins ; Python 3 pour les outils et le serveur de test. Le module peut utiliser l’API sans DOM.

```sh
php tests/test.php
php tests/log-test.php
python3 scripts/test_transport.py
python3 tests/tools_test.py
```

Couverture : 118 contrôles fonctionnels simulés, 5 contrôles réels des logs, 10 contrôles de transport HTTP local et 2 tests des outils. Certains scripts relancent la suite fonctionnelle en préalable ; ne pas additionner ces répétitions.

Le serveur écoute uniquement sur 127.0.0.1, utilise des cookies fictifs et sert un fichier de référence dont l’empreinte est vérifiée. Aucun compte Turbobit, fichier réel ou accès SSH n’est requis. La CI n’embarque aucun secret.

## Origine et redistribution

Module original : Mathieu Vedie, version 1.0.0 (2025). Attribution conservée dans les sources.

Aucune licence explicite n’a été identifiée dans les deux fichiers de l’archive originale inspectée. Aucune licence supplémentaire n’est attribuée ici ; les conditions de redistribution et de réutilisation restent à clarifier avec l’auteur original. La visibilité publique de ce dépôt ne constitue pas une licence.

## Références

- [Interface .host Synology](https://global.download.synology.com/download/Document/Software/DeveloperGuide/Package/DownloadStation/All/enu/Developer_Guide_to_File_Hosting_Module.pdf)
- [Turbobit](https://turbobit.net/)
