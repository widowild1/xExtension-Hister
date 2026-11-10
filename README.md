# xExtension-Hister

Extension pour [FreshRSS](https://freshrss.org/) permettant la synchronisation, l'archivage et le transfert automatique ou manuel des articles de flux RSS vers une instance de service d'historique personnel **Hister**.

---

## Fonctionnalités

- **Synchronisation automatique** :
  - Hook `entry_before_insert` pour archiver chaque nouvel article au fil de l'eau.
  - Hook `post_update` pour synchroniser les articles favoris (étoilés) ou labellisés lors de l'actualisation des flux.
- **Envoi manuel** : Ajout d'un raccourci dans l'interface de lecture FreshRSS (`entry_before_display`) avec requête AJAX sécurisée.
- **Résilience et File d'attente** :
  - Gestion d'une file d'attente tampon locale (`data/hister-queue-<user>.jsonl`) en cas d'indisponibilité du service distant.
  - Coupe-circuit (*circuit breaker*) automatique et dépilement par lots via `flushQueue`.
- **Intégration FlareSolverr** : Déblocage optionnel des articles protégés par des challenges Cloudflare.
- **Sécurité** : Validation stricte des accès (`FreshRSS_Auth::hasAccess()`) et vérification systématique des jetons CSRF (`FreshRSS_Auth::isCsrfOk()`) sur toutes les requêtes POST.

---

## Dernières Modifications & Correctifs

### 1. Correctif Critique PostgreSQL `bigint` (Erreur SQL 22003)
- **Problème résolu** : Lors de la synchronisation via des clients Google Reader API (ex. Reeder, NetNewsWire, FeedMe), certains clients transmettent un curseur temporel `ot` (*older than*) ou `nt` sous la forme d'un timestamp en microsecondes ou d'un identifiant d'article à 16 chiffres. FreshRSS concaténait automatiquement 6 zéros supplémentaires, produisant une valeur à 22 chiffres qui dépassait la capacité du type 64-bit `bigint` de PostgreSQL.
- **Correctif** : Interception et assainissement défensif dans la méthode `init()` de `HisterExtension`. Tout paramètre `ot` ou `nt` dépassant 10 chiffres est automatiquement tronqué à sa valeur standard en secondes Unix (10 chiffres).

### 2. Architecture & Conformité Minz
- Déclaration de la classe principale en `final class HisterExtension extends Minz_Extension` dans l'espace global (aucun `namespace` restrictif), assurant la compatibilité avec le chargeur d'extensions FreshRSS/Minz.
- Déclaration stricte du fichier `metadata.json` avec l'identifiant exact `Hister`.

### 3. Banc d'essai Automatisé (`test_benchmark.sh`)
- Ajout d'un script complet de validation couvrant :
  - La syntaxe PHP (`php -l`) de tous les composants de l'extension.
  - La conformité des métadonnées et conventions Minz.
  - L'audit d'anonymisation PII (aucun secret, token ou e-mail privé hors RFC 2606).
  - L'accessibilité des services réseau (`127.0.0.1:4433`, `127.0.0.1:8191`, serveur Web local).
  - Un tir de test anti-régression simulant l'envoi d'un paramètre `ot` à 22 chiffres sur l'API Google Reader sans crash HTTP 500 ni erreur SQL.

### 4. Isolation CI/CD Forgejo & Export GitHub
- Intégration des workflows souverains dans `.forgejo/workflows/`.
- Configuration de `.gitattributes` avec directive `export-ignore` sur le dossier `.forgejo` pour empêcher l'exposition ou la duplication involontaire des workflows internes lors des exports d'archives ou miroirs GitHub.

---

## Installation & Déploiement

### Déploiement dans FreshRSS (LXC Proxmox ou Dédié)

1. **Cloner ou déposer le répertoire dans le dossier extensions de FreshRSS :**
   ```bash
   cd /opt/freshrss/extensions
   git clone https://forgejo.example.com/user/xExtension-Hister.git
   ```

2. **Rétablir les permissions du serveur Web :**
   ```bash
   chown -R www-data:www-data /opt/freshrss/extensions/xExtension-Hister
   find /opt/freshrss/extensions/xExtension-Hister -type d -exec chmod 755 {} +
   find /opt/freshrss/extensions/xExtension-Hister -type f -exec chmod 644 {} +
   chmod +x /opt/freshrss/extensions/xExtension-Hister/test_benchmark.sh
   ```

3. **Activer l'extension :**
   Rendez-vous dans l'interface FreshRSS : *Administration > Extensions*, puis activez **Hister**.

---

## Configuration

Dans l'écran de configuration de l'extension (*Administration > Extensions > Hister*) :

| Paramètre | Description | Valeur par défaut |
| :--- | :--- | :--- |
| **URL du service Hister** | Adresse de l'API de réception Hister | `http://127.0.0.1:4433` |
| **Endpoint API** | Point de terminaison pour l'ingestion | `/api/add` |
| **Synchronisation automatique** | Synchroniser tous les flux entrants (`entry_before_insert`) | Désactivé |
| **Synchronisation des favoris** | Synchroniser les articles marqués d'une étoile | Activé |
| **Support FlareSolverr** | Résolution Cloudflare pour articles externes | `http://127.0.0.1:8191` |

---

## Validation & Banc d'essai

Pour exécuter la suite de tests automatisés :
```bash
cd /opt/freshrss/extensions/xExtension-Hister
./test_benchmark.sh
```

---

## Structure du Répertoire

```text
xExtension-Hister/
├── .forgejo/
│   └── workflows/          # CI/CD Forgejo et réplication miroir
├── Controllers/
│   └── histerController.php # Contrôleur AJAX et actions de maintenance
├── i18n/
│   ├── en/ext.php          # Traductions anglaises
│   └── fr/ext.php          # Traductions françaises
├── static/
│   ├── script.js           # Intégration interface client
│   └── style.css           # Feuilles de style
├── .gitattributes          # Règles export-ignore pour workflows internes
├── configure.phtml         # Template d'administration
├── extension.php           # Classe principale HisterExtension & hooks
├── metadata.json           # Métadonnées FreshRSS
├── README.md               # Documentation de l'extension
└── test_benchmark.sh       # Script de banc d'essai et conformité
```

---

## Licence & Auteur

- **Auteur** : widowild
- **Compatibilité** : FreshRSS >= 1.21.0, PHP >= 8.1, PostgreSQL / MariaDB / SQLite
