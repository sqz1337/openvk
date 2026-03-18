<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities;

use Chandler\Database\DatabaseConnection;
use Chandler\Signaling\SignalManager;
use Chandler\Security\Authenticator;
use Nette\Database\Table\ActiveRow;
use openvk\Web\Models\Repositories\{Clubs, Messages, Users};
use openvk\Web\Models\RowModel;
use openvk\Web\Util\DateTime;

class Conversation extends RowModel
{
    protected $tableName = "conversations";

    public function __construct(?ActiveRow $ar = null)
    {
        parent::__construct($ar);
    }

    public function getTitle(): string
    {
        return $this->getRecord()->title ?? "Беседа";
    }

    public function getURL(): string
    {
        return "/im/c" . $this->getId();
    }

    public function getSettingsURL(): string
    {
        return "/im/c" . $this->getId() . "/settings";
    }

    public function getCreator(): ?User
    {
        return (new Users())->get((int) $this->getRecord()->creator);
    }

    public function getCreatedTime(): DateTime
    {
        return new DateTime((int) $this->getRecord()->created);
    }

    public function canBeModifiedBy(RowModel $participant): bool
    {
        $relation = $this->getParticipantRelation($participant);
        return !is_null($relation) && ((bool) $relation->is_admin || $this->getCreator()?->getId() === $participant->getId());
    }

    public function getUpdatedTime(): DateTime
    {
        return new DateTime((int) $this->getRecord()->updated);
    }

    public function getLastMessage(): ?Message
    {
        $id = $this->getRecord()->last_message_id;
        if (is_null($id)) {
            return null;
        }

        return (new Messages())->get((int) $id);
    }

    private function resolveParticipant(string $type, int $id): ?RowModel
    {
        if ($type === User::class) {
            return (new Users())->get($id);
        } elseif ($type === Club::class) {
            return (new Clubs())->get($id);
        }

        return null;
    }

    private function getParticipantRelation(RowModel $participant): ?ActiveRow
    {
        return DatabaseConnection::i()->getContext()->table("conversation_participants")->where([
            "conversation_id"  => $this->getId(),
            "participant_type" => get_class($participant),
            "participant_id"   => $participant->getId(),
            "deleted"          => 0,
        ])->where("left_at IS NULL")->fetch();
    }

    public function getParticipants(bool $activeOnly = true): array
    {
        $selection = DatabaseConnection::i()->getContext()->table("conversation_participants")
            ->where("conversation_id", $this->getId());
        if ($activeOnly) {
            $selection = $selection->where("deleted", 0)->where("left_at IS NULL");
        }

        $rows = iterator_to_array($selection);
        $participants = [];
        foreach ($rows as $participant) {
            $entity = $this->resolveParticipant($participant->participant_type, (int) $participant->participant_id);
            if (!is_null($entity)) {
                $participants[] = $entity;
            }
        }

        return $participants;
    }

    public function getDisplayParticipants(?RowModel $viewer = null): array
    {
        $participants = $this->getParticipants();
        if (!is_null($viewer)) {
            $participants = array_values(array_filter($participants, fn (RowModel $participant): bool => !(
                get_class($participant) === get_class($viewer) && $participant->getId() === $viewer->getId()
            )));
        }

        return $participants;
    }

    public function getParticipantCount(bool $activeOnly = true): int
    {
        $selection = DatabaseConnection::i()->getContext()->table("conversation_participants")
            ->where("conversation_id", $this->getId());
        if ($activeOnly) {
            $selection = $selection->where("deleted", 0)->where("left_at IS NULL");
        }

        return $selection->count("*");
    }

    public function isParticipant(RowModel $participant): bool
    {
        return !is_null($this->getParticipantRelation($participant));
    }

    public function addParticipant(RowModel $participant, bool $isAdmin = false): void
    {
        $participants = DatabaseConnection::i()->getContext()->table("conversation_participants");
        $existing = $participants->where([
            "conversation_id"  => $this->getId(),
            "participant_type" => get_class($participant),
            "participant_id"   => $participant->getId(),
        ])->fetch();

        if (!is_null($existing) && (int) $existing->deleted === 0 && is_null($existing->left_at)) {
            return;
        }

        if (!is_null($existing)) {
            $existing->update([
                "joined"               => time(),
                "last_read_message_id" => null,
                "is_admin"             => (int) $isAdmin,
                "left_at"              => null,
                "deleted"              => 0,
            ]);
            return;
        }

        $participants->insert([
            "conversation_id"      => $this->getId(),
            "participant_type"     => get_class($participant),
            "participant_id"       => $participant->getId(),
            "joined"               => time(),
            "last_read_message_id" => null,
            "is_admin"             => (int) $isAdmin,
            "left_at"              => null,
            "deleted"              => 0,
        ]);
    }

    public function removeParticipant(RowModel $participant): void
    {
        if ($this->getCreator()?->getId() === $participant->getId() && get_class($participant) === User::class) {
            return;
        }

        $relation = $this->getParticipantRelation($participant);
        if (is_null($relation)) {
            return;
        }

        $relation->update([
            "left_at"  => time(),
            "deleted"  => 1,
            "is_admin" => 0,
        ]);
    }

    public function getMessages(int $capBehavior = 1, ?int $cap = null, ?int $limit = null, ?int $padding = null, bool $reverse = false): array
    {
        $selection = DatabaseConnection::i()->getContext()
            ->table("messages")
            ->where([
                "conversation_id" => $this->getId(),
                "deleted"         => 0,
            ]);

        if (!is_null($cap)) {
            $selection = $selection->where($capBehavior === 1 ? "id < ?" : "id > ?", $cap);
        }

        $selection = $selection->order($reverse ? "created ASC" : "created DESC");
        $selection = $selection->limit($limit ?? OPENVK_DEFAULT_PER_PAGE, $padding ?? 0);

        $messages = [];
        foreach ($selection as $row) {
            $messages[] = new Message($row);
        }

        if (is_null($cap)) {
            $latest = $messages[0] ?? $this->getLastMessage();
            $this->markReadUpTo($latest);
        }

        return $messages;
    }

    public function getUnreadStateFor(RowModel $participant): bool
    {
        $lastMessage = $this->getLastMessage();
        if (is_null($lastMessage)) {
            return false;
        }

        $relation = $this->getParticipantRelation($participant);
        if (is_null($relation) || is_null($relation->last_read_message_id)) {
            return true;
        }

        return (int) $relation->last_read_message_id < $lastMessage->getId();
    }

    public function isMessageReadFor(Message $message, RowModel $participant): bool
    {
        $sender = $message->getSender();
        if (!is_null($sender) && get_class($sender) === get_class($participant) && $sender->getId() === $participant->getId()) {
            return true;
        }

        $relation = $this->getParticipantRelation($participant);
        if (is_null($relation) || is_null($relation->last_read_message_id)) {
            return false;
        }

        return (int) $relation->last_read_message_id >= $message->getId();
    }

    public function sendMessage(Message $message, ?RowModel $sender = null)
    {
        $sender ??= (new Users())->getByChandlerUser(Authenticator::i()->getUser());
        if (is_null($sender) || !$this->isParticipant($sender)) {
            return false;
        }

        $creator = $this->getCreator();
        if (is_null($creator)) {
            return false;
        }

        $message->setSender_Id($sender->getId());
        $message->setSender_Type(get_class($sender));
        $message->setRecipient_Id($creator->getId());
        $message->setRecipient_Type(User::class);
        $message->setConversation_Id($this->getId());
        $message->setCreated(time());
        $message->setUnread(0);
        $message->save();

        $this->setUpdated(time());
        $this->setLast_Message_Id($message->getId());
        $this->save();

        $this->markReadUpTo($message);

        $event = new \openvk\Web\Events\NewMessageEvent($message);
        foreach ($this->getParticipants() as $participant) {
            if ($participant instanceof User && $participant->getId() !== $sender->getId()) {
                SignalManager::i()->triggerEvent($event, $participant->getId());
            }
        }

        return $message;
    }

    public function getPreviewSubtitle(?RowModel $viewer = null): string
    {
        $participants = $this->getDisplayParticipants($viewer);
        $names = array_map(fn (RowModel $participant): string => $participant->getCanonicalName(), $participants);
        if (sizeof($names) < 1) {
            return "Только вы";
        }

        return implode(", ", array_slice($names, 0, 3));
    }

    public function getAvatarURL(string $size = "miniscule", ?RowModel $viewer = null): string
    {
        $avatarFile = $this->getRecord()->avatar_file ?? null;
        if (!is_null($avatarFile) && $avatarFile !== "") {
            return ovk_scheme(true) . $_SERVER["HTTP_HOST"] . "/im/c" . $this->getId() . "/avatar";
        }

        $participants = $this->getDisplayParticipants($viewer);
        if (sizeof($participants) > 0) {
            return $participants[0]->getAvatarURL($size);
        }

        $creator = $this->getCreator();
        if (!is_null($creator)) {
            return $creator->getAvatarURL($size);
        }

        $serverUrl = ovk_scheme(true) . $_SERVER["HTTP_HOST"];
        return "$serverUrl/assets/packages/static/openvk/img/messages.svg";
    }

    public function getAvatarPath(): ?string
    {
        $avatarFile = $this->getRecord()->avatar_file ?? null;
        if (is_null($avatarFile) || $avatarFile === "") {
            return null;
        }

        $baseDir = OPENVK_ROOT . "/storage/conversations";
        return $baseDir . "/" . basename($avatarFile);
    }

    public function markReadUpTo(?Message $message): void
    {
        $user = DatabaseConnection::i()->getContext();
        $participant = (new Users())->getByChandlerUser(Authenticator::i()->getUser());
        if (!$participant) {
            return;
        }

        $user->table("conversation_participants")->where([
            "conversation_id"  => $this->getId(),
            "participant_type" => User::class,
            "participant_id"   => $participant->getId(),
            "deleted"          => 0,
        ])->update([
            "last_read_message_id" => is_null($message) ? null : $message->getId(),
        ]);
    }
}
