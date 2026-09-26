# Validation 1.0.8 — 26 septembre 2026

## Essai réel

Environnement : DSM 7.4.1-90080, Download Station 4.1.2-5012, x86_64, PHP 8.1.32.

Après import manuel de la 1.0.8, une capture fournie par l’utilisateur montre le vrai nom de fichier, sa taille et un transfert à 8,3 %, environ 6,38 Mo/s. L’utilisateur a ensuite confirmé la réussite du téléchargement complet. Aucune vérification indépendante de l’empreinte du fichier réel n’a été effectuée. Deux tâches étaient simultanément en transfert sur la capture.

La connexion Premium avait été confirmée dans DSM avec la 1.0.6. Les journaux de la 1.0.7 confirmaient l’authentification et le statut Premium mais montraient le rejet des deux candidats HTTPS pour espaces ou caractères de contrôle. La 1.0.8 encode seulement les espaces du chemin des URL absolues fournies par l’API ; les contrôles restent refusés et les paramètres signés sont préservés.

## Tests automatisés

CI réussie pour le commit de code 23220562403b09e07e8793333a46f6fe2f43ff05 sur PHP 5.6, 7.4, 8.1 et 8.5 : 148 contrôles fonctionnels, 5 contrôles des logs, 10 contrôles HTTP locaux et 2 tests d’outils. Les répétitions de la suite fonctionnelle ne sont pas additionnées. Le test local vérifie l’empreinte du fichier synthétique transféré.

## Limites

Pas de validation générale de toutes les versions DSM, des architectures ARM ou des variantes de CDN. La pause/reprise complète, les très gros fichiers sur systèmes 32 bits et la sélection du module par DSM pour chaque alias restent à vérifier. Le téléchargement parallèle d’un même fichier reste désactivé. Aucun journal, identifiant, lien signé ni nom de fichier personnel n’est publié.
