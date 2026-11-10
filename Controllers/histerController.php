<?php

declare(strict_types=1);

class FreshExtension_hister_Controller extends Minz_ActionController {

    public function sendAction(): void {
        if (!FreshRSS_Auth::hasAccess()) {
            Minz_Error::error(403);
            return;
        }

        $entryId = Minz_Request::param('id');
        $entryDAO = FreshRSS_Factory::createEntryDao();
        $entry = $entryDAO->searchById($entryId);

        if ($entry === null) {
            if (Minz_Request::isAjax()) {
                $this->view->_layout(false);
                header('Content-Type: application/json', true, 404);
                echo json_encode(['status' => 'error', 'message' => 'Article introuvable']);
                exit;
            }
            Minz_Request::bad(_t('feedback.sub.error'), ['c' => 'index', 'a' => 'index']);
            return;
        }

        $ext = Minz_ExtensionManager::findExtension('Hister');
        $success = false;

        if ($ext instanceof HisterExtension) {
            $success = $ext->sendToHister($entry, false, true, true);
        }

        if (Minz_Request::isAjax()) {
            $this->view->_layout(false);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => $success ? 'ok' : (($ext instanceof HisterExtension && $ext->wasQueued()) ? 'queued' : 'error'),
                'entry_id' => $entryId,
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

    /** Exécute une action de la page de configuration : enregistre les réglages, lance $task, affiche le résultat. */
    private function runConfigurationTask(callable $task): void {
        if (!FreshRSS_Auth::hasAccess() || !Minz_Request::isPost()) {
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
        } catch (Throwable $e) {
            Minz_Log::error('Hister: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
            $ok = false;
            $message = 'Erreur : ' . $e->getMessage();
        }
        $this->backToConfiguration($message, $ok);
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
}