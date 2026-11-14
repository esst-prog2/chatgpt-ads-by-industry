{{--
    Small "i" icon linking to the static promo page (public/promo.html),
    pinned to the top-right corner of the login card. Icon-only, so it carries an
    accessible name and a hover tooltip. Position and colour come from the
    page's .info-link rules. A Blade component rather than inline markup, per
    DESIGN_AGENT.md section 7.
--}}
<a class="info-link" href="{{ url('promo.html') }}"
   aria-label="About this dashboard"
   title="About this dashboard">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="16" x2="12" y2="12"/>
        <line x1="12" y1="8" x2="12.01" y2="8"/>
    </svg>
</a>
