#!/usr/bin/env python3
import os
import sys
import re
import json
import time
import glob
import fcntl
import signal
import subprocess
import urllib.request
import urllib.error
import urllib.parse
from concurrent.futures import ThreadPoolExecutor
from threading import Lock, Event

DATA_DIR = os.getenv("FRESHRSS_DATA", "/opt/freshrss/data")
STATUS_FILE = os.path.join(DATA_DIR, "nogon-drain-status.json")
WORKERS = max(1, int(os.getenv("NOGON_WORKERS", "4")))
BATCH_SIZE = min(100, max(1, int(os.getenv("NOGON_BATCH_SIZE", "50"))))
MAX_BATCH_BYTES = 20 * 1024 * 1024  # 20 MiB par lot (limite serveur par défaut : 40 MiB)
REQUEST_DELAY = max(0.0, float(os.getenv("NOGON_DELAY", "0.0")))
USE_BATCH = os.getenv("NOGON_USE_BATCH", "1").strip().lower() not in ("0", "false", "no")
BACKOFF_THRESHOLD = 3
BACKOFF_SECONDS = 15
PERMANENT_HTTP_ERRORS = {400, 404, 410, 422}

stop_event = Event()


def handle_sigterm(signum, frame):
    stop_event.set()


signal.signal(signal.SIGTERM, handle_sigterm)
signal.signal(signal.SIGINT, handle_sigterm)


def write_status(data):
    tmp = STATUS_FILE + ".tmp"
    try:
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(data, f, ensure_ascii=False)
        os.replace(tmp, STATUS_FILE)
        try:
            os.chmod(STATUS_FILE, 0o666)
        except OSError:
            pass
    except Exception:
        pass


def find_queue_files():
    files = glob.glob(os.path.join(DATA_DIR, "nogon-queue-*.jsonl*"))
    return sorted(f for f in files if not f.endswith((".lock", ".tmp")))


def detect_username(queue_files):
    username = os.getenv("NOGON_USER", "").strip()
    if not username and queue_files:
        basename = os.path.basename(queue_files[0])
        m = re.match(r"^nogon-queue-(.+?)\.jsonl", basename)
        if m:
            username = m.group(1)

    if not username:
        user_dirs = [d for d in glob.glob(os.path.join(DATA_DIR, "users", "*")) if os.path.isdir(d)]
        username = os.path.basename(user_dirs[0]) if user_dirs else "_"

    return username


def resolve_nogon_credentials(username):
    env_url = os.getenv("NOGON_URL", "").strip()
    env_token = os.getenv("NOGON_TOKEN", "").strip()
    if env_url and env_token:
        return env_url, env_token

    php_code = """
    $user = $argv[1] ?? '_';
    $dataDir = $argv[2] ?? '/opt/freshrss/data';
    $paths = [
        $dataDir . '/users/' . $user . '/config.php',
        $dataDir . '/config.php'
    ];
    $res = ['api_url' => '', 'endpoint_path' => '/api/add', 'api_token' => ''];
    foreach ($paths as $p) {
        if (file_exists($p)) {
            $c = @include $p;
            if (is_array($c)) {
                $ext = $c['extensions']['Nogon'] ?? $c['extensions']['xExtension-Nogon'] ?? [];
                if (!empty($ext['api_url'])) $res['api_url'] = $ext['api_url'];
                if (!empty($ext['endpoint_path'])) $res['endpoint_path'] = $ext['endpoint_path'];
                if (!empty($ext['api_token'])) $res['api_token'] = $ext['api_token'];
            }
        }
    }
    echo json_encode($res);
    """
    try:
        out = subprocess.check_output(
            ["php", "-r", php_code, "--", username, DATA_DIR],
            text=True,
            stderr=subprocess.DEVNULL
        ).strip()
        data = json.loads(out)
        base_url = env_url or data.get("api_url", "")
        endpoint = data.get("endpoint_path", "/api/add")
        if base_url:
            resolved_url = base_url.rstrip("/") + ("/" + endpoint.lstrip("/") if endpoint else "/api/add")
        else:
            resolved_url = ""
        resolved_token = env_token or data.get("api_token", "")
        return resolved_url, resolved_token
    except Exception:
        return env_url, env_token


def build_batch_url(add_url):
    if add_url.endswith("/api/add"):
        return add_url[:-8] + "/api/batch"
    return add_url.rstrip("/") + "/api/batch"


def acquire_lock(lock_path, max_wait=60):
    f = open(lock_path, "a+")
    start = time.time()
    while time.time() - start < max_wait:
        try:
            fcntl.flock(f, fcntl.LOCK_EX | fcntl.LOCK_NB)
            return f
        except BlockingIOError:
            print("[*] Verrou occupé par FreshRSS. Attente (2s)...")
            time.sleep(2)
    print(f"[-] Impossible d'acquérir le verrou après {max_wait}s.")
    sys.exit(1)


def post_json(url, api_token, body_obj, timeout=60):
    parsed_u = urllib.parse.urlsplit(url)
    origin = f"{parsed_u.scheme}://{parsed_u.netloc}" if parsed_u.scheme and parsed_u.netloc else ""
    headers = {
        "Content-Type": "application/json; charset=UTF-8",
        "Accept": "application/json",
        "Authorization": f"Bearer {api_token}",
        "User-Agent": "FreshRSS-Nogon-Bulk/2.0",
        "Origin": "same-origin",
    }
    if origin:
        headers["Origin"] = origin
    data = json.dumps(body_obj, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers=headers, method="POST")
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        raw = resp.read().decode("utf-8", errors="replace")
        return resp.getcode(), raw


def send_single_to_nogon(add_url, api_token, payload):
    try:
        code, _ = post_json(add_url, api_token, payload, timeout=30)
        return ("sent", code) if 200 <= code < 300 else ("retry", code)
    except urllib.error.HTTPError as e:
        if e.code == 409:
            return ("conflict", 409)
        if e.code in PERMANENT_HTTP_ERRORS:
            return ("dead", e.code)
        return ("retry", e.code)
    except Exception:
        return ("retry", 0)


def send_batch_to_nogon(batch_url, add_url, api_token, payloads):
    """
    Envoie un lot via POST /api/batch ({"ops": [{"op": "add", ...}, ...]}).
    En cas de repli (endpoint absent ou erreur globale sur le lot), bascule sur /api/add.
    Retourne une liste de tuples (outcome, code, payload).
    """
    ops = []
    for p in payloads:
        op = dict(p)
        op["op"] = "add"
        ops.append(op)

    try:
        code, raw = post_json(batch_url, api_token, {"ops": ops}, timeout=90)
        if 200 <= code < 300:
            try:
                parsed = json.loads(raw) if raw.strip() else {}
            except json.JSONDecodeError:
                parsed = {}

            results_list = None
            if isinstance(parsed, dict):
                for key in ("results", "ops", "items"):
                    if isinstance(parsed.get(key), list):
                        results_list = parsed[key]
                        break
            elif isinstance(parsed, list):
                results_list = parsed

            if results_list is not None and len(results_list) == len(payloads):
                outcomes = []
                for item_res, p in zip(results_list, payloads):
                    if not isinstance(item_res, dict):
                        outcomes.append(("sent", code, p))
                        continue
                    item_code = int(item_res.get("status") or item_res.get("code") or (200 if item_res.get("ok", True) else 500))
                    err_msg = str(item_res.get("error") or "").lower()
                    if 200 <= item_code < 300 and not err_msg:
                        outcomes.append(("sent", item_code, p))
                    elif item_code == 409 or "exist" in err_msg or "duplicate" in err_msg or "conflict" in err_msg:
                        outcomes.append(("conflict", 409, p))
                    elif item_code in PERMANENT_HTTP_ERRORS:
                        outcomes.append(("dead", item_code, p))
                    else:
                        outcomes.append(("retry", item_code, p))
                return outcomes

            return [("sent", code, p) for p in payloads]

        return [("retry", code, p) for p in payloads]

    except urllib.error.HTTPError as e:
        # Si /api/batch n'existe pas ou rejette un élément du lot (400/422), repli unitaire sur /api/add
        if e.code in (400, 404, 405, 413, 422):
            return [(out, c, p) for p in payloads for out, c in [send_single_to_nogon(add_url, api_token, p)]]
        return [("retry", e.code, p) for p in payloads]
    except Exception:
        return [("retry", 0, p) for p in payloads]


def chunk_payloads(payloads, max_items, max_bytes):
    chunks = []
    cur_chunk = []
    cur_bytes = 32
    for p in payloads:
        p_bytes = len(json.dumps(p, ensure_ascii=False).encode("utf-8")) + 16
        if cur_chunk and (len(cur_chunk) >= max_items or cur_bytes + p_bytes > max_bytes):
            chunks.append(cur_chunk)
            cur_chunk = []
            cur_bytes = 32
        cur_chunk.append(p)
        cur_bytes += p_bytes
    if cur_chunk:
        chunks.append(cur_chunk)
    return chunks


def main():
    initial_files = find_queue_files()
    if not initial_files:
        print("[+] Aucune file d'attente trouvée.")
        write_status({"running": False, "processed": 0, "total": 0, "pct": 100.0, "message": "File d'attente vide"})
        return

    username = detect_username(initial_files)
    add_url, api_token = resolve_nogon_credentials(username)
    if not add_url or not api_token:
        err = "Erreur critique : URL ou Jeton Nogon introuvable dans la configuration FreshRSS."
        print(f"[-] {err}")
        write_status({"running": False, "processed": 0, "total": 0, "pct": 0.0, "message": err})
        sys.exit(1)

    batch_url = build_batch_url(add_url)
    base_queue_file = os.path.join(DATA_DIR, f"nogon-queue-{username}.jsonl")
    dead_letter_file = os.path.join(DATA_DIR, f"nogon-dead-{username}.jsonl")
    lock_file = base_queue_file + ".lock"

    mode_str = f"batch={batch_url} (lot={BATCH_SIZE})" if USE_BATCH else f"unitaire={add_url}"
    print(f"[*] Cible : {mode_str} | workers={WORKERS}, delay={REQUEST_DELAY}s")
    print(f"[*] Verrouillage : {lock_file}")
    lock_fd = acquire_lock(lock_file, max_wait=60)

    try:
        queue_files = find_queue_files()
        raw_items = []
        for qf in queue_files:
            if not os.path.isfile(qf):
                continue
            with open(qf, "r", encoding="utf-8", errors="replace") as f:
                for line in f:
                    line = line.strip()
                    if line:
                        try:
                            raw_items.append(json.loads(line))
                        except json.JSONDecodeError:
                            pass

        MEDIA_EXTS = (".mp4", ".wmv", ".mkv", ".avi", ".mov", ".webm", ".jpg", ".jpeg", ".png", ".gif", ".zip", ".rar")
        unique_items = {}
        for item in raw_items:
            payload = item.get("payload")
            if isinstance(payload, dict) and payload.get("url"):
                if payload["url"].lower().split("?")[0].endswith(MEDIA_EXTS):
                    continue
                unique_items[payload["url"]] = payload

        total = len(unique_items)
        if total == 0:
            for qf in queue_files:
                try:
                    os.unlink(qf)
                except OSError:
                    pass
            write_status({"running": False, "processed": 0, "total": 0, "pct": 100.0, "message": "Aucun article à traiter"})
            return

        write_status({
            "running": True, "processed": 0, "total": total, "pct": 0.0,
            "sent": 0, "conflict": 0, "failed": 0, "dead": 0, "rate": 0.0, "eta_min": 0.0
        })

        start_time = time.time()
        stats = {"sent": 0, "conflict": 0, "failed": 0, "dead": 0, "processed": 0, "consecutive_errors": 0}
        stats_lock = Lock()
        retry_payloads = []
        dead_payloads = []
        unprocessed_payloads = []

        batches = chunk_payloads(list(unique_items.values()), BATCH_SIZE if USE_BATCH else 1, MAX_BATCH_BYTES)

        def worker(batch):
            if stop_event.is_set():
                with stats_lock:
                    unprocessed_payloads.extend(batch)
                return

            if USE_BATCH and len(batch) > 1:
                results = send_batch_to_nogon(batch_url, add_url, api_token, batch)
            else:
                results = [(out, c, p) for p in batch for out, c in [send_single_to_nogon(add_url, api_token, p)]]

            should_backoff = False

            with stats_lock:
                for outcome, code, payload in results:
                    stats["processed"] += 1
                    if outcome == "sent":
                        stats["sent"] += 1
                        stats["consecutive_errors"] = 0
                    elif outcome == "conflict":
                        stats["conflict"] += 1
                        stats["consecutive_errors"] = 0
                    elif outcome == "dead":
                        stats["dead"] += 1
                        stats["consecutive_errors"] = 0
                        dead_payloads.append((code, payload))
                    else:
                        stats["failed"] += 1
                        stats["consecutive_errors"] += 1
                        retry_payloads.append(payload)
                        if stats["consecutive_errors"] >= BACKOFF_THRESHOLD:
                            stats["consecutive_errors"] = 0
                            should_backoff = True

                p = stats["processed"]
                elapsed = time.time() - start_time
                rate = p / elapsed if elapsed > 0 else 0
                pct = round((p / total) * 100, 1)
                eta = round(((total - p) / rate) / 60, 1) if rate > 0 else 0
                write_status({
                    "running": True, "processed": p, "total": total, "pct": pct,
                    "sent": stats["sent"], "conflict": stats["conflict"],
                    "failed": stats["failed"], "dead": stats["dead"],
                    "rate": round(rate, 1), "eta_min": eta
                })
                print(
                    f"\r[{pct}%] {p}/{total} | +{stats['sent']} ok | {stats['conflict']} vus | "
                    f"{stats['failed']} retry | {stats['dead']} morts | {rate:.1f} art/s",
                    end=""
                )
                sys.stdout.flush()

            if should_backoff and not stop_event.is_set():
                print(f"\n[!] Nogon saturé/injoignable : pause de récupération ({BACKOFF_SECONDS}s)...")
                stop_event.wait(BACKOFF_SECONDS)
            elif REQUEST_DELAY > 0 and not stop_event.is_set():
                stop_event.wait(REQUEST_DELAY)

        with ThreadPoolExecutor(max_workers=WORKERS) as executor:
            list(executor.map(worker, batches))

        for qf in queue_files:
            try:
                os.unlink(qf)
            except OSError:
                pass

        remaining_items = retry_payloads + unprocessed_payloads
        if remaining_items:
            with open(base_queue_file, "a", encoding="utf-8") as f:
                now_ts = int(time.time())
                for rp in remaining_items:
                    f.write(json.dumps({"queued_at": now_ts, "fetch": False, "payload": rp}) + "\n")

        if dead_payloads:
            with open(dead_letter_file, "a", encoding="utf-8") as f:
                now_ts = int(time.time())
                for code, dp in dead_payloads:
                    f.write(json.dumps({"failed_at": now_ts, "http_code": code, "payload": dp}) + "\n")

        if stop_event.is_set():
            summary = f"Interrompu : {stats['sent']} indexés, {len(remaining_items)} conservés en file."
        else:
            summary = (
                f"Terminé : {stats['sent']} indexés, {stats['conflict']} déjà présents, "
                f"{stats['failed']} à retenter, {stats['dead']} ignorés (404/410)."
            )

        pct_final = round((stats["processed"] / total) * 100, 1) if total > 0 else 100.0
        write_status({
            "running": False, "processed": stats["processed"], "total": total, "pct": pct_final,
            "sent": stats["sent"], "conflict": stats["conflict"],
            "failed": stats["failed"], "dead": stats["dead"],
            "message": summary
        })
        print(f"\n[+] {summary}")

    finally:
        fcntl.flock(lock_fd, fcntl.LOCK_UN)
        lock_fd.close()
        try:
            os.unlink(lock_file)
        except OSError:
            pass


if __name__ == "__main__":
    main()
