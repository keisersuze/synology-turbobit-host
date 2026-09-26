# Audit de compatibilité — TurboBitOrg 1.0.4

Document historique : voir [la mise en œuvre 1.0.5](VALIDATION-1.0.5.md) pour le statut actuel.

Date : 26 septembre 2026. Statut : proposition pour validation, aucun correctif appliqué dans cet audit. Ni le module installé ni son archive n’ont été modifiés.

## Conclusion

La 1.0.4 est fonctionnelle sur le NAS testé, mais ne constitue pas encore une version validée pour une diffusion large. L’objectif est une compatibilité documentée et vérifiable ; aucune version ne peut garantir le fonctionnement futur d’une API tierce non contractuelle.

## Ce qui est réellement établi

- Authentification et résolution Premium réelles, puis transfert de plus de 233 Mo du fichier de test, à environ 7,8 Mo/s. La fin du téléchargement et son intégrité n’ont pas été vérifiées.
- Environnement relevé : DSM 7.4.1-90080, Download Station 4.1.2-5012, x86_64 ; PHP disponible sur le NAS 8.1.32, cURL 7.86.0, OpenSSL 3.0.19.
- 30 contrôles simulés passent à nouveau pendant cet audit. Les 7 contrôles de transport cURL avaient été exécutés lors du développement.
- La syntaxe a été vérifiée en PHP 8.1 et 8.5. La mention « syntaxe PHP 5.6+ » dans le fichier n’est pas une validation de fonctionnement sur un ancien DSM ou une ancienne bibliothèque TLS.
- Format .host et méthodes Synology préservés ; absence de credentials intégrés ; certificats TLS vérifiés ; logs désactivés par défaut.

## Corrections proposées pour une 1.0.5

### 1. Domaines et formes de liens — nécessaire

Constat : `torbobit.net` est absent de INFO et de la liste des hôtes acceptés. Son rejet a été reproduit. Il sert actuellement une application identifiée comme Turbobit ; cela ne suffit pas à lui confier les identifiants.

Proposition : accepter `turbobit.net`, `trbt.cc`, `torbobit.net` et leurs variantes www explicitement testées. Transformer les liens de fichiers vers `https://turbobit.net/<id>.html`. Mettre INFO et le parseur en cohérence et vérifier que Download Station choisit effectivement le module pour chaque variante.

Les liens avec nom de fichier sont déjà partiellement acceptés. Tester HTTP/HTTPS, casse du domaine, espaces extérieurs, paramètres de suivi et fragments. Vérifier le sens des liens sans `.html` avant de les annoncer comme pris en charge. Ne pas ajouter tous les domaines ressemblants par une règle permissive.

### 2. Liens directs — régression à corriger

La 1.0.0 acceptait en entrée `/download/redirect/…`. La 1.0.4 les refuse dès le constructeur : constat reproduit.

Proposition : rétablir ces liens sur les hôtes autorisés, en préservant les parties nécessaires à leur signature. Lorsque l’identifiant du fichier est identifiable sans ambiguïté, privilégier une nouvelle résolution Premium du fichier plutôt que dépendre d’un token expiré. Ne pas transformer aveuglément une URL signée en changeant son hôte ou ses paramètres.

### 3. Résolution des redirections — défaut reproduit

Avec la base `https://turbobit.net/dir/file`, le lien relatif `../../../x` produit actuellement `https://turbobit.netx`.

Proposition : corriger la normalisation des segments `..` pour ne jamais remonter au-dessus de la racine et conserver l’hôte. Couvrir les chemins relatifs, absolus, doubles slashs, requêtes seules, fragments et redirections 301/302/303/307/308. Décoder les entités HTML uniquement lorsqu’une URL vient du HTML, sans modifier arbitrairement les URLs brutes de l’API ou des headers HTTP.

### 4. Reconnaissance des vrais fichiers — nécessaire

Un fichier valide servi en `text/plain` sans Content-Disposition est rejeté : constat reproduit. Inversement, la présence d’un header attachment suffit actuellement à accepter le contenu, même si le corps est une page HTML d’erreur. Des fichiers HTML/JSON/XML peuvent aussi être de vrais fichiers : le MIME seul ne doit pas décider.

Proposition : croiser le statut HTTP, les métadonnées attendues, les headers, le nom et les indices de page de connexion/erreur. Tester ZIP, vidéo, PDF, texte, JSON, noms UTF-8, fichier vide, type absent et page d’erreur HTTP 200. Valider Content-Range lorsque le serveur répond 206. Garder une lecture bornée.

### 5. Robustesse API et CDN — nécessaire

La connexion API corrigée de la 1.0.4 doit rester couverte par un test sans champ `login`. Le code dépend toujours du chargement préalable de pages HTML et ne considère que `downloadUrls[0]`. Il réalise aussi une seconde sonde du lien final sans Referer, qui pourrait poser problème à un lien à usage unique ; ce dernier cas n’a pas été observé sur le fichier testé.

Proposition : définir précisément les cas où l’API ou le HTML prennent le relais, sans répéter les tentatives de connexion après un refus explicite. Valider les types de la réponse JSON et prévoir un repli borné sur les autres liens fournis si leur caractère alternatif est confirmé. Réduire les sondes inutiles tout en vérifiant les conditions du moteur Synology. Distinguer mauvais mot de passe, Premium expiré, CAPTCHA, quota, 403, 429, 5xx et panne réseau. Pas de boucle automatique de login et pas de contournement de CAPTCHA.

### 6. Sessions et logs — nécessaire pour un usage durable

Les sessions ayant fourni un lien sont conservées dans /tmp pour le moteur de téléchargement, sans nettoyage ultérieur dans le module. La taille de chaque log est bornée, mais le nombre de fichiers n’est pas limité et le nettoyage par ancienneté de la 1.0.0 a disparu. Des URL CDN, chemins signés ou noms de fichiers peuvent rester sensibles malgré le masquage actuel. Les permissions 0600 ne constituent pas une séparation entre modules exécutés sous le même utilisateur système.

Proposition : contrôler les échecs de création/écriture des fichiers temporaires, prévoir une rétention documentée et un nettoyage prudent sans toucher aux cookies d’une tâche en cours. Définir ce mécanisme à partir du cycle de vie réel de Download Station ; ne pas supprimer aveuglément une session après quelques minutes. Ajouter un identifiant d’exécution pour distinguer des tentatives concurrentes, une rotation bornée et un format de diagnostic partageable qui masque aussi les liens signés et noms personnels. Ne pas présenter les anciens journaux de la 1.0.0 comme sûrs à partager.

### 7. Compatibilité Synology/PHP — validation indispensable

Proposition : vérifier les constantes réellement disponibles, les extensions PHP, les permissions /tmp, la lecture du cookie par le moteur et les comportements de cURL sur les versions ciblées. Produire une matrice distinguant « testé », « attendu » et « non testé ».

Cible initiale recommandée : DSM 7 avec versions précises de Download Station validées. DSM 6, ARM et les anciens PHP restent des cibles candidates jusqu’à essais réels ou représentatifs ; ne pas annoncer « tous les Synology ». Tester aussi le conflit de sélection avec un autre module Turbobit activé et la mise à jour depuis 1.0.0/1.0.2/1.0.4, notamment la conservation ou la ressaisie des identifiants.

### 8. Téléchargement parallèle et reprise — décision après tests

La désactivation actuelle du parallèle est un choix de diagnostic, pas une restriction démontrée du service. Le header Accept-Ranges ou une seule réponse 206 ne prouvent pas que plusieurs connexions sont autorisées et fiables.

Proposition : conserver le comportement actuel par défaut dans la première candidate. Tester deux connexions sur des plages distinctes, la cohérence des données, la pause/reprise, l’expiration du lien et plusieurs tâches simultanées. Proposer ensuite une option explicite si le résultat est fiable. Ne pas confondre les connexions parallèles d’un fichier avec plusieurs fichiers en cours.

### 9. Paquet de diffusion — nécessaire

Préparer une archive sans cookies, logs, clés SSH, identifiants ni chemins personnels, des sources lisibles, un changelog, une notice d’installation et de retour arrière, la matrice de tests et une empreinte SHA-256. Employer un petit fichier de test partageable et autorisé plutôt que diffuser le lien personnel utilisé pour le diagnostic. Conserver l’attribution à l’auteur original et retrouver les conditions de redistribution : aucune licence explicite n’a été identifiée dans les deux fichiers de l’archive inspectée ; ne pas inventer de licence.

## Conditions proposées avant diffusion

1. Régression couverte pour chaque défaut reproduit, avec tests simulés et réseau contrôlé.
2. Connexion valide, mot de passe incorrect, compte non Premium/expiré et challenge interactif : résultats cohérents, pas de faux succès.
3. Trois domaines et variantes annoncées reconnus réellement par Download Station.
4. Téléchargement complet d’un petit fichier connu, avec taille et empreinte comparées ; essai d’un gros fichier et pause/reprise.
5. Plusieurs tâches simultanées sans mélange de cookies ni journaux illisibles ; comportement contrôlé sur panne réseau et lien expiré.
6. Importation, mise à jour et retour arrière testés ; contrôle final de l’archive et absence de secrets.
7. Tests complémentaires sur les environnements que l’on souhaite annoncer. Les cas non accessibles sont explicitement marqués non testés.

## Périmètre soumis à validation

Préparer une candidate 1.0.5 intégrant les corrections de compatibilité, la protection des diagnostics et la documentation ci-dessus. Préserver l’authentification API fonctionnelle. Garder le parallèle désactivé par défaut jusqu’à validation. Présenter le diff et les résultats avant remplacement sur le NAS ou publication. Aucun de ces changements n’a été appliqué par cet audit.

## Références

- Interface, archive, constantes et environnement restreint : [guide Synology](https://global.download.synology.com/download/Document/Software/DeveloperGuide/Package/DownloadStation/All/enu/Developer_Guide_to_File_Hosting_Module.pdf). Ce guide date de 2011 : il ne remplace pas les essais sur les versions actuelles.
- Requêtes par plages : [documentation officielle cURL](https://curl.se/libcurl/c/CURLOPT_RANGE.html).
- Contrat API observé dans les scripts publics de [Turbobit](https://turbobit.net/login), non assimilable à une garantie de stabilité du fournisseur.
- Code audité : archive originale 1.0.0 et sources locales de la 1.0.4 ; essais supplémentaires exécutés sans modification du module.
