<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Demo;

use Andre\AiPageBuilder\Models\Page;

/**
 * Seeds a polished, single-page marketing site ("Nimbus") into the page
 * builder. Used by the installer to demonstrate what an owner-authored,
 * fully-trusted page can do: Alpine-powered modal, FAQ accordion and mobile
 * menu, CSS entrance animations, a real type scale and a deliberate palette.
 *
 * Idempotent — re-running refreshes the page in place (updateOrCreate by slug).
 */
class MarketingDemo
{
    /** Create/refresh the marketing site pages. */
    public function build(): void
    {
        Page::updateOrCreate(['slug' => 'home'], [
            'title' => 'Nimbus — Project management, reimagined',
            'kind' => 'page',
            'status' => 'published',
            'requires_auth' => false,
            'html' => $this->homeHtml(),
            'css' => $this->homeCss(),
            // Behaviour lives in custom_js (emitted raw to visitors), NOT inline in
            // the HTML: the AI HtmlSanitizer strips @click / @keydown / @submit, so
            // nav toggle, demo modal, FAQ and the contact form are wired here via a
            // window.marketingApp() component + data-act triggers (InventoryDemo pattern).
            'custom_js' => $this->homeJs(),
            // Force a clean re-import through the editor's Alpine attribute bridge
            // (the canonical GrapesJS tree is rebuilt from html on next open).
            'project_data' => null,
            'meta' => [
                'title' => 'Nimbus — Project management, reimagined',
                'description' => 'Nimbus is the calm, fast project workspace where plans, work and updates live in one place. Ship on time without the chaos.',
            ],
        ]);
    }

    private function homeHtml(): string
    {
        return <<<'HTML'
<div class="nb" x-data="marketingApp()">

  <!-- ===================== STICKY NAV ===================== -->
  <header class="nb-nav">
    <div class="nb-container nb-nav__inner">
      <a href="#top" class="nb-logo" aria-label="Nimbus home">
        <span class="nb-logo__mark" aria-hidden="true">
          <svg viewBox="0 0 32 32" width="28" height="28" fill="none">
            <path d="M9 21a6 6 0 0 1 .6-11.97A8 8 0 0 1 25 11.2 5.5 5.5 0 0 1 23.5 22H9Z" fill="currentColor" opacity=".18"/>
            <path d="M9 21a6 6 0 0 1 .6-11.97A8 8 0 0 1 25 11.2 5.5 5.5 0 0 1 23.5 22H9Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
            <circle cx="16" cy="15" r="2.4" fill="currentColor"/>
          </svg>
        </span>
        <span class="nb-logo__word">Nimbus</span>
      </a>

      <nav class="nb-nav__links" aria-label="Primary">
        <a href="#features">Features</a>
        <a href="#how">How it works</a>
        <a href="#pricing">Pricing</a>
        <a href="#faq">FAQ</a>
      </nav>

      <div class="nb-nav__cta">
        <a href="#pricing" class="nb-btn nb-btn--ghost nb-hide-sm">Sign in</a>
        <button type="button" class="nb-btn nb-btn--accent" data-act="openDemo">Book a demo</button>
        <button type="button" class="nb-burger" :aria-expanded="navOpen.toString()" aria-label="Toggle menu" data-act="toggleNav">
          <span :class="{ 'is-open': navOpen }"></span>
        </button>
      </div>
    </div>

    <!-- Mobile menu (CSS grid-rows collapse — works with core Alpine, no plugin) -->
    <div class="nb-mobilemenu" :class="{ 'is-open': navOpen }">
      <div class="nb-mobilemenu__inner-wrap">
      <div class="nb-container nb-mobilemenu__inner">
        <a href="#features" data-act="closeNav">Features</a>
        <a href="#how" data-act="closeNav">How it works</a>
        <a href="#pricing" data-act="closeNav">Pricing</a>
        <a href="#faq" data-act="closeNav">FAQ</a>
        <button type="button" class="nb-btn nb-btn--accent nb-btn--block" data-act="openDemoFromNav">Book a demo</button>
      </div>
      </div>
    </div>
  </header>

  <main id="top">

    <!-- ===================== HERO ===================== -->
    <section class="nb-hero" data-pb-block>
      <div class="nb-hero__glow" aria-hidden="true"></div>
      <div class="nb-container nb-hero__grid">
        <div class="nb-hero__copy">
          <span class="nb-eyebrow nb-anim" style="--d:0ms">New · Timeline 2.0 is here</span>
          <h1 class="nb-h1 nb-anim" style="--d:80ms">Project management, finally&nbsp;calm.</h1>
          <p class="nb-lead nb-anim" style="--d:160ms">
            Nimbus brings your plans, work and updates into one fast workspace —
            so your team ships on time without living in status meetings.
          </p>
          <div class="nb-hero__actions nb-anim" style="--d:240ms">
            <button type="button" class="nb-btn nb-btn--accent nb-btn--lg" data-act="openDemo">Book a demo</button>
            <a href="#features" class="nb-btn nb-btn--outline nb-btn--lg">
              See how it works
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true"><path d="M5 12h14m0 0-6-6m6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
          </div>
          <p class="nb-hero__note nb-anim" style="--d:320ms">Free 14-day trial · No credit card · Cancel anytime</p>
        </div>

        <!-- CSS/SVG product mock -->
        <div class="nb-hero__mock nb-anim" style="--d:200ms" aria-hidden="true">
          <div class="nb-mock">
            <div class="nb-mock__bar">
              <span class="nb-mock__dot"></span><span class="nb-mock__dot"></span><span class="nb-mock__dot"></span>
              <span class="nb-mock__title">Q3 Launch · Board</span>
            </div>
            <div class="nb-mock__body">
              <div class="nb-mock__col">
                <span class="nb-mock__coltitle">To do</span>
                <div class="nb-card-t"><span class="nb-tag nb-tag--coral">Design</span><span class="nb-bar w70"></span><span class="nb-bar w40"></span></div>
                <div class="nb-card-t"><span class="nb-tag nb-tag--blue">Spec</span><span class="nb-bar w90"></span><span class="nb-bar w55"></span></div>
              </div>
              <div class="nb-mock__col">
                <span class="nb-mock__coltitle">In progress</span>
                <div class="nb-card-t"><span class="nb-tag nb-tag--lime">Build</span><span class="nb-bar w80"></span><span class="nb-bar w60"></span>
                  <span class="nb-avatars"><i></i><i></i><i></i></span>
                </div>
              </div>
              <div class="nb-mock__col">
                <span class="nb-mock__coltitle">Done</span>
                <div class="nb-card-t nb-card-t--done"><span class="nb-tag nb-tag--green">Ship</span><span class="nb-bar w65"></span></div>
              </div>
            </div>
          </div>
          <div class="nb-mock__float nb-mock__float--a">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="m5 13 4 4L19 7" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            12 tasks shipped today
          </div>
          <div class="nb-mock__float nb-mock__float--b">
            <span class="nb-spark"><i style="--h:40%"></i><i style="--h:65%"></i><i style="--h:50%"></i><i style="--h:85%"></i><i style="--h:70%"></i></span>
            On track
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== SOCIAL PROOF ===================== -->
    <section class="nb-proof" aria-label="Trusted by">
      <div class="nb-container">
        <p class="nb-proof__label">Trusted by fast-moving teams at</p>
        <ul class="nb-proof__logos">
          <li>Northwind</li>
          <li>Lumen&nbsp;Labs</li>
          <li>Apex&nbsp;Studio</li>
          <li>Veridian</li>
          <li>Cobalt</li>
          <li>Foundry&nbsp;9</li>
        </ul>
      </div>
    </section>

    <!-- ===================== FEATURES ===================== -->
    <section class="nb-section" id="features" data-pb-block>
      <div class="nb-container">
        <div class="nb-section__head">
          <span class="nb-eyebrow">Features</span>
          <h2 class="nb-h2">Everything the work needs, nothing it doesn't</h2>
          <p class="nb-section__sub">Opinionated defaults that get teams moving in minutes — flexible enough to grow with you.</p>
        </div>

        <div class="nb-grid nb-grid--3">
          <article class="nb-feature nb-anim">
            <span class="nb-feature__icon nb-feature__icon--lime">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><rect x="3" y="4" width="18" height="4" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="3" y="11" width="12" height="4" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="3" y="18" width="15" height="4" rx="1.5" stroke="currentColor" stroke-width="1.8"/></svg>
            </span>
            <h3 class="nb-feature__title">Timeline that plans itself</h3>
            <p class="nb-feature__text">Drag a task and dependencies reflow automatically. Spot the critical path before it slips, not after.</p>
          </article>

          <article class="nb-feature nb-anim" style="--d:80ms">
            <span class="nb-feature__icon nb-feature__icon--coral">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M12 3v4m0 10v4m9-9h-4M7 12H3m13.5-6.5-2.8 2.8M9.3 14.7l-2.8 2.8m11 0-2.8-2.8M9.3 9.3 6.5 6.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>
            </span>
            <h3 class="nb-feature__title">Automations, no scripts</h3>
            <p class="nb-feature__text">When a task is approved, assign the next owner and notify the channel. Build rules in plain language.</p>
          </article>

          <article class="nb-feature nb-anim" style="--d:160ms">
            <span class="nb-feature__icon nb-feature__icon--blue">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M4 19V5m0 14h16M8 16l3.5-4 3 2.5L20 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <h3 class="nb-feature__title">Live status, zero meetings</h3>
            <p class="nb-feature__text">Dashboards roll up progress, risk and workload in real time. Share a link instead of a standup.</p>
          </article>

          <article class="nb-feature nb-anim">
            <span class="nb-feature__icon nb-feature__icon--lime">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M12 21s-7-4.4-7-9.5A4.5 4.5 0 0 1 12 8a4.5 4.5 0 0 1 7 3.5C19 16.6 12 21 12 21Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
            </span>
            <h3 class="nb-feature__title">Docs that stay in context</h3>
            <p class="nb-feature__text">Specs, notes and decisions live next to the work — searchable, versioned and always up to date.</p>
          </article>

          <article class="nb-feature nb-anim" style="--d:80ms">
            <span class="nb-feature__icon nb-feature__icon--coral">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><circle cx="9" cy="8" r="3" stroke="currentColor" stroke-width="1.8"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0M16 7.2a3 3 0 0 1 0 5.6M20.5 19a5 5 0 0 0-3.2-4.7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </span>
            <h3 class="nb-feature__title">Workload you can balance</h3>
            <p class="nb-feature__text">See who's overloaded at a glance and rebalance in a drag. Healthy teams ship faster.</p>
          </article>

          <article class="nb-feature nb-anim" style="--d:160ms">
            <span class="nb-feature__icon nb-feature__icon--blue">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><rect x="4" y="4" width="16" height="16" rx="4" stroke="currentColor" stroke-width="1.8"/><path d="M8.5 12.5 11 15l5-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <h3 class="nb-feature__title">Connected to your stack</h3>
            <p class="nb-feature__text">GitHub, Slack, Figma and 40+ tools sync both ways. Nimbus fits the way you already work.</p>
          </article>
        </div>
      </div>
    </section>

    <!-- ===================== HOW IT WORKS ===================== -->
    <section class="nb-section nb-section--alt" id="how" data-pb-block>
      <div class="nb-container">
        <div class="nb-section__head">
          <span class="nb-eyebrow">How it works</span>
          <h2 class="nb-h2">Up and running before your coffee's cold</h2>
        </div>

        <ol class="nb-steps">
          <li class="nb-step nb-anim">
            <span class="nb-step__num">1</span>
            <h3 class="nb-step__title">Bring in your work</h3>
            <p class="nb-step__text">Import from a spreadsheet, Jira or Trello in one click — or start from a ready-made template.</p>
          </li>
          <li class="nb-step nb-anim" style="--d:100ms">
            <span class="nb-step__num">2</span>
            <h3 class="nb-step__title">Shape the plan</h3>
            <p class="nb-step__text">Set owners, dates and dependencies. Nimbus flags risk and suggests a realistic timeline.</p>
          </li>
          <li class="nb-step nb-anim" style="--d:200ms">
            <span class="nb-step__num">3</span>
            <h3 class="nb-step__title">Ship, then watch it run</h3>
            <p class="nb-step__text">Automations handle the busywork while live dashboards keep everyone aligned — no nudging.</p>
          </li>
        </ol>
      </div>
    </section>

    <!-- ===================== PRICING ===================== -->
    <section class="nb-section" id="pricing" data-pb-block>
      <div class="nb-container">
        <div class="nb-section__head">
          <span class="nb-eyebrow">Pricing</span>
          <h2 class="nb-h2">Simple plans that scale with you</h2>
          <p class="nb-section__sub">Per user, billed annually. Switch or cancel whenever you like.</p>
        </div>

        <div class="nb-grid nb-grid--3 nb-pricing">
          <article class="nb-plan nb-anim">
            <h3 class="nb-plan__name">Starter</h3>
            <p class="nb-plan__price"><span class="nb-plan__amt">$0</span><span class="nb-plan__per">/ user / mo</span></p>
            <p class="nb-plan__blurb">For small teams getting organised.</p>
            <ul class="nb-plan__list">
              <li>Up to 5 members</li>
              <li>Boards &amp; lists</li>
              <li>Basic automations</li>
              <li>Community support</li>
            </ul>
            <button type="button" class="nb-btn nb-btn--outline nb-btn--block" data-act="openDemo">Start free</button>
          </article>

          <article class="nb-plan nb-plan--featured nb-anim" style="--d:90ms">
            <span class="nb-plan__badge">Most popular</span>
            <h3 class="nb-plan__name">Pro</h3>
            <p class="nb-plan__price"><span class="nb-plan__amt">$12</span><span class="nb-plan__per">/ user / mo</span></p>
            <p class="nb-plan__blurb">For growing teams that ship often.</p>
            <ul class="nb-plan__list">
              <li>Unlimited members</li>
              <li>Timeline &amp; dashboards</li>
              <li>Advanced automations</li>
              <li>40+ integrations</li>
              <li>Priority support</li>
            </ul>
            <button type="button" class="nb-btn nb-btn--accent nb-btn--block" data-act="openDemo">Book a demo</button>
          </article>

          <article class="nb-plan nb-anim" style="--d:180ms">
            <h3 class="nb-plan__name">Scale</h3>
            <p class="nb-plan__price"><span class="nb-plan__amt">$24</span><span class="nb-plan__per">/ user / mo</span></p>
            <p class="nb-plan__blurb">For organisations that need control.</p>
            <ul class="nb-plan__list">
              <li>SSO &amp; SCIM</li>
              <li>Advanced permissions</li>
              <li>Audit log &amp; data residency</li>
              <li>Dedicated success manager</li>
            </ul>
            <button type="button" class="nb-btn nb-btn--outline nb-btn--block" data-act="openDemo">Talk to sales</button>
          </article>
        </div>
      </div>
    </section>

    <!-- ===================== TESTIMONIAL ===================== -->
    <section class="nb-quote" data-pb-block>
      <div class="nb-container nb-quote__inner nb-anim">
        <svg class="nb-quote__mark" viewBox="0 0 48 48" width="44" height="44" fill="none" aria-hidden="true"><path d="M20 14c-6 2-10 7-10 14 0 4 2 6 5 6s5-2 5-5-2-5-5-5c0-3 2-6 5-7l-1-3Zm18 0c-6 2-10 7-10 14 0 4 2 6 5 6s5-2 5-5-2-5-5-5c0-3 2-6 5-7l-1-3Z" fill="currentColor"/></svg>
        <blockquote class="nb-quote__text">
          We replaced three tools with Nimbus and cut our project status meetings in half.
          For the first time, everyone actually trusts the timeline.
        </blockquote>
        <div class="nb-quote__by">
          <span class="nb-quote__avatar" aria-hidden="true">RA</span>
          <div>
            <p class="nb-quote__name">Rina Adler</p>
            <p class="nb-quote__role">VP of Operations, Veridian</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== FAQ ===================== -->
    <section class="nb-section nb-section--alt" id="faq" data-pb-block>
      <div class="nb-container nb-faq" x-data="{ open: 0 }">
        <div class="nb-section__head">
          <span class="nb-eyebrow">FAQ</span>
          <h2 class="nb-h2">Questions, answered</h2>
        </div>

        <div class="nb-faq__list">
          <div class="nb-faq__item">
            <button type="button" class="nb-faq__q" :class="{ 'is-open': open === 1 }" data-faq="1" :aria-expanded="(open === 1).toString()">
              <span>How long does it take to get started?</span>
              <svg class="nb-faq__chev" viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="nb-faq__a" :class="{ 'is-open': open === 1 }">
              <div class="nb-faq__a-inner"><p>Most teams are running within an afternoon. Import your existing work, pick a template, and Nimbus sets up sensible defaults you can tweak later.</p></div>
            </div>
          </div>

          <div class="nb-faq__item">
            <button type="button" class="nb-faq__q" :class="{ 'is-open': open === 2 }" data-faq="2" :aria-expanded="(open === 2).toString()">
              <span>Can I migrate from Jira or Trello?</span>
              <svg class="nb-faq__chev" viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="nb-faq__a" :class="{ 'is-open': open === 2 }">
              <div class="nb-faq__a-inner"><p>Yes. Our one-click importers bring over your projects, statuses, assignees and history. Nothing is lost, and you can run both tools in parallel during the switch.</p></div>
            </div>
          </div>

          <div class="nb-faq__item">
            <button type="button" class="nb-faq__q" :class="{ 'is-open': open === 3 }" data-faq="3" :aria-expanded="(open === 3).toString()">
              <span>Is my data secure?</span>
              <svg class="nb-faq__chev" viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="nb-faq__a" :class="{ 'is-open': open === 3 }">
              <div class="nb-faq__a-inner"><p>Nimbus is SOC 2 Type II certified, encrypts data in transit and at rest, and offers SSO, granular permissions and data residency on the Scale plan.</p></div>
            </div>
          </div>

          <div class="nb-faq__item">
            <button type="button" class="nb-faq__q" :class="{ 'is-open': open === 4 }" data-faq="4" :aria-expanded="(open === 4).toString()">
              <span>What happens when my trial ends?</span>
              <svg class="nb-faq__chev" viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="nb-faq__a" :class="{ 'is-open': open === 4 }">
              <div class="nb-faq__a-inner"><p>Nothing disappears. Your workspace drops to the free Starter plan and your data stays put — upgrade whenever you're ready, no pressure.</p></div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== FINAL CTA ===================== -->
    <section class="nb-cta" data-pb-block>
      <div class="nb-cta__glow" aria-hidden="true"></div>
      <div class="nb-container nb-cta__inner nb-anim">
        <h2 class="nb-cta__title">Ready to ship calmly?</h2>
        <p class="nb-cta__sub">Join thousands of teams who traded the chaos for clarity. Try Nimbus free for 14 days.</p>
        <div class="nb-cta__actions">
          <button type="button" class="nb-btn nb-btn--accent nb-btn--lg" data-act="openDemo">Book a demo</button>
          <a href="#pricing" class="nb-btn nb-btn--light nb-btn--lg">View pricing</a>
        </div>
      </div>
    </section>
  </main>

  <!-- ===================== FOOTER ===================== -->
  <footer class="nb-footer">
    <div class="nb-container nb-footer__grid">
      <div class="nb-footer__brand">
        <a href="#top" class="nb-logo nb-logo--light">
          <span class="nb-logo__mark" aria-hidden="true">
            <svg viewBox="0 0 32 32" width="26" height="26" fill="none"><path d="M9 21a6 6 0 0 1 .6-11.97A8 8 0 0 1 25 11.2 5.5 5.5 0 0 1 23.5 22H9Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="16" cy="15" r="2.4" fill="currentColor"/></svg>
          </span>
          <span class="nb-logo__word">Nimbus</span>
        </a>
        <p class="nb-footer__tag">The calm project workspace.</p>
      </div>
      <div class="nb-footer__col">
        <h4>Product</h4>
        <a href="#features">Features</a>
        <a href="#pricing">Pricing</a>
        <a href="#how">How it works</a>
        <a href="#faq">FAQ</a>
      </div>
      <div class="nb-footer__col">
        <h4>Company</h4>
        <a href="#top">About</a>
        <a href="#top">Careers</a>
        <a href="#top">Blog</a>
        <a href="#top">Contact</a>
      </div>
      <div class="nb-footer__col">
        <h4>Legal</h4>
        <a href="#top">Privacy</a>
        <a href="#top">Terms</a>
        <a href="#top">Security</a>
        <a href="#top">Status</a>
      </div>
    </div>
    <div class="nb-container nb-footer__bar">
      <p>© 2026 Nimbus Labs, Inc. All rights reserved.</p>
      <p class="nb-footer__made">Made for teams who'd rather build than chase updates.</p>
    </div>
  </footer>

  <!-- ===================== BOOK-A-DEMO MODAL ===================== -->
  <div class="nb-modal" x-show="demoOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="nb-modal-title">
    <div class="nb-modal__backdrop"
         x-show="demoOpen"
         x-transition.opacity.duration.250ms
         data-act="closeDemo"></div>

    <div class="nb-modal__card"
         x-show="demoOpen"
         x-transition:enter="nb-modal__t-enter"
         x-transition:enter-start="nb-modal__t-start"
         x-transition:enter-end="nb-modal__t-end"
         x-transition:leave="nb-modal__t-leave"
         x-transition:leave-start="nb-modal__t-end"
         x-transition:leave-end="nb-modal__t-start">
      <button type="button" class="nb-modal__close" aria-label="Close" data-act="closeDemo">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      </button>

      <span class="nb-eyebrow nb-eyebrow--dark">Book a demo</span>
      <h2 id="nb-modal-title" class="nb-modal__title">See Nimbus in action</h2>
      <p class="nb-modal__sub">Tell us a little about your team and we'll tailor a 20-minute walkthrough.</p>

      <form class="nb-form" data-demo-form x-show="! demoSent">
        <div class="nb-form__row">
          <label class="nb-field">
            <span>Full name</span>
            <input type="text" name="name" placeholder="Alex Rivera" autocomplete="name">
          </label>
          <label class="nb-field">
            <span>Work email</span>
            <input type="email" name="email" placeholder="alex@company.com" autocomplete="email">
          </label>
        </div>
        <label class="nb-field">
          <span>Company</span>
          <input type="text" name="company" placeholder="Veridian" autocomplete="organization">
        </label>
        <label class="nb-field">
          <span>Team size</span>
          <select name="size">
            <option>1–10</option>
            <option>11–50</option>
            <option>51–200</option>
            <option>200+</option>
          </select>
        </label>
        <button type="submit" class="nb-btn nb-btn--accent nb-btn--block nb-btn--lg">Request my demo</button>
        <p class="nb-form__fine">By submitting you agree to our Privacy Policy. No spam, ever.</p>
      </form>
      <div class="nb-form__done" x-show="demoSent" x-cloak>
        <span class="nb-form__done-mark" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="26" height="26" fill="none"><path d="m5 13 4 4L19 7" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
        <h3 class="nb-form__done-title">Thanks — you're on the list.</h3>
        <p class="nb-form__done-sub">We'll email you shortly to book your 20-minute walkthrough.</p>
        <button type="button" class="nb-btn nb-btn--outline nb-btn--block" data-act="closeDemo">Close</button>
      </div>
    </div>
  </div>
</div>
HTML;
    }

    private function homeCss(): string
    {
        return <<<'CSS'
/* ===== Nimbus marketing site ===== */
.nb {
  /* Palette */
  --nb-ink: #0B1020;        /* deep midnight */
  --nb-ink-2: #141A2E;      /* raised surface on dark */
  --nb-paper: #F7F8FC;      /* off-white canvas */
  --nb-surface: #FFFFFF;
  --nb-line: #E7E9F2;
  --nb-text: #1B2236;       /* body text */
  --nb-muted: #5C6480;      /* secondary text */
  --nb-accent: #C6F24E;     /* electric lime */
  --nb-accent-ink: #1A2400; /* text on lime */
  --nb-coral: #FF7A59;      /* warm secondary */
  --nb-blue: #6EA8FF;

  /* Type scale (fluid) */
  --nb-h1: clamp(2.4rem, 1.4rem + 4.4vw, 4rem);
  --nb-h2: clamp(1.8rem, 1.2rem + 2.4vw, 2.7rem);
  --nb-h3: 1.18rem;
  --nb-lead: clamp(1.05rem, 0.98rem + 0.5vw, 1.28rem);

  /* Rhythm */
  --nb-sect: clamp(4rem, 2.5rem + 6vw, 7.5rem);
  --nb-radius: 18px;
  --nb-shadow: 0 1px 2px rgba(11,16,32,.04), 0 12px 32px -12px rgba(11,16,32,.18);

  color: var(--nb-text);
  background: var(--nb-paper);
  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  line-height: 1.6;
  -webkit-font-smoothing: antialiased;
}
.nb *, .nb *::before, .nb *::after { box-sizing: border-box; }
.nb img { max-width: 100%; display: block; }
.nb [x-cloak] { display: none !important; }

.nb-container { width: 100%; max-width: 1160px; margin-inline: auto; padding-inline: clamp(1.1rem, 4vw, 2rem); }
.nb-hide-sm { display: inline-flex; }

/* ===== Headings / text ===== */
.nb-h1 { font-size: var(--nb-h1); line-height: 1.04; letter-spacing: -0.02em; font-weight: 800; margin: 0; text-wrap: balance; }
.nb-h2 { font-size: var(--nb-h2); line-height: 1.12; letter-spacing: -0.015em; font-weight: 800; margin: 0; text-wrap: balance; }
.nb-lead { font-size: var(--nb-lead); color: var(--nb-muted); margin: 1rem 0 0; max-width: 34ch; }
.nb-eyebrow {
  display: inline-block; font-size: .78rem; font-weight: 700; letter-spacing: .14em;
  text-transform: uppercase; color: var(--nb-coral);
}
.nb-eyebrow--dark { color: var(--nb-coral); }

/* ===== Buttons ===== */
.nb-btn {
  --bg: var(--nb-ink); --fg: #fff;
  display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
  font: inherit; font-weight: 650; line-height: 1; cursor: pointer;
  border: 1px solid transparent; border-radius: 12px;
  padding: .72rem 1.15rem; text-decoration: none;
  background: var(--bg); color: var(--fg);
  transition: transform .16s ease, box-shadow .2s ease, background .2s ease, border-color .2s ease, color .2s ease;
}
.nb-btn:hover { transform: translateY(-2px); }
.nb-btn:active { transform: translateY(0); }
.nb-btn--lg { padding: .92rem 1.5rem; font-size: 1.02rem; border-radius: 13px; }
.nb-btn--block { width: 100%; }
.nb-btn--accent { background: var(--nb-accent); color: var(--nb-accent-ink); box-shadow: 0 8px 22px -10px rgba(198,242,78,.9); }
.nb-btn--accent:hover { box-shadow: 0 14px 30px -10px rgba(198,242,78,.95); }
.nb-btn--ghost { background: transparent; color: var(--nb-ink); }
.nb-btn--ghost:hover { background: rgba(11,16,32,.06); transform: none; }
.nb-btn--outline { background: transparent; color: var(--nb-ink); border-color: var(--nb-line); }
.nb-btn--outline:hover { border-color: var(--nb-ink); background: var(--nb-surface); }
.nb-btn--light { background: rgba(255,255,255,.12); color: #fff; border-color: rgba(255,255,255,.22); }
.nb-btn--light:hover { background: rgba(255,255,255,.2); }

/* ===== Logo ===== */
.nb-logo { display: inline-flex; align-items: center; gap: .55rem; text-decoration: none; color: var(--nb-ink); font-weight: 800; font-size: 1.2rem; letter-spacing: -0.01em; }
.nb-logo__mark { display: inline-flex; color: var(--nb-coral); }
.nb-logo--light, .nb-logo--light .nb-logo__word { color: #fff; }

/* ===== Nav ===== */
.nb-nav { position: sticky; top: 0; z-index: 50; background: rgba(247,248,252,.82); backdrop-filter: saturate(160%) blur(12px); border-bottom: 1px solid var(--nb-line); }
.nb-nav__inner { display: flex; align-items: center; justify-content: space-between; gap: 1rem; height: 68px; }
.nb-nav__links { display: flex; align-items: center; gap: 1.6rem; }
.nb-nav__links a { color: var(--nb-muted); text-decoration: none; font-weight: 550; font-size: .96rem; transition: color .15s ease; }
.nb-nav__links a:hover { color: var(--nb-ink); }
.nb-nav__cta { display: flex; align-items: center; gap: .6rem; }

.nb-burger { display: none; width: 42px; height: 42px; border: 1px solid var(--nb-line); border-radius: 11px; background: var(--nb-surface); cursor: pointer; position: relative; }
.nb-burger span, .nb-burger span::before, .nb-burger span::after {
  content: ""; position: absolute; left: 50%; height: 2px; width: 18px; background: var(--nb-ink);
  transform: translateX(-50%); transition: transform .25s ease, opacity .2s ease; border-radius: 2px;
}
.nb-burger span { top: 50%; margin-top: -1px; }
.nb-burger span::before { top: -6px; } .nb-burger span::after { top: 6px; }
.nb-burger span.is-open { background: transparent; }
.nb-burger span.is-open::before { transform: translateX(-50%) translateY(6px) rotate(45deg); }
.nb-burger span.is-open::after { transform: translateX(-50%) translateY(-6px) rotate(-45deg); }

.nb-mobilemenu { background: var(--nb-surface); display: grid; grid-template-rows: 0fr; transition: grid-template-rows .32s cubic-bezier(.2,.8,.25,1); }
.nb-mobilemenu.is-open { grid-template-rows: 1fr; border-bottom: 1px solid var(--nb-line); }
.nb-mobilemenu__inner-wrap { overflow: hidden; min-height: 0; }
.nb-mobilemenu__inner { display: flex; flex-direction: column; gap: .25rem; padding-block: .75rem 1.1rem; }
.nb-mobilemenu__inner a { padding: .7rem .25rem; color: var(--nb-text); text-decoration: none; font-weight: 600; border-bottom: 1px solid var(--nb-line); }
.nb-mobilemenu__inner .nb-btn { margin-top: .6rem; }

/* ===== Hero ===== */
.nb-hero { position: relative; overflow: hidden; background: var(--nb-ink); color: #fff; }
.nb-hero__glow { position: absolute; inset: -30% -10% auto; height: 620px; pointer-events: none;
  background: radial-gradient(60% 60% at 20% 0%, rgba(198,242,78,.22), transparent 60%),
              radial-gradient(50% 50% at 85% 10%, rgba(255,122,89,.20), transparent 60%); }
.nb-hero__grid { position: relative; display: grid; grid-template-columns: 1.05fr .95fr; gap: clamp(2rem, 4vw, 4rem); align-items: center;
  padding-block: clamp(3.5rem, 2rem + 7vw, 6.5rem); }
.nb-hero .nb-h1, .nb-hero .nb-eyebrow { color: #fff; }
.nb-hero .nb-eyebrow { color: var(--nb-accent); }
.nb-hero__copy .nb-lead { color: rgba(255,255,255,.74); max-width: 40ch; }
.nb-hero__actions { display: flex; flex-wrap: wrap; gap: .8rem; margin-top: 1.7rem; }
.nb-hero__note { margin: 1.1rem 0 0; font-size: .86rem; color: rgba(255,255,255,.55); }

/* Hero product mock */
.nb-hero__mock { position: relative; }
.nb-mock { background: var(--nb-ink-2); border: 1px solid rgba(255,255,255,.1); border-radius: 16px; box-shadow: 0 30px 60px -25px rgba(0,0,0,.7); overflow: hidden; }
.nb-mock__bar { display: flex; align-items: center; gap: .4rem; padding: .7rem .9rem; border-bottom: 1px solid rgba(255,255,255,.08); }
.nb-mock__dot { width: 10px; height: 10px; border-radius: 50%; background: rgba(255,255,255,.25); }
.nb-mock__title { margin-left: .6rem; font-size: .78rem; color: rgba(255,255,255,.6); font-weight: 600; }
.nb-mock__body { display: grid; grid-template-columns: repeat(3, 1fr); gap: .7rem; padding: .9rem; }
.nb-mock__coltitle { display: block; font-size: .68rem; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.45); margin-bottom: .55rem; font-weight: 700; }
.nb-card-t { background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.08); border-radius: 10px; padding: .6rem; margin-bottom: .55rem; display: flex; flex-direction: column; gap: .4rem; }
.nb-card-t--done { opacity: .65; }
.nb-tag { align-self: flex-start; font-size: .64rem; font-weight: 700; padding: .18rem .42rem; border-radius: 6px; letter-spacing: .03em; }
.nb-tag--coral { background: rgba(255,122,89,.2); color: #FFB39E; }
.nb-tag--blue { background: rgba(110,168,255,.2); color: #AECBFF; }
.nb-tag--lime { background: rgba(198,242,78,.2); color: #DCF58E; }
.nb-tag--green { background: rgba(80,220,140,.18); color: #92E9B8; }
.nb-bar { height: 7px; border-radius: 4px; background: rgba(255,255,255,.14); }
.nb-bar.w90 { width: 90%; } .nb-bar.w80 { width: 80%; } .nb-bar.w70 { width: 70%; }
.nb-bar.w65 { width: 65%; } .nb-bar.w60 { width: 60%; } .nb-bar.w55 { width: 55%; } .nb-bar.w40 { width: 40%; }
.nb-avatars { display: flex; margin-top: .15rem; }
.nb-avatars i { width: 18px; height: 18px; border-radius: 50%; border: 2px solid var(--nb-ink-2); margin-left: -6px; background: linear-gradient(135deg, var(--nb-blue), var(--nb-coral)); }
.nb-avatars i:first-child { margin-left: 0; }

.nb-mock__float { position: absolute; display: flex; align-items: center; gap: .45rem; background: #fff; color: var(--nb-ink); font-size: .8rem; font-weight: 650; padding: .55rem .8rem; border-radius: 12px; box-shadow: 0 16px 34px -14px rgba(0,0,0,.5); }
.nb-mock__float--a { top: 14%; left: -18px; color: #138a4e; animation: nbFloat 5s ease-in-out infinite; }
.nb-mock__float--a svg { color: #138a4e; }
.nb-mock__float--b { bottom: 10%; right: -14px; animation: nbFloat 6s ease-in-out infinite .6s; }
.nb-spark { display: inline-flex; align-items: flex-end; gap: 2px; height: 16px; }
.nb-spark i { width: 4px; border-radius: 2px; height: var(--h); background: var(--nb-accent); }
@keyframes nbFloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }

/* ===== Social proof ===== */
.nb-proof { padding-block: clamp(2.2rem, 4vw, 3.2rem); border-bottom: 1px solid var(--nb-line); }
.nb-proof__label { text-align: center; font-size: .82rem; letter-spacing: .08em; text-transform: uppercase; color: var(--nb-muted); margin: 0 0 1.4rem; font-weight: 600; }
.nb-proof__logos { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: clamp(1.4rem, 4vw, 3rem); }
.nb-proof__logos li { font-weight: 800; font-size: 1.15rem; letter-spacing: -0.01em; color: #9aa1b8; opacity: .85; transition: color .2s ease, opacity .2s ease; }
.nb-proof__logos li:hover { color: var(--nb-ink); opacity: 1; }

/* ===== Sections ===== */
.nb-section { padding-block: var(--nb-sect); }
.nb-section--alt { background: var(--nb-surface); border-block: 1px solid var(--nb-line); }
.nb-section__head { max-width: 60ch; margin: 0 auto clamp(2.2rem, 4vw, 3.4rem); text-align: center; }
.nb-section__head .nb-h2 { margin-top: .7rem; }
.nb-section__sub { color: var(--nb-muted); font-size: 1.06rem; margin: .85rem auto 0; max-width: 52ch; }

/* ===== Grid ===== */
.nb-grid { display: grid; gap: 1.4rem; }
.nb-grid--3 { grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); }

/* ===== Feature cards ===== */
.nb-feature { background: var(--nb-surface); border: 1px solid var(--nb-line); border-radius: var(--nb-radius); padding: 1.6rem; box-shadow: var(--nb-shadow); transition: transform .2s ease, box-shadow .25s ease, border-color .2s ease; }
.nb-section--alt .nb-feature { background: var(--nb-paper); }
.nb-feature:hover { transform: translateY(-6px); box-shadow: 0 1px 2px rgba(11,16,32,.04), 0 26px 48px -20px rgba(11,16,32,.32); border-color: #d6dae9; }
.nb-feature__icon { display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 48px; border-radius: 13px; margin-bottom: 1.05rem; }
.nb-feature__icon--lime { background: rgba(198,242,78,.18); color: #5e7a00; }
.nb-feature__icon--coral { background: rgba(255,122,89,.16); color: #d8492a; }
.nb-feature__icon--blue { background: rgba(110,168,255,.16); color: #2f6bd6; }
.nb-feature__title { font-size: var(--nb-h3); font-weight: 750; margin: 0 0 .45rem; letter-spacing: -0.01em; }
.nb-feature__text { color: var(--nb-muted); margin: 0; font-size: .97rem; }

/* ===== Steps ===== */
.nb-steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 1.4rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr)); counter-reset: nb; }
.nb-step { position: relative; padding: 1.7rem 1.5rem; border-radius: var(--nb-radius); background: var(--nb-paper); border: 1px solid var(--nb-line); }
.nb-step__num { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; background: var(--nb-ink); color: var(--nb-accent); font-weight: 800; font-size: 1.05rem; margin-bottom: .9rem; }
.nb-step__title { font-size: var(--nb-h3); font-weight: 750; margin: 0 0 .4rem; }
.nb-step__text { color: var(--nb-muted); margin: 0; font-size: .97rem; }

/* ===== Pricing ===== */
.nb-pricing { align-items: stretch; }
.nb-plan { display: flex; flex-direction: column; background: var(--nb-surface); border: 1px solid var(--nb-line); border-radius: var(--nb-radius); padding: 1.8rem 1.6rem; box-shadow: var(--nb-shadow); transition: transform .2s ease, box-shadow .25s ease; }
.nb-plan:hover { transform: translateY(-4px); }
.nb-plan--featured { position: relative; background: var(--nb-ink); color: #fff; border-color: var(--nb-ink); box-shadow: 0 30px 60px -24px rgba(11,16,32,.55); }
.nb-plan__badge { position: absolute; top: -13px; left: 50%; transform: translateX(-50%); background: var(--nb-accent); color: var(--nb-accent-ink); font-size: .7rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; padding: .32rem .7rem; border-radius: 999px; }
.nb-plan__name { font-size: 1.05rem; font-weight: 750; margin: 0; }
.nb-plan__price { display: flex; align-items: baseline; gap: .35rem; margin: .7rem 0 .2rem; }
.nb-plan__amt { font-size: 2.5rem; font-weight: 850; letter-spacing: -0.03em; }
.nb-plan__per { color: var(--nb-muted); font-size: .9rem; }
.nb-plan--featured .nb-plan__per { color: rgba(255,255,255,.6); }
.nb-plan__blurb { color: var(--nb-muted); margin: 0 0 1.2rem; font-size: .95rem; }
.nb-plan--featured .nb-plan__blurb { color: rgba(255,255,255,.7); }
.nb-plan__list { list-style: none; margin: 0 0 1.5rem; padding: 0; display: grid; gap: .65rem; flex: 1; }
.nb-plan__list li { position: relative; padding-left: 1.7rem; font-size: .95rem; }
.nb-plan__list li::before { content: ""; position: absolute; left: 0; top: .15rem; width: 18px; height: 18px; border-radius: 50%; background: rgba(198,242,78,.22); }
.nb-plan__list li::after { content: ""; position: absolute; left: 6px; top: .42rem; width: 6px; height: 4px; border-left: 2px solid #5e7a00; border-bottom: 2px solid #5e7a00; transform: rotate(-45deg); }
.nb-plan--featured .nb-plan__list li::before { background: rgba(198,242,78,.28); }
.nb-plan--featured .nb-plan__list li::after { border-color: var(--nb-accent); }

/* ===== Testimonial ===== */
.nb-quote { background: var(--nb-paper); padding-block: var(--nb-sect); }
.nb-quote__inner { max-width: 760px; margin-inline: auto; text-align: center; }
.nb-quote__mark { color: var(--nb-coral); margin: 0 auto .6rem; }
.nb-quote__text { font-size: clamp(1.3rem, 1rem + 1.6vw, 1.9rem); line-height: 1.32; font-weight: 650; letter-spacing: -0.01em; margin: 0 auto 1.6rem; text-wrap: balance; }
.nb-quote__by { display: inline-flex; align-items: center; gap: .8rem; }
.nb-quote__avatar { display: inline-flex; align-items: center; justify-content: center; width: 46px; height: 46px; border-radius: 50%; background: linear-gradient(135deg, var(--nb-coral), var(--nb-blue)); color: #fff; font-weight: 800; font-size: .9rem; }
.nb-quote__name { margin: 0; font-weight: 750; }
.nb-quote__role { margin: 0; color: var(--nb-muted); font-size: .9rem; }

/* ===== FAQ ===== */
.nb-faq { max-width: 760px; }
.nb-faq__list { display: grid; gap: .8rem; }
.nb-faq__item { background: var(--nb-paper); border: 1px solid var(--nb-line); border-radius: 14px; overflow: hidden; }
.nb-faq__q { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 1rem; text-align: left; font: inherit; font-weight: 650; font-size: 1.02rem; color: var(--nb-ink); background: transparent; border: 0; cursor: pointer; padding: 1.1rem 1.25rem; }
.nb-faq__chev { color: var(--nb-muted); transition: transform .25s ease; flex: none; }
.nb-faq__q.is-open .nb-faq__chev { transform: rotate(180deg); color: var(--nb-coral); }
.nb-faq__a { display: grid; grid-template-rows: 0fr; transition: grid-template-rows .3s cubic-bezier(.2,.8,.25,1); }
.nb-faq__a.is-open { grid-template-rows: 1fr; }
.nb-faq__a-inner { overflow: hidden; min-height: 0; }
.nb-faq__a-inner p { margin: 0; padding: 0 1.25rem 1.15rem; color: var(--nb-muted); }

/* ===== Final CTA ===== */
.nb-cta { position: relative; overflow: hidden; background: var(--nb-ink); color: #fff; padding-block: var(--nb-sect); }
.nb-cta__glow { position: absolute; inset: auto -10% -40%; height: 520px; pointer-events: none;
  background: radial-gradient(50% 60% at 50% 100%, rgba(198,242,78,.22), transparent 65%),
              radial-gradient(40% 50% at 80% 90%, rgba(255,122,89,.18), transparent 60%); }
.nb-cta__inner { position: relative; text-align: center; max-width: 640px; margin-inline: auto; }
.nb-cta__title { font-size: var(--nb-h2); font-weight: 850; letter-spacing: -0.02em; margin: 0; text-wrap: balance; }
.nb-cta__sub { color: rgba(255,255,255,.72); margin: 1rem auto 0; max-width: 46ch; font-size: 1.06rem; }
.nb-cta__actions { display: flex; flex-wrap: wrap; gap: .8rem; justify-content: center; margin-top: 1.8rem; }

/* ===== Footer ===== */
.nb-footer { background: var(--nb-ink); color: rgba(255,255,255,.7); padding-top: clamp(3rem, 5vw, 4.5rem); }
.nb-footer__grid { display: grid; grid-template-columns: 1.6fr repeat(3, 1fr); gap: 2rem; padding-bottom: 2.6rem; }
.nb-footer__tag { margin: .8rem 0 0; max-width: 26ch; font-size: .95rem; }
.nb-footer__col h4 { color: #fff; font-size: .82rem; letter-spacing: .06em; text-transform: uppercase; margin: 0 0 .9rem; }
.nb-footer__col a { display: block; color: rgba(255,255,255,.66); text-decoration: none; padding: .3rem 0; font-size: .95rem; transition: color .15s ease; }
.nb-footer__col a:hover { color: var(--nb-accent); }
.nb-footer__bar { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .6rem; padding-block: 1.4rem; border-top: 1px solid rgba(255,255,255,.1); font-size: .85rem; color: rgba(255,255,255,.5); }
.nb-footer__bar p { margin: 0; }

/* ===== Modal ===== */
.nb-modal { position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center; padding: 1.2rem; }
.nb-modal__backdrop { position: absolute; inset: 0; background: rgba(11,16,32,.6); backdrop-filter: blur(3px); }
.nb-modal__card { position: relative; width: 100%; max-width: 480px; max-height: 92vh; overflow-y: auto; background: var(--nb-surface); border-radius: 20px; padding: clamp(1.6rem, 4vw, 2.2rem); box-shadow: 0 40px 80px -24px rgba(0,0,0,.5); }
.nb-modal__t-enter { transition: opacity .26s ease, transform .26s cubic-bezier(.2,.8,.25,1); }
.nb-modal__t-leave { transition: opacity .18s ease, transform .18s ease; }
.nb-modal__t-start { opacity: 0; transform: translateY(16px) scale(.97); }
.nb-modal__t-end { opacity: 1; transform: none; }
.nb-modal__close { position: absolute; top: 1rem; right: 1rem; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--nb-line); border-radius: 10px; background: var(--nb-surface); color: var(--nb-muted); cursor: pointer; transition: color .15s ease, border-color .15s ease; }
.nb-modal__close:hover { color: var(--nb-ink); border-color: var(--nb-ink); }
.nb-modal__title { font-size: 1.5rem; font-weight: 800; letter-spacing: -0.015em; margin: .55rem 0 .35rem; }
.nb-modal__sub { color: var(--nb-muted); margin: 0 0 1.3rem; font-size: .96rem; }

/* ===== Form ===== */
.nb-form { display: grid; gap: .9rem; }
.nb-form__row { display: grid; grid-template-columns: 1fr 1fr; gap: .9rem; }
.nb-field { display: grid; gap: .35rem; }
.nb-field > span { font-size: .82rem; font-weight: 650; color: var(--nb-text); }
.nb-field input, .nb-field select { font: inherit; width: 100%; padding: .68rem .8rem; border: 1px solid var(--nb-line); border-radius: 10px; background: var(--nb-paper); color: var(--nb-text); transition: border-color .15s ease, box-shadow .15s ease; }
.nb-field input:focus, .nb-field select:focus { outline: none; border-color: var(--nb-coral); box-shadow: 0 0 0 3px rgba(255,122,89,.18); }
.nb-form__fine { margin: .2rem 0 0; font-size: .78rem; color: var(--nb-muted); text-align: center; }
.nb-form__done { text-align: center; padding: .6rem 0 .2rem; }
.nb-form__done-mark { display: inline-flex; align-items: center; justify-content: center; width: 52px; height: 52px; border-radius: 50%; background: rgba(198,242,78,.22); color: #5e7a00; margin-bottom: .8rem; }
.nb-form__done-title { font-size: 1.2rem; font-weight: 800; letter-spacing: -.01em; margin: 0 0 .4rem; }
.nb-form__done-sub { color: var(--nb-muted); margin: 0 0 1.3rem; font-size: .96rem; }

/* ===== Entrance animation ===== */
.nb-anim { opacity: 0; transform: translateY(20px); animation: nbReveal .7s cubic-bezier(.2,.7,.25,1) forwards; animation-delay: var(--d, 0ms); }
@keyframes nbReveal { to { opacity: 1; transform: none; } }

/* ===== Responsive ===== */
@media (max-width: 900px) {
  .nb-hero__grid { grid-template-columns: 1fr; }
  .nb-hero__mock { order: -1; max-width: 460px; }
  .nb-footer__grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 760px) {
  .nb-nav__links { display: none; }
  .nb-burger { display: inline-flex; }
  .nb-hide-sm { display: none; }
}
@media (max-width: 560px) {
  .nb-form__row { grid-template-columns: 1fr; }
  .nb-footer__grid { grid-template-columns: 1fr; gap: 1.6rem; }
  .nb-mock__float--a { left: 4px; } .nb-mock__float--b { right: 4px; }
}

/* Respect reduced motion */
@media (prefers-reduced-motion: reduce) {
  .nb-anim { opacity: 1 !important; transform: none !important; animation: none !important; }
  .nb-mock__float { animation: none !important; }
  .nb-mobilemenu, .nb-faq__a { transition: none !important; }
  .nb-btn { transition: background .2s ease, color .2s ease; }
  .nb-btn:hover { transform: none; }
}
CSS;
    }

    /**
     * The page's behaviour, emitted RAW in the page's custom_js channel — never
     * inline in the HTML, where the AI HtmlSanitizer strips @click / @keydown /
     * @submit. `window.marketingApp()` is the root component (x-data="marketingApp()");
     * Alpine calls its init() automatically (no x-init needed). init() wires a
     * single delegated click listener for [data-act] triggers + the FAQ, an
     * Escape-to-close handler, and a submit handler that intercepts the demo
     * form (preventDefault in JS, not @submit.prevent). Mirrors InventoryDemo.
     */
    private function homeJs(): string
    {
        return <<<'JS'
window.marketingApp = function () {
  return {
    navOpen: false,
    demoOpen: false,
    demoSent: false,
    // Alpine auto-calls init(); the sanitizer strips inline @click/@submit, so
    // behaviour is delegated here from [data-act] hooks (and the FAQ's data-faq).
    init() {
      var self = this;

      // Delegated clicks: [data-act="method"] on this component's own buttons,
      // and the FAQ single-open toggle (its own nested x-data holds `open`).
      this.$el.addEventListener('click', function (e) {
        var act = e.target.closest('[data-act]');
        if (act && self.$el.contains(act)) {
          var fn = self[act.getAttribute('data-act')];
          if (typeof fn === 'function') { fn.call(self); return; }
        }
        var faq = e.target.closest('[data-faq]');
        if (faq && self.$el.contains(faq)) {
          self.toggleFaq(faq);
        }
      });

      // Escape closes the mobile nav and the demo modal.
      window.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { self.demoOpen = false; self.navOpen = false; }
      });

      // Contact form: intercept submit (no page reload), show the thank-you.
      this.$el.addEventListener('submit', function (e) {
        var form = e.target.closest('[data-demo-form]');
        if (! form) { return; }
        e.preventDefault();
        self.demoSent = true;
      });
    },
    toggleNav() { this.navOpen = ! this.navOpen; },
    closeNav() { this.navOpen = false; },
    openDemo() { this.demoSent = false; this.demoOpen = true; },
    // Mobile "Book a demo": close the nav sheet, then open the modal.
    openDemoFromNav() { this.navOpen = false; this.openDemo(); },
    closeDemo() { this.demoOpen = false; },
    // FAQ single-open accordion. The FAQ keeps its own x-data="{ open: 0 }"
    // scope (declarative :class survives sanitizing); resolve it and toggle.
    toggleFaq(btn) {
      var n = parseInt(btn.getAttribute('data-faq'), 10) || 0;
      var scope = window.Alpine && window.Alpine.$data ? window.Alpine.$data(btn) : null;
      if (scope) { scope.open = (scope.open === n ? 0 : n); }
    },
  };
};
JS;
    }
}
