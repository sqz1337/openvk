<?php

declare(strict_types=1);

namespace openvk\Web\Models\Repositories;

use Chandler\Database\DatabaseConnection;
use openvk\Web\Models\Entities\{Conversation, User};
use openvk\Web\Models\RowModel;

class Conversations extends Repository
{
    protected $tableName = "conversations";
    protected $modelName = "Conversation";

    public function create(User $creator, ?string $title = null): Conversation
    {
        $now = time();
        $row = $this->table->insert([
            "creator"         => $creator->getId(),
            "title"           => $title,
            "created"         => $now,
            "updated"         => $now,
            "last_message_id" => null,
            "deleted"         => 0,
        ]);

        $conversation = $this->toEntity($row);
        $conversation->addParticipant($creator, true);

        return $conversation;
    }

    public function byParticipant(RowModel $participant, int $page = 1, ?int $perPage = null): \Traversable
    {
        $perPage ??= OPENVK_DEFAULT_PER_PAGE;
        $offset = ($page - 1) * $perPage;

        $selection = DatabaseConnection::i()->getContext()
            ->table("conversation_participants")
            ->where([
                "participant_type" => get_class($participant),
                "participant_id"   => $participant->getId(),
                "deleted"          => 0,
            ])
            ->where("left_at IS NULL")
            ->limit($perPage, $offset);

        foreach ($selection as $relation) {
            $conversation = $this->get((int) $relation->conversation_id);
            if (!is_null($conversation) && !(bool) $conversation->getRecord()->deleted) {
                yield $conversation;
            }
        }
    }

    public function countByParticipant(RowModel $participant): int
    {
        return DatabaseConnection::i()->getContext()
            ->table("conversation_participants")
            ->where([
                "participant_type" => get_class($participant),
                "participant_id"   => $participant->getId(),
                "deleted"          => 0,
            ])
            ->where("left_at IS NULL")
            ->count("*");
    }
}
