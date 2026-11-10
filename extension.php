<?php

declare(strict_types=1);

class HisterExtension extends Minz_Extension {

    private const DEFAULT_URL = 'http://127.0.0.1:4433';
    private const DEFAULT_ENDPOINT = '/api/add';
    private const MAX_TEXT_CHARS = 200000;
    private const MAX_QUEUE_BYTES = 500 * 1024 * 1024;
    private const MAX_HTML_BYTES = 4 * 1024 * 1024;
    private const DEFAULT_FLARE_URL = 'http://127.0.0.1:8191';

    /** Une fois Hister injoignable pendant une requête, on n'insiste plus (évite de bloquer l'actualisation des flux). */
    private static bool $circuitOpen = false;


    private bool $lastQueued = false;

    /** FlareSolverr injoignable pendant cette requête : on laisse les articles en file au lieu d'insister. */
    private static bool $flareDown = false;

    public function init(): void {
        $this->registerTranslates();
        $this->registerController('hister');
        Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));

        Minz_ExtensionManager::addHook('entry_before_display', [$this, 'addSendLink']);

        if ($this->isAutoSyncAll()) {
            Minz_ExtensionManager::addHook('entry_before_insert', [$this, 'hookEntryBeforeInsert']);
        }
        if ($this->isAutoSyncAll() || $this->isSyncStarred() || $this->isSyncLabeled()) {
            Minz_ExtensionManager::addHook('post_update', [$this, 'hookPostUpdate']);
        }
    }

    public function handleConfigureAction(): void {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            $this->saveSettingsFromRequest();
        }
    }

    /** Enregistre les réglages du formulaire (utilisé par « Valider » et par les boutons d'action). */
    public function saveSettingsFromRequest(): void {
            $apiUrl = rtrim((string)Minz_Request::param('api_url', ''), '/');
            $endpointPath = '/' . ltrim((string)Minz_Request::param('endpoint_path', ''), '/');

            $this->setUserConfigurationValue('api_url', $apiUrl !== '' ? $apiUrl : self::DEFAULT_URL);
            $this->setUserConfigurationValue('endpoint_path', $endpointPath !== '/' ? $endpointPath : self::DEFAULT_ENDPOINT);
            $this->setUserConfigurationValue('api_token', trim((string)Minz_Request::param('api_token', '')));
            $this->setUserConfigurationValue('default_tags', (string)Minz_Request::param('default_tags', ''));
            $this->setUserConfigurationValue('include_category_tag', !empty(Minz_Request::param('include_category_tag', '')));
            $this->setUserConfigurationValue('include_feed_tag', !empty(Minz_Request::param('include_feed_tag', '')));
            $this->setUserConfigurationValue('include_entry_tags', !empty(Minz_Request::param('include_entry_tags', '')));
            $this->setUserConfigurationValue('ignore_skip_rules', !empty(Minz_Request::param('ignore_skip_rules', '')));
            $this->setUserConfigurationValue('auto_sync_all', !empty(Minz_Request::param('auto_sync_all', '')));
            $this->setUserConfigurationValue('sync_starred', !empty(Minz_Request::param('sync_starred', '')));
            $this->setUserConfigurationValue('sync_labeled', !empty(Minz_Request::param('sync_labeled', '')));
            $this->setUserConfigurationValue('verify_ssl', !empty(Minz_Request::param('verify_ssl', '')));
            $this->setUserConfigurationValue('clean_tracking', !empty(Minz_Request::param('clean_tracking', '')));
            $this->setUserConfigurationValue('extra_tracking_params', trim((string)Minz_Request::param('extra_tracking_params', '')));
            $this->setUserConfigurationValue('flaresolverr_enabled', !empty(Minz_Request::param('flaresolverr_enabled', '')));
            $flareUrl = rtrim(trim((string)Minz_Request::param('flaresolverr_url', '')), '/');
            $this->setUserConfigurationValue('flaresolverr_url', $flareUrl !== '' ? $flareUrl : self::DEFAULT_FLARE_URL);
            $this->setUserConfigurationValue('flaresolverr_timeout', max(10, min(300, (int)Minz_Request::param('flaresolverr_timeout', '60'))));
            $this->setUserConfigurationValue('flush_seconds', max(10, min(600, (int)Minz_Request::param('flush_seconds', '40'))));
    }

    public function getApiUrl(): string {
        $val = (string)$this->getUserConfigurationValue('api_url');
        return $val !== '' ? $val : self::DEFAULT_URL;
    }

    public function getEndpointPath(): string {
        $val = (string)$this->getUserConfigurationValue('endpoint_path');
        return $val !== '' ? $val : self::DEFAULT_ENDPOINT;
    }

    public function getApiToken(): string {
        return (string)$this->getUserConfigurationValue('api_token');
    }

    public function getDefaultTags(): string {
        return (string)$this->getUserConfigurationValue('default_tags');
    }

    public function isIncludeCategoryTag(): bool {
        return (bool)$this->getUserConfigurationValue('include_category_tag');
    }

    public function isIncludeFeedTag(): bool {
        return (bool)$this->getUserConfigurationValue('include_feed_tag');
    }

    public function isIncludeEntryTags(): bool {
        return (bool)$this->getUserConfigurationValue('include_entry_tags');
    }

    public function isIgnoreSkipRules(): bool {
        return (bool)$this->getUserConfigurationValue('ignore_skip_rules');
    }

    public function isSyncStarred(): bool {
        return (bool)$this->getUserConfigurationValue('sync_starred');
    }

    public function isSyncLabeled(): bool {
        return (bool)$this->getUserConfigurationValue('sync_labeled');
    }

    public function isCleanTracking(): bool {
        $val = $this->getUserConfigurationValue('clean_tracking');
        return $val === null ? true : (bool)$val;
    }

    public function getExtraTrackingParams(): string {
        return (string)$this->getUserConfigurationValue('extra_tracking_params');
    }

    public function isFlareEnabled(): bool {
        return (bool)$this->getUserConfigurationValue('flaresolverr_enabled');
    }

    public function getFlareUrl(): string {
        $val = (string)$this->getUserConfigurationValue('flaresolverr_url');
        return $val !== '' ? $val : self::DEFAULT_FLARE_URL;
    }

    public function getFlareTimeout(): int {
        $val = (int)$this->getUserConfigurationValue('flaresolverr_timeout');
        return $val >= 10 ? $val : 60;
    }

    public function getFlushSeconds(): int {
        $val = (int)$this->getUserConfigurationValue('flush_seconds');
        return $val >= 10 ? $val : 40;
    }

    public function isAutoSyncAll(): bool {
        return (bool)$this->getUserConfigurationValue('auto_sync_all');
    }

    public function isVerifySsl(): bool {
        $val = $this->getUserConfigurationValue('verify_ssl');
        return $val === null ? true : (bool)$val;
    }

    /** Ajoute un lien « Envoyer à Hister » à la fin du contenu de l'article. */
    public function addSendLink(FreshRSS_Entry $entry): FreshRSS_Entry {
        $sendUrl = _url('hister', 'send', 'id', $entry->id());
        $title = htmlspecialchars(_t('ext.hister.send_button_title'), ENT_QUOTES, 'UTF-8');

        $entry->_content(
            $entry->content()
            . '<p class="hister-action"><a class="hister-send-btn" href="' . $sendUrl . '" title="' . $title . '">'
            . '↗ ' . $title . '</a></p>'
        );
        return $entry;
    }

    public function hookEntryBeforeInsert(FreshRSS_Entry $entry): FreshRSS_Entry {
        $this->sendToHister($entry);
        return $entry;
    }

    /** @return list<string> */
    private function buildTags(FreshRSS_Entry $entry, bool $withLabels = false): array {
        $tags = [];

        foreach (explode(',', $this->getDefaultTags()) as $tag) {
            $clean = trim($tag);
            if ($clean !== '') {
                $tags[] = $clean;
            }
        }

        $feed = $entry->feed();
        if ($feed !== null) {
            if ($this->isIncludeCategoryTag()) {
                $cat = $feed->category();
                if ($cat !== null && trim($cat->name()) !== '') {
                    $tags[] = trim($cat->name());
                }
            }
            if ($this->isIncludeFeedTag() && trim($feed->name()) !== '') {
                $tags[] = trim($feed->name());
            }
        }

        // Tags propres à l'article (catégories fournies par le flux RSS).
        if ($this->isIncludeEntryTags()) {
            foreach ($entry->tags() as $tag) {
                $clean = trim(ltrim((string)$tag, '#'));
                if ($clean !== '') {
                    $tags[] = $clean;
                }
            }
        }

        // Labels FreshRSS posés par l'utilisateur : seulement pour un article déjà en base.
        if ($withLabels || $this->isIncludeEntryTags()) {
            foreach ($this->getEntryLabels($entry) as $label) {
                $tags[] = $label;
            }
        }

        return array_values(array_unique($tags));
    }

    /** @return list<string> */
    private function getEntryLabels(FreshRSS_Entry $entry): array {
        $names = [];
        $collect = static function ($item) use (&$names): void {
            $name = is_array($item) ? (string)($item['name'] ?? '') : (string)$item;
            $name = trim($name);
            if ($name !== '') {
                $names[] = $name;
            }
        };

        try {
            if (method_exists($entry, 'labels')) {
                $labels = $entry->labels();
                if (is_array($labels)) {
                    array_map($collect, $labels);
                }
            }
            if ($names === [] && (string)$entry->id() !== '' && (string)$entry->id() !== '0') {
                $tagDao = FreshRSS_Factory::createTagDao();
                if (method_exists($tagDao, 'getTagsForEntry')) {
                    $rows = $tagDao->getTagsForEntry((string)$entry->id());
                    if (is_iterable($rows)) {
                        foreach ($rows as $row) {
                            $collect($row);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Minz_Log::warning('Hister: lecture des labels impossible (' . $e->getMessage() . ')');
        }

        return array_values(array_unique($names));
    }

    public function hookPostUpdate(): void {
        $this->flushQueue(500, $this->getFlushSeconds());
        $this->syncMarkedEntries(50);
    }

    /** @return array<string,string> identifiant d'article => signature des tags déjà envoyés */
    private function getSentState(): array {
        $state = $this->getUserConfigurationValue('sent_state');
        return is_array($state) ? $state : [];
    }

    /**
     * Envoie les articles en favoris et/ou labellisés (selon la config) qui n'ont pas encore été envoyés,
     * ou dont les labels ont changé depuis le dernier envoi.
     * @return array{sent:int,failed:int}
     */
    public function syncMarkedEntries(int $limit = 50): array {
        $result = ['sent' => 0, 'failed' => 0];
        $types = [];
        if ($this->isSyncStarred()) {
            $types[] = 's';
        }
        if ($this->isSyncLabeled()) {
            $types[] = 'T';
        }
        if ($types === []) {
            return $result;
        }

        $state = $this->getSentState();
        $entryDAO = FreshRSS_Factory::createEntryDao();
        $seen = [];

        foreach ($types as $type) {
            try {
                $rows = $entryDAO->listWhere($type, 0, FreshRSS_Entry::STATE_ALL, 'DESC', 200);
                $entries = method_exists('FreshRSS_Entry', 'fromTraversable')
                    ? FreshRSS_Entry::fromTraversable($rows)
                    : $rows;
            } catch (Throwable $e) {
                Minz_Log::error('Hister: lecture des articles impossible (' . $e->getMessage() . ')');
                continue;
            }

            foreach ($entries as $entry) {
                if (!$entry instanceof FreshRSS_Entry) {
                    continue;
                }
                $id = (string)$entry->id();
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;

                $signature = md5(implode('|', $this->buildTags($entry, true)));
                if (($state[$id] ?? '') === $signature) {
                    continue;
                }
                if ($result['sent'] + $result['failed'] >= $limit || self::$circuitOpen) {
                    break 2;
                }

                $status = $this->submit($entry, true, false, false);
                if ($status === 'sent' || $status === 'queued') {
                    $state[$id] = $signature;
                    $result['sent']++;
                } else {
                    $result['failed']++;
                }
            }
        }

        // On borne la taille de l'état mémorisé (les plus anciens sont oubliés ; Hister dédoublonne par URL).
        if (count($state) > 3000) {
            $state = array_slice($state, -3000, null, true);
        }
        $this->setUserConfigurationValue('sent_state', $state);

        return $result;
    }

    /** Vrai si le dernier appel à sendToHister() a mis l'article en file d'attente. */
    public function wasQueued(): bool {
        return $this->lastQueued;
    }

    /**
     * Envoie un article à Hister. Si Hister est indisponible (hors ligne, réindexation, erreur 5xx, token refusé),
     * l'article est conservé dans une file d'attente et renvoyé plus tard (si $queue est vrai).
     * Avec FlareSolverr, la page est récupérée immédiatement seulement si $fetchNow est vrai (envoi manuel) ;
     * sinon l'article est mis en file et la page est récupérée lors du prochain passage.
     */
    public function sendToHister(FreshRSS_Entry $entry, bool $withLabels = false, bool $queue = true, bool $fetchNow = false): bool {
        $status = $this->submit($entry, $withLabels, $queue, $fetchNow);
        $this->lastQueued = ($status === 'queued');
        return $status === 'sent';
    }

    /** @return 'sent'|'queued'|'failed' */
    private function submit(FreshRSS_Entry $entry, bool $withLabels, bool $allowQueue, bool $fetchNow): string {
        $payload = $this->buildPayload($entry, $withLabels);
        if ($payload === null) {
            return 'failed';
        }

        if ($this->isFlareEnabled()) {
            if (!$fetchNow) {
                return $this->enqueue($payload, true) ? 'queued' : 'failed';
            }
            $page = $this->fetchHtml((string)$payload['url']);
            if ($page['status'] === 'ok') {
                $payload['html'] = $page['html'];
                unset($payload['text']);
            }
        }

        if (self::$circuitOpen) {
            $status = 'retry';
        } else {
            $status = $this->postPayload($payload);
            if ($status === 'ok') {
                return 'sent';
            }
        }

        if ($status === 'retry' && $allowQueue) {
            return $this->enqueue($payload, false) ? 'queued' : 'failed';
        }
        return 'failed';
    }

    /** @return array<string,mixed>|null */
    private function buildPayload(FreshRSS_Entry $entry, bool $withLabels): ?array {
        $url = (string)$entry->link();
        if ($url === '') {
            return null;
        }
        if ($this->isCleanTracking()) {
            $url = $this->cleanUrl($url);
        }

        $tags = $this->buildTags($entry, $withLabels);
        $text = trim(html_entity_decode(strip_tags((string)$entry->content()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = mb_substr($text, 0, self::MAX_TEXT_CHARS, 'UTF-8');

        $feed = $entry->feed();
        $metadata = [
            'source' => 'freshrss',
            'tags' => $tags,
            'published' => $entry->date(true),
        ];
        if ($feed !== null) {
            $metadata['feed'] = $feed->name();
        }
        if ($this->isIgnoreSkipRules()) {
            $metadata['ignore_skip_rules'] = true;
        }

        // L'API Hister n'a qu'un champ « label » (chaîne) : les tags y sont réunis, séparés par des virgules.
        return [
            'url' => $url,
            'title' => html_entity_decode($entry->title(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'text' => $text,
            'label' => implode(', ', $tags),
            'metadata' => $metadata,
        ];
    }

    /**
     * Envoie un document à Hister.
     * @param array<string,mixed> $payload
     * @return 'ok'|'retry'|'drop'  retry = à renvoyer plus tard, drop = refusé définitivement (document invalide)
     */
    private function postPayload(array $payload): string {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: FreshRSS-Hister-Client/2.4.1',
        ];
        $token = $this->getApiToken();
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $targetUrl = rtrim($this->getApiUrl(), '/') . '/' . ltrim($this->getEndpointPath(), '/');
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $targetUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => isset($payload['html']) ? 20 : 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->isVerifySsl(),
            CURLOPT_SSL_VERIFYHOST => $this->isVerifySsl() ? 2 : 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $url = (string)($payload['url'] ?? '');

        if ($curlError !== '') {
            self::$circuitOpen = true;
            Minz_Log::warning('Hister: instance injoignable (' . $curlError . '), envois suspendus, articles mis en file.');
            return 'retry';
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            Minz_Log::notice('Hister: envoyé ' . $url . ' (HTTP ' . $httpCode . ')');
            return 'ok';
        }

        // Indisponible (réindexation, surcharge), authentification à corriger : on garde et on réessaiera.
        if ($httpCode === 0 || $httpCode === 401 || $httpCode === 403 || $httpCode === 408 || $httpCode === 429 || $httpCode >= 500) {
            self::$circuitOpen = true;
            Minz_Log::warning('Hister: HTTP ' . $httpCode . ', envois suspendus, articles mis en file. ' . mb_substr((string)$response, 0, 200));
            return 'retry';
        }

        Minz_Log::error('Hister: document refusé HTTP ' . $httpCode . ' pour ' . $url . ' — ' . mb_substr((string)$response, 0, 200));
        return 'drop';
    }

    // ---------------------------------------------------------------------
    // File d'attente (JSON Lines, un fichier par utilisateur FreshRSS)
    // ---------------------------------------------------------------------

    private function queuePath(): string {
        $user = (string)(Minz_User::name() ?? '_');
        $user = preg_replace('/[^A-Za-z0-9_.-]/', '_', $user) ?? '_';
        return DATA_PATH . '/hister-queue-' . $user . '.jsonl';
    }

    /** @param array<string,mixed> $payload */
    private function enqueue(array $payload, bool $fetch = false): bool {
        $path = $this->queuePath();

        if (is_file($path) && (int)filesize($path) > self::MAX_QUEUE_BYTES) {
            Minz_Log::error('Hister: file d\'attente pleine (' . self::MAX_QUEUE_BYTES . ' octets), article non conservé : ' . ($payload['url'] ?? ''));
            return false;
        }

        $line = json_encode(['queued_at' => time(), 'fetch' => $fetch, 'payload' => $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false || @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            Minz_Log::error('Hister: écriture impossible dans la file d\'attente ' . $path);
            return false;
        }
        return true;
    }

    /** Nombre d'articles en attente d'envoi (hors doublons d'URL non dédoublonnés). */
    public function queueSize(): int {
        $path = $this->queuePath();
        $count = 0;
        foreach ([$path . '.processing', $path] as $file) {
            if (is_file($file)) {
                $fh = @fopen($file, 'rb');
                if ($fh !== false) {
                    while (fgets($fh) !== false) {
                        $count++;
                    }
                    fclose($fh);
                }
            }
        }
        return $count;
    }

    /**
     * Renvoie à Hister les articles mis en file d'attente (en récupérant d'abord la page via FlareSolverr
     * si l'article est marqué « à récupérer »). S'arrête dès que Hister ou FlareSolverr est indisponible,
     * ou quand le temps imparti est écoulé.
     * @return array{sent:int,dropped:int,remaining:int}
     */
    public function flushQueue(int $limit = 100, int $maxSeconds = 40): array {
        $result = ['sent' => 0, 'dropped' => 0, 'remaining' => 0];
        $path = $this->queuePath();
        $proc = $path . '.processing';

        if (!is_file($path) && !is_file($proc)) {
            return $result;
        }

        // Un seul vidage à la fois (actualisation en cron + page de configuration, par exemple).
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $result['remaining'] = $this->queueSize();
            return $result;
        }

        try {
            // Les ajouts récents sont déplacés atomiquement, puis fusionnés à la suite des éléments en cours.
            if (is_file($path)) {
                $incoming = $path . '.in';
                if (@rename($path, $incoming)) {
                    $data = (string)file_get_contents($incoming);
                    if (@file_put_contents($proc, $data, FILE_APPEND) !== false) {
                        @unlink($incoming);
                    }
                }
            }

            $items = [];
            if (is_file($proc)) {
                foreach (file($proc, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $item = json_decode($line, true);
                    if (is_array($item) && isset($item['payload']['url'])) {
                        $items[(string)$item['payload']['url']] = $item; // dédoublonnage par URL : le plus récent l'emporte
                    }
                }
            }

            $deadline = time() + max(5, $maxSeconds);
            $kept = [];
            $processed = 0;
            foreach ($items as $item) {
                if ($processed >= $limit || self::$circuitOpen || time() >= $deadline) {
                    $kept[] = $item;
                    continue;
                }

                $payload = $item['payload'];

                if (!empty($item['fetch']) && $this->isFlareEnabled()) {
                    if (self::$flareDown) {
                        $kept[] = $item; // FlareSolverr hors ligne : on réessaiera plus tard
                        continue;
                    }
                    $processed++;
                    $page = $this->fetchHtml((string)$payload['url']);
                    if ($page['status'] === 'down') {
                        $kept[] = $item;
                        continue;
                    }
                    if ($page['status'] === 'ok') {
                        $payload['html'] = $page['html'];
                        unset($payload['text']);
                        $item['fetch'] = false;
                    } else {
                        $item['fetch_attempts'] = (int)($item['fetch_attempts'] ?? 0) + 1;
                        if ($item['fetch_attempts'] < 3) {
                            $kept[] = $item;
                            continue;
                        }
                        // Page inaccessible même via FlareSolverr : on envoie au moins le texte du flux.
                        $item['fetch'] = false;
                    }
                } else {
                    $processed++;
                }

                $status = $this->postPayload($payload);
                if ($status === 'ok') {
                    $result['sent']++;
                } elseif ($status === 'retry') {
                    $item['payload'] = $payload; // conserve le HTML déjà récupéré
                    $kept[] = $item;
                } else {
                    $result['dropped']++;
                }
            }

            if ($kept === []) {
                @unlink($proc);
            } else {
                $out = '';
                foreach ($kept as $item) {
                    $out .= json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                }
                file_put_contents($proc, $out, LOCK_EX);
            }
            $result['remaining'] = count($kept);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $result;
    }

    // ---------------------------------------------------------------------
    // FlareSolverr
    // ---------------------------------------------------------------------

    /**
     * Récupère le HTML d'une page via FlareSolverr (contournement des protections anti-bot).
     * @return array{status:'ok'|'down'|'fail',html:?string}
     */
    private function fetchHtml(string $url): array {
        if (self::$flareDown) {
            return ['status' => 'down', 'html' => null];
        }

        $timeout = $this->getFlareTimeout();
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->getFlareUrl() . '/v1',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'cmd' => 'request.get',
                'url' => $url,
                'maxTimeout' => $timeout * 1000,
            ], JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeout + 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '' || $httpCode === 0 || $httpCode >= 502) {
            self::$flareDown = true;
            Minz_Log::warning('Hister: FlareSolverr injoignable (' . ($curlError !== '' ? $curlError : 'HTTP ' . $httpCode) . ').');
            return ['status' => 'down', 'html' => null];
        }

        $data = json_decode((string)$response, true);
        $solution = is_array($data) ? ($data['solution'] ?? null) : null;
        if (!is_array($data) || ($data['status'] ?? '') !== 'ok' || !is_array($solution)) {
            Minz_Log::warning('Hister: FlareSolverr n\'a pas pu charger ' . $url . ' — ' . mb_substr((string)($data['message'] ?? $httpCode), 0, 200));
            return ['status' => 'fail', 'html' => null];
        }

        $pageStatus = (int)($solution['status'] ?? 0);
        $html = (string)($solution['response'] ?? '');
        if ($pageStatus < 200 || $pageStatus >= 300 || trim($html) === '' || strlen($html) > self::MAX_HTML_BYTES) {
            Minz_Log::warning('Hister: page inutilisable via FlareSolverr (' . $url . ', statut ' . $pageStatus . ', ' . strlen($html) . ' octets).');
            return ['status' => 'fail', 'html' => null];
        }

        return ['status' => 'ok', 'html' => $html];
    }

    public function testFlareSolverr(): string {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->getFlareUrl() . '/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            return 'FlareSolverr injoignable : ' . $curlError;
        }
        $data = json_decode((string)$response, true);
        if ($httpCode === 200 && is_array($data) && isset($data['msg'])) {
            return 'FlareSolverr répond : ' . $data['msg'] . (isset($data['version']) ? ' (v' . $data['version'] . ')' : '');
        }
        return 'Réponse inattendue de ' . $this->getFlareUrl() . ' (HTTP ' . $httpCode . ').';
    }

    // ---------------------------------------------------------------------
    // Nettoyage des liens de tracking
    // ---------------------------------------------------------------------

    private const TRACKING_PARAMS = [
        'fbclid', 'gclid', 'gclsrc', 'dclid', 'gbraid', 'wbraid', 'msclkid', 'yclid', 'twclid', 'ttclid',
        'li_fat_id', 'igshid', 'igsh', 'mc_cid', 'mc_eid', '_hsenc', '_hsmi', 'hsctatracking', 'mkt_tok',
        'vero_id', 'oly_enc_id', 'oly_anon_id', '__s', 'rb_clickid', 's_kwcid', 'ef_id', 'cmpid', 'ocid',
        'spm', 'wt_mc', 'xtor', 'ref_src', 'ref_url', 'trk', 'trkid', '_ga', '_gl', 'at_medium', 'at_campaign',
        'sr_share', 'soc_src', 'soc_trk', 'ncid', 'cid_source', 'feedburner', 'ito', 'sthash',
    ];

    private const TRACKING_PREFIXES = ['utm_', 'pk_', 'mtm_', 'matomo_', 'hmb_', 'trk_', 'pd_rd_', 'pf_rd_', 'itm_'];

    private function isTrackingParam(string $key): bool {
        $key = strtolower($key);
        if (in_array($key, self::TRACKING_PARAMS, true)) {
            return true;
        }
        foreach (self::TRACKING_PREFIXES as $prefix) {
            if (strncmp($key, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }
        foreach (explode(',', strtolower($this->getExtraTrackingParams())) as $extra) {
            $extra = trim($extra);
            if ($extra === '') {
                continue;
            }
            if (substr($extra, -1) === '*') {
                $prefix = rtrim($extra, '*');
                if ($prefix !== '' && strncmp($key, $prefix, strlen($prefix)) === 0) {
                    return true;
                }
            } elseif ($key === $extra) {
                return true;
            }
        }
        return false;
    }

    /** Supprime les paramètres et fragments de tracking, et déballe quelques redirections connues. */
    public function cleanUrl(string $url, int $depth = 0): string {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return $url;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';

        // Redirections de suivi (Google, Facebook) : on garde la destination.
        if ($depth < 2 && $query !== '') {
            $target = null;
            parse_str($query, $q);
            if (in_array($host, ['www.google.com', 'google.com'], true) && $path === '/url') {
                $target = $q['url'] ?? ($q['q'] ?? null);
            } elseif (in_array($host, ['l.facebook.com', 'lm.facebook.com'], true) && $path === '/l.php') {
                $target = $q['u'] ?? null;
            }
            if (is_string($target) && preg_match('~^https?://~i', $target) === 1) {
                return $this->cleanUrl($target, $depth + 1);
            }
        }

        if ($query !== '') {
            $kept = [];
            foreach (explode('&', $query) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $key = urldecode(explode('=', $pair, 2)[0]);
                if (!$this->isTrackingParam($key)) {
                    $kept[] = $pair;
                }
            }
            $query = implode('&', $kept);
        }

        $fragment = $parts['fragment'] ?? '';
        if ($fragment !== '' && preg_match('/^(xtor|utm_|ref=|ito=|xt=)/i', $fragment) === 1) {
            $fragment = '';
        }

        $clean = $parts['scheme'] . '://';
        if (isset($parts['user'])) {
            $clean .= $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
        }
        $clean .= $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path;
        if ($query !== '') {
            $clean .= '?' . $query;
        }
        if ($fragment !== '') {
            $clean .= '#' . $fragment;
        }
        return $clean;
    }

    // ---------------------------------------------------------------------
    // Mise en file de tous les articles existants
    // ---------------------------------------------------------------------

    /**
     * Parcourt les articles déjà en base (du plus récent au plus ancien) et les met en file d'attente.
     * La position est mémorisée : on peut relancer pour continuer.
     * @return array{queued:int,done:bool}
     */
    public function enqueueAllExisting(int $maxSeconds = 20): array {
        $cursor = (string)$this->getUserConfigurationValue('bulk_cursor');
        $deadline = time() + max(5, $maxSeconds);
        $queued = 0;
        $done = false;
        $seen = [];
        $entryDAO = FreshRSS_Factory::createEntryDao();
        $fetch = $this->isFlareEnabled();

        while (time() < $deadline) {
            try {
                $rows = $entryDAO->listWhere('a', 0, FreshRSS_Entry::STATE_ALL, 'DESC', 300, $cursor);
                $entries = method_exists('FreshRSS_Entry', 'fromTraversable')
                    ? FreshRSS_Entry::fromTraversable($rows)
                    : $rows;
            } catch (Throwable $e) {
                Minz_Log::error('Hister: lecture des articles impossible (' . $e->getMessage() . ')');
                break;
            }

            $new = 0;
            $last = $cursor;
            foreach ($entries as $entry) {
                if (!$entry instanceof FreshRSS_Entry) {
                    continue;
                }
                $id = (string)$entry->id();
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $new++;
                $last = $id;

                $payload = $this->buildPayload($entry, false);
                if ($payload !== null && $this->enqueue($payload, $fetch)) {
                    $queued++;
                }
            }

            if ($new === 0) {
                $done = true;
                break;
            }
            $cursor = $last;
        }

        $this->setUserConfigurationValue('bulk_cursor', $done ? '' : $cursor);
        return ['queued' => $queued, 'done' => $done];
    }
}
