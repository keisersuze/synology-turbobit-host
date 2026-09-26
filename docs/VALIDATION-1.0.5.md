# Validation 1.0.5 — candidate

Le code est préparé pour revue. La 1.0.4 installée sur le NAS n’a pas été remplacée.

| Environnement / scénario | Résultat |
|---|---|
| PHP 8.5 local | 107 contrôles fonctionnels, 5 logs, 10 transport et 2 tests Python réussis |
| NAS x86_64, PHP 8.1.32 | Syntaxe, 107 contrôles fonctionnels et 5 logs réussis dans un dossier isolé |
| CI Linux PHP 5.6 / 7.4 / 8.1 / 8.5 | Matrice ajoutée ; résultat à consulter sur la PR |
| Intégrité d’un petit fichier | Empreinte vérifiée sur serveur HTTP local uniquement |
| Authentification réelle Premium 1.0.5 | À tester dans DSM après revue ; la 1.0.4 a réussi ce test |
| Importation 1.0.5 et sélection des six variantes d’hôte | À tester dans DSM ; parseur couvert par les tests |
| Pause/reprise, fin d’un gros fichier, plusieurs tâches DSM | Non validés |
| DSM 6, autres versions de Download Station, NAS ARM | Non validés ; CI PHP ne vaut pas certification DSM |
| Compte Premium expiré / CAPTCHA / quota / erreurs réseau | Réponses simulées couvertes, pas d’essai de chaque situation sur le service réel |
| Plusieurs liens de l’API pour le même fichier | Repli borné couvert en simulation ; comportement multi-CDN réel non confirmé |

## Correspondance avec l’audit

1. Domaines : implémenté ; variantes sans .html volontairement hors périmètre faute de confirmation.
2. Liens directs : renouvellement des formes avec identifiant explicite ; les formes opaques restent rejetées.
3. URLs relatives : défaut de remontée au-dessus de la racine corrigé et testé, séparation HTML/URL brute.
4. Fichiers : croisement taille/type/Content-Range ; une réponse ambiguë reste un refus explicite.
5. API/CDN : login fonctionnel préservé, dépendance à la page de fichier retirée en mode API, repli et erreurs bornés.
6. Sessions/logs : logs bornés et export filtré ; cookies conservés jusqu’au nettoyage explicite, faute de signal fiable de fin de tâche.
7. Compatibilité : tests par runtime et limites documentées ; couverture matérielle à compléter.
8. Parallèle : maintenu désactivé ; aucun gain ou compatibilité multi-connexion prétendu.
9. Diffusion : archive reproductible à deux fichiers, empreinte, sources, changelog, tests et documentation ; licence originale toujours à clarifier, aucune licence inventée.

## Vérifications avant adoption comme version stable

- Installer la candidate, saisir le compte dans DSM et vérifier le statut Premium réel.
- Utiliser un petit fichier partageable dont on connaît la taille et l’empreinte, puis un gros fichier.
- Vérifier les alias, les entrées directes, l’activation des modules concurrents et la conservation des identifiants à la mise à jour.
- Tester pause/reprise et plusieurs tâches. En cas de refus, fournir l’export filtré des logs, pas les cookies ni le journal brut.
- Effectuer ces essais sur les autres versions/architectures que l’on souhaite annoncer.

La distinction entre tests simulés, tests HTTP locaux et téléchargement Premium réel est intentionnelle. Aucune promesse de compatibilité universelle n’est faite.
