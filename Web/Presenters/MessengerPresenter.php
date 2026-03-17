<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use Chandler\Signaling\SignalManager;
use openvk\Web\Events\NewMessageEvent;
use openvk\Web\Models\Repositories\{Users, Clubs, Messages, Conversations};
use openvk\Web\Models\Entities\{Message, Correspondence, Conversation};

final class MessengerPresenter extends OpenVKPresenter
{
    private $messages;
    private $conversations;
    private $signaler;
    protected $presenterName = "messenger";

    public function __construct(Messages $messages, Conversations $conversations)
    {
        $this->messages = $messages;
        $this->conversations = $conversations;
        $this->signaler = SignalManager::i();

        parent::__construct();
    }

    private function releaseSessionLock(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function getCorrespondent(int $id): object
    {
        if ($id > 0) {
            return (new Users())->get($id);
        } elseif ($id < 0) {
            return (new Clubs())->get(abs($id));
        } elseif ($id === 0) {
            return $this->user->identity;
        }
    }

    private function getConversation(int $id): ?Conversation
    {
        return $this->conversations->get($id);
    }

    private function serializeConversation(Conversation $conversation): array
    {
        $lastMsg = $conversation->getLastMessage();
        $author  = is_null($lastMsg) ? null : $lastMsg->getSender();
        $avatar  = $conversation->getAvatarURL('miniscule', $this->user->identity);

        return [
            "url"          => $conversation->getURL(),
            "title"        => $conversation->getTitle(),
            "subtitle"     => $conversation->getPreviewSubtitle($this->user->identity),
            "avatar"       => $avatar,
            "participantCount" => $conversation->getParticipantCount(),
            "lastMessage"  => is_null($lastMsg) ? null : [
                "uuid"         => $lastMsg->getId(),
                "text"         => $lastMsg->getText(),
                "time"         => $lastMsg->getSendTimeHumanized(),
                "unread"       => $conversation->getUnreadStateFor($this->user->identity),
                "senderOwn"    => !is_null($author) && $author->getId() === $this->user->identity->getId(),
                "senderAvatar" => is_null($author) ? null : $author->getAvatarURL('miniscule'),
            ],
        ];
    }

    private function serializeConversationMessage(Conversation $conversation, Message $message): array
    {
        $data = $message->simplify();
        $data["read"] = $conversation->isMessageReadFor($message, $this->user->identity);

        return $data;
    }

    private function serializeCorrespondence(Correspondence $correspondence): array
    {
        $recipient = $correspondence->getCorrespondents()[1];
        $lastMsg   = $correspondence->getPreviewMessage();
        $author    = $lastMsg->getSender();

        return [
            "url"       => $correspondence->getURL(),
            "recipient" => [
                "name"   => $recipient->getCanonicalName(),
                "url"    => $recipient->getURL(),
                "avatar" => $recipient->getAvatarURL('miniscule'),
            ],
            "lastMessage" => [
                "uuid"       => $lastMsg->getId(),
                "text"       => $lastMsg->getText(),
                "time"       => $lastMsg->getSendTimeHumanized(),
                "unread"     => (bool) $lastMsg->getUnreadState(),
                "senderOwn"  => $author->getId() === $this->user->identity->getId(),
                "senderAvatar" => $author->getAvatarURL('miniscule'),
            ],
        ];
    }

    public function renderIndex(): void
    {
        $this->assertUserLoggedIn();

        if (isset($_GET["sel"])) {
            $this->pass("openvk!Messenger->app", $_GET["sel"]);
        }

        $page = (int) ($_GET["p"] ?? 1);
        $correspondences = iterator_to_array($this->messages->getCorrespondencies($this->user->identity, $page));
        $conversations   = iterator_to_array($this->conversations->byParticipant($this->user->identity, 1));

        // #КакаоПрокакалось

        $this->template->conversations = $conversations;
        $this->template->corresps = $correspondences;
        $this->template->paginatorConf = (object) [
            "count"   => $this->messages->getCorrespondenciesCount($this->user->identity),
            "page"    => (int) ($_GET["p"] ?? 1),
            "amount"  => sizeof($this->template->corresps),
            "perPage" => OPENVK_DEFAULT_PER_PAGE,
            "tidy"    => false,
            "atTop"   => false,
        ];
    }

    public function renderApp(int $sel): void
    {
        $this->assertUserLoggedIn();

        $correspondent = $this->getCorrespondent($sel);
        if (!$correspondent) {
            $this->notFound();
        }

        if (!$this->user->identity->getPrivacyPermission('messages.write', $correspondent)) {
            $this->flash("err", tr("warning"), tr("user_may_not_reply"));
        }

        $this->template->disable_ajax  = 1;
        $this->template->selId         = $sel;
        $this->template->correspondent = $correspondent;
    }

    public function renderCreateConversation(): void
    {
        $this->assertUserLoggedIn();
    }

    public function renderCreateConversationCommit(): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $rawParticipants = trim((string) $this->postParam("participants"));
        $title = trim((string) $this->postParam("title"));
        $ids = array_values(array_unique(array_filter(array_map("intval", preg_split('/[\s,;]+/', $rawParticipants) ?: []))));

        if (sizeof($ids) < 1) {
            $this->flash("err", tr("error"), "Укажите хотя бы одного участника.");
            $this->redirect("/im/create-conversation");
        }

        $participants = [];
        foreach ($ids as $id) {
            if ($id === $this->user->identity->getId() || $id < 1) {
                continue;
            }

            $user = (new Users())->get($id);
            if (!is_null($user)) {
                $participants[] = $user;
            }
        }

        if (sizeof($participants) < 1) {
            $this->flash("err", tr("error"), "Не удалось найти участников беседы.");
            $this->redirect("/im/create-conversation");
        }

        $conversation = $this->conversations->create($this->user->identity, $title === "" ? null : $title);
        $this->conversations->addParticipants($conversation, $participants);

        $this->flash("succ", tr("changes_saved"), "Беседа создана.");
        $this->redirect($conversation->getURL());
    }

    public function renderConversation(int $id): void
    {
        $this->assertUserLoggedIn();

        $conversation = $this->getConversation($id);
        if (is_null($conversation) || !$conversation->isParticipant($this->user->identity)) {
            $this->notFound();
        }

        $this->template->disable_ajax = 1;
        $this->template->conversation = $conversation;
        $this->template->participants = $conversation->getParticipants();
    }

    public function renderConversationSettings(int $id): void
    {
        $this->assertUserLoggedIn();

        $conversation = $this->getConversation($id);
        if (is_null($conversation) || !$conversation->isParticipant($this->user->identity)) {
            $this->notFound();
        }
        if (!$conversation->canBeModifiedBy($this->user->identity)) {
            $this->flash("err", tr("error"), "Недостаточно прав для изменения беседы.");
            $this->redirect($conversation->getURL());
        }

        $this->template->conversation = $conversation;
    }

    public function renderConversationSettingsSave(int $id): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $conversation = $this->getConversation($id);
        if (is_null($conversation) || !$conversation->isParticipant($this->user->identity)) {
            $this->notFound();
        }
        if (!$conversation->canBeModifiedBy($this->user->identity)) {
            $this->flash("err", tr("error"), "Недостаточно прав для изменения беседы.");
            $this->redirect($conversation->getURL());
        }

        $title = trim((string) $this->postParam("title"));
        $conversation->setTitle($title === "" ? null : ovk_proc_strtr($title, 128));

        if (isset($_FILES["avatar"]) && $_FILES["avatar"]["error"] === UPLOAD_ERR_OK) {
            $this->storeConversationAvatar($conversation, $_FILES["avatar"]);
        }

        $conversation->setUpdated(time());
        $conversation->save();

        $this->flash("succ", tr("changes_saved"), "Настройки беседы сохранены.");
        $this->redirect($conversation->getSettingsURL());
    }

    public function renderConversationAvatar(int $id): void
    {
        $conversation = $this->getConversation($id);
        if (is_null($conversation)) {
            $this->notFound();
        }

        $path = $conversation->getAvatarPath();
        if (is_null($path) || !is_file($path)) {
            $this->notFound();
        }

        header("Content-Type: " . mime_content_type($path));
        header("Content-Length: " . filesize($path));
        header("Cache-Control: public, max-age=86400");
        readfile($path);
        exit;
    }

    public function renderEvents(int $randNum): void
    {
        $this->assertUserLoggedIn();
        $this->releaseSessionLock();

        header("Content-Type: application/json");
        $this->signaler->listen(function ($event, $id) {
            exit(json_encode([[
                "UUID"  => $id,
                "event" => $event->getLongPoolSummary(),
            ]]));
        }, $this->user->id);
    }

    public function renderVKEvents(int $id): void
    {
        header("Access-Control-Allow-Origin: *");
        header("Content-Type: application/json");

        if ($this->queryParam("act") !== "a_check") {
            header("HTTP/1.1 400 Bad Request");
            exit();
        } elseif (!$this->queryParam("key")) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $key       = $this->queryParam("key");
        $payload   = hex2bin(substr($key, 0, 16));
        $signature = hex2bin(substr($key, 16));
        if (($signature ^ (~CHANDLER_ROOT_CONF["security"]["secret"] | ((string) $id))) !== $payload) {
            exit(json_encode([
                "failed" => 3,
            ]));
        }

        $legacy = $this->queryParam("version") < 3;

        $time = intval($this->queryParam("wait"));

        if ($time > 60) {
            $time = 60;
        } elseif ($time == 0) {
            $time = 25;
        } // default

        $this->releaseSessionLock();

        $this->signaler->listen(function ($event, $eId) use ($id) {
            exit(json_encode([
                "ts"      => time(),
                "updates" => [
                    $event->getVKAPISummary($id),
                ],
            ]));
        }, $id, $time);
    }

    public function renderApiGetMessages(int $sel, int $lastMsg): void
    {
        $this->assertUserLoggedIn();

        $correspondent = $this->getCorrespondent($sel);
        if (!$correspondent) {
            $this->notFound();
        }

        $messages       = [];
        $correspondence = new Correspondence($this->user->identity, $correspondent);
        foreach ($correspondence->getMessages(1, $lastMsg === 0 ? null : $lastMsg, null, 0) as $message) {
            $messages[] = $message->simplify();
        }

        header("Content-Type: application/json");
        exit(json_encode($messages));
    }

    public function renderApiGetConversationMessages(int $id, int $lastMsg): void
    {
        $this->assertUserLoggedIn();

        $conversation = $this->getConversation($id);
        if (is_null($conversation) || !$conversation->isParticipant($this->user->identity)) {
            $this->notFound();
        }

        $messages = [];
        foreach ($conversation->getMessages(1, $lastMsg === 0 ? null : $lastMsg, null, 0) as $message) {
            $messages[] = $this->serializeConversationMessage($conversation, $message);
        }

        header("Content-Type: application/json");
        exit(json_encode($messages));
    }

    public function renderApiList(int $page = 1): void
    {
        $this->assertUserLoggedIn();

        $correspondences = iterator_to_array($this->messages->getCorrespondencies($this->user->identity, $page));
        $payload = array_map(fn (Correspondence $correspondence): array => $this->serializeCorrespondence($correspondence), $correspondences);

        header("Content-Type: application/json");
        exit(json_encode($payload));
    }

    public function renderApiConversationList(int $page = 1): void
    {
        $this->assertUserLoggedIn();

        $conversations = iterator_to_array($this->conversations->byParticipant($this->user->identity, $page));
        $payload = array_map(fn (Conversation $conversation): array => $this->serializeConversation($conversation), $conversations);

        header("Content-Type: application/json");
        exit(json_encode($payload));
    }

    public function renderApiWriteMessage(int $sel): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        if (empty($this->postParam("content"))) {
            header("HTTP/1.1 400 Bad Request");
            exit("<b>Argument error</b>: param 'content' expected to be string, undefined given.");
        }

        $sel = $this->getCorrespondent($sel);
        if ($sel->getId() !== $this->user->id && !$sel->getPrivacyPermission('messages.write', $this->user->identity)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $cor = new Correspondence($this->user->identity, $sel);
        $msg = new Message();
        $msg->setContent($this->postParam("content"));
        $cor->sendMessage($msg);

        header("HTTP/1.1 202 Accepted");
        header("Content-Type: application/json");
        exit(json_encode($msg->simplify()));
    }

    public function renderApiWriteConversationMessage(int $id): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        if (empty($this->postParam("content"))) {
            header("HTTP/1.1 400 Bad Request");
            exit("<b>Argument error</b>: param 'content' expected to be string, undefined given.");
        }

        $conversation = $this->getConversation($id);
        if (is_null($conversation) || !$conversation->isParticipant($this->user->identity)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $msg = new Message();
        $msg->setContent($this->postParam("content"));
        $conversation->sendMessage($msg, $this->user->identity);

        header("HTTP/1.1 202 Accepted");
        header("Content-Type: application/json");
        exit(json_encode($this->serializeConversationMessage($conversation, $msg)));
    }

    private function storeConversationAvatar(Conversation $conversation, array $upload): void
    {
        $imageInfo = @getimagesize($upload["tmp_name"]);
        if ($imageInfo === false) {
            $this->flash("err", tr("error"), "Нужно загрузить изображение.");
            $this->redirect($conversation->getSettingsURL());
        }

        $mimeToExtension = [
            "image/jpeg" => "jpg",
            "image/png"  => "png",
            "image/gif"  => "gif",
            "image/webp" => "webp",
        ];

        $mime = $imageInfo["mime"] ?? "";
        if (!isset($mimeToExtension[$mime])) {
            $this->flash("err", tr("error"), "Поддерживаются только JPG, PNG, GIF и WEBP.");
            $this->redirect($conversation->getSettingsURL());
        }

        $dir = OPENVK_ROOT . "/storage/conversations";
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->flash("err", tr("error"), "Не удалось создать директорию для аватаров бесед.");
            $this->redirect($conversation->getSettingsURL());
        }

        $fileName = "conversation_" . $conversation->getId() . "_" . bin2hex(random_bytes(8)) . "." . $mimeToExtension[$mime];
        $target = $dir . "/" . $fileName;
        if (!move_uploaded_file($upload["tmp_name"], $target)) {
            $this->flash("err", tr("error"), "Не удалось сохранить аватар беседы.");
            $this->redirect($conversation->getSettingsURL());
        }

        $oldPath = $conversation->getAvatarPath();
        if (!is_null($oldPath) && is_file($oldPath)) {
            @unlink($oldPath);
        }

        $conversation->setAvatar_File($fileName);
    }
}
