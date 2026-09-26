# TurboBitOrg — Synology Download Station

Module `.host` pour télécharger des fichiers avec un compte **Turbobit Premium** depuis Synology Download Station.

**Version actuelle : 1.0.4, expérimentale.** Une authentification Premium et un transfert réel ont été vérifiés sur un NAS. La compatibilité générale, la reprise et l’intégrité d’un téléchargement complet restent à valider. Voir [l’audit et les changements proposés](docs/AUDIT-1.0.4.md). Les corrections de la future 1.0.5 ne sont pas encore appliquées.

## Fonctionnement

- Liens de fichiers `https://trbt.cc/<id>.html` et `https://turbobit.net/<id>.html`, normalisés vers `turbobit.net`.
- Authentification via l’API actuelle de Turbobit, vérification explicite du statut Premium ; prise en charge du formulaire HTML classique lorsqu’il est présent.
- Résolution du lien de fichier/CDN, cookies transmis à Download Station, aucun header Host forcé.
- Aucun identifiant, cookie ni mot de passe intégré. Vérification TLS activée.

## Construire et installer

Prérequis de construction : Python 3. Aucun accès réseau ni compte Turbobit requis pour créer l’archive.

```sh
python3 scripts/build.py
```

L’archive est créée dans `dist/TurboBitOrg(1.0.4).host`, avec son empreinte SHA-256.

1. Dans Download Station → Paramètres → Hébergement de fichiers, ajouter cette archive.
2. Vérifier la version affichée et activer le module. Éviter plusieurs modules Turbobit actifs pour les mêmes domaines.
3. Renseigner le compte Premium dans DSM et vérifier le compte. Une mise à jour peut nécessiter de ressaisir les identifiants.
4. Créer une nouvelle tâche avec un lien de fichier. Contrôler le vrai nom, la taille et la progression.

Conserver l’ancienne archive avant mise à jour pour permettre un retour arrière. La conservation des identifiants lors des différentes procédures de remplacement reste à tester.

## Diagnostic local

Ajouter `;local_log=1` au nom d’utilisateur dans DSM pour activer les logs. Le mot de passe reste dans son champ habituel.

Journaux : `/tmp/turbobit_dot_org/<id>.log` ; vérification du compte : `default.log`. La lecture peut nécessiter les droits administrateur.

Les valeurs des cookies, identifiants, mots de passe et paramètres d’URL sont masquées. **Les journaux ne sont pas garantis anonymes** : des noms de fichiers et chemins CDN peuvent rester sensibles. Ne pas publier de cookies, de lien signé, de log brut ou de capture contenant des données personnelles. Les anciens logs du module original peuvent contenir davantage de secrets.

Les sessions de téléchargement sont conservées sous `/tmp/turbobit_session_*` pour le moteur Synology. Ne pas les supprimer pendant un téléchargement actif. Leur nettoyage automatique est un point de l’audit en attente.

## Compatibilité et limites

- Essai réel : DSM 7.4.1-90080, Download Station 4.1.2-5012, x86_64. PHP disponible : 8.1.32 ; cURL 7.86.0.
- Tests locaux en PHP 8.5 ; syntaxe visant PHP 5.6+, mais aucune garantie de fonctionnement sur les anciennes versions de DSM/PHP, ni sur ARM sans tests supplémentaires.
- `torbobit.net`, les liens directs `/download/redirect/…` en entrée et les liens sans `.html` ne sont pas pris en charge par cette version.
- Téléchargement parallèle d’un même fichier désactivé ; pause/reprise et tâches simultanées encore à valider.
- CAPTCHA interactif non résolu ; API tierce susceptible de changer.
- Défauts connus de résolution de certains chemins relatifs et de reconnaissance de certains types de fichiers : détails dans l’audit.

## Tests sans identifiants

Prérequis : PHP avec cURL et DOM, Python 3 pour le test de transport.

```sh
php tests/test.php
python3 scripts/test_transport.py
```

Le premier script exécute 30 contrôles simulés. Le second utilise uniquement un serveur HTTP local et des cookies fictifs, avec 7 contrôles de transport supplémentaires. Ils ne valident pas un compte Premium ni toute la compatibilité de Download Station.

## Origine et redistribution

Module original : Mathieu Vedie, version 1.0.0 (2025). Attribution conservée dans les sources.

Aucune licence explicite n’a été identifiée dans les deux fichiers de l’archive originale inspectée. Aucune licence supplémentaire n’est attribuée ici ; les conditions de redistribution et de réutilisation restent à clarifier avec l’auteur original. La visibilité publique de ce dépôt ne constitue pas une licence.

## Références

- [Interface .host Synology](https://global.download.synology.com/download/Document/Software/DeveloperGuide/Package/DownloadStation/All/enu/Developer_Guide_to_File_Hosting_Module.pdf)
- [Turbobit](https://turbobit.net/)
