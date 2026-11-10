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
}