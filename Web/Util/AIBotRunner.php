<?php

declare(strict_types=1);

namespace openvk\Web\Util;

use Chandler\Database\DatabaseConnection;
use Nette\Database\Table\ActiveRow;
use openvk\Web\Models\Entities\{Comment, Conversation, Message, Post, Notifications\CommentNotification, User};
use openvk\Web\Models\Repositories\{AIBots, Comments, Conversations, Posts, Users};

final class AIBotRunner
{
    private AIBots $bots;
    private Users $users;
    private Posts $posts;
    private Comments $comments;
    private Conversations $conversations;

    public function __construct()
    {
        $this->bots     = new AIBots();
        $this->users    = new Users();
        $this->posts    = new Posts();
        $this->comments = new Comments();
        $this->conversations = new Conversations();
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
            $validated = $this->validateDecision($this->applyFallbackDecision($validated, $bot, $user, $context), $context);

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
            "unread_conversations" => $this->collectUnreadConversations($bot, max(1, (int) ($conf["maxDirectMessages"] ?? 5)), $blocked["conversations"]),
            "recent_posts"    => $this->collectRecentPosts($bot, $since, max(1, (int) ($conf["maxPosts"] ?? 5)), $blocked["posts"]),
            "recent_comments" => $this->collectRecentComments($bot, $since, max(1, (int) ($conf["maxComments"] ?? 5)), $blocked["comments"]),
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

    private function collectUnreadConversations(User $bot, int $limit, array $blockedConversationIds = []): array
    {
        $rows = DatabaseConnection::i()->getConnection()->query(
            "SELECT c.id AS conversation_id, c.title, c.last_message_id
             FROM conversation_participants cp
             INNER JOIN conversations c ON c.id = cp.conversation_id
             WHERE cp.participant_type = ?
               AND cp.participant_id = ?
               AND cp.deleted = 0
               AND cp.left_at IS NULL
               AND c.deleted = 0
               AND c.last_message_id IS NOT NULL
               AND (cp.last_read_message_id IS NULL OR cp.last_read_message_id < c.last_message_id)
             ORDER BY c.updated DESC
             LIMIT ?",
            User::class,
            $bot->getId(),
            $limit
        );

        $items = [];
        foreach ($rows as $row) {
            $conversationId = (int) $row->conversation_id;
            if (in_array($conversationId, $blockedConversationIds, true)) {
                continue;
            }

            $conversation = $this->conversations->get($conversationId);
            if (!$conversation || !$conversation->isParticipant($bot)) {
                continue;
            }

            $lastMessage = $conversation->getLastMessage();
            if (!$lastMessage) {
                continue;
            }

            $sender = $lastMessage->getSender();
            $items[] = [
                "id"               => $conversationId,
                "title"            => $conversation->getTitle(),
                "last_message_id"  => $lastMessage->getId(),
                "last_sender_id"   => $sender?->getId(),
                "last_sender_name" => $sender?->getCanonicalName(),
                "last_text"        => $this->trimText($lastMessage->getText(), 240),
                "created"          => $lastMessage->getSendTimeHumanized(),
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

    private function collectRecentComments(User $bot, int $since, int $limit, array $blockedCommentIds = []): array
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

            if (in_array($comment->getId(), $blockedCommentIds, true)) {
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
                "liked"       => $comment->hasLikeFrom($bot),
            ];
        }

        return $items;
    }

    private function decide(ActiveRow $botRow, User $bot, array $context, bool $forceAction = false): array
    {
        $client = new DeepSeekClient();
        $forceInstruction = $forceAction
            ? "The context is actionable. You must not choose do_nothing. Pick the single best action from the allowed list and provide text when needed."
            : "Choose at most one action for the current step.";
        $messages = [
            [
                "role"    => "system",
                "content" => "You control a social media bot inside OpenVK. {$forceInstruction} Always return strict JSON only. Allowed actions: do_nothing, reply_to_message, reply_to_conversation, like_post, like_comment, comment_post, create_post. Prefer replying to unread direct messages first, then unread conversations. If there are fresh posts or comments and no messages need replies, usually choose like_post, like_comment, comment_post, or create_post instead of do_nothing. Do not repeat the same target if it is not present in context. Do not mention being an AI. Keep tone natural and short.",
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
                        "action"    => "do_nothing|reply_to_message|reply_to_conversation|like_post|like_comment|comment_post|create_post",
                        "target_id" => "message id for reply_to_message, conversation id for reply_to_conversation, post id for like_post/comment_post, comment id for like_comment; omit for create_post/do_nothing",
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
        $allowed = ["do_nothing", "reply_to_message", "reply_to_conversation", "like_post", "like_comment", "comment_post", "create_post"];
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

        if ($action === "reply_to_conversation") {
            $allowedIds = array_column($context["unread_conversations"], "id");
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

        if ($action === "like_comment") {
            $allowedIds = array_column($context["recent_comments"], "id");
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
            "reply_to_conversation" => $this->executeReplyToConversation($bot, (int) $decision["target_id"], (string) $decision["text"]),
            "like_post"        => $this->executeLikePost($bot, (int) $decision["target_id"]),
            "like_comment"     => $this->executeLikeComment($bot, (int) $decision["target_id"]),
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

    private function executeReplyToConversation(User $bot, int $conversationId, string $text): array
    {
        $conversation = $this->conversations->get($conversationId);
        if (!$conversation || !$conversation->isParticipant($bot)) {
            return ["status" => "failed", "error" => "Conversation is unavailable"];
        }

        $message = new Message();
        $message->setContent($this->trimText($text, max(50, (int) (OPENVK_ROOT_CONF["openvk"]["aiBots"]["responseMaxLength"] ?? 280))));
        $saved = $conversation->sendMessage($message, $bot);
        if (!$saved) {
            return ["status" => "failed", "error" => "Unable to reply to conversation"];
        }

        DatabaseConnection::i()->getContext()->table("conversation_participants")->where([
            "conversation_id"  => $conversationId,
            "participant_type" => User::class,
            "participant_id"   => $bot->getId(),
            "deleted"          => 0,
        ])->update([
            "last_read_message_id" => $saved->getId(),
        ]);

        return ["status" => "success", "message" => "Conversation reply sent", "message_id" => $saved->getId(), "conversation_id" => $conversationId];
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

    private function executeLikeComment(User $bot, int $commentId): array
    {
        $comment = $this->comments->get($commentId);
        if (!$comment || $comment->isDeleted() || !$comment->canBeViewedBy($bot)) {
            return ["status" => "failed", "error" => "Comment is unavailable"];
        }

        if (!$comment->hasLikeFrom($bot)) {
            $comment->toggleLike($bot);
        }

        return ["status" => "success", "message" => "Comment liked", "comment_id" => $commentId];
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
        $blockedComments = [];
        $blockedConversations = [];

        foreach ($recentActions as $action) {
            $name    = (string) ($action["action"] ?? "");
            $payload = $action["payload"] ?? [];
            $target  = (int) ($payload["target_id"] ?? 0);

            if ($name === "reply_to_message" && $target > 0) {
                $blockedMessages[] = $target;
            }

            if ($name === "reply_to_conversation" && $target > 0) {
                $blockedConversations[] = $target;
            }

            if (in_array($name, ["like_post", "comment_post"], true) && $target > 0) {
                $blockedPosts[] = $target;
            }

            if ($name === "like_comment" && $target > 0) {
                $blockedComments[] = $target;
            }
        }

        return [
            "messages" => array_values(array_unique(array_merge($blockedMessages, $runBlockedTargets["messages"] ?? []))),
            "posts"    => array_values(array_unique(array_merge($blockedPosts, $runBlockedTargets["posts"] ?? []))),
            "comments" => array_values(array_unique(array_merge($blockedComments, $runBlockedTargets["comments"] ?? []))),
            "conversations" => array_values(array_unique(array_merge($blockedConversations, $runBlockedTargets["conversations"] ?? []))),
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

        if ($action === "reply_to_conversation" && $targetId > 0) {
            $runBlockedTargets["conversations"] ??= [];
            $runBlockedTargets["conversations"][] = $targetId;
        }

        if (in_array($action, ["like_post", "comment_post"], true) && $targetId > 0) {
            $runBlockedTargets["posts"] ??= [];
            $runBlockedTargets["posts"][] = $targetId;
        }

        if ($action === "like_comment" && $targetId > 0) {
            $runBlockedTargets["comments"] ??= [];
            $runBlockedTargets["comments"][] = $targetId;
        }
    }

    private function hasActionableContext(array $context): bool
    {
        return sizeof($context["direct_messages"] ?? []) > 0
            || sizeof($context["unread_conversations"] ?? []) > 0
            || sizeof($context["recent_posts"] ?? []) > 0
            || sizeof($context["recent_comments"] ?? []) > 0;
    }

    private function applyFallbackDecision(array $decision, ActiveRow $botRow, User $bot, array $context): array
    {
        if (($decision["action"] ?? "do_nothing") !== "do_nothing") {
            return $decision;
        }

        if (!$this->hasActionableContext($context)) {
            return $decision;
        }

        $retry = $this->validateDecision($this->decide($botRow, $bot, $context, true), $context);
        if (($retry["action"] ?? "do_nothing") !== "do_nothing") {
            return $retry;
        }

        if (sizeof($context["direct_messages"] ?? []) > 0) {
            return [
                "action"    => "reply_to_message",
                "target_id" => (int) $context["direct_messages"][0]["id"],
                "text"      => "Привет. Сейчас увидел сообщение и решил ответить.",
                "reason"    => "fallback_direct_message",
            ];
        }

        if (sizeof($context["unread_conversations"] ?? []) > 0) {
            return [
                "action"    => "reply_to_conversation",
                "target_id" => (int) $context["unread_conversations"][0]["id"],
                "text"      => "Увидел новые сообщения в беседе, я на связи.",
                "reason"    => "fallback_conversation_reply",
            ];
        }

        foreach (($context["recent_posts"] ?? []) as $post) {
            if (!(bool) ($post["liked"] ?? false)) {
                return [
                    "action"    => "like_post",
                    "target_id" => (int) $post["id"],
                    "text"      => "",
                    "reason"    => "fallback_like_post",
                ];
            }
        }

        foreach (($context["recent_comments"] ?? []) as $comment) {
            if (!(bool) ($comment["liked"] ?? false)) {
                return [
                    "action"    => "like_comment",
                    "target_id" => (int) $comment["id"],
                    "text"      => "",
                    "reason"    => "fallback_like_comment",
                ];
            }
        }

        if (sizeof($context["recent_posts"] ?? []) > 0) {
            return [
                "action"    => "comment_post",
                "target_id" => (int) $context["recent_posts"][0]["id"],
                "text"      => "Интересная мысль, зацепило.",
                "reason"    => "fallback_comment_post",
            ];
        }

        return [
            "action"    => "create_post",
            "target_id" => null,
            "text"      => "Сегодня хочется что-то написать и просто быть чуть активнее.",
            "reason"    => "fallback_create_post",
        ];
    }
}
