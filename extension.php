<?php

declare(strict_types=1);

class FreshExtension_Hister extends Minz_Extension {

    public function init(): void {
        $this->registerTranslates();
        $this->registerController('hister');

        // Ajout du bouton d'action sous chaque article
        Minz_ExtensionManager::addHook('menu_entry', [$this, 'addMenuEntry']);

        // Synchronisation automatique si activée
        if ((bool)$this->getUserConfigurationValue('auto_sync_all')) {
            Minz_ExtensionManager::addHook('entry_before_insert', [$this, 'hookEntryBeforeInsert']);
        }
    }

    public function handleConfigureAction(): void {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            FreshRSS_Form_Token::validate();

            $apiUrl = rtrim((string)Minz_Request::param('api_url', 'https://demo.hister.org'), '/');
            $apiToken = (string)Minz_Request::param('api_token', '');
            $endpointPath = '/' . ltrim((string)Minz_Request::param('endpoint_path', '/api/v1/bookmarks'), '/');
            $defaultTags = (string)Minz_Request::param('default_tags', '');
            $includeCategory = !empty(Minz_Request::param('include_category_tag'));
            $includeFeed = !empty(Minz_Request::param('include_feed_tag'));
            $autoSyncAll = !empty(Minz_Request::param('auto_sync_all'));
            $verifySsl = !empty(Minz_Request::param('verify_ssl'));

            $this->setUserConfigurationValue('api_url', $apiUrl);
            $this->setUserConfigurationValue('api_token', $apiToken);
            $this->setUserConfigurationValue('endpoint_path', $endpointPath);
            $this->setUserConfigurationValue('default_tags', $defaultTags);
            $this->setUserConfigurationValue('include_category_tag', $includeCategory);
            $this->setUserConfigurationValue('include_feed_tag', $includeFeed);
            $this->setUserConfigurationValue('auto_sync_all', $autoSyncAll);
            $this->setUserConfigurationValue('verify_ssl', $verifySsl);
        }
    }

    public function addMenuEntry(FreshRSS_Entry $entry): string {
        $sendUrl = _url('hister', 'send', 'id', $entry->id());
        $title = _t('ext.hister.send_button_title');

        return '<li class="item manage hister-action">'
            . '<a class="btn hister-send-btn" href="' . $sendUrl . '" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" data-id="' . $entry->id() . '">'
            . '<svg class="icon" viewBox="0 0 24 24" width="16" height="16" fill="currentColor">'
            . '<path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>'
            . '</svg>'
            . '</a></li>';
    }

    public function hookEntryBeforeInsert(FreshRSS_Entry $entry): FreshRSS_Entry {
        $this->sendToHister($entry);
        return $entry;
    }

    public function sendToHister(FreshRSS_Entry $entry): bool {
        $apiUrl = (string)$this->getUserConfigurationValue('api_url');
        $apiToken = (string)$this->getUserConfigurationValue('api_token');
        $endpointPath = (string)$this->getUserConfigurationValue('endpoint_path');

        if ($apiUrl === '' || $apiToken === '') {
            Minz_Log::warning('Hister Extension: URL ou Token API manquant.');
            return false;
        }

        if ($endpointPath === '') {
            $endpointPath = '/api/v1/bookmarks';
        }

        $tags = [];
        $configuredTags = (string)$this->getUserConfigurationValue('default_tags');
        if ($configuredTags !== '') {
            $rawList = explode(',', $configuredTags);
            foreach ($rawList as $tag) {
                $clean = trim($tag);
                if ($clean !== '') {
                    $tags[] = $clean;
                }
            }
        }

        $feed = $entry->feed();
        if ($feed !== null) {
            if ((bool)$this->getUserConfigurationValue('include_category_tag')) {
                $cat = $feed->category();
                if ($cat !== null && trim($cat->name()) !== '') {
                    $tags[] = trim($cat->name());
                }
            }

            if ((bool)$this->getUserConfigurationValue('include_feed_tag')) {
                if (trim($feed->name()) !== '') {
                    $tags[] = trim($feed->name());
                }
            }
        }

        $tags = array_values(array_unique($tags));
        $rawContent = (string)$entry->content();
        $cleanNotes = mb_substr(trim(strip_tags($rawContent)), 0, 500, 'UTF-8');

        $payload = [
            'url' => $entry->link(),
            'title' => html_entity_decode($entry->title(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'tags' => $tags,
            'notes' => $cleanNotes,
            'timestamp' => $entry->date(true),
        ];

        $targetUrl = rtrim($apiUrl, '/') . '/' . ltrim($endpointPath, '/');
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $targetUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $apiToken,
                'User-Agent: FreshRSS-Hister-Client/2.0.0',
            ],
            CURLOPT_SSL_VERIFYPEER => (bool)$this->getUserConfigurationValue('verify_ssl'),
            CURLOPT_SSL_VERIFYHOST => (bool)$this->getUserConfigurationValue('verify_ssl') ? 2 : 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            Minz_Log::error('Hister API cURL failure: ' . $curlError);
            return false;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            Minz_Log::notice('Hister API: Succès (' . $entry->link() . ') Code HTTP ' . $httpCode);
            return true;
        }

        Minz_Log::error('Hister API rejet: HTTP ' . $httpCode . ' Réponse: ' . mb_substr((string)$response, 0, 200));
        return false;
    }
}