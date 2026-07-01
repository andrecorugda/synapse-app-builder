<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Blocks;

use Andre\AiPageBuilder\Capabilities\ComponentRegistry;

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
    /** Category marking a top-level page section (the AI's page-generation vocabulary). */
    public const SECTION_CATEGORY = 'Sections';

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
     * OWNER-authored blocks that nonetheless survive the AI {@see HtmlSanitizer}
     * unchanged: the sanitizer STRIPS executable Alpine (@click / @keydown /
     * @mouseenter / x-on: / x-init), so these blocks carry NO inline handlers.
     * Local reactive state stays in DECLARATIVE Alpine (x-data / x-show / x-bind
     * / x-transition), and every action is DELEGATED through data-pb-* hooks
     * (data-pb-open / data-pb-close / data-pb-toggle / data-pb-set / data-pb-hover
     * / data-pb-dismiss / data-pb-outside-close / data-pb-escape-close). The
     * published-page runtime (page.blade.php) binds one delegated listener that
     * resolves the owning component via Alpine.$data(el) and mutates the named
     * x-data prop — nothing the sanitizer removes ever appears in saved markup.
     * Each block's root carries data-pb-block="{key}" and uses inline styles +
     * stable pb-{key}__* classes (no host Tailwind on the page). Overlay panels
     * use x-cloak so they stay hidden in the editor canvas, where Alpine is off.
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
              <button type="button" class="pb-banner__dismiss" data-pb-dismiss aria-label="Dismiss" style="flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:1.75rem;height:1.75rem;padding:0;border:0;border-radius:0.375rem;background:transparent;color:inherit;cursor:pointer;line-height:0;">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
            HTML, 'Components'),

            self::block('modal', 'Modal', 'Trigger button with a centered overlay dialog.', <<<'HTML'
            <div data-pb-block="modal" class="pb-modal" x-data="{ open: false }">
              <button type="button" class="pb-modal__trigger" data-pb-open="open" style="display:inline-block;padding:0.7rem 1.4rem;border:0;border-radius:0.5rem;background:#4f46e5;color:#fff;font-weight:600;cursor:pointer;">Open modal</button>
              <div class="pb-modal__overlay" x-show="open" x-cloak x-transition.opacity data-pb-close="open" data-pb-close-self data-pb-escape-close="open" style="position:fixed;inset:0;display:flex;align-items:center;justify-content:center;padding:1rem;background:rgba(15,23,42,0.55);z-index:1000;">
                <div class="pb-modal__panel" role="dialog" aria-modal="true" x-transition style="width:100%;max-width:28rem;background:#fff;border-radius:0.75rem;box-shadow:0 20px 50px rgba(15,23,42,0.25);overflow:hidden;">
                  <div class="pb-modal__body" style="padding:1.75rem;">
                    <h3 class="pb-modal__title" style="margin:0 0 0.75rem;font-size:1.25rem;color:#0f172a;">Modal title</h3>
                    <p class="pb-modal__text" style="margin:0;color:#475569;line-height:1.6;">Put your dialog content here. Press Escape, click the backdrop, or use the buttons to close.</p>
                  </div>
                  <div class="pb-modal__actions" style="display:flex;justify-content:flex-end;gap:0.75rem;padding:1rem 1.75rem;border-top:1px solid #e2e8f0;background:#f8fafc;">
                    <button type="button" class="pb-modal__close" data-pb-close="open" style="padding:0.55rem 1.1rem;border:1px solid #cbd5e1;border-radius:0.5rem;background:#fff;color:#334155;font-weight:600;cursor:pointer;">Close</button>
                    <button type="button" class="pb-modal__confirm" data-pb-close="open" style="padding:0.55rem 1.1rem;border:0;border-radius:0.5rem;background:#4f46e5;color:#fff;font-weight:600;cursor:pointer;">Confirm</button>
                  </div>
                </div>
              </div>
            </div>
            HTML, 'Components'),

            self::block('drawer', 'Drawer', 'Trigger button with a right-edge slide-over panel.', <<<'HTML'
            <div data-pb-block="drawer" class="pb-drawer" x-data="{ open: false }">
              <button type="button" class="pb-drawer__trigger" data-pb-open="open" style="display:inline-block;padding:0.7rem 1.4rem;border:0;border-radius:0.5rem;background:#4f46e5;color:#fff;font-weight:600;cursor:pointer;">Open drawer</button>
              <div class="pb-drawer__backdrop" x-show="open" x-cloak x-transition.opacity data-pb-close="open" data-pb-escape-close="open" style="position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:1000;"></div>
              <aside class="pb-drawer__panel" role="dialog" aria-modal="true" x-show="open" x-cloak x-transition:enter="pb-drawer-enter" x-transition:enter-start="pb-drawer-enter-start" x-transition:enter-end="pb-drawer-enter-end" x-transition:leave="pb-drawer-leave" x-transition:leave-start="pb-drawer-enter-end" x-transition:leave-end="pb-drawer-enter-start" style="position:fixed;top:0;right:0;height:100%;width:360px;max-width:90vw;background:#fff;box-shadow:-12px 0 40px rgba(15,23,42,0.18);z-index:1001;display:flex;flex-direction:column;">
                <div class="pb-drawer__header" style="display:flex;align-items:center;justify-content:space-between;padding:1.25rem 1.5rem;border-bottom:1px solid #e2e8f0;">
                  <h3 class="pb-drawer__title" style="margin:0;font-size:1.15rem;color:#0f172a;">Drawer</h3>
                  <button type="button" class="pb-drawer__close" data-pb-close="open" aria-label="Close" style="display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;padding:0;border:0;border-radius:0.375rem;background:transparent;color:#475569;cursor:pointer;line-height:0;">
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
                <button type="button" class="pb-tabs__tab" role="tab" data-pb-set="tab:'one'" :aria-selected="tab === 'one'" :style="tab === 'one' ? 'color:#4f46e5;border-bottom-color:#4f46e5' : 'color:#64748b;border-bottom-color:transparent'" style="padding:0.7rem 1rem;border:0;border-bottom:2px solid transparent;background:transparent;font-weight:600;cursor:pointer;">Tab one</button>
                <button type="button" class="pb-tabs__tab" role="tab" data-pb-set="tab:'two'" :aria-selected="tab === 'two'" :style="tab === 'two' ? 'color:#4f46e5;border-bottom-color:#4f46e5' : 'color:#64748b;border-bottom-color:transparent'" style="padding:0.7rem 1rem;border:0;border-bottom:2px solid transparent;background:transparent;font-weight:600;cursor:pointer;">Tab two</button>
                <button type="button" class="pb-tabs__tab" role="tab" data-pb-set="tab:'three'" :aria-selected="tab === 'three'" :style="tab === 'three' ? 'color:#4f46e5;border-bottom-color:#4f46e5' : 'color:#64748b;border-bottom-color:transparent'" style="padding:0.7rem 1rem;border:0;border-bottom:2px solid transparent;background:transparent;font-weight:600;cursor:pointer;">Tab three</button>
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
                <button type="button" class="pb-accordion__header" data-pb-toggle="open" :aria-expanded="open" style="display:flex;align-items:center;justify-content:space-between;width:100%;padding:1rem 1.25rem;border:0;background:#fff;font-weight:600;color:#0f172a;text-align:left;cursor:pointer;">
                  <span>First item</span>
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg)' : ''" style="transition:transform 0.2s ease;"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="pb-accordion__body" x-show="open" x-cloak style="padding:0 1.25rem 1rem;color:#475569;line-height:1.6;"><p style="margin:0;">Body content for the first item.</p></div>
              </div>
              <div class="pb-accordion__item" x-data="{ open: false }" style="border-bottom:1px solid #e2e8f0;">
                <button type="button" class="pb-accordion__header" data-pb-toggle="open" :aria-expanded="open" style="display:flex;align-items:center;justify-content:space-between;width:100%;padding:1rem 1.25rem;border:0;background:#fff;font-weight:600;color:#0f172a;text-align:left;cursor:pointer;">
                  <span>Second item</span>
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg)' : ''" style="transition:transform 0.2s ease;"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="pb-accordion__body" x-show="open" x-cloak style="padding:0 1.25rem 1rem;color:#475569;line-height:1.6;"><p style="margin:0;">Body content for the second item.</p></div>
              </div>
              <div class="pb-accordion__item" x-data="{ open: false }">
                <button type="button" class="pb-accordion__header" data-pb-toggle="open" :aria-expanded="open" style="display:flex;align-items:center;justify-content:space-between;width:100%;padding:1rem 1.25rem;border:0;background:#fff;font-weight:600;color:#0f172a;text-align:left;cursor:pointer;">
                  <span>Third item</span>
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg)' : ''" style="transition:transform 0.2s ease;"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="pb-accordion__body" x-show="open" x-cloak style="padding:0 1.25rem 1rem;color:#475569;line-height:1.6;"><p style="margin:0;">Body content for the third item.</p></div>
              </div>
            </div>
            HTML, 'Components'),

            self::block('tooltip', 'Tooltip', 'Hover/focus hint bubble above an element.', <<<'HTML'
            <div data-pb-block="tooltip" class="pb-tooltip" x-data="{ h: false }" style="display:inline-block;position:relative;">
              <button type="button" class="pb-tooltip__trigger" data-pb-hover="h" :aria-describedby="h ? 'pb-tooltip-bubble' : null" style="display:inline-block;padding:0.6rem 1.1rem;border:1px solid #cbd5e1;border-radius:0.5rem;background:#fff;color:#334155;font-weight:600;cursor:default;">Hover me</button>
              <span class="pb-tooltip__bubble" id="pb-tooltip-bubble" role="tooltip" x-show="h" x-cloak x-transition.opacity style="position:absolute;bottom:calc(100% + 0.5rem);left:50%;transform:translateX(-50%);white-space:nowrap;padding:0.4rem 0.65rem;border-radius:0.375rem;background:#0f172a;color:#fff;font-size:0.8125rem;line-height:1.3;box-shadow:0 4px 12px rgba(15,23,42,0.25);z-index:10;">Helpful hint goes here</span>
            </div>
            HTML, 'Components'),

            self::block('dropdown_menu', 'Dropdown menu', 'Button that toggles a menu of actions.', <<<'HTML'
            <div data-pb-block="dropdown_menu" class="pb-dropdown" x-data="{ open: false }" data-pb-outside-close="open" data-pb-escape-close="open" style="display:inline-block;position:relative;">
              <button type="button" class="pb-dropdown__trigger" data-pb-toggle="open" :aria-expanded="open" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.6rem 1.1rem;border:1px solid #cbd5e1;border-radius:0.5rem;background:#fff;color:#334155;font-weight:600;cursor:pointer;">
                <span>Options</span>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg)' : ''" style="transition:transform 0.2s ease;"><polyline points="6 9 12 15 18 9"/></svg>
              </button>
              <div class="pb-dropdown__menu" role="menu" x-show="open" x-cloak x-transition.opacity style="position:absolute;top:calc(100% + 0.35rem);left:0;min-width:11rem;padding:0.35rem;background:#fff;border:1px solid #e2e8f0;border-radius:0.5rem;box-shadow:0 12px 32px rgba(15,23,42,0.16);z-index:20;">
                <a href="#" class="pb-dropdown__item" role="menuitem" data-pb-close="open" style="display:block;padding:0.5rem 0.65rem;border-radius:0.375rem;color:#334155;text-decoration:none;">Edit</a>
                <a href="#" class="pb-dropdown__item" role="menuitem" data-pb-close="open" style="display:block;padding:0.5rem 0.65rem;border-radius:0.375rem;color:#334155;text-decoration:none;">Duplicate</a>
                <a href="#" class="pb-dropdown__item" role="menuitem" data-pb-close="open" style="display:block;padding:0.5rem 0.65rem;border-radius:0.375rem;color:#dc2626;text-decoration:none;">Delete</a>
              </div>
            </div>
            HTML, 'Components'),

            self::block('video', 'Video', 'A self-hosted HTML5 video player.', <<<'HTML'
            <div data-pb-block="video" class="pb-video" style="max-width:100%;border-radius:var(--pb-radius,0.75rem);overflow:hidden;background:#000;">
              <video class="pb-video__el" controls playsinline poster="https://placehold.co/1280x720/0f172a/64748b?text=Video" style="display:block;width:100%;height:auto;">
                <source src="" type="video/mp4">
              </video>
            </div>
            HTML, 'Components'),

            self::block('breadcrumbs', 'Breadcrumbs', 'A breadcrumb trail.', <<<'HTML'
            <nav data-pb-block="breadcrumbs" class="pb-breadcrumbs" aria-label="Breadcrumb" style="font-family:inherit;font-size:0.875rem;color:var(--pb-muted,#64748b);">
              <ol style="list-style:none;display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem;margin:0;padding:0;">
                <li><a href="/" style="color:var(--pb-muted,#64748b);text-decoration:none;">Home</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="#" style="color:var(--pb-muted,#64748b);text-decoration:none;">Section</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page" style="color:var(--pb-ink,#0f172a);font-weight:600;">Current</li>
              </ol>
            </nav>
            HTML, 'Components'),

            self::block('rating', 'Rating', 'A star rating display.', <<<'HTML'
            <div data-pb-block="rating" class="pb-rating" role="img" aria-label="Rated 4 out of 5" style="font-size:1.25rem;letter-spacing:2px;color:var(--pb-accent,#f59e0b);">★★★★<span style="color:#cbd5e1;">★</span></div>
            HTML, 'Components'),

            self::block('progress', 'Progress bar', 'A labelled progress bar.', <<<'HTML'
            <div data-pb-block="progress" class="pb-progress" style="font-family:inherit;max-width:32rem;">
              <div class="pb-progress__label" style="display:flex;justify-content:space-between;font-size:0.8125rem;color:var(--pb-muted,#64748b);margin-bottom:0.35rem;"><span>Progress</span><span>70%</span></div>
              <div class="pb-progress__track" style="height:0.6rem;border-radius:999px;background:var(--pb-border,#e2e8f0);overflow:hidden;"><div class="pb-progress__bar" style="height:100%;width:70%;border-radius:999px;background:var(--pb-primary,#6366f1);"></div></div>
            </div>
            HTML, 'Components'),

            self::block('alert', 'Alert', 'A callout / alert box.', <<<'HTML'
            <div data-pb-block="alert" class="pb-alert" role="alert" style="display:flex;gap:0.75rem;align-items:flex-start;padding:1rem 1.15rem;border:1px solid var(--pb-border,#e2e8f0);border-left:4px solid var(--pb-primary,#6366f1);border-radius:var(--pb-radius,0.6rem);background:var(--pb-surface,#f8fafc);color:var(--pb-ink,#0f172a);font-family:inherit;">
              <span aria-hidden="true" style="font-size:1.15rem;line-height:1.3;">ℹ️</span>
              <div><strong class="pb-alert__title" style="display:block;margin-bottom:0.15rem;">Heads up</strong><span class="pb-alert__text" style="color:var(--pb-muted,#64748b);font-size:0.9375rem;">This is an informational message you can edit.</span></div>
            </div>
            HTML, 'Components'),

            self::block('avatar', 'Avatar', 'A circular avatar image.', <<<'HTML'
            <img data-pb-block="avatar" class="pb-avatar" src="https://placehold.co/96x96/6366f1/ffffff?text=A" alt="Avatar" width="64" height="64" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--pb-border,#e2e8f0);">
            HTML, 'Components'),
        ];
    }

    /**
     * Form building blocks — inputs + a Form container.
     *
     * OWNER-authored (trusted) blocks. Each input is a real form control with a
     * stable `name` attribute so the published-page runtime's collectFormInput()
     * (and a Form's record-create / flow submit) picks it up. Controls are also
     * x-model-bindable via the editor's existing x-model trait. Roots carry
     * data-pb-block="{key}" and use inline styles + pb-{key}__* classes; labels
     * are tied to controls (for/id) with focus-visible outlines for a11y.
     *
     * @return array<int,SectionBlock>
     */
    public static function forms(): array
    {
        return [
            self::block('text_input', 'Text input', 'Single-line text field with a label.', <<<'HTML'
            <label data-pb-block="text_input" class="pb-text-input" style="display:block;max-width:24rem;font-family:inherit;">
              <span class="pb-text-input__label" style="display:block;margin:0 0 0.35rem;font-weight:600;color:#0f172a;font-size:0.9375rem;">Name</span>
              <input type="text" name="name" placeholder="Your name" class="pb-text-input__control" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9375rem;color:#0f172a;outline-offset:2px;box-sizing:border-box;">
            </label>
            HTML, 'Forms'),

            self::block('email_input', 'Email input', 'Email field with a label.', <<<'HTML'
            <label data-pb-block="email_input" class="pb-email-input" style="display:block;max-width:24rem;font-family:inherit;">
              <span class="pb-email-input__label" style="display:block;margin:0 0 0.35rem;font-weight:600;color:#0f172a;font-size:0.9375rem;">Email</span>
              <input type="email" name="email" placeholder="you@example.com" class="pb-email-input__control" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9375rem;color:#0f172a;outline-offset:2px;box-sizing:border-box;">
            </label>
            HTML, 'Forms'),

            self::block('textarea', 'Textarea', 'Multi-line text field with a label.', <<<'HTML'
            <label data-pb-block="textarea" class="pb-textarea" style="display:block;max-width:24rem;font-family:inherit;">
              <span class="pb-textarea__label" style="display:block;margin:0 0 0.35rem;font-weight:600;color:#0f172a;font-size:0.9375rem;">Message</span>
              <textarea name="message" rows="4" placeholder="Type your message…" class="pb-textarea__control" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9375rem;color:#0f172a;outline-offset:2px;box-sizing:border-box;resize:vertical;font-family:inherit;"></textarea>
            </label>
            HTML, 'Forms'),

            self::block('select', 'Select', 'Dropdown select with a label.', <<<'HTML'
            <label data-pb-block="select" class="pb-select" style="display:block;max-width:24rem;font-family:inherit;">
              <span class="pb-select__label" style="display:block;margin:0 0 0.35rem;font-weight:600;color:#0f172a;font-size:0.9375rem;">Choose an option</span>
              <select name="option" class="pb-select__control" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9375rem;color:#0f172a;outline-offset:2px;box-sizing:border-box;background:#fff;">
                <option value="">— select —</option>
                <option value="one">Option one</option>
                <option value="two">Option two</option>
                <option value="three">Option three</option>
              </select>
            </label>
            HTML, 'Forms'),

            self::block('checkbox', 'Checkbox', 'A single labelled checkbox.', <<<'HTML'
            <label data-pb-block="checkbox" class="pb-checkbox" style="display:flex;align-items:center;gap:0.6rem;max-width:24rem;font-family:inherit;color:#0f172a;font-size:0.9375rem;cursor:pointer;">
              <input type="checkbox" name="agree" value="yes" class="pb-checkbox__control" style="width:1.1rem;height:1.1rem;outline-offset:2px;cursor:pointer;">
              <span class="pb-checkbox__label">I agree to the terms</span>
            </label>
            HTML, 'Forms'),

            self::block('radio_group', 'Radio group', 'A set of radio buttons sharing one name.', <<<'HTML'
            <fieldset data-pb-block="radio_group" class="pb-radio-group" style="border:0;padding:0;margin:0;max-width:24rem;font-family:inherit;">
              <legend class="pb-radio-group__legend" style="padding:0;margin:0 0 0.5rem;font-weight:600;color:#0f172a;font-size:0.9375rem;">Pick one</legend>
              <label class="pb-radio-group__option" style="display:flex;align-items:center;gap:0.6rem;margin:0 0 0.4rem;color:#0f172a;font-size:0.9375rem;cursor:pointer;">
                <input type="radio" name="choice" value="a" class="pb-radio-group__control" style="width:1.05rem;height:1.05rem;outline-offset:2px;cursor:pointer;" checked>
                <span>Option A</span>
              </label>
              <label class="pb-radio-group__option" style="display:flex;align-items:center;gap:0.6rem;margin:0 0 0.4rem;color:#0f172a;font-size:0.9375rem;cursor:pointer;">
                <input type="radio" name="choice" value="b" class="pb-radio-group__control" style="width:1.05rem;height:1.05rem;outline-offset:2px;cursor:pointer;">
                <span>Option B</span>
              </label>
              <label class="pb-radio-group__option" style="display:flex;align-items:center;gap:0.6rem;margin:0;color:#0f172a;font-size:0.9375rem;cursor:pointer;">
                <input type="radio" name="choice" value="c" class="pb-radio-group__control" style="width:1.05rem;height:1.05rem;outline-offset:2px;cursor:pointer;">
                <span>Option C</span>
              </label>
            </fieldset>
            HTML, 'Forms'),

            self::block('submit_button', 'Submit button', 'A form submit button.', <<<'HTML'
            <button type="submit" data-pb-block="submit_button" class="pb-submit-button" style="display:inline-block;padding:0.7rem 1.4rem;border:0;border-radius:0.5rem;background:#4f46e5;color:#fff;font-weight:600;font-size:0.9375rem;cursor:pointer;outline-offset:2px;font-family:inherit;">Submit</button>
            HTML, 'Forms'),

            self::block('form', 'Form', 'A form card with fields and a submit button.', <<<'HTML'
            <form data-pb-block="form" class="pb-form" style="display:flex;flex-direction:column;gap:1rem;max-width:28rem;padding:1.75rem;border:1px solid #e2e8f0;border-radius:0.75rem;background:#fff;box-shadow:0 1px 3px rgba(15,23,42,0.08);font-family:inherit;">
              <label class="pb-form__field" style="display:block;">
                <span style="display:block;margin:0 0 0.35rem;font-weight:600;color:#0f172a;font-size:0.9375rem;">Name</span>
                <input type="text" name="name" placeholder="Your name" required style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9375rem;color:#0f172a;outline-offset:2px;box-sizing:border-box;">
              </label>
              <label class="pb-form__field" style="display:block;">
                <span style="display:block;margin:0 0 0.35rem;font-weight:600;color:#0f172a;font-size:0.9375rem;">Email</span>
                <input type="email" name="email" placeholder="you@example.com" required style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9375rem;color:#0f172a;outline-offset:2px;box-sizing:border-box;">
              </label>
              <button type="submit" class="pb-form__submit" style="display:inline-block;padding:0.7rem 1.4rem;border:0;border-radius:0.5rem;background:#4f46e5;color:#fff;font-weight:600;font-size:0.9375rem;cursor:pointer;outline-offset:2px;">Submit</button>
            </form>
            HTML, 'Forms'),

            self::block('date_picker', 'Date picker', 'A date input field.', <<<'HTML'
            <label data-pb-block="date_picker" class="pb-field" style="display:block;font-family:inherit;max-width:24rem;">
              <span class="pb-field__label" style="display:block;font-size:0.875rem;font-weight:600;color:var(--pb-ink,#0f172a);margin-bottom:0.35rem;">Date</span>
              <input type="date" name="date" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font:inherit;color:var(--pb-ink,#0f172a);box-sizing:border-box;">
            </label>
            HTML, 'Forms'),

            self::block('file_upload', 'File upload', 'A file input field.', <<<'HTML'
            <label data-pb-block="file_upload" class="pb-field" style="display:block;font-family:inherit;max-width:24rem;">
              <span class="pb-field__label" style="display:block;font-size:0.875rem;font-weight:600;color:var(--pb-ink,#0f172a);margin-bottom:0.35rem;">Upload a file</span>
              <input type="file" name="file" style="width:100%;padding:0.5rem;border:1px dashed #cbd5e1;border-radius:0.5rem;font:inherit;color:var(--pb-muted,#64748b);box-sizing:border-box;">
            </label>
            HTML, 'Forms'),
        ];
    }

    /**
     * Data-driven blocks — render rows reactively from a collection / State.
     *
     * OWNER-authored (trusted) blocks. The published page registers
     * Alpine.data('pbTable', …) and seeds $store.app, so:
     *  - the Data Table's root carries x-data="pbTable('<collection>')"; on init
     *    it fetches GET {api}/{collection} and exposes rows/loading/error. The
     *    "Collection" trait rewrites the x-data argument.
     *  - the List repeats over a $store.app array; the "List source" trait
     *    rewrites the x-for expression.
     *
     * Editor-vs-published <template> handling: <template> content is inert in
     * the GrapesJS canvas (Alpine doesn't run there), so each block ships static
     * sample rows/items the editor can show. Those samples carry x-show="false"
     * so Alpine removes them on the published page, leaving only real rows.
     *
     * @return array<int,SectionBlock>
     */
    public static function data(): array
    {
        return [
            // Config-driven data table. All config is via data-pb-* attributes;
            // the runtime fetches the schema + rows and renders TYPE-DRIVEN cells.
            // Set data-pb-collection to point at a collection. Optional attrs:
            //   data-pb-columns="k,k:Header" — explicit columns + rename
            //   data-pb-hide="k,k"           — hide columns
            //   data-pb-sortable="true"       — column sort (default true)
            //   data-pb-searchable="true"     — search box
            //   data-pb-filters="k,k"        — filter controls
            //   data-pb-selectable="true"     — row checkboxes
            //   data-pb-bulk="delete:Delete,…"— bulk action buttons
            //   data-pb-per-page="20"         — rows per page
            //   data-pb-state="key"           — display $store.app[key] instead of collection
            self::block('data_table', 'Data Table', 'A table that lists rows from a collection.', <<<'HTML'
            <div data-pb-block="data_table" class="pb-data-table" data-pb-collection="" data-pb-per-page="20" x-data="pbTable('')" style="font-family:inherit;font-size:0.9375rem;color:#0f172a;border:1px solid #e2e8f0;border-radius:0.75rem;overflow:hidden;background:#fff;">
              <p class="pb-data-table__placeholder" x-show="false" style="padding:1rem;color:#94a3b8;font-size:0.85rem;">Set a collection in the Settings panel — the table renders on the published page.</p>
            </div>
            HTML, 'Data'),

            self::block('list', 'List', 'A list that repeats over a State array.', <<<'HTML'
            <div data-pb-block="list" class="pb-list" x-data style="font-family:inherit;color:#0f172a;display:flex;flex-direction:column;gap:0.5rem;max-width:32rem;">
              <template x-for="item in $store.app.items" :key="item.id">
                <div class="pb-list__item" x-text="item.label" style="padding:0.75rem 1rem;border:1px solid #e2e8f0;border-radius:0.5rem;background:#fff;"></div>
              </template>
              <div class="pb-list__sample" x-show="false" style="padding:0.75rem 1rem;border:1px solid #e2e8f0;border-radius:0.5rem;background:#fff;">First item</div>
              <div class="pb-list__sample" x-show="false" style="padding:0.75rem 1rem;border:1px solid #e2e8f0;border-radius:0.5rem;background:#fff;">Second item</div>
            </div>
            HTML, 'Data'),

            self::block('kpi', 'Stat card', 'A KPI number aggregated from a collection (count/sum/avg).', <<<'HTML'
            <div data-pb-block="kpi" class="pb-kpi" data-pb-collection="" data-pb-metric="count" data-pb-field="" data-pb-label="Total" style="font-family:inherit;border:1px solid #e2e8f0;border-radius:0.75rem;padding:1.25rem 1.5rem;background:#fff;min-width:12rem;display:inline-flex;flex-direction:column;gap:0.4rem;">
              <span class="pb-kpi__label" style="font-size:0.72rem;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">Total</span>
              <span class="pb-kpi__value" data-pb-kpi-value style="font-size:2rem;font-weight:700;color:#0f172a;line-height:1.1;">—</span>
            </div>
            HTML, 'Data'),

            self::block('chart', 'Chart', 'A chart (bar/line/donut/area) aggregated from a collection via Chart.js.', <<<'HTML'
            <div data-pb-block="chart" class="pb-chart" data-pb-collection="" data-pb-metric="count" data-pb-field="" data-pb-group="" data-pb-date-bucket="" data-pb-chart-type="bar" style="font-family:inherit;border:1px solid #e2e8f0;border-radius:0.75rem;padding:1.25rem;background:#fff;max-width:40rem;">
              <div class="pb-chart__title" style="font-size:0.95rem;font-weight:600;color:#334155;margin-bottom:0.75rem;">Chart</div>
              <div class="pb-chart__canvas-wrap" style="position:relative;height:18rem;">
                <canvas class="pb-chart__canvas"></canvas>
              </div>
              <div class="pb-chart__placeholder" style="color:#94a3b8;font-size:0.85rem;padding:0.5rem 0;">Pick a collection in the Settings panel — the chart renders on the published page.</div>
            </div>
            HTML, 'Data'),

            // An embed (iframe). Categorised under Components; lives here for proximity
            // to the other URL/data-driven blocks. The runtime sets the iframe src
            // from data-pb-embed-url (and normalizes YouTube/Vimeo share links).
            self::block('embed', 'Embed / iframe', 'Embed a YouTube, Vimeo, Maps or any URL via an iframe.', <<<'HTML'
            <div data-pb-block="embed" class="pb-embed" data-pb-embed-url="" style="font-family:inherit;">
              <div class="pb-embed__frame" style="position:relative;width:100%;aspect-ratio:16/9;background:#0f172a;border-radius:0.5rem;overflow:hidden;">
                <iframe class="pb-embed__iframe" src="" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen style="position:absolute;inset:0;width:100%;height:100%;border:0;"></iframe>
                <div class="pb-embed__placeholder" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:0.85rem;text-align:center;padding:1rem;">Set an embed URL (YouTube, Vimeo, Maps, or any page) in the Settings panel.</div>
              </div>
            </div>
            HTML, 'Components'),

            // A typeahead bound to a collection (Forms category). Fetches matches
            // from the REST API via the pbAutocomplete Alpine component.
            self::block('autocomplete', 'Autocomplete', 'A typeahead input that searches a collection and fills a value.', <<<'HTML'
            <div data-pb-block="autocomplete" class="pb-autocomplete" data-pb-collection="" data-pb-label-field="name" x-data="pbAutocomplete($el)" data-pb-outside-close="open" style="position:relative;font-family:inherit;max-width:24rem;">
              <input type="text" class="pb-autocomplete__input" name="autocomplete" x-model="q" data-pb-ac-search placeholder="Search…" autocomplete="off" style="width:100%;padding:0.6rem 0.8rem;border:1px solid #cbd5e1;border-radius:0.5rem;font:inherit;">
              <input type="hidden" class="pb-autocomplete__value" :value="selectedId">
              <ul class="pb-autocomplete__menu" x-show="open && results.length" x-cloak style="position:absolute;z-index:30;left:0;right:0;margin:0.25rem 0 0;padding:0.25rem;list-style:none;background:#fff;border:1px solid #e2e8f0;border-radius:0.5rem;box-shadow:0 12px 32px -12px rgba(2,6,23,0.35);max-height:14rem;overflow:auto;">
                <template x-for="r in results" :key="r.id">
                  <li class="pb-autocomplete__option" x-text="r.label" :data-pb-ac-pick="r.id" style="padding:0.5rem 0.6rem;border-radius:0.375rem;cursor:pointer;"></li>
                </template>
              </ul>
            </div>
            HTML, 'Forms'),
        ];
    }

    /**
     * Interactive, data-driven components — the reusable toolkit behind
     * line-item apps (POS carts, invoices, order entry).
     *
     * OWNER-authored (trusted) blocks, but deliberately written to survive the
     * AI {@see HtmlSanitizer} unchanged: the sanitizer STRIPS executable Alpine
     * (@click / x-on: / x-init) and inline on*= handlers, so these components
     * carry NO inline click handlers. Reactivity is declarative Alpine
     * (x-data / x-for / x-model / x-show / :bind) and every user action is
     * delegated: the element carries a data-pb-* hook (data-pb-repeater-add,
     * data-pb-step, data-pb-pick, …) and the published-page runtime
     * (page.blade.php) binds a single delegated click/change listener that
     * reaches the owning Alpine component via Alpine.$data(el) and calls its
     * method. Nothing that the sanitizer removes ever appears in the saved
     * markup, so these blocks round-trip through Page::saving() intact.
     *
     * State lives in Alpine's $store.app: each block's x-data component reads
     * data-pb-state (the store array key) and proxies its rows to
     * $store.app[key], so flows (setState) and other components see the same
     * cart array. Live math (subtotal = qty*price, grand total) is just Alpine
     * expressions in x-text.
     *
     * Editor-vs-published <template> handling mirrors the Data blocks: static
     * sample rows carry x-show="false" so Alpine drops them on the published
     * page, leaving the editor canvas something to show.
     *
     * @return array<int,SectionBlock>
     */
    public static function interactive(): array
    {
        return [
            self::block('repeater', 'Repeater', 'Repeats an inner template per item in a bound State array, with add / remove.', <<<'HTML'
            <div data-pb-block="repeater" class="pb-repeater" data-pb-state="items" data-pb-min="0" data-pb-max="0" x-data="pbRepeater($el)" style="font-family:inherit;color:#0f172a;display:flex;flex-direction:column;gap:0.6rem;max-width:32rem;">
              <template x-for="(item, index) in rows" :key="index">
                <div class="pb-repeater__item" style="display:flex;align-items:center;gap:0.6rem;padding:0.75rem;border:1px solid #e2e8f0;border-radius:0.5rem;background:#fff;">
                  <input type="text" class="pb-repeater__field" x-model="item.label" placeholder="Item" style="flex:1;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.375rem;font:inherit;color:#0f172a;box-sizing:border-box;">
                  <button type="button" class="pb-repeater__remove" data-pb-repeater-remove aria-label="Remove item" style="flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;padding:0;border:1px solid #e2e8f0;border-radius:0.375rem;background:#fff;color:#dc2626;cursor:pointer;line-height:0;">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  </button>
                </div>
              </template>
              <div class="pb-repeater__sample" x-show="false" style="display:flex;align-items:center;gap:0.6rem;padding:0.75rem;border:1px solid #e2e8f0;border-radius:0.5rem;background:#fff;">
                <input type="text" class="pb-repeater__field" value="Sample item" style="flex:1;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.375rem;font:inherit;color:#0f172a;box-sizing:border-box;">
                <span style="flex-shrink:0;width:2rem;height:2rem;"></span>
              </div>
              <button type="button" class="pb-repeater__add" data-pb-repeater-add style="align-self:flex-start;display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 0.9rem;border:1px dashed #6366f1;border-radius:0.5rem;background:#eef2ff;color:#4f46e5;font-weight:600;cursor:pointer;">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>Add item</span>
              </button>
            </div>
            HTML, 'Interactive'),

            self::block('editable_grid', 'Editable grid', 'A cart-style table with inline-editable cells, add / delete row and computed totals.', <<<'HTML'
            <table data-pb-block="editable_grid" class="pb-grid" data-pb-state="cart" data-pb-qty="qty" data-pb-price="price" data-pb-max="0" x-data="pbGrid($el)" style="width:100%;border-collapse:collapse;font-family:inherit;font-size:0.9375rem;color:#0f172a;border:1px solid #e2e8f0;border-radius:0.75rem;overflow:hidden;">
              <thead class="pb-grid__head" style="background:#f8fafc;text-align:left;">
                <tr>
                  <th class="pb-grid__th" style="padding:0.75rem 1rem;border-bottom:1px solid #e2e8f0;font-weight:600;color:#334155;">Item</th>
                  <th class="pb-grid__th" style="padding:0.75rem 1rem;border-bottom:1px solid #e2e8f0;font-weight:600;color:#334155;width:6rem;">Qty</th>
                  <th class="pb-grid__th" style="padding:0.75rem 1rem;border-bottom:1px solid #e2e8f0;font-weight:600;color:#334155;width:8rem;">Price</th>
                  <th class="pb-grid__th" style="padding:0.75rem 1rem;border-bottom:1px solid #e2e8f0;font-weight:600;color:#334155;width:8rem;text-align:right;">Subtotal</th>
                  <th class="pb-grid__th" style="padding:0.75rem 1rem;border-bottom:1px solid #e2e8f0;width:3rem;"></th>
                </tr>
              </thead>
              <tbody class="pb-grid__body">
                <tr class="pb-grid__empty" x-show="rows.length === 0" x-cloak><td colspan="5" style="padding:0.75rem 1rem;color:#64748b;">No rows yet — add one below.</td></tr>
                <template x-for="(row, index) in rows" :key="index">
                  <tr class="pb-grid__row" style="border-top:1px solid #e2e8f0;">
                    <td class="pb-grid__td" style="padding:0.5rem 1rem;"><input type="text" class="pb-grid__field" x-model="row.label" placeholder="Item" style="width:100%;padding:0.4rem 0.55rem;border:1px solid transparent;border-radius:0.375rem;font:inherit;color:#0f172a;box-sizing:border-box;background:transparent;"></td>
                    <td class="pb-grid__td" style="padding:0.5rem 1rem;"><input type="number" min="0" step="1" class="pb-grid__field" x-model.number="row.qty" style="width:100%;padding:0.4rem 0.55rem;border:1px solid #e2e8f0;border-radius:0.375rem;font:inherit;color:#0f172a;box-sizing:border-box;"></td>
                    <td class="pb-grid__td" style="padding:0.5rem 1rem;"><input type="number" min="0" step="0.01" class="pb-grid__field" x-model.number="row.price" style="width:100%;padding:0.4rem 0.55rem;border:1px solid #e2e8f0;border-radius:0.375rem;font:inherit;color:#0f172a;box-sizing:border-box;"></td>
                    <td class="pb-grid__td pb-grid__subtotal" x-text="money((Number(row.qty)||0) * (Number(row.price)||0))" style="padding:0.5rem 1rem;text-align:right;font-variant-numeric:tabular-nums;"></td>
                    <td class="pb-grid__td" style="padding:0.5rem 1rem;text-align:center;"><button type="button" class="pb-grid__remove" data-pb-grid-remove aria-label="Delete row" style="display:inline-flex;align-items:center;justify-content:center;width:1.9rem;height:1.9rem;padding:0;border:1px solid #e2e8f0;border-radius:0.375rem;background:#fff;color:#dc2626;cursor:pointer;line-height:0;"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button></td>
                  </tr>
                </template>
                <tr class="pb-grid__sample" x-show="false" style="border-top:1px solid #e2e8f0;">
                  <td class="pb-grid__td" style="padding:0.5rem 1rem;">Widget</td>
                  <td class="pb-grid__td" style="padding:0.5rem 1rem;">2</td>
                  <td class="pb-grid__td" style="padding:0.5rem 1rem;">9.50</td>
                  <td class="pb-grid__td" style="padding:0.5rem 1rem;text-align:right;">19.00</td>
                  <td class="pb-grid__td" style="padding:0.5rem 1rem;"></td>
                </tr>
              </tbody>
              <tfoot class="pb-grid__foot" style="background:#f8fafc;">
                <tr>
                  <td colspan="5" style="padding:0.5rem 1rem;border-top:1px solid #e2e8f0;">
                    <button type="button" class="pb-grid__add" data-pb-grid-add style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 0.85rem;border:1px dashed #6366f1;border-radius:0.5rem;background:#eef2ff;color:#4f46e5;font-weight:600;cursor:pointer;font:inherit;">
                      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                      <span>Add row</span>
                    </button>
                  </td>
                </tr>
                <tr class="pb-grid__total-row" style="border-top:2px solid #e2e8f0;">
                  <td colspan="3" style="padding:0.75rem 1rem;font-weight:600;color:#334155;">Total (<span x-text="rows.length"></span> items)</td>
                  <td class="pb-grid__grand-total" x-text="money(total)" style="padding:0.75rem 1rem;text-align:right;font-weight:700;font-size:1.05rem;color:#0f172a;font-variant-numeric:tabular-nums;"></td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
            HTML, 'Interactive'),

            self::block('stepper', 'Quantity stepper', 'A −/+ stepper around a number input, bound to State.', <<<'HTML'
            <div data-pb-block="stepper" class="pb-stepper" data-pb-state="quantity" data-pb-min="0" data-pb-max="0" data-pb-step="1" x-data="pbStepper($el)" style="display:inline-flex;align-items:stretch;font-family:inherit;border:1px solid #cbd5e1;border-radius:0.5rem;overflow:hidden;background:#fff;">
              <button type="button" class="pb-stepper__dec" data-pb-step="-1" aria-label="Decrease" style="display:inline-flex;align-items:center;justify-content:center;width:2.4rem;border:0;border-right:1px solid #e2e8f0;background:#f8fafc;color:#334155;font-size:1.1rem;cursor:pointer;line-height:0;">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
              </button>
              <input type="number" class="pb-stepper__input" x-model.number="value" :min="min" :max="max === 0 ? null : max" :step="step" style="width:3.5rem;padding:0.5rem;border:0;text-align:center;font:inherit;color:#0f172a;box-sizing:border-box;-moz-appearance:textfield;">
              <button type="button" class="pb-stepper__inc" data-pb-step="1" aria-label="Increase" style="display:inline-flex;align-items:center;justify-content:center;width:2.4rem;border:0;border-left:1px solid #e2e8f0;background:#f8fafc;color:#334155;font-size:1.1rem;cursor:pointer;line-height:0;">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              </button>
            </div>
            HTML, 'Interactive'),

            self::block('context_menu', 'Context menu', 'A kebab (⋮) / right-click menu of actions — fire a flow or mutate State.', <<<'HTML'
            <div data-pb-block="context_menu" class="pb-context" data-pb-contextmenu x-data="pbContextMenu($el)" style="display:inline-block;position:relative;font-family:inherit;">
              <button type="button" class="pb-context__trigger" data-pb-context-toggle aria-label="Open menu" :aria-expanded="open" style="display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;padding:0;border:1px solid #e2e8f0;border-radius:0.375rem;background:#fff;color:#334155;cursor:pointer;line-height:0;">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
              </button>
              <div class="pb-context__menu" role="menu" x-show="open" x-cloak x-transition.opacity :style="pos" style="position:absolute;top:calc(100% + 0.35rem);left:0;min-width:11rem;padding:0.35rem;background:#fff;border:1px solid #e2e8f0;border-radius:0.5rem;box-shadow:0 12px 32px rgba(15,23,42,0.16);z-index:40;">
                <button type="button" class="pb-context__item" role="menuitem" data-pb-context-close data-pb-flow="" data-pb-flow-input="" style="display:block;width:100%;text-align:left;padding:0.5rem 0.65rem;border:0;border-radius:0.375rem;background:transparent;color:#334155;font:inherit;cursor:pointer;">Edit</button>
                <button type="button" class="pb-context__item" role="menuitem" data-pb-context-close data-pb-context-remove style="display:block;width:100%;text-align:left;padding:0.5rem 0.65rem;border:0;border-radius:0.375rem;background:transparent;color:#dc2626;font:inherit;cursor:pointer;">Remove</button>
              </div>
            </div>
            HTML, 'Interactive'),

            // Bare picker: only data-pb-label-field and data-pb-target are set.
            // data-pb-image-field and data-pb-extra-field are OPT-IN (no defaults).
            // A tile shows only the label; add the other attrs in the builder to
            // opt-in to image thumbnails or an extra info line.
            self::block('record_picker', 'Record picker', 'A searchable tile grid from a collection — click a tile to add it to a State array.', <<<'HTML'
            <div data-pb-block="record_picker" class="pb-picker" data-pb-collection="" data-pb-label-field="" data-pb-target="" x-data="pbRecordPicker($el)" style="font-family:inherit;color:#0f172a;max-width:40rem;">
              <input type="text" class="pb-picker__search" x-model="q" data-pb-picker-search placeholder="Search…" autocomplete="off" style="width:100%;padding:0.6rem 0.8rem;border:1px solid #cbd5e1;border-radius:0.5rem;font:inherit;color:#0f172a;box-sizing:border-box;margin-bottom:0.75rem;">
              <p class="pb-picker__loading" x-show="loading" x-cloak style="color:#64748b;margin:0.25rem 0;">Loading…</p>
              <p class="pb-picker__empty" x-show="!loading && results.length === 0" x-cloak style="color:#64748b;margin:0.25rem 0;">No matches.</p>
              <div class="pb-picker__grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(9rem,1fr));gap:0.6rem;">
                <template x-for="r in results" :key="r.id">
                  <button type="button" class="pb-picker__tile" data-pb-pick :data-pb-pick-id="r.id" style="display:flex;flex-direction:column;gap:0.4rem;text-align:left;padding:0.6rem;border:1px solid #e2e8f0;border-radius:0.5rem;background:#fff;color:#0f172a;font:inherit;cursor:pointer;transition:border-color .15s,box-shadow .15s;">
                    <img :src="r.image" x-show="r.image != null" x-cloak alt="" style="width:100%;height:5rem;object-fit:cover;border-radius:0.35rem;background:#f1f5f9;">
                    <span class="pb-picker__tile-label" x-text="r.label" style="font-weight:600;font-size:0.85rem;"></span>
                    <span class="pb-picker__tile-extra" x-show="r.extra != null && r.extra !== ''" x-text="r.extra" style="color:#475569;font-size:0.8rem;"></span>
                  </button>
                </template>
                <button type="button" class="pb-picker__sample" x-show="false" style="text-align:left;padding:0.75rem;border:1px solid #e2e8f0;border-radius:0.5rem;background:#fff;color:#0f172a;font:inherit;cursor:pointer;">Sample item</button>
              </div>
            </div>
            HTML, 'Interactive'),
        ];
    }

    /**
     * The raw built-in blocks (sections + basics + shapes + components + forms
     * + data + interactive). This is the seed for {@see ComponentRegistry} — the public
     * accessors below delegate back to that registry, so building it from the
     * public all() would recurse. Consumers should read all()/keys()/find()/
     * toArray() (which include registered third-party blocks); only the
     * registry seeding reads builtins().
     *
     * @return array<int,SectionBlock>
     */
    public static function builtins(): array
    {
        return [...self::sections(), ...self::basics(), ...self::shapes(), ...self::components(), ...self::forms(), ...self::data(), ...self::interactive()];
    }

    /**
     * Every block for the GrapesJS block manager — built-ins plus any blocks
     * registered through {@see ComponentRegistry} (third-party / premium).
     * Delegates to the registry so registered components are visible here.
     *
     * @return array<int,SectionBlock>
     */
    public static function all(): array
    {
        return app(ComponentRegistry::class)->all();
    }

    /**
     * The SECTION-level vocabulary the AI is allowed to emit for page generation —
     * the top-level page sections (hero, features, pricing, …), NOT every granular
     * block. Sourced from the registry and scoped to the "Sections" category, so a
     * registered third-party / premium component that declares that category joins
     * the AI section vocabulary while finer-grained blocks stay drag-only (this
     * preserves the pre-registry behaviour — see all() for the full block list).
     *
     * @return array<int,string>
     */
    public static function keys(): array
    {
        return array_values(array_map(
            static fn (SectionBlock $b): string => $b->key,
            array_filter(
                app(ComponentRegistry::class)->all(),
                static fn (SectionBlock $b): bool => $b->category === self::SECTION_CATEGORY,
            ),
        ));
    }

    public static function find(string $key): ?SectionBlock
    {
        return app(ComponentRegistry::class)->find($key);
    }

    /**
     * Serializable form for the GrapesJS block manager (JS side) — built-ins
     * plus registered blocks. Delegates to the registry.
     *
     * @return array<int,array{key:string,label:string,category:string,template:string,description:string,icon:string}>
     */
    public static function toArray(): array
    {
        return app(ComponentRegistry::class)->toArray();
    }

    private static function block(string $key, string $label, string $description, string $template, string $category = self::SECTION_CATEGORY): SectionBlock
    {
        return new SectionBlock($key, $label, $category, trim($template), $description, Icons::for($key));
    }
}
