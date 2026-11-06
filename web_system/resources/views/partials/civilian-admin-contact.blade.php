<section class="civilian-admin-contact" aria-labelledby="civilian-admin-contact-heading">
    <div class="civilian-admin-contact-copy">
        <h2 id="civilian-admin-contact-heading">Need help? Contact Admin</h2>
        <p>For account concerns, app issues or feedback, email our administrator.</p>
        <a class="civilian-admin-contact-email" href="mailto:{{ config('support.admin_email') }}">{{ config('support.admin_email') }}</a>
        <p class="civilian-admin-contact-note">Opens your email app. For emergencies, use Report Emergency.</p>
    </div>
    <a class="btn btn-primary" href="mailto:{{ config('support.admin_email') }}">Email Admin</a>
</section>
