# xExtension-Hister

Extension pour [FreshRSS](https://freshrss.org/) permettant la synchronisation, l'archivage et le transfert automatique ou manuel des articles de flux RSS vers une instance personnelle de **[Nogon](https://github.com/asciimoo/nogon)** (anciennement **Hister**, 100 % compatible via l'endpoint `/api/add`).

---

## Fonctionnalités

- **Synchronisation automatique** :
  - Hook `entry_before_insert` pour archiver chaque nouvel article au fil de l'eau.
  - Hook `post_update` pour synchroniser les articles favoris (étoilés) ou labellisés lors de l'actualisation des flux.
- **Envoi manuel** : Ajout d'un raccourci dans l'interface de lecture FreshRSS (`entry_before_display`) avec requête AJAX sécurisée.
- **Résilience, File d'attente & Dépilage massif** :
  - Gestion d'une file d'attente tampon locale (`data/hister-queue-<user>.jsonl`) en cas d'indisponibilité du service distant.
  - Coupe-circuit (*circuit breaker*) automatique et dépilement par lots via `flushQueue`.
  - Traitement massif asynchrone via `bin/drain_hister_queue.py`, suivi temps réel via `bin/hister-status` et estimation de réindexation via `bin/hister-eta`.
- **Intégration FlareSolverr** : Déblocage optionnel des articles protégés par des challenges Cloudflare.
- **Sécurité & Permissions** : Validation stricte des accès (`FreshRSS_Auth::hasAccess()`), vérification systématique des jetons CSRF (`FreshRSS_Auth::isCsrfOk()`) et contrôle automatique des droits d'exécution (`+x`) sur les scripts binaires.

---

## Dernières Modifications & Correctifs

### 1. Compatibilité Nogon (ex-Hister)
- Le projet upstream d'`asciimoo` a été renommé de **Hister** vers **[Nogon](https://github.com/asciimoo/nogon)** (`https://nogon.eu/`).
- L'API d'ingestion `POST /api/add` et le format de charge utile JSON restent 100 % identiques : `xExtension-Hister` fonctionne sans aucune modification de configuration avec Hister comme avec Nogon (`>= v0.21.0`).

### 2. Dépilage massif & Vérification des scripts exécutables (`bin/`)
- Intégration des utilitaires CLI dans `bin/` (`drain_hister_queue.py`, `hister-status`, `hister-eta`).
- Détection proactive dans `Controllers/histerController.php`, `configure.phtml` et `test_benchmark.sh` de la présence du bit d'exécution (`+x`) sur les binaires avec alerte dédiée dans l'interface FreshRSS.

### 3. Correctif Critique PostgreSQL `bigint` (Erreur SQL 22003)
- **Problème résolu** : Lors de la synchronisation via des clients Google Reader API (ex. Reeder, NetNewsWire, FeedMe), certains clients transmettent un curseur temporel `ot` (*older than*) ou `nt` sous la forme d'un timestamp en microsecondes ou d'un identifiant d'article à 16 chiffres. FreshRSS concaténait automatiquement 6 zéros supplémentaires, produisant une valeur à 22 chiffres qui dépassait la capacité du type 64-bit `bigint` de PostgreSQL.
- **Correctif** : Interception et assainissement défensif dans la méthode `init()` de `HisterExtension`. Tout paramètre `ot` ou `nt` dépassant 10 chiffres est automatiquement tronqué à sa valeur standard en secondes Unix (10 chiffres).

### 4. Architecture & Conformité Minz
- Déclaration de la classe principale en `final class HisterExtension extends Minz_Extension` dans l'espace global (aucun `namespace` restrictif), assurant la compatibilité avec le chargeur d'extensions FreshRSS/Minz.
- Déclaration stricte du fichier `metadata.json` avec l'identifiant exact `Hister`.

### 5. Banc d'essai Automatisé (`test_benchmark.sh`)
- Suite complète de validation couvrant :
  - La syntaxe PHP (`php -l`), Python (`py_compile`) et Shell (`bash -n`).
  - La conformité des métadonnées, des conventions Minz et des permissions exécutables (`+x`) sur `bin/`.
  - L'audit d'anonymisation PII (aucun secret, token ou e-mail privé hors RFC 2606).
  - L'accessibilité des services réseau (`127.0.0.1:4433`, `127.0.0.1:8191`, serveur Web local).
  - Un tir de test anti-régression simulant l'envoi d'un paramètre `ot` à 22 chiffres sur l'API Google Reader sans crash HTTP 500 ni erreur SQL.

### 6. Isolation CI/CD Forgejo & Export GitHub
- Intégration des workflows souverains dans `.forgejo/workflows/`.
- Configuration de `.gitattributes` avec directive `export-ignore` sur le dossier `.forgejo` pour empêcher l'exposition ou la duplication involontaire des workflows internes lors des exports d'archives ou miroirs GitHub.

---

## Installation & Déploiement

### Déploiement dans FreshRSS (LXC Proxmox ou Dédié)

1. **Cloner ou déposer le répertoire dans le dossier extensions de FreshRSS :**
   ```bash
   cd /opt/freshrss/extensions
   git clone [https://forgejo.example.com/user/xExtension-Hister.git](https://forgejo.example.com/user/xExtension-Hister.git)
