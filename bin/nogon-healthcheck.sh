#!/usr/bin/env bash
set -euo pipefail

FRESHRSS_USER="${FRESHRSS_USER:-widowild}"
FRESHRSS_DIR="${FRESHRSS_DIR:-/opt/freshrss}"
CONFIG_FILE="${FRESHRSS_DIR}/data/users/${FRESHRSS_USER}/config.php"
QUEUE_FILE="${FRESHRSS_DIR}/data/nogon-queue-${FRESHRSS_USER}.jsonl"
DEAD_FILE="${FRESHRSS_DIR}/data/nogon-dead-${FRESHRSS_USER}.jsonl"
NOGON_BASE_URL="${NOGON_BASE_URL:-http://192.168.1.12:4433}"

GREEN="\033[0;32m"
YELLOW="\033[1;33m"
RED="\033[0;31m"
CYAN="\033[0;36m"
NC="\033[0m"

ok()   { echo -e "  [${GREEN}OK${NC}]   $1"; }
warn() { echo -e "  [${YELLOW}WARN${NC}] $1"; }
err()  { echo -e "  [${RED}ERR${NC}]  $1"; }

echo -e "${CYAN}=== DIAGNOSTIC SANTÉ FRESHRSS <-> NOGON ($(date '+%Y-%m-%d %H:%M:%S')) ===${NC}"

echo -e "\n${CYAN}1. Configuration & File d'attente (LXC-freshrss)${NC}"
[[ -f "$CONFIG_FILE" ]] || { err "Configuration introuvable : $CONFIG_FILE"; exit 1; }

NOGON_TOKEN=$(php -r '
    $c = include $argv[1];
    $ext = $c["extensions"]["Nogon"] ?? $c["extensions"]["xExtension-Nogon"] ?? [];
    echo $ext["api_token"] ?? "";
' "$CONFIG_FILE")

if [[ -n "$NOGON_TOKEN" ]]; then
    ok "Jeton API utilisateur chargé depuis config.php (${#NOGON_TOKEN} caractères)"
else
    err "Aucun api_token trouvé dans $CONFIG_FILE"; exit 1
fi

if systemctl is-active --quiet nogon-drain.timer; then
    NEXT_RUN=$(systemctl show nogon-drain.timer -p NextElapseUSecRealtime --value)
    ok "Timer nogon-drain.timer actif (prochain passage : ${NEXT_RUN:-imminent})"
else
    err "Timer nogon-drain.timer INACTIF"
fi

if systemctl is-failed --quiet nogon-drain.service; then
    err "Service nogon-drain.service en échec (voir : journalctl -u nogon-drain.service -n 20)"
else
    LAST_RESULT=$(systemctl show nogon-drain.service -p Result --value)
    ok "Service nogon-drain.service sain (dernier statut : ${LAST_RESULT:-success})"
fi

if [[ -f "$QUEUE_FILE" ]]; then
    Q_COUNT=$(wc -l < "$QUEUE_FILE" | tr -d ' ')
    if [[ "$Q_COUNT" -gt 500 ]]; then
        warn "File d'attente élevée : ${Q_COUNT} articles en attente"
    else
        ok "File d'attente active : ${Q_COUNT} article(s) en attente de dépilage"
    fi
else
    ok "File d'attente vide (0 article en attente)"
fi

if [[ -f "$DEAD_FILE" ]]; then
    D_COUNT=$(wc -l < "$DEAD_FILE" | tr -d ' ')
    warn "Articles écartés (dead-letter) : ${D_COUNT} ligne(s)"
else
    ok "Aucun article rejeté (0 dead-letter)"
fi

echo -e "\n${CYAN}2. Serveur Nogon & Validation CSRF (${NOGON_BASE_URL})${NC}"
HTTP_HEALTH=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 3 "${NOGON_BASE_URL}/" || echo "000")
if [[ "$HTTP_HEALTH" == "200" || "$HTTP_HEALTH" == "302" ]]; then
    ok "Serveur Nogon joignable (HTTP ${HTTP_HEALTH})"
else
    err "Serveur Nogon injoignable sur ${NOGON_BASE_URL} (HTTP ${HTTP_HEALTH})"; exit 1
fi

CSRF_PROBE=$(curl -s -i -X POST "${NOGON_BASE_URL}/api/batch" \
    -H "Content-Type: application/json" \
    -H "Origin: same-origin" \
    -H "Authorization: Bearer ${NOGON_TOKEN}" \
    -d '{"ops":[]}' --connect-timeout 5)

PROBE_CODE=$(echo "$CSRF_PROBE" | awk 'NR==1 {print $2}')
if [[ "$PROBE_CODE" == "200" || "$PROBE_CODE" == "201" || "$PROBE_CODE" == "204" || "$PROBE_CODE" == "400" ]]; then
    ok "Authentification Bearer + CSRF (Origin: same-origin) validés sur /api/batch (HTTP ${PROBE_CODE})"
elif [[ "$PROBE_CODE" == "403" ]]; then
    BODY_ERR=$(echo "$CSRF_PROBE" | tail -n 1)
    err "Rejet HTTP 403 sur /api/batch : ${BODY_ERR:-Jeton invalide ou CSRF refusé}"
else
    warn "Réponse inattendue sur /api/batch : HTTP ${PROBE_CODE}"
fi

echo -e "\n${CYAN}3. Indexation & Embeddings Nogon${NC}"
STATS_JSON=$(curl -s -H "Authorization: Bearer ${NOGON_TOKEN}" \
    -H "Accept: application/json" \
    "${NOGON_BASE_URL}/api/stats" --connect-timeout 5 || echo "{}")

if echo "$STATS_JSON" | jq -e . >/dev/null 2>&1 && [[ "$STATS_JSON" != "{}" ]]; then
    echo "$STATS_JSON" | jq -r 'to_entries[] | select(.key != "recent_searches") | "  [INFO] \(.key): \(.value)"'
else
    SEARCH_JSON=$(curl -s -H "Authorization: Bearer ${NOGON_TOKEN}" \
        -H "Accept: application/json" \
        "${NOGON_BASE_URL}/api/search?q=&limit=1" --connect-timeout 5 || echo "{}")
    TOTAL_DOCS=$(echo "$SEARCH_JSON" | jq -r '.total // .count // "inconnu"' 2>/dev/null || echo "inconnu")
    ok "API de recherche opérationnelle (documents indexés détectés : ${TOTAL_DOCS})"
fi

echo -e "\n${CYAN}=== FIN DU DIAGNOSTIC ===${NC}"
