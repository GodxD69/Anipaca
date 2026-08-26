<?php
class CommentSystem {
    private $conn;
    private $anime_id;
    private $episode_id;
    private $user_id;

    public function __construct($conn, $episode_id, $anime_id) {
        $this->conn = $conn;
        $this->episode_id = (int)$episode_id;
        $this->anime_id = (string)$anime_id;
        $this->user_id = isset($_COOKIE['userID']) ? (int)$_COOKIE['userID'] : null;
    }

    public function getComments($page = 1, $limit = 20, $sort = 'newest', $allEpisodes = false) {
        $offset = max(0, ($page - 1) * $limit);
        $order = 'c.created_at DESC';
        if ($sort === 'oldest') {
            $order = 'c.created_at ASC';
        } elseif ($sort === 'top') {
            $order = 'likes DESC, c.created_at DESC';
        }

        $episodeSql = $allEpisodes ? '' : 'AND c.episode_id = ?';
        $query = "
            SELECT 
                c.*,
                (SELECT COUNT(*) FROM comment_reactions cr WHERE cr.comment_id = c.id AND cr.type = 1) as likes,
                (SELECT COUNT(*) FROM comment_reactions cr WHERE cr.comment_id = c.id AND cr.type = 0) as dislikes,
                (SELECT COUNT(*) FROM comments r WHERE r.parent_id = c.id) as reply_count
            FROM comments c
            WHERE c.anime_id = ?
            {$episodeSql}
            AND c.parent_id IS NULL
            ORDER BY {$order}
            LIMIT ? OFFSET ?
        ";

        $stmt = $this->conn->prepare($query);
        if ($allEpisodes) {
            $stmt->bind_param('sii', $this->anime_id, $limit, $offset);
        } else {
            $stmt->bind_param('siii', $this->anime_id, $this->episode_id, $limit, $offset);
        }
        $stmt->execute();
        $comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($comments as &$comment) {
            $comment['userReaction'] = $this->getUserReaction((int)$comment['id']);
        }
        return $comments;
    }

    public function getCommentCount($allEpisodes = false): int {
        $episodeSql = $allEpisodes ? '' : 'AND episode_id = ?';
        $query = "SELECT COUNT(*) as cnt FROM comments WHERE anime_id = ? {$episodeSql} AND parent_id IS NULL";
        $stmt = $this->conn->prepare($query);
        if ($allEpisodes) {
            $stmt->bind_param('s', $this->anime_id);
        } else {
            $stmt->bind_param('si', $this->anime_id, $this->episode_id);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int)($row['cnt'] ?? 0);
    }

    public function getRepliesPublic($parent_id) {
        $query = "
            SELECT 
                c.*,
                (SELECT COUNT(*) FROM comment_reactions cr WHERE cr.comment_id = c.id AND cr.type = 1) as likes,
                (SELECT COUNT(*) FROM comment_reactions cr WHERE cr.comment_id = c.id AND cr.type = 0) as dislikes,
                0 as reply_count
            FROM comments c
            WHERE c.parent_id = ?
            ORDER BY c.created_at ASC
        ";
        $stmt = $this->conn->prepare($query);
        $pid = (int)$parent_id;
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $replies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($replies as &$reply) {
            $reply['userReaction'] = $this->getUserReaction((int)$reply['id']);
        }
        return $replies;
    }

    public function addComment($content, $username, $avatar_url, $is_spoiler = 0, $parent_id = null) {
        try {
            if ($this->anime_id === '') {
                return ['success' => false, 'message' => 'Invalid anime ID'];
            }

            $user_id = $this->user_id ?: 0;
            $is_spoiler = $is_spoiler ? 1 : 0;
            $parent = $parent_id ? (int)$parent_id : null;
            $episode_id = $this->episode_id;
            $anime_id = $this->anime_id;

            if ($parent === null) {
                $stmt = $this->conn->prepare("
                    INSERT INTO comments 
                    (content, username, user_avatar, episode_id, anime_id, user_id, is_spoiler, parent_id, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NOW())
                ");
                if (!$stmt) {
                    return ['success' => false, 'message' => 'Failed to prepare statement'];
                }
                $stmt->bind_param(
                    'sssissi',
                    $content,
                    $username,
                    $avatar_url,
                    $episode_id,
                    $anime_id,
                    $user_id,
                    $is_spoiler
                );
            } else {
                $stmt = $this->conn->prepare("
                    INSERT INTO comments 
                    (content, username, user_avatar, episode_id, anime_id, user_id, is_spoiler, parent_id, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                if (!$stmt) {
                    return ['success' => false, 'message' => 'Failed to prepare statement'];
                }
                $stmt->bind_param(
                    'sssissii',
                    $content,
                    $username,
                    $avatar_url,
                    $episode_id,
                    $anime_id,
                    $user_id,
                    $is_spoiler,
                    $parent
                );
            }

            if ($stmt->execute()) {
                $comment_id = $this->conn->insert_id;
                return [
                    'success' => true,
                    'message' => 'Comment added successfully',
                    'comment' => [
                        'id' => $comment_id,
                        'content' => $content,
                        'username' => $username,
                        'user_avatar' => $avatar_url,
                        'episode_id' => $this->episode_id,
                        'anime_id' => $this->anime_id,
                        'user_id' => $user_id,
                        'is_spoiler' => $is_spoiler,
                        'parent_id' => $parent,
                        'created_at' => date('Y-m-d H:i:s'),
                        'likes' => 0,
                        'dislikes' => 0,
                        'reply_count' => 0,
                    ]
                ];
            }
            return ['success' => false, 'message' => 'Failed to add comment'];
        } catch (Exception $e) {
            error_log('Exception in addComment: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error processing comment'];
        }
    }

    public function addReaction($comment_id, $type) {
        if (!$this->user_id) {
            return ['success' => false, 'message' => 'User not logged in'];
        }

        try {
            $this->conn->begin_transaction();
            $comment_id = (int)$comment_id;
            $type = (int)$type;

            $stmt = $this->conn->prepare("
                SELECT type FROM comment_reactions 
                WHERE comment_id = ? AND user_id = ?
            ");
            $stmt->bind_param('ii', $comment_id, $this->user_id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();

            if ($existing) {
                if ((int)$existing['type'] === $type) {
                    $stmt = $this->conn->prepare("
                        DELETE FROM comment_reactions 
                        WHERE comment_id = ? AND user_id = ?
                    ");
                    $stmt->bind_param('ii', $comment_id, $this->user_id);
                    $stmt->execute();
                } else {
                    $stmt = $this->conn->prepare("
                        UPDATE comment_reactions 
                        SET type = ? 
                        WHERE comment_id = ? AND user_id = ?
                    ");
                    $stmt->bind_param('iii', $type, $comment_id, $this->user_id);
                    $stmt->execute();
                }
            } else {
                $stmt = $this->conn->prepare("
                    INSERT INTO comment_reactions (comment_id, user_id, type) 
                    VALUES (?, ?, ?)
                ");
                $stmt->bind_param('iii', $comment_id, $this->user_id, $type);
                $stmt->execute();
            }

            $this->conn->commit();

            return [
                'success' => true,
                'likes' => $this->getReactionCount($comment_id, 1),
                'dislikes' => $this->getReactionCount($comment_id, 0),
                'userReaction' => $this->getUserReaction($comment_id),
            ];
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log('Reaction error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update reaction'];
        }
    }

    private function getReactionCount($comment_id, $type) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count 
            FROM comment_reactions 
            WHERE comment_id = ? AND type = ?
        ");
        $stmt->bind_param('ii', $comment_id, $type);
        $stmt->execute();
        return (int)$stmt->get_result()->fetch_assoc()['count'];
    }

    private function getUserReaction($comment_id) {
        if (!$this->user_id) return null;
        $stmt = $this->conn->prepare("
            SELECT type 
            FROM comment_reactions 
            WHERE comment_id = ? AND user_id = ?
        ");
        $stmt->bind_param('ii', $comment_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0 ? (int)$result->fetch_assoc()['type'] : null;
    }
}
