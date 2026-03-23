<?php

declare(strict_types=1);

namespace openvk\Web\Util;

use Chandler\Database\DatabaseConnection;
use Nette\Database\Table\ActiveRow;
use openvk\Web\Models\Entities\{Comment, Message, Post, Notifications\CommentNotification, User};
use openvk\Web\Models\Repositories\{AIBots, Comments, Posts, Users};

final class AIBotRunner
{
    private AIBots $bots;
    private Users $users;
    private Posts $posts;
    private Comments $comments;

    public function __construct()
    {
        $this->bots     = new AIBots();
        $this->users    = new Users();
        $this->posts    = new Posts();
        $this->comments = new Comments();
    }

    public function run(?int $botId = null, bool $dryRun = false): array
    {
        if (!(OPENVK_ROOT_CONF["openvk"]["aiBots"]["enable"] ?? false)) {
            throw new \RuntimeException("AI bots are disabled in config");
        }

        $bot = $this->bots->pickNext($botId);
        if (!$bot) {
            return [
                "status"  => "idle",
                "message" => "No eligible AI bot found",
            ];
        }

        if (!$dryRun) {
            $this->bots->touchRun((int) $bot->id);
        }

        $user = $this->users->get((int) $bot->user_id);
        if (!$user) {
            $this->bots->logAction((int) $bot->id, "none", "failed", [], ["error" => "Bot user not found"]);
            throw new \RuntimeException("Bot user not found");
        }

        $conf              = OPENVK_ROOT_CONF["openvk"]["aiBots"] ?? [];
        $memoryMinutes     = max(1, (int) ($conf["actionMemoryMinutes"] ?? 30));
        $maxActionsPerRun  = max(1, min(3, (int) ($conf["maxActionsPerRun"] ?? 3)));
        $recentActions     = $this->bots->getRecentActions((int) $bot->id, $memoryMinutes);
        $runBlockedTargets = [];
        $actions           = [];

        for ($i = 0; $i < $maxActionsPerRun; $i++) {
            $context   = $this->buildContext($user, $recentActions, $runBlockedTargets);
            $decision  = $this->decide($bot, $user, $context);
            $validated = $this->validateDecision($decision, $context);

            if ($validated["action"] === "do_nothing") {
                if ($dryRun && sizeof($actions) === 0) {
                    return [
                        "status"   => "dry_run",
                        "bot"      => $bot->name,
                        "decision" => $validated,
                    ];
                }

                break;
            }

            if ($dryRun) {
                $actions[] = $validated;
                $this->rememberRunTarget($runBlockedTargets, $validated);
                continue;
            }

            $result = $this->executeDecision($user, $validated, $context);
            $this->bots->logAction((int) $bot->id, (string) $validated["action"], (string) ($result["status"] ?? "unknown"), $validated, $result);

            if (($result["status"] ?? "") !== "success") {
                $actions[] = [
                    "decision" => $validated,
                    "result"   => $result,
                ];
                break;
            }

            $this->bots->touchAction((int) $bot->id);
            $recentActions[] = [
                "action"  => (string) $validated["action"],
                "payload" => $validated,
                "result"  => $result,
            ];
            $this->rememberRunTarget($runBlockedTargets, $validated);
            $actions[] = [
                "decision" => $validated,
                "result"   => $result,
            ];
        }

        if ($dryRun) {
            return [
                "status"    => "dry_run",
                "bot"       => $bot->name,
                "decisions" => $actions,
            ];
        }

        if (sizeof($actions) === 0) {
            return [
                "status"  => "success",
                "bot"     => $bot->name,
                "message" => "Bot chose to do nothing",
                "actions" => [],
            ];
        }

        return [
            "status"  => "success",
            "bot"     => $bot->name,
            "actions" => $actions,
        ];
    }

    private function buildContext(User $bot, array $recentActions = [], array $runBlockedTargets = []): array
    {
        $conf            = OPENVK_ROOT_CONF["openvk"]["aiBots"] ?? [];
        $lookbackSeconds = max(5, (int) ($conf["lookbackMinutes"] ?? 30)) * 60;
        $since           = time() - $lookbackSeconds;
        $blocked         = $this->buildBlockedTargets($recentActions, $runBlockedTargets);

        return [
            "direct_messages" => $this->collectDirectMessages($bot, $since, max(1, (int) ($conf["maxDirectMessages"] ?? 5)), $blocked["messages"]),
            "recent_posts"    => $this->collectRecentPosts($bot, $since, max(1, (int) ($conf["maxPosts"] ?? 5)), $blocked["posts"]),
            "recent_comments" => $this->collectRecentComments($bot, $since, max(1, (int) ($conf["maxComments"] ?? 5))),
        ];
    }

    private function collectDirectMessages(User $bot, int $since, int $limit, array $blockedMessageIds = []): array
    {
        $rows = DatabaseConnection::i()->getContext()
            ->table("messages")
            ->where("recipient_type", User::class)
            ->where("recipient_id", $bot->getId())
            ->where("conversation_id IS NULL")
            ->where("deleted", 0)
            ->where("unread", 1)
            ->where("created >= ?", $since)
            ->order("created DESC")
            ->limit($limit);

        $items = [];
        foreach ($rows as $row) {
            $msg = new Message($row);
            $sender = $msg->getSender();
            if (!$sender || $sender->isDeleted()) {
                continue;
            }

            if (in_array($msg->getId(), $blockedMessageIds, true)) {
                continue;
            }

            $items[] = [
                "id"          => $msg->getId(),
                "sender_id"   => $sender->getId(),
                "sender_name" => $sender->getCanonicalName(),
                "text"        => $this->trimText($msg->getText(), 240),
                "created"     => $msg->getSendTimeHumanized(),
                "unread"      => $msg->isUnread(),
            ];
        }

        return $items;
    }

    private function collectRecentPosts(User $bot, int $since, int $limit, array $blockedPostIds = []): array
    {
        $rows = DatabaseConnection::i()->getContext()
            ->table("posts")
            ->where("owner != ?", $bot->getId())
            ->where("deleted", 0)
            ->where("suggested", 0)
            ->where("created >= ?", $since)
            ->order("created DESC")
            ->limit($limit);

        $items = [];
        foreach ($rows as $row) {
            $post = new Post($row);
            if ($post->getOwner(false)->isDeleted() || !$post->canBeViewedBy($bot)) {
                continue;
            }

            if (in_array($post->getId(), $blockedPostIds, true)) {
                continue;
            }

            $items[] = [
                "id"          => $post->getId(),
                "author_id"   => $post->getOwner(false)->getId(),
                "author_name" => $post->getOwner(false)->getCanonicalName(),
                "wall_id"     => $post->getTargetWall(),
                "text"        => $this->trimText($post->getText(), 260),
                "created"     => $post->getPublicationTime()->format("%d.%m.%y %T"),
                "liked"       => $post->hasLikeFrom($bot),
            ];
        }

        return $items;
    }

    private function collectRecentComments(User $bot, int $since, int $limit): array
    {
        $rows = DatabaseConnection::i()->getContext()
            ->table("comments")
            ->where("owner != ?", $bot->getId())
            ->where("deleted", 0)
            ->where("created >= ?", $since)
            ->order("created DESC")
            ->limit($limit);

        $items = [];
        foreach ($rows as $row) {
            $comment = new Comment($row);
            $target  = $comment->getTarget();
            if (!$target || !$target->canBeViewedBy($bot) || $comment->getOwner(false)->isDeleted()) {
                continue;
            }

            $items[] = [
                "id"          => $comment->getId(),
                "author_id"   => $comment->getOwner(false)->getId(),
                "author_name" => $comment->getOwner(false)->getCanonicalName(),
                "target_type" => get_class($target),
                "target_id"   => $target->getId(),
                "text"        => $this->trimText($comment->getText(), 220),
                "created"     => $comment->getPublicationTime()->format("%d.%m.%y %T"),
            ];
        }

        return $items;
    }

    private function decide(ActiveRow $botRow, User $bot, array $context): array
    {
        $client = new DeepSeekClient();
        $messages = [
            [
                "role"    => "system",
                "content" => "You control a social media bot inside OpenVK. Choose at most one action for the current step. Always return strict JSON only. Allowed actions: do_nothing, reply_to_message, like_post, comment_post, create_post. Prefer replying to unread direct messages first. Do not repeat the same target if it is not present in context. Do not mention being an AI. Keep tone natural and short. If context is weak, choose do_nothing.",
            ],
            [
                "role"    => "user",
                "content" => json_encode([
                    "bot_profile" => [
                        "id"       => $bot->getId(),
                        "name"     => $bot->getCanonicalName(),
                        "persona"  => (string) $botRow->persona,
                    ],
                    "limits" => [
                        "max_text_length" => max(50, (int) (OPENVK_ROOT_CONF["openvk"]["aiBots"]["responseMaxLength"] ?? 280)),
                        "max_post_length" => max(100, (int) (OPENVK_ROOT_CONF["openvk"]["aiBots"]["postMaxLength"] ?? 600)),
                    ],
                    "context" => $context,
                    "response_schema" => [
                        "action"    => "do_nothing|reply_to_message|like_post|comment_post|create_post",
                        "target_id" => "message id for reply_to_message, post id for like_post/comment_post; omit for create_post/do_nothing",
                        "text"      => "required for reply_to_message/comment_post/create_post, omit otherwise",
                        "reason"    => "short internal reason",
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        return $client->decide($messages);
    }

    private function validateDecision(array $decision, array $context): array
    {
        $action = (string) ($decision["action"] ?? "do_nothing");
        $allowed = ["do_nothing", "reply_to_message", "like_post", "comment_post", "create_post"];
        if (!in_array($action, $allowed, true)) {
            $action = "do_nothing";
        }

        $targetId = isset($decision["target_id"]) ? (int) $decision["target_id"] : null;
        $text     = trim((string) ($decision["text"] ?? ""));
        $reason   = trim((string) ($decision["reason"] ?? ""));

        if ($action === "reply_to_message") {
            $allowedIds = array_column($context["direct_messages"], "id");
            if (is_null($targetId) || !in_array($targetId, $allowedIds, true) || $text === "") {
                $action = "do_nothing";
            }
        }

        if (in_array($action, ["like_post", "comment_post"], true)) {
            $allowedIds = array_column($context["recent_posts"], "id");
            if (is_null($targetId) || !in_array($targetId, $allowedIds, true)) {
                $action = "do_nothing";
            }
        }

        if (in_array($action, ["comment_post", "create_post"], true) && $text === "") {
            $action = "do_nothing";
        }

        return [
            "action"    => $action,
            "target_id" => $targetId,
            "text"      => $text,
            "reason"    => $reason,
        ];
    }

    private function executeDecision(User $bot, array $decision, array $context): array
    {
        $action = $decision["action"];

        return match ($action) {
            "reply_to_message" => $this->executeReplyToMessage($bot, (int) $decision["target_id"], (string) $decision["text"]),
            "like_post"        => $this->executeLikePost($bot, (int) $decision["target_id"]),
            "comment_post"     => $this->executeCommentPost($bot, (int) $decision["target_id"], (string) $decision["text"]),
            "create_post"      => $this->executeCreatePost($bot, (string) $decision["text"]),
            default            => ["status" => "success", "message" => "Bot chose to do nothing", "context" => $context],
        };
    }

    private function executeReplyToMessage(User $bot, int $messageId, string $text): array
    {
        $row = DatabaseConnection::i()->getContext()->table("messages")->get($messageId);
        if (!$row) {
            return ["status" => "failed", "error" => "Message not found"];
        }

        $message = new Message($row);
        $sender  = $message->getSender();
        if (!$sender || !($sender instanceof User)) {
            return ["status" => "failed", "error" => "Unsupported message sender"];
        }

        $correspondence = new \openvk\Web\Models\Entities\Correspondence($bot, $sender);
        $reply = new Message();
        $reply->setContent($this->trimText($text, max(50, (int) (OPENVK_ROOT_CONF["openvk"]["aiBots"]["responseMaxLength"] ?? 280))));
        $saved = $correspondence->sendMessage($reply, true);

        if (!$saved) {
            return ["status" => "failed", "error" => "Unable to send reply"];
        }

        $row->update([
            "unread" => 0,
        ]);

        return ["status" => "success", "message" => "Reply sent", "message_id" => $saved->getId()];
    }

    private function executeLikePost(User $bot, int $postId): array
    {
        $post = $this->posts->get($postId);
        if (!$post || $post->isDeleted() || !$post->canBeViewedBy($bot)) {
            return ["status" => "failed", "error" => "Post is unavailable"];
        }

        if (!$post->hasLikeFrom($bot)) {
            $post->toggleLike($bot);
        }

        return ["status" => "success", "message" => "Post liked", "post_id" => $postId];
    }

    private function executeCommentPost(User $bot, int $postId, string $text): array
    {
        $post = $this->posts->get($postId);
        if (!$post || $post->isDeleted() || !$post->canBeViewedBy($bot)) {
            return ["status" => "failed", "error" => "Post is unavailable"];
        }

        $comment = new Comment();
        $comment->setOwner($bot->getId());
        $comment->setModel(Post::class);
        $comment->setTarget($post->getId());
        $comment->setContent($this->trimText($text, max(50, (int) (OPENVK_ROOT_CONF["openvk"]["aiBots"]["responseMaxLength"] ?? 280))));
        $comment->setCreated(time());
        $comment->setFlags(0);
        $comment->save();

        $owner = $post->getOwner(false);
        if ($owner instanceof User && $owner->getId() !== $bot->getId()) {
            (new CommentNotification($owner, $comment, $post, $bot))->emit();
        }

        return ["status" => "success", "message" => "Comment added", "comment_id" => $comment->getId(), "post_id" => $postId];
    }

    private function executeCreatePost(User $bot, string $text): array
    {
        $post = new Post();
        $post->setOwner($bot->getId());
        $post->setWall($bot->getId());
        $post->setCreated(time());
        $post->setContent($this->trimText($text, max(100, (int) (OPENVK_ROOT_CONF["openvk"]["aiBots"]["postMaxLength"] ?? 600))));
        $post->setAnonymous(false);
        $post->setFlags(0);
        $post->setNsfw(false);
        $post->save();

        return ["status" => "success", "message" => "Post created", "post_id" => $post->getId()];
    }

    private function trimText(string $text, int $maxLength): string
    {
        $text = trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(1, $maxLength - 3))) . "...";
    }

    private function buildBlockedTargets(array $recentActions, array $runBlockedTargets): array
    {
        $blockedMessages = [];
        $blockedPosts    = [];

        foreach ($recentActions as $action) {
            $name    = (string) ($action["action"] ?? "");
            $payload = $action["payload"] ?? [];
            $target  = (int) ($payload["target_id"] ?? 0);

            if ($name === "reply_to_message" && $target > 0) {
                $blockedMessages[] = $target;
            }

            if (in_array($name, ["like_post", "comment_post"], true) && $target > 0) {
                $blockedPosts[] = $target;
            }
        }

        return [
            "messages" => array_values(array_unique(array_merge($blockedMessages, $runBlockedTargets["messages"] ?? []))),
            "posts"    => array_values(array_unique(array_merge($blockedPosts, $runBlockedTargets["posts"] ?? []))),
        ];
    }

    private function rememberRunTarget(array &$runBlockedTargets, array $decision): void
    {
        $action   = (string) ($decision["action"] ?? "");
        $targetId = (int) ($decision["target_id"] ?? 0);

        if ($action === "reply_to_message" && $targetId > 0) {
            $runBlockedTargets["messages"] ??= [];
            $runBlockedTargets["messages"][] = $targetId;
        }

        if (in_array($action, ["like_post", "comment_post"], true) && $targetId > 0) {
            $runBlockedTargets["posts"] ??= [];
            $runBlockedTargets["posts"][] = $targetId;
        }
    }
}
