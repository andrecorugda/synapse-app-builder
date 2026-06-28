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
                <img src="https://via.placeholder.com/400x300" alt="" style="width:100%;border-radius:0.5rem;">
                <img src="https://via.placeholder.com/400x300" alt="" style="width:100%;border-radius:0.5rem;">
                <img src="https://via.placeholder.com/400x300" alt="" style="width:100%;border-radius:0.5rem;">
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
                <div><img src="https://via.placeholder.com/120" alt="" style="width:96px;height:96px;border-radius:50%;object-fit:cover;"><h3 style="margin:0.75rem 0 0.25rem;">Alex Kim</h3><p style="color:#64748b;margin:0;">CEO</p></div>
                <div><img src="https://via.placeholder.com/120" alt="" style="width:96px;height:96px;border-radius:50%;object-fit:cover;"><h3 style="margin:0.75rem 0 0.25rem;">Sam Lee</h3><p style="color:#64748b;margin:0;">CTO</p></div>
                <div><img src="https://via.placeholder.com/120" alt="" style="width:96px;height:96px;border-radius:50%;object-fit:cover;"><h3 style="margin:0.75rem 0 0.25rem;">Jo Park</h3><p style="color:#64748b;margin:0;">Design</p></div>
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
            self::block('image', 'Image', 'An image.', '<img src="https://via.placeholder.com/600x300" alt="" style="max-width:100%;">', 'Basic'),
            self::block('button', 'Button', 'A call-to-action button.', '<a href="#" style="display:inline-block;padding:0.7rem 1.4rem;border-radius:0.5rem;background:#4f46e5;color:#fff;text-decoration:none;font-weight:600;">Button</a>', 'Basic'),
            self::block('columns-2', '2 Columns', 'Two-column row.', '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;padding:1rem;"><div style="min-height:3rem;border:1px dashed #cbd5e1;"></div><div style="min-height:3rem;border:1px dashed #cbd5e1;"></div></div>', 'Basic'),
            self::block('columns-3', '3 Columns', 'Three-column row.', '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;padding:1rem;"><div style="min-height:3rem;border:1px dashed #cbd5e1;"></div><div style="min-height:3rem;border:1px dashed #cbd5e1;"></div><div style="min-height:3rem;border:1px dashed #cbd5e1;"></div></div>', 'Basic'),
            self::block('spacer', 'Spacer', 'Vertical spacing.', '<div style="height:3rem;"></div>', 'Basic'),
            self::block('divider', 'Divider', 'A horizontal rule.', '<hr style="border:0;border-top:1px solid #e2e8f0;margin:1.5rem 0;">', 'Basic'),
        ];
    }

    /**
     * Every block (sections + basics) for the GrapesJS block manager.
     *
     * @return array<int,SectionBlock>
     */
    public static function all(): array
    {
        return [...self::sections(), ...self::basics()];
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
