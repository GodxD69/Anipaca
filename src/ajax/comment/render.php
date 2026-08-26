<?php
/**
 * HTML render helpers matching Zanora comment widget markup.
 */

function comment_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function comment_time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime($datetime);
    if (!$ts) {
        return (string)$datetime;
    }
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    if ($diff < 2592000) return floor($diff / 604800) . ' weeks ago';
    if ($diff < 31536000) return floor($diff / 2592000) . ' months ago';
    return floor($diff / 31536000) . ' years ago';
}

function comment_avatar_url(?string $avatar): string
{
    $avatar = trim((string)$avatar);
    if ($avatar === '') {
        return '/public/images/no-avatar.jpeg';
    }
    return $avatar;
}

function comment_render_widget(array $comments, int $count, int $episodeId, string $sort, string $type, ?array $user): string
{
    $epLabel = 'Episode ' . max(1, $episodeId);
    $sort = in_array($sort, ['top', 'newest', 'oldest'], true) ? $sort : 'newest';
    $type = $type === 'all' ? 'all' : 'episode';

    $html = '<div class="sc-header">
    <div class="sc-h-from">
      <a class="btn btn-sm" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . ($type === 'all' ? 'All Episode' : $epLabel) . '<i class="fas fa-angle-down ml-2"></i></a>
      <div class="dropdown-menu dropdown-menu-model dropdown-menu-normal" aria-labelledby="ssc-list">
        <a class="dropdown-item cm-by ' . ($type === 'episode' ? 'active' : '') . '" data-value="episode" href="javascript:;">' . comment_h($epLabel) . ' ' . ($type === 'episode' ? '<i class="fas fa-check mt-2"></i>' : '') . '</a>
        <a class="dropdown-item cm-by ' . ($type === 'all' ? 'active' : '') . '" data-value="all" href="javascript:;">All Episode ' . ($type === 'all' ? '<i class="fas fa-check mt-2"></i>' : '') . '</a>
      </div>
    </div>
    <div class="sc-h-title"><i class="far fa-comment-alt mr-2"></i>' . (int)$count . '<span> Comments</span></div>
    <div class="sc-h-sort">
        <a class="btn btn-sm" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Sort by<i class="fas fa-sort ml-2"></i></a>
        <div class="dropdown-menu dropdown-menu-model dropdown-menu-normal" aria-labelledby="ssc-list">
            <a class="dropdown-item cm-sort ' . ($sort === 'top' ? 'active' : '') . '" data-value="top" href="javascript:;">Top ' . ($sort === 'top' ? '<i class="fas fa-check mt-2"></i>' : '') . '</a>
            <a class="dropdown-item cm-sort ' . ($sort === 'newest' ? 'active' : '') . '" data-value="newest" href="javascript:;">Newest ' . ($sort === 'newest' ? '<i class="fas fa-check mt-2"></i>' : '') . '</a>
            <a class="dropdown-item cm-sort ' . ($sort === 'oldest' ? 'active' : '') . '" data-value="oldest" href="javascript:;">Oldest ' . ($sort === 'oldest' ? '<i class="fas fa-check mt-2"></i>' : '') . '</a>
        </div>
    </div>
    <div class="clearfix"></div>
</div>';

    $html .= comment_render_input($episodeId, $user);
    $html .= '<div class="list-comment"><div class="cw_list">';
    if (empty($comments)) {
        $html .= '<div class="no-comments">No comments yet. Be the first!</div>';
    } else {
        $html .= comment_render_list($comments, $episodeId, $type, $user);
    }
    $html .= '</div></div>';
    $html .= '<script type="module" src="https://cdn.jsdelivr.net/npm/emoji-picker-element@^1/index.js"></script>
<script>
$(document).off("emoji-click.cm").on("emoji-click.cm", "emoji-picker", function (event) {
    var input = $($(event.target).data("input"));
    if (!input || !input.length) return;
    input.val((input.val() || "") + event.detail.unicode);
    input.focus();
});
</script>';

    return $html;
}

function comment_render_input(int $episodeId, ?array $user, int $parentId = 0): string
{
    $avatar = comment_avatar_url($user['image'] ?? '');
    $isReply = $parentId > 0;
    $inputClass = $isReply ? 'cm-input-' . $parentId : 'cm-input-base';
    $textareaId = $isReply ? '' : ' id="df-cm-content"';
    $placeholder = $isReply ? 'Add a reply' : 'Leave a comment';
    $buttonsId = $isReply ? '' : ' id="df-cm-buttons" style="display: none;"';
    $closeBtn = $isReply
        ? '<a class="btn btn-sm btn-secondary btn-close-reply" data-id="' . (int)$parentId . '">Close</a>'
        : '<a class="btn btn-sm btn-secondary" id="df-cm-close">Close</a>';
    $submitLabel = $isReply ? 'Reply' : 'Comment';

    if ($user) {
        $userLine = 'Comment as <span class="link-highlight ml-1">' . comment_h($user['username'] ?? 'User') . '</span>';
    } else {
        $userLine = 'You must be <a class="link-highlight ml-1 mr-1" href="javascript:;" data-toggle="modal" data-target="#modallogin">login</a> to post a comment';
    }

    $hiddenParent = $isReply
        ? '<input type="hidden" name="mention_id" value="0">
            <input type="hidden" name="parent_id" value="' . (int)$parentId . '">'
        : '';

    return '<div class="comment-input' . ($isReply ? ' is-reply reply-block' : '') . '"' . ($isReply ? ' id="reply-' . (int)$parentId . '" style="display:none;"' : '') . '>
<div class="user-avatar"><img class="user-avatar-img" src="' . comment_h($avatar) . '" alt="' . comment_h($user['username'] ?? 'guest') . '"></div>
<div class="ci-form"><div class="user-name">' . $userLine . '</div>
    <form class="preform preform-dark comment-form">
        <div class="loading-absolute bg-white" style="display: none;">
            <div class="loading"><div class="span1"></div><div class="span2"></div><div class="span3"></div></div>
            </div>
            ' . $hiddenParent . '
            <input type="hidden" name="episode_id" value="' . (int)$episodeId . '">
            <textarea' . $textareaId . ' name="content" class="form-control form-control-textarea comment-subject emo-on ' . $inputClass . '" maxlength="3000" placeholder="' . comment_h($placeholder) . '" required></textarea>
            <div class="ci-emo">
                <div data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="cb-icon"><i class="fas fa-laugh"></i></div>
                <div class="dropdown-menu dropdown-menu-model dropdown-menu-normal dr-bottom-right dropdown-menu-emo" style="max-height : 400px !important;">
                    <emoji-picker data-input=".' . $inputClass . '"></emoji-picker>
                </div>
            </div>
            <div class="ci-buttons"' . $buttonsId . '>
                <div class="alert alert-danger cm-error mt-1" style="display: none;"></div>
                <div class="ci-b-left">
                    <div class="cb-li"><a class="btn btn-sm btn-spoil"><i class="fas fa-check mr-2"></i>Spoil?</a></div>
                </div>
                <div class="ci-b-right">
                    <div class="cb-li">' . $closeBtn . '</div>
                    <div class="cb-li"><button class="btn btn-sm btn-primary ml-2">' . $submitLabel . '</button></div>
                </div>
            </div>
            <div class="loading-absolute" style="display:none;"></div>
        </form></div></div>';
}

function comment_render_list(array $comments, int $episodeId, string $type, ?array $user, bool $isReplyList = false): string
{
    $html = '';
    foreach ($comments as $comment) {
        $html .= comment_render_item($comment, $episodeId, $type, $user, $isReplyList);
    }
    return $html;
}

function comment_render_item(array $c, int $episodeId, string $type, ?array $user, bool $isReplyList = false): string
{
    $id = (int)($c['id'] ?? 0);
    $uid = (int)($c['user_id'] ?? 0);
    $name = comment_h($c['username'] ?? 'User');
    $avatar = comment_h(comment_avatar_url($c['user_avatar'] ?? ''));
    $content = nl2br(comment_h($c['content'] ?? ''));
    $time = comment_time_ago($c['created_at'] ?? null);
    $iso = !empty($c['created_at']) ? date('c', strtotime($c['created_at'])) : '';
    $likes = (int)($c['likes'] ?? 0);
    $dislikes = (int)($c['dislikes'] ?? 0);
    $likeActive = isset($c['userReaction']) && (int)$c['userReaction'] === 1 ? 'active' : '';
    $dislikeActive = isset($c['userReaction']) && (int)$c['userReaction'] === 0 ? 'active' : '';
    $isSpoil = !empty($c['is_spoiler']);
    $epNum = (int)($c['episode_id'] ?? $episodeId);
    $replyCount = (int)($c['reply_count'] ?? 0);

    $eps = '';
    if ($type === 'all' && !$isReplyList) {
        $eps = '<div class="eps"><i class="fas fa-caret-left mr-1"></i>Episode ' . $epNum . '</div>';
    }

    $ibodyClass = $isSpoil ? 'is-spoil' : '';
    $spoilBtn = $isSpoil ? '<div class="show-spoil">Show spoiler</div>' : '';

    $showRep = '';
    if (!$isReplyList && $replyCount > 0) {
        $showRep = '<div class="ib-li"><a class="btn cm-btn-show-rep" data-id="' . $id . '"><i class="fas fa-comments mr-1"></i>' . $replyCount . ' Replies</a></div>';
    }

    $replyForm = '';
    $repliesBox = '';
    if (!$isReplyList) {
        $replyForm = comment_render_input($epNum, $user, $id);
        $repliesBox = '<div class="replies" id="replies-' . $id . '"></div>';
    }

    return '<div class="cw_l-line" id="cm-' . $id . '">
        <a href="javascript:;" class="user-avatar">
            <img class="user-avatar-img" src="' . $avatar . '" alt="' . $name . '">
        </a>
        <div class="info">
            <div class="ihead">
                <a href="javascript:;" target="_blank" class="user-name is-level-x">' . $name . '</a>
                <div class="time" data-time="' . comment_h($iso) . '">' . comment_h($time) . '</div>
                ' . $eps . '
            </div>
            <div class="ibody ' . $ibodyClass . '">
                <p>' . $content . '</p>
                ' . $spoilBtn . '
            </div>
            <div class="ibottom">
                <div class="ib-li ib-reply" data-id="' . $id . '"><a class="btn"><i class="fas fa-reply mr-1"></i>Reply</a></div>
                <div class="ib-li ib-like">
                    <a class="btn cm-btn-vote ' . $likeActive . '" data-id="' . $id . '" data-type="1">
                        <i class="far fa-thumbs-up mr-1"></i><span class="value">' . ($likes > 0 ? $likes : '') . '</span>
                    </a>
                </div>
                <div class="ib-li ib-dislike">
                    <a class="btn cm-btn-vote ' . $dislikeActive . '" data-id="' . $id . '" data-type="0">
                        <i class="far fa-thumbs-down mr-1"></i><span class="value">' . ($dislikes > 0 ? $dislikes : '') . '</span>
                    </a>
                </div>
                ' . $showRep . '
                <div class="ib-li">
                    <a class="btn more-btn" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-ellipsis-h mr-1"></i>More
                    </a>
                    <div class="dropdown-menu dropdown-menu-model dropdown-menu-normal" aria-labelledby="ssc-list" data-user-id="' . $uid . '">
                        <a class="dropdown-item cm-report" href="javascript:;" data-type="1" data-id="' . $id . '">Report Spam</a>
                        <a class="dropdown-item cm-report" href="javascript:;" data-type="2" data-id="' . $id . '">Report Spoil</a>
                        <a class="dropdown-item cm-cp-link" href="javascript:;" data-id="' . $id . '">Copy Link</a>
                    </div>
                </div>
                <div class="clearfix"></div>
            </div>
            ' . $replyForm . '
            ' . $repliesBox . '
        </div>
    </div>';
}
