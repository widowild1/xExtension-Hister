<?php

declare(strict_types=1);

final class FreshExtension_hister_Controller extends Minz_ActionController {

    public function sendAction(): void {
        if (!FreshRSS_Auth::hasAccess()) {
            Minz_Error::error(403);
            return;
        }

        if (!Minz_Request::isPost() || !FreshRSS_Auth::isCsrfOk()) {
            Minz_Error::error(403);
            return;
        }

        $entryId = Minz_Request::param('id');
        $entryDAO = FreshRSS_Factory::createEntryDao();
        $entry = $entryDAO->searchById($entryId);

        if ($entry === null) {
            if (Minz_Request::isAjax()) {
                $this->view->_layout(false);
                header('Content-Type: application/json; charset=UTF-8', true, 404);
                echo json_encode(['status' => 'error', 'message' => 'Article introuvable']);
                exit;
            }
            Minz_Request::bad(_t('feedback.sub.error'), ['c' => 'index', 'a' => 'index']);
            return;
        }

        /** @var HisterExtension|null $ext */
        $ext = Minz_ExtensionManager::findExtension('Hister');
        $success = false;

        if ($ext instanceof HisterExtension) {
            $success = $ext->sendToHister($entry, false, true, true);
        }

        if (Minz_Request::isAjax()) {
            $this->view->_layout(false);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'status' => $success ? 'ok' : (($ext instanceof HisterExtension && $ext->wasQueued()) ? 'queued' : 'error'),
                'entry_id' => $entryId,
                'message' => $success
                    ? _t('ext.hister.success_notification')
                    : (($ext instanceof HisterExtension && $ext->wasQueued())
                        ? _t('ext.hister.queued_notification')
                        : _t('ext.hister.error_notification')),
            ]);
            exit;
        }

        if ($success) {
            Minz_Request::good(_t('ext.hister.success_notification'), ['c' => 'index', 'a' => 'index']);
        } elseif ($ext instanceof HisterExtension && $ext->wasQueued()) {
            Minz_Request::good(_t('ext.hister.queued_notification'), ['c' => 'index', 'a' => 'index']);
        } else {
            Minz_Request::bad(_t('ext.hister.error_notification'), ['c' => 'index', 'a' => 'index']);
        }
    }

    private function histerExtension(): ?HisterExtension {
        $ext = Minz_ExtensionManager::findExtension('Hister');
        return $ext instanceof HisterExtension ? $ext : null;
    }

    private function backToConfiguration(string $message, bool $ok): void {
        $url = ['c' => 'extension', 'a' => 'configure', 'params' => ['e' => urlencode('Hister')]];
        if ($ok) {
            Minz_Request::good($message, $url);
        } else {
            Minz_Request::bad($message, $url);
        }
    }

    private function runConfigurationTask(callable $task): void {
        if (!FreshRSS_Auth::hasAccess()) {
            Minz_Error::error(403);
            return;
        }

        if (!Minz_Request::isPost() || !FreshRSS_Auth::isCsrfOk()) {
            Minz_Error::error(403);
            return;
        }

        $ext = $this->histerExtension();
        if ($ext === null) {
            Minz_Error::error(404);
            return;
        }

        try {
            $ext->saveSettingsFromRequest();
            [$ok, $message] = $task($ext);
        } catch (\Throwable $e) {
            Minz_Log::error('Hister: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
            $ok = false;
            $message = 'Erreur : ' . $e->getMessage();
        }

        $this->backToConfiguration($message, $ok);
    }

    public function testHisterAction(): void {
        $this->runConfigurationTask(static function (HisterExtension $ext): array {
            $message = $ext->testHisterInstance();
            return [strpos($message, 'succès') !== false, $message];
        });
    }

    public function flareAction(): void {
        $this->runConfigurationTask(static function (HisterExtension $ext): array {
            $message = $ext->testFlareSolverr();
            return [strpos($message, 'FlareSolverr répond') === 0, $message];
        });
    }

    public function bulkAction(): void {
        $this->runConfigurationTask(static function (HisterExtension $ext): array {
            $r = $ext->enqueueAllExisting(20);
            return [true, $r['queued'] . ' article(s) mis en file d\'attente, '
                . ($r['done'] ? 'terminé.' : 'cliquez à nouveau pour continuer.')];
        });
    }

    public function flushAction(): void {
        $this->runConfigurationTask(static function (HisterExtension $ext): array {
            $q = $ext->flushQueue(1000, 20);
            $r = $ext->syncMarkedEntries(200);
            return [true, ($q['sent'] + $r['sent']) . ' envoyé(s), ' . $r['failed'] . ' échec(s), '
                . $q['remaining'] . ' en file d\'attente.'];
        });
    }

    public function drainAction(): void {
        if (!FreshRSS_Auth::hasAccess() || !Minz_Request::isPost() || !FreshRSS_Auth::isCsrfOk()) {
            Minz_Error::error(403);
            return;
        }

        $running = !empty(trim((string)@shell_exec('/usr/bin/pgrep -f "drain_hister_queue\.py"')));
        if ($running) {
            $this->backToConfiguration('Le dépilage est déjà en cours d\'exécution.', true);
            return;
        }

        $ext = $this->histerExtension();
        $baseApiUrl = $ext instanceof HisterExtension ? rtrim($ext->getApiUrl(), '/') : '';
        $endpoint = $ext instanceof HisterExtension ? ('/' . ltrim($ext->getEndpointPath(), '/')) : '/api/add';
        $fullApiUrl = $baseApiUrl !== '' ? ($baseApiUrl . $endpoint) : '';
        $apiToken = $ext instanceof HisterExtension ? $ext->getApiToken() : '';
        $user = FreshRSS_Context::$user_conf->user ?? 'widowild';

        @unlink(DATA_PATH . '/hister-queue-' . $user . '.jsonl.lock');

        $envVars = sprintf(
            'HISTER_URL=%s HISTER_TOKEN=%s HISTER_USER=%s',
            escapeshellarg($fullApiUrl),
            escapeshellarg($apiToken),
            escapeshellarg($user)
        );

        $cmd = "{$envVars} /usr/bin/nohup /usr/bin/python3 -u /usr/local/bin/drain_hister_queue.py </dev/null > " . DATA_PATH . "/hister-drain.log 2>&1 &";
        exec($cmd);
        usleep(400000);

        $this->backToConfiguration('🚀 Dépilage lancé en arrière-plan avec succès !', true);
    }

    public function drainStopAction(): void {
        if (!FreshRSS_Auth::hasAccess() || !Minz_Request::isPost() || !FreshRSS_Auth::isCsrfOk()) {
            Minz_Error::error(403);
            return;
        }

        exec('/usr/bin/pkill -f "drain_hister_queue\.py"');
        $user = FreshRSS_Context::$user_conf->user ?? 'widowild';
        @unlink(DATA_PATH . '/hister-queue-' . $user . '.jsonl.lock');
        $this->backToConfiguration('Arrêt du dépilage demandé.', true);
    }

    public function drainStatusAction(): void {
        if (!FreshRSS_Auth::hasAccess()) {
            Minz_Error::error(403);
            return;
        }

        $this->view->_layout(false);
        header('Content-Type: application/json; charset=UTF-8');

        $running = !empty(trim((string)@shell_exec('/usr/bin/pgrep -f "drain_hister_queue\.py"')));
        $statusFile = DATA_PATH . '/hister-drain-status.json';
        $data = ['running' => $running];

        if (is_file($statusFile)) {
            $fileData = json_decode((string)file_get_contents($statusFile), true);
            if (is_array($fileData)) {
                $data = array_merge($fileData, ['running' => $running]);
            }
        }

        $ext = $this->histerExtension();
        $data['queue_size'] = $ext instanceof HisterExtension ? $ext->queueSize() : 0;

        echo json_encode($data);
        exit;
    }
}
