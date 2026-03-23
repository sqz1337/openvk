<?php

declare(strict_types=1);

namespace openvk\Web\Models\Repositories;

use Chandler\Database\DatabaseConnection;
use Nette\Database\Table\ActiveRow;

final class AIBots
{
    public function get(int $id): ?ActiveRow
    {
        return DatabaseConnection::i()->getContext()->table("ai_bots")->get($id);
    }

    public function getByUserId(int $userId): ?ActiveRow
    {
        return DatabaseConnection::i()->getContext()->table("ai_bots")->where("user_id", $userId)->fetch();
    }

    public function register(int $userId, string $name, string $persona, int $minIntervalMinutes = 3): ActiveRow
    {
        $ctx      = DatabaseConnection::i()->getContext();
        $existing = $this->getByUserId($userId);

        if ($existing) {
            $existing->update([
                "name"                 => $name,
                "persona"              => $persona,
                "min_interval_minutes" => max(1, $minIntervalMinutes),
                "is_enabled"           => 1,
                "updated_at"           => date("Y-m-d H:i:s"),
            ]);

            return $existing;
        }

        return $ctx->table("ai_bots")->insert([
            "user_id"               => $userId,
            "name"                  => $name,
            "persona"               => $persona,
            "is_enabled"            => 1,
            "min_interval_minutes"  => max(1, $minIntervalMinutes),
            "created_at"            => date("Y-m-d H:i:s"),
            "updated_at"            => date("Y-m-d H:i:s"),
        ]);
    }

    public function pickNext(?int $botId = null): ?ActiveRow
    {
        if (!is_null($botId)) {
            return DatabaseConnection::i()->getContext()
                ->table("ai_bots")
                ->where("id", $botId)
                ->where("is_enabled", 1)
                ->fetch();
        }

        $bots = DatabaseConnection::i()->getContext()
            ->table("ai_bots")
            ->where("is_enabled", 1)
            ->fetchAll();

        usort($bots, static function (ActiveRow $a, ActiveRow $b): int {
            if (is_null($a->last_run_at) && is_null($b->last_run_at)) {
                return (int) $a->id <=> (int) $b->id;
            }

            if (is_null($a->last_run_at)) {
                return -1;
            }

            if (is_null($b->last_run_at)) {
                return 1;
            }

            return strtotime((string) $a->last_run_at) <=> strtotime((string) $b->last_run_at);
        });

        $now = time();
        foreach ($bots as $bot) {
            if (is_null($bot->last_run_at)) {
                return $bot;
            }

            $nextRunAt = strtotime((string) $bot->last_run_at) + ((int) $bot->min_interval_minutes * 60);
            if ($nextRunAt <= $now) {
                return $bot;
            }
        }

        return null;
    }

    public function touchRun(int $botId): void
    {
        $this->get($botId)?->update([
            "last_run_at" => date("Y-m-d H:i:s"),
            "updated_at"  => date("Y-m-d H:i:s"),
        ]);
    }

    public function touchAction(int $botId): void
    {
        $this->get($botId)?->update([
            "last_action_at" => date("Y-m-d H:i:s"),
            "updated_at"     => date("Y-m-d H:i:s"),
        ]);
    }

    public function logAction(int $botId, string $action, string $status, array $payload = [], array $result = []): void
    {
        DatabaseConnection::i()->getContext()->table("ai_bot_action_logs")->insert([
            "bot_id"       => $botId,
            "action"       => $action,
            "status"       => $status,
            "payload_json" => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            "result_json"  => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            "created_at"   => date("Y-m-d H:i:s"),
        ]);
    }
}
