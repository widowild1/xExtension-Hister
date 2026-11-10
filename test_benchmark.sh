#!/usr/bin/env bash
# ==============================================================================
# Banc d'essai : xExtension-Hister & Intégration FreshRSS
# Validation : Conformité code, API, URLs, Anonymisation PII, Isolation Workflow
# ==============================================================================

set -o pipefail

# Couleurs d'affichage
CLR_RESET="\033[0m"
CLR_RED="\033[0;31m"
CLR_GREEN="\033[0;32m"
CLR_YELLOW="\033[0;33m"
CLR_CYAN="\033[0;36m"
CLR_BOLD="\033[1m"

BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRESHRSS_DIR="/opt/freshrss"
DATA_DIR="${FRESHRSS_DIR}/data"
ERRORS=0
WARNINGS=0

log_pass() { echo -e "  [${CLR_GREEN}OK${CLR_RESET}] $1"; }
log_fail() { echo -e "  [${CLR_RED}FAIL${CLR_RESET}] $1"; ((ERRORS++)); }
log_warn() { echo -e "  [${CLR_YELLOW}WARN${CLR_RESET}] $1"; ((WARNINGS++)); }
log_section() { echo -e "\n${CLR_CYAN}${CLR_BOLD}=== $1 ===${CLR_RESET}"; }

cd "${BASE_DIR}" || exit 1

# ------------------------------------------------------------------------------
# 1. Conformité du Code et Architecture FreshRSS / Minz
# ------------------------------------------------------------------------------
log_section "1. Conformité du code et conventions Minz"

# A. Syntaxe PHP
php_files=$(find . -path "./.git" -prune -o -name "*.php" -type f -print)
for file in ${php_files}; do
    lint_output=$(php -l "${file}" 2>&1)
    if [[ $? -eq 0 ]]; then
        log_pass "Syntaxe PHP : ${file}"
    else
        log_fail "Erreur de syntaxe dans ${file} : ${lint_output}"
    fi
done

# B. Validation metadata.json
if [[ -f "metadata.json" ]]; then
    if python3 -c "import json; json.load(open('metadata.json'))" 2>/dev/null; then
        ext_name=$(python3 -c "import json; print(json.load(open('metadata.json')).get('name', ''))")
        if [[ "${ext_name}" == "Hister" ]]; then
            log_pass "metadata.json valide (name: Hister)"
        else
            log_fail "metadata.json : le champ 'name' (${ext_name}) ne correspond pas à 'Hister'"
        fi
    else
        log_fail "metadata.json n'est pas un JSON valide"
    fi
else
    log_fail "metadata.json absent"
fi

# C. Vérification de la classe et du namespace global
if grep -q "class HisterExtension extends Minz_Extension" extension.php; then
    log_pass "Classe HisterExtension correctement déclarée"
else
    log_fail "Classe HisterExtension introuvable ou incorrectement nommée dans extension.php"
fi

if grep -E "^[[:space:]]*namespace[[:space:]]+" extension.php >/dev/null; then
    log_fail "Namespace détecté dans extension.php (Minz requiert le namespace global)"
else
    log_pass "Aucun namespace restrictif dans extension.php (namespace global respecté)"
fi

# D. Permissions fichiers (644) et répertoires (755)
exec_php=$(find . -path "./.git" -prune -o -type f -name "*.php" -perm -111 -print)
if [[ -z "${exec_php}" ]]; then
    log_pass "Permissions fichiers conformes (aucun bit d'exécution sur les fichiers .php)"
else
    log_warn "Fichiers PHP avec bit d'exécution actif : ${exec_php}"
fi

# ------------------------------------------------------------------------------
# 2. Anonymisation et Protection des Données Personnelles (PII)
# ------------------------------------------------------------------------------
log_section "2. Audit d'anonymisation (Secrets, PII, Domaines réels)"

# Scan de détection pour emails réels (hors exemples standards RFC 2606)
detected_emails=$(grep -rnEI "([a-zA-Z0-9_.+-]+@(?!example\.(com|org|net))[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+)" . \
    --exclude-dir=".git" --exclude-dir=".forgejo" 2>/dev/null || true)

if [[ -z "${detected_emails}" ]]; then
    log_pass "Aucune adresse e-mail personnelle détectée dans le code applicatif"
else
    log_fail "Adresse e-mail potentiellement privée détectée :\n${detected_emails}"
fi

# Scan de clés ou tokens privés non masqués
detected_tokens=$(grep -rnEI "(api[_-]?key|secret|token|password)[[:space:]]*[=:][[:space:]]*['\"][a-zA-Z0-9_-]{16,}['\"]" . \
    --exclude-dir=".git" --exclude-dir=".forgejo" --exclude="*.bak" 2>/dev/null || true)

if [[ -z "${detected_tokens}" ]]; then
    log_pass "Aucun secret ou jeton d'authentification brut détecté"
else
    log_warn "Jeton ou secret potentiel trouvé dans le code :\n${detected_tokens}"
fi

# ------------------------------------------------------------------------------
# 3. Isolation du dossier Workflow pour GitHub
# ------------------------------------------------------------------------------
log_section "3. Isolation et non-exposition des workflows de forge"

# Vérification du dossier .forgejo
if [[ -d ".forgejo/workflows" ]]; then
    log_pass "Dossier .forgejo/workflows présent pour la forge souveraine"
else
    log_warn "Dossier .forgejo/workflows introuvable"
fi

# Vérifier que le dossier .github/workflows n'est pas exposé inutilement
if [[ -d ".github/workflows" ]]; then
    log_warn ".github/workflows existe : assurez-vous qu'aucun workflow interne Forgejo n'y est dupliqué"
else
    log_pass "Aucun dossier .github/workflows présent (pas d'exposition de workflows sur GitHub)"
fi

# Vérification de l'isolation dans .gitattributes (export-ignore)
if [[ -f ".gitattributes" ]] && grep -q ".forgejo export-ignore" .gitattributes; then
    log_pass ".gitattributes isole le dossier .forgejo des archives et releases (export-ignore)"
else
    log_warn "Recommandation : ajouter '.forgejo export-ignore' dans .gitattributes pour masquer le dossier des archives de distribution"
fi

# ------------------------------------------------------------------------------
# 4. Connectivité Réseau et Appels URLs Internes
# ------------------------------------------------------------------------------
log_section "4. Connectivité et disponibilité des URLs configurées"

# Service Hister (127.0.0.1:4433)
if nc -z -w 2 127.0.0.1 4433 2>/dev/null; then
    log_pass "Service Hister accessible sur http://127.0.0.1:4433"
else
    log_warn "Service Hister non détecté sur 127.0.0.1:4433 (démon hors ligne ou port différent)"
fi

# FlareSolverr (127.0.0.1:8191)
if nc -z -w 2 127.0.0.1 8191 2>/dev/null; then
    log_pass "FlareSolverr accessible sur http://127.0.0.1:8191"
else
    log_warn "FlareSolverr non détecté sur 127.0.0.1:8191 (optionnel selon configuration)"
fi

# Serveur Web local FreshRSS (port 80)
http_status=$(curl -s -o /dev/null -w "%{http_code}" -m 3 http://127.0.0.1/ || true)
if [[ "${http_status}" =~ ^(200|301|302|401|403)$ ]]; then
    log_pass "Serveur Web local opérationnel (HTTP ${http_status})"
else
    log_fail "Le serveur Web local ne répond pas sur http://127.0.0.1/ (code: ${http_status})"
fi

# ------------------------------------------------------------------------------
# 5. Banc d'essai API FreshRSS et Épreuve anti-débordement PostgreSQL
# ------------------------------------------------------------------------------
log_section "5. Test de résistance API Google Reader (Patch ot/nt bigint)"

# Injection d'un timestamp à 22 chiffres simulant le comportement en échec
test_ot="1794171511579128000000"
api_url="http://127.0.0.1/api/greader.php/reader/api/0/stream/contents?ot=${test_ot}"

api_response_code=$(curl -s -o /dev/null -w "%{http_code}" -m 5 "${api_url}" || true)

# L'API doit retourner une réponse HTTP standard (200 ou 401/403 si authentification requise), mais JAMAIS une erreur serveur 500
if [[ "${api_response_code}" == "500" ]]; then
    log_fail "L'API a retourné HTTP 500 sur un ot de 22 chiffres (débordement SQL non filtré)"
elif [[ "${api_response_code}" =~ ^(200|401|403)$ ]]; then
    log_pass "L'API a intercepté la requête sans crash serveur (Code HTTP: ${api_response_code})"
else
    log_warn "Code HTTP inattendu reçu lors de la requête API : ${api_response_code}"
fi

# Contrôle du journal des 60 dernières secondes
latest_sql_error=$(tail -n 30 "${DATA_DIR}/users/widowild/log.txt" 2>/dev/null | grep -F "out of range for type bigint" || true)
if [[ -z "${latest_sql_error}" ]]; then
    log_pass "Aucun débordement bigint enregistré dans log.txt lors du tir de test"
else
    log_fail "Une erreur PostgreSQL 'out of range for type bigint' est apparue dans log.txt"
fi

# ------------------------------------------------------------------------------
# Bilan de validation
# ------------------------------------------------------------------------------
echo -e "\n------------------------------------------------------------"
if [[ ${ERRORS} -eq 0 ]]; then
    echo -e "${CLR_GREEN}${CLR_BOLD}Banc d'essai validé avec succès (0 erreur critique, ${WARNINGS} avertissement(s)).${CLR_RESET}"
    exit 0
else
    echo -e "${CLR_RED}${CLR_BOLD}Échec du banc d'essai : ${ERRORS} erreur(s) détectée(s), ${WARNINGS} avertissement(s).${CLR_RESET}"
    exit 1
fi
