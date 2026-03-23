<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use Latte\Engine as TemplatingEngine;
use openvk\Web\Models\Entities\Notifications\Notification;
use openvk\Web\Models\Repositories\Notifications as NotificationsRepository;

final class NotificationPresenter extends OpenVKPresenter
{
    protected $presenterName = "notification";

    public function renderFeed(): void
    {
        $this->assertUserLoggedIn();

        $archive = $this->queryParam("act") === "archived";
        $count   = $this->user->identity->getNotificationsCount($archive);

        if ($count == 0 && $this->queryParam("act") == null) {
            $mode = "archived";
            $archive = true;
        } else {
            $mode = $archive ? "archived" : "new";
        }

        $this->template->mode     = $mode;
        $this->template->page     = (int) ($this->queryParam("p") ?? 1);
        $this->template->iterator = iterator_to_array($this->user->identity->getNotifications($this->template->page, $archive));
        $this->template->count    = $count;

        $this->user->identity->updateNotificationOffset();
        $this->user->identity->save();
    }

    private function encodeType(object $model): int
    {
        return (int) json_decode(file_get_contents(__DIR__ . "/../../data/modelCodes.json"), true)[get_class($model)];
    }

    private function serializeNotification(Notification $notification): array
    {
        $originType = $this->encodeType($notification->getModel(0));
        $targetType = $this->encodeType($notification->getModel(1));
        $actionCode = $notification->getActionCode();

        $tplDir = __DIR__ . "/templates/components/notifications/$actionCode";
        $tplId  = "$tplDir/_{$originType}_{$targetType}_.latte";
        if (!is_file($tplId)) {
            $tplId = "$tplDir/@default.latte";
        }

        $latte = new TemplatingEngine();
        $latte->setTempDirectory(CHANDLER_ROOT . "/tmp/cache/templates");
        $latte->addExtension(new \Latte\Essential\TranslatorExtension(tr(...)));

        return [
            "id"       => sha1(implode(":", [
                $actionCode,
                $originType,
                $targetType,
                $notification->getModel(0)->getId(),
                $notification->getModel(1)->getId(),
                $notification->getDateTime()->timestamp(),
                $notification->getData(),
            ])),
            "title"    => tr("notif_{$actionCode}_{$originType}_{$targetType}"),
            "body"     => trim(preg_replace('%(\s){2,}%', "$1", $latte->renderToString($tplId, [
                "notification" => $notification,
            ]))),
            "ava"      => $notification->getModel(1)->getAvatarURL(),
            "priority" => 1,
        ];
    }

    public function renderApiLatest(): void
    {
        $this->assertUserLoggedIn();

        $notifications = new NotificationsRepository();
        $items = iterator_to_array($notifications->getNotificationsByUser($this->user->identity, $this->user->identity->getNotificationOffset(), false, 1, 10));
        $items = array_map(fn (Notification $notification): array => $this->serializeNotification($notification), $items);

        header("Content-Type: application/json");
        exit(json_encode($items));
    }
}
