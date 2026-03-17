<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities;

use Chandler\Database\DatabaseConnection;
use Nette\Database\Table\ActiveRow;
use openvk\Web\Models\Repositories\{Clubs, Messages, Users};
use openvk\Web\Models\RowModel;
use openvk\Web\Util\DateTime;

class Conversation extends RowModel
{
    protected $tableName = "conversations";

    private $participants;

    public function __construct(?ActiveRow $ar = null)
    {
        parent::__construct($ar);

        $this->participants = DatabaseConnection::i()->getContext()->table("conversation_participants");
    }

    public function getTitle(): string
    {
        return $this->getRecord()->title ?? "Беседа";
    }

    public function getCreator(): ?User
    {
        return (new Users())->get((int) $this->getRecord()->creator);
    }

    public function getCreatedTime(): DateTime
    {
        return new DateTime((int) $this->getRecord()->created);
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

    public function getParticipants(bool $activeOnly = true): array
    {
        $selection = $this->participants->where("conversation_id", $this->getId());
        if ($activeOnly) {
            $selection = $selection->where("deleted", 0)->where("left_at IS NULL");
        }

        $participants = [];
        foreach ($selection as $participant) {
            $entity = $this->resolveParticipant($participant->participant_type, (int) $participant->participant_id);
            if (!is_null($entity)) {
                $participants[] = $entity;
            }
        }

        return $participants;
    }

    public function getParticipantCount(bool $activeOnly = true): int
    {
        $selection = $this->participants->where("conversation_id", $this->getId());
        if ($activeOnly) {
            $selection = $selection->where("deleted", 0)->where("left_at IS NULL");
        }

        return $selection->count("*");
    }

    public function isParticipant(RowModel $participant): bool
    {
        return $this->participants->where([
            "conversation_id"   => $this->getId(),
            "participant_type"  => get_class($participant),
            "participant_id"    => $participant->getId(),
            "deleted"           => 0,
        ])->where("left_at IS NULL")->count("*") > 0;
    }

    public function addParticipant(RowModel $participant, bool $isAdmin = false): void
    {
        if ($this->isParticipant($participant)) {
            return;
        }

        $this->participants->insert([
            "conversation_id"     => $this->getId(),
            "participant_type"    => get_class($participant),
            "participant_id"      => $participant->getId(),
            "joined"              => time(),
            "last_read_message_id"=> null,
            "is_admin"            => (int) $isAdmin,
            "left_at"             => null,
            "deleted"             => 0,
        ]);
    }

    public function markReadUpTo(?Message $message): void
    {
        $user = DatabaseConnection::i()->getContext();
        $participant = (new Users())->getByChandlerUser(\Chandler\Security\Authenticator::i()->getUser());
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
