# Changelog

## 1.0.7 — candidats de téléchargement indépendants

- Un lien alternatif non HTTP ou invalide ne fait plus rejeter les autres liens valides fournis pour le fichier.
- Les candidats rejetés ne sont jamais demandés ni journalisés en clair ; une catégorie de rejet est ajoutée au diagnostic.
- Conserve le correctif de connexion 1.0.6 et ajoute dix contrôles de régression.
- Téléchargement réel en cours de validation avant publication.

## 1.0.6 — correctif de connexion

- Corrige le contrôle introduit en 1.0.5 qui exigeait un objet JSON pour l’accusé de connexion. Un tableau vide est maintenant accepté uniquement sur `/auth/login`.
- Le statut Premium reste obligatoirement vérifié sur `/user/info` ; les réponses incorrectes et les autres endpoints ne sont pas assouplis.
- Ajoute 11 contrôles pour les accusés vides, le compte gratuit, la session refusée et les réponses malformées.
- La validation Premium réelle dans DSM reste nécessaire après installation.

## 1.0.5 — préversion avec régression de connexion confirmée

- Rétablit torbobit.net et ajoute explicitement les variantes www dans INFO ; canonicalise les liens de fichiers HTTP/HTTPS sur les trois domaines.
- Renouvelle les liens directs reconnus à partir de leur identifiant, sans réutiliser un token expiré.
- Corrige les chemins relatifs qui remontent au-delà de la racine ; conserve les URL HTTP/API brutes sans décodage HTML supplémentaire.
- Préserve le login API 1.0.4 ; autorise un repli unique vers cette API en cas de panne de la page publique, sans réessayer un login rejeté.
- Résout les métadonnées et liens via l’API sans charger la page HTML du fichier ; valide la structure JSON et borne les candidats CDN à trois.
- Vérifie tailles et Content-Range, accepte les fichiers texte/vides confirmés par les métadonnées et refuse les réponses incohérentes.
- Supprime la double sonde CDN. Teste directement les conditions de Referer disponibles pour le moteur Synology.
- Distingue erreurs d’identifiants, Premium, CAPTCHA, quota, débit et service. Parallèle toujours désactivé.
- Contrôle les écritures de sessions, isole les cookies ; ajoute un outil manuel de nettoyage sans suppression automatique risquée.
- Masque chemins signés et noms de fichier, ajoute un identifiant d’exécution, une rétention des logs et un export filtré.
- Ajoute les tests de régression, d’intégrité locale, d’isolation des cookies, de logs et d’outils ; matrice CI PHP 5.6/7.4/8.1/8.5.

## 1.0.4 — référence testée sur un NAS

- Login API et statut Premium corrigés : ne dépend plus du champ login ajouté uniquement par le client web.
- Résolution API/HTML du lien, redirections, cookies et logs locaux.
- Transfert Premium réel observé dans Download Station ; téléchargement complet et compatibilité générale non certifiés.

Origine : module 1.0.0 de Mathieu Vedie (2025). Historique détaillé des limites dans docs/AUDIT-1.0.4.md.
