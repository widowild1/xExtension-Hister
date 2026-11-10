<?php

declare(strict_types=1);

class HisterExtension extends Minz_Extension {

    private const DEFAULT_URL = 'http://127.0.0.1:4433';
    private const DEFAULT_ENDPOINT = '/api/add';
    private const MAX_TEXT_CHARS = 200000;

    /** Une fois Hister injoignable pendant une requête, on n'insiste plus (évite de bloquer l'actualisation des flux). */
    private static bool $circuitOpen = false;

    /** Résultat de la dernière synchronisation manuelle (affiché dans la page de configuration). */
    public string $syncResult = '';

    public function init(): void {
        $this->registerTranslates();
        $this->registerController('hister');
        Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));

        Minz_ExtensionManager::addHook('entry_before_display', [$this, 'addSendLink']);

        if ($this->isAutoSyncAll()) {
            Minz_ExtensionManager::addHook('entry_before_insert', [$this, 'hookEntryBeforeInsert']);
        }
        if ($this->isSyncStarred() || $this->isSyncLabeled()) {
            Minz_ExtensionManager::addHook('post_update', [$this, 'hookPostUpdate']);
        }
    }

    public function handleConfigureAction(): void {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
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

            if (Minz_Request::param('sync_now', '') !== '') {
                $r = $this->syncMarkedEntries(200);
                $this->syncResult = $r['sent'] . ' envoyé(s), ' . $r['failed'] . ' échec(s)';
            }
        }
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

                if ($this->sendToHister($entry, true)) {
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

    public function sendToHister(FreshRSS_Entry $entry, bool $withLabels = false): bool {
        if (self::$circuitOpen) {
            return false;
        }

        $url = (string)$entry->link();
        if ($url === '') {
            return false;
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
        $payload = [
            'url' => $url,
            'title' => html_entity_decode($entry->title(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'text' => $text,
            'label' => implode(', ', $tags),
            'metadata' => $metadata,
        ];

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: FreshRSS-Hister-Client/2.2.1',
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
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->isVerifySsl(),
            CURLOPT_SSL_VERIFYHOST => $this->isVerifySsl() ? 2 : 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            self::$circuitOpen = true;
            Minz_Log::error('Hister: instance injoignable (' . $curlError . '), envois suspendus pour cette requête.');
            return false;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            Minz_Log::notice('Hister: envoyé ' . $url . ' (HTTP ' . $httpCode . ')');
            return true;
        }

        if ($httpCode === 401 || $httpCode === 403) {
            self::$circuitOpen = true;
        }
        Minz_Log::error('Hister: rejet HTTP ' . $httpCode . ' pour ' . $url . ' — ' . mb_substr((string)$response, 0, 200));
        return false;
    }
}
