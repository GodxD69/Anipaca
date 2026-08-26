/**
 * Watch-page comments — Zanora-compatible UI + API (/ajax/comment/...)
 * Requires globals: movieId, epId, isLoggedIn, checkLogin, isInViewport, currentUrl
 */
(function () {
    var cmSort = 'newest';
    var commentLoading = false;
    var commentLoaded = false;
    var firstLoad = true;

    if (typeof currentUrl === 'undefined') {
        window.currentUrl = new URL(window.location.href);
    }
    if (typeof isInViewport !== 'function') {
        window.isInViewport = function (el) {
            if (!el) return false;
            var r = el.getBoundingClientRect();
            return r.top < window.innerHeight && r.bottom >= 0;
        };
    }
    if (typeof checkLogin !== 'function') {
        window.checkLogin = function () {
            if (window.isLoggedIn) return true;
            if ($('#modallogin').length) $('#modallogin').modal('show');
            return false;
        };
    }

    function getReplies(t, n) {
        n = n || null;
        $.get('/ajax/comment/replies/' + t, function (e) {
            $('#replies-' + t).html(e.html);
            $('#replies-' + t).slideToggle(200);
            if (n) {
                $('#cm-' + t + ' .cm-btn-show-rep').addClass('active');
                $('#cm-' + n).addClass('comment-focus');
                setTimeout(function () {
                    if ($.fn.scrollTo) {
                        $(window).scrollTo($('#cm-' + n).prev(), { duration: 300 });
                    }
                }, 1000);
            }
        });
    }

    window.getCommentWidgetMovie = function (type, force) {
        type = type || 'episode';
        force = !!force;
        if (typeof movieId === 'undefined' || !movieId || typeof epId === 'undefined' || !epId) return;

        var n = '/ajax/comment/widget/' + movieId + '?episodeId=' + epId + '&sort=' + cmSort;
        if (currentUrl.search && firstLoad) {
            var params = new URLSearchParams(currentUrl.search);
            if (params.get('c_id') && params.get('c_type')) {
                type = params.get('c_type');
                n += '&cId=' + params.get('c_id');
                force = true;
            }
        }
        n += '&type=' + type;
        firstLoad = false;

        var visible = isInViewport(document.getElementById('content-comments'));
        if (!visible && !force) {
            commentLoaded = false;
            return;
        }
        if (commentLoading) return;
        commentLoading = true;
        $.get(n, function (e) {
            commentLoading = false;
            commentLoaded = true;
            if (!e || !e.html) return;
            $('#content-comments').html(e.html);
            if (e.gotoId) {
                setTimeout(function () {
                    if ($.fn.scrollTo) {
                        $(window).scrollTo('.block_area-comment', { duration: 300 });
                    }
                }, 1000);
                var t = $('#cm-' + e.gotoId);
                if (t.length) t.addClass('comment-focus');
                else if (e.cParentId) getReplies(e.cParentId, e.gotoId);
            }
        }).fail(function () {
            commentLoading = false;
            $('#content-comments').html('<div class="no-comments">Failed to load comments.</div>');
        });
    };

    $(document).on('click', '#cm-view-more', function () {
        if (commentLoading) return;
        commentLoading = true;
        var btn = $(this);
        var page = $(this).data('page');
        var type = $('.cm-by.active').data('value') || 'episode';
        var url = '/ajax/comment/list/' + movieId + '?episodeId=' + epId + '&page=' + page + '&sort=' + cmSort + '&type=' + type;
        $.get(url, function (e) {
            commentLoading = false;
            if (e && e.status) {
                if (e.nextPage > 0) btn.data('page', e.nextPage);
                else btn.remove();
                $('.cw_list').append(e.html);
            }
        });
    });

    $(document).on('click', '.cm-report', function () {
        if (!checkLogin() || commentLoading) return;
        commentLoading = true;
        $.post('/ajax/comment/report', {
            id: $(this).data('id'),
            type: $(this).data('type')
        }, function (e) {
            commentLoading = false;
            if (window.toastr) {
                e.status ? toastr.success(e.msg || 'Reported', '', { timeOut: 6000 })
                    : toastr.error(e.msg || 'Failed', '', { timeOut: 6000 });
            }
        });
    });

    $(document).on('click', '.cm-cp-link', function () {
        var id = $(this).data('id');
        var type = $('.cm-by.active').data('value') || 'episode';
        var link = currentUrl.origin + currentUrl.pathname + '?ep=' + epId + '&c_id=' + id + '&c_type=' + type;
        if (navigator.clipboard) navigator.clipboard.writeText(link);
        if (window.toastr) toastr.success('Link Copied.', '', { timeOut: 6000 });
    });

    $(document).on('click', '.cm-sort', function () {
        cmSort = $(this).data('value');
        getCommentWidgetMovie($('.cm-by.active').data('value') || 'episode', true);
    });

    $(document).on('click', '.cm-by', function () {
        getCommentWidgetMovie($(this).data('value'), true);
    });

    $(document).on('click', '.btn-spoil', function () {
        $(this).toggleClass('active');
    });

    $(document).on('click', '.cm-btn-show-rep', function () {
        var id = $(this).data('id');
        $(this).toggleClass('active');
        if ($(this).hasClass('active')) getReplies(id);
        else $('#replies-' + id).slideToggle(200);
    });

    $(document).on('click', '.show-spoil', function () {
        $(this).hide();
        $(this).parent().removeClass('is-spoil');
    });

    $(document).on('click', '.ib-reply, .btn-close-reply', function () {
        if (!checkLogin()) return;
        var id = $(this).data('id');
        $('#reply-' + id).slideToggle(100);
        $('#reply-' + id).find('.comment-subject').focus();
    });

    $(document).on('focus', '#df-cm-content', function () {
        if (checkLogin()) $('#df-cm-buttons').slideDown(100);
    });

    $(document).on('click', '#df-cm-close', function () {
        $('#df-cm-buttons').slideUp(100);
    });

    $(document).on('click', '.cm-btn-vote', function () {
        if (!checkLogin() || commentLoading) return;
        commentLoading = true;
        var btn = $(this);
        var type = parseInt(btn.data('type'), 10);
        var id = btn.data('id');
        var active = $('.cm-btn-vote[data-id=' + id + '].active');
        if (active.length && parseInt(active.data('type'), 10) !== type) {
            active.removeClass('active');
            var v = parseInt(active.find('.value').text(), 10);
            if (v > 0) {
                v -= 1;
                active.find('.value').text(v > 0 ? v : '');
            }
        }
        btn.toggleClass('active');
        var cur = parseInt(btn.find('.value').text(), 10);
        cur = cur > 0 ? (btn.hasClass('active') ? cur + 1 : cur - 1) : 1;
        btn.find('.value').text(cur > 0 ? cur : '');
        $.post('/ajax/comment/vote', { id: id, type: type }, function (e) {
            commentLoading = false;
            if (e && !e.status && window.toastr) {
                toastr.error(e.msg || 'Vote failed', '', { timeOut: 6000 });
            }
        });
    });

    $(document).on('submit', '.comment-form', function (e) {
        e.preventDefault();
        if (!checkLogin() || commentLoading) return;
        commentLoading = true;
        var form = $(this);
        var loading = form.find('.loading-absolute');
        loading.show();
        var data = form.serializeArray();
        data.push({ name: 'movie_id', value: movieId });
        data.push({ name: 'is_spoil', value: form.find('.btn-spoil').hasClass('active') ? 1 : 0 });
        $.post('/ajax/comment/add', data, function (res) {
            commentLoading = false;
            loading.hide();
            if (res && res.status) {
                var parentId = parseInt(res.parentId, 10) || 0;
                if (parentId > 0) {
                    var block = $('#block-reply-' + parentId);
                    if (block.length) block.html(res.html);
                    else $('#cm-' + parentId).append('<div class="replies" id="block-reply-' + parentId + '">' + res.html + '</div>');
                    $('#cm-' + parentId + ' .cm-btn-show-rep').addClass('active');
                    $('#replies-' + parentId).slideDown(100);
                    $('#reply-' + parentId).slideUp(100);
                } else {
                    $('#df-cm-buttons').slideUp(100);
                    $('.list-comment .cw_list').html(res.html);
                }
                form[0].reset();
                form.find('.btn-spoil').removeClass('active');
            } else {
                var msg = (res && res.msg) ? res.msg : 'Failed to post comment';
                form.find('.cm-error').html(msg).show();
                setTimeout(function () { form.find('.cm-error').hide(); }, 10000);
            }
        }).fail(function () {
            commentLoading = false;
            loading.hide();
        });
    });

    window.addEventListener('scroll', function () {
        if (isInViewport(document.getElementById('content-comments')) && !commentLoaded && epId) {
            getCommentWidgetMovie('episode');
        }
    });

    $(function () {
        if (typeof epId !== 'undefined' && epId && isInViewport(document.getElementById('content-comments'))) {
            getCommentWidgetMovie('episode');
        } else if (typeof epId !== 'undefined' && epId) {
            // lazy-load on scroll; also kick once shortly after paint
            setTimeout(function () {
                if (!commentLoaded) getCommentWidgetMovie('episode', true);
            }, 400);
        }

        // Reload comments when episode changes on the watch page
        $(document).on('click', '.ssl-item', function () {
            var ep = $(this).data('number') || (new URLSearchParams(window.location.search)).get('ep');
            if (ep) {
                window.epId = ep;
                commentLoaded = false;
                setTimeout(function () { getCommentWidgetMovie('episode', true); }, 200);
            }
        });
    });
})();
