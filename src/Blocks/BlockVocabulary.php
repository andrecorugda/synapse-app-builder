<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Blocks;

/**
 * The source of truth for the editor's blocks.
 *
 * Two families:
 *  - SECTIONS: full landing-page sections, each wrapped in
 *    `<section data-pb-block="{key}">` with stable pb-{key}__* classes. A
 *    GrapesJS component type is registered per key, so markup using this
 *    convention — dragged or AI-generated — imports as a labelled, editable
 *    component. These keys are the vocabulary the AI is constrained to.
 *  - BASICS: primitive building blocks (text, image, button, columns…) for
 *    free-form composition. Generic GrapesJS components, no data-pb-block.
 */
final class BlockVocabulary
{
    /**
     * Section blocks — the AI vocabulary.
     *
     * @return array<int,SectionBlock>
     */
    public static function sections(): array
    {
        return [
            self::block('navbar', 'Navbar', 'Top navigation bar with logo and links.', <<<'HTML'
            <section data-pb-block="navbar" class="pb-navbar" style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.5rem;border-bottom:1px solid #e2e8f0;">
              <span class="pb-navbar__brand" style="font-weight:700;font-size:1.25rem;">Brand</span>
              <nav class="pb-navbar__links" style="display:flex;gap:1.5rem;">
                <a href="#" style="color:#334155;text-decoration:none;">Features</a>
                <a href="#" style="color:#334155;text-decoration:none;">Pricing</a>
                <a href="#" style="color:#334155;text-decoration:none;">Contact</a>
              </nav>
              <a href="#" class="pb-navbar__cta" style="padding:0.5rem 1rem;border-radius:0.5rem;background:#4f46e5;color:#fff;text-decoration:none;">Sign up</a>
            </section>
            HTML),

            self::block('hero', 'Hero', 'Headline, subheadline and a primary call-to-action.', <<<'HTML'
            <section data-pb-block="hero" class="pb-hero" style="padding:5rem 1.5rem;text-align:center;">
              <h1 class="pb-hero__title" style="font-size:2.75rem;margin:0 0 1rem;">Your headline here</h1>
              <p class="pb-hero__subtitle" style="font-size:1.25rem;color:#475569;max-width:40rem;margin:0 auto 2rem;">A short, punchy subheadline that explains the value.</p>
              <a class="pb-hero__cta" href="#" style="display:inline-block;padding:0.85rem 1.75rem;border-radius:0.5rem;background:#4f46e5;color:#fff;text-decoration:none;font-weight:600;">Get started</a>
            </section>
            HTML),

            self::block('features', 'Features', 'A grid of feature cards.', <<<'HTML'
            <section data-pb-block="features" class="pb-features" style="padding:4rem 1.5rem;">
              <h2 class="pb-features__title" style="text-align:center;font-size:2rem;margin:0 0 2.5rem;">Features</h2>
              <div class="pb-features__grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;max-width:64rem;margin:0 auto;">
                <div class="pb-features__item" style="padding:1.5rem;border:1px solid #e2e8f0;border-radius:0.75rem;"><h3 style="margin:0 0 0.5rem;">Feature one</h3><p style="color:#475569;margin:0;">Describe the benefit.</p></div>
                <div class="pb-features__item" style="padding:1.5rem;border:1px solid #e2e8f0;border-radius:0.75rem;"><h3 style="margin:0 0 0.5rem;">Feature two</h3><p style="color:#475569;margin:0;">Describe the benefit.</p></div>
                <div class="pb-features__item" style="padding:1.5rem;border:1px solid #e2e8f0;border-radius:0.75rem;"><h3 style="margin:0 0 0.5rem;">Feature three</h3><p style="color:#475569;margin:0;">Describe the benefit.</p></div>
              </div>
            </section>
            HTML),

            self::block('logos', 'Logo cloud', 'A row of customer / partner logos.', <<<'HTML'
            <section data-pb-block="logos" class="pb-logos" style="padding:3rem 1.5rem;text-align:center;">
              <p class="pb-logos__caption" style="color:#64748b;margin:0 0 1.5rem;">Trusted by teams at</p>
              <div class="pb-logos__row" style="display:flex;flex-wrap:wrap;gap:2.5rem;justify-content:center;align-items:center;color:#94a3b8;font-weight:700;font-size:1.25rem;">
                <span>Acme</span><span>Globex</span><span>Initech</span><span>Umbrella</span><span>Soylent</span>
              </div>
            </section>
            HTML),

            self::block('stats', 'Stats', 'Key metrics in a row.', <<<'HTML'
            <section data-pb-block="stats" class="pb-stats" style="padding:4rem 1.5rem;background:#f8fafc;">
              <div class="pb-stats__row" style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;max-width:60rem;margin:0 auto;text-align:center;">
                <div><div style="font-size:2.5rem;font-weight:800;color:#4f46e5;">99.9%</div><div style="color:#475569;">Uptime</div></div>
                <div><div style="font-size:2.5rem;font-weight:800;color:#4f46e5;">10k+</div><div style="color:#475569;">Customers</div></div>
                <div><div style="font-size:2.5rem;font-weight:800;color:#4f46e5;">24/7</div><div style="color:#475569;">Support</div></div>
              </div>
            </section>
            HTML),

            self::block('gallery', 'Gallery', 'An image gallery grid.', <<<'HTML'
            <section data-pb-block="gallery" class="pb-gallery" style="padding:4rem 1.5rem;">
              <div class="pb-gallery__grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;max-width:64rem;margin:0 auto;">
                <img src="data:image/svg+xml;charset=utf8,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='400'%20height='300'%3E%3Crect%20width='400'%20height='300'%20fill='%23e2e8f0'/%3E%3C/svg%3E" alt="" style="width:100%;border-radius:0.5rem;">
                <img src="data:image/svg+xml;charset=utf8,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='400'%20height='300'%3E%3Crect%20width='400'%20height='300'%20fill='%23e2e8f0'/%3E%3C/svg%3E" alt="" style="width:100%;border-radius:0.5rem;">
                <img src="data:image/svg+xml;charset=utf8,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='400'%20height='300'%3E%3Crect%20width='400'%20height='300'%20fill='%23e2e8f0'/%3E%3C/svg%3E" alt="" style="width:100%;border-radius:0.5rem;">
              </div>
            </section>
            HTML),

            self::block('pricing', 'Pricing', 'Pricing tiers with a CTA each.', <<<'HTML'
            <section data-pb-block="pricing" class="pb-pricing" style="padding:4rem 1.5rem;">
              <h2 class="pb-pricing__title" style="text-align:center;font-size:2rem;margin:0 0 2.5rem;">Pricing</h2>
              <div class="pb-pricing__grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;max-width:60rem;margin:0 auto;">
                <div class="pb-pricing__plan" style="padding:2rem;border:1px solid #e2e8f0;border-radius:0.75rem;text-align:center;"><h3 style="margin:0 0 0.5rem;">Starter</h3><p style="font-size:2rem;font-weight:700;margin:0 0 1rem;">$9<span style="font-size:1rem;color:#64748b;">/mo</span></p><a href="#" style="display:inline-block;padding:0.6rem 1.25rem;border-radius:0.5rem;background:#4f46e5;color:#fff;text-decoration:none;">Choose</a></div>
                <div class="pb-pricing__plan" style="padding:2rem;border:2px solid #4f46e5;border-radius:0.75rem;text-align:center;"><h3 style="margin:0 0 0.5rem;">Pro</h3><p style="font-size:2rem;font-weight:700;margin:0 0 1rem;">$29<span style="font-size:1rem;color:#64748b;">/mo</span></p><a href="#" style="display:inline-block;padding:0.6rem 1.25rem;border-radius:0.5rem;background:#4f46e5;color:#fff;text-decoration:none;">Choose</a></div>
                <div class="pb-pricing__plan" style="padding:2rem;border:1px solid #e2e8f0;border-radius:0.75rem;text-align:center;"><h3 style="margin:0 0 0.5rem;">Business</h3><p style="font-size:2rem;font-weight:700;margin:0 0 1rem;">$99<span style="font-size:1rem;color:#64748b;">/mo</span></p><a href="#" style="display:inline-block;padding:0.6rem 1.25rem;border-radius:0.5rem;background:#4f46e5;color:#fff;text-decoration:none;">Choose</a></div>
              </div>
            </section>
            HTML),

            self::block('testimonial', 'Testimonial', 'A customer quote with attribution.', <<<'HTML'
            <section data-pb-block="testimonial" class="pb-testimonial" style="padding:4rem 1.5rem;background:#f8fafc;text-align:center;">
              <blockquote class="pb-testimonial__quote" style="font-size:1.5rem;max-width:48rem;margin:0 auto 1rem;font-style:italic;">“This product changed how our team works.”</blockquote>
              <p class="pb-testimonial__author" style="color:#475569;margin:0;font-weight:600;">Jane Doe, CEO at Acme</p>
            </section>
            HTML),

            self::block('faq', 'FAQ', 'Frequently asked questions.', <<<'HTML'
            <section data-pb-block="faq" class="pb-faq" style="padding:4rem 1.5rem;max-width:48rem;margin:0 auto;">
              <h2 class="pb-faq__title" style="text-align:center;font-size:2rem;margin:0 0 2rem;">Frequently asked questions</h2>
              <div class="pb-faq__item" style="padding:1.25rem 0;border-bottom:1px solid #e2e8f0;"><h3 style="margin:0 0 0.5rem;font-size:1.1rem;">Is there a free trial?</h3><p style="color:#475569;margin:0;">Yes, 14 days, no card required.</p></div>
              <div class="pb-faq__item" style="padding:1.25rem 0;border-bottom:1px solid #e2e8f0;"><h3 style="margin:0 0 0.5rem;font-size:1.1rem;">Can I cancel anytime?</h3><p style="color:#475569;margin:0;">Absolutely — cancel in one click.</p></div>
            </section>
            HTML),

            self::block('team', 'Team', 'Team member cards.', <<<'HTML'
            <section data-pb-block="team" class="pb-team" style="padding:4rem 1.5rem;">
              <h2 class="pb-team__title" style="text-align:center;font-size:2rem;margin:0 0 2.5rem;">Meet the team</h2>
              <div class="pb-team__grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;max-width:60rem;margin:0 auto;text-align:center;">
                <div><img src="data:image/svg+xml;charset=utf8,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='120'%20height='120'%3E%3Crect%20width='120'%20height='120'%20fill='%23e2e8f0'/%3E%3C/svg%3E" alt="" style="width:96px;height:96px;border-radius:50%;object-fit:cover;"><h3 style="margin:0.75rem 0 0.25rem;">Alex Kim</h3><p style="color:#64748b;margin:0;">CEO</p></div>
                <div><img src="data:image/svg+xml;charset=utf8,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='120'%20height='120'%3E%3Crect%20width='120'%20height='120'%20fill='%23e2e8f0'/%3E%3C/svg%3E" alt="" style="width:96px;height:96px;border-radius:50%;object-fit:cover;"><h3 style="margin:0.75rem 0 0.25rem;">Sam Lee</h3><p style="color:#64748b;margin:0;">CTO</p></div>
                <div><img src="data:image/svg+xml;charset=utf8,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='120'%20height='120'%3E%3Crect%20width='120'%20height='120'%20fill='%23e2e8f0'/%3E%3C/svg%3E" alt="" style="width:96px;height:96px;border-radius:50%;object-fit:cover;"><h3 style="margin:0.75rem 0 0.25rem;">Jo Park</h3><p style="color:#64748b;margin:0;">Design</p></div>
              </div>
            </section>
            HTML),

            self::block('cta', 'Call to action', 'A closing call-to-action banner.', <<<'HTML'
            <section data-pb-block="cta" class="pb-cta" style="padding:4rem 1.5rem;text-align:center;background:#4f46e5;color:#fff;">
              <h2 class="pb-cta__title" style="font-size:2rem;margin:0 0 1rem;">Ready to get started?</h2>
              <a class="pb-cta__button" href="#" style="display:inline-block;padding:0.85rem 1.75rem;border-radius:0.5rem;background:#fff;color:#4f46e5;text-decoration:none;font-weight:600;">Sign up free</a>
            </section>
            HTML),

            self::block('contact', 'Contact', 'A simple contact form.', <<<'HTML'
            <section data-pb-block="contact" class="pb-contact" style="padding:4rem 1.5rem;max-width:36rem;margin:0 auto;">
              <h2 class="pb-contact__title" style="text-align:center;font-size:2rem;margin:0 0 2rem;">Get in touch</h2>
              <form class="pb-contact__form" style="display:flex;flex-direction:column;gap:1rem;">
                <input type="text" placeholder="Name" style="padding:0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;">
                <input type="email" placeholder="Email" style="padding:0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;">
                <textarea placeholder="Message" rows="4" style="padding:0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;"></textarea>
                <button type="submit" style="padding:0.75rem;border:0;border-radius:0.5rem;background:#4f46e5;color:#fff;font-weight:600;cursor:pointer;">Send</button>
              </form>
            </section>
            HTML),

            self::block('footer', 'Footer', 'A footer with links and copyright.', <<<'HTML'
            <section data-pb-block="footer" class="pb-footer" style="padding:2.5rem 1.5rem;background:#0f172a;color:#cbd5e1;text-align:center;">
              <p class="pb-footer__links" style="margin:0 0 0.75rem;"><a href="#" style="color:#cbd5e1;text-decoration:none;margin:0 0.75rem;">About</a><a href="#" style="color:#cbd5e1;text-decoration:none;margin:0 0.75rem;">Pricing</a><a href="#" style="color:#cbd5e1;text-decoration:none;margin:0 0.75rem;">Contact</a></p>
              <p class="pb-footer__copy" style="margin:0;font-size:0.875rem;color:#64748b;">© Your Company. All rights reserved.</p>
            </section>
            HTML),
        ];
    }

    /**
     * Primitive building blocks for free-form composition (no data-pb-block).
     *
     * @return array<int,SectionBlock>
     */
    public static function basics(): array
    {
        return [
            self::block('text', 'Text', 'A paragraph of text.', '<p style="padding:0.5rem;">Insert your text here.</p>', 'Basic'),
            self::block('heading', 'Heading', 'A section heading.', '<h2 style="padding:0.5rem;">Heading</h2>', 'Basic'),
            // The editor registers this as GrapesJS's native image component (opens
            // the media picker); the template here is just a fallback.
            self::block('image', 'Image', 'An image.', '<img alt="" style="max-width:100%;">', 'Basic'),
            self::block('button', 'Button', 'A call-to-action button.', '<a href="#" style="display:inline-block;padding:0.7rem 1.4rem;border-radius:0.5rem;background:#4f46e5;color:#fff;text-decoration:none;font-weight:600;">Button</a>', 'Basic'),
            self::block('columns-2', '2 Columns', 'Two-column row.', '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;padding:1rem;"><div style="min-height:3rem;border:1px dashed #cbd5e1;"></div><div style="min-height:3rem;border:1px dashed #cbd5e1;"></div></div>', 'Basic'),
            self::block('columns-3', '3 Columns', 'Three-column row.', '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;padding:1rem;"><div style="min-height:3rem;border:1px dashed #cbd5e1;"></div><div style="min-height:3rem;border:1px dashed #cbd5e1;"></div><div style="min-height:3rem;border:1px dashed #cbd5e1;"></div></div>', 'Basic'),
            self::block('spacer', 'Spacer', 'Vertical spacing.', '<div style="height:3rem;"></div>', 'Basic'),
            self::block('divider', 'Divider', 'A horizontal rule.', '<hr style="border:0;border-top:1px solid #e2e8f0;margin:1.5rem 0;">', 'Basic'),
        ];
    }

    /**
     * Decorative shape dividers (full-width SVGs placed between sections).
     *
     * @return array<int,SectionBlock>
     */
    public static function shapes(): array
    {
        return [
            self::block('shape-wave', 'Wave', 'A wavy section divider.', <<<'HTML'
            <div data-pb-block="shape-wave" class="pb-shape" style="line-height:0;"><svg viewBox="0 0 1200 120" preserveAspectRatio="none" style="display:block;width:100%;height:80px;"><path d="M0,0 C300,120 900,0 1200,80 L1200,120 L0,120 Z" fill="#4f46e5"></path></svg></div>
            HTML, 'Shapes'),
            self::block('shape-slant', 'Slant', 'A diagonal section divider.', <<<'HTML'
            <div data-pb-block="shape-slant" class="pb-shape" style="line-height:0;"><svg viewBox="0 0 1200 120" preserveAspectRatio="none" style="display:block;width:100%;height:80px;"><path d="M0,120 L1200,0 L1200,120 Z" fill="#4f46e5"></path></svg></div>
            HTML, 'Shapes'),
            self::block('shape-tilt', 'Tilt', 'A tilted section divider.', <<<'HTML'
            <div data-pb-block="shape-tilt" class="pb-shape" style="line-height:0;"><svg viewBox="0 0 1200 120" preserveAspectRatio="none" style="display:block;width:100%;height:80px;"><path d="M0,0 L1200,120 L0,120 Z" fill="#4f46e5"></path></svg></div>
            HTML, 'Shapes'),
            self::block('shape-curve', 'Curve', 'A curved section divider.', <<<'HTML'
            <div data-pb-block="shape-curve" class="pb-shape" style="line-height:0;"><svg viewBox="0 0 1200 120" preserveAspectRatio="none" style="display:block;width:100%;height:80px;"><path d="M0,120 C400,0 800,0 1200,120 Z" fill="#4f46e5"></path></svg></div>
            HTML, 'Shapes'),
        ];
    }

    /**
     * Interactive UI-kit components.
     *
     * OWNER-authored (trusted) blocks. Published pages already load Alpine
     * (window.Alpine + $store.app), so overlay/disclosure components carry
     * local Alpine state via executable directives (x-data, @click, x-show…).
     * Each block's root element carries data-pb-block="{key}" and uses inline
     * styles + stable pb-{key}__* classes (no host Tailwind on the page).
     * Overlay panels use x-cloak so they stay hidden in the editor canvas,
     * where Alpine does not run.
     *
     * @return array<int,SectionBlock>
     */
    public static function components(): array
    {
        return [
            self::block('card', 'Card', 'Titled content container with heading, text and a slot.', <<<'HTML'
            <div data-pb-block="card" class="pb-card" style="max-width:24rem;border:1px solid #e2e8f0;border-radius:0.75rem;background:#fff;box-shadow:0 1px 3px rgba(15,23,42,0.08);overflow:hidden;">
              <div class="pb-card__body" style="padding:1.5rem;">
                <h3 class="pb-card__title" style="margin:0 0 0.5rem;font-size:1.15rem;color:#0f172a;">Card title</h3>
                <p class="pb-card__text" style="margin:0;color:#475569;line-height:1.6;">Supporting copy that explains the content of this card. Add anything you like below.</p>
                <div class="pb-card__slot" style="margin-top:1rem;"></div>
              </div>
            </div>
            HTML, 'Components'),

            self::block('banner', 'Banner', 'Dismissible inline alert strip.', <<<'HTML'
            <div data-pb-block="banner" class="pb-banner" x-data="{ show: true }" x-show="show" style="display:flex;align-items:center;gap:0.75rem;padding:0.85rem 1rem;border:1px solid #bfdbfe;border-radius:0.5rem;background:#eff6ff;color:#1e3a8a;">
              <svg class="pb-banner__icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="11" x2="12" y2="16"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
              <span class="pb-banner__message" style="flex:1;line-height:1.5;">Heads up — this is an informational message.</span>
              <button type="button" class="pb-banner__dismiss" @click="show = false" aria-label="Dismiss" style="flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:1.75rem;height:1.75rem;padding:0;border:0;border-radius:0.375rem;background:transparent;color:inherit;cursor:pointer;line-height:0;">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
            HTML, 'Components'),

            self::block('modal', 'Modal', 'Trigger button with a centered overlay dialog.', <<<'HTML'
            <div data-pb-block="modal" class="pb-modal" x-data="{ open: false }">
              <button type="button" class="pb-modal__trigger" @click="open = true" style="display:inline-block;padding:0.7rem 1.4rem;border:0;border-radius:0.5rem;background:#4f46e5;color:#fff;font-weight:600;cursor:pointer;">Open modal</button>
              <div class="pb-modal__overlay" x-show="open" x-cloak x-transition.opacity @keydown.escape.window="open = false" @click.self="open = false" style="position:fixed;inset:0;display:flex;align-items:center;justify-content:center;padding:1rem;background:rgba(15,23,42,0.55);z-index:1000;">
                <div class="pb-modal__panel" role="dialog" aria-modal="true" x-transition style="width:100%;max-width:28rem;background:#fff;border-radius:0.75rem;box-shadow:0 20px 50px rgba(15,23,42,0.25);overflow:hidden;">
                  <div class="pb-modal__body" style="padding:1.75rem;">
                    <h3 class="pb-modal__title" style="margin:0 0 0.75rem;font-size:1.25rem;color:#0f172a;">Modal title</h3>
                    <p class="pb-modal__text" style="margin:0;color:#475569;line-height:1.6;">Put your dialog content here. Press Escape, click the backdrop, or use the buttons to close.</p>
                  </div>
                  <div class="pb-modal__actions" style="display:flex;justify-content:flex-end;gap:0.75rem;padding:1rem 1.75rem;border-top:1px solid #e2e8f0;background:#f8fafc;">
                    <button type="button" class="pb-modal__close" @click="open = false" style="padding:0.55rem 1.1rem;border:1px solid #cbd5e1;border-radius:0.5rem;background:#fff;color:#334155;font-weight:600;cursor:pointer;">Close</button>
                    <button type="button" class="pb-modal__confirm" @click="open = false" style="padding:0.55rem 1.1rem;border:0;border-radius:0.5rem;background:#4f46e5;color:#fff;font-weight:600;cursor:pointer;">Confirm</button>
                  </div>
                </div>
              </div>
            </div>
            HTML, 'Components'),

            self::block('drawer', 'Drawer', 'Trigger button with a right-edge slide-over panel.', <<<'HTML'
            <div data-pb-block="drawer" class="pb-drawer" x-data="{ open: false }">
              <button type="button" class="pb-drawer__trigger" @click="open = true" style="display:inline-block;padding:0.7rem 1.4rem;border:0;border-radius:0.5rem;background:#4f46e5;color:#fff;font-weight:600;cursor:pointer;">Open drawer</button>
              <div class="pb-drawer__backdrop" x-show="open" x-cloak x-transition.opacity @click="open = false" @keydown.escape.window="open = false" style="position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:1000;"></div>
              <aside class="pb-drawer__panel" role="dialog" aria-modal="true" x-show="open" x-cloak x-transition:enter="pb-drawer-enter" x-transition:enter-start="pb-drawer-enter-start" x-transition:enter-end="pb-drawer-enter-end" x-transition:leave="pb-drawer-leave" x-transition:leave-start="pb-drawer-enter-end" x-transition:leave-end="pb-drawer-enter-start" style="position:fixed;top:0;right:0;height:100%;width:360px;max-width:90vw;background:#fff;box-shadow:-12px 0 40px rgba(15,23,42,0.18);z-index:1001;display:flex;flex-direction:column;">
                <div class="pb-drawer__header" style="display:flex;align-items:center;justify-content:space-between;padding:1.25rem 1.5rem;border-bottom:1px solid #e2e8f0;">
                  <h3 class="pb-drawer__title" style="margin:0;font-size:1.15rem;color:#0f172a;">Drawer</h3>
                  <button type="button" class="pb-drawer__close" @click="open = false" aria-label="Close" style="display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;padding:0;border:0;border-radius:0.375rem;background:transparent;color:#475569;cursor:pointer;line-height:0;">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </div>
                <div class="pb-drawer__body" style="padding:1.5rem;overflow-y:auto;color:#475569;line-height:1.6;">
                  <p style="margin:0;">Slide-over panel content. Great for navigation, filters or details.</p>
                </div>
              </aside>
            </div>
            HTML, 'Components'),

            self::block('tabs', 'Tabs', 'Tabbed panels — switch content without navigating.', <<<'HTML'
            <div data-pb-block="tabs" class="pb-tabs" x-data="{ tab: 'one' }" style="max-width:36rem;">
              <div class="pb-tabs__list" role="tablist" style="display:flex;gap:0.25rem;border-bottom:1px solid #e2e8f0;">
                <button type="button" class="pb-tabs__tab" role="tab" @click="tab = 'one'" :aria-selected="tab === 'one'" :style="tab === 'one' ? 'color:#4f46e5;border-bottom-color:#4f46e5' : 'color:#64748b;border-bottom-color:transparent'" style="padding:0.7rem 1rem;border:0;border-bottom:2px solid transparent;background:transparent;font-weight:600;cursor:pointer;">Tab one</button>
                <button type="button" class="pb-tabs__tab" role="tab" @click="tab = 'two'" :aria-selected="tab === 'two'" :style="tab === 'two' ? 'color:#4f46e5;border-bottom-color:#4f46e5' : 'color:#64748b;border-bottom-color:transparent'" style="padding:0.7rem 1rem;border:0;border-bottom:2px solid transparent;background:transparent;font-weight:600;cursor:pointer;">Tab two</button>
                <button type="button" class="pb-tabs__tab" role="tab" @click="tab = 'three'" :aria-selected="tab === 'three'" :style="tab === 'three' ? 'color:#4f46e5;border-bottom-color:#4f46e5' : 'color:#64748b;border-bottom-color:transparent'" style="padding:0.7rem 1rem;border:0;border-bottom:2px solid transparent;background:transparent;font-weight:600;cursor:pointer;">Tab three</button>
              </div>
              <div class="pb-tabs__panels" style="padding:1.25rem 0;color:#475569;line-height:1.6;">
                <div class="pb-tabs__panel" role="tabpanel" x-show="tab === 'one'"><p style="margin:0;">Content for the first tab.</p></div>
                <div class="pb-tabs__panel" role="tabpanel" x-show="tab === 'two'" x-cloak><p style="margin:0;">Content for the second tab.</p></div>
                <div class="pb-tabs__panel" role="tabpanel" x-show="tab === 'three'" x-cloak><p style="margin:0;">Content for the third tab.</p></div>
              </div>
            </div>
            HTML, 'Components'),

            self::block('accordion', 'Accordion', 'Collapsible items — expand one at a time.', <<<'HTML'
            <div data-pb-block="accordion" class="pb-accordion" style="max-width:36rem;border:1px solid #e2e8f0;border-radius:0.75rem;overflow:hidden;">
              <div class="pb-accordion__item" x-data="{ open: true }" style="border-bottom:1px solid #e2e8f0;">
                <button type="button" class="pb-accordion__header" @click="open = !open" :aria-expanded="open" style="display:flex;align-items:center;justify-content:space-between;width:100%;padding:1rem 1.25rem;border:0;background:#fff;font-weight:600;color:#0f172a;text-align:left;cursor:pointer;">
                  <span>First item</span>
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg)' : ''" style="transition:transform 0.2s ease;"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="pb-accordion__body" x-show="open" x-cloak style="padding:0 1.25rem 1rem;color:#475569;line-height:1.6;"><p style="margin:0;">Body content for the first item.</p></div>
              </div>
              <div class="pb-accordion__item" x-data="{ open: false }" style="border-bottom:1px solid #e2e8f0;">
                <button type="button" class="pb-accordion__header" @click="open = !open" :aria-expanded="open" style="display:flex;align-items:center;justify-content:space-between;width:100%;padding:1rem 1.25rem;border:0;background:#fff;font-weight:600;color:#0f172a;text-align:left;cursor:pointer;">
                  <span>Second item</span>
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg)' : ''" style="transition:transform 0.2s ease;"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="pb-accordion__body" x-show="open" x-cloak style="padding:0 1.25rem 1rem;color:#475569;line-height:1.6;"><p style="margin:0;">Body content for the second item.</p></div>
              </div>
              <div class="pb-accordion__item" x-data="{ open: false }">
                <button type="button" class="pb-accordion__header" @click="open = !open" :aria-expanded="open" style="display:flex;align-items:center;justify-content:space-between;width:100%;padding:1rem 1.25rem;border:0;background:#fff;font-weight:600;color:#0f172a;text-align:left;cursor:pointer;">
                  <span>Third item</span>
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg)' : ''" style="transition:transform 0.2s ease;"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="pb-accordion__body" x-show="open" x-cloak style="padding:0 1.25rem 1rem;color:#475569;line-height:1.6;"><p style="margin:0;">Body content for the third item.</p></div>
              </div>
            </div>
            HTML, 'Components'),

            self::block('tooltip', 'Tooltip', 'Hover/focus hint bubble above an element.', <<<'HTML'
            <div data-pb-block="tooltip" class="pb-tooltip" x-data="{ h: false }" style="display:inline-block;position:relative;">
              <button type="button" class="pb-tooltip__trigger" @mouseenter="h = true" @mouseleave="h = false" @focus="h = true" @blur="h = false" :aria-describedby="h ? 'pb-tooltip-bubble' : null" style="display:inline-block;padding:0.6rem 1.1rem;border:1px solid #cbd5e1;border-radius:0.5rem;background:#fff;color:#334155;font-weight:600;cursor:default;">Hover me</button>
              <span class="pb-tooltip__bubble" id="pb-tooltip-bubble" role="tooltip" x-show="h" x-cloak x-transition.opacity style="position:absolute;bottom:calc(100% + 0.5rem);left:50%;transform:translateX(-50%);white-space:nowrap;padding:0.4rem 0.65rem;border-radius:0.375rem;background:#0f172a;color:#fff;font-size:0.8125rem;line-height:1.3;box-shadow:0 4px 12px rgba(15,23,42,0.25);z-index:10;">Helpful hint goes here</span>
            </div>
            HTML, 'Components'),

            self::block('dropdown_menu', 'Dropdown menu', 'Button that toggles a menu of actions.', <<<'HTML'
            <div data-pb-block="dropdown_menu" class="pb-dropdown" x-data="{ open: false }" style="display:inline-block;position:relative;">
              <button type="button" class="pb-dropdown__trigger" @click="open = !open" :aria-expanded="open" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.6rem 1.1rem;border:1px solid #cbd5e1;border-radius:0.5rem;background:#fff;color:#334155;font-weight:600;cursor:pointer;">
                <span>Options</span>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg)' : ''" style="transition:transform 0.2s ease;"><polyline points="6 9 12 15 18 9"/></svg>
              </button>
              <div class="pb-dropdown__menu" role="menu" x-show="open" x-cloak x-transition.opacity @click.outside="open = false" @keydown.escape.window="open = false" style="position:absolute;top:calc(100% + 0.35rem);left:0;min-width:11rem;padding:0.35rem;background:#fff;border:1px solid #e2e8f0;border-radius:0.5rem;box-shadow:0 12px 32px rgba(15,23,42,0.16);z-index:20;">
                <a href="#" class="pb-dropdown__item" role="menuitem" @click="open = false" style="display:block;padding:0.5rem 0.65rem;border-radius:0.375rem;color:#334155;text-decoration:none;">Edit</a>
                <a href="#" class="pb-dropdown__item" role="menuitem" @click="open = false" style="display:block;padding:0.5rem 0.65rem;border-radius:0.375rem;color:#334155;text-decoration:none;">Duplicate</a>
                <a href="#" class="pb-dropdown__item" role="menuitem" @click="open = false" style="display:block;padding:0.5rem 0.65rem;border-radius:0.375rem;color:#dc2626;text-decoration:none;">Delete</a>
              </div>
            </div>
            HTML, 'Components'),
        ];
    }

    /**
     * Every block (sections + basics + shapes + components) for the GrapesJS
     * block manager.
     *
     * @return array<int,SectionBlock>
     */
    public static function all(): array
    {
        return [...self::sections(), ...self::basics(), ...self::shapes(), ...self::components()];
    }

    /**
     * Section keys only — the vocabulary the AI is allowed to emit.
     *
     * @return array<int,string>
     */
    public static function keys(): array
    {
        return array_map(static fn (SectionBlock $b): string => $b->key, self::sections());
    }

    public static function find(string $key): ?SectionBlock
    {
        foreach (self::all() as $block) {
            if ($block->key === $key) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Serializable form for the GrapesJS block manager (JS side).
     *
     * @return array<int,array{key:string,label:string,category:string,template:string,description:string}>
     */
    public static function toArray(): array
    {
        return array_map(static fn (SectionBlock $b): array => $b->toArray(), self::all());
    }

    private static function block(string $key, string $label, string $description, string $template, string $category = 'Sections'): SectionBlock
    {
        return new SectionBlock($key, $label, $category, trim($template), $description, Icons::for($key));
    }
}
