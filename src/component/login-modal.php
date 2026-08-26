<?php
$loginModalLoggedIn = isset($_COOKIE['userID']) && !empty($_COOKIE['userID']);
$loginRedirect = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/home', ENT_QUOTES, 'UTF-8');
?>
<div class="modal fade premodal premodal-login" id="modallogin" tabindex="-1" role="dialog" aria-labelledby="modalloginLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-body">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <div class="text-center mb-3">
                    <h5 class="modal-title" id="modalloginLabel">Login to continue</h5>
                    <p class="mb-0 text-muted" style="font-size:13px;">Sign in to add anime to your list</p>
                </div>
                <form class="preform" method="post" action="<?= htmlspecialchars($websiteUrl) ?>/login?redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/home') ?>" id="modal-login-form">
                    <div class="form-group">
                        <label class="prelabel" for="modal-login-user">Username or Email</label>
                        <input type="text" class="form-control" id="modal-login-user" name="login" placeholder="user69 or name@email.com" required>
                    </div>
                    <div class="form-group">
                        <label class="prelabel" for="modal-login-pass">Password</label>
                        <input type="password" class="form-control" id="modal-login-pass" name="password" placeholder="Password" required>
                    </div>
                    <div class="form-group mb-2">
                        <button type="submit" name="submit" class="btn btn-primary btn-block">Login</button>
                    </div>
                </form>
                <div class="text-center" style="font-size:13px;">
                    Don't have an account?
                    <a href="<?= htmlspecialchars($websiteUrl) ?>/register" class="link-highlight">Register</a>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
window.isLoggedIn = <?= $loginModalLoggedIn ? 'true' : 'false' ?>;
if (typeof isLoggedIn !== 'undefined') { isLoggedIn = window.isLoggedIn; }
document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.isLoggedIn !== 'undefined') {
        try { isLoggedIn = window.isLoggedIn; } catch (e) {}
    }
    $(document).on('click', '.film-fav.wl-item', function (e) {
        if (!window.isLoggedIn && !isLoggedIn) {
            e.preventDefault();
            e.stopImmediatePropagation();
            $('#modallogin').modal('show');
            return false;
        }
        if (!$(this).attr('data-type')) {
            $(this).attr('data-type', '3');
        }
        if (!$(this).attr('data-page')) {
            $(this).attr('data-page', 'detail');
        }
    });
});
</script>
