#!/usr/bin/env python3
import os
import sys
import re
import json
import time
import glob
import fcntl
import subprocess
import urllib.request
import urllib.error
from concurrent.futures import ThreadPoolExecutor
from threading import Lock

DATA_DIR = os.getenv("FRESHRSS_DATA", "/opt/freshrss/data")
STATUS_FILE = os.path.join(DATA_DIR, "hister-drain-status.json")
WORKERS = 4
PERMANENT_HTTP_ERRORS = {400, 404, 410, 422}


def write_status(data):
    tmp = STATUS_FILE + ".tmp"
    try:
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(data, f)
        os.replace(tmp, STATUS_FILE)
        try:
            os.chmod(STATUS_FILE, 0o666)
        except OSError:
            pass
    except Exception:
        pass


def find_queue_files():
    files = glob.glob(os.path.join(DATA_DIR, "hister-queue-*.jsonl*"))
    return sorted(f for f in files if not f.endswith((".lock", ".tmp")))


def detect_username(queue_files):
    username = os.getenv("HISTER_USER", "").strip()
    if not username and queue_files:
        basename = os.path.basename(queue_files[0])
        m = re.match(r"^hister-queue-(.+?)\.jsonl", basename)
        if m:
            username = m.group(1)

    if not username:
        user_dirs = [d for d in glob.glob(os.path.join(DATA_DIR, "users", "*")) if os.path.isdir(d)]
        username = os.path.basename(user_dirs[0]) if user_dirs else "_"

    return username


def resolve_hister_credentials(username):
    env_url = os.getenv("HISTER_URL", "").strip()
    env_token = os.getenv("HISTER_TOKEN", "").strip()
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
                $ext = $c['extensions']['Hister'] ?? $c['extensions']['xExtension-Hister'] ?? [];
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


def send_to_hister(api_url, api_token, payload):
    headers = {
        "Content-Type": "application/json; charset=UTF-8",
        "Accept": "application/json",
        "Origin": "same-origin",
        "Authorization": f"Bearer {api_token}",
        "User-Agent": "FreshRSS-Hister-Bulk/1.1",
    }
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(api_url, data=data, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return ("sent", resp.getcode()) if 200 <= resp.getcode() < 300 else ("retry", resp.getcode())
    except urllib.error.HTTPError as e:
        if e.code == 409:
            return ("conflict", 409)
        if e.code in PERMANENT_HTTP_ERRORS:
            return ("dead", e.code)
        return ("retry", e.code)
    except Exception:
        return ("retry", 0)


def main():
    initial_files = find_queue_files()
    if not initial_files:
        print("[+] Aucune file d'attente trouvée.")
        write_status({"running": False, "processed": 0, "total": 0, "pct": 100.0, "message": "File d'attente vide"})
        return

    username = detect_username(initial_files)
    api_url, api_token = resolve_hister_credentials(username)
    if not api_url or not api_token:
        err = "[-] Erreur critique : URL ou Jeton Hister introuvable dans la configuration FreshRSS."
        print(err)
        write_status({"running": False, "processed": 0, "total": 0, "pct": 0.0, "message": err})
        sys.exit(1)

    base_queue_file = os.path.join(DATA_DIR, f"hister-queue-{username}.jsonl")
    dead_letter_file = os.path.join(DATA_DIR, f"hister-dead-{username}.jsonl")
    lock_file = base_queue_file + ".lock"

    print(f"[*] Cible : {api_url}")
    print(f"[*] Verrouillage : {lock_file}")
    lock_fd = acquire_lock(lock_file, max_wait=60)

    try:
        # Lister les fichiers sous verrou pour ne manquer aucun tampon .in
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

        unique_items = {}
        for item in raw_items:
            payload = item.get("payload")
            if isinstance(payload, dict) and payload.get("url"):
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
        stats = {"sent": 0, "conflict": 0, "failed": 0, "dead": 0, "processed": 0}
        stats_lock = Lock()
        retry_payloads = []
        dead_payloads = []

        def worker(payload):
            outcome, code = send_to_hister(api_url, api_token, payload)
            with stats_lock:
                stats["processed"] += 1
                if outcome == "sent":
                    stats["sent"] += 1
                elif outcome == "conflict":
                    stats["conflict"] += 1
                elif outcome == "dead":
                    stats["dead"] += 1
                    dead_payloads.append((code, payload))
                else:
                    stats["failed"] += 1
                    retry_payloads.append(payload)

                p = stats["processed"]
                if p % 20 == 0 or p == total:
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

        with ThreadPoolExecutor(max_workers=WORKERS) as executor:
            list(executor.map(worker, unique_items.values()))

        # Supprimer tous les fichiers sources traités (.jsonl et .jsonl.in)
        for qf in queue_files:
            try:
                os.unlink(qf)
            except OSError:
                pass

        # Réécrire uniquement les échecs temporaires dans la file active
        if retry_payloads:
            with open(base_queue_file, "a", encoding="utf-8") as f:
                for rp in retry_payloads:
                    f.write(json.dumps({"queued_at": int(time.time()), "fetch": False, "payload": rp}) + "\n")

        # Archiver les liens morts permanents (404/410) hors de la file active
        if dead_payloads:
            with open(dead_letter_file, "a", encoding="utf-8") as f:
                for code, dp in dead_payloads:
                    f.write(json.dumps({"failed_at": int(time.time()), "http_code": code, "payload": dp}) + "\n")

        summary = (
            f"Terminé : {stats['sent']} indexés, {stats['conflict']} déjà présents, "
            f"{stats['failed']} à retenter, {stats['dead']} ignorés (404/410)."
        )
        write_status({
            "running": False, "processed": total, "total": total, "pct": 100.0,
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