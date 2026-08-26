<?php
/**
 * Zanora-compatible comment AJAX API
 * Routes: /ajax/comment/{action}/{id?}
 */
require_once($_SERVER['DOCUMENT_ROOT'] . '/_config.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/src/ajax/cm-up.php');
require_once(__DIR__ . '/render.php');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$path = trim((string)($_GET['__path'] ?? $_GET['slug'] ?? ''), '/');
$parts = $path === '' ? [] : explode('/', $path);
$action = strtolower($parts[0] ?? '');
$id = $parts[1] ?? null;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$userId = isset($_COOKIE['userID']) ? (int)$_COOKIE['userID'] : null;
$currentUser = null;

if ($userId) {
    $stmt = $conn->prepare('SELECT id, username, image FROM users WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $currentUser = $stmt->get_result()->fetch_assoc() ?: null;
    }
}

try {
    switch ($action) {
        case 'widget':
            $animeId = (string)$id;
            $episodeId = (int)($_GET['episodeId'] ?? $_GET['ep'] ?? 1);
            $sort = (string)($_GET['sort'] ?? 'newest');
            $type = (string)($_GET['type'] ?? 'episode');
            $system = new CommentSystem($conn, $episodeId, $animeId);
            $comments = $system->getComments(1, 20, $sort, $type === 'all');
            $count = $system->getCommentCount($type === 'all');
            echo json_encode([
                'status' => true,
                'html' => comment_render_widget($comments, $count, $episodeId, $sort, $type, $currentUser),
                'gotoId' => null,
                'cParentId' => null,
            ]);
            break;

        case 'list':
            $animeId = (string)$id;
            $episodeId = (int)($_GET['episodeId'] ?? 1);
            $page = max(1, (int)($_GET['page'] ?? 1));
            $sort = (string)($_GET['sort'] ?? 'newest');
            $type = (string)($_GET['type'] ?? 'episode');
            $system = new CommentSystem($conn, $episodeId, $animeId);
            $comments = $system->getComments($page, 20, $sort, $type === 'all');
            $nextPage = count($comments) >= 20 ? $page + 1 : 0;
            echo json_encode([
                'status' => true,
                'html' => comment_render_list($comments, $episodeId, $type, $currentUser),
                'nextPage' => $nextPage,
            ]);
            break;

        case 'replies':
            $commentId = (int)$id;
            $system = new CommentSystem($conn, 0, '');
            $replies = $system->getRepliesPublic($commentId);
            echo json_encode([
                'status' => true,
                'html' => comment_render_list($replies, 0, 'episode', $currentUser, true),
            ]);
            break;

        case 'add':
            if ($method !== 'POST') {
                throw new RuntimeException('Method not allowed');
            }
            if (!$userId || !$currentUser) {
                echo json_encode(['status' => false, 'msg' => 'Please login to comment']);
                break;
            }
            $content = trim((string)($_POST['content'] ?? ''));
            if ($content === '') {
                echo json_encode(['status' => false, 'msg' => 'Comment content cannot be empty']);
                break;
            }
            $animeId = (string)($_POST['movie_id'] ?? $_POST['anime_id'] ?? '');
            $episodeId = (int)($_POST['episode_id'] ?? 1);
            $parentId = (int)($_POST['parent_id'] ?? 0);
            $isSpoil = !empty($_POST['is_spoil']) ? 1 : 0;
            if ($animeId === '') {
                echo json_encode(['status' => false, 'msg' => 'Missing anime ID']);
                break;
            }
            $avatar = !empty($currentUser['image']) ? $currentUser['image'] : '/public/images/no-avatar.jpeg';
            $system = new CommentSystem($conn, $episodeId, $animeId);
            $result = $system->addComment(
                $content,
                $currentUser['username'],
                $avatar,
                $isSpoil,
                $parentId > 0 ? $parentId : null
            );
            if (empty($result['success'])) {
                echo json_encode(['status' => false, 'msg' => $result['message'] ?? 'Failed to add comment']);
                break;
            }
            $comment = $result['comment'];
            $comment['reply_count'] = 0;
            $comment['userReaction'] = null;
            if ($parentId > 0) {
                echo json_encode([
                    'status' => true,
                    'parentId' => $parentId,
                    'html' => comment_render_list([$comment], $episodeId, 'episode', $currentUser, true),
                ]);
            } else {
                $comments = $system->getComments(1, 20, 'newest', false);
                echo json_encode([
                    'status' => true,
                    'parentId' => 0,
                    'html' => comment_render_list($comments, $episodeId, 'episode', $currentUser),
                ]);
            }
            break;

        case 'vote':
            if ($method !== 'POST') {
                throw new RuntimeException('Method not allowed');
            }
            if (!$userId) {
                echo json_encode(['status' => false, 'msg' => 'Please login']);
                break;
            }
            $commentId = (int)($_POST['id'] ?? 0);
            $type = (int)($_POST['type'] ?? 1);
            $system = new CommentSystem($conn, 0, '');
            $result = $system->addReaction($commentId, $type);
            echo json_encode([
                'status' => !empty($result['success']),
                'msg' => $result['message'] ?? '',
                'likes' => $result['likes'] ?? 0,
                'dislikes' => $result['dislikes'] ?? 0,
                'userReaction' => $result['userReaction'] ?? null,
            ]);
            break;

        case 'report':
            echo json_encode(['status' => true, 'msg' => 'Report submitted. Thanks!']);
            break;

        case 'update':
            echo json_encode(['status' => true, 'msg' => 'Updated']);
            break;

        default:
            http_response_code(404);
            echo json_encode(['status' => false, 'msg' => 'Unknown comment action']);
    }
} catch (Throwable $e) {
    error_log('Comment API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'msg' => 'Server error']);
}
